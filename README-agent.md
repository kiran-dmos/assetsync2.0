# GLPI B Agent Prototype

This branch adds a separate `assetsync20agent` plugin for GLPI B. Install the
controller (`assetsync20`) only on A. B stores Computer fingerprints and a durable
outbox; it has no mappings and cannot run the controller. A remains responsible
for native/Fields metadata, mappings, permissions, Both/source-of-truth policy,
native UUID handling and billing. The prototype has passed the isolated checks
listed below; this is not a production rollout or scale qualification.

## Install and Configure

1. Upgrade/install controller version **0.2.0** as `plugins/assetsync20` on A using GLPI's
   plugin installer. This adds registration/receipt tables, confirmed-link
   evidence, queue revision counters and claim tokens. Stop workers during the
   schema upgrade, including cron, and run GLPI's plugin **Update** action (not
   uninstall/reinstall). Resume cron/workers only after migrations succeed.
   Pre-upgrade running claims cannot
   finish with an absent token and become reclaimable after their existing lease.
2. Copy **only** `assetsync20agent/` to `plugins/assetsync20agent` on B; install
   and enable it through GLPI. Do not install the controller on B.
3. On A, configure the existing connection, Computer routes and mappings. The
   existing controller must have successfully verified each pair after this
   upgrade. Historical positive remote IDs alone are deliberately unconfirmed.
   Ordinary A processing establishes confirmation. For a saved legacy pair,
   including A-authoritative-only mappings, use **Verify saved pair** on the agent
   registration page after step 4. Enter the single saved A Computer ID. The
   protected POST action requires `config UPDATE`, asset read permission and the
   registration's source-entity scope. It makes a bounded read-only B API request
   for that saved ID and checks serial, actual entity, active route and ambiguity;
   it changes only confirmation evidence. No dummy data edits are needed.
   The notification path never searches serials, creates assets, merges, relinks
   or moves B entities.
4. Open **B Agent Notifications > Agent registrations and blocked notifications**
   on A's dashboard/activity page. With central `config UPDATE` and the route's
   source entity active, select one connection and its approved Computer routes.
   Copy the once-visible registration, generation and token into B's plugin setup.
5. Set B's receiver URL to
   `https://A/plugins/assetsync20/front/agent-notify.php` (include any GLPI prefix).
   The receiver requires direct web-server TLS (`HTTPS=on/1`); it ignores forwarded
   protocol headers. TLS-terminating proxies without direct TLS to GLPI are not
   supported by this first prototype. Configure normal system/PHP CA trust for a
   private lab CA; there is no insecure HTTP or certificate-verification override.

Run from B's GLPI root as its normal GLPI CLI user:

```sh
php bin/console plugins:assetsync20agent:worker
php bin/console plugins:assetsync20agent:worker --loop
```

Keep A's existing worker running independently:

```sh
php bin/console plugins:assetsync20:worker --loop
```

Use a process supervisor for continuous mode. Each B cycle has a 20-second soft
deadline, at most 20 notifications, a 5-second maximum HTTPS request and a database
worker lock. A request never follows redirects. Retries persist across restarts
with 15-second exponential backoff capped at one hour and 60-second claim leases.
Computer saves do local SQL only. Reconciliation examines at most 50 rows across
two persistent cursors per cycle within a 2-second soft budget. Individual SQL
statements cannot be preempted; Fields metadata is capped at 100 containers and
500 fields per container, with incomplete snapshots left for a later pass.
The same bounded worker sends a separate authenticated health report normally
every 60 seconds, and when reconciliation health changes (at most one per cycle).
Health is not an asset notification or a synchronization result. Reconciliation
failures persist as degraded instead of being hidden by the worker timestamp.

## Delivery and Scope

B coalesces changes in one outbox row per Computer. A receipt stores the latest
received, admitted and completed revisions. A returns an exact registration,
generation, Computer ID and revision ACK only after receipt plus real queue
admission commits. Duplicate notifications after lost ACKs are idempotent. New
arrivals do not reset running leases or retry timing. Random claim tokens fence
all queue/outbox ownership writes; old ACKs cannot clear newer revisions.

Each registration binds an enabled connection identity and fingerprints of its
approved route definitions. Editing route scope requires new approval through a
new registration. Replacing/deleting a connection or changing its credentials
invalidates registrations and confirmations. Token rotation through A's agent
page preserves the registration generation and all revision watermarks. Update
B with the new token. Revocation prevents new admission and is rechecked by jobs
before mutations. New registrations have new random generations.

Agent-triggered jobs require exactly one current reverse link, independently confirmed,
then check the current A route and actual B entity. They bypass the unchanged-A
hash shortcut and use the existing mapping engine. Ordinary unchanged local
notifications retain the zero-network shortcut. Notifications raised by A's
writes on B can cause one additional read; unchanged snapshots and no-op mapping
comparisons stop repeated writes. Controller-side conflicts remain visible.
An extra unconfirmed current reverse link also blocks admission. Existing
controller-authorized read-only Fields writes remain allowed; the agent does not
change this policy or ordinary Fields permission checks. Actual B scope is checked
before billing preparation can persist derived fields on A.

