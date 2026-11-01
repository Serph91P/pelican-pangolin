# Installation

1. Extract the release ZIP into `plugins/pelican-pangolin` so that `plugin.json` is directly inside that directory.
2. Run Pelican's plugin discovery and installation commands from the Panel root.
3. Start or restart a queue worker and keep Pelican's scheduler running. Reconciliation is queued after database commits, and the scheduler refreshes opted-in desired state every five minutes to recover bulk allocation or configuration changes.
4. Open the Pelican Pangolin plugin settings in the administrator panel.
5. Configure an HTTPS Pangolin API URL, organization ID, org-scoped Integration API token and bounded timeouts. Configure a public game host for raw publication; the advanced default domain is only for explicit HTTP use. There are no pool fields.
6. Run `php artisan pangolin:preflight` before creating node mappings.

The token field always displays blank. Leaving it blank retains the existing value. Saving checks `listSites` and `listResources`; it checks `listOrgDomains` only when an HTTP default domain is supplied. Read success does not prove write permission, infrastructure application or external reachability. No probe resource is created. Saving settings restarts Pelican queue workers so subsequent reconciliation uses the new endpoint/token. Select a site in each node mapping; its numeric ID and nice ID are stored together, then read-validated.

## Upgrade to allocation-based publication

Back up the database, plugin files/metadata and current configuration. Replace only this plugin through Pelican's native upgrade flow; restart workers and run `php artisan pangolin:reconcile` (or allow the five-minute refresh). This change adds no migration and does not drop tables or remote IDs. The existing MariaDB install/retry contract below remains unchanged.

`PANGOLIN_TCP_PORTS` and `PANGOLIN_UDP_PORTS` are ignored, even if still present in the environment. They are not hidden allowlists. Do not delete production environment files to upgrade. Global fixed-port Egg profiles remain visible as legacy read-only metadata and no longer grant exposure. A legacy-reapproval warning appears on affected publications. Existing explicit Game/Query approvals keyed by a still-assigned allocation ID survive; profile-only/unknown approvals are cleaned up, never silently retained or widened. Review each server and explicitly approve its current IDs. Ownership IDs remain until confirmed cleanup; errors require retry, not table deletion.

Snapshots now include bind IPs. Same-ID changes on the same server use the current IP/port; new/replacement IDs and node transfers require new approval. A rename/no-op does not advance the desired revision or clear an alias. Concrete bind IPs are targets; wildcard binds require the explicit node mapping target. Existing remapped resources are removed before publishing the correct exact port. Conflicts fail visibly, never remap.

States distinguish `unselected`, configuration `blocked`, actual suspension (`suspended` flag and disabled API resources), pending sync, API `synced`, and removal/error. `synced` is not a reachability claim. Deleted-server cleanup rows remain visible to root admins, with error and retry; scoped admins cannot access orphaned ownership. Do not remove infrastructure while retained remote rows still need cleanup.

## Earlier 1.1.0 upgrade (historical context)

The earlier release introduced safe ownership and exact-port publication on Pelican `1.0.0-rc.2`/PHP 8.4/8.5. Its exact-port Egg editor is now retired; do not follow old profile-editing instructions. Use the allocation-based upgrade above. Native aliases and existing ownership records still follow the same preservation rules.

Legacy stored remote IDs are checked against their original deterministic resource name; new records use a durable random ownership name. A mismatched name or unexpected target requires operator investigation: do not edit IDs or rename foreign resources just to make sync pass. Manual aliases are preserved; use the confirmed endpoint column when an existing alias intentionally differs.

## Rollback

Opt out all publications, run the queue until resource cleanup completes, and verify no managed ownership rows remain before reverting plugin files/schema using Pelican's native flow. The down migration refuses to discard active ownership rows. Do not force-drop tables. Only unchanged plugin-owned aliases are cleared; manual edits remain. Old binaries do not understand the new states or bind-aware snapshots: do not enable old workers against these rows. Restore a matching pre-upgrade database/config backup only after comparing remote ownership, with workers paused until consistent. Restoring a database backup also restores IDs; never discard still-active ownership. Infrastructure rollback is a separate reviewed Git/Dockhand handoff, described in [allocation infrastructure](raw-port-pool.md).

## Uninstall

Opt out all managed servers and wait for removal jobs to complete before uninstalling. Uninstalling without this step intentionally does not guess at or delete remote Pangolin resources.

## MariaDB migration 003 failure and native retry

