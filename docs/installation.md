# Installation

1. Extract the release ZIP into `plugins/pelican-pangolin` so that `plugin.json` is directly inside that directory.
2. Run Pelican's plugin discovery and installation commands from the Panel root.
3. Start or restart a queue worker and keep Pelican's scheduler running. Reconciliation is queued after database commits, and the scheduler refreshes opted-in desired state every five minutes to recover bulk allocation or configuration changes.
4. Open the Pelican Pangolin plugin settings in the administrator panel.
5. Configure an HTTPS Pangolin API URL, organization ID, Integration API token, default domain, TCP pool, UDP pool, and bounded timeouts.
6. Run `php artisan pangolin:preflight` before creating node mappings.

The token field always displays blank. Leaving it blank retains the existing value. Settings are validated with read-only inventory requests before being written. Saving settings restarts Pelican queue workers so subsequent reconciliation uses the new endpoint, token, and port pools.

## Upgrade to 1.1.0

Back up the Pelican database and plugin files. Target Pelican `1.0.0-rc.2` and PHP 8.4/8.5; older targets are not verified. Opt out existing publications and complete remote cleanup before replacing a remapped-port setup. Run the native plugin upgrade flow and restart queue workers.

Configure `PANGOLIN_PUBLIC_HOST` explicitly. Existing positional Egg rules and overrides without `purpose` do NOT become approved automatically. Review each rule, supply its exact `allocation_port` and `game`/`query` purpose, then re-approve the desired allocations/protocols. `allocation_position` remains a unique rule identifier for schema compatibility; it no longer selects an allocation by list position. Legacy remapped rows intentionally fail with a cleanup/reapproval instruction, rather than displaying the wrong endpoint.

Legacy stored remote IDs are checked against their original deterministic resource name; new records use a durable random ownership name. A mismatched name or unexpected target requires operator investigation: do not edit IDs or rename foreign resources just to make sync pass. Manual aliases are preserved; use the confirmed endpoint column when an existing alias intentionally differs.

## Rollback

Opt out all publications, run the queue until resource cleanup completes, and verify no managed ownership rows remain before reverting plugin files/schema using Pelican's native flow. The new down migration refuses to discard active ownership rows. Do not force-drop tables. Only unchanged plugin-owned aliases are cleared; manual edits remain. Restoring a database backup also restores IDs and requires comparing remote state before enabling workers. Infrastructure rollback is a separate reviewed Git/Dockhand handoff, described in [raw port pools](raw-port-pool.md).

## Uninstall

Opt out all managed servers and wait for removal jobs to complete before uninstalling. Uninstalling without this step intentionally does not guess at or delete remote Pangolin resources.

## Troubleshooting

- A pending status usually means no queue worker is running.
- A permission error identifies the inventory or mutation that the Integration API key cannot perform.
- A missing mapping, unknown Egg rule, invalid method, exhausted pool, or ambiguous domain fails closed.
- A remote 404 is treated as drift and recreated only from an existing plugin-owned record.
- Use `php artisan pangolin:reconcile SERVER_ID` after correcting configuration.
