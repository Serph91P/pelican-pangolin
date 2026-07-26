<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Services\NodeMappingValidator;

uses(RefreshDatabase::class);

it('stores exactly one enabled Pangolin site mapping per Pelican node', function () {
    expect(Schema::hasColumns('pangolin_node_mappings', [
        'node_id',
        'site_id',
        'site_nice_id',
        'target_host',
        'enabled',
        'validated_at',
        'validation_error',
    ]))->toBeTrue();

    $mapping = NodeMapping::query()->create([
        'node_id' => 42,
        'site_id' => 7,
        'site_nice_id' => 'stable-site',
        'target_host' => '10.0.0.42',
        'enabled' => true,
    ]);

    expect($mapping->enabled)->toBeTrue()
        ->and($mapping->site_id)->toBe(7);

    NodeMapping::query()->create([
        'node_id' => 42,
        'site_id' => 8,
        'site_nice_id' => 'other-site',
        'target_host' => '10.0.0.43',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('invalidates a mapping when its remote identity or target changes', function () {
    $mapping = NodeMapping::query()->create([
        'node_id' => 42,
        'site_id' => 7,
        'site_nice_id' => 'stable-site',
        'target_host' => '10.0.0.42',
        'enabled' => true,
        'validated_at' => now(),
    ]);

    $mapping->update(['target_host' => '10.0.0.43']);

    expect($mapping->fresh()->validated_at)->toBeNull()
        ->and($mapping->fresh()->validation_error)->toBe('Mapping changed and must be validated again.');
});

it('validates a site only when it is listed in the configured organization', function () {
    $mapping = NodeMapping::query()->create([
        'node_id' => 42,
        'site_id' => 7,
        'site_nice_id' => 'stable-site',
        'target_host' => '10.0.0.42',
        'enabled' => true,
    ]);
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('listSites')->once()->andReturn([
            ['siteId' => 7, 'niceId' => 'stable-site'],
        ]);
    });

    expect((new NodeMappingValidator($client))->validate($mapping))->toBeTrue()
        ->and($mapping->fresh()->validated_at)->not->toBeNull();
});
