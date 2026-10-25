<?php

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Jobs\ReconcileServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Services\ResourceNaming;

uses(RefreshDatabase::class);

it('does not mark a superseded API operation synced or overwrite its newer status', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldReceive('listResources')->andReturn([]);
    $client->shouldReceive('createRawResource')->once()->andReturnUsing(function () use ($publication) {
        ServerPublication::whereKey($publication->id)->update(['desired_revision' => 2, 'status' => 'removing', 'opted_in' => false, 'deletion_pending' => true]);

        return ['resourceId' => 91];
    });
    $client->shouldNotReceive('createTarget');
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    expect(fn () => $reconciler->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [new DesiredResource(1, 25565, PublicationMode::Tcp, null)])))->toThrow(DomainException::class, 'stale');
    expect($publication->fresh()->status->value)->toBe('removing')
        ->and($publication->resources()->firstOrFail()->pangolin_resource_id)->toBe(91)
        ->and($publication->resources()->firstOrFail()->confirmed_endpoint)->toBeNull();
});

it('stops obsolete allocation deletion when the revision changes during ownership checks', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $publication->resources()->create(['allocation_id' => 1, 'mode' => 'tcp', 'public_port' => 25565, 'pangolin_resource_id' => 91, 'pangolin_target_id' => 92, 'ownership_name' => 'our-random-identity']);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldReceive('listResources')->andReturn([]);
    $client->shouldReceive('getResource')->with(91)->andReturn(['resourceId' => 91, 'name' => 'our-random-identity']);
    $client->shouldReceive('listTargets')->with(91)->andReturnUsing(function () use ($publication) {
        ServerPublication::whereKey($publication->id)->update(['desired_revision' => 2, 'status' => 'pending']);

        return [['targetId' => 92]];
    });
    $client->shouldNotReceive('deleteResource');
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    expect(fn () => $reconciler->reconcile($publication, new DesiredPublication(1, 8, 'new-node.internal', [])))->toThrow(DomainException::class, 'stale');
    expect($publication->fresh()->status->value)->toBe('pending')
        ->and($publication->resources()->count())->toBe(1);
});

it('does not let an exhausted old job overwrite a new revision', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'desired_revision' => 2, 'status' => 'pending']);
    (new ReconcileServerPublication($publication->id, 12, 1))->failed(new RuntimeException('old failure'));
    expect($publication->fresh()->status->value)->toBe('pending');
});

it('serializes remote inventory across different servers', function () {
    $lock = Cache::lock('pangolin:remote-reconciliation', 300);
    expect($lock->get())->toBeTrue();
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldNotReceive('listResources');
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    try {
        expect(fn () => $reconciler->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [])))->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});
