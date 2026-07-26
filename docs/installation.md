# Installation

1. Extract the release ZIP into `plugins/pelican-pangolin` so that `plugin.json` is directly inside that directory.
2. Run Pelican's plugin discovery and installation commands from the Panel root.
3. Start or restart a queue worker and keep Pelican's scheduler running. Reconciliation is queued after database commits, and the scheduler refreshes opted-in desired state every five minutes to recover bulk allocation or configuration changes.
4. Open the Pelican Pangolin plugin settings in the administrator panel.
5. Configure an HTTPS Pangolin API URL, organization ID, Integration API token, default domain, TCP pool, UDP pool, and bounded timeouts.
6. Run `php artisan pangolin:preflight` before creating node mappings.

The token field always displays blank. Leaving it blank retains the existing value. Settings are validated with read-only inventory requests before being written. Saving settings restarts Pelican queue workers so subsequent reconciliation uses the new endpoint, token, and port pools.

## Upgrade

Back up the Pelican database, replace the plugin files, run Pelican's plugin upgrade flow, and restart queue workers. Do not remove plugin tables while managed remote IDs remain.

## Uninstall

Opt out all managed servers and wait for removal jobs to complete before uninstalling. Uninstalling without this step intentionally does not guess at or delete remote Pangolin resources.

## Troubleshooting

- A pending status usually means no queue worker is running.
- A permission error identifies the inventory or mutation that the Integration API key cannot perform.
- A missing mapping, unknown Egg rule, invalid method, exhausted pool, or ambiguous domain fails closed.
- A remote 404 is treated as drift and recreated only from an existing plugin-owned record.
- Use `php artisan pangolin:reconcile SERVER_ID` after correcting configuration.
