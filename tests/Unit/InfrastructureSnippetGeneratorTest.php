<?php

use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;

it('generates read-only firewall, Compose, and Traefik raw-port snippets', function () {
    $snippets = (new InfrastructureSnippetGenerator)->generate('30000-30001', '31000');

    expect($snippets['firewall'])->toContain('30000:30001/tcp', '31000/udp')
        ->and($snippets['compose'])->toContain('30000-30001:30000-30001/tcp', '31000:31000/udp')
        ->and($snippets['traefik'])->toContain('tcp-30000', 'tcp-30001', 'udp-31000')
        ->and($snippets['warning'])->toContain('does not apply');
});
