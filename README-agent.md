# GLPI B Agent

## Scoped REST Routing Checkpoint (Settings3 / Work10)

Controller **0.9.5** and agent **0.8.5** add a bounded routing extension for
confirmed Computer pairs with one native Name mapping owned by GLPI A. This
checkpoint does not add custom-field sync, creation, or child-scope Both.
Install B before A through the supported plugin Update and Enable lifecycle.
Quiesce workers and saves during the upgrade, preserve pending work and journals,
and follow the browser-capture coverage limitations below. A plugin update does
not manufacture original edit evidence for older values.

A's saved Entity Route selects the connection, source entity, optional source
children and exact B target entity. Complete source ancestry is required; the
existing deepest-route and conflict rules still apply. A and B entity IDs are
independent. An existing confirmed target is never moved or relinked because a
route changes. Participation comes from the confirmed pair and current route;
the legacy REST pair selection remains for old protocols, not a second route list.
Mixed-type routes may include Computer, but this checkpoint executes only Computer.

Settings3 and Work10 are distinct schemas, not extensions to old task bodies.
They share the existing settings scope, monotonic revision and active head with
Settings2 and Settings4. A lower schema cannot replace a newer active head, even
with a higher revision. Unchanged Settings4 Name Both documents stay unchanged.
If a changed scope cannot be represented by its frozen Settings4 schema, A
publishes a valid higher-revision disable record and holds the scope visibly.
It does not silently choose A authority, downgrade, or reinterpret retained work.
A later explicit schema is required for child-scope Both and broader mappings.

Settings publication takes fair turns across connections, with bounded retry
backoff. Disabled connections make no authentication requests. Late replies
cannot acknowledge a newer settings revision. Metadata and stored-result reads
use the exact binding, type and authorized target entity, with fresh direct API
and service-account grants. They do not execute work, broaden entity grants, or
fall back to REST asset operations. A provides its own source-ancestry proof; B
does not independently claim to know A's entity tree.

These are implementation limits, not production-scale or latency guarantees.
Host tests and isolated native verification must be recorded separately. All old
work bodies, hashes, results and mutation journals remain retained. Name Both
Work11 and its bounded never-reserved recovery below keep their existing rules.

Work dispatch examines at most32 due candidates per invocation under the existing
deadline. A small persisted cursor advances before each attempt and sweeps a fixed
upper boundary, so a retained malformed or non-reservable task cannot permanently
hide healthy work behind it. A successful reservation ends selection for that
pass. Cursor selection uses a short local lock, released before asset locks or
network requests. Skipping a candidate does not change its body, result, lease,
retry time or authorization. Invalid held work is not repaired or certified by
this selection change; its evidence remains available for investigation.

## Original-Edit Name Both Checkpoint (Settings4 / Work11)

Controller **0.9.4** and agent **0.8.4** add the first original-field Both
checkpoint: an existing confirmed Computer pair, exact entity route, and one
native Name-to-Name mapping. Update B, then A with the supported plugin Update
and Enable lifecycle; quiesce saves/workers as described below. No new table is
needed beyond the installed capture heads and existing settings/work/journal
tables. Keep the pair explicitly selected in the existing v8 batch pair list.
Choose Both in the existing mapping control; A publishes settings4 with a new
durable revision. Old peers reject it, without a legacy/transport fallback.
Settings3/Work10 provide the separate A-authoritative routing checkpoint above.
Protocols/settings1-9 retain their original schemas, bodies, hashes and replays.

This is **Name-only, not complete Both support**. Broader supported native/custom
scalars and references/dropdowns, different keys/containers, reverse conversions,
field-specific conflict groups, fanout/first-copy and the remaining REST roadmap
still require separate implementation and independent qualification. Legacy
protocol6 Both is not original-browser-evidence qualification for the new flow.

Newest **genuine mapped-field edit** wins, including a permitted newer blank.
Values normalize null to blank without trimming/case folding; equal normalized
values cause no model write, new origin or duplicate History. For unequal values,
A wins only when A's original lower UTC bound is strictly greater than B's upper
bound; B wins symmetrically. Missing, same-second/equal or overlapping evidence
is a visible Name conflict, with no model mutation. Comparable installation
clocks remain an assumption, not a claim that UTC establishes clock trust. No
baseline, asset `date_mod`, fixed priority or guessed original time participates.

Logical eligibility hashes normalized Name, original event identity/revision,
technical field identity, current settings and confirmed asset identity. They
exclude capture head-generation/epoch/History housekeeping. An unmapped Comments
save can therefore advance the exact execution guard without publishing Name
work. Both the scanner and queued-hook processing check this locally: zero
per-asset/auth requests and no invented verified/link success. A local skip does
not update the last probe time. Full immutable guards remain in prepared Work11
and are rechecked before dispatch and under owned transaction locks. A preparation
with changed or unavailable source evidence makes zero HTTP/auth requests. Only
work proven **never reserved for dispatch** can be replaced: its initialized lease
must still be empty, its result empty and its current pair slot unchanged. A's
ordinary queue claim then rechecks fresh source evidence, rules and the confirmed
pair before atomically transferring the slot to a new immutable work ID and batch.
The old body, hash, result and job remain intact, with a superseded status and
successor reference. Replacement does not count as a successful sync.

An expired lease is not proof that work was never dispatched. Any retained lease,
attempt marker, mutation receipt or uncertain result prevents this replacement.
Reservation and attempt markers are fenced durably before HTTP so a paused old
dispatcher cannot send or finalize replaced work. Already-sent work with a changed
source is observation-only, not permission to replay the obsolete mutation.
Unavailable fresh evidence or a midflight change can still hold work conservatively.

A's ordinary bounded backfill scanner currently schedules an unchanged pair
again after **60 seconds since its last completed reconciliation** to discover
B-only edits. This is temporary **per-pair current-state probe overhead**, not
shared polling, a production latency guarantee or the final diagram's request
count. There is no required B daemon. Wave7's immutable changes GET/exact ACK
delivery is not implemented by these probes. A normal eligible request uses a
bounded POST then stored-result GET when ready; auth/settings, probes, failures,
retries and metadata continuations are counted separately.

