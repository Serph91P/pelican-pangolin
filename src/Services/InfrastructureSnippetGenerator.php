<?php

namespace PelicanDev\PelicanPangolin\Services;

use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Models\ManagedResource;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

final class InfrastructureSnippetGenerator
{
    /** @return array{warning: string, firewall: string, compose: string, traefik: string, pangolin: string} */
    public function current(): array
    {
        $desired = [];
        if (Schema::hasTable('servers')) {
            foreach (ServerPublication::query()->where('opted_in', true)->with('server.allocations')->get() as $publication) {
                $server = $publication->server;
                $state = $publication->desired_state;
                if ($server === null || (isset($state['node_id']) && $state['node_id'] !== $server->node_id)) {
                    continue;
                }
                foreach ($server->allocations as $allocation) {
                    $approval = $state['overrides'][$allocation->id] ?? [];
                    if (! in_array($approval['purpose'] ?? null, ['game', 'query'], true)) {
                        continue;
                    }
                    foreach (['tcp', 'udp'] as $protocol) {
                        if (in_array($approval['mode'] ?? null, [$protocol, 'both'], true)) {
                            $desired[] = ['protocol' => $protocol, 'port' => $allocation->port];
                        }
                    }
                }
            }
        }
        $retained = ManagedResource::query()->whereNotNull('pangolin_resource_id')->whereIn('mode', ['tcp', 'udp'])->whereNotNull('public_port')->get()
            ->map(fn ($resource): array => ['protocol' => $resource->mode->value, 'port' => $resource->public_port ?? throw new \DomainException('Missing retained public port.')])->values()->all();

        return $this->generate($desired, array_values($retained));
    }

    /**
     * @param  list<array{protocol: string, port: int}>  $desired
     * @param  list<array{protocol: string, port: int}>  $retained
     * @return array{warning: string, firewall: string, compose: string, traefik: string, pangolin: string}
     */
    public function generate(array $desired, array $retained = []): array
    {
        $firewall = [];
        $compose = [];
        $traefik = [];
        $pairs = [];
        $describe = function (array $items) use (&$pairs): string {
            $names = [];
            foreach ($items as $item) {
                $protocol = $item['protocol'];
                $port = $item['port'];
                (new PublicPortAllocator)->allocate($protocol, $port, []);
                $key = $protocol.'/'.str_pad((string) $port, 5, '0', STR_PAD_LEFT);
                $pairs[$key] = $item;
                $names[$key] = "{$protocol}/{$port}";
            }
            ksort($names);

            return implode(', ', $names) ?: 'none';
        };
        $desiredText = $describe($desired);
        $retainedText = $describe($retained);
        ksort($pairs);
        foreach ($pairs as $item) {
            ['protocol' => $protocol, 'port' => $port] = $item;
            $firewall[] = "Allow inbound {$protocol}/{$port} on the public Pangolin host in your actual host AND provider firewall, only after checking existing listeners and management ports.";
            $compose[] = "      - \"{$port}:{$port}/{$protocol}\"";
            $traefik[] = "  {$protocol}-{$port}:\n    address: \":{$port}/{$protocol}\"";
        }

        return [
            'warning' => ($desired === [] ? 'No approved raw allocations. ' : '')."Desired: {$desiredText}. Remote rows retained: {$retainedText}. Do not remove infrastructure for retained remote rows until cleanup is confirmed. ".'Generated only; application and external reachability are not verified. Merge children under existing entryPoints in config/traefik/traefik_config.yml; under services.gerbil.ports in Compose (standard Gerbil network architecture only); and flags in Pangolin config/config.yml. Do not duplicate parent YAML keys or replace existing HTTP/TLS/SSH/management settings. Commit changes in the responsible infrastructure Git repository and hand off to Dockhand: static Traefik changes require a Traefik restart; Docker mapping changes require recreation of the affected Gerbil container. Restarting only the Pangolin app is insufficient. New arbitrary raw listeners require actual infrastructure application. Check custom listeners and reserved ports first.',
            'firewall' => implode("\n", $firewall),
            'compose' => $pairs === [] ? '' : "services:\n  gerbil:\n    ports:\n".implode("\n", $compose),
            'traefik' => $pairs === [] ? '' : "entryPoints:\n".implode("\n", $traefik),
            'pangolin' => $pairs === [] ? '' : "flags:\n  allow_raw_resources: true",
        ];
    }
}
