<?php

use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;

it('generates read-only firewall, Compose, and Traefik raw-port snippets', function () {
    $snippets = (new InfrastructureSnippetGenerator)->generate([['protocol' => 'tcp', 'port' => 30000], ['protocol' => 'tcp', 'port' => 30001], ['protocol' => 'udp', 'port' => 31000]]);

    expect($snippets['firewall'])->toContain('tcp/30000', 'tcp/30001', 'udp/31000')
        ->and($snippets['compose'])->toContain('30000:30000/tcp', '30001:30001/tcp', '31000:31000/udp')
        ->and($snippets['traefik'])->toContain('tcp-30000', 'tcp-30001', 'udp-31000')
        ->and($snippets['warning'])->toContain('Generated only', 'not verified');
});
