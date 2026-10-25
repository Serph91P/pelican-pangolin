# Issue #2 audit and verification

## Scope and target

Safe publication, endpoint display, infrastructure templates and the existing plugin's related security/lifecycle boundaries. Target: Pelican `v1.0.0-rc.2`, commit `f64a167eea349bfbb89cda8732eacf682a82a4b3`; local Linux PHP 8.4.27, Composer 2.8.8. The previous pinned Panel main was not rc.2. No Core, Wings or host changes. PHP 8.3/older Panel support is deliberately not claimed. PHP 8.5 is exercised by CI, not by this local runtime.

## Confirmed findings fixed

References are candidate source locations, not claims of production exploitation.

| Severity | Finding and fix | Candidate source | Evidence |
|---|---|---|---|
| High | B1: obsolete allocation reservations blocked a same-port successor and left the old target active. Remove obsolete owned resources before fresh inventory/reservation; preserve HTTP method reuse and recheck revision after ownership reads before delete. | `src/Services/PublicationReconciler.php:52-67,299-318` | TCP/UDP/Both replacement RED→GREEN; `AllocationReplacementTest` success/deletion retry/target retry; `RevisionSafetyTest`; native UI/DB-queue transfer |
| High | Positional/unknown approval could publish an unintended allocation after reordering. Require exact-port Egg rules or assigned allocation overrides AND explicit game/query purpose; TCP+UDP expands into two resources. | `src/Services/DesiredStateBuilder.php:46-87` | `SafePublicationTest`, `DesiredStateBuilderTest`, native forged-allocation rejection |
| High | Stored IDs alone did not establish current remote ownership. Check ID/name, persist random names before create, reject conflicting identities/unexpected targets and keep ownership records through partial failures. | `src/Services/PublicationReconciler.php:134-219,252-256,296-328` | `RemoteOwnershipTest`, `PublicationReconcilerTest` |
| High | Overlapping jobs/stale revisions could overwrite newer state. Serialize remote writes with a shared cache lock, check revisions between effects and condition final updates; lock snapshot rows and refresh models. | `src/Services/PublicationReconciler.php:31-77`; `src/Services/PublicationStateSnapshot.php:17-26`; `src/Jobs/ReconcileServerPublication.php:55-57,89` | `RevisionSafetyTest`, stale snapshot RED→GREEN in `LifecycleObserverTest` |
| High | Observer-bypassing server deletion could leave an opted-in orphan publication indefinitely. Scheduler discovers missing servers and tombstones owned resources for cleanup. | `src/Services/PublicationRefreshService.php:57-79` | bulk deletion RED→GREEN in `ConfigurationPropagationTest` |
| Medium | Port remapping made native alias+allocation-port display inaccurate. Remove remapping, reject conflicts/out-of-pool values, revalidate existing reservations; never change bind data. | `src/Services/PublicPortAllocator.php:23-34`; `src/Services/PublicationReconciler.php:232-243` | `PublicPortAllocatorTest`, `SafePublicationTest`, `RemoteOwnershipTest`, native address assertions |
| Medium | No ownership-aware public alias lifecycle. Add explicit validated public host and alias ledger; preserve manual values, clear only own unchanged values, check current publication under row lock before applying. | `src/Services/AllocationAliasManager.php:12-49`; `src/Services/PluginSettings.php:67-79` | `AllocationAliasTest` including IPv6, manual edit, remap, unassignment and stale opt-out RED→GREEN |
| Medium | Infrastructure snippets were unbounded, assumed UFW and lacked a supported copyable surface. Bound/reject pools, generate parsed deterministic YAML, provide root-admin page and precise merge/restart guidance. | `src/Services/InfrastructureSnippetGenerator.php:10-43`; `src/Filament/Admin/Pages/Infrastructure.php:17-41`; `resources/views/infrastructure-blocks.blade.php:4-10` | `InfrastructureSafetyTest`, `InfrastructureUiTest`, native root/non-root UI |
| Medium | Claimed Panel/PHP target differed from pinned test dependencies/metadata. Pin rc.2, update lockfile, metadata and CI. | `composer.json:7-25`; `.github/workflows/ci.yml:13-36`; `plugin.json` | packaged native discovery/migrations and complete local checks |
| Medium | Dependency audit found CVE-2026-108082 in the initially resolved development HttpFoundation 8.0 branch. Use patched PHP-8.4-compatible 7.4.21 via `^7.4.19`. This changes the plugin test environment, not the production Panel dependency installation. | `composer.json:17`; `composer.lock` | `composer audit --format=json`: no advisories or abandoned packages |

