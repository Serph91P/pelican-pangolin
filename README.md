# Pelican Pangolin

Pelican Pangolin is an administrator-only Pelican Panel plugin that publishes explicitly opted-in servers through the Pangolin Integration API. It supports raw TCP and UDP resources and explicit HTTP or HTTPS resources.

## Requirements

- Pelican Panel `1.0.0-rc.2` (the verified target; earlier 1.x builds are not claimed)
- PHP 8.4 or 8.5
- Pangolin with the Integration API enabled
- A queue worker for reconciliation jobs
- Pelican's scheduler, which refreshes opted-in desired state every five minutes as a recovery path
- Prepared Pangolin host firewall, container, and Traefik configuration for raw ports

## Safety model

- Servers are never published until an administrator opts them in.
- HTTP publication is never inferred from an allocation.
- The plugin verifies stored resource IDs and their ownership names before mutation or deletion. Unexpected targets cause an operator-visible error rather than being adopted or removed.
- Only explicitly approved game/query allocations are published. TCP+UDP is supported; unknown purposes fail closed. No protocol is guessed from a port number.
- Raw external ports must equal allocation ports. Conflicts fail; there is no remapping. Native bind IPs, ports, Node/Wings settings and manual aliases remain unchanged.
- Confirmed public hosts are applied only to empty/plugin-owned native aliases after API sync. Opt-out, suspension and refresh restore only unchanged plugin values.
- TCP and UDP public-port reservations are independent.
- API tokens are masked, omitted from jobs, and redacted from errors.
- Host snippets are guidance only. The plugin never edits a Pangolin host remotely.

## Setup

1. Follow [installation](docs/installation.md).
2. Create a least-privilege key using [Pangolin API key](docs/pangolin-api-key.md).
3. Configure the HTTPS API endpoint, organization and org-scoped token in plugin settings. The form explains where to find the Org ID and lists the required actions. Set an explicit public game host (`PANGOLIN_PUBLIC_HOST`) for raw publication. HTTP domain settings are optional/advanced.
4. Run `php artisan pangolin:preflight`.
5. Select an existing Pangolin site for each Pelican node. Set a site-reachable target for wildcard bind addresses; concrete allocation bind IPs are used directly.
6. Open the Pangolin publication action on an existing server. Review every current allocation (ID, bind IP, port), opt in and explicitly approve Game/Query plus TCP/UDP/Both per allocation. Unknown/new allocations stay unpublished. HTTP/HTTPS requires an explicitly selected HTTP mode and domain.
7. Process the queue, inspect API sync, and copy the derived infrastructure requirements for separate operator application. No port pools or automatic ranges are used.

See [allocation publication and infrastructure](docs/raw-port-pool.md) before enabling TCP or UDP publication. Old pool environment keys are ignored; legacy fixed-port Egg profiles are read-only and inert. Review the [upgrade instructions](docs/installation.md).

## Commands

- `php artisan pangolin:preflight --site=SITE --domain=DOMAIN`
- `php artisan pangolin:reconcile [server]`
- `php artisan pangolin:infrastructure`

## Development

```bash
git clone https://github.com/pelican/panel.git pelican-panel
git -C pelican-panel checkout f64a167eea349bfbb89cda8732eacf682a82a4b3
composer install
scripts/build-plugin.sh
composer test
composer lint
composer analyse
scripts/build-plugin.sh
```

Licensed under AGPL-3.0-only.
