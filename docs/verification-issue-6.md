# Allocation publication verification (#6)

## Boundaries

Source/PR repository: `Serph91P/pelican-pangolin`, base `develop` at `f9a089c8d29e0dbf24eb13f97139cebd93e58fe3`, branch `feat/6-allocation-publication`. No deployment repository, production configuration or live Pangolin resources were changed. Linux, PHP 8.4.27; native Panel target exactly `f64a167eea349bfbb89cda8732eacf682a82a4b3` (`1.0.0-rc.2`). PHP 8.5 is covered by the PR CI, not the local runtime.

## Executed checks

| Check | Result / evidence kind |
|---|---|
| Fresh merged-base build + suite | PASS: 119 tests, 891 assertions |
| Allocation regression tests before implementation | RED: legacy pool validation, implicit Egg approval, missing bind/target identity and onboarding help; not merely a new green suite |
| Additional focused RED → GREEN | Duplicate concrete-IP public listener rejected before mutation; missing settings/failed-read notification; immediate cleanup DI failure reporting; visible native suspension column |
| `bash scripts/build-plugin.sh` | PASS: ZIP contains source files only, no vendor payload; every archived file byte compared to its source |
| `composer test -- --compact` | PASS: 146 tests, 1119 assertions |
| `vendor/bin/pest tests/Integration --compact` | PASS: 14 tests, 249 assertions; overlapping subset of the full suite |
| `composer validate --strict` | PASS |
| `composer lint` | PASS |
| `composer analyse` | PASS: 48 source files |
| `composer check-platform-reqs` | PASS on PHP 8.4.27 |
| `bash scripts/test-mariadb-install.sh` | PASS: 13/13 scenarios against MariaDB 11.8.9 and official rc.2 Panel image; unchanged migrations. Native installer exit code **and** status, ledger, schema/data effects checked |
| `composer audit --format=json` | FAIL (exit 1): unchanged dev/test dependency `symfony/mime` 8.0.15, CVE-2026-45070 |
| `composer audit --no-dev` | PASS: no production Composer packages |
| Required exact-head CI | Check the PR's recorded head/check runs; require `verify (8.4)`, `verify (8.5)` (Actions app 15368) and the separate MariaDB job. No CI/protection changes in this patch |

The audit finding already exists in the base lockfile, exclusively under `packages-dev`. No dependency file, advisory ignore or audit gate was changed. The shipped plugin requires PHP only and contains no vendor directory. This does not make the development advisory disappear; it is a separately reported limitation. [Current Symfony advisory](https://symfony.com/cve-2026-45070).

## Native user-flow evidence

`tests/Integration/AllocationFlowTest.php` starts the main onboarding scenario with **empty plugin settings**, no public host and no pools/hidden allowlist. It mounts the real Plugin settings action (`exclude_settings`), checks inline Org-ID/key help, saves via the actual UI callback, and confirms raw-only saves make read requests only, without domain inventory. It creates the node mapping through the native site dropdown and confirms both numeric ID and nice ID plus read validation.

The real server action renders every current allocation ID, bind IP and port. An admin approves one allocation as Game/Both, leaving a second allocation unselected. A real database queue executes the real job/reconciler/HTTP client against `PangolinApiFake`, a stateful **HTTP fixture**, not live Pangolin. Assertions read actual resource/target state, native allocation address, unchanged bind data and untouched unknown/manual aliases. Rename plus scheduled refresh preserves the revision, aliases and resource IDs. Same-ID IP/port changes update the target and exact public port. The actual infrastructure page and `Artisan::call('pangolin:infrastructure')` show the derived TCP/UDP listeners, not unapproved allocations.

The same flow withdraws approval, simulates missing native server state, injects a deletion failure, confirms retained remote ownership/error/tombstone visibility, and retries through the native publications table. The final API fixture is empty, cleanup is no longer pending and the command reports no approved raw demand/retained rows.

Adjacent native cases cover no-selection vs configuration-blocked vs actual suspension, blank initial token and HTTP 401/403/503 read errors without saving credentials, legacy per-ID preservation and profile-only remote cleanup, primary-ID replacement, node transfer, root-only orphan visibility, TCP-only/UDP-only/HTTP-only use, concrete IPv6 targets, manual aliases and bracketed public IPv6 endpoints. Unit/feature coverage retains ownership checks, unexpected-target refusal, stale revisions, independent TCP/UDP conflicts, protected ports, shared locks, partial API failure/retry and byte-exact copy/YAML contracts. Queue draining uses bounded `queue:work --once` invocations: a long-running `--stop-when-empty` Worker retains daemon exit state when reused inside one PHP test process.

## Review and limits

Self-review followed the Ponytail, gstack correctness/safety/gate-integrity and functional-contract matrices: no new abstraction/dependency, no migration/ownership-table deletion, all changed callers traced, protocol enums and trust boundaries checked. Existing pool-related tests were adapted to the deliberately removed contract, not skipped; alias/revision/ownership checks remain. New tests protect observable behavior at native or service boundaries; no test-only production seam was added. The unused pool range helper was removed after confirming no callers. This is author self-review, not independent approval.

No live host access, listener/firewall/container application, browser clipboard permission, external TCP game response or UDP query response was tested. The API fixture cannot prove any of these. Real multi-process Redis/MariaDB queue contention was not load-tested; existing lock/revision tests are bounded functional tests. Generated requirements, API sync, actual infrastructure application and external reachability remain distinct. A pending/failed cleanup or missing alias never proves successful revocation. Retain ownership and infrastructure until remote cleanup is confirmed. Merge, release, deployment and external acceptance remain owner/operator work.
