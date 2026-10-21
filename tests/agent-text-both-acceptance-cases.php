<?php

declare(strict_types=1);

// Acceptance data only. Names below select endpoint families, not future wire keys.
$endpoints = [
    'name' => ['family' => 'N', 'type' => 'text', 'native_key' => 'name'],
    'comments' => ['family' => 'N', 'type' => 'textarea', 'native_key' => 'comment'],
    'fields-text' => ['family' => 'C', 'type' => 'text', 'native_key' => null],
    'fields-textarea' => ['family' => 'C', 'type' => 'textarea', 'native_key' => null],
];
$mappings = [];
foreach ($endpoints as $a => $left) {
    foreach ($endpoints as $b => $right) {
        $family = $left['family'] . '/' . $right['family'];
        $layouts = $family === 'C/C' ? [
            ['same', 'same', 'same'],
            ['different', 'same', 'different'],
            ['different', 'different', 'same'],
            ['different', 'different', 'different'],
        ] : [[
            $family === 'N/N' && $a === $b ? 'same' : 'different',
            $family === 'N/N' ? 'same' : 'different',
            $family === 'N/N' && $a === $b ? 'same' : 'different',
        ]];
        foreach ($layouts as [$keys, $classes, $columns]) {
            $mappings[] = [
                'id' => $a . '--' . $b . '--' . $keys . '-' . $classes . '-' . $columns,
                'family' => $family, 'a' => $a, 'b' => $b,
                'keys' => $keys, 'classes' => $classes, 'columns' => $columns,
                'containers' => $family === 'C/C' ? $classes : 'not-applicable',
                'metadata_ids' => 'resolve-independently-on-each-side',
                'runtime' => 'pending',
            ];
        }
    }
}

$values = [
    'ordinary' => 'Owned fixture value',
    'blank' => '',
    'zero-string' => '0',
    'whitespace' => " \t  ",
    'multiline-lf' => "First line\nSecond line",
    'multiline-crlf' => "First line\r\nSecond line",
];

// These are controlled-clock experiment bounds, never observations of a live asset.
$observation = static function (string $value, string $side, ?array $bounds, string $request): array {
    return ['value' => $value, 'origin' => $bounds === null ? null : [
        'event' => $request . ':original-edit', 'side' => $side,
        'request' => $request, 'bounds' => $bounds,
    ]];
};
$scenarios = [];
foreach (['A', 'B'] as $winner) {
    foreach ($values as $label => $value) {
        $a = $observation($winner === 'A' ? $value : 'Older A', 'A', $winner === 'A' ? [120, 130] : [100, 110], 'human-A');
        $b = $observation($winner === 'B' ? $value : 'Older B', 'B', $winner === 'B' ? [120, 130] : [100, 110], 'human-B');
        $scenarios[] = [
            'id' => 'newer-' . strtolower($winner) . '-' . $label, 'category' => 'genuine-winner',
            'a' => $a, 'b' => $b, 'requests' => ['human-A', 'human-B', 'sync-1', 'sync-2'],
            'schedule' => ['Separate authenticated human requests establish the recorded bounds.',
                'Ordinary worker observes both fields; perform a second unchanged sync.'],
            'expected' => ['winner' => $winner, 'writes' => ['A' => $winner === 'B' ? 1 : 0, 'B' => $winner === 'A' ? 1 : 0],
                'history_delta' => ['A' => $winner === 'B' ? 1 : 0, 'B' => $winner === 'A' ? 1 : 0],
                'final_value' => $value, 'origin_from' => $winner, 'repeat_writes' => 0],
        ];
    }
}
foreach ($values + ['null-and-empty' => null] as $label => $value) {
    $scenarios[] = [
        'id' => 'equal-' . $label, 'category' => 'equal',
        'a' => ['value' => $value, 'origin' => null],
        'b' => ['value' => $value ?? '', 'origin' => null],
        'requests' => ['sync-1', 'sync-2'],
        'schedule' => ['Capture History and origin snapshots, then run two independent unchanged sync requests.'],
        'expected' => ['winner' => 'equal', 'writes' => ['A' => 0, 'B' => 0],
            'history_delta' => ['A' => 0, 'B' => 0], 'preserve_each_origin' => true],
    ];
}
foreach ([
    'missing-a' => [null, [100, 110]],
    'missing-b' => [[120, 130], null],
    'missing-both' => [null, null],
    'equal-bounds' => [[120, 130], [120, 130]],
    'touching-bounds' => [[100, 120], [120, 130]],
    'overlap' => [[100, 125], [120, 130]],
    'nested-bounds' => [[100, 140], [120, 130]],
] as $label => [$aBounds, $bBounds]) {
    $scenarios[] = [
        'id' => 'conflict-' . $label, 'category' => 'evidence-conflict',
        'a' => $observation('Different A', 'A', $aBounds, 'human-A'),
        'b' => $observation('Different B', 'B', $bBounds, 'human-B'),
        'requests' => ['human-A', 'human-B', 'sync-1'],
        'schedule' => ['Use controlled independent requests/locks to establish the specified evidence, then sync.'],
        'expected' => ['winner' => 'conflict', 'writes' => ['A' => 0, 'B' => 0],
            'history_delta' => ['A' => 0, 'B' => 0], 'visible_field_conflict' => true],
    ];
}

