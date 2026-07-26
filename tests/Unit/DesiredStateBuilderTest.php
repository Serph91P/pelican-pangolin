<?php

use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Services\DesiredStateBuilder;

it('builds explicit overrides before Egg suggestions and ignores unknown allocations', function () {
    $state = (new DesiredStateBuilder)->build(
        expectedRevision: 3,
        server: [
            'uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886',
            'desired_revision' => 3,
        ],
        mapping: ['site_id' => 7, 'target_host' => '10.0.0.42', 'enabled' => true],
        allocations: [
            ['id' => 50, 'port' => 25565],
            ['id' => 51, 'port' => 19132],
            ['id' => 52, 'port' => 8080],
        ],
        profileRules: [
            0 => ['mode' => 'tcp'],
            1 => ['mode' => 'udp'],
        ],
        overrides: [
            50 => ['mode' => 'https', 'domain' => 'games.example.com', 'subdomain' => 'survival'],
        ],
        defaultDomain: 'example.com',
    );

    expect($state->revision)->toBe(3)
        ->and($state->siteId)->toBe(7)
        ->and($state->resources)->toHaveCount(2)
        ->and($state->resources[0]->mode)->toBe(PublicationMode::Https)
        ->and($state->resources[0]->domain)->toBe('survival.games.example.com')
        ->and($state->resources[1]->mode)->toBe(PublicationMode::Udp);
});

it('fails closed for stale revisions and incomplete HTTP configuration', function (int $expected, int $actual, array $rule, ?string $domain) {
    (new DesiredStateBuilder)->build(
        expectedRevision: $expected,
        server: ['uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'desired_revision' => $actual],
        mapping: ['site_id' => 7, 'target_host' => 'host.internal', 'enabled' => true],
        allocations: [['id' => 50, 'port' => 8080]],
        profileRules: [0 => $rule],
        overrides: [],
        defaultDomain: $domain,
    );
})->with([
    'stale revision' => [2, 3, ['mode' => 'tcp'], 'example.com'],
    'missing HTTP domain' => [3, 3, ['mode' => 'http'], null],
    'invalid mode' => [3, 3, ['mode' => 'smtp'], 'example.com'],
])->throws(DomainException::class);