Work11 batches contain at most **two** rows and49,152 request bytes. Each public
row is bounded to31,000 native JSON bytes, so two rows plus punctuation stay
under the unchanged64KiB response cap, including255-character supplementary
Unicode/control/quote/backslash Name evidence. Collection lookup requires exact
batch+compiler11, range at most two, `get_hateoas=0`, no debug. External
body/hash/result remain exact; only private compiler fields are omitted. The
existing v8 four-row/16,000-byte-row contract is unchanged.
One monotonic five-second execution budget is shared by the admitted batch,
not renewed per member. Admission and post-model checks prevent over-budget
model commits. A synchronous official model call cannot be preempted, so this
is not a hard HTTP response-latency guarantee; an overrun rolls back that member.

Each local write uses the supported Computer model with fresh mapping, scope,
identity, readonly/access and full-value guards. B retains its separate real
service login; the exact inherited-origin scope is inside that authorized write,
beneath the outer unknown-sync scope. A uses its established internal cron job
boundary with fresh confirmed route/entity/ownership checks, not a fabricated
interactive session; interactive callers still need native READ/UPDATE/entity
access. Native field History and typed readback must corroborate the transition.

Model+History+an exact inherited receipt commit atomically as `origin_pending`.
That is **not verified completion**. B's public result stays empty until an
idle metadata-only pass proves the inherited original event and fresh guards;
A likewise leaves its queue/slot unfinished until its local evidence qualifies.
If the process/reply is lost after commit, identical POST (including after work
expiry) or the ordinary A job recovers only the persisted receipt, never reruns
the asset mutation or mints a new time. GET only reads persisted state and checks
fresh READ/guard evidence; it never settles or executes. An executing orphan
without a committed receipt remains uncertain, not permission to replay.
Only a validated terminal **no-write conflict** may retire its slot while retaining
the immutable body/hash/result, letting a genuinely newer edit be reconsidered.
Uncertain/executing/ambiguous work remains held; no blanket slot reset is provided.
A later B edit can also fail the exact guard on terminal GET disclosure, leaving
A unable to observe that immutable result and its slot held. This is a known
finite checkpoint limitation. The bounded never-reserved replacement above does
not provide general stale-slot recovery or full liveness.

Host suites `agent-name-both*.php` exercise protocol/decision parity, response
bounds, guards, committed fresh-process evidence recovery, model rollback,
queued unmapped zero-RPC behavior and ordinary due scanning. These use SQL/model
or HTTP doubles. Actual ordinary Chrome wins/blanks/no-op/conflicts, unmodified
A CLI worker B-only reconciliation, native transaction/deadline/permission
faults and protected old evidence are independent Debugger gates before this
checkpoint can be called qualified. Name success never implies full-field Both.

## Local Browser Field Evidence (Capture Only)

Controller **0.9.3** and agent **0.8.3** add independently packaged local field
capture. Update each plugin through GLPI's normal Update action, then Enable it.
Quiesce asset saves and workers during Update/Enable/Disable: old or unloaded
in-flight requests cannot acquire the new capture hook retroactively.
Each install/upgrade, activation and deactivation rotates an internal coverage
epoch. Existing evidence tables are retained; an absent pre-upgrade protocol
marker is inert, and a new head starts unknown. No historical backfill or
add-origin inference occurs. There is no mapping/route/transport switch for
capturing local facts.
The private table has a `model_class` index for covering bootstrap class
discovery; this is not a measured large-installation performance claim.

The technical envelope is native Computer Name and Comments, plus existing
generated Computer Fields single text/textarea rows. Native-only saves do not
depend on Fields. Generated classes remain hooked even when their old container
or business mapping is disabled. Capture does not grant permissions, change
inventory/readonly behavior, add missing child rows, or change any REST body,
hash, result or legacy Both behavior. **This slice does not enable REST Both.**

A private head per local Computer/field records a generation, every unfinished
attempt token, overlap taint and an optional candidate. Registration precedes
model SQL. Fresh locking reads must agree with model-loaded old values and row
identity; postprocessed changes must agree with full typed readback and fresh
metadata. Failed registration cancels the model call with a sanitized error
before remaining plugin callbacks can continue. One completion cannot clear
another token. Overflow preserves all unfinished tokens and cancels new saves.
Coverage and shared Fields definitions use portable `LOCK IN SHARE MODE`
current reads, including absent/inactive coverage decisions; an old ordinary
Config/RR snapshot cannot bypass registration. These readers are compatible
across assets, not exclusive global locks. Per-asset head/value reads still lock
their own rows. No caller isolation is changed. Fresh human origins require
transaction-idle pre-capture. Trustworthy loaded-old proof is checked separately:
an exact trusted scope can inherit its original event inside the caller's
transaction, and proven unchanged fields can retain their earlier origin after
commit. Unscoped or legacy changed fields stay unknown; rollback restores the
original evidence along with the model change.

Exact field History references and one expected official History projection
corroborate a genuine typed transition. History's 255-character truncation and
JSON blanking never substitute for the full value hash. Fields parent History
is written after the child post-hook, so its candidate remains unfinished until
that parent row exists. References come from fresh official definitions, not a
profile-filtered CLI search-option list. Only hashes and metadata identities,
not raw field values or credentials, are persisted.
Technical field identity excludes labels, readonly/default/display settings,
parent entity and deleted scope. Unchanged field evidence survives those UI or
scope changes; storage/type/reference changes invalidate it. Business callers
must still enforce fresh permissions, readonly policy and current entity scope.

Origin bounds are inclusive database UTC seconds read with zero-argument
`UNIX_TIMESTAMP()` before/after the model operation. Cross-second and long saves
are valid intervals; backwards time is unknown. These are model-save bounds,
not commit timestamps. A future Both comparison must require strictly disjoint
intervals and comparable installation clocks; UTC alone does not prove clock
trust. This capture-only release performs no winning-side selection.

One request-end callback makes a bounded, non-waiting pass over only its own
tokens, requiring the original connection, an idle transaction probe and fresh
value/metadata/History/generation checks. It never starts, commits or rolls back
a transaction. Active caller transactions, fatal interruption, missing context
or missing delayed History leave pending unknown. A no-post no-op can preserve
an earlier origin only with uninterrupted value, metadata and field-History
proof. Ordinary GET requests and public settlement remain read-only/forbidden:
they never settle, clean up or promote candidates. No hook changes caller
transaction ownership. Crash orphans have no timeout-to-success or automatic reap.
The pass has a 100 ms cooperative budget and no sleep/retry; database/native
calls can exceed it, so it is not a hard request-latency guarantee.

