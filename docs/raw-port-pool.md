# Allocation publication and infrastructure handoff

The historical filename is retained for existing links. **There are no plugin port pools.** Old `PANGOLIN_TCP_PORTS` / `PANGOLIN_UDP_PORTS` values are inert, not hidden allowlists.

Raw TCP and UDP are public and unauthenticated. Pangolin authentication does not protect RCON, databases, management, Wings or SFTP. Do not approve these allocations. Only explicitly approved `game` / `query` purposes per current allocation ID are eligible; neither an Egg rule nor a port number implies approval, protocol or purpose.

## Approve actual allocations

Set an explicit player-facing IPv4, IPv6 (without brackets), or domain in plugin settings (`PANGOLIN_PUBLIC_HOST`). This is not inferred from the API endpoint or public API DNS. HTTP-only setups do not need it. Raw public and internal ports must match. Conflicts fail even if another port is free. TCP and UDP reservations are independent, so the same allocation can be approved for both.

Select a Pangolin site in each node mapping. Its numeric site ID and nice ID are saved together. Each concrete allocation bind IP is the actual target reached through that site, not the public game host. Wildcard binds (`0.0.0.0`, `::`) use the mapping's explicit site-reachable target. Ensure the site can reach those private addresses; the plugin does not probe or infer network reachability. Two concrete IPs with the same port/protocol still compete for one listener on the public host: select only one. No native bind/port, Node/Wings data or manual alias is rewritten.

Open an existing server's Pangolin publication action. Its full current allocation list shows ID, bind IP, port and effective selection. Enable publication, select Game or Query and TCP, UDP or Both for each approved ID. New and unselected allocations remain unpublished. HTTP/HTTPS is an explicit advanced selection with its own domain; raw use requires no domain inventory. Same-ID IP/port updates follow the current allocation. Replacement IDs and node transfers do not inherit a matching number's approval. Manual aliases stay untouched; see confirmed endpoint when an alias intentionally differs.

Ports must be 1024–65535. Known protected ports 2022, 8080, 8443, 51820 and 51821 are rejected. Operators must also check deployment-specific listeners/reservations: the plugin cannot inventory the host. Never approve management listeners just because their number passes validation.

## Derive requirements, do not invent inventory

Open **Pangolin infrastructure** in the admin navigation (root admin only), or run `php artisan pangolin:infrastructure`. The source is currently assigned, explicitly approved allocation/protocol pairs, deduplicated and sorted. Empty selection gives a no-demand explanation, not a pool error. Stored remote raw resources are listed separately as retained rows and remain included in requirements while cleanup is uncertain, even after opt-out or server deletion. A desired-state change alone is not evidence that an old listener is safe to remove.

Copy buttons copy the complete readonly text; if clipboard permission is unavailable, select/copy manually. Nothing is applied remotely.

## Merge, never replace

The generated YAML includes parent keys to show structure. Do NOT paste duplicate keys into an existing file. Merge only the children at the indicated location, preserving all existing entries:

1. `config/traefik/traefik_config.yml`: merge generated `tcp-PORT` and `udp-PORT` children under the existing top-level `entryPoints`. Keep HTTP, TLS, SSH and all unrelated entrypoints unchanged.
2. Compose: append generated mapping items to the existing `services.gerbil.ports` list. Keep existing service options, ports and networking unchanged. This is the documented standard Gerbil architecture, not a universal mapping for host-network/custom deployments.
3. Pangolin `config/config.yml`: set `allow_raw_resources: true` under existing `flags`; do not duplicate `flags`.
4. Host AND provider firewall: permit only the approved TCP/UDP numbers using the actual firewall tooling/policy. No UFW assumption or executable firewall command is generated.

Example shape for one approved allocation using TCP and UDP port 25565 (merge the children, not duplicate parent keys):

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

Commit reviewed infrastructure changes to the responsible Git repository and hand off to Dockhand/Infra. Static Traefik changes require a Traefik restart. Docker port-mapping changes require recreation of the affected Gerbil container. Restarting only the Pangolin app is insufficient. No stack shutdown is recommended. Every newly required arbitrary external listener still needs actual infrastructure application; the Integration API cannot apply these static changes.

## Acceptance, cleanup and rollback

Generated requirements, applied infrastructure (unknown) and external reachability (not tested) are separate. `synced` means API resources/targets were synchronized, not that a game connection works. Infra must verify host/provider firewall, listeners, container mappings and real external TCP AND UDP traffic against an actual test game/query listener. A UDP send without a response is not success evidence.

Withdraw approvals or opt out, process the queue and verify remote deletion. Pending/failed cleanup remains visible, including root-only tombstones for deleted Pelican servers; correct the failure and retry. Partial API failures retain ownership IDs. An `error`/`blocked` status, missing alias or changed snippet is not proof that exposure ended: API/connectivity/ownership failures can prevent disable/cleanup. Keep ownership records and infrastructure for retained remote rows until confirmed cleanup.

For rollback, finish remote cleanup first. Review the infrastructure Git diff, remove only no-longer-used plugin entries, and hand off restart/recreation to Infra/Dockhand. Preserve existing ports/services. See [installation](installation.md) for matching file/database rollback and legacy-profile reapproval. No production mutation, restart or deployment is part of plugin delivery.
