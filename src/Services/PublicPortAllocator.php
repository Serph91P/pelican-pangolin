<?php

namespace PelicanDev\PelicanPangolin\Services;

use InvalidArgumentException;
use PelicanDev\PelicanPangolin\Support\PortRange;
use RuntimeException;

final readonly class PublicPortAllocator
{
    /** @param array<string, list<PortRange>> $pools */
    private function __construct(private array $pools) {}

    public static function from(string $tcpPool, string $udpPool): self
    {
        return new self([
            'tcp' => self::parsePool($tcpPool),
            'udp' => self::parsePool($udpPool),
        ]);
    }

    /** @param list<int> $reserved */
    public function allocate(string $protocol, int $allocationPort, array $reserved): int
    {
        $ranges = $this->pools[strtolower($protocol)] ?? throw new InvalidArgumentException('Protocol must be TCP or UDP.');
        $reservedLookup = array_fill_keys($reserved, true);

        foreach ($ranges as $range) {
            if ($range->contains($allocationPort) && ! isset($reservedLookup[$allocationPort])) {
                return $allocationPort;
            }
        }

        foreach ($ranges as $range) {
            for ($port = $range->start; $port <= $range->end; $port++) {
                if (! isset($reservedLookup[$port])) {
                    return $port;
                }
            }
        }

        throw new RuntimeException('No '.strtoupper($protocol).' public ports are available.');
    }

    /** @return list<PortRange> */
    private static function parsePool(string $pool): array
    {
        $values = array_filter(array_map('trim', explode(',', $pool)), fn (string $value): bool => $value !== '');

        if ($values === []) {
            throw new InvalidArgumentException('Port pool cannot be empty.');
        }

        $ranges = array_map(PortRange::parse(...), $values);
        usort($ranges, fn (PortRange $a, PortRange $b): int => $a->start <=> $b->start);

        for ($index = 1; $index < count($ranges); $index++) {
            if ($ranges[$index]->start <= $ranges[$index - 1]->end) {
                throw new InvalidArgumentException('Port pool ranges cannot overlap.');
            }
        }

        return $ranges;
    }
}
