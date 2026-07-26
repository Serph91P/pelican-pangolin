<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;

uses(RefreshDatabase::class);

it('preflights inventory and reports actionable non-secret failures', function () {
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listSites')->once()->andReturn([
            ['siteId' => 7, 'niceId' => 'pelican-node', 'online' => true],
        ]);
        $mock->shouldReceive('listDomains')->once()->andReturn([
            ['domainId' => 'domain-1', 'baseDomain' => 'example.com', 'verified' => true],
        ]);
        $mock->shouldReceive('listResources')->once()->andReturn([
            ['resourceId' => 91, 'mode' => 'tcp'],
        ]);
    });
    $this->app->instance(PangolinClient::class, $client);

    $this->artisan('pangolin:preflight', ['--site' => 'pelican-node', '--domain' => 'example.com'])
        ->expectsOutputToContain('Pangolin preflight passed')
        ->doesntExpectOutputToContain('token')
        ->assertSuccessful();
});

it('fails preflight when the requested site or domain is unavailable', function () {
    $client = Mockery::mock(PangolinClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('listSites')->once()->andReturn([]);
        $mock->shouldReceive('listDomains')->once()->andReturn([]);
        $mock->shouldReceive('listResources')->once()->andReturn([]);
    });
    $this->app->instance(PangolinClient::class, $client);

    $this->artisan('pangolin:preflight', ['--site' => 'missing', '--domain' => 'missing.example'])
        ->expectsOutputToContain('Pangolin site was not found')
        ->expectsOutputToContain('Pangolin domain was not found')
        ->assertFailed();
});
