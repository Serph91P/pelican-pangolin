<?php

namespace PelicanDev\PelicanPangolin\Services;

use InvalidArgumentException;
use RuntimeException;

final readonly class PublicPortAllocator
{
    /** @param list<int> $reserved */
    public function allocate(string $protocol, int $allocationPort, array $reserved): int
    {
        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            throw new InvalidArgumentException('Protocol must be TCP or UDP.');
        }
        if ($allocationPort < 1024 || $allocationPort > 65535 || in_array($allocationPort, [2022, 8080, 8443, 51820, 51821], true)) {
            throw new InvalidArgumentException('The allocation overlaps a protected management/tunnel port or is outside 1024–65535.');
        }
        if (in_array($allocationPort, $reserved, true)) {
            throw new RuntimeException('The exact public allocation port conflicts with another resource. Port remapping is not supported.');
        }

        return $allocationPort;
    }
}
