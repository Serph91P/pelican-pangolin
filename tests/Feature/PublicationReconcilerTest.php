<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Exceptions\PangolinApiException;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Services\ResourceNaming;

uses(RefreshDatabase::class);

it('recovers idempotently when target creation fails after resource creation', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
        'desired_revision' => 3,
    ]);
    $desired = new DesiredPublication(3, 7, '10.0.0.42', [
        new DesiredResource(50, 25565, PublicationMode::Tcp, null),
    ]);

    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listResources')->twice()->andReturn([]);
        $mock->shouldReceive('createRawResource')->once()->andReturn(['resourceId' => 91]);
        $mock->shouldReceive('listTargets')->twice()->with(91)->andReturn([]);
        $attempt = 0;
        $mock->shouldReceive('createTarget')->twice()->andReturnUsing(function () use (&$attempt) {
            if ($attempt++ === 0) {
                throw new RuntimeException('temporary failure');
            }

            return ['targetId' => 92];
        });
        $mock->shouldReceive('getResource')->once()->with(91)->andReturn(['resourceId' => 91, 'enabled' => true]);
        $mock->shouldReceive('updateResource')->once()->with(91, Mockery::type('array'))->andReturn(['resourceId' => 91]);
        $mock->shouldReceive('updateTarget')->never();
    });
    $reconciler = new PublicationReconciler(
        $client,
        PublicPortAllocator::from('25565,30000-30001', '25565'),
        new ResourceNaming,
    );

    expect(fn () => $reconciler->reconcile($publication, $desired))->toThrow(RuntimeException::class);

    $owned = $publication->resources()->firstOrFail();
    expect($owned->public_port)->toBe(25565)
        ->and($owned->pangolin_resource_id)->toBe(91)
        ->and($owned->pangolin_target_id)->toBeNull();

    $reconciler->reconcile($publication->fresh(), $desired);

    expect($publication->fresh()->status)->toBe(SyncStatus::Synced)
        ->and($owned->fresh()->pangolin_target_id)->toBe(92);
});

it('disables and removes only exact locally owned remote identifiers', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 13,
        'server_uuid' => '10b8c7c9-ee30-4377-9493-66e04f14aa01',
        'opted_in' => true,
    ]);
    $publication->resources()->create([
        'allocation_id' => 60,
        'mode' => PublicationMode::Udp,
        'pangolin_resource_id' => 101,
        'pangolin_target_id' => 102,
        'public_port' => 27015,
    ]);

    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('updateResource')->once()->with(101, ['enabled' => false])->andReturn(['resourceId' => 101]);
        $mock->shouldReceive('deleteResource')->once()->with(101);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('27015', '27015'), new ResourceNaming);

    $reconciler->disable($publication);
    expect($publication->fresh()->status)->toBe(SyncStatus::Disabled);

    $publication->forceFill([
        'opted_in' => false,
        'deletion_pending' => true,
        'desired_revision' => 2,
    ])->save();
    $reconciler->remove($publication, 2);
    expect($publication->fresh()->resources)->toHaveCount(0);
});

it('repairs a missing owned target without adopting an unowned target', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 14,
        'server_uuid' => '7f84b785-38a0-4443-9892-65ea694d884b',
        'opted_in' => true,
        'desired_revision' => 2,
    ]);
    $publication->resources()->create([
        'allocation_id' => 70,
        'mode' => PublicationMode::Tcp,
        'pangolin_resource_id' => 201,
        'pangolin_target_id' => 202,
        'public_port' => 25565,
    ]);
    $desired = new DesiredPublication(2, 7, '10.0.0.44', [
        new DesiredResource(70, 25565, PublicationMode::Tcp, null),
    ]);

    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listResources')->once()->andReturn([]);
        $mock->shouldReceive('getResource')->once()->with(201)->andReturn(['resourceId' => 201]);
        $mock->shouldReceive('updateTarget')->once()->with(202, 7, '10.0.0.44', 25565, PublicationMode::Tcp)
            ->andThrow(new PangolinApiException('Target not found.', 404));
        $mock->shouldReceive('listTargets')->once()->with(201)->andReturn([]);
        $mock->shouldReceive('createTarget')->once()->with(201, 7, '10.0.0.44', 25565, PublicationMode::Tcp)
            ->andReturn(['targetId' => 203]);
        $mock->shouldReceive('updateResource')->once()->andReturn(['resourceId' => 201]);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);

    $reconciler->reconcile($publication, $desired);

    expect($publication->resources()->firstOrFail()->pangolin_target_id)->toBe(203);
});