Before that pass drains candidates, one bounded metadata-only CAS statement
hands off up to 1,024 exact post receipts (256 KiB predicate cap) from the clean,
idle, original-connection callback. Change and unchanged-field post receipts
retain their original bounds and generation. `FieldEvidence::settle(id, key)`
provides a single-head POST/CLI fallback with fresh value/metadata/History/epoch,
generation and overlap checks; it is not a new remote endpoint. Pre-only attempts
and candidates without that request-end proof cannot recover into success.
The next model POST/worker may settle a clean deferred receipt before registering
new work; GET cannot. Larger handoffs and unresolved attempts stay unknown.
Existing A job and B executor finalizers also finish their own metadata after
model transactions/wrappers, before later jobs/network, so long-running CLI
workers need not exit to clear ordinary attempts. At that explicit existing job
boundary, a legitimate legacy web-Cron job running under GET can finish only
its own process-owned tokens after its transaction/wrapper ends. This does not
initiate sync under GET or permit public GET settlement. Completed owners/frames are
removed from memory; durable foreign/orphan attempts are never silently reset.
Budget-spilled post ownership is released from memory only after the entire
exact CAS handoff succeeds. Unknown legacy/foreign-context post receipts may
retire **unknown**, never publish an origin; any qualified or inherited change
still requires the exact parent History corroboration above.

Current evidence also validates the coverage epoch, local revision, full typed
hash, metadata, anchor contents and latest **field-specific** History watermark
across all actors/values. Unexplained rows, deleted anchors and logged browser
edit/revert during missed capture return unknown. Raw SQL, selective History
deletion and arbitrary missing-code/global-capture outages are not universally
covered. No heartbeat or History-only origin reconstruction is attempted.
Exact process-local trusted sync scopes can inherit a verified event only for
the exact asset, field and before/after hashes. Existing legacy/derived internal
sync scopes remain unknown; a service-account browser edit is not suppressed
merely because of its username. Unscoped API/CLI/inventory writes also remain
unknown, including retained legacy REST callers; they do not mint a new human
origin. An explicit trusted exact sync scope may still inherit its verified
original event in those contexts.

`php tests/field-evidence.php` exercises both packaged helpers with SQL/CAS and
model doubles, including actual PHP fatal shutdown, fresh-process deferred
settlement and more than 32 jobs through real job finalizers. Full host regressions/lints
are required before freeze. Ordinary Chrome/official Fields, InnoDB contention,
callback lifecycle and protected legacy-row preservation remain independent
Debugger gates; these host checks do not establish live browser qualification.

## A-Driven REST Setup And Settings (4a)

Controller **0.9.2** and agent **0.8.2** add an explicit **REST records** transport.
Update B first, then A through GLPI's plugin Update action. B adds a private
settings-head table through its existing install hook; repeated installation
preserves old Work/settings columns and evidence. The default transport remains
**Adapter**. Existing credentials, bindings, pairs and pending tasks are retained.

For REST records, A needs B's direct HTTPS `/apirest.php` URL, B's native app/user
tokens, stable local registration identifiers and saved routes. A does not need
an incoming A peer or the old B Agent API URL for this transport. B needs its
incoming native peer, binding identifiers and separate local service account;
it does not need A's URL or tokens. Select REST records on both sides. Adapter
authentication is unchanged, and retained credentials are available for rollback.
Switching transport does not send pending REST work through the adapter.

A's worker drives publication and bounded Work POST/GET. No B worker or HTTPS wake
is required in this mode. B fences adapter run, wake, send, pull and direct claims
against fresh saved transport, including callers holding old adapter settings.
Its retained adapter health/outbox remain inspectable as previous evidence.

The original4a slice supports only exact-entity Computer routes, one Name-to-Name mapping
with A authority and explicitly opted-in confirmed v8 pairs. Child-entity and
mixed-type routes remain unsupported here. The selected v7 pair setting remains
separate; a route governed by v2 is not silently downgraded to older settings.

A saves immutable settings v2 bytes and monotonically increasing revisions under
a short configuration lock/owned transaction. Only effective canonical changes
advance the revision. Retries reuse the same bytes. Disabled/deleted routes publish
disable tombstones without asset jobs on active connections; disabled connections
make no requests. B atomically stores the immutable version and current head under
its worker lock: higher revisions activate, equal/exact retries are idempotent,
equal/different conflicts and lower revisions reject. A missing head with retained
v2 history fails closed. New execution checks the current enabled head before
compilation or writes; historical terminal results retain their original evidence.

A may retire an old v8 slot only after an explicit `settings_superseded` stale
receipt with no changes and a valid newer policy. The old body/hash/result remain
stored, and replacement work receives a new ID. Executing, uncertain, ambiguous,
ordinary expired and legacy v7 slots are not released by this rule.

Agent Pair can request Computer/Name definitions for a target entity without a
remote asset row or confirmed pair. Explicit candidate IDs can request typed
inspection, followed by the existing separate confirmation step. These versioned
read-only Work requests use POST plus stored-result GET with fresh native/service
READ context; GET does not execute, and discovery never activates empty mappings
over a business route. An `observed` inspection is not a verified asset mutation.
The unchanged 64 KiB response cap applies; v8/v9 public rows are bounded to 16,000
encoded bytes. Auth, shared settings, polls, retries and continuations count
separately from the ready Work POST/GET path.

Focused checks are `tests/agent-rest-transport.php`,
`tests/agent-rest-settings-model.php` and `tests/agent-rest-settings.php`, plus
retained REST/native regressions. They use model/HTTP doubles and SQLite. Actual
stock API activation, transaction/lock behavior, fresh service READ and PHP 8.5
qualification remain independent gates before accepting 4a. Distinct source/target
and child scopes, multi-connection fairness, new fields, Both, creation, UUID and
B change/ACK delivery are not implemented by this slice.

## Opt-In Name REST Batches (V8)

Controller 0.9.1 and agent 0.8.1 add opt-in batching for independently confirmed
Computer pairs with exactly native Name to Name and A authority. Update B first
through GLPI's plugin Update action, then A. B's existing install hook adds nullable
batch/result columns and a scoped index to its existing Work table; repeat Update
preserves existing records. Keep the selected v7 pair in its existing setting.
Use **Name REST v8 batch pair IDs** on the A connection for additional confirmed
pairs. Blank keeps batching disabled. No mappings or pairs are enabled globally.