// Each row is applied to every mapping. Directional hazards run with A and B as destination.
$hazards = [
    ['clock-unproven', 'evidence', 'Clock domains are unproven or a clock moved backwards.', 'visible-field-conflict'],
    ['sync-echo', 'origin', 'Copy a genuine winner, then observe the copy in a later independent request.', 'preserve-original-event-and-bounds'],
    ['inherited-origin', 'origin', 'Sync within an enclosing unknown-origin scope and caller-owned transaction.', 'preserve-original-event-and-bounds'],
    ['unrelated-edit', 'origin', 'Change an unmapped sibling/asset modification date after the mapped edit.', 'preserve-mapped-origin'],
    ['service-edit', 'origin', 'Change the field from an unscoped service/CLI request, without a trusted inherited origin.', 'unknown-origin-no-minted-time'],
    ['equal-existing-origins', 'origin', 'Equal values have distinct valid original events; repeat sync.', 'no-write-no-origin-copy'],
    ['source-stale-unsent', 'race', 'Prepare work, then genuinely edit the source in a separate request before send.', 'zero-peer-calls-retain-immutable-work'],
    ['source-aba-unsent', 'race', 'Prepare work, then edit source X to Y to X using two separate human requests.', 'zero-peer-calls-retain-immutable-work'],
    ['source-aba-sent', 'race', 'Send work, lose the reply, then edit source X to Y to X.', 'observation-only-no-old-mutation-replay'],
    ['destination-stale', 'race', 'Edit the destination after inspection but before its guarded model update.', 'no-stale-overwrite'],
    ['destination-aba', 'race', 'Change destination X to Y to X after inspection in independent requests.', 'reject-stale-event-even-if-value-equal'],
    ['overlapping-saves', 'race', 'Use independent sessions and declared row locks to overlap genuine mapped saves.', 'unknown-or-conflict-until-clean-edit'],
    ['fresh-read-acl', 'permissions', 'Revoke READ after preparation before execution, then restore it.', 'no-write-no-private-disclosure'],
    ['fresh-update-acl', 'permissions', 'Revoke UPDATE after preparation before execution, then restore it.', 'no-write'],
    ['fresh-container-acl', 'permissions', 'Revoke destination container rights after preparation.', 'no-write'],
    ['readonly', 'permissions', 'Set native/custom destination read-only after preparation.', 'no-write'],
    ['inactive-account', 'permissions', 'Deactivate the service account after preparation.', 'no-write'],
    ['entity-scope', 'permissions', 'Move the fixture outside the exact permitted entity after preparation.', 'no-write'],
    ['mandatory-blank', 'mandatory', 'Try a newer blank against an actually mandatory destination field.', 'visible-rejection-no-write'],
    ['mandatory-zero', 'mandatory', 'Use the newer genuine string zero on a mandatory text destination; do not treat it as empty.', 'preserve-literal-zero-if-permitted'],
    ['mandatory-sibling', 'mandatory', 'A mandatory unmapped sibling is missing during a mapped custom group update.', 'atomic-group-rejection-no-partial-write'],
    ['preserve-siblings', 'mandatory', 'Update one mapped member of an existing custom child containing unmapped siblings.', 'preserve-siblings-and-parent-history'],
    ['missing-child', 'child-row', 'The selected custom destination has no existing child row.', 'visible-rejection-no-implicit-add'],
    ['duplicate-child', 'child-row', 'Two child rows match the selected custom parent/container.', 'visible-rejection-no-arbitrary-row'],
    ['wrong-child-identity', 'child-row', 'Child itemtype/container/parent identity no longer matches discovery.', 'no-write'],
    ['metadata-changed', 'metadata', 'Change type, generated class, definition, container or search reference after preparation.', 'no-write-refresh-required'],
    ['metadata-ids-differ', 'metadata', 'Use independently allocated field/container/search-option IDs on A and B.', 'resolve-both-sides-independently'],
    ['metadata-ambiguous', 'metadata', 'Selected metadata is missing, inactive or ambiguous on either side.', 'visible-rejection-no-guess'],
    ['retry-before-send', 'recovery', 'Retry identical durable work without any previously admitted model attempt.', 'at-most-one-model-write'],
    ['lost-post-reply', 'recovery', 'Discard the actual successful reply after model commit and retry immutable work.', 'at-most-one-model-write'],
    ['lost-get-reply', 'recovery', 'Discard an observation reply, then observe again.', 'observation-only-no-mutation'],
    ['crash-before-model', 'recovery', 'Crash after durable intent exists but before a provable model result.', 'uncertain-no-replay'],
    ['crash-before-commit', 'recovery', 'Interrupt the model transaction before commit; retain orphan intent.', 'uncertain-no-replay'],
    ['crash-after-commit', 'recovery', 'Crash after model commit but before origin/result qualification.', 'metadata-recovery-only-no-repeat-history'],
    ['crash-local-finalize', 'recovery', 'Interrupt A finalization after B result; use exact committed receipts later.', 'no-premature-completion-no-replay'],
    ['uncertain', 'recovery', 'Intent or receipt cannot prove whether mutation committed.', 'hold-visible-no-replay'],
    ['readback-mismatch', 'recovery', 'Model return is successful but exact typed readback/origin/History disagrees.', 'no-false-completion'],
    ['atomic-custom-group', 'atomicity', 'One mapped sibling fails within the single custom model group.', 'all-or-nothing-group'],
    ['all-before-sql', 'atomicity', 'Registration fails on a later member before any group model SQL.', 'zero-model-sql-for-entire-group'],
    ['native-custom-boundary', 'atomicity', 'A task spans native and custom model boundaries and a later group fails.', 'contract-required-no-invented-cross-model-atomicity'],
    ['body-response-limits', 'limits', 'Use exact boundary and one-over native/custom values, escaped/Unicode text and grouped evidence.', 'contract-required-reject-before-sql-no-truncation'],
    ['old-wire', 'compatibility', 'Replay retained supported old requests against their original decoder with exact stored bytes/hashes/results.', 'unchanged-old-contract-no-silent-fallback'],
    ['unsupported-peer', 'compatibility', 'Peer lacks the later frozen text Both capability.', 'visible-rejection-no-legacy-fallback'],
    ['first-copy', 'routing', 'Use an unpaired asset before normal Both authority applies.', 'preserve-diagram-first-copy-rule'],
    ['fanout-ownership', 'routing', 'A fixture belongs to multiple connections/routes with independent owners.', 'preserve-ownership-and-isolation'],
];
foreach ($hazards as [$id, $category, $schedule, $outcome]) {
    $scenarios[] = [
        'id' => $id, 'category' => $category, 'directions' => ['A', 'B'],
        'requests' => ['independent-prepare', 'independent-interference', 'independent-execute', 'independent-observe'],
        'schedule' => [$schedule], 'expected' => ['invariant' => $outcome],
        'applicability' => in_array($id, ['fresh-container-acl', 'mandatory-sibling', 'preserve-siblings',
            'missing-child', 'duplicate-child', 'wrong-child-identity', 'atomic-custom-group'], true)
            ? 'destination-custom' : 'all',
    ];
}

