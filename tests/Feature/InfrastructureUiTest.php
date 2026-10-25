<?php

use PelicanDev\PelicanPangolin\Filament\Admin\Pages\Infrastructure;
use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;

it('renders exact copyable text with accessible labels and escapes hostile markup', function () {
    $blocks = (new InfrastructureSnippetGenerator)->generate('30000', '30000');
    $blocks['firewall'] = '<script>alert(1)</script>';
    $html = view('pelican-pangolin::infrastructure-blocks', ['snippets' => $blocks])->render();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    foreach (['compose', 'traefik', 'pangolin', 'firewall'] as $key) {
        expect($xpath->query('//textarea[@id="pangolin-'.$key.'"]')->item(0)->textContent)->toBe($blocks[$key]);
        expect($xpath->query('//button[@aria-controls="pangolin-'.$key.'"]')->length)->toBe(1);
    }
    expect($html)->not->toContain('<script>')->toContain('navigator.clipboard.writeText', 'aria-live="polite"');
});

it('rejects anonymous access to global infrastructure templates', function () {
    expect(Infrastructure::canAccess())->toBeFalse();
});
