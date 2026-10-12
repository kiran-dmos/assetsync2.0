# GLPI B Agent

This branch provides a separate `assetsync20agent` plugin for GLPI B. Install the
controller (`assetsync20`) only on A. B stores typed asset fingerprints and a durable
outbox; it has no mappings and cannot run the controller. In default REST mode A remains responsible
for native/Fields metadata, mappings, permissions, Both/source-of-truth policy,
native UUID handling and billing. The prototype has passed the isolated checks
listed below; this is not a production rollout or scale qualification.

## Bounded Agent-Only Mode

Stop both workers, including scheduled runs, before upgrading AssetSync. Install
the multi-asset B agent code first (0.3.0), then the A controller code (0.4.0),
using GLPI's plugin Update action when upgrading from older installed versions.
New task/pair/journal tables and columns are additive. Existing connections default
to REST and retain their existing behavior. This is an AssetSync upgrade only;
do not upgrade official GLPI or Fields as part of this procedure. Resume workers
only after both code updates and any required additive migrations succeed.

The controller publishes task version 3 with immutable pair ID, item type, item ID
and exact entity, plus the scalar `type` on each change. Install B before A: older
agents reject version 3. There is no protocol downgrade or REST fallback.
Versions 1 and 2 retain their original wire and snapshot shapes. Existing task bodies, hashes, saved
results and journal replay are preserved, including expired tasks with durable
results; do not rewrite, delete or reset them during an upgrade. B validates the
original wire hash before checking scalar values. Refresh observation after the
upgrade to expose Yes/No and Date fields in the mapping catalog.

1. Select **agent** for the A connection. B REST tokens are not saved in this mode.
2. On B's agent configuration, obtain its persisted installation identity. This
   identifies the installation, not the Computer UUID.
3. Open **Agent Pair** on A and register the installation identity. Registration
   needs no asset pair, route, mapping, REST token or connection test.
4. Enter the once-displayed registration/generation/token and connection ID on B.
   Select agent mode and a dedicated active GLPI service account using the native
   searchable account selector. Select a permitted profile with direct nonrecursive
   entity assignments, selected asset type READ
   and UPDATE, and applicable Fields container rights. Its password is encrypted
   using GLPIKey on B only. Set the direct HTTPS notification URL described below.
5. A exclusively owns type selection, asset scope, routes, mappings and pair management.
   B setup contains only transport binding and service identity/permissions, with
   read-only permitted entity information. B has no asset-scope editor. Migration
   enrolls nothing; old configured Computer/entity settings remain solely for
   v1/v2 compatibility. Inventory, links and outbox rows never enroll subscriptions.
6. Request B discovery on A's existing Agent Pair page, then run B's worker.
   The readonly HTTPS task returns at most 50 summaries, a cursor and its original
   observation time. Pending, empty, stale and error are distinct. Choose A and B
   independently by name, type, serial and entity. A checkboxes select only the
   displayed page (at most 50); save page selection to retain explicit choices across
   pages in a cart capped at 200. Each selected A asset needs its own independent B
   choice from a fresh discovery page. Save chosen candidates, then confirm selected
   pairs with individual routes and per-row results (at most 50 per request).
   Candidate selection does not infer positional/name/UUID matches. Refresh its
   independent inspection. Add an exact single-type nonrecursive route on A using
   the B entity selector populated by discovery. Confirm the pair through
   the protected Agent Pair POST. Fresh A reads, matching serial/entity, route
   authorization and reverse uniqueness are required. Existing REST confirmation
   is not agent confirmation. No asset is created, moved or relinked.
7. Map Name, Comments or existing custom **text**, **Yes/No** or **Date** fields.
   Custom endpoint types must match. Each mapping must use
   `glpi_a` or `glpi_b` authority; Both is rejected. Metadata GET uses the bounded
   stored B observation; explicit refresh queues a fresh inspection without REST.
   Run A and B workers independently using the commands below.

B pulls tasks from the separate `front/agent-task.php` HTTPS endpoint and reports
durable results before ACK. A retains immutable task IDs, bindings, expected
values, policy/metadata fingerprints and captured queue revisions; B journals
before execution. A releases its claim while `waiting_agent`. New notifications
remain pending across results; old results cannot complete a newer claim. B's
notifier and reconciliation are restricted to noneditable A-issued typed subscriptions. Unchanged A
backfill converges using an A-side fingerprint; an hourly safety-net inspection
or explicit force obtains new B evidence rather than trusting cached B values.

B uses real Auth login and Session profile/entity selection, fresh direct grants
and model permissions, selected-type search-cache clearing before/after contexts, and
official Computer/Printer/Fields model writes. Existing parent/child rows are locked on
InnoDB through each write and readback. Named official parent history is checked.
Readonly Fields scalars still require ordinary parent/container authorization; their
definition is not changed. A keeps billing calculations after verified inbound
work, guards existing billing child rows and schedules a recheck for derived changes.

Accepted/executing/waiting are not success. Verified/unchanged require supported
readback, and changed B fields require named history evidence. Stale, blocked,
partial and uncertain are retained explicitly. A possible write followed by a
crash is never blindly rerun. Late evidence is retained but is not fresh input.
Only confirmed no-write stale operations offer an administrator retry. Scope
failure before task publication retains revisions with bounded retry backoff.

