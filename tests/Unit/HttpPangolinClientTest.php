<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Exceptions\PangolinApiException;
use PelicanDev\PelicanPangolin\Services\HttpPangolinClient;

it('uses the v1 integration API contract for resources and targets', function () {
    Http::fake([
        'https://pangolin.example/v1/org/acme/public-resource' => Http::response([
            'data' => ['resourceId' => 91, 'fullDomain' => 'game.example.com'],
            'success' => true,
        ], 201),
        'https://pangolin.example/v1/public-resource/91/target' => Http::response([
            'data' => ['targetId' => 92],
            'success' => true,
        ], 201),
        'https://pangolin.example/v1/public-resource/91' => Http::response([
            'data' => ['resourceId' => 91, 'enabled' => false],
            'success' => true,
        ]),
        'https://pangolin.example/v1/target/92' => Http::response([
            'data' => ['targetId' => 92],
            'success' => true,
        ]),
    ]);

    $client = new HttpPangolinClient('https://pangolin.example/', 'acme', 'top-secret', 2, 8);

    expect($client->createRawResource('pelican-test', PublicationMode::Tcp, 25565)['resourceId'])->toBe(91)
        ->and($client->createHttpResource('pelican-web', 'domain-1', 'game')['fullDomain'])->toBe('game.example.com')
        ->and($client->createTarget(91, 7, '10.0.0.42', 25565, PublicationMode::Tcp)['targetId'])->toBe(92);

    $client->updateResource(91, ['enabled' => false]);
    $client->updateTarget(92, 7, '10.0.0.43', 25566, PublicationMode::Https);
    $client->deleteResource(91);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://pangolin.example/v1/org/acme/public-resource'
        && $request->data() === ['name' => 'pelican-test', 'mode' => 'tcp', 'proxyPort' => 25565]
        && $request->hasHeader('Authorization', 'Bearer top-secret'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://pangolin.example/v1/org/acme/public-resource'
        && $request->data() === ['name' => 'pelican-web', 'mode' => 'http', 'domainId' => 'domain-1', 'subdomain' => 'game']);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://pangolin.example/v1/public-resource/91/target'
        && $request->data() === ['siteId' => 7, 'ip' => '10.0.0.42', 'port' => 25565, 'mode' => 'tcp', 'enabled' => true]);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pangolin.example/v1/target/92'
        && $request->data()['method'] === 'https');
});

it('paginates inventory responses', function () {
    Http::fakeSequence()
        ->push(['data' => ['domains' => [['domainId' => 'one']], 'pagination' => ['total' => 2, 'limit' => 1, 'offset' => 0]], 'success' => true])
        ->push(['data' => ['domains' => [['domainId' => 'two']], 'pagination' => ['total' => 2, 'limit' => 1, 'offset' => 1]], 'success' => true]);

    $domains = (new HttpPangolinClient('https://pangolin.example', 'acme', 'secret'))->listDomains(1);

    expect(array_column($domains, 'domainId'))->toBe(['one', 'two']);
});

it('paginates page-number inventories using Pangolins total and pageSize fields', function () {
    Http::fakeSequence()
        ->push(['data' => ['sites' => [['siteId' => 1]], 'pagination' => ['total' => 2, 'pageSize' => 1, 'page' => 1]], 'success' => true])
        ->push(['data' => ['sites' => [['siteId' => 2]], 'pagination' => ['total' => 2, 'pageSize' => 1, 'page' => 2]], 'success' => true]);

    $sites = (new HttpPangolinClient('https://pangolin.example', 'acme', 'secret'))->listSites(1);

    expect(array_column($sites, 'siteId'))->toBe([1, 2]);
});

it('never exposes a bearer token in normalized API failures', function () {
    Http::fake(fn () => Http::response([
        'message' => 'Authorization Bearer top-secret was rejected: top-secret',
    ], 403));

    try {
        (new HttpPangolinClient('https://pangolin.example', 'acme', 'top-secret'))->listSites();
    } catch (PangolinApiException $exception) {
        expect($exception->getMessage())->not->toContain('top-secret')
            ->and($exception->getMessage())->toContain('[REDACTED]')
            ->and($exception->status)->toBe(403);

        return;
    }

    $this->fail('Expected a PangolinApiException.');
});

it('normalizes API error statuses', function (int $status) {
    Http::fake(fn () => Http::response(['message' => 'Request rejected'], $status));

    try {
        (new HttpPangolinClient('https://pangolin.example', 'acme', 'secret'))->getResource(91);
    } catch (PangolinApiException $exception) {
        expect($exception->status)->toBe($status)
            ->and($exception->getMessage())->toBe('Request rejected');

        return;
    }

    $this->fail('Expected a PangolinApiException.');
})->with([404, 409, 422, 429, 500, 503]);

it('rejects an insecure or ambiguous API base URL', function (string $url) {
    new HttpPangolinClient($url, 'acme', 'secret');
})->with([
    'http://pangolin.example',
    'https://user@pangolin.example',
    'https://user:pass@pangolin.example',
    'https://pangolin.example?token=value',
])->throws(InvalidArgumentException::class);