it('publishes HTTP only with an explicit verified domain and stores Pangolins confirmed domain', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 15,
        'server_uuid' => '1973b219-960e-4519-a311-b831d5691f1b',
        'opted_in' => true,
        'desired_revision' => 1,
    ]);
    $desired = new DesiredPublication(1, 7, 'web.internal', [
        new DesiredResource(80, 8080, PublicationMode::Https, 'play.example.com'),
    ]);

    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listDomains')->once()->andReturn([
            ['domainId' => 'unverified-domain', 'baseDomain' => 'example.com'],
            ['domainId' => 'domain-1', 'baseDomain' => 'example.com', 'verified' => true],
        ]);
        $mock->shouldReceive('listResources')->once()->andReturn([]);
        $mock->shouldReceive('createHttpResource')->once()
            ->with('pelican-1973b219960e-80', 'domain-1', 'play')
            ->andReturn(['resourceId' => 301, 'fullDomain' => 'play.example.com']);
        $mock->shouldReceive('createTarget')->once()
            ->with(301, 7, 'web.internal', 8080, PublicationMode::Https)
            ->andReturn(['targetId' => 302]);
        $mock->shouldReceive('listTargets')->once()->with(301)->andReturn([]);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);

    $reconciler->reconcile($publication, $desired);

    $owned = $publication->resources()->firstOrFail();
    expect($owned->public_port)->toBeNull()
        ->and($owned->confirmed_domain)->toBe('play.example.com');
});

it('updates the domain of an existing HTTP resource', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 16,
        'server_uuid' => '8a04496a-386f-43cf-8d2f-32a9b0995074',
        'opted_in' => true,
        'desired_revision' => 2,
    ]);
    $publication->resources()->create([
        'allocation_id' => 90,
        'mode' => PublicationMode::Https,
        'pangolin_resource_id' => 401,
        'pangolin_target_id' => 402,
        'confirmed_domain' => 'old.example.com',
    ]);
    $desired = new DesiredPublication(2, 7, 'web.internal', [
        new DesiredResource(90, 8443, PublicationMode::Https, 'new.example.net'),
    ]);

    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listResources')->once()->andReturn([]);
        $mock->shouldReceive('getResource')->once()->with(401)->andReturn(['resourceId' => 401]);
        $mock->shouldReceive('updateTarget')->once()->with(402, 7, 'web.internal', 8443, PublicationMode::Https)->andReturn(['targetId' => 402]);
        $mock->shouldReceive('listDomains')->once()->andReturn([
            ['domainId' => 'domain-2', 'baseDomain' => 'example.net', 'verified' => true],
        ]);
        $mock->shouldReceive('updateResource')->once()->with(401, [
            'name' => 'pelican-8a04496a386f-90',
            'enabled' => true,
            'domainId' => 'domain-2',
            'subdomain' => 'new',
        ])->andReturn(['resourceId' => 401, 'fullDomain' => 'new.example.net']);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);

    $reconciler->reconcile($publication, $desired);

    expect($publication->resources()->firstOrFail()->confirmed_domain)->toBe('new.example.net');
});

