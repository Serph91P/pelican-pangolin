<?php

use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;

it('derives sorted deduplicated infrastructure from explicit protocol-port pairs and retains cleanup demand', function () {
    $generator = new InfrastructureSnippetGenerator;
    $pairs = [['protocol' => 'udp', 'port' => 30002], ['protocol' => 'tcp', 'port' => 30000], ['protocol' => 'tcp', 'port' => 30000]];
    $retained = [['protocol' => 'tcp', 'port' => 30001]];
    $blocks = $generator->generate($pairs, $retained);
    expect($blocks['traefik'])->toBe("entryPoints:\n  tcp-30000:\n    address: \":30000/tcp\"\n  tcp-30001:\n    address: \":30001/tcp\"\n  udp-30002:\n    address: \":30002/udp\"");
    expect($blocks['warning'])->toContain('Desired: tcp/30000, udp/30002', 'Remote rows retained: tcp/30001', 'Do not remove');
    expect($blocks)->toBe($generator->generate(array_reverse($pairs), $retained));
});

it('renders an empty infrastructure demand without invalid pool or empty YAML parents', function () {
    $blocks = (new InfrastructureSnippetGenerator)->generate([]);
    expect($blocks['warning'])->toContain('No approved raw allocations')->and($blocks['compose'])->toBe('')->and($blocks['traefik'])->toBe('')->and($blocks['pangolin'])->toBe('');
});