A groups immutable v8 work only within one run, connection, binding, settings and
target entity. Each row pins its batch ID before publication. B admits native input
lists up to ten rows and 49,152 encoded request bytes; A currently publishes at most
**four rows per batch**, shrinking further for the request-byte bound. This is a
conservative supported batch size, not a ten-row throughput claim. Ten assets need
three batches and six successful sync requests when every result is ready at its
first GET. A warm ready batch uses one POST and one collection GET; auth, settings,
polls, retries and continuation are additional requests counted separately.

B validates the entire list's schema, hashes and shared scope before core adds any
row. Native per-row authorization/execution failures may still yield 207 or 400.
POST IDs indicate acceptance only, and response positions are not result identities.
A matches collection results against exact stored work IDs, hashes and external
bodies. A complete successful read can recover missing members with identical IDs;
failed or partial-page reads remain unknown and never authorize a resend. Each A
member finishes only under its own fresh queue claim and source/scope checks.

The request shares one five-second monotonic deadline across native model instances.
Later rows may remain unaccepted when that window expires; accepted unstarted rows
can continue through identical POST. GET never compiles, executes or changes expiry.
An expired accepted projection becomes stale on A. An executing empty projection
can receive identical POST even after expiry solely to reconcile its retained journal;
unresolved intent is never executed again. A local model overrun rolls back its
transaction and retains uncertainty; successful v8 results persist start/end timing
with verified readback. Native methods and database waits can exceed this cooperative
budget; there is no hard five-second response guarantee.

V8 executing projections commit with journal intent. Terminal projections, native
model/History and journal results share a transaction. Lost/ambiguous commits cannot
be inferred successful from an in-memory result. Collection reads use these stored
outcomes directly. External body/hash/result remain exact; only v8 compiler internals
are omitted from API replies. Each public row is bounded to 16,000 encoded bytes;
four rows plus array punctuation fit the unchanged 64 KiB client cap. Batch reads
require `get_hateoas=0`, no debug parameter and pages of at most four, with an exact
32-hex batch predicate nested inside mandatory binding/entity SQL scope. Existing
v7 virtual GET evidence and protocol 1-7 bodies, hashes and results are preserved.

Focused component checks: `tests/agent-rest-batch.php`,
`tests/agent-rest-batch-model.php`, `tests/agent-rest-batch-admission.php` and
`tests/agent-rest-acl.php`. These use HTTP/native model doubles and SQLite; actual
stock API batching, deadline/partial failures and transaction behavior require
independent isolated qualification. This slice adds no B change queue/ACK flow,
new field families, Both, creation, UUID or broader transport/settings redesign.

## Optional Name REST Records (Wave 1)

After updating B first, A can opt one existing independently confirmed Computer
pair into stock GLPI REST records. On that A agent connection, set **Name REST
confirmed pair ID** and the direct HTTPS **Name REST API URL** ending in
`/apirest.php`. Leave the pair ID blank to keep the existing transport. Reuse the
connection's native B app/user tokens; B's outgoing HTTP credentials are not used
by this path. The selected pair must have exactly one saved mapping, native
Name to Name with `glpi_a` authority. Additional mappings, creation, other types
and other authorities reject explicitly. Other pairs keep their current path.

A's normal worker/scanner/hook queue publishes immutable, secret-free
`GlpiPlugin\Assetsync20agent\RestSettings` records only when needed. They identify
the approved route/entities and Name mapping by version/ID/hash. Each immutable
`GlpiPlugin\Assetsync20agent\RestWork` record pins that settings ID/hash and the
confirmed target. The normal work exchange is one Work POST and one Work GET,
plus native init/kill lifecycle requests. Settings publication is a separate
setup/change request. Network calls occur after A releases its run/asset locks
and transactions; finalization reacquires a fresh queue claim and checks scope
and source again. A newer source edit remains pending for a subsequent request.

The initial Work POST commits acceptance before attempting one bounded local
pass. If busy or unstarted, A first reconciles with a successful GET, then can
POST the identical Work ID/body/hash as continuation. Failed GET is unknown,
never absence. Duplicate terminal requests read the existing outcome; an
unresolved mutation intent is uncertain and never rerun. GET does not execute.
Stock POST returns its ordinary record ID, not proof of asset mutation. The
persisted journal result/readback supplies that proof. Native no-op skips update
and asset History. Unstarted expired work reports expiry. Investigate retained
uncertain evidence before switching transport; disabling the opt-in cannot send
pending REST work through the legacy executor.

If local service login or permissions prevent compilation after acceptance, Work
remains `accepted` with no mutation journal or result. A retains the request phase,
HTTP status and an allowlisted error code in the existing task context, along with
the last Work POST diagnostic, observed state, next attempt and execution expiry.
Activity shows accepted work as completion pending and distinguishes an unknown
outcome from a received result awaiting A finalization. A generic HTTP 400 does
not identify a permission failure. Response text, payloads, tokens and URLs are
never retained in these diagnostics. Restoring permissions before expiry permits
the identical Work to continue after GET reconciliation. Otherwise unstarted work
expires and A retains a stale result for inspection. Failed GET never authorizes
resending an ambiguous POST.

Resource admission rechecks the pinned native account, exact profile/entity
grant, native READ, account/MFA policy and App-Token on every operation. An app
token shared by multiple active API clients rejects. The separate local writer
still uses its native password and fresh READ/UPDATE permission. Stable local
peer/service identities bind records; secret rotation alone does not change the
B record binding. Name snapshots use core metadata and parent-only locking;
unrelated Fields containers and unmapped Comments are not loaded/exported.

POST accepts exactly one input object and a bounded body. Use JSON
`session_write: false`; writable PHP sessions and string boolean values reject.
GET collections require an explicit range of at most ten records and apply SQL
scope before pagination. Resource records are immutable through PUT/DELETE.
The internal Name compiler uses journal envelope 7 with `report_status=rest` and
`acknowledged=0`; it is excluded from legacy pull/report/recovery selection.
Existing protocol 1-6 bodies, hashes, results and default behavior are retained.