return [
    'scope' => 'Computer native Name/Comments and official Fields text/textarea acceptance preparation only',
    'runtime_status' => 'pending-not-executed',
    'clock_rule' => 'Use genuine per-field original intervals from independent requests; strict disjoint bounds only. No baseline, asset date_mod, guessed time or side priority.',
    'clock_fixture' => 'Numeric vector bounds are coordinates only. D records actual genuine request bounds with declared lock scheduling; never rewrite clocks, History or capture evidence to match vectors.',
    'endpoints' => $endpoints, 'mappings' => $mappings, 'values' => $values, 'scenarios' => $scenarios,
    'normalization' => [
        ['id' => 'null', 'input' => null, 'expected' => ''],
        ['id' => 'empty', 'input' => '', 'expected' => ''],
        ['id' => 'zero', 'input' => '0', 'expected' => '0'],
        ['id' => 'spaces', 'input' => '  Name  ', 'expected' => '  Name  '],
        ['id' => 'tabs', 'input' => " \t ", 'expected' => " \t "],
        ['id' => 'lf', 'input' => "A\nB", 'expected' => "A\nB"],
        ['id' => 'crlf', 'input' => "A\r\nB", 'expected' => "A\r\nB"],
        ['id' => 'escaped', 'input' => "\"A\\B\"", 'expected' => "\"A\\B\""],
        ['id' => 'unicode', 'input' => "\u{00e9}\u{1f600}", 'expected' => "\u{00e9}\u{1f600}"],
    ],
    'runtime_oracle' => [
        'Before/after: exact mapped values, typed full hashes, metadata identities, original event/bounds, capture guards and parent/field History deltas.',
        'History deltas exclude fixture seeding and genuine user interference; a simple changed field adds one normal destination History row, repeat sync adds zero.',
        'Use fresh authenticated browser requests for human origins and normal worker execution for sync. Keep model/SQL doubles distinct from native evidence.',
        'Snapshot owned assets, siblings, protected old tasks/bodies/hashes/results and routes before mutation; retain failed receipts and restore permissions via D.',
    ],
    'gates' => [
        'D independently passes stale Work11 recovery and routing before any A/B implementation assignment.',
        'Planner freezes field identities, normalization/admission, clock comparability, atomic groups, all-before-SQL registration, size/batch budgets, result/History and recovery contract.',
        'Future contract must pin one atomic custom group, its membership/limits, and registration of all selected origins before any model SQL; these fixtures implement none of that protocol.',
        'New separate implementation worktrees start from the same latest verified baseline; this worktree never implements a new wire version.',
        'D alone owns Docker, databases, workers, fixtures and runtime. Only assetsync-wave1-demo-20261017 is eligible; internal HTTPS must verify TLS in both directions with no insecure fallback.',
        'Runtime inventory must confirm GLPI 11.0.7 and official Fields 1.24.5. A currently has no Fields: A-custom rows are blocked, not skipped or passed; no install is authorized.',
        'D discovers A/B keys, classes, containers, IDs, exact scope, ACL and existing child rows independently. Native/custom full keys are inherently different.',
        'Text vectors do not define wire types or sizes. Native Name existing 255-character limit is component coverage only; larger Comments/Fields and group/body/response limits await the frozen contract.',
        'D executes every applicable matrix/scenario/destination combination with retained independent evidence. Equality is a no-op, never a sync-origin copy.',
        'D runs old wire, routing/ownership, billing, UUID, authority, capture and transport regressions on the final frozen candidate.',
        'Native references, dropdowns, linked objects, other scalars and asset types remain later coverage, not implied by this text slice.',
    ],
];