## Baseline, Deletes and Retention

The first reconciliation snapshot is a **silent baseline**, so installation does
not enqueue the entire inventory. A hook racing that baseline retains its revision.
Native values and supported Fields scalar/dropdown IDs are fingerprinted using
B's own generated Fields classes/tables. Volatile modification/inventory dates
are excluded. Later native/custom changes and missed hooks are detected locally.
Dropdown catalog-label edits without a changed Computer reference are not a
Computer-row notification; ordinary controller rechecks remain necessary for
catalog-only changes.

Deleted/templates and tracked purged Computers produce retained blocked
notifications. Scope exits block at A. There is no delete propagation, recreation,
entity move or relinking from agent work. Unknown/ambiguous/unconfirmed links
return a visible blocked result; B retains and retries them so later confirmation
can admit a present Computer.

There is no automatic pruning. Current watermarks, blocked records and unacknowledged
work must not be deleted. Uninstalling B preserves its outbox and removes credentials;
configure a new A registration/generation on reinstall. A uninstall revokes agent
registrations and retains receipt/registration tables. Do not restore old credentials
or revisions into a newer generation. These records describe current invalidation
state, not an audit history.

## Activity and Verification

A's dashboard and Sync Activity show visible Computers' received/admitted/completed
revisions and queued, processing, retrying, blocked, unchanged or synchronized
outcomes. **Accepted is not synchronization success.** Synchronized means the
mapping engine reported successful field changes; finishing an empty queue job
reports unchanged. A processing failure can follow partial field updates. Unlinked
blocked IDs are visible only on the registration administration page within the
approved source-entity scope. GET page rendering does local reads only and exposes
no tokens or field payloads. The explicit protected **Verify saved pair** POST is
the exception: it performs the bounded remote read described above.
Registration/rotation POST responses display newly issued credentials once.
Separate health shows no heartbeat, reporting/reconciliation OK, degraded
reconciliation, stale heartbeat after 180 seconds, or revoked. Stale means contact
is overdue, not proof that B is offline. Health ACKs use `kind: health` and
`recorded`, never asset `accepted`/revision ACKs.

Offline checks:

```sh
php tests/agent-prototype.php
php tests/agent-http.php
php tests/queue-notifications.php
```

The focused SQL suite executes real SQLite transactions/constraints with a small
MySQL dialect adapter; it tests rollback, commit failure, duplicate/restart,
fencing, generation races, scope revocation, missed hooks and fresh inbound reads.
It is not a substitute for InnoDB concurrency or GLPI integration. Existing offline
tests cover mapping compatibility, custom permissions/read-only policy, Both,
UUID, billing, scheduling and activity visibility.

Live verification used newly created isolated GLPI 11.0.7 A/B and MySQL 8.4
databases, Fields 1.24.2 and direct Apache TLS with a lab CA. It verified installation
and additive upgrade preserving saved configuration/data; native, `last_boot` and
independent generated Fields hooks; no save-time controller/agent HTTP; scoped
stateless admission and real administrative CSRF; actual A/B values after inbound
work; Both/A authority, UUID isolation, existing read-only policy and billing;
no-op echo convergence; and A dashboard/activity/registration HTML permissions.
A follow-up separately verified the B configuration page over authenticated HTTP
inside the isolated lab: full GET rendering, missing/invalid CSRF rejected, valid
CSRF accepted, config-READ-only and no-config users denied, and zero instrumented
agent outbound HTTP during page requests. Viewing left settings, worker timestamps
and outbox state unchanged. The B receiver URL still requires verified HTTPS.

Real InnoDB tests checked durable commit settings, concurrent duplicate admission,
competing process claims, running/retry arrivals, stale-owner and old-ACK fencing,
lost ACK replay, trigger-induced admission rollback and a receiver killed before
commit. TLS tests rejected untrusted CA, wrong hostname, spoofed forwarded TLS,
wrong credentials and malformed requests; storage failure returned 503 without
an ACK. Reconciliation fault injection produced persisted visible degraded health.
`tests/mysql-timestamp.php` passed against the isolated DB, including DST fold.
The 35 offline entrypoints pass, including 114 focused SQL/behavior and 19 mocked
HTTP-contract checks. Offline checks complement, not replace, live evidence.

Single-fixture samples were 50-157 ms for save plus hook/assertion work with zero
instrumented controller/agent HTTP. Native inbound used 6 HTTP requests, custom
inbound 12, and saved-pair verification 3 including API session setup/teardown.
These are not scale benchmarks. No 50k run, long soak, full live UUID reconciliation
campaign, host/storage power-loss test, or browser screenshot/accessibility audit
was performed. UI evidence is real authenticated HTTP/HTML. Production and regular
client-connected systems were untouched. Only new owned lab containers were used
and are stopped/preserved rather than deleted. Keep all protocol tables on InnoDB
with durable commit settings; external changes across A/B cannot be one distributed
transaction, so failures may follow partial mapping writes and retries must converge.