Focused offline checks: `tests/agent-rest-acl.php`, `tests/agent-rest-name.php`,
`tests/agent-rest-coexistence.php`, `tests/agent-rest-journal.php` and
`tests/agent-rest-observation.php`. `tests/agent-rest-dispatch.php` exercises
diagnostics, deny/restore continuation, ambiguous POST/GET, expiry, redaction and
standalone session compatibility with mocked HTTP. Actual core
REST, TLS, native model/History and concurrency require isolated integration QA.

## Native-Only Setup And Upgrade

Controller **0.9.2** and agent **0.8.2** use GLPI-native authentication for all
agent communications. Custom registration bearer tokens and wake keys, their
rotation controls, and B's notification-only REST mode are removed. Ordinary
controller REST asset synchronization and its GLPI credentials remain supported.
The following bidirectional setup is for **Adapter** transport; use the A-driven
REST setup above instead for **REST records**.

1. Back up both installations and pause AssetSync workers/scheduled runs. Install
   the complete **B plugin first, then A**, using GLPI's plugin **Update** action.
   Delete stale deployed copies of A's front/agent-notify.php and front/agent-task.php
   and B's front/agent-wake.php. Refresh serving PHP OPcache and reload the A worker.
   Do not upgrade GLPI or Fields as part of this change.
2. Update removes obsolete custom credentials through GLPI configuration APIs.
   Both sides disable wake once and clear derived admission/retry state; explicitly
   re-enable native wake after configuration. Repeating Update does not disable a
   wake already re-enabled after migration. Existing registration, generation,
   installation, pair/link IDs, tasks, hashes and journal evidence are preserved.
   Do not re-register or reset an existing agent binding.
3. On A's agent connection configure the B Agent API URL; on B configure A's URL:
   A: https://A/plugins/assetsync20/front/agent-api.php
   B: https://B/plugins/assetsync20agent/front/agent-api.php
   Include any GLPI URL prefix; marketplace aliases are accepted. Direct HTTPS,
   a trusted certificate and a matching hostname are required; no redirects.
4. Configure the destination's native GLPI app token and user API token in each
   direction. Enable GLPI's legacy REST API and external-token authentication and
   restrict each API client to the actual caller IP. Select the expected incoming
   local user/API client/profile/initial exact entity grant on each installation.
   These are explicit local selections, not IDs inferred from the other server.
   Transport accounts should have only required asset/Fields READ, not config
   UPDATE or asset UPDATE/CREATE. Enabled/enforced transport MFA is rejected.
5. Keep B's separate local service account/profile/password for real GLPI model
   writes. Its password stays on B; never give A the writer's user API token.
   Native app/user tokens and the local service password are encrypted with GLPIKey.
   Session tokens exist only in memory for one bounded turn. The native adapter
   permits session lifecycle and named agent operations, never generic asset CRUD.
6. New installations register B's persisted installation identity on **Agent Pair**.
   Registration returns binding identifiers only. Enter registration, generation
   and connection ID on B, then configure routes/mappings as described below.
   A's worker is required. B may use its CLI worker or optional native HTTPS wake;
   wake uses the same B Agent API URL and credentials, with no separate URL/key.

Old notification-only or incomplete native configurations stop before B scans,
claims or executes work. Missing native credentials never trigger a fallback.
The registration admin page retains evidence, revocation and saved-pair verification; it
does not issue or rotate communication tokens. Keep all unresolved evidence.
Downgrading code to a legacy release is not a supported authentication rollback.

B reuses one A session across pulls/results/recovery/notifications/health. A opens
one B session per bounded wake. Core initialization/termination calls count
separately from protocol requests. Authentication failures retain durable results
for a later bounded cycle; ambiguous mutations are never automatically replayed.
The API releases its session lock before local model execution, rejects cookies
and query/body credentials, and refreshes permissions per operation.

Offline checks use mocked HTTP/core sessions and SQLite/GLPI doubles, not a claim
of deployed core compatibility, crash safety, scale or production readiness.
Focused suites are agent-native-session.php, agent-native-api.php,
agent-native-settings.php and agent-native-legacy.php under tests/.

This branch provides a separate `assetsync20agent` plugin for GLPI B. Install the
controller (`assetsync20`) only on A. B stores typed asset fingerprints and a durable
outbox; it has no mappings and cannot run the controller. In A's ordinary REST asset mode, A remains responsible
for native/Fields metadata, mappings, permissions, Both/source-of-truth policy,
native UUID handling and billing. The prototype has passed the isolated checks
listed below; this is not a production rollout or scale qualification.

## Bounded Agent-Only Mode

### Optional Native HTTPS Wake

Enable wake on B after saving its complete native binding, then on A's connection.
Changes to native credentials or service scope invalidate B's wake binding; save
its wake setting again. Disabling B blocks incoming wake; disabling A stops sending.
B's CLI worker and HTTPS wake share the same nonblocking worker lock.

Ordinary CLI work keeps its full existing 60-second worker or 25-second cron budget.
After it finishes, A may reserve one wake if about ten seconds plus cleanup time
remain in that same absolute deadline. When ordinary work uses that time, the next
eligible turn is wake-only, then ordinary work resumes. A rotates due connections;
one unavailable peer does not prevent another due peer's turn. Per-connection retry
delays are 10, 20, 40, then at most 60 seconds. Success or busy responses reset the
outage count but still wait at least ten seconds before another attempt. Retry
records contain an attempt marker and binding fingerprint, not plaintext secrets;
changed or disabled bindings are removed during the next background planning pass.
B's admission interval remains ten seconds.

The sender caps HTTP at ten seconds; B's turn uses an eight-second cooperative
deadline. Model calls, database waits and process/tool overhead can exceed soft
operation budgets. There is no two-second convergence guarantee. A wake response
`completed` means a worker turn ended, including turns whose tasks were blocked;
`busy` means another B worker held the lock. Only the existing durable task results
prove asset outcomes. A timeout or lost response does not prove that B did no work;
retain the journals and let the existing acknowledgement/recovery path reconcile
them. Uncertain lock cleanup fences that A process and exits its continuous loop,
requiring a fresh process/connection. Transaction probes never end caller work;
their 128-bit private savepoint names are probabilistically unique.

The supported single-connection native/custom Both path has actual HTTPS evidence.
Three-connection fairness/cost, slow calls, hard process-crash handling, and positive
CREATE qualification remain separate Gate 4 checks; simulated suites are not proof
of those runtime outcomes. Snapshot/Docker-inclusive timings must be reported
separately from HTTP and pipeline latency.