it('reuses one ownership row when switching an allocation between HTTP methods', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 19,
        'server_uuid' => '46db2070-49f8-48ba-b01f-0da272ae5fd0',
        'opted_in' => true,
        'desired_revision' => 2,
    ]);
    $publication->resources()->create([
        'allocation_id' => 120,
        'mode' => PublicationMode::Https,
        'pangolin_resource_id' => 701,
        'pangolin_target_id' => 702,
        'confirmed_domain' => 'play.example.com',
    ]);
    $desired = new DesiredPublication(2, 7, 'web.internal', [
        new DesiredResource(120, 8080, PublicationMode::Http, 'play.example.com'),
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listResources')->once()->andReturn([
            ['resourceId' => 701, 'name' => 'pelican-46db207049f8-120', 'mode' => 'http', 'fullDomain' => 'play.example.com'],
        ]);
        $mock->shouldReceive('getResource')->once()->with(701)->andReturn(['resourceId' => 701]);
        $mock->shouldReceive('updateTarget')->once()->with(702, 7, 'web.internal', 8080, PublicationMode::Http)->andReturn(['targetId' => 702]);
        $mock->shouldReceive('listDomains')->once()->andReturn([
            ['domainId' => 'domain-1', 'baseDomain' => 'example.com', 'verified' => true],
        ]);
        $mock->shouldReceive('updateResource')->once()->andReturn(['resourceId' => 701, 'fullDomain' => 'play.example.com']);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);

    $reconciler->reconcile($publication, $desired);

    expect($publication->resources()->get())->toHaveCount(1)
        ->and($publication->resources()->firstOrFail()->mode)->toBe(PublicationMode::Http)
        ->and($publication->resources()->firstOrFail()->pangolin_resource_id)->toBe(701);
});

it('adopts one exact deterministic resource and target after an ambiguous create outcome', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 17,
        'server_uuid' => 'ef02fb28-c49a-4f4c-95a3-ab8b0b6d9faa',
        'opted_in' => true,
        'desired_revision' => 1,
    ]);
    $desired = new DesiredPublication(1, 7, '10.0.0.50', [
        new DesiredResource(100, 25565, PublicationMode::Tcp, null),
    ]);

    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listResources')->once()->andReturn([
            ['resourceId' => 501, 'name' => 'pelican-ef02fb28c49a-100', 'mode' => 'tcp', 'proxyPort' => 25565],
        ]);
        $mock->shouldReceive('createRawResource')->never();
        $mock->shouldReceive('listTargets')->once()->with(501)->andReturn([
            ['targetId' => 502, 'siteId' => 7, 'ip' => '10.0.0.50', 'port' => 25565, 'mode' => 'tcp'],
        ]);
        $mock->shouldReceive('createTarget')->never();
        $mock->shouldReceive('updateResource')->once()->with(501, [
            'name' => 'pelican-ef02fb28c49a-100',
            'enabled' => true,
            'proxyPort' => 25565,
        ])->andReturn(['resourceId' => 501]);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565', '25565'), new ResourceNaming);

    $reconciler->reconcile($publication, $desired);

    $owned = $publication->resources()->firstOrFail();
    expect($owned->pangolin_resource_id)->toBe(501)
        ->and($owned->pangolin_target_id)->toBe(502);
});

it('falls back to the next pool port when Pangolin already uses the allocation port', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 18,
        'server_uuid' => 'ca1756f7-7a78-4960-965b-649f37ddf432',
        'opted_in' => true,
        'desired_revision' => 1,
    ]);
    $desired = new DesiredPublication(1, 7, '10.0.0.51', [
        new DesiredResource(110, 25565, PublicationMode::Tcp, null),
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listResources')->once()->andReturn([
            ['resourceId' => 601, 'name' => 'foreign-resource', 'mode' => 'tcp', 'proxyPort' => 25565],
        ]);
        $mock->shouldReceive('createRawResource')->once()->with('pelican-ca1756f77a78-110', PublicationMode::Tcp, 25566)
            ->andReturn(['resourceId' => 602]);
        $mock->shouldReceive('listTargets')->once()->with(602)->andReturn([]);
        $mock->shouldReceive('createTarget')->once()->with(602, 7, '10.0.0.51', 25565, PublicationMode::Tcp)
            ->andReturn(['targetId' => 603]);
    });
    $reconciler = new PublicationReconciler($client, PublicPortAllocator::from('25565-25566', '25565'), new ResourceNaming);

    $reconciler->reconcile($publication, $desired);

    expect($publication->resources()->firstOrFail()->public_port)->toBe(25566);
});
