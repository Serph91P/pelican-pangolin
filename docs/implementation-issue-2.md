# Issue #2 implementation ledger

Specification: issue #2 and the supplied safe-publication handoff. Base: d8378e2b2e31e2dec711d0899de612ff4780f9d2. One writer, branch feat/2-safe-publication; PR target develop. No production actions.

## Plan and shared contracts
1. Pin actual Pelican rc.2 and audit supported hooks and consumers.
2. Require same raw public/internal port, validated explicit public host, purpose and protocol approval; preserve native bind data and manual aliases.
3. Reconcile lifecycle with durable ownership, stale-revision checks and serialized jobs; confirm endpoints only after complete synchronization.
4. Expose bounded infrastructure templates through root-admin plugin page with copy controls and explicit insertion/restart guidance.
5. Run rc.2 integration, migrations, packaging, tests/lint/analysis and self-review, then publish PR and verify exact-head CI.

Shared interfaces: rules feed desired resources, which feed reservations and aliases; snapshot revisions feed queue jobs; pool validation is shared by allocator/settings/generator. Preserve these contracts across all consumers.

Ruling: remove raw port remapping rather than retain unequal ports — native Allocation.address combines alias and actual port; conflicts must fail, not produce misleading addresses.
Ruling: previous positional Egg rules require explicit purpose approval on upgrade; unknown purposes fail closed. TCP+UDP is expanded into separate resources, not a new remote protocol.

## Evidence
- Initial worktree clean at binding base; two worktrees in common repository. Serena AllocationObserver lookup succeeded. Graphify 0.9.92 verified fresh, scoped src/database/tests/config (72 files); docs/scripts reviewed separately.
- Official Pelican plugin and Pangolin raw-resource docs retrieved. Native discovery/settings/custom-header hooks exist; no Core patch or host execution required.
- Actual rc.2 checkout f64a167eea349bfbb89cda8732eacf682a82a4b3, dependency update successful; initial rc.2 suite 62 passed / 222 assertions. This supersedes old-panel baseline, not external reachability evidence.
- Dependency audit initially found CVE-2026-108082 in HttpFoundation 8.0.x. Official advisory checked; allowed compatible patched 7.4.21 selected via dev constraint ^7.4.19. Final composer audit has zero advisories.

## Progress
First CI detected the native Panel autoloader prerequisite missing from a fresh checkout. Reproduced 3 failures without local .env/vendor, fixed bootstrap to create the application dependency symlink and use checked-in .env.example; clean-state 3/47 and full 103/391 now pass. Native tests were not skipped or weakened.

Implementation and author self-review complete; PR/CI delivery follows. Final local checks: 103 tests / 391 assertions; native packaged rc.2 integration 3 tests / 47 assertions; composer validate/lint/analyse/audit, ZIP build and diff check pass. See verification-issue-2.md for audit and honest matrix.

RED→GREEN evidence includes stale alias application, stale snapshot revision overwrite, existing reservation outside revised pool, bulk server-deletion orphan cleanup, and unexpected target cascading deletion prevention. Existing tests retained; remapping expectations changed to explicit rejection per contract.

Ruling: HTTP resource method changes reuse the owned row; raw Both expands before the client boundary. Legacy remote IDs require their original deterministic name, while new resources have persisted random ownership names. Unexpected targets require operator cleanup rather than deletion.

Final self-review: Ponytail/gstack checklist and test-value rubric applied by author. No production access assumed and no external TCP/UDP test performed. Keep worktree, dependency checkout and test artifacts for reviewer/owner acceptance; no cleanup before their lifecycle gate.

## Review correction round

B1 (High): an obsolete allocation reserved its successor's exact port, so a transfer failed before cleanup and left the old target enabled. Reproduced TCP/UDP/Both replacement failures before changing production code. Remove obsolete owned resources before fresh remote inventory/reservation; preserve HTTP/HTTPS row reuse. Check revision again after remote ownership/target reads, immediately before deletion; its regression also failed before the guard was added. The existing shared lock, ownership verification and durable failure records remain in force. New stateful HTTP cases cover successful replacement, deletion failure/retry, target creation failure/retry and idempotent repeat, with old IDs absent and new host/site/exact port/alias verified. Native rc.2 UI → database queue now also exercises the allocation/node swap and reapproval, with old TCP+UDP targets removed.

S1: provide a valid public host in the existing URL/pool rejection table so each intended guard is reached; cover disabled/missing-target alias cases separately. S2: pool revocation does not disable existing remote exposure; document this pre-existing limitation separately rather than claim it fixed. No extra product redesign or review pipeline.

Ruling: cleanup precedes successor creation, accepting an interruption during transfer instead of keeping an obsolete target exposed. Remote deletion failures retain ownership for retry and cannot be presented as completed cleanup. No atomic remote transaction or live reachability is claimed.
