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

        throw new RuntimeException('No '.strtoupper($protocol).' public ports are available for the exact allocation port; resolve the conflict or extend the approved pool. Port remapping is not supported.');
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

        $count = 0;
        foreach ($ranges as $range) {
            $count += $range->end - $range->start + 1;
            if ($range->start < 1024 || $count > 1024) {
                throw new InvalidArgumentException('Pools require ports >= 1024 and at most 1024 ports per protocol.');
            }
            foreach ([2022, 8080, 8443, 51820, 51821] as $reserved) {
                if ($range->contains($reserved)) {
                    throw new InvalidArgumentException('Pool overlaps a reserved management or tunnel port.');
                }
            }
        }

        for ($index = 1; $index < count($ranges); $index++) {
            if ($ranges[$index]->start <= $ranges[$index - 1]->end) {
                throw new InvalidArgumentException('Port pool ranges cannot overlap.');
            }
        }

        return $ranges;
    }
}
