# Changelog

## 1.1.0 - Unreleased

- Pin development and native plugin integration to Pelican `1.0.0-rc.2`, PHP 8.4/8.5.
- Require explicit public host, exact allocation ports, and explicit game/query purpose; expand TCP+UDP approval into separate resources.
- Preserve manual aliases and restore only unchanged plugin aliases; display API-synchronized endpoints without claiming external reachability.
- Persist random ownership identities, reject foreign resource/target drift, serialize remote reconciliation, and guard stale revisions.
- Add root-admin infrastructure UI with copyable, bounded YAML merge templates and correct restart/recreation guidance.
- Add native plugin discovery, migrations and admin action/database-queue integration tests.
- Upgrade test dependencies and select patched Symfony HttpFoundation compatible with PHP 8.4.

## 1.0.0 - 2026-07-26

- Add administrator-only per-server Pangolin publication.
- Add node mappings, Egg suggestions, and per-allocation overrides.
- Add TCP, UDP, HTTP, and HTTPS reconciliation with exact remote ownership.
- Add suspension, reinstatement, opt-out, deletion tombstones, retries, and drift recovery.
- Add non-mutating preflight and raw-host configuration snippets.