Additional reviewed boundaries: root-only global configuration, server update authorization, current assignment validation, HTTPS-only API URL without credentials/query/fragment, TLS verification/no redirects, token retention/masking/redaction, bounded API timeouts, after-commit jobs, retry policy, missing remote IDs, migrations and ZIP contents. No new confirmed defect was found in those unchanged boundaries. This is a scoped code/test audit, not a penetration test or a claim of universal safety.

## Executed verification matrix

All commands ran from the issue worktree unless noted. Final local source/test run: **116 tests, 649 assertions**, no failures. Native integration is included in that total, not added again.

| Contract | Command / evidence | Outcome and limit |
|---|---|---|
| Manifest/lock | `composer validate --strict` | PASS |
| Entire declared suite | `composer test -- --compact` | PASS: 116 tests / 649 assertions |
| Native packaged rc.2 discovery + admin UI/queue | `vendor/bin/pest tests/Integration --compact` | PASS: 3 tests / 66 assertions; real rc.2 application, Filament/Livewire, SQLite and database queue; stateful fake Pangolin HTTP API |
| Invalid assignment / permission boundary | native integration and `SafePublicationTest`, `InfrastructureUiTest` | PASS: unassigned injected allocation rejected without publication/API effect; non-root infrastructure page forbidden |
| Native final business effect | native integration | PASS: pending without alias → database `queue:work --once` → TCP+UDP resources/targets with matching 25565 → `games.example.com:25565`; bind IP unchanged; suspension disables both and clears alias, resume synchronizes; bulk allocation/node swap + explicit admin reapproval removes old TCP+UDP targets and publishes Site 8 / new-node.internal on exactly 25565, then opt-out deletes resources/targets |
| Manual alias/IPv6/native consumers | `AllocationAliasTest` | PASS: native `address`/`alias`, manual values retained; bind IP/port unchanged |
| Conflict/exhaustion, unsafe purpose, stale revision, retry/partial failure | Unit/Feature suites | PASS: no remapping/adoption, persisted ownership after failure and idempotent retry, stale terminal update rejected |
| Concurrency | `RevisionSafetyTest`, `LifecycleObserverTest`, `AllocationAliasTest` | PASS for controlled stale-order/interleaved state and lock-contention fixtures. NOT RUN: multi-process MySQL/Redis load test; local DB is SQLite and cache lock test uses array store |
| Observer/scheduler recovery | `ConfigurationPropagationTest`, `LifecycleObserverTest`, native integration | PASS for server save/suspension, mapping/Egg refresh and bulk server-deletion recovery; assignment changes observed, bulk allocation changes converge through periodic snapshot |
| YAML/copy bytes | `InfrastructureSafetyTest`, `InfrastructureUiTest` | PASS: parse with Symfony YAML, deterministic order, textarea decoded bytes equal generated output, escaping/accessibility; NOT RUN: real browser/OS clipboard permission interaction |
| Migration upgrade/rollback/uninstall guard | `SafePublicationMigrationTest`, native integration | PASS: legacy rules remain unapproved, new schema installed, empty down/up works, owned rows block uninstall |
| Formatting | `composer lint` | PASS |
| Static analysis | `composer analyse` | PASS: 49 source files, no errors |
| Dependency audit | `composer audit --format=json` | PASS: empty advisories/abandoned lists |
| Packaging | `bash scripts/build-plugin.sh`, `PackagingTest` | PASS: ZIP includes views, excludes development dependencies/state |
| Patch whitespace | `git diff --check` | PASS |
| Standalone native CLI | in isolated Panel: `APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=<isolated database> PANGOLIN_TCP_PORTS=25565 PANGOLIN_UDP_PORTS=25565 php artisan pangolin:infrastructure` | PASS: four sections and no application/reachability claim. Initial attempt with `APP_ENV=testing` had no plugin commands because rc.2 intentionally skips plugins in testing; corrected explicit local mode, no Core patch |
| Exact-head CI | GitHub CI PHP 8.4/8.5 on PR candidate | Record final run URLs/status in PR and review handoff; local PHP 8.4 is not PHP 8.5 evidence |
| Live Pangolin / actual external TCP+UDP | no credentials/access established or production action authorized | NOT RUN; isolated HTTP fixture is not live Pangolin |
| Production install/Infra apply/restart | outside delivery authorization | NOT RUN; no changes made |

