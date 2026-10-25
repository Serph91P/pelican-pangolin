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
- A missing mapping, unknown Egg rule, invalid method, exhausted pool, or ambiguous domain fails closed.
- A remote 404 is treated as drift and recreated only from an existing plugin-owned record.
- Use `php artisan pangolin:reconcile SERVER_ID` after correcting configuration.
