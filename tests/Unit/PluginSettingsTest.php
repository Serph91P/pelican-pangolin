<?php

use PelicanDev\PelicanPangolin\Services\PluginSettings;

it('never returns a stored token to the settings form and retains it on blank save', function () {
    $settings = new PluginSettings;
    $configured = [
        'api_url' => 'https://pangolin.example',
        'organization_id' => 'acme',
        'token' => 'stored-secret',
        'tcp_ports' => '25565',
        'udp_ports' => '19132',
    ];

    expect($settings->formData($configured)['token'])->toBe('')
        ->and($settings->environment([
            'api_url' => 'https://pangolin.example',
            'organization_id' => 'acme',
            'token' => '',
            'default_domain' => 'example.com',
            'tcp_ports' => '25565',
            'udp_ports' => '19132',
            'connect_timeout' => 3,
            'timeout' => 10,
        ], 'stored-secret')['PANGOLIN_API_TOKEN'])->toBe('stored-secret');
});

it('rejects settings without HTTPS or valid protocol-specific pools', function (array $data) {
    (new PluginSettings)->validate($data);
})->with([
    [['api_url' => 'http://pangolin.example', 'organization_id' => 'acme', 'token' => 'x', 'tcp_ports' => '25565', 'udp_ports' => '19132']],
    [['api_url' => 'https://user@pangolin.example', 'organization_id' => 'acme', 'token' => 'x', 'tcp_ports' => '25565', 'udp_ports' => '19132']],
    [['api_url' => 'https://pangolin.example', 'organization_id' => 'acme', 'token' => 'x', 'tcp_ports' => 'bad', 'udp_ports' => '19132']],
])->throws(InvalidArgumentException::class);