### Native Both (Protocol V6)

Confirmed existing pairs may use Both for the same native Name or Comments key
on A and B. Native/custom cross-mappings, custom Both, creation with Both, UUID
and dropdowns remain unsupported. Existing one-way text, yesno and date mappings
retain their protocol behavior and permission checks.

Native Both was introduced in **B agent 0.5.3 / A controller 0.6.4**. For the current
release use the B-first 0.8.2 / A 0.9.2 procedure above. Both sides must support
the native-only Agent API; no older-controller pull downgrade is attempted. Upgrade A before
enabling Both. A withholds v6 tasks from older B and reports the upgrade requirement.
There is no task downgrade or REST fallback. No new database table or column is
required for Both; older installations still need their existing additive migrations.
Back up privately and preserve outstanding tasks, journals, results and enabled scheduling.

Equal normalized values are no-ops, including blanks, without requiring timestamps.
For different values, a strictly newer reliable native asset instant wins, including
a newer blank. Each instance reads native values and UNIX_TIMESTAMP(date_mod) together
from its own TIMESTAMP column. Equal, missing, invalid or ambiguous instants report
`blocked_field_conflict`; a populated value never wins merely because the other is blank.

V6 pins both observations, metadata, identities and resolved directions before writes.
B prevalidates the entire batch under existing parent/child locks and asserts captured
values and modification evidence even when B wins without a B write. A rechecks its
observation before dispatch and under its finalization lock. No direction is recomputed
after a model or billing write advances date_mod. Post-write native values, epochs and
actual parent History anchors live in the existing task context and journal; unchanged
own mutations inherit the pinned real comparison origin. Later native History can
establish a fresh human edit in the same second. Legacy incomplete provenance stays
ambiguous; neither author names nor guessed timestamps repair it.

A retained read-only v6 inspection conflict may refresh after changed A context or a
new admitted B notification. The old row and evidence remain. It does not repeatedly
inspect an unchanged conflict or replay partial/uncertain writes. V6 apply recovery
requires independent B journal proof and starts a new v6 inspection; v5 creation
remains excluded. Retained v1-v5 bodies, hashes, results and replay schemas are unchanged.

### Automatic Creation (Protocol V5)

Follow the native-only B-first upgrade procedure above. Existing eligible routes authorize automatic creation after complete no-match.
Preserve all retained task/journal bodies, hashes, results and outstanding slots.
Versions 1-4 keep their wire formats and replay behavior.

Only Computer and Printer are supported. Both native identity lookups must be
complete and empty, and every supplied A identifier must be locally unique.
Missing metadata, denied reads, timeouts, duplicates and conflicting identifiers
never authorize creation. A first sends a read-only `prepare_create` task. B reads
fresh native/Fields metadata without requiring an existing parent or child row.
The subsequent `create` task must match that prerequisite fingerprint and repeat
the complete no-match lookup before adding anything. If an existing B asset appears,
A returns to ordinary independent matching; that asset is never seeded.

For a brand-new B asset only, initialization copies **every mapped A value once**,
including mappings whose authority is B. Supported mapped fields are native Name
and Comments and Fields text, yesno and date. No other A asset columns are copied;
the target entity and native serial/inventory identity come from the captured route
and source identity. Conflicting seeds for one B field are rejected. After independent
confirmation, normal mapping authority applies immediately, including B-to-A writes.

B uses its real service login and fresh CREATE/READ/UPDATE, exact nonrecursive entity
grant and container rights. It validates all applicable mandatory definitions and
status overrides, including unsupported required fields, before writing. Supported
unmapped Fields values use validated configured defaults, or blank text/date and
zero yesno when no default exists. Required unsupported values block creation.
Initialization writes complete container values together using the normal
`PluginFieldsContainer::updateFieldsValues(..., false)` and generated child model.
Readonly Fields retain the existing agent policy; profile/container rights and
mandatory checks remain enforced. Normal updates still require existing child rows.

B commits its executing journal marker before mutation. Normal parent `add()`,
custom child initialization, official History and the result containing the created
B ID share one transaction. Parent creation requires `HISTORY_CREATE_ITEM`; custom
creation History is verified for nonempty values. Blank values and zero do not
require nonexistent Fields creation entries. A savepoint detects unexpected model
or plugin commits; these become uncertain rather than inferred success. A returned
model ID is retained as `attempted_id` when available; it does not prove that the
parent survived rollback. On a lost
COMMIT acknowledgement, only a reread durable result proves creation succeeded.
An interrupted executing creation is never run again.

A retains a delivered creation across expiry and accepts its original late durable
result without issuing another add. It then publishes a separate read-only
`confirm_create`, requiring fresh B identity/snapshot and unchanged A source,
mapping, binding and route before committing the link. Mapped A child rows are
locked through confirmation. Creation confirmation uses transaction-local READ
COMMITTED so a child edit committed while waiting for its lock cannot be hidden by
an earlier repeatable-read snapshot. Changed A values leave the created ID retained for
review, without reseeding or recreating. Read-only prerequisite/confirmation tasks
can renew on expiry; a create can renew only when a locked reread proves it was
never delivered. Uncertain operations retain their slots for investigation.

Activity distinguishes **Created B; awaiting confirmation** from confirmed linkage
and completed synchronization. Creation does not bypass the normal inspect/apply,
billing and authority cycle. Do not delete retained uncertain operations to retry.
Host SQLite tests cover protocol and failure paths, but cannot prove InnoDB locking.
Isolated GLPI 11.0.7 / Fields A 1.24.2 and B 1.24.4 / MySQL 8.4 verification covered normal tab/DOM
creation, defaults, History, authority transitions, physical pre/post-commit crashes,
late HTTPS results and concurrent confirmation edits. This does not establish an
atomic claim against external human/inventory writers: a new duplicate after the
lookup must still fail independent confirmation. Arbitrary third-party hook side
effects and MariaDB concurrency were not verified by that lab.

### Automatic Pairing (Protocol V4)

