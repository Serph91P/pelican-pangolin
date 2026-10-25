<?php

use PelicanDev\PelicanPangolin\Services\DesiredStateBuilder;
use PelicanDev\PelicanPangolin\Services\PluginSettings;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;

it('never silently remaps a conflicting or out-of-pool allocation', function (int $port, array $reserved) {
    expect(fn () => PublicPortAllocator::from('25565-25566', '25565')->allocate('tcp', $port, $reserved))
        ->toThrow(RuntimeException::class);
})->with([[25565, [25565]], [25567, []]]);

it('bounds pools and excludes reserved infrastructure ports', function (string $pool) {
    expect(fn () => PublicPortAllocator::from($pool, '25565'))->toThrow(InvalidArgumentException::class);
})->with(['1-65535', '80', '443', '2022', '8080', '51820', '30000-32000']);

it('only publishes approved game and query protocols and supports both on one allocation', function () {
    $state = (new DesiredStateBuilder)->build(1, ['desired_revision' => 1],
        ['enabled' => true, 'site_id' => 7, 'target_host' => 'node.internal'],
        [['id' => 1, 'port' => 25565], ['id' => 2, 'port' => 25566], ['id' => 3, 'port' => 25567]], [], [
            1 => ['mode' => 'both', 'purpose' => 'game'],
            2 => ['mode' => 'tcp', 'purpose' => 'rcon'],
            3 => ['mode' => 'udp'],
        ], null);
    expect(array_map(fn ($r) => $r->mode->value, $state->resources))->toBe(['tcp', 'udp'])
        ->and(array_map(fn ($r) => $r->allocationId, $state->resources))->toBe([1, 1]);
});

it('does not reuse a positional Egg approval for another port', function () {
    $state = (new DesiredStateBuilder)->build(1, ['desired_revision' => 1],
        ['enabled' => true, 'site_id' => 7, 'target_host' => 'node.internal'],
        [['id' => 2, 'port' => 25566]], [0 => ['mode' => 'tcp', 'purpose' => 'game', 'allocation_port' => 25565]], [], null);
    expect($state->resources)->toBe([]);
});

it('validates explicit public hosts independently of API DNS', function (string $host) {
    expect(fn () => (new PluginSettings)->validate([
        'api_url' => 'https://api.example.com', 'organization_id' => 'org', 'token' => 'test',
        'tcp_ports' => '25565', 'udp_ports' => '25565', 'public_host' => $host,
    ]))->toThrow(InvalidArgumentException::class);
})->with(['', 'https://games.example.com', 'games.example.com:25565', '[2001:db8::1]', "bad\nhost", '127.0.0.1', '10.0.0.1', '::1']);
