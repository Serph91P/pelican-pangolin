<?php

namespace PelicanDev\PelicanPangolin\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PelicanDev\PelicanPangolin\Providers\PelicanPangolinServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PelicanPangolinServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('pelican-pangolin.public_host', 'games.example.com');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
