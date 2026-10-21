<?php

declare(strict_types=1);

require __DIR__ . '/agent-text-both-contract.php';
$checks = 0;
$layout = ['classes' => 'different', 'columns' => 'different'];
$rows = [];
foreach (['a', 'a', 'b', 'b', 'equal', 'conflict', 'a', 'b'] as $i => $winner) {
    $a = textContractPin($plan['endpoints']['fields-text'], $layout, 'a');
    $b = textContractPin($plan['endpoints']['fields-textarea'], $layout, 'b');
    foreach (['a', 'b'] as $side) {
        $pin = &${$side};
        $pin['definition_id'] += $i;
        $pin['capture_key'] = 'fields:' . $pin['definition_id'];
        $pin['column'] .= $i;
        $pin['key'] = 'Computer.' . $pin['class'] . '.' . $pin['column'];
        $pin['definition_hash'] = textContractHash([$pin['key'], $pin['definition_id']]);
        unset($pin);
    }
    $m = ['mapping_id' => substr(textContractHash(['text-row', $a, $b]), 0, 32), 'source_of_truth' => 'both', 'a' => $a, 'b' => $b];
    $ae = textContractEnvelope(['value' => $winner === 'equal' || $i === 1 ? '' : ($i === 0 ? null : 'A-' . $i),
        'origin' => ['event' => 'a-' . $i, 'bounds' => $winner === 'a' ? [30, 31] : [10, 11]]], $a, 'a');
    $be = textContractEnvelope(['value' => $winner === 'equal' || $i === 2 ? null : ($i === 3 ? '' : 'B-' . $i),
        'origin' => $winner === 'conflict' ? null : ['event' => 'b-' . $i, 'bounds' => [20, 21]]], $b, 'b');
    $rows[] = ['mapping' => $m, 'a' => $ae, 'b' => $be, 'winner' => $winner];
}
usort($rows, static fn ($a, $b) => strcmp($a['mapping']['mapping_id'], $b['mapping']['mapping_id']));
$policy = ['mappings' => array_column($rows, 'mapping')];
$work = ['id' => md5('multi-work'), 'sources' => array_map(static fn ($row) => ['mapping_id' => $row['mapping']['mapping_id'], 'envelope' => $row['a']], $rows),
    'source_hash' => '', 'expires' => 1700000300];
$work['source_hash'] = textContractHash($work['sources']);
$fields = []; $groups = [];
foreach ($rows as $row) {
    $winner = $row['winner']; $m = $row['mapping']; $gid = null;
    $outcome = ['a' => 'pending', 'b' => 'offered', 'equal' => 'unchanged', 'conflict' => 'conflict'][$winner];
    if (in_array($winner, ['a', 'b'], true)) {
        $side = $winner === 'a' ? 'b' : 'a'; $pin = $m[$side];
        $gid = substr(textContractHash(['text-group', $work['id'], $side, $pin['kind'], $pin['container_id']]), 0, 32);
        $groups[$gid] ??= ['id' => $gid, 'side' => $side, 'kind' => $pin['kind'], 'container_id' => $pin['container_id'],
            'members' => [], 'phase' => $outcome, 'mutation' => $winner === 'a' ? 'unstarted' : 'none',
            'reason' => null, 'started_at' => null, 'model_ended_at' => null];
        $groups[$gid]['members'][] = $m['mapping_id'];
    }
    $fields[] = ['mapping_id' => $m['mapping_id'], 'source_hash' => textContractHash($row['a']), 'winner' => $winner,
        'outcome' => $outcome, 'mutation' => $winner === 'a' ? 'unstarted' : 'none', 'before' => $row['b'],
        'after' => $winner === 'a' ? null : $row['b'], 'group_id' => $gid, 'reason' => null];
}
ksort($groups, SORT_STRING);
$initial = ['revision' => 1, 'source_hash' => $work['source_hash'], 'b_complete' => false, 'fields' => $fields, 'groups' => array_values($groups)];
$complete = $initial; $complete['revision'] = 4; $complete['b_complete'] = true;
foreach ($complete['groups'] as &$group) {
    if ($group['side'] === 'a') { continue; }
    $group['phase'] = 'done'; $group['mutation'] = 'committed'; $group['started_at'] = 1700000001; $group['model_ended_at'] = 1700000002;
}
unset($group);
foreach ($complete['fields'] as $i => &$field) {
    if ($field['winner'] !== 'a') { continue; }
    $field['outcome'] = 'verified'; $field['mutation'] = 'committed'; $field['after'] = $field['before'];
    $field['after']['value'] = $rows[$i]['a']['value'] ?? '';
    $field['after']['hash'] = textContractHash(['type' => 'text', 'value' => $field['after']['value']]);
    $field['after']['origin'] = $rows[$i]['a']['origin'];
    $field['after']['guard']['generation']++;
    $field['after']['guard']['head'] = textContractHash('verified-head');
}
unset($field);
$observation = ['revision' => $complete['revision'], 'receipt_hash' => textContractHash($complete),
    'fields' => array_map(static fn ($field) => ['mapping_id' => $field['mapping_id'], 'receipt_hash' => textContractHash($field), 'current_matches' => true], $complete['fields'])];

