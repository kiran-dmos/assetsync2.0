# assetsync2.0

GLPI 11 asset synchronization controller, with an optional lightweight Computer
notification agent for GLPI B. The controller owns mappings, routes, comparison,
billing and UUID reconciliation. The agent only observes B and notifies A.

## Local plugin key

GLPI plugin keys are used in PHP function names, so the internal plugin key is
`assetsync20`. The user-facing plugin name is `assetsync2.0`.

## B Agent Prototype

See [README-agent.md](README-agent.md) for separate A/B installation, explicit
saved-pair verification, direct HTTPS, scoped credentials, health and retention.
Install `assetsync20` on A and only `assetsync20agent/` on B. Stop workers during
the additive controller upgrade; use GLPI's Update action, not uninstall/reinstall.

Prototype verification used fresh, separately isolated GLPI 11.0.7 A/B instances,
Fields 1.24.2, MySQL 8.4 and direct Apache HTTPS with a private test CA. Real tests
covered additive migration with preserved data, independent native/custom hooks,
stateless authentication/CSRF boundaries, durable admission and rollback, competing
claims, inflight/retry revisions, unchanged-A fresh B reads, policy preservation,
no-op echo convergence and authenticated A activity/administration and B agent
configuration HTML permissions. B configuration GET, valid/invalid CSRF, and
zero agent outbound HTTP while viewing were separately verified after fixing
its GLPI legacy-include database scope.
All 35 offline test entrypoints, PHP lint, `git diff --check`, and the separate
MySQL timestamp/DST test pass. The focused suite has 114 SQLite/behavior checks;
19 HTTP-contract checks use mocked transport and are not live TLS evidence.

Limits: no production deployment, 50k load qualification, storage power-loss test,
long soak, or browser screenshot/accessibility audit. A few single-fixture saves
and request counts are diagnostic samples, not performance guarantees. Native UUID
was verified unchanged by agent processing; the full UUID reconciliation workflow
has offline regression coverage, not a new live campaign here. Existing regular
and client-connected GLPI systems were not used. Deploy only after independent
review and environment-specific operational approval.
