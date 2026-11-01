<?php

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Jobs\ReconcileServerPublication;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\DesiredStateBuilder;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Services\ResourceNaming;

uses(RefreshDatabase::class);

it('serializes reconciliation per server and ignores stale revisions', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
        'desired_revision' => 4,
        'desired_state' => [],
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createRawResource', 'createHttpResource', 'updateResource', 'deleteResource');
    });
    $reconciler = new PublicationReconciler($client, new PublicPortAllocator, new ResourceNaming);
    $job = new ReconcileServerPublication($publication->id, $publication->server_id, 3);

    expect($job)->not->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('server:12')
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->backoff())->toBe([10, 30, 90, 180]);

    $this->app->instance(PublicationReconciler::class, $reconciler);
    $job->handle(new DesiredStateBuilder);
    expect($publication->fresh()->desired_revision)->toBe(4);
});

it('disables a suspended publication before building unavailable desired state', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 13,
        'server_uuid' => '10b8c7c9-ee30-4377-9493-66e04f14aa01',
        'opted_in' => true,
        'suspended' => true,
        'desired_revision' => 2,
        'desired_state' => [],
    ]);
    $publication->resources()->create([
        'allocation_id' => 60,
        'mode' => 'udp',
        'pangolin_resource_id' => 101,
        'pangolin_target_id' => 102,
        'public_port' => 27015,
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('updateResource')->once()->with(101, ['enabled' => false])->andReturn(['resourceId' => 101]);
        $mock->shouldReceive('getResource')->once()->with(101)->andReturn(['resourceId' => 101, 'name' => 'pelican-10b8c7c9ee30-60']);
    });
    $reconciler = new PublicationReconciler($client, new PublicPortAllocator, new ResourceNaming);
    $this->app->instance(PublicationReconciler::class, $reconciler);

    (new ReconcileServerPublication($publication->id, $publication->server_id, 2))
        ->handle(new DesiredStateBuilder);

    expect($publication->fresh()->status->value)->toBe('disabled');
});

it('uses one shared per-server execution lock for reconciliation and removal', function () {
    $reconcile = new ReconcileServerPublication(5, 12, 3);
    $remove = new RemoveServerPublication(5, 12, 3);

    expect($remove)->not->toBeInstanceOf(ShouldBeUnique::class)
        ->and($remove->uniqueId())->toBe('server:12')
        ->and($remove->afterCommit)->toBeTrue()
        ->and($reconcile->middleware()[0]->releaseAfter)->toBe(15)
        ->and($remove->middleware()[0]->releaseAfter)->toBe(15)
        ->and($reconcile->middleware()[0]->getLockKey($reconcile))
        ->toBe($remove->middleware()[0]->getLockKey($remove));
});

it('ignores a stale removal after the publication is opted in again', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
    ]);
    $publication->resources()->create([
        'allocation_id' => 50,
        'mode' => 'tcp',
        'pangolin_resource_id' => 91,
        'pangolin_target_id' => 92,
        'public_port' => 25565,
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('deleteResource');
    });
    $reconciler = new PublicationReconciler($client, new PublicPortAllocator, new ResourceNaming);

    $this->app->instance(PublicationReconciler::class, $reconciler);
    (new RemoveServerPublication($publication->id, $publication->server_id, 1))->handle();

    expect($publication->fresh()->opted_in)->toBeTrue()
        ->and($publication->fresh()->resources)->toHaveCount(1);
});

it('ignores an in-flight removal generation after re-opt-in', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 14,
        'server_uuid' => '6380ea5a-c2b0-45ac-870b-505abf0d39c9',
        'opted_in' => false,
        'deletion_pending' => true,
        'desired_revision' => 5,
    ]);
    $publication->resources()->create([
        'allocation_id' => 70,
        'mode' => 'tcp',
        'pangolin_resource_id' => 111,
        'pangolin_target_id' => 112,
        'public_port' => 25565,
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) use ($publication) {
        $mock->shouldReceive('getResource')->once()->with(111)->andReturn(['resourceId' => 111, 'name' => 'pelican-6380ea5ac2b0-70']);
        $mock->shouldReceive('listTargets')->once()->with(111)->andReturn([]);
        $mock->shouldReceive('deleteResource')->once()->with(111)->andReturnUsing(function () use ($publication): void {
            $publication->forceFill([
                'opted_in' => true,
                'deletion_pending' => false,
                'desired_revision' => 6,
            ])->save();
        });
    });
    $reconciler = new PublicationReconciler($client, new PublicPortAllocator, new ResourceNaming);

    $this->app->instance(PublicationReconciler::class, $reconciler);
    (new RemoveServerPublication($publication->id, $publication->server_id, 5))->handle();

    expect($publication->fresh()->opted_in)->toBeTrue()
        ->and($publication->fresh()->desired_revision)->toBe(6);
});

it('requeues durable pending removals during scheduled refresh', function () {
    Queue::fake();
    $publication = ServerPublication::query()->create([
        'server_id' => 15,
        'server_uuid' => '3500d9f1-8ff1-4057-95e2-9e78145457ec',
        'opted_in' => false,
        'deletion_pending' => true,
        'desired_revision' => 7,
    ]);

    app(PublicationRefreshService::class)->refreshAll();

    Queue::assertPushed(RemoveServerPublication::class, fn (RemoveServerPublication $job): bool => $job->publicationId === $publication->id
        && $job->revision === 7);
});

it('reports cleanup connection configuration failures immediately and retains ownership for retry', function () {
    $publication = ServerPublication::create(['server_id' => 99, 'server_uuid' => '3500d9f1-8ff1-4057-95e2-9e78145457ec', 'opted_in' => false, 'deletion_pending' => true, 'status' => 'removing']);
    $publication->resources()->create(['allocation_id' => 70, 'mode' => 'tcp', 'public_port' => 30000, 'pangolin_resource_id' => 91, 'pangolin_target_id' => 92]);
    $this->app->bind(PublicationReconciler::class, fn () => throw new InvalidArgumentException('Invalid API connection configuration'));
    $job = new RemoveServerPublication($publication->id, 99, 1);
    expect(fn () => $this->app->call([$job, 'handle']))->toThrow(InvalidArgumentException::class);
    expect($publication->fresh()->status->value)->toBe('error')->and($publication->fresh()->deletion_pending)->toBeTrue()
        ->and($publication->fresh()->last_error)->toContain('remote exposure may remain')->and($publication->resources()->firstOrFail()->pangolin_resource_id)->toBe(91);
});