V4 was introduced in **B agent 0.4.0 and A controller 0.5.0**. For current upgrade
versions use the v5 procedure above, with both workers stopped.
Use GLPI's plugin Update action for each version. The new B code must accept
v4 before A resumes its scanner. A's versioned Update runs the additive migration so
`agentpairs.identity_basis` exists before resuming A. Its nullable JSON text column
is the only new storage field; NULL retains legacy serial semantics. Do not rewrite
retained v1-v3 task bodies, hashes, results, journal entries or uncertain work.
Existing manual/confirmed pairs continue their original protocol behavior.
V4-capable B pulls advertise `task_versions: [1,2,3,4]`. A withholds v4 tasks from older
pulls and records a visible upgrade requirement without changing task bytes.
There is no automatic downgrade or REST fallback.

The existing bounded A scanner queues unpaired Computer/Printer assets on exactly
one active, nonrecursive, single-type route with explicit source and target entities.
The matching tasks never create an asset; complete no-match starts the separate v5
creation chain described above. Existing links and manual candidates are preserved.
Hooks and page loads do not start matching or network requests. B supplies native
read-only evidence through the existing task transport; A makes every decision.

Matching uses native `serial` first. A unique serial wins; duplicate serials block.
Only blank serial or a complete zero-result serial lookup permits native inventory
number (`otherserial`) fallback. Duplicate inventory numbers block fallback, as do
contradictory nonblank A/B serials. A identities are checked for duplicates before
assignment, including assets already paired, so worker order cannot choose a winner.
Names, numeric IDs, UUID equality and REST discovery are not matching criteria.

V4 trims ASCII spaces at the ends only and compares exact case-sensitive bytes.
`0`, leading zeros, punctuation and internal whitespace remain significant. Missing
columns, failed reads, invalid controls and values over 255 bytes block; explicit
NULL is blank only when the native column exists. Both sides apply the exact SQL
predicate before the two-row limit. Discovery pagination and permission filtering
cannot hide a duplicate. B requires fresh type rights and an explicit entity grant;
a denied candidate or exhausted read budget leaves that identifier's lookup
incomplete, never zero. Serial and inventory completeness are separate: a valid
unique serial does not depend on a failed or denied inventory lookup.

Each lookup is restricted to the exact entity and active native type, with the
binary identity predicate before `LIMIT 2`. MySQL 8/9 uses the per-statement
`MAX_EXECUTION_TIME` hint; MariaDB 10.6+ uses `SET STATEMENT max_statement_time`.
Each query gets at most one second and shrinks to the remaining two-second lookup
budget. Unsupported database versions fail explicitly. There is no global inventory
row cap or duplicate-query `FOR UPDATE`; session timeout settings are not changed.
Host tests simulate database deadline failures. Actual optimizer/deadline behavior
requires independent MySQL/MariaDB verification.

A publishes a separate read-only reverify task after choosing a candidate. Fresh
A route/binding/entity/identifiers and B lookup plus snapshot must agree before
pair and link confirmation in one transaction. Manual and automatic confirmation
share the binding lock; existing typed unique indexes protect pair ownership.
These locks serialize plugin pair claims, not external native asset edits/inserts:
an external writer can still introduce a new duplicate after the fresh lookup.
New pairs retain separate A/B serial and inventory guards in `identity_basis`.
Ordinary v4 inspect/apply and readback enforce those guards, including blank-serial
inventory pairs. Fingerprints include inventory. Matching errors and expiry use
the existing queue backoff; uncertain applies are never automatically reset.

Agent Pair and Activity show queued matching, evidence received, matched pending
synchronization, and blocked/retrying outcomes, including unpaired assets. Matching
is not synchronization: mapped updates still require the ordinary inspect/apply
cycle with their existing authority, readonly Fields, scalar/date, billing, and
History checks. Host tests use GLPI doubles and SQLite, including case-insensitive
fixture columns; they do not prove live MySQL collation, InnoDB concurrency or scale.

### Installation And Automatic Pairing

Follow the native-only setup/upgrade procedure above. All retained task, pair and
journal data stays intact; ordinary A REST asset connections retain their mode.

New automatic pairs use **version 4** for matching, reverify, inspect and apply,
including separate native identity guards. Existing manual/legacy pairs continue
**version 3**, with immutable pair ID, item type, item ID, exact entity and the
scalar `type` on each change. Versions **1 and 2** retain their original wire and
snapshot shapes. There is no protocol downgrade or REST fallback. Existing task bodies, hashes, saved
results and journal replay are preserved, including expired tasks with durable
results; do not rewrite, delete or reset them during an upgrade. B validates the
original wire hash before checking scalar values. Refresh observation after the
upgrade to expose Yes/No and Date fields in the mapping catalog.

1. Configure an active, exact single-type nonrecursive route on A for each intended
   source/target entity. B discovery on Agent Pair can populate the B entity selector;
   it is read-only and does not require selecting assets or saving manual candidates.
   Run A and B workers. A's scanner automatically queues eligible unpaired assets;
   B supplies exact identity evidence, then a fresh reverify before A confirms them.
   Manual selection/save/confirm is **not required** for automatic matching.
   Complete authorized no-match starts v5 creation using the saved supported mappings,
   followed by independent confirmation. Existing assets are not moved or relinked;
   existing candidates and links remain intact.
2. Map Name, Comments or existing custom **text**, **Yes/No** or **Date** fields.
   Custom endpoint types must match. Each mapping must use
   `glpi_a` or `glpi_b` authority; matching native Name/Comments on confirmed
   existing pairs also support v6 Both. Metadata GET uses the bounded
   stored B observation; explicit refresh queues a fresh inspection without REST.

Automatic creation uses existing saved mappings. The mapping UI still requires an
accessible, scoped B pair observation less than one hour old to populate its field
catalog. An empty, previously unconfigured B has no such catalog: automatic creation
does not provide cold-start mapping setup. Establish and inspect a suitable pair
before configuring mappings; registration alone does not supply field metadata.
   An initial automatic pair provides the stored B metadata when configuring new
   custom mappings. Run A and B workers independently using the commands below;
   confirmed pairs then synchronize according to those mappings.

Agent Pair is also available for optional manual exceptions and inspection of
blocked work. Its existing discovery/cart flow lets an operator choose A and B
independently, save candidates, refresh their observation, and confirm matching
serials on an exact route. Discovery remains paginated (50 rows), and this manual
flow does not infer positional, name or UUID matches. Manual candidates are never
overwritten by automatic matching. Inspect and resolve the visible cause of blocked
work; partial or uncertain apply results still require investigation.