MariaDB limits index/constraint identifiers to [64 characters](https://mariadb.com/docs/server/reference/sql-structure/sql-language-structure/identifier-names#maximum-length). The original 003 generated a 68-character unique-index name. MariaDB commits the table and FK before rejecting that index, leaving 001/002 logged and 003 unlogged. The corrected migration uses `pangolin_rule_profile_position_unique`.

For this specific failed install (plugin still `not_installed`):

1. Retain/verify the pre-install database and plugin-file backups. Stop concurrent plugin installation/schema changes; do not configure or publish servers during recovery.
2. Replace only this plugin's files with the reviewed corrected package, preserving the current `plugin.json` metadata and existing configuration. Do not overwrite another plugin or force this plugin's status to enabled. No version bump is needed to retry a plugin that was never installed.
3. From the Panel root, run `php artisan p:plugin:install pelican-pangolin --no-interaction` once. Do **not** uninstall, delete/truncate the table, change the migration ledger, disable foreign-key checks or edit rows to make installation pass.
4. Read back `php artisan p:plugin:list` and the database migration ledger. rc.2 can return **exit 0 on failure**: require plugin status `enabled`, all six plugin migrations logged, and no installation error. Inspect the actual schema/constraints as well:

   ```sql
   SELECT migration, batch FROM migrations
   WHERE migration IN (
     '001_create_pangolin_node_mappings_table',
     '002_create_pangolin_egg_profiles_table',
     '003_create_pangolin_egg_profile_rules_table',
     '004_create_pangolin_server_publications_table',
     '005_create_pangolin_managed_resources_table',
     '006_safe_publication_ownership'
   ) ORDER BY migration;
   SHOW CREATE TABLE pangolin_egg_profile_rules;
   SHOW INDEX FROM pangolin_egg_profile_rules;
   ```

When the rules table already exists and 003 is pending, recovery checks the original eight columns (types/unsignedness, nullability, defaults, auto-increment and generated/extra attributes), InnoDB engine, primary key, known indexes and the same-database cascading FK to `pangolin_egg_profiles(id)`. It rejects additional constraint types, including CHECK constraints, and checks duplicate profile/position pairs and orphaned references **before any DDL**. It only adds the missing unique index; valid rows, IDs and existing constraints remain. A complete matching 003 table with that index already present is also accepted, covering interruption between DDL and the native ledger write. Only Pelican's migrator records completion and installer changes plugin status.

Unexpected/incompatible schema, duplicates or orphans produce `Unsafe partial migration 003` and leave the table/data/ledger unchanged. Stop and investigate that state with the owner; do not turn this into a generic repair or force migration success. This recovery is limited to the original pending 003 table on MySQL/MariaDB, not later partially applied migrations or an already upgraded schema. Already successfully installed plugins do not rerun 003 or rename existing valid indexes.

### Reproducing the installation contract

Run `bash scripts/test-mariadb-install.sh` from the repository with Docker, PHP/ZipArchive and the build script prerequisites available. It uses only disposable `--rm` containers, its own network, empty-password isolated test databases and a fixed nonsecret test application key, with no host ports/production credentials/volumes. It copies the built ZIP and test fixtures into the official rc.2 Panel image, uses MariaDB `11.8.9`, and executes the real `php artisan p:plugin:install pelican-pangolin --no-interaction` subprocess. Allow several minutes. Optional arguments select existing scenarios, e.g. `bash scripts/test-mariadb-install.sh fresh partial`.

The harness reproduces the original 1059 DDL failure, seeds 001/002 in **batch 8 through native migrations**, then verifies fresh/partial/data-preserving/completed-DDL retry. It also verifies safe rejection of duplicates, orphans, wrong column length, missing/wrong FK, conflicting nonunique index, extra column, unexpected `ON UPDATE` behavior and an additional CHECK constraint restricting mode to TCP. Successful cases enforce duplicate rejection (1062), orphan rejection (1452) and FK cascade. Failed cases require unchanged schema/data and only 001/002 logged, not merely a CLI exit code. CI runs this separately from the existing suite. SQLite tests/native UI tests remain useful but do not prove MariaDB identifier limits or nontransactional DDL recovery; `MigrationIdentifiersTest` additionally compiles every plugin migration with the MySQL grammar and checks generated identifiers against the limit.

## Troubleshooting

- A pending status usually means no queue worker is running.
- A permission error identifies the inventory or mutation that the Integration API key cannot perform.
- A missing mapping, invalid method, protected/conflicting exact port, or ambiguous HTTP domain fails closed. No selection is not an API-ready publication.
- A remote 404 is treated as drift and recreated only from an existing plugin-owned record.
- Use `php artisan pangolin:reconcile SERVER_ID` after correcting configuration.
