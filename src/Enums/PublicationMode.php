<?php

namespace PelicanDev\PelicanPangolin\Enums;

enum PublicationMode: string
{
    case Tcp = 'tcp';
    case Udp = 'udp';
    case Both = 'both';
    case Http = 'http';
    case Https = 'https';
    case Ignored = 'ignored';

    public function isHttp(): bool
    {
        return $this === self::Http || $this === self::Https;
    }
}