B pulls tasks through the native Agent API and reports
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

Types other than Computer and Printer, recursive/inherited-only grants, custom or
cross-key Both mappings, creation with Both, dropdowns, textarea, number, datetime,
other native fields and UUID mutation are unsupported
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

If initial inspection publication fails, its candidate and binding identifiers
remain available; use Refresh observation when storage is available.
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

Native transport accounts should be separate from B's local service writer.
Give transport accounts only asset/container READ within their exact entity,
without config UPDATE or asset UPDATE/CREATE. B's writer password stays on B;
do not configure its user token on A, because that token also grants its ordinary
GLPI REST permissions. A's selected entity is the initial session context. Each
disclosed task or subscription requires a fresh direct, nonrecursive profile grant
on its actual A source entity, asset READ and mapped Fields READ. Connections can
retain routes across multiple directly granted entities. Revoking one route's
read scope withholds its work; health and durable result receipts remain available.
Native task admission examines at most 50 candidates per pull. Fifty denied
candidates can hide an authorized later candidate; this bounded partial-permission
case is not scale-qualified. The local prototype verifies two directly granted
routes, not large mixed-permission queues. Disabled connections retain pending
queue and task evidence: ordinary claim/recovery selection and asset-save hooks
skip them before mutation; orphaned queue identities still block normally.

The plugin endpoint issues a native core-authenticated session marked for its
configured connection. A session created through stock `apirest.php/initSession`
does not have that marker and is deliberately rejected by plugin operations.

## Worker Operation

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

Each registration binds an enabled connection identity and approved route scope.
Native GLPI account/API-client permissions are rechecked for admission. Revocation
prevents new admission; retained tasks and generations are never reset by upgrade.

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

Reconciliation is restricted to A-issued typed subscriptions and uses a silent
baseline. Concurrent hook revisions survive baseline/ACK races. Native/custom
changes are fingerprinted under fresh service permissions; there is no unrestricted
inventory scanner. No automatic pruning or delete/entity-move propagation is added.
Retain unresolved journals, watermarks and task bindings across upgrades.

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
Registration returns identifiers only; native GLPI secrets are never displayed.
Separate health shows no heartbeat, reporting/reconciliation OK, degraded
reconciliation, stale heartbeat after 180 seconds, or revoked. Stale means contact
is overdue, not proof that B is offline. Health ACKs use `kind: health` and
`recorded`, never asset `accepted`/revision ACKs.

Offline checks:

```sh
php tests/agent-prototype.php
php tests/agent-http.php
php tests/agent-auto-create.php
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

### Controller 0.6.1 queue deadlines

When queueing has shortened its execution window, an undelivered, accepted task
with no result receives a new task ID and a full 300-second window on its first
pull. The previous body, hash and result remain intact. Delivered tasks keep
their original ID and deadline; lost replies and uncertain writes do not
authorize another execution.

A records the first result receipt in nullable `result_received_at`. Timely
results can be consumed after the execution deadline while their observations
are no more than 300 seconds old and current scope/source checks still pass.
Historical rows remain null; the upgrade does not invent receipt times or reset
retained stale/uncertain operations. Durable committed creation evidence remains
available for confirmation without another create.

Upgrade A only; B's protocol and worker are unchanged. Add the nullable column
before loading the new controller, coordinate with the existing database run
lock and in-flight HTTP requests, and refresh the serving PHP workers after
atomic file replacement. Preserve the existing enabled schedule, frequency,
mode and batch size. Keep backups and verify old task evidence and deployed
hashes. A code rollback should retain the additive column and task history.

### Controller 0.6.2 / Agent 0.5.1 recovery and cadence

Keep the existing enabled GLPI automatic action, frequency and batch size. The
database run lock prevents the A loop and automatic action from overlapping.
A's existing worker interval accepts 2-3600 seconds (default 10). B's loop accepts
`--interval=1` through `--interval=3600` (default 15), measured between cycle starts.
For the owned localhost fixture, use A's saved 2-second interval and B's
`plugins:assetsync20agent:worker --loop --interval=1`. Deployment and local interval
changes are separate operational steps; installing source does not change them.

B drains at most ten task reports/executions/recovery probes in a soft five-second
budget, reserving the rest of the existing twenty-second cycle for other work.
Each result is acknowledged before the next pull. Empty pulls, failed transport
and exhausted deadlines stop the drain. An execution in progress still resolves
its durable outcome before returning. A reserved probe and rotating offers keep
ordinary traffic or refused journal evidence from pinning legacy recovery.

When every desired B value strictly equals its validated fresh inspection, A
does not publish a no-op B apply. It uses the same guarded local finalization,
permissions, row locks, billing checks and readback instead. Any real B change
still requires apply. This remains an asynchronous observation, not a guarantee
that B cannot change again after inspection.

Normal A runs consider at most ten retained stale inspections within a one-second
budget. They release only read-only inspect slots and request fresh inspection.
Already-done legacy queues with missing remote IDs/routes or unsynchronized links
also require a fresh inspect/apply chain. Old completion is not new verification.
Matching stores queue remote ID/route with pair confirmation. Apply completion
stores the synced link, verification time and payload hash with queue remote ID,
route and current claim. New notifications remain outstanding.

Legacy stale apply slots use a separate authenticated recovery envelope that B
never executes. Under its existing exclusive worker lock, B validates the original
immutable task, current binding and exact typed target, then reads its journal.
An expired absent record or an exact terminal unchanged/stale record with no
changed fields can authorize a new inspection. A terminal verified journal also
permits fresh inspection when its complete typed readback and named History are
validated and A stopped before local finalization. Terminal proof need not wait
for expiry; an absent journal always must. Failed reads, nonexpired absent tasks,
hash/body conflicts, executing journals, interrupted A finalization,
partial/uncertain outcomes and creation
tasks remain retained. Absence authorizes fresh inspection; it does not certify
a historical outcome. Old bodies, hashes, results and null receipt times stay
intact; proof is stored separately in context. The journal has no pruning path.
After restoring or deleting journal data, re-establish installation identity
before using absence recovery.

Native-only deployments advertise recovery_version: 1. Follow the B-first upgrade
procedure at the top; preserve all retained task/journal evidence and coordinate
worker resumption after both supported plugin updates complete.
