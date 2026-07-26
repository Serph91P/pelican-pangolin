<?php

use App\Enums\ServerState;
use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PelicanDev\PelicanPangolin\Jobs\ReconcileServerPublication;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Observers\ServerObserver;

uses(RefreshDatabase::class);

it('tombstones exact ownership and queues after-commit removal before server deletion', function () {
    Queue::fake();
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
    ]);
    $server = new Server;
    $server->setRawAttributes(['id' => 12, 'uuid' => $publication->server_uuid]);

    (new ServerObserver)->deleting($server);

    expect($publication->fresh()->deletion_pending)->toBeTrue()
        ->and($publication->fresh()->opted_in)->toBeFalse();
    Queue::assertPushed(RemoveServerPublication::class, fn (RemoveServerPublication $job) => $job->publicationId === $publication->id
        && $job->serverId === 12
        && $job->revision === 2
        && $job->afterCommit === true);
});

it('refreshes opted-in publications on every saved server and preserves suspension', function () {
    Queue::fake();
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
    ]);
    $server = new Server;
    $server->setRawAttributes([
        'id' => 12,
        'uuid' => $publication->server_uuid,
        'node_id' => 7,
        'status' => ServerState::Suspended->value,
    ]);
    $egg = new Egg;
    $egg->setRawAttributes(['uuid' => 'e2ed07f0-7f4f-4fa4-9c90-56b0c0998a70']);
    $server->setRelation('egg', $egg);
    $server->setRelation('allocations', new Collection);

    (new ServerObserver)->saved($server);

    expect($publication->fresh()->desired_revision)->toBe(2)
        ->and($publication->fresh()->suspended)->toBeTrue();
    Queue::assertPushed(ReconcileServerPublication::class, fn (ReconcileServerPublication $job) => $job->revision === 2);
});