Native integration sets the isolated application's environment to local only for actual plugin discovery, then testing for framework form assertions. It loads the checked-in Panel `.env.example`, never a developer's `.env`, and creates the missing application vendor symlink to the root test dependencies without replacing an existing directory. A clean-state replay with both local `.env` and prepared vendor symlink absent passes 3 tests / 47 assertions. The initial CI run exposed that missing autoloader prerequisite; the bootstrap was fixed instead of skipping native tests. Test helpers/fixtures stay under `tests/`; no product test-only seam, disabled check or timeout increase was added. Tests changed from remap expectations to explicit conflict rejection because the requested contract removes remapping. Snapshot/metadata/CI version changes follow the exact rc.2 target, not gate weakening. Existing tests were retained; source-shape guards are not presented as runtime evidence.

## Open confirmed limitation (not fixed)

S2 (Medium, pre-existing on the integration base): changing the approved pool does not disable an already published remote resource. `PublicationReconciler.php:237-242` rejects its reservation before mutation, leaving the prior remote enabled. Pool edits and `error` status are not revocation. Opt out and verify remote deletion before withdrawing ports; see raw-port-pool.md. This is separate from the corrected allocation-replacement B1, not a claim of complete pool-revocation coverage.

Correction evidence: `AllocationReplacementTest` passes 9 cases / 228 assertions (TCP, UDP, both × success, target failure/retry, deletion failure/retry). The reviewer's original standalone `testAllocationReplacementKeepsExactPortAndRemovesOldTarget` replay also passes 1 test / 5 assertions on the corrected working tree. S1's URL/pool negatives now supply a valid public host, and disabled/missing-target alias checks are explicit. All correction test hunks were manually reviewed for gate integrity: no removed tests, skips, disabled checks, timeout increases or loosened assertions. Only existing fixtures were extended; no production test seam.

## Operational limits and handoff

All panel workers must share the same atomic-lock-capable cache (e.g. Redis/database), not independent in-memory caches. Keep worker timeout below the 300-second lock lifetime and queue retry-after above worker timeout. Remote operations are not a distributed transaction: a stopped/failed request can leave a recorded partial resource; retries rediscover exact ownership. Pending/error/removing is not a ready endpoint, and remote cleanup requires working API permission/connectivity. Do not erase ownership records to hide failures. Configuration/assignment bulk changes converge on the five-minute scheduler, not synchronously at the SQL write.

Infra must review the generated bounded pool in the responsible infrastructure Git repository, preserve unrelated YAML keys/listeners, enable raw resources, apply host/provider firewall changes, and perform authorized Dockhand rollout with appropriate Traefik restart/Gerbil recreation. Then verify actual game/query responses from an external TCP/UDP client. See [raw port pools](raw-port-pool.md) and [installation/rollback](installation.md). No live state is implied by green API fixture tests.

## Optional ideas (not implemented)

- P2: isolated real Pangolin version matrix and multi-worker MySQL/Redis stress harness when authorized test infrastructure exists.
- P3: browser clipboard-permission end-to-end coverage or optional snippet download; manual copy remains available.

No additional abstractions, host automation, production operations or unrelated refactoring were added. Author self-review used the Ponytail, gstack review checklist and test-value rubric; independent same-card review remains required.
