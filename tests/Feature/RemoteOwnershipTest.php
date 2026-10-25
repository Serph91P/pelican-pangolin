<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\HttpPangolinClient;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Services\ResourceNaming;
use PelicanDev\PelicanPangolin\Tests\Support\PangolinApiFake;

uses(RefreshDatabase::class);

it('refuses a stored ID whose remote ownership changed for update disable and removal', function (string $operation) {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => $operation !== 'remove', 'deletion_pending' => $operation === 'remove']);
    $publication->resources()->create(['allocation_id' => 1, 'mode' => 'tcp', 'public_port' => 25565, 'pangolin_resource_id' => 91, 'ownership_name' => 'our-random-identity']);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldReceive('listResources')->andReturn([]);
    $client->shouldReceive('getResource')->with(91)->andReturn(['resourceId' => 91, 'name' => 'foreign-resource', 'mode' => 'tcp', 'proxyPort' => 25565]);
    $client->shouldNotReceive('updateResource');
    $client->shouldNotReceive('deleteResource');
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    expect(fn () => match ($operation) {
        'update' => $reconciler->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [new DesiredResource(1, 25565, PublicationMode::Tcp, null)])),
        'replace' => $reconciler->reconcile($publication, new DesiredPublication(1, 8, 'new-node.internal', [new DesiredResource(2, 25565, PublicationMode::Tcp, null)])),
        'revoke' => (new PublicationReconciler($client, PublicPortAllocator::from('25566', '25566'), new ResourceNaming))->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [new DesiredResource(1, 25565, PublicationMode::Tcp, null)])),
        'disable' => $reconciler->disable($publication),
        'remove' => $reconciler->remove($publication, 1),
    })->toThrow(DomainException::class);
    expect($publication->resources()->count())->toBe(1);
})->with(['update', 'replace', 'revoke', 'disable', 'remove']);

it('revalidates an existing reservation against the currently approved pool', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $publication->resources()->create(['allocation_id' => 1, 'mode' => 'tcp', 'public_port' => 25565, 'pangolin_resource_id' => 91, 'ownership_name' => 'our-random-identity']);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldReceive('listResources')->andReturn([]);
    $client->shouldReceive('getResource')->andReturn(['resourceId' => 91, 'name' => 'our-random-identity']);
    $client->shouldReceive('listTargets')->andReturn([]);
    $client->shouldReceive('createTarget')->andReturn(['targetId' => 92]);
    $client->shouldReceive('updateResource')->andReturn(['resourceId' => 91]);
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25566', '25566'), new ResourceNaming);
    expect(fn () => $reconciler->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [new DesiredResource(1, 25565, PublicationMode::Tcp, null)])))->toThrow(RuntimeException::class, 'exact allocation port');
});

it('disables previously published resources when the approved pool no longer permits their ports', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $api = new PangolinApiFake;
    $api->install();
    $client = new HttpPangolinClient('https://api.example.com', 'test-org', 'fixture-only');
    $desired = new DesiredPublication(1, 7, 'node.internal', [
        new DesiredResource(1, 25565, PublicationMode::Tcp, null),
        new DesiredResource(1, 25565, PublicationMode::Udp, null),
    ]);
    (new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming))->reconcile($publication, $desired);
    expect(array_column($api->resources, 'enabled'))->toBe([true, true]);
    $ids = array_keys($api->resources);
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25566', '25566'), new ResourceNaming);
    expect(fn () => $reconciler->reconcile($publication->fresh(), $desired))->toThrow(RuntimeException::class, 'exact allocation port');
    expect(array_keys($api->resources))->toBe($ids)
        ->and(array_column($api->resources, 'enabled'))->toBe([false, false])
        ->and($publication->resources()->pluck('enabled')->all())->toBe([false, false])
        ->and($publication->resources()->pluck('confirmed_endpoint')->all())->toBe([null, null])
        ->and($publication->fresh()->status->value)->toBe('error');
    // Pool restoration and retry reuse the same owned identities and recover normally.
    (new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming))->reconcile($publication->fresh(), $desired);
    expect(array_keys($api->resources))->toBe($ids)
        ->and(array_column($api->resources, 'enabled'))->toBe([true, true])
        ->and($publication->fresh()->status->value)->toBe('synced');
});

it('does not delete unexpected targets through an owned resource during opt-out', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => false, 'deletion_pending' => true]);
    $publication->resources()->create(['allocation_id' => 1, 'mode' => 'tcp', 'public_port' => 25565, 'pangolin_resource_id' => 91, 'pangolin_target_id' => 92, 'ownership_name' => 'our-random-identity']);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldReceive('getResource')->andReturn(['resourceId' => 91, 'name' => 'our-random-identity']);
    $client->shouldReceive('listTargets')->andReturn([['targetId' => 92], ['targetId' => 93]]);
    $client->shouldReceive('deleteResource')->andReturnNull();
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    expect(fn () => $reconciler->remove($publication, 1))->toThrow(DomainException::class, 'Unexpected target');
    expect($publication->resources()->count())->toBe(1);
});

it('does not adopt a deterministic name without a durable random ownership marker', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'opted_in' => true]);
    $client = Mockery::mock(PangolinClient::class);
    $client->shouldReceive('listResources')->andReturn([['resourceId' => 91, 'name' => 'pelican-3a3c5ce98a95-1', 'mode' => 'tcp', 'proxyPort' => 25565]]);
    $client->shouldNotReceive('updateResource');
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);
    expect(fn () => $reconciler->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [new DesiredResource(1, 25565, PublicationMode::Tcp, null)])))->toThrow(RuntimeException::class);
    expect($publication->resources()->count())->toBe(0);
});