Yes/No accepts only integer or database string `0`/`1`, transported as JSON integers.
A blank authoritative Yes/No blocks in either direction; a blank destination is
distinct from zero and may be set to either value. Date accepts only a valid
`YYYY-MM-DD` calendar date, or blank/SQL NULL for clearing. Clearing uses the
official model and is denied for mandatory dates. Missing metadata, columns or
existing child rows never count as blank values and cannot trigger row creation.
Date-clear guards consult the current Computer status and fresh Fields overrides.
B conservatively also denies a base-mandatory date clear: Fields 1.24.2/1.24.4's
tab-container validator enforces that base flag even when a status override relaxes
it. No Fields configuration or validation is bypassed to force a clear.
B checks the official localized `Dropdown::getYesNo()` history labels; a date
clear reads back as blank (empty string or SQL NULL). Generated Fields models can
log an empty new value; date-aware models can log the `"NULL"` input sentinel.
Both representations require verified blank readback and named History evidence;
an old database NULL has blank history text. A reuses FieldsText
normalization and its existing model writer. Both writers preserve NULL-to-zero
Yes/No updates despite GLPI's loose comparison.

Creation, types other than Computer and Printer, recursive/inherited-only grants, Both, dropdowns,
textarea, number, datetime, other native fields and UUID mutation are unsupported
in agent-only mode. The separate UUID service
returns `unsupported_agent` before any mutation/REST for agent participation,
including mixed REST/agent participant sets. Supported scalar work remains
available. REST entry points are fenced against agent fallback.

The installation authenticates independently of its pairs. Each pair has its own
outstanding slot; discovery has a separate installation slot. Retained uncertainty
on one pair does not block another. Legacy connection slots resolve only to their
original Computer and are never released or rewritten by migration. Typed unique
keys isolate equal numeric Computer/Printer IDs in pairs, receipts and outbox.
Route approval is appended when confirming another pair. A checks confirmed pair
authorization before issuing/delivering apply tasks; B independently authenticates
the service account and freshly checks exact grants, asset and container rights.
Subscriptions are notification state, never write authority. Discovery and candidate
inspection require fresh permissions but do not require or create subscriptions.
Durable results still replay under their original registration after task expiry or
subscription revocation, without model execution.

Each subscription binds installation, registration/generation, connection, immutable
pair ID and typed target/entity. A issues ordered revisions and fixed 15-minute
leases, refreshing active leases after ten minutes. B rejects conflicting/older
state without extending replayed expiry; omitted pages never revoke other pairs.
Unconfirming a pair or losing its route authorization produces an explicit revocation.
An independent bounded subscription-only pull precedes result reporting each worker
cycle, so task uncertainty, large tasks and lost report ACKs cannot pin subscription
progress. Each page has at most 50 updates; piggyback updates use only remaining
bytes and every total HTTPS envelope remains at most 64 KiB. Hooks mark subscribed
targets dirty without reading values. The worker uses fresh service authorization
for fingerprints and notification claims. Expiry/revocation/permission failures do
not reset old outbox revisions, fingerprints or acknowledgement watermarks.

Types come from each installation's trusted GLPI inventory capabilities. Only
Computer and Printer are implemented in this bounded version; other installed
types display an unsupported reason. No arbitrary PHP class comes from form input.
GET setup/route/mapping pages do not enqueue or execute work or call B REST. Discovery
requires a protected POST and the existing B-pulled task transport. Its summaries
are limited by the existing 48 KiB snapshot / 64 KiB envelope limits.

There is no pruning. A task/pair records and B journal survive uninstall, including
unresolved evidence. Keep credentials private and do not reset/reuse generations.
Task expiry is five minutes; slow/offline work can require fresh inspection.
Keep A and B clocks synchronized. Pair confirmation requires an observation no
older than five minutes; the mapping catalog can display observations up to one
hour old. Receiving delayed evidence does not refresh its observation time.

The bounded snapshot permits at most 32 total eligible fields, including Name and
Comments and eligible custom text/Yes/No/Date fields, even when unmapped. It is limited to 48 KiB of encoded
JSON. Individual text values are limited to 16 KiB and complete task/result HTTPS
envelopes to 64 KiB; JSON escaping and metadata count toward these byte limits.
Oversized work fails closed and needs smaller values or a narrower eligible scope.
The endpoints require
direct HTTPS and normal CA validation, without redirects or insecure overrides.

Agent Pair offers token rotation for both unconfirmed candidates and confirmed
pairs. Rotation preserves task bindings and shows the replacement token once;
update B's token. If initial inspection publication fails, the candidate and its
credentials are still returned; use Refresh observation when storage is available.
Recent task outcomes remain visible after an inspection releases its slot.
Releasing a no-write setup task does not itself queue an inspection: use Refresh
observation. Partial and uncertain tasks remain retained for investigation.

Limits: no distributed transaction across A/B, no atomic protection against every
human policy/metadata edit, and no production, scale, browser/accessibility, inventory
ingress or storage power-loss qualification. Row locks protect existing asset rows,
not all GLPI policy tables. Isolated GLPI 11.0.7 / Fields 1.24.5 / PHP 8.5.7 evidence
and host PHP 8.2 regressions are prepared for independent review, not an approval.
Focused suites: `tests/agent-tasks.php`, `tests/agent-scalars.php`,
`tests/agent-multi.php`, `tests/agent-task-reporting.php` and `tests/agent-http.php`. Scalar tests use host
model doubles and SQLite for normalization parity, both authorities, raw history,
readback failures, stale/no-op behavior and immutable v1/v2 replay. HTTP tests use
mocked transport, not live traffic measurement. These tests do not certify live
GLPI/Fields behavior or InnoDB locking. The scalar executor uses Computer's own
UPDATE permission check, avoiding a helper absent from Fields 1.24.2 and 1.24.4;
independent verification on those installed versions remains required.

## REST Notification Mode Setup

1. Upgrade/install controller version **0.4.0** as `plugins/assetsync20` on A using GLPI's
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
