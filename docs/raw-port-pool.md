# Raw port pools and infrastructure handoff

Raw TCP and UDP are public and unauthenticated. Pangolin authentication does not protect RCON, databases, management, Wings or SFTP. Do not approve these allocations. Only explicitly approved `game`/`query` purposes from admin overrides or exact-port Egg rules are eligible; a port number alone never implies a protocol or purpose.

## Configure once

Set an explicit player-facing IPv4, IPv6 (without brackets), or domain in plugin settings (`PANGOLIN_PUBLIC_HOST`). This is not inferred from the API endpoint or DNS. Raw public and internal ports must match. A conflicting/out-of-pool allocation fails, even if another port is free. TCP and UDP pools/reservations are independent, so the same number can be approved for both.

Pools accept comma-separated ports and inclusive ranges, e.g. `25565,30000-30100`. Each protocol allows at most 1024 ports, between 1024 and 65535. Duplicate/overlapping ranges and reserved ports 2022, 8080, 8443, 51820, 51821 are rejected. Operators must also check deployment-specific listeners/reservations: the plugin cannot inventory the host. Do not include HTTP/TLS/SSH/management listeners.

Open **Pangolin infrastructure** in the admin navigation (root admin only), or run `php artisan pangolin:infrastructure`. Copy buttons copy the complete readonly text; if clipboard permission is unavailable, select/copy manually. Nothing is applied remotely.

## Merge, never replace

The generated YAML includes parent keys to show structure. Do NOT paste duplicate keys into an existing file. Merge only the children at the indicated location, preserving all existing entries:

1. `config/traefik/traefik_config.yml`: merge generated `tcp-PORT` and `udp-PORT` children under the existing top-level `entryPoints`. Keep HTTP, TLS, SSH and all unrelated entrypoints unchanged.
2. Compose: append generated mapping items to the existing `services.gerbil.ports` list. Keep existing service options, ports and networking unchanged. This is the documented standard Gerbil architecture, not a universal mapping for host-network/custom deployments.
3. Pangolin `config/config.yml`: set `allow_raw_resources: true` under existing `flags`; do not duplicate `flags`.
4. Host AND provider firewall: permit only the approved TCP/UDP numbers using the actual firewall tooling/policy. No UFW assumption or executable firewall command is generated.

Example shape for TCP and UDP port 25565 (merge the children, not duplicate parent keys):

```yaml
entryPoints:
  tcp-25565:
    address: ":25565/tcp"
  udp-25565:
    address: ":25565/udp"
```

```yaml
services:
  gerbil:
    ports:
      - "25565:25565/tcp"
      - "25565:25565/udp"
```

```yaml
flags:
  allow_raw_resources: true
```

Commit reviewed infrastructure changes to the responsible Git repository and hand off to Dockhand/Infra. Static Traefik changes require a Traefik restart. Docker port-mapping changes require recreation of the affected Gerbil container. Restarting only the Pangolin app is insufficient. No stack shutdown is recommended. Once the bounded pool is prepared, publishing a new server within it needs only API reconciliation, not a per-server infrastructure restart.

## Acceptance and rollback

The UI distinguishes generated output from applied infrastructure (unknown) and external reachability (not tested). `synced` means API resources/targets were synchronized, not that a game connection works. Infra must verify host/provider firewall, listeners, container mappings and real external TCP AND UDP traffic against an actual test game/query listener. A UDP send without a response is not success evidence.

Before withdrawing a pool port, opt out its publications and verify remote deletion. Merely removing it from plugin settings rejects subsequent reconciliation but does **not** disable an already enabled Pangolin resource (known pre-existing limitation S2). An `error` status, missing alias or changed generated snippet is not proof that exposure ended. If cleanup fails, retain ownership records and resolve API/ownership issues; no successful revocation can be claimed until remote state is verified.

For rollback, opt out affected servers and wait for owned resources to be removed first. Review the infrastructure Git diff, remove only this approved pool's no-longer-used entries, and hand off restart/recreation to Infra/Dockhand. Preserve existing ports/services. No production mutation, restart or deployment is part of plugin delivery.
