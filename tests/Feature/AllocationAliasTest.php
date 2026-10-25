<?php

use App\Models\Allocation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\AllocationAliasManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('allocations', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('server_id')->nullable();
        $table->string('ip');
        $table->unsignedInteger('port');
        $table->string('ip_alias')->nullable();
    });
    DB::table('allocations')->insert([
        ['id' => 1, 'server_id' => 12, 'ip' => '10.0.0.42', 'port' => 25565, 'ip_alias' => null],
        ['id' => 2, 'server_id' => 12, 'ip' => '10.0.0.42', 'port' => 25566, 'ip_alias' => 'manual.example.com'],
    ]);
    $this->publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true, 'status' => 'synced']);
    foreach ([1 => 25565, 2 => 25566] as $id => $port) {
        $this->publication->resources()->create(['allocation_id' => $id, 'mode' => 'tcp', 'public_port' => $port, 'pangolin_resource_id' => $id, 'pangolin_target_id' => $id, 'enabled' => true]);
    }
});

it('applies a public host without changing bind data or manual aliases then restores only owned unchanged values', function () {
    $manager = new AllocationAliasManager;
    $manager->apply($this->publication, 'games.example.com');
    expect((array) DB::table('allocations')->find(1))->toMatchArray(['ip' => '10.0.0.42', 'port' => 25565, 'server_id' => 12, 'ip_alias' => 'games.example.com'])
        ->and(DB::table('allocations')->find(2)->ip_alias)->toBe('manual.example.com');
    $manager->restore($this->publication);
    expect(DB::table('allocations')->find(1)->ip_alias)->toBeNull();
    $manager->apply($this->publication, 'games.example.com');
    DB::table('allocations')->where('id', 1)->update(['ip_alias' => 'admin-edit.example.com']);
    $manager->restore($this->publication);
    expect(DB::table('allocations')->find(1)->ip_alias)->toBe('admin-edit.example.com');
});

it('never aliases a remapped or unassigned allocation', function () {
    $manager = new AllocationAliasManager;
    $this->publication->resources()->where('allocation_id', 1)->update(['public_port' => 30000]);
    $manager->apply($this->publication, 'games.example.com');
    expect(DB::table('allocations')->find(1)->ip_alias)->toBeNull();
    $this->publication->resources()->where('allocation_id', 1)->update(['public_port' => 25565]);
    DB::table('allocations')->where('id', 1)->update(['server_id' => null]);
    $manager->apply($this->publication, 'games.example.com');
    expect(DB::table('allocations')->find(1)->ip_alias)->toBeNull();
});

it('never aliases a disabled resource or one without a confirmed target', function (array $state) {
    $this->publication->resources()->where('allocation_id', 1)->update($state);
    (new AllocationAliasManager)->apply($this->publication, 'games.example.com');
    expect(DB::table('allocations')->find(1)->ip_alias)->toBeNull();
})->with([
    'disabled' => [['enabled' => false]],
    'missing target' => [['pangolin_target_id' => null]],
]);

it('does not apply an alias from a stale synchronized publication after opt-out', function () {
    $stale = $this->publication;
    ServerPublication::whereKey($stale->id)->update(['opted_in' => false, 'desired_revision' => 2, 'status' => 'removing']);
    (new AllocationAliasManager)->apply($stale, 'games.example.com');
    expect(DB::table('allocations')->find(1)->ip_alias)->toBeNull();
});

it('keeps IPv6 native address and API host consumers consistent', function () {
    (new AllocationAliasManager)->apply($this->publication, '2606:4700:4700::1111');
    $allocation = Allocation::findOrFail(1);
    expect($allocation->address)->toBe('[2606:4700:4700::1111]:25565')
        ->and($allocation->alias)->toBe('2606:4700:4700::1111')
        ->and($allocation->ip)->toBe('10.0.0.42')
        ->and($allocation->port)->toBe(25565);
});
