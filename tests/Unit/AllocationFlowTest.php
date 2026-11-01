<?php

use PelicanDev\PelicanPangolin\Services\DesiredStateBuilder;
use PelicanDev\PelicanPangolin\Services\PluginSettings;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;

it('accepts pool-free raw and HTTP settings and ignores legacy pool values', function (string $host) {
    $data = ['api_url' => 'https://api.example.com', 'organization_id' => 'org', 'token' => 'fixture', 'public_host' => $host, 'tcp_ports' => 'invalid', 'udp_ports' => 'invalid'];
    $settings = new PluginSettings;
    $settings->validate($data);
    expect($settings->environment($data, ''))->not->toHaveKeys(['PANGOLIN_TCP_PORTS', 'PANGOLIN_UDP_PORTS']);
    expect($settings->formData($data))->not->toHaveKeys(['tcp_ports', 'udp_ports']);
})->with(['games.example.com', '']);

it('requires per-ID approval even when a legacy Egg port matches', function () {
    $state = (new DesiredStateBuilder)->build(1, ['desired_revision' => 1], ['enabled' => true, 'site_id' => 7, 'target_host' => 'node.internal'], [
        ['id' => 1, 'ip' => '10.0.0.1', 'port' => 30000], ['id' => 2, 'ip' => '10.0.0.2', 'port' => 30001],
    ], [['allocation_port' => 30001, 'purpose' => 'game', 'mode' => 'tcp']], [1 => ['purpose' => 'game', 'mode' => 'both']], null);
    expect(array_column($state->resources, 'allocationId'))->toBe([1, 1]);
    expect(array_column($state->resources, 'targetHost'))->toBe(['10.0.0.1', '10.0.0.1']);
    expect(array_column($state->resources, 'bindIp'))->toBe(['10.0.0.1', '10.0.0.1']);
});

it('uses concrete bind IPs and only explicit mapping for wildcard addresses', function (string $ip, string $target) {
    $state = (new DesiredStateBuilder)->build(1, ['desired_revision' => 1], ['enabled' => true, 'site_id' => 7, 'target_host' => 'node.internal'], [['id' => 1, 'ip' => $ip, 'port' => 30000]], [], [1 => ['purpose' => 'query', 'mode' => 'udp']], null);
    expect($state->resources[0]->targetHost)->toBe($target)->and($state->resources[0]->bindIp)->toBe($ip);
})->with([['10.0.0.2', '10.0.0.2'], ['2001:db8::2', '2001:db8::2'], ['0.0.0.0', 'node.internal'], ['::', 'node.internal']]);

it('blocks wildcard targets without a reachable mapping', function () {
    (new DesiredStateBuilder)->build(1, ['desired_revision' => 1], ['enabled' => true, 'site_id' => 7, 'target_host' => ''], [['id' => 1, 'ip' => '::', 'port' => 30000]], [], [1 => ['purpose' => 'game', 'mode' => 'tcp']], null);
})->throws(DomainException::class);

it('allocates exact approved ports without a pool and retains conflict and protected-port checks', function () {
    $allocator = new PublicPortAllocator;
    expect($allocator->allocate('tcp', 30000, []))->toBe(30000);
    expect($allocator->allocate('udp', 30000, []))->toBe(30000);
    expect(fn () => $allocator->allocate('tcp', 30000, [30000]))->toThrow(RuntimeException::class);
    foreach ([80, 443, 2022, 8080, 8443, 51820, 51821, 65536] as $port) {
        expect(fn () => $allocator->allocate('udp', $port, []))->toThrow(InvalidArgumentException::class);
    }
});

it('rejects two concrete IPs competing for the same public host port and protocol before publication', function () {
    (new DesiredStateBuilder)->build(1, ['desired_revision' => 1], ['enabled' => true, 'site_id' => 7, 'target_host' => 'node.internal'], [
        ['id' => 1, 'ip' => '10.0.0.1', 'port' => 30000], ['id' => 2, 'ip' => '10.0.0.2', 'port' => 30000],
    ], [], [1 => ['purpose' => 'game', 'mode' => 'tcp'], 2 => ['purpose' => 'query', 'mode' => 'both']], null);
})->throws(DomainException::class, 'same public');
