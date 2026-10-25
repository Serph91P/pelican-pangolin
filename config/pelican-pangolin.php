<?php

return [
    'api_url' => env('PANGOLIN_API_URL'),
    'organization_id' => env('PANGOLIN_ORGANIZATION_ID'),
    'token' => env('PANGOLIN_API_TOKEN'),
    'default_domain' => env('PANGOLIN_DEFAULT_DOMAIN'),
    'public_host' => env('PANGOLIN_PUBLIC_HOST'),
    'tcp_ports' => env('PANGOLIN_TCP_PORTS', ''),
    'udp_ports' => env('PANGOLIN_UDP_PORTS', ''),
    'connect_timeout' => (int) env('PANGOLIN_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('PANGOLIN_TIMEOUT', 10),
];