foreach ($classes as $class) {
    $class::validateResult($work, $policy, $initial);
    $class::validateResult($work, $policy, $complete);
    $class::validateProgress($work, $policy, $initial, $complete);
    $class::validateProgress($work, $policy, $complete, $complete);
    $class::validateProgress($work, $policy, [], $complete);
    foreach ([2, 3, 5, 25] as $revision) {
        $bad = $complete; $bad['revision'] = $revision;
        textContractReject(static fn () => $class::validateProgress($work, $policy, $initial, $bad), 'One pending-to-done group requires exactly three transitions, not revision ' . $revision);
    }
    $class::validateReadObservation($complete, $observation);
    textContractCheck(true, 'A/B accept the same complete mixed-winner, blank/no-op and independent-conflict plan.');
    textContractCheck(count($complete['fields']) === 8 && count($complete['groups'][0]['members']) === 3
        && count($complete['groups'][1]['members']) === 3, 'Eight mappings retain both three-field atomic groups and two non-writing facts.');
    foreach (['missing_field', 'missing_group', 'false_complete', 'winner', 'source_hash', 'foreign_origin', 'aggregate_outcome'] as $fault) {
        $bad = $fault === 'false_complete' ? $initial : $complete;
        if ($fault === 'missing_field') { array_pop($bad['fields']); }
        if ($fault === 'missing_group') { array_pop($bad['groups']); }
        if ($fault === 'false_complete') { $bad['b_complete'] = true; }
        if ($fault === 'aggregate_outcome') { $bad['outcome'] = 'verified'; }
        foreach ($bad['fields'] as &$field) {
            if ($field['winner'] !== 'a') { continue; }
            if ($fault === 'winner') { $field['winner'] = 'b'; }
            if ($fault === 'source_hash') { $field['source_hash'] = str_repeat('0', 64); }
            if ($fault === 'foreign_origin') { $field['after']['origin']['identity'] = md5('unrelated') . ':' . md5('unrelated'); }
            break;
        }
        unset($field);
        textContractReject(static fn () => $class::validateResult($work, $policy, $bad), 'Reject ' . $fault . ' without trusting an aggregate completion claim.');
    }
    $bad = $complete; $bad['revision']++;
    textContractReject(static fn () => $class::validateProgress($work, $policy, $complete, $bad), 'A read-only poll cannot advance public progress.');
    $bad = $complete; $bad['fields'][0]['before']['guard']['generation']++;
    textContractReject(static fn () => $class::validateProgress($work, $policy, $complete, $bad), 'Same revision cannot rewrite historical before evidence.');
    textContractReject(static fn () => $class::validateProgress($work, $policy, $complete, $initial), 'Completed groups cannot regress or become executable on retry.');
    foreach ([0, 26] as $revision) {
        $bad = $complete; $bad['revision'] = $revision;
        textContractReject(static fn () => $class::validateResult($work, $policy, $bad), 'Reject invalid public revision outside the finite transition bound.');
    }
    $originPending = $complete; $originPending['revision'] = 3; $originPending['b_complete'] = false;
    foreach ($originPending['groups'] as &$group) {
        if ($group['side'] === 'b') { $group['phase'] = 'origin_pending'; }
    }
    unset($group);
    foreach ($originPending['fields'] as &$field) {
        if ($field['winner'] === 'a') { $field['outcome'] = 'origin_pending'; $field['after'] = null; }
    }
    unset($field);
    $class::validateProgress($work, $policy, $initial, $originPending);
    $blocked = $initial; $blocked['revision'] = 2; $blocked['b_complete'] = true;
    foreach ($blocked['groups'] as &$group) {
        if ($group['side'] === 'b') { $group['phase'] = 'blocked'; $group['mutation'] = 'none'; $group['reason'] = 'source_superseded'; }
    } unset($group);
    foreach ($blocked['fields'] as &$field) {
        if ($field['winner'] === 'a') { $field['outcome'] = 'blocked'; $field['mutation'] = 'none'; $field['reason'] = 'source_superseded'; $field['after'] = $field['before']; }
    } unset($field);
    $class::validateProgress($work, $policy, $initial, $blocked);
    textContractCheck(true, 'Never-started pending-to-blocked uses exactly one public transition.');
    $bad = $blocked; $bad['revision'] = 4;
    textContractReject(static fn () => $class::validateProgress($work, $policy, $initial, $bad), 'Never-started pending-to-blocked cannot inflate revision1-to4.');
    $startedBlocked = $blocked; $startedBlocked['revision'] = 3;
    foreach ($startedBlocked['groups'] as &$group) { if ($group['side'] === 'b') { $group['started_at'] = 1700000001; } } unset($group);
    $class::validateProgress($work, $policy, $initial, $startedBlocked);
    textContractCheck(true, 'Started pending-to-blocked accounts for intent plus the known rollback transition.');
    foreach ([2,4] as $revision) {
        $bad = $startedBlocked; $bad['revision'] = $revision;
        textContractReject(static fn () => $class::validateProgress($work, $policy, $initial, $bad), 'Started blocked marker requires exactly two transitions, not revision ' . $revision);
    }
    $intent = $originPending; $intent['revision'] = 2;
    foreach ($intent['groups'] as &$group) {
        if ($group['side'] === 'b') { $group['phase'] = 'intent'; $group['mutation'] = 'unknown'; $group['model_ended_at'] = null; }
    } unset($group);
    foreach ($intent['fields'] as &$field) {
        if ($field['winner'] === 'a') { $field['outcome'] = 'intent'; $field['mutation'] = 'unknown'; }
    } unset($field);
    $class::validateProgress($work, $policy, $intent, $complete);
    textContractCheck(true, 'Intent-to-done uses exactly two remaining public transitions.');
    $bad = $complete; $bad['revision'] = 5;
    textContractReject(static fn () => $class::validateProgress($work, $policy, $intent, $bad), 'Intent-to-done cannot count three more public transitions.');
    $uncertain = $originPending; $uncertain['revision'] = 4;
    foreach ($uncertain['groups'] as &$group) {
        if ($group['side'] === 'b') { $group['phase'] = 'uncertain'; $group['mutation'] = 'unknown'; $group['reason'] = 'commit_unknown'; }
    }
    unset($group);
    foreach ($uncertain['fields'] as &$field) {
        if ($field['winner'] === 'a') { $field['outcome'] = 'uncertain'; $field['mutation'] = 'unknown'; $field['reason'] = 'commit_unknown'; }
    }
    unset($field);
    $class::validateResult($work, $policy, $uncertain);
    foreach (['pending_none', 'pending_timing', 'pending_reason', 'pending_after', 'intent_no_start', 'intent_after',
        'origin_pending_no_end', 'origin_pending_after', 'done_no_start', 'done_reason', 'field_reason', 'blocked_no_reason', 'blocked_after', 'uncertain_no_start'] as $fault) {
        $bad = str_starts_with($fault, 'done') || $fault === 'field_reason' ? $complete : (str_starts_with($fault, 'origin_pending') ? $originPending : $initial);
        foreach ($bad['groups'] as &$group) {
            if ($group['side'] !== 'b') { continue; }
            if ($fault === 'pending_none') { $group['mutation'] = 'none'; }
            if ($fault === 'pending_timing') { $group['started_at'] = 1700000001; $group['model_ended_at'] = 1700000002; }
            if (in_array($fault, ['pending_reason','done_reason'], true)) { $group['reason'] = 'unexpected'; }
            if (str_starts_with($fault, 'intent')) { $group['phase'] = 'intent'; $group['mutation'] = 'unknown'; $group['started_at'] = $fault === 'intent_no_start' ? null : 1700000001; }
            if ($fault === 'origin_pending_no_end') { $group['model_ended_at'] = null; }
            if ($fault === 'done_no_start') { $group['started_at'] = null; }
            if (str_starts_with($fault, 'blocked')) { $group['phase'] = 'blocked'; $group['mutation'] = 'none'; $group['reason'] = $fault === 'blocked_no_reason' ? null : 'destination_validation'; }
            if ($fault === 'uncertain_no_start') { $group['phase'] = 'uncertain'; $group['mutation'] = 'unknown'; $group['reason'] = 'commit_unknown'; }
        }
        unset($group);
        foreach ($bad['fields'] as &$field) {
            if ($field['winner'] !== 'a') { continue; }
            if ($fault === 'pending_none') { $field['mutation'] = 'none'; }
            if (in_array($fault, ['pending_reason','done_reason','field_reason'], true)) { $field['reason'] = 'unexpected'; }
            if (in_array($fault, ['pending_after','intent_after','origin_pending_after'], true)) { $field['after'] = $field['before']; }
            if (str_starts_with($fault, 'intent')) { $field['outcome'] = 'intent'; $field['mutation'] = 'unknown'; }
            if (str_starts_with($fault, 'blocked')) { $field['outcome'] = 'blocked'; $field['mutation'] = 'none'; $field['reason'] = $fault === 'blocked_no_reason' ? null : 'destination_validation'; $field['after'] = $fault === 'blocked_after' ? null : $field['before']; }
            if ($fault === 'uncertain_no_start') { $field['outcome'] = 'uncertain'; $field['mutation'] = 'unknown'; $field['reason'] = 'commit_unknown'; }
        }
        unset($field);
        if (str_starts_with($fault, 'blocked')) { $bad['b_complete'] = true; }
        textContractReject(static fn () => $class::validateResult($work, $policy, $bad), 'A/B both reject phase/mutation/timing/reason/after inconsistency: ' . $fault);
    }
    $changedTiming = $originPending; $changedTiming['revision']++;
    foreach ($changedTiming['groups'] as &$group) { if ($group['side'] === 'b') { $group['started_at']++; $group['model_ended_at']++; } } unset($group);
    textContractReject(static fn () => $class::validateProgress($work, $policy, $originPending, $changedTiming), 'Same-phase timing changes cannot explain a new revision.');
    textContractReject(static fn () => $class::validateProgress($work, $policy, $originPending, $uncertain), 'A committed origin-pending group cannot be relabeled uncertain.');
    $uncertainObs = ['revision' => $uncertain['revision'], 'receipt_hash' => textContractHash($uncertain),
        'fields' => array_map(static fn ($field) => ['mapping_id' => $field['mapping_id'], 'receipt_hash' => textContractHash($field),
            'current_matches' => $field['outcome'] === 'uncertain' ? null : true], $uncertain['fields'])];
    $class::validateReadObservation($uncertain, $uncertainObs);
    foreach ($uncertainObs['fields'] as $i => &$field) {
        if ($uncertain['fields'][$i]['outcome'] === 'uncertain') { $field['current_matches'] = true; break; }
    }
    unset($field);
    textContractReject(static fn () => $class::validateReadObservation($uncertain, $uncertainObs), 'Immutable uncertain receipts never claim currently verified values.');
    foreach (['missing', 'wrong_revision', 'wrong_receipt', 'wrong_member'] as $fault) {
        $bad = $observation;
        if ($fault === 'missing') { array_pop($bad['fields']); }
        if ($fault === 'wrong_revision') { $bad['revision']++; }
        if ($fault === 'wrong_receipt') { $bad['receipt_hash'] = str_repeat('0', 64); }
        if ($fault === 'wrong_member') { $bad['fields'][0]['mapping_id'] = md5('other'); }
        textContractReject(static fn () => $class::validateReadObservation($complete, $bad), 'Reject incomplete or stale batched observation: ' . $fault);
    }
}
echo "Text Both multi progress: $checks checks PASS (pure contract only; not runtime mutation evidence)\n";
