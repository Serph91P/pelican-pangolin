<?php

namespace PelicanDev\PelicanPangolin\Services;

use PelicanDev\PelicanPangolin\Support\PortRange;

final class InfrastructureSnippetGenerator
{
    /** @return array{warning: string, firewall: string, compose: string, traefik: string} */
    public function generate(string $tcpPool, string $udpPool): array
    {
        PublicPortAllocator::from($tcpPool, $udpPool);
        $tcpRanges = $this->ranges($tcpPool);
        $udpRanges = $this->ranges($udpPool);
        $firewall = [];
        $compose = [];
        $traefik = [];

        foreach (['tcp' => $tcpRanges, 'udp' => $udpRanges] as $protocol => $ranges) {
            foreach ($ranges as $range) {
                $firewallPort = $range->start === $range->end ? (string) $range->start : "{$range->start}:{$range->end}";
                $composePort = $range->start === $range->end ? (string) $range->start : "{$range->start}-{$range->end}";
                $firewall[] = "ufw allow {$firewallPort}/{$protocol}";
                $compose[] = "      - \"{$composePort}:{$composePort}/{$protocol}\"";

                for ($port = $range->start; $port <= $range->end; $port++) {
                    $traefik[] = "  {$protocol}-{$port}:";
                    $traefik[] = "    address: :{$port}/{$protocol}";
                }
            }
        }

        return [
            'warning' => 'Generated guidance only. This plugin does not apply or edit Pangolin host configuration.',
            'firewall' => implode("\n", $firewall),
            'compose' => "ports:\n".implode("\n", $compose),
            'traefik' => "entryPoints:\n".implode("\n", $traefik),
        ];
    }

    /** @return list<PortRange> */
    private function ranges(string $pool): array
    {
        return array_map(
            PortRange::parse(...),
            array_values(array_filter(array_map('trim', explode(',', $pool)))),
        );
    }
}
