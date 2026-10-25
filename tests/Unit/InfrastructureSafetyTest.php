<?php

use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;
use Symfony\Component\Yaml\Yaml;

it('generates deterministic parseable bounded blocks and provider-neutral guidance', function () {
    $generator = new InfrastructureSnippetGenerator;
    $blocks = $generator->generate('30002,30000-30001', '30000');
    expect($blocks)->toBe($generator->generate('30000-30002', '30000'));
    expect(Yaml::parse($blocks['traefik']))->toBe(['entryPoints' => [
        'tcp-30000' => ['address' => ':30000/tcp'], 'tcp-30001' => ['address' => ':30001/tcp'],
        'tcp-30002' => ['address' => ':30002/tcp'], 'udp-30000' => ['address' => ':30000/udp'],
    ]]);
    expect(Yaml::parse($blocks['compose']))->toBe(['services' => ['gerbil' => ['ports' => ['30000:30000/tcp', '30001:30001/tcp', '30002:30002/tcp', '30000:30000/udp']]]]);
    expect(Yaml::parse($blocks['pangolin']))->toBe(['flags' => ['allow_raw_resources' => true]])
        ->and($blocks['firewall'])->not->toContain('ufw', 'compose down')
        ->and($blocks['warning'])->toContain('Traefik', 'recreation', 'Dockhand', 'not verified');
});
