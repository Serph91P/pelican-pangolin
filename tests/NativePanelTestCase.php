<?php

namespace PelicanDev\PelicanPangolin\Tests;

use Filament\FilamentManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase;

abstract class NativePanelTestCase extends TestCase
{
    public function createApplication()
    {
        $root = dirname(__DIR__);
        $panel = $root.'/pelican-panel';
        if (! is_file($root.'/build/pelican-pangolin.zip')) {
            throw new \RuntimeException('Build the plugin before native integration tests.');
        }
        $zip = new \ZipArchive;
        $zip->open($root.'/build/pelican-pangolin.zip');
        $zip->extractTo($panel.'/plugins');
        $zip->close();
        $manifest = $panel.'/plugins/pelican-pangolin/plugin.json';
        $metadata = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $metadata['meta'] = ['status' => 'enabled'];
        file_put_contents($manifest, json_encode($metadata, JSON_THROW_ON_ERROR));
        $app = require $panel.'/bootstrap/app.php';
        // rc.2 intentionally skips plugins in APP_ENV=testing. Use local mode
        // with an explicitly isolated in-memory database before provider registration.
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $app['env'] = 'local';
            $app['config']->set([
                'app.env' => 'local',
                'app.key' => 'base64:'.base64_encode(random_bytes(32)),
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'database',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();
        $app->make(FilamentManager::class)->getPanels();
        // Native discovery ran above; enable framework test-only form helpers now.
        $app['env'] = 'testing';
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'database',
            'pelican-pangolin.public_host' => 'games.example.com',
            'pelican-pangolin.tcp_ports' => '25565-25566',
            'pelican-pangolin.udp_ports' => '25565-25566',
        ]);

        return $app;
    }
}
