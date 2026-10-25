<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Jobs\ReconcileServerPublication;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

uses(RefreshDatabase::class);

it('refreshes affected publications after node mapping and Egg profile changes', function () {
    Schema::create('eggs', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid');
    });
    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid');
        $table->unsignedBigInteger('node_id');
        $table->unsignedBigInteger('egg_id');
        $table->unsignedBigInteger('allocation_id')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
    });
    Schema::create('allocations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('server_id')->nullable();
        $table->unsignedInteger('port');
        $table->timestamps();
    });

    DB::table('eggs')->insert(['id' => 3, 'uuid' => 'e2ed07f0-7f4f-4fa4-9c90-56b0c0998a70']);
    DB::table('servers')->insert([
        'id' => 12,
        'uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'node_id' => 42,
        'egg_id' => 3,
        'allocation_id' => 50,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('allocations')->insert([
        'id' => 50,
        'server_id' => 12,
        'port' => 25565,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('pangolin_node_mappings')->insert([
        'node_id' => 42,
        'site_id' => 7,
        'site_nice_id' => 'stable-site',
        'target_host' => '10.0.0.42',
        'enabled' => true,
        'validated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
    ]);
    Queue::fake();
    $service = app(PublicationRefreshService::class);

    $service->refreshNode(42);
    $service->refreshEgg('e2ed07f0-7f4f-4fa4-9c90-56b0c0998a70');

    expect($publication->fresh()->desired_revision)->toBe(3)
        ->and($publication->fresh()->desired_state['mapping']['enabled'])->toBeTrue();
    Queue::assertPushed(ReconcileServerPublication::class, 2);

    // Query-builder deletion bypasses native observers; the scheduler must recover it.
    DB::table('servers')->where('id', 12)->delete();
    $service->refreshAll();
    expect($publication->fresh()->opted_in)->toBeFalse()
        ->and($publication->fresh()->deletion_pending)->toBeTrue();
    Queue::assertPushed(RemoveServerPublication::class);
});
