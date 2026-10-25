<?php

namespace PelicanDev\PelicanPangolin\Services;

use PelicanDev\PelicanPangolin\Support\PortRange;

final class InfrastructureSnippetGenerator
{
    /** @return array{warning: string, firewall: string, compose: string, traefik: string, pangolin: string} */
    public function generate(string $tcpPool, string $udpPool): array
    {
        PublicPortAllocator::from($tcpPool, $udpPool);
        $firewall = [];
        $compose = [];
        $traefik = [];
        foreach (['tcp' => $tcpPool, 'udp' => $udpPool] as $protocol => $pool) {
            $ports = [];
            foreach (explode(',', $pool) as $value) {
                if (trim($value) === '') {
                    continue;
                }
                $range = PortRange::parse($value);
                array_push($ports, ...range($range->start, $range->end));
            }
            sort($ports, SORT_NUMERIC);
            foreach ($ports as $port) {
                $firewall[] = "Allow inbound {$protocol}/{$port} on the public Pangolin host in your actual host AND provider firewall, only after checking existing listeners and management ports.";
                $compose[] = "      - \"{$port}:{$port}/{$protocol}\"";
                $traefik[] = "  {$protocol}-{$port}:\n    address: \":{$port}/{$protocol}\"";
            }
        }

        return [
            'warning' => 'Generated only; application and external reachability are not verified. Merge children under existing entryPoints in config/traefik/traefik_config.yml; under services.gerbil.ports in Compose (standard Gerbil network architecture only); and flags in Pangolin config/config.yml. Do not duplicate parent YAML keys or replace existing HTTP/TLS/SSH/management settings. Commit changes in the responsible infrastructure Git repository and hand off to Dockhand: static Traefik changes require a Traefik restart; Docker mapping changes require recreation of the affected Gerbil container. Restarting only the Pangolin app is insufficient. Prepare the pool once; per-server API synchronization within it needs no infrastructure restart. Check custom listeners and reserved ports first.',
            'firewall' => implode("\n", $firewall),
            'compose' => "services:\n  gerbil:\n    ports:\n".implode("\n", $compose),
            'traefik' => "entryPoints:\n".implode("\n", $traefik),
            'pangolin' => "flags:\n  allow_raw_resources: true",
        ];
    }
}
