<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

uses(RefreshDatabase::class);

it('persists desired state and exact owned remote identifiers', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 12,
        'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
        'opted_in' => true,
        'desired_revision' => 4,
        'status' => SyncStatus::Pending,
        'desired_state' => ['allocations' => ['55' => ['mode' => 'tcp']]],
    ]);

    $resource = $publication->resources()->create([
        'allocation_id' => 55,
        'mode' => PublicationMode::Tcp,
        'pangolin_resource_id' => 901,
        'pangolin_target_id' => 902,
        'public_port' => 25565,
    ]);

    expect($publication->opted_in)->toBeTrue()
        ->and($publication->status)->toBe(SyncStatus::Pending)
        ->and($publication->desired_state['allocations']['55']['mode'])->toBe('tcp')
        ->and($resource->pangolin_resource_id)->toBe(901);

    $publication->resources()->create([
        'allocation_id' => 56,
        'mode' => PublicationMode::Tcp,
        'public_port' => 25565,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows TCP and UDP to reserve the same numeric public port', function () {
    $publication = ServerPublication::query()->create([
        'server_id' => 13,
        'server_uuid' => '10b8c7c9-ee30-4377-9493-66e04f14aa01',
    ]);

    $publication->resources()->create([
        'allocation_id' => 60,
        'mode' => PublicationMode::Tcp,
        'public_port' => 27015,
    ]);
    $publication->resources()->create([
        'allocation_id' => 60,
        'mode' => PublicationMode::Udp,
        'public_port' => 27015,
    ]);

    expect($publication->resources()->count())->toBe(2);
});
