# Pelican Pangolin

Pelican Pangolin is an administrator-only Pelican Panel plugin that publishes explicitly opted-in servers through the Pangolin Integration API. It supports raw TCP and UDP resources and explicit HTTP or HTTPS resources.

## Requirements

- Pelican Panel 1.x
- PHP 8.3, 8.4, or 8.5
- Pangolin with the Integration API enabled
- A queue worker for reconciliation jobs
- Pelican's scheduler, which refreshes opted-in desired state every five minutes as a recovery path
- Prepared Pangolin host firewall, container, and Traefik configuration for raw ports

## Safety model

- Servers are never published until an administrator opts them in.
- HTTP publication is never inferred from an allocation.
- The plugin mutates or deletes only exact Pangolin resource and target IDs stored in its own tables.
- TCP and UDP public-port reservations are independent.
- API tokens are masked, omitted from jobs, and redacted from errors.
- Host snippets are guidance only. The plugin never edits a Pangolin host remotely.

## Setup

1. Follow [installation](docs/installation.md).
2. Create a least-privilege key using [Pangolin API key](docs/pangolin-api-key.md).
3. Configure the API endpoint, organization, token, domains, and raw port pools in the plugin settings.
4. Run `php artisan pangolin:preflight`.
5. Map each Pelican node to one Pangolin site and reachable target host.
6. Optionally create Egg profiles, then use the Pangolin publication action on a server.

See [raw port pools](docs/raw-port-pool.md) before enabling TCP or UDP publication.

## Commands

- `php artisan pangolin:preflight --site=SITE --domain=DOMAIN`
- `php artisan pangolin:reconcile [server]`
- `php artisan pangolin:infrastructure`

## Development

```bash
composer install
composer test
composer lint
composer analyse
scripts/build-plugin.sh
```

Licensed under AGPL-3.0-only.
