<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Exceptions\PangolinApiException;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\HttpPangolinClient;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Services\ResourceNaming;
use PelicanDev\PelicanPangolin\Tests\Support\PangolinApiFake;

uses(RefreshDatabase::class);

it('replaces an obsolete allocation on the exact port and removes the old remote targets', function (array $modes, string $failure) {
    Schema::create('allocations', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('server_id')->nullable();
        $table->string('ip');
        $table->unsignedInteger('port');
        $table->string('ip_alias')->nullable();
    });
    DB::table('allocations')->insert([
        ['id' => 1, 'server_id' => 12, 'ip' => '10.0.0.42', 'port' => 25565, 'ip_alias' => null],
        ['id' => 2, 'server_id' => null, 'ip' => '10.0.0.43', 'port' => 25565, 'ip_alias' => null],
    ]);
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $api = new PangolinApiFake;
    $api->install();
    $reconciler = new PublicationReconciler(new HttpPangolinClient('https://api.example.com', 'test-org', 'fixture-only'), PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    $resources = fn (int $id): array => array_map(fn (PublicationMode $mode) => new DesiredResource($id, 25565, $mode, null), $modes);
    $reconciler->reconcile($publication, new DesiredPublication(1, 7, 'old-node.internal', $resources(1)));
    expect($publication->fresh()->status)->toBe(SyncStatus::Synced)
        ->and(DB::table('allocations')->find(1)->ip_alias)->toBe('games.example.com');
    $oldResources = array_keys($api->resources);
    $oldTargets = array_keys($api->targets);
    DB::table('allocations')->where('id', 1)->update(['server_id' => null]);
    DB::table('allocations')->where('id', 2)->update(['server_id' => 12]);
    $publication->forceFill(['desired_revision' => 2, 'status' => SyncStatus::Pending])->save();
    $desired = new DesiredPublication(2, 8, 'new-node.internal', $resources(2));
    if ($failure === 'delete') {
        $api->failNextDelete = true;
        expect(fn () => $reconciler->reconcile($publication, $desired))->toThrow(PangolinApiException::class, 'Temporary deletion failure');
        expect(array_keys($api->resources))->toBe($oldResources)
            ->and(array_keys($api->targets))->toBe($oldTargets)
            ->and($publication->resources()->pluck('allocation_id')->unique()->all())->toBe([1])
            ->and($publication->fresh()->status)->toBe(SyncStatus::Error)
            ->and(DB::table('allocations')->find(2)->ip_alias)->toBeNull();
    }
    if ($failure === 'target') {
        $api->failNextTarget = true;
        expect(fn () => $reconciler->reconcile($publication, $desired))->toThrow(PangolinApiException::class, 'Temporary target failure');
        expect($publication->fresh()->status)->toBe(SyncStatus::Error)
            ->and(array_intersect($oldResources, array_keys($api->resources)))->toBe([])
            ->and(array_intersect($oldTargets, array_keys($api->targets)))->toBe([])
            ->and(DB::table('allocations')->find(2)->ip_alias)->toBeNull();
    }
    $reconciler->reconcile($publication->fresh(), $desired);
    $newResources = array_keys($api->resources);
    $newTargets = array_keys($api->targets);
    // Repeating the same desired revision must preserve the new remote identities.
    $reconciler->reconcile($publication->fresh(), $desired);
    expect($publication->fresh()->status)->toBe(SyncStatus::Synced)
        ->and(array_keys($api->resources))->toBe($newResources)
        ->and(array_keys($api->targets))->toBe($newTargets)
        ->and(array_intersect($oldResources, $newResources))->toBe([])
        ->and(array_intersect($oldTargets, $newTargets))->toBe([])
        ->and($publication->resources()->pluck('allocation_id')->unique()->all())->toBe([2])
        ->and(array_column($api->resources, 'proxyPort'))->toBe(array_fill(0, count($modes), 25565))
        ->and(array_column($api->resources, 'enabled'))->toBe(array_fill(0, count($modes), true))
        ->and(array_column($api->targets, 'siteId'))->toBe(array_fill(0, count($modes), 8))
        ->and(array_column($api->targets, 'ip'))->toBe(array_fill(0, count($modes), 'new-node.internal'))
        ->and(array_column($api->targets, 'port'))->toBe(array_fill(0, count($modes), 25565))
        ->and($publication->resources()->pluck('confirmed_endpoint')->all())->toBe(array_fill(0, count($modes), 'games.example.com:25565'))
        ->and(DB::table('allocations')->find(1)->ip_alias)->toBeNull()
        ->and((array) DB::table('allocations')->find(2))->toMatchArray(['ip' => '10.0.0.43', 'port' => 25565, 'ip_alias' => 'games.example.com']);
})->with([
    'TCP' => [[PublicationMode::Tcp]],
    'UDP' => [[PublicationMode::Udp]],
    'TCP+UDP' => [[PublicationMode::Tcp, PublicationMode::Udp]],
])->with(['none', 'target', 'delete']);
