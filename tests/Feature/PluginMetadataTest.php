<?php

use Illuminate\Support\Facades\File;

it('declares installable administrator-only Pelican metadata', function () {
    $path = dirname(__DIR__, 2).'/plugin.json';

    expect(File::exists($path))->toBeTrue();

    $metadata = File::json($path, JSON_THROW_ON_ERROR);

    expect($metadata)->toMatchArray([
        'id' => 'pelican-pangolin',
        'name' => 'Pelican Pangolin',
        'category' => 'plugin',
        'namespace' => 'PelicanDev\\PelicanPangolin',
        'class' => 'PelicanPangolinPlugin',
        'panels' => ['admin'],
        'panel_version' => '^1.0.0',
        'composer_packages' => null,
    ]);
});
