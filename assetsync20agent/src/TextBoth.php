<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Settings5/Work12 only. Pure contract checks precede the guarded B execution below. */
final class TextBoth
{
    public static function normalize(mixed $value, array $pin): string
    {
        self::validatePin($pin);
        if ($value !== null && !is_string($value)) { throw new \RuntimeException('Text must be a string or null.'); }
        $text = $value ?? '';
        if (!mb_check_encoding($text, 'UTF-8') || !isset($pin['limits']['characters'], $pin['limits']['bytes'])
            || mb_strlen($text, 'UTF-8') > $pin['limits']['characters'] || strlen($text) > $pin['limits']['bytes']) {
            throw new \RuntimeException('destination_limit');
        }
        return $text;
    }

    public static function validatePin(array $pin): void
    {
        if (array_keys($pin) !== ['kind', 'key', 'capture_key', 'type', 'search_id', 'definition_id', 'container_id',
            'class', 'table', 'column', 'definition_hash', 'limits']
            || !in_array($pin['kind'], ['native', 'fields'], true) || !in_array($pin['type'], ['text', 'textarea'], true)
            || !is_int($pin['search_id']) || $pin['search_id'] < 1 || !self::hex($pin['definition_hash'], 64)
            || !is_array($pin['limits']) || array_keys($pin['limits']) !== ['characters', 'bytes']) {
            throw new \RuntimeException('Invalid text field pin.');
        }
        foreach (['characters', 'bytes'] as $limit) {
            if (!is_int($pin['limits'][$limit]) || $pin['limits'][$limit] < 1) { throw new \RuntimeException('Invalid storage limit.'); }
        }
        foreach (['key', 'capture_key', 'class', 'table', 'column'] as $key) {
            if (!is_string($pin[$key]) || $pin[$key] === '' || strlen($pin[$key]) > 512) { throw new \RuntimeException('Invalid text identity.'); }
        }
        if ($pin['type'] === 'text' && $pin['limits']['characters'] > 255) { throw new \RuntimeException('Text exceeds the supported character limit.'); }
        if ($pin['kind'] === 'native') {
            if (!in_array($pin['key'], ['name', 'comment'], true) || $pin['column'] !== $pin['key']
                || $pin['capture_key'] !== 'native:' . $pin['key'] || $pin['class'] !== 'Computer' || $pin['table'] !== 'glpi_computers'
                || $pin['definition_id'] !== null || $pin['container_id'] !== null
                || $pin['type'] !== ($pin['key'] === 'name' ? 'text' : 'textarea')) { throw new \RuntimeException('Invalid native text pin.'); }
        } elseif (!is_int($pin['definition_id']) || $pin['definition_id'] < 1 || !is_int($pin['container_id']) || $pin['container_id'] < 1
            || $pin['capture_key'] !== 'fields:' . $pin['definition_id']
            || !preg_match('/^PluginFieldsComputer[A-Za-z0-9_]+$/D', $pin['class'])
            || !preg_match('/^glpi_plugin_fields_[a-z0-9_]{1,45}$/D', $pin['table'])
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $pin['column'])
            || $pin['key'] !== 'Computer.' . $pin['class'] . '.' . $pin['column']) {
            throw new \RuntimeException('Invalid custom text pin.');
        }
    }

    public static function validateEnvelope(array $e, array $pin): void
    {
        self::validatePin($pin);
        if (array_keys($e) !== ['value', 'hash', 'meta', 'origin', 'guard']) { throw new \RuntimeException('Invalid text envelope.'); }
        self::normalize($e['value'], $pin);
        if ($e['hash'] !== self::valueHash($e['value']) || !self::hex($e['meta'], 64)) {
            throw new \RuntimeException('Invalid raw text evidence hash.');
        }
        if ($e['origin'] !== null) {
            $o = $e['origin'];
            if (!is_array($o) || array_keys($o) !== ['identity', 'revision', 'kind', 'from_epoch', 'to_epoch']
                || !is_string($o['identity']) || !preg_match('/^[a-f0-9]{32}:[a-f0-9]{32}$/D', $o['identity'])
                || !is_int($o['revision']) || $o['revision'] < 1 || $o['kind'] !== 'user'
                || !is_int($o['from_epoch']) || !is_int($o['to_epoch']) || $o['from_epoch'] < 0 || $o['from_epoch'] > $o['to_epoch']) {
                throw new \RuntimeException('Invalid original text interval.');
            }
        }
        $g = $e['guard'];
        if (!is_array($g) || array_keys($g) !== ['epoch', 'generation', 'head', 'history'] || !self::hex($g['epoch'], 32)
            || ($g['generation'] !== null && (!is_int($g['generation']) || $g['generation'] < 0))
            || ($g['generation'] === null ? $g['head'] !== null : !self::hex($g['head'], 64))
            || !is_array($g['history']) || array_keys($g['history']) !== ['latest', 'anchor']
            || !is_int($g['history']['latest']) || $g['history']['latest'] < 0) { throw new \RuntimeException('Invalid text execution guard.'); }
        $anchor = $g['history']['anchor'];
        if ($anchor !== null && (!is_array($anchor) || array_keys($anchor) !== ['id', 'hash']
            || !is_int($anchor['id']) || $anchor['id'] < 1 || !self::hex($anchor['hash'], 64))) { throw new \RuntimeException('Invalid text History anchor.'); }
        if (($anchor['id'] ?? 0) !== $g['history']['latest'] || ($e['origin'] !== null && ($anchor === null || $g['head'] === null))) {
            throw new \RuntimeException('Incomplete text origin guard.');
        }
    }

    public static function decide(array $a, array $b, array $mapping): string
    {
        self::validateMappings([$mapping]);
        self::validateEnvelope($a, $mapping['a']); self::validateEnvelope($b, $mapping['b']);
        if (self::normalize($a['value'], $mapping['a']) === self::normalize($b['value'], $mapping['b'])) { return 'equal'; }
        if ($a['origin'] === null || $b['origin'] === null) { return 'conflict'; }
        if ($a['origin']['from_epoch'] > $b['origin']['to_epoch']) { return 'a'; }
        if ($b['origin']['from_epoch'] > $a['origin']['to_epoch']) { return 'b'; }
        return 'conflict';
    }

    public static function validateResult(array $work, array $policy, array $r): void
    {
        self::validateSources($work, $policy);
        if (array_keys($r) !== ['revision', 'source_hash', 'b_complete', 'fields', 'groups']
            || !is_int($r['revision']) || $r['revision'] < 1 || $r['revision'] > 25
            || $r['source_hash'] !== $work['source_hash'] || !is_bool($r['b_complete'])
            || !is_array($r['fields']) || !array_is_list($r['fields']) || count($r['fields']) !== count($policy['mappings'])
            || !is_array($r['groups']) || !array_is_list($r['groups']) || count($r['groups']) > 8) { throw new \RuntimeException('Invalid text progress.'); }
        $members = []; $groups = []; $last = ''; $complete = true;
        foreach ($r['groups'] as $g) {
            if (!is_array($g) || array_keys($g) !== ['id', 'side', 'kind', 'container_id', 'members', 'phase', 'mutation', 'reason', 'started_at', 'model_ended_at']
                || !self::hex($g['id'], 32) || strcmp($g['id'], $last) <= 0 || !in_array($g['side'], ['a', 'b'], true)
                || !in_array($g['kind'], ['native', 'fields'], true)
                || ($g['kind'] === 'native' ? $g['container_id'] !== null : !is_int($g['container_id']) || $g['container_id'] < 1)
                || $g['id'] !== self::groupId($work['id'], $g['side'], $g['kind'], $g['container_id'])
                || !is_array($g['members']) || !array_is_list($g['members']) || $g['members'] === [] || count($g['members']) > 8
                || !in_array($g['phase'], $g['side'] === 'a' ? ['offered', 'blocked'] : ['pending', 'intent', 'origin_pending', 'done', 'blocked', 'uncertain'], true)
                || !self::validReason($g['reason'])) { throw new \RuntimeException('Invalid text group.'); }
            $sorted = array_values(array_unique($g['members'])); sort($sorted, SORT_STRING);
            if ($g['members'] !== $sorted) { throw new \RuntimeException('Invalid text group membership.'); }
            foreach ($g['members'] as $id) {
                if (!self::hex($id, 32) || isset($members[$id])) { throw new \RuntimeException('Duplicate text group member.'); }
                $members[$id] = $g['id'];
            }
            $mutation = ['pending' => 'unstarted', 'intent' => 'unknown', 'origin_pending' => 'committed', 'done' => 'committed', 'offered' => 'none', 'blocked' => 'none', 'uncertain' => 'unknown'][$g['phase']];
            if ($g['mutation'] !== $mutation || (in_array($g['phase'], ['blocked', 'uncertain'], true) ? $g['reason'] === null : $g['reason'] !== null)) { throw new \RuntimeException('Invalid group mutation.'); }
            foreach (['started_at', 'model_ended_at'] as $key) {
                if ($g[$key] !== null && (!is_int($g[$key]) || $g[$key] < $work['expires'] - 300 || $g[$key] > $work['expires'])) { throw new \RuntimeException('Invalid group time.'); }
            }
            if (in_array($g['phase'], ['intent', 'origin_pending', 'done', 'uncertain'], true) && $g['started_at'] === null
                || in_array($g['phase'], ['origin_pending', 'done'], true) && $g['model_ended_at'] === null
                || $g['model_ended_at'] !== null && ($g['started_at'] === null || $g['model_ended_at'] < $g['started_at'])
                || in_array($g['phase'], ['pending', 'offered'], true) && ($g['started_at'] !== null || $g['model_ended_at'] !== null)) { throw new \RuntimeException('Invalid group timing.'); }
            if ($g['side'] === 'b' && !in_array($g['phase'], ['done', 'blocked'], true)) { $complete = false; }
            $groups[$g['id']] = $g; $last = $g['id'];
        }
        $seen = [];
        foreach ($r['fields'] as $i => $f) {
            $m = $policy['mappings'][$i]; $a = $work['sources'][$i]['envelope'];
            if (!is_array($f) || array_keys($f) !== ['mapping_id', 'source_hash', 'winner', 'outcome', 'mutation', 'before', 'after', 'group_id', 'reason']
                || $f['mapping_id'] !== $m['mapping_id'] || $f['source_hash'] !== TaskExecutor::hash($a)
                || !self::validReason($f['reason'])) { throw new \RuntimeException('Invalid text field progress.'); }
            if ($f['winner'] === null) {
                if ($f['outcome'] !== 'blocked' || $f['mutation'] !== 'none' || $f['before'] !== null || $f['after'] !== null
                    || $f['group_id'] !== null || $f['reason'] === null) { throw new \RuntimeException('Invalid unavailable text fact.'); }
                continue;
            }
            if (!is_array($f['before']) || self::decide($a, $f['before'], $m) !== $f['winner']) { throw new \RuntimeException('Text decision mismatch.'); }
            if (in_array($f['winner'], ['equal', 'conflict'], true)) {
                if ($f['outcome'] !== ($f['winner'] === 'equal' ? 'unchanged' : 'conflict') || $f['mutation'] !== 'none'
                    || $f['after'] !== $f['before'] || $f['group_id'] !== null || $f['reason'] !== null) { throw new \RuntimeException('Invalid no-op text fact.'); }
                continue;
            }
            $side = $f['winner'] === 'a' ? 'b' : 'a'; $pin = $m[$side];
            $gid = self::groupId($work['id'], $side, $pin['kind'], $pin['container_id']);
            $g = $groups[$gid] ?? null;
            if ($f['group_id'] !== $gid || ($members[$m['mapping_id']] ?? null) !== $gid || !$g
                || $f['outcome'] !== ($g['phase'] === 'done' ? 'verified' : $g['phase'])
                || $f['mutation'] !== $g['mutation'] || $f['reason'] !== $g['reason']) { throw new \RuntimeException('Text group field mismatch.'); }
            $seen[$m['mapping_id']] = true;
            if ($g['phase'] === 'done') {
                if (!is_array($f['after'])) { throw new \RuntimeException('Missing inherited text result.'); }
                self::validateEnvelope($f['after'], $m['b']);
                if ($f['after']['value'] !== self::normalize($a['value'], $m['b']) || $f['after']['origin'] !== $a['origin']
                    || $f['after']['meta'] !== $f['before']['meta']) { throw new \RuntimeException('Inherited text result mismatch.'); }
            } elseif (in_array($g['phase'], ['offered', 'blocked'], true)) {
                if ($f['after'] !== $f['before']) { throw new \RuntimeException('Read-only text result changed evidence.'); }
                if ($g['phase'] === 'offered') { self::normalize($f['before']['value'], $m['a']); }
            } elseif ($f['after'] !== null) { throw new \RuntimeException('Unqualified after evidence.'); }
        }
        if (array_diff_key($members, $seen) || $r['b_complete'] !== $complete) { throw new \RuntimeException('Incomplete text plan.'); }
    }

    public static function validateMappings(array $mappings): void
    {
        if (!array_is_list($mappings) || count($mappings) < 1 || count($mappings) > 8) { throw new \RuntimeException('One to eight text mappings required.'); }
        $last = ''; $seen = ['a' => [], 'b' => []];
        foreach ($mappings as $m) {
            if (!is_array($m) || array_keys($m) !== ['mapping_id', 'source_of_truth', 'a', 'b'] || $m['source_of_truth'] !== 'both'
                || !is_array($m['a']) || !is_array($m['b'])) { throw new \RuntimeException('Invalid text mapping.'); }
            self::validatePin($m['a']); self::validatePin($m['b']);
            if ($m['mapping_id'] !== substr(TaskExecutor::hash(['text-row', $m['a'], $m['b']]), 0, 32) || strcmp($m['mapping_id'], $last) <= 0) { throw new \RuntimeException('Invalid text mapping order or identity.'); }
            foreach (['a', 'b'] as $side) {
                $pin = $m[$side]; $physical = $pin['table'] . ':' . $pin['column'];
                if (isset($seen[$side][$pin['capture_key']]) || isset($seen[$side][$physical])) { throw new \RuntimeException('Aliased text endpoint.'); }
                $seen[$side][$pin['capture_key']] = $seen[$side][$physical] = true;
            }
            $last = $m['mapping_id'];
        }
    }

    public static function validateSources(array $work, array $policy): void
    {
        self::validateMappings($policy['mappings'] ?? []);
        if (!is_array($work['sources'] ?? null) || !array_is_list($work['sources']) || count($work['sources']) !== count($policy['mappings'])
            || ($work['source_hash'] ?? null) !== TaskExecutor::hash($work['sources'])) { throw new \RuntimeException('Text sources mismatch.'); }
        foreach ($work['sources'] as $i => $s) {
            if (!is_array($s) || array_keys($s) !== ['mapping_id', 'envelope'] || $s['mapping_id'] !== $policy['mappings'][$i]['mapping_id']
                || !is_array($s['envelope'])) { throw new \RuntimeException('Invalid text source row.'); }
            self::validateEnvelope($s['envelope'], $policy['mappings'][$i]['a']);
        }
    }

    public static function validateProgress(array $work, array $policy, array $previous, array $next): void
    {
        self::validateResult($work, $policy, $next);
        if ($previous === []) { return; }
        self::validateResult($work, $policy, $previous);
        if ($next['revision'] === $previous['revision']) {
            if ($next !== $previous) { throw new \RuntimeException('Same revision changed.'); }
            return;
        }
        if ($next['revision'] <= $previous['revision'] || count($previous['groups']) !== count($next['groups'])) { throw new \RuntimeException('Text progress regressed.'); }
        $minimum = 0; $maximum = 0;
        foreach ($previous['groups'] as $i => $old) {
            $new = $next['groups'][$i];
            foreach (['id', 'side', 'kind', 'container_id', 'members'] as $key) {
                if ($new[$key] !== $old[$key]) { throw new \RuntimeException('Text group plan changed.'); }
            }
            $reachable = ['pending' => ['pending', 'intent', 'origin_pending', 'done', 'blocked', 'uncertain'],
                'intent' => ['intent', 'origin_pending', 'done', 'blocked', 'uncertain'], 'origin_pending' => ['origin_pending', 'done'],
                'done' => ['done'], 'blocked' => ['blocked'], 'uncertain' => ['uncertain'], 'offered' => ['offered']];
            if (!in_array($new['phase'], $reachable[$old['phase']], true)) { throw new \RuntimeException('Illegal text group transition.'); }
            if ($new['phase'] === $old['phase']) {
                if ($new !== $old) { throw new \RuntimeException('Text group fact changed.'); }
            } else {
                $distance = ['pending' => ['intent' => [1, 1], 'origin_pending' => [2, 2], 'done' => [3, 3], 'blocked' => [1, 2], 'uncertain' => [2, 2]],
                    'intent' => ['origin_pending' => [1, 1], 'done' => [2, 2], 'blocked' => [1, 1], 'uncertain' => [1, 1]],
                    'origin_pending' => ['done' => [1, 1]]][$old['phase']][$new['phase']];
                if ($old['phase'] === 'pending' && $new['phase'] === 'blocked') {
                    $distance = $new['started_at'] === null ? [1, 1] : [2, 2];
                }
                $minimum += $distance[0]; $maximum += $distance[1];
                foreach (['started_at', 'model_ended_at'] as $key) {
                    if ($old[$key] !== null && $old[$key] !== $new[$key]) { throw new \RuntimeException('Group timing changed.'); }
                }
            }
        }
        foreach ($previous['fields'] as $i => $old) {
            $new = $next['fields'][$i];
            foreach (['mapping_id', 'source_hash', 'winner', 'before', 'group_id'] as $key) {
                if ($new[$key] !== $old[$key]) { throw new \RuntimeException('Text decision changed.'); }
            }
            if (!in_array($old['outcome'], ['pending', 'intent', 'origin_pending'], true) && $new !== $old) { throw new \RuntimeException('Completed text fact changed.'); }
        }
        $distance = $next['revision'] - $previous['revision'];
        if ($maximum === 0 || $distance < $minimum || $distance > $maximum) { throw new \RuntimeException('Unexplained text progress revision.'); }
    }

    public static function validateReadObservation(array $result, array $obs): void
    {
        if (array_keys($obs) !== ['revision', 'receipt_hash', 'fields'] || $obs['revision'] !== ($result['revision'] ?? 0)
            || $obs['receipt_hash'] !== TaskExecutor::hash($result) || !is_array($obs['fields']) || !array_is_list($obs['fields'])
            || count($obs['fields']) !== count($result['fields'] ?? [])) { throw new \RuntimeException('Invalid text observation.'); }
        foreach ($obs['fields'] as $i => $o) {
            $f = $result['fields'][$i]; $stable = !in_array($f['outcome'], ['pending', 'intent', 'origin_pending'], true);
            if (!is_array($o) || array_keys($o) !== ['mapping_id', 'receipt_hash', 'current_matches'] || $o['mapping_id'] !== $f['mapping_id']
                || $o['receipt_hash'] !== ($stable ? TaskExecutor::hash($f) : null)
                || !in_array($o['current_matches'], [null, true, false], true)
                || ((!$stable || $f['after'] === null || $f['outcome'] === 'uncertain') && $o['current_matches'] !== null)) { throw new \RuntimeException('Invalid text field observation.'); }
        }
    }

    private static function groupId(string $id, string $side, string $kind, ?int $container): string
    {
        return substr(TaskExecutor::hash(['text-group', $id, $side, $kind, $container]), 0, 32);
    }

    private static function validReason(mixed $reason): bool
    {
        return $reason === null || is_string($reason) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) === 1;
    }

    /** Fresh admission and historical READ share bindings, but only mutation requires active policy/UPDATE. */
    public static function authorize(array $row, array $body, bool $mutation, int $deadline): array
    {
        global $DB;
        $settings = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? RestPeer::read($body) : RestPeer::work($body);
        $stored = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id`=' . $DB->quote($body['settings_id']));
        if (!$stored || $stored['hash'] !== $body['settings_hash']) { throw new \RuntimeException('metadata_invalid'); }
        $policy = RestSettings::decode($stored['body'], $stored['hash']);
        if ($policy['version'] !== 5 || $policy['mappings'] === [] || $policy['target_entity'] !== $body['entity_id']
            || (!$policy['include_child_entities'] && $policy['source_entity'] !== $body['source_asset_entity'])
            || !in_array('Computer', $policy['asset_types'], true)
            || $stored['binding'] !== RestSettings::binding($settings, 5) || $row['binding'] !== $stored['binding']) { throw new \RuntimeException('metadata_invalid'); }
        RestSettings::authorize($policy);
        self::validateSources($body, $policy);
        $fresh = Outbox::one('SELECT * FROM `' . RestWork::getTable() . '` WHERE `work_id`=' . $DB->quote($body['id']));
        if (!$fresh || $fresh['body'] !== $row['body'] || $fresh['hash'] !== $row['hash'] || $fresh['binding'] !== $row['binding']) { throw new \RuntimeException('metadata_invalid'); }
        if ($mutation && (time() > $body['expires'] || hrtime(true) >= $deadline || !RestSettings::active($policy)
            || ($settings['transport'] ?? '') !== 'rest_records')) { throw new \RuntimeException('policy_unavailable'); }
        return [$settings, $policy];
    }

    /** POST only. Persist a complete plan, then advance independent storage groups. */
    public static function run(array $row, array $body, array $policy, int $deadline, array $allowRows): void
    {
        global $DB;
        self::validateSources($body, $policy);
        self::validateAllowRows($allowRows, array_column($policy['mappings'], 'mapping_id'));
        [$settings] = self::authorize($row, $body, false, $deadline);
        $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
        $journal = self::journal($row);
        if (!$journal) {
            if (hrtime(true) >= $deadline) { return; }
            $plan = self::prepare($body, $policy, $target, $deadline);
            if ($plan['revision'] !== 1) { throw new \RuntimeException('Invalid initial text plan.'); }
            self::preflight($row, $body, $policy, $plan);
            if (hrtime(true) >= $deadline) { return; }
            Outbox::sql('START TRANSACTION');
            try {
                self::authorize($row, $body, false, $deadline);
                ServiceContext::run($target, static function () use ($target, $body, $policy, $plan): void {
                    ServiceContext::readComputer($target);
                    foreach ($plan['fields'] as $i => $field) {
                        if ($field['before'] === null) { continue; }
                        $pin = $policy['mappings'][$i]['b'];
                        Snapshot::textState($pin, $body['items_id'], $body['entity_id'], false, true);
                        FieldEvidence::assertGuard($body['items_id'], $pin['capture_key'], $field['before']);
                    }
                });
                self::insertJournal($row, ['result' => $plan, 'receipts' => []]);
                RestWork::projectText($row, self::state($plan), $plan);
                Outbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
            $journal = self::journal($row);
        }
        $document = self::document($journal, $body, $policy);
        foreach ($document['result']['groups'] as $group) {
            if ($group['side'] !== 'b' || hrtime(true) >= $deadline) { continue; }
            $journal = self::journal($row);
            $document = self::document($journal, $body, $policy);
            $group = self::findGroup($document['result'], $group['id']);
            try {
                if ($group['phase'] === 'intent') {
                    self::closeGroup($row, $body, $policy, $journal, $group['id'], 'uncertain', 'unresolved_intent');
                } elseif ($group['phase'] === 'origin_pending') {
                    self::recover($row, $body, $policy, $target, $journal, $group, $deadline);
                } elseif ($group['phase'] === 'pending') {
                    self::executeGroup($row, $body, $policy, $target, $journal, $group, $allowRows, $deadline);
                }
            } catch (\Throwable $error) {
                // Continue unrelated groups only after proving the connection is idle again.
                if (!RestPeer::idle()) { throw $error; }
                $saved = self::journal($row);
                $current = self::findGroup(self::document($saved, $body, $policy)['result'], $group['id']);
                if ($current['phase'] === 'intent') {
                    self::closeGroup($row, $body, $policy, $saved, $group['id'], 'uncertain', 'unresolved_intent');
                }
            }
        }
    }

    public static function validateAllowRows(array $rows, ?array $known = null): void
    {
        if (!array_is_list($rows) || count($rows) > 8 || count(array_filter($rows, 'is_string')) !== count($rows)) { throw new \RuntimeException('Invalid restrictive row mask.'); }
        $sorted = array_values(array_unique($rows)); sort($sorted, SORT_STRING);
        if (!array_is_list($rows) || count($rows) > 8 || $rows !== $sorted) { throw new \RuntimeException('Invalid restrictive row mask.'); }
        foreach ($rows as $id) {
            if (!self::hex($id, 32) || ($known !== null && !in_array($id, $known, true))) { throw new \RuntimeException('Unknown restrictive row mask member.'); }
        }
    }

    private static function prepare(array $body, array $policy, array $target, int $deadline): array
    {
        $result = ['revision' => 1, 'source_hash' => $body['source_hash'], 'b_complete' => true, 'fields' => [], 'groups' => []];
        $groups = [];
        ServiceContext::run($target, static function () use ($target, $body, $policy, $deadline, &$result, &$groups): void {
            ServiceContext::readComputer($target);
            foreach ($policy['mappings'] as $i => $mapping) {
                if (hrtime(true) >= $deadline) { throw new \RuntimeException('execution_deadline'); }
                $source = $body['sources'][$i]['envelope'];
                $field = ['mapping_id' => $mapping['mapping_id'], 'source_hash' => TaskExecutor::hash($source),
                    'winner' => null, 'outcome' => 'blocked', 'mutation' => 'none', 'before' => null, 'after' => null, 'group_id' => null, 'reason' => 'field_unavailable'];
                try {
                    $pin = $mapping['b']; Snapshot::textState($pin, $body['items_id'], $body['entity_id']);
                    $before = FieldEvidence::envelope($body['items_id'], $pin['capture_key']);
                    $winner = self::decide($source, $before, $mapping);
                    $field = array_replace($field, ['winner' => $winner, 'before' => $before, 'after' => $before, 'reason' => null]);
                    if (in_array($winner, ['equal', 'conflict'], true)) {
                        $field['outcome'] = $winner === 'equal' ? 'unchanged' : 'conflict';
                    } else {
                        $side = $winner === 'a' ? 'b' : 'a'; $destination = $mapping[$side];
                        $id = self::groupId($body['id'], $side, $destination['kind'], $destination['container_id']);
                        if (!isset($groups[$id])) {
                            $groups[$id] = ['id' => $id, 'side' => $side, 'kind' => $destination['kind'], 'container_id' => $destination['container_id'],
                                'members' => [], 'phase' => $side === 'b' ? 'pending' : 'offered', 'mutation' => $side === 'b' ? 'unstarted' : 'none',
                                'reason' => null, 'started_at' => null, 'model_ended_at' => null];
                        }
                        $groups[$id]['members'][] = $mapping['mapping_id']; $field['group_id'] = $id;
                        try { self::normalize($winner === 'a' ? $source['value'] : $before['value'], $destination); }
                        catch (\Throwable $error) { $groups[$id]['phase'] = 'blocked'; $groups[$id]['mutation'] = 'none'; $groups[$id]['reason'] = self::reason($error); }
                    }
                } catch (\Throwable $error) { $field['reason'] = self::reason($error); }
                $result['fields'][] = $field;
            }
            // Validate the full proposed input: a mapped sibling can fill another mandatory field.
            foreach ($groups as $id => $group) {
                if ($group['side'] !== 'b' || $group['phase'] === 'blocked') { continue; }
                try {
                    ServiceContext::computer($target); $pins = []; $values = []; $state = null;
                    foreach ($policy['mappings'] as $i => $mapping) {
                        if (!in_array($mapping['mapping_id'], $group['members'], true)) { continue; }
                        $pin = $mapping['b']; self::transactionalTables($pin);
                        $state = Snapshot::textState($pin, $body['items_id'], $body['entity_id'], true);
                        $pins[] = $pin; $values[$pin['column']] = self::normalize($body['sources'][$i]['envelope']['value'], $pin);
                    }
                    Snapshot::textGroupInput($pins, $state, $values);
                } catch (\Throwable $error) { $groups[$id]['phase'] = 'blocked'; $groups[$id]['mutation'] = 'none'; $groups[$id]['reason'] = self::reason($error); }
            }
        });
        ksort($groups, SORT_STRING); $result['groups'] = array_values($groups);
        foreach ($result['groups'] as $g) { $result = self::applyGroup($result, $g); }
        self::validateResult($body, $policy, $result);
        return $result;
    }

    private static function executeGroup(array $row, array $body, array $policy, array $target, array $journal, array $group, array $allowRows, int $deadline): void
    {
        global $DB;
        if (array_diff($group['members'], $allowRows)) { self::closeGroup($row, $body, $policy, $journal, $group['id'], 'blocked', 'source_superseded'); return; }
        if (time() > $body['expires'] || !RestSettings::active($policy)) { self::closeGroup($row, $body, $policy, $journal, $group['id'], 'blocked', 'policy_unavailable'); return; }
        try { $captured = self::idleGroup($body, $policy, self::document($journal, $body, $policy)['result'], $group, $target); }
        catch (\Throwable $error) { self::closeGroup($row, $body, $policy, $journal, $group['id'], 'blocked', self::reason($error)); return; }
        if (hrtime(true) >= $deadline) { return; }
        Outbox::sql('START TRANSACTION'); $committing = false;
        try {
            self::lockJournal($row, $journal); self::authorize($row, $body, true, $deadline);
            $document = self::document($journal, $body, $policy);
            ServiceContext::run($target, static function () use ($target, $body, $policy, $document, $group, $captured): void {
                ServiceContext::computer($target); self::freshGroup($body, $policy, $document['result'], $group, $captured);
            });
            $group['phase'] = 'intent'; $group['mutation'] = 'unknown'; $group['started_at'] = time();
            $document['result'] = self::applyGroup($document['result'], $group);
            self::store($row, $body, $policy, $journal, $document);
            $committing = true; Outbox::sql('COMMIT');
        } catch (\Throwable $error) {
            $rolledBack = $DB->doQuery('ROLLBACK');
            if ($committing || !$rolledBack) { throw $error; }
            if (hrtime(true) >= $deadline) { return; }
            self::closeGroup($row, $body, $policy, self::journal($row), $group['id'], 'blocked', self::reason($error)); return;
        }
        $journal = self::journal($row); $ownedDB = $DB; $connection = self::connection(); $commitAttempted = false;
        $fence = 'text_group_' . bin2hex(random_bytes(8));
        try { $captured = self::idleGroup($body, $policy, self::document($journal, $body, $policy)['result'], $group, $target); }
        catch (\Throwable $error) { self::closeGroup($row, $body, $policy, $journal, $group['id'], 'blocked', self::reason($error)); return; }
        Outbox::sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); Outbox::sql('START TRANSACTION');
        try {
            Outbox::sql('SAVEPOINT ' . $fence);
            self::lockJournal($row, $journal); self::authorize($row, $body, true, $deadline);
            $document = self::document($journal, $body, $policy);
            $receipts = ServiceContext::run($target, static function () use ($target, $body, $policy, $document, $group, $deadline, $captured): array {
                $item = ServiceContext::computer($target);
                [$pins, $state, $changes, $values] = self::freshGroup($body, $policy, $document['result'], $group, $captured);
                $input = Snapshot::textGroupInput($pins, $state, $values, true);
                if (hrtime(true) >= $deadline || time() > $body['expires']) { throw new \RuntimeException('execution_deadline'); }
                // The whole map must be installed INSIDE ServiceContext's ordinary-origin suppression.
                return FieldEvidence::withOrigins($body['items_id'], $changes, static function () use ($item, $target, $body, $pins, $state, $input, $values, $changes): array {
                    $ok = $pins[0]['kind'] === 'native' ? $item->update($input) : (new \PluginFieldsContainer())->updateFieldsValues($input, 'Computer', false);
                    ServiceContext::computer($target); // Fresh parent UPDATE after the real supported model.
                    $expectedParent = $state['parent']; $expectedRow = $state['row'];
                    foreach ($values as $column => $value) { $expectedRow[$column] = $value; if ($pins[0]['kind'] === 'native') { $expectedParent[$column] = $value; } }
                    foreach ($pins as $pin) {
                        $after = Snapshot::textState($pin, $body['items_id'], $body['entity_id'], true, true); $parent = $expectedParent; $child = $expectedRow;
                        foreach (['date_mod', 'date_creation'] as $key) { unset($parent[$key], $child[$key], $after['parent'][$key], $after['row'][$key]); }
                        if (!$ok || $after['parent'] !== $parent || $after['row'] !== $child) { throw new \RuntimeException('destination_validation'); }
                    }
                    if (Snapshot::textGroupInput($pins, $after, $values, true) !== $input) { throw new \RuntimeException('metadata_invalid'); }
                    $receipts = [];
                    foreach ($changes as $change) {
                        $receipt = FieldEvidence::inheritedReceipt($body['items_id'], $change['key'], $change['origin']);
                        if ($receipt['value_hash'] !== $change['after_hash']) { throw new \RuntimeException('destination_validation'); }
                        $receipts[] = $receipt;
                    }
                    return $receipts;
                });
            });
            self::authorize($row, $body, true, $deadline);
            if (hrtime(true) >= $deadline || time() > $body['expires']) { throw new \RuntimeException('execution_deadline'); }
            $group['phase'] = 'origin_pending'; $group['mutation'] = 'committed'; $group['model_ended_at'] = time();
            $document['receipts'][$group['id']] = $receipts; $document['result'] = self::applyGroup($document['result'], $group);
            self::store($row, $body, $policy, $journal, $document); $commitAttempted = true; Outbox::sql('COMMIT');
        } catch (\Throwable $error) {
            $same = $connection > 0 && $DB === $ownedDB && self::connection() === $connection;
            $fenced = false; $rolledBack = false;
            try {
                if ($same && !$commitAttempted) { $fenced = $DB->doQuery('ROLLBACK TO SAVEPOINT ' . $fence) !== false; }
            } catch (\Throwable) { $fenced = false; }
            try { if ($same) { $rolledBack = $DB->doQuery('ROLLBACK') !== false; } }
            catch (\Throwable) { $rolledBack = false; }
            $saved = self::journal($row); $savedDocument = self::document($saved, $body, $policy);
            if (self::findGroup($savedDocument['result'], $group['id'])['phase'] === 'intent') {
                // A later rollback cannot disprove an earlier ambiguous COMMIT.
                $none = !$commitAttempted && $same && $fenced && $rolledBack;
                self::closeGroup($row, $body, $policy, $saved, $group['id'], $none ? 'blocked' : 'uncertain', self::reason($error));
            }
            return;
        } finally { FieldEvidence::finishWork(); }
        self::recover($row, $body, $policy, $target, self::journal($row), $group, $deadline);
    }

    private static function idleGroup(array $body, array $policy, array $result, array $group, array $target): array
    {
        return ServiceContext::run($target, static function () use ($target, $body, $policy, $result, $group): array {
            ServiceContext::computer($target); $captured = [];
            foreach ($policy['mappings'] as $i => $mapping) {
                if (!in_array($mapping['mapping_id'], $group['members'], true)) { continue; }
                $pin = $mapping['b']; Snapshot::textState($pin, $body['items_id'], $body['entity_id'], true);
                $fresh = FieldEvidence::envelope($body['items_id'], $pin['capture_key']); self::validateEnvelope($fresh, $pin);
                if (!self::sameLogical($fresh, $result['fields'][$i]['before'])) { throw new \RuntimeException('destination_changed'); }
                $captured[$mapping['mapping_id']] = $fresh;
            }
            return $captured;
        });
    }

    private static function freshGroup(array $body, array $policy, array $result, array $group, array $captured): array
    {
        $pins = []; $changes = []; $values = []; $state = null;
        foreach ($policy['mappings'] as $i => $mapping) {
            if (!in_array($mapping['mapping_id'], $group['members'], true)) { continue; }
            $pin = $mapping['b']; self::transactionalTables($pin);
            $selected = Snapshot::textState($pin, $body['items_id'], $body['entity_id'], true, true);
            if ($state !== null && $state !== $selected) { throw new \RuntimeException('metadata_invalid'); } $state = $selected;
            $fresh = $captured[$mapping['mapping_id']]; self::validateEnvelope($fresh, $pin);
            if (!self::sameLogical($fresh, $result['fields'][$i]['before'])) { throw new \RuntimeException('destination_changed'); }
            FieldEvidence::assertGuard($body['items_id'], $pin['capture_key'], $fresh);
            $value = self::normalize($body['sources'][$i]['envelope']['value'], $pin);
            $pins[] = $pin; $values[$pin['column']] = $value;
            $changes[] = ['key' => $pin['capture_key'], 'before_hash' => $fresh['hash'], 'after_hash' => self::valueHash($value), 'origin' => $body['sources'][$i]['envelope']['origin']];
        }
        return [$pins, $state, $changes, $values];
    }

    private static function recover(array $row, array $body, array $policy, array $target, array $journal, array $group, int $deadline): void
    {
        global $DB;
        if (hrtime(true) >= $deadline) { return; }
        try {
            self::authorize($row, $body, false, $deadline); $document = self::document($journal, $body, $policy);
            $receipts = $document['receipts'][$group['id']] ?? [];
            if (count($receipts) !== count($group['members'])) { throw new \RuntimeException('metadata_invalid'); }
            $after = ServiceContext::run($target, static function () use ($target, $body, $policy, $group, $receipts, $deadline): array {
                ServiceContext::readComputer($target); $after = [];
                foreach ($policy['mappings'] as $mapping) {
                    if (!in_array($mapping['mapping_id'], $group['members'], true)) { continue; }
                    if (hrtime(true) >= $deadline) { throw new \RuntimeException('execution_deadline'); }
                    $pin = $mapping['b']; Snapshot::textState($pin, $body['items_id'], $body['entity_id']);
                    $matches = array_values(array_filter($receipts, static fn ($r): bool => $r['field_key'] === $pin['capture_key']));
                    if (count($matches) !== 1) { throw new \RuntimeException('metadata_invalid'); }
                    $after[$mapping['mapping_id']] = FieldEvidence::settleInherited($matches[0]);
                }
                return $after;
            });
            if (hrtime(true) >= $deadline) { return; }
            Outbox::sql('START TRANSACTION');
            try {
                self::lockJournal($row, $journal); self::authorize($row, $body, false, $deadline);
                ServiceContext::run($target, static function () use ($target, $body, $policy, $after): void {
                    ServiceContext::readComputer($target);
                    foreach ($policy['mappings'] as $mapping) {
                        if (!isset($after[$mapping['mapping_id']])) { continue; }
                        $pin = $mapping['b']; Snapshot::textState($pin, $body['items_id'], $body['entity_id'], false, true);
                        FieldEvidence::assertGuard($body['items_id'], $pin['capture_key'], $after[$mapping['mapping_id']]);
                    }
                });
                $group['phase'] = 'done'; $document['result'] = self::applyGroup($document['result'], $group, $after);
                self::store($row, $body, $policy, $journal, $document); Outbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        } catch (\Throwable) { /* Retain held receipts; neither POST nor GET repeats their model. */ }
    }

    /** READ only observes fresh evidence; it never executes, settles or rewrites progress. */
    public static function disclose(array $row): array
    {
        $body = RestWork::decode($row['body'], $row['hash']); [$settings, $policy] = self::authorize($row, $body, false, 0);
        $result = self::decodeJson($row['result'] ?? '{}', 65536);
        if ($result !== []) { self::validateResult($body, $policy, $result); }
        $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
        $observations = ServiceContext::run($target, static function () use ($target, $body, $policy, $result): array {
            ServiceContext::readComputer($target); $observations = [];
            foreach ($policy['mappings'] as $i => $mapping) {
                $pin = $mapping['b']; Snapshot::textReadPermission($pin, $body['entity_id']);
                if ($result === []) { continue; }
                $field = $result['fields'][$i]; $matches = null; $stable = !in_array($field['outcome'], ['pending', 'intent', 'origin_pending'], true);
                if ($stable && $field['after'] !== null) {
                    try {
                        Snapshot::textState($pin, $body['items_id'], $body['entity_id']);
                        $fresh = FieldEvidence::envelope($body['items_id'], $pin['capture_key']); self::validateEnvelope($fresh, $pin);
                        if ($fresh['origin'] !== null || $field['after']['origin'] === null) { $matches = self::sameLogical($fresh, $field['after']); }
                    } catch (\Throwable) { $matches = null; }
                }
                ServiceContext::readComputer($target); Snapshot::textReadPermission($pin, $body['entity_id']);
                $observations[] = ['mapping_id' => $mapping['mapping_id'], 'receipt_hash' => $stable ? TaskExecutor::hash($field) : null, 'current_matches' => $matches];
            }
            return $observations;
        });
        return ['revision' => $result['revision'] ?? 0, 'receipt_hash' => TaskExecutor::hash($result), 'fields' => $observations];
    }

    private static function sameLogical(array $a, array $b): bool
    {
        return $a['hash'] === $b['hash'] && $a['meta'] === $b['meta'] && $a['origin'] === $b['origin'];
    }

    public static function state(array $result): string
    {
        if ($result === []) { return 'accepted'; }
        foreach ($result['groups'] as $g) { if ($g['side'] === 'b' && in_array($g['phase'], ['pending', 'intent'], true)) { return 'active'; } }
        return $result['b_complete'] ? 'terminal' : 'held';
    }

    private static function applyGroup(array $result, array $group, array $after = []): array
    {
        foreach ($result['groups'] as &$g) { if ($g['id'] === $group['id']) { $g = $group; } } unset($g);
        foreach ($result['fields'] as &$field) {
            if ($field['group_id'] !== $group['id']) { continue; }
            $field['outcome'] = $group['phase'] === 'done' ? 'verified' : $group['phase']; $field['mutation'] = $group['mutation']; $field['reason'] = $group['reason'];
            $field['after'] = $group['phase'] === 'done' ? ($after[$field['mapping_id']] ?? null) : (in_array($group['phase'], ['offered', 'blocked'], true) ? $field['before'] : null);
        } unset($field);
        $result['b_complete'] = true;
        foreach ($result['groups'] as $g) { if ($g['side'] === 'b' && !in_array($g['phase'], ['done', 'blocked'], true)) { $result['b_complete'] = false; } }
        return $result;
    }

    private static function findGroup(array $result, string $id): array
    {
        foreach ($result['groups'] as $g) { if ($g['id'] === $id) { return $g; } } throw new \RuntimeException('Missing text group.');
    }

    private static function closeGroup(array $row, array $body, array $policy, array $journal, string $id, string $phase, string $reason): void
    {
        global $DB; Outbox::sql('START TRANSACTION');
        try {
            self::lockJournal($row, $journal); $document = self::document($journal, $body, $policy); $group = self::findGroup($document['result'], $id);
            if (!in_array($group['phase'], ['pending', 'intent'], true)) { throw new \RuntimeException('Cannot cancel started text group.'); }
            $group['phase'] = $phase; $group['mutation'] = $phase === 'blocked' ? 'none' : 'unknown'; $group['reason'] = $reason;
            $document['result'] = self::applyGroup($document['result'], $group); self::store($row, $body, $policy, $journal, $document); Outbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function preflight(array $row, array $body, array $policy, array $result): void
    {
        $worst = $result; $worst['revision'] = 25; $receipts = [];
        foreach ($worst['fields'] as $i => &$field) {
            if ($field['before'] === null) { continue; } $after = $field['before'];
            if ($field['winner'] === 'a') {
                try { $after['value'] = self::normalize($body['sources'][$i]['envelope']['value'], $policy['mappings'][$i]['b']); } catch (\Throwable) { continue; }
                $after['hash'] = self::valueHash($after['value']); $after['origin'] = $body['sources'][$i]['envelope']['origin'];
                $receipts[] = ['computer_id' => PHP_INT_MAX, 'field_key' => $policy['mappings'][$i]['b']['capture_key'],
                    'token' => str_repeat('f', 32), 'generation' => PHP_INT_MAX, 'epoch' => str_repeat('f', 32),
                    'candidate' => str_repeat('f', 64), 'origin' => $after['origin'], 'value_hash' => str_repeat('f', 64),
                    'meta' => str_repeat('f', 64), 'anchor' => ['id' => PHP_INT_MAX, 'hash' => str_repeat('f', 64)]];
            }
            $after['guard'] = ['epoch' => str_repeat('f', 32), 'generation' => PHP_INT_MAX, 'head' => str_repeat('f', 64), 'history' => ['latest' => PHP_INT_MAX, 'anchor' => ['id' => PHP_INT_MAX, 'hash' => str_repeat('f', 64)]]];
            $field['before']['guard'] = $after['guard']; $field['after'] = $after;
            $field['outcome'] = 'origin_pending'; $field['mutation'] = 'committed'; $field['reason'] = str_repeat('x', 64);
        } unset($field);
        foreach ($worst['groups'] as &$g) { $g['phase'] = 'origin_pending'; $g['mutation'] = 'committed'; $g['reason'] = str_repeat('x', 64); $g['started_at'] = PHP_INT_MAX; $g['model_ended_at'] = PHP_INT_MAX; } unset($g);
        RestWork::assertTextResponse($row, $worst);
        if (strlen(json_encode(['result' => $worst, 'receipts' => [str_repeat('f', 32) => $receipts]], JSON_THROW_ON_ERROR)) + 7 * 40 > 131072) { throw new \RuntimeException('response_limit'); }
    }

    private static function reason(\Throwable $error): string
    {
        return in_array($error->getMessage(), ['destination_limit', 'destination_validation', 'destination_changed', 'field_unavailable', 'unsupported_field', 'metadata_invalid', 'response_limit', 'policy_unavailable', 'execution_deadline'], true) ? $error->getMessage() : 'execution_unverified';
    }

    private static function connection(): int { return (int) (Outbox::one('SELECT CONNECTION_ID() AS connection_id')['connection_id'] ?? 0); }

    private static function journal(array $row): ?array
    {
        global $DB; $journal = Outbox::one('SELECT * FROM ' . TaskJournal::TABLE . ' WHERE id=' . $DB->quote($row['work_id']));
        if ($journal) { self::sameJournal($journal, $row); } return $journal;
    }

    private static function document(array $journal, array $body, array $policy): array
    {
        $document = self::decodeJson($journal['result'], 131072);
        if (array_keys($document) !== ['result', 'receipts'] || !is_array($document['receipts'])) { throw new \RuntimeException('Invalid text journal.'); }
        self::validateResult($body, $policy, $document['result']);
        if ($journal['state'] !== self::state($document['result'])) { throw new \RuntimeException('Text journal state mismatch.'); } return $document;
    }

    private static function insertJournal(array $row, array $document): void
    {
        global $DB;
        $fields = ['id' => $row['work_id'], 'hash' => $row['hash'], 'body' => $row['body'], 'state' => self::state($document['result']),
            'result' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'created_at' => time(), 'report_status' => 'rest'];
        foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) { $fields[$key] = $row[$key]; }
        if (!$DB->insert(TaskJournal::TABLE, $fields)) { throw new \RuntimeException('Text plan storage failed.'); }
    }

    private static function sameJournal(array $journal, array $row): void
    {
        if ($journal['hash'] !== $row['hash'] || $journal['body'] !== $row['body']) { throw new \RuntimeException('Text journal identity changed.'); }
    }

    private static function lockJournal(array $row, array $expected): void
    {
        global $DB; $fresh = Outbox::one('SELECT * FROM ' . TaskJournal::TABLE . ' WHERE id=' . $DB->quote($row['work_id']) . ' FOR UPDATE');
        if (!$fresh) { throw new \RuntimeException('Text journal disappeared.'); } self::sameJournal($fresh, $row);
        if ($fresh['result'] !== $expected['result'] || $fresh['state'] !== $expected['state']) { throw new \RuntimeException('Text progress changed.'); }
    }

    private static function store(array $row, array $body, array $policy, array $journal, array $document): void
    {
        global $DB; $previous = self::document($journal, $body, $policy)['result']; $document['result']['revision'] = $previous['revision'] + 1;
        self::validateProgress($body, $policy, $previous, $document['result']); $raw = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($raw) > 131072) { throw new \RuntimeException('response_limit'); } $state = self::state($document['result']);
        if (!$DB->update(TaskJournal::TABLE, ['state' => $state, 'result' => $raw], ['id' => $row['work_id'], 'hash' => $row['hash'], 'body' => $row['body'], 'state' => $journal['state'], 'result' => $journal['result']])
            || $DB->affectedRows() !== 1) { throw new \RuntimeException('Text progress CAS failed.'); }
        RestWork::projectText($row, $state, $document['result']);
    }

    private static function transactionalTables(array $pin): void
    {
        global $DB;
        $tables = ['glpi_computers', $pin['table'], 'glpi_logs', TaskJournal::TABLE, RestWork::getTable(), RestSettings::getTable(), 'glpi_plugin_assetsync20agent_restsettingsheads', 'glpi_plugin_assetsync20agent_fieldevidence'];
        if ($pin['kind'] === 'fields') {
            $tables = array_merge($tables, ['glpi_plugin_fields_fields', 'glpi_plugin_fields_containers', \PluginFieldsStatusOverride::getTable()]);
        }
        foreach (array_unique($tables) as $table) {
            $row = Outbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $DB->quote($table));
            if (strtolower($row['ENGINE'] ?? '') !== 'innodb') { throw new \RuntimeException('metadata_invalid'); }
        }
    }

    /** New bodies reject duplicate object members; retained schemas keep their existing decoder. */
    public static function decodeJson(string $json, int $limit): array
    {
        if (strlen($json) > $limit) { throw new \RuntimeException('response_limit'); }
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new \RuntimeException('Expected a JSON object.'); }
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $index => $token) {
            if ($token === '{' || $token === '[') { $stack[] = ['object' => $token === '{', 'keys' => []]; }
            elseif ($token === '}' || $token === ']') { array_pop($stack); }
            elseif ($token[0] === '"' && ($matches[0][$index + 1] ?? '') === ':') {
                $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR); $level = count($stack) - 1;
                if ($level < 0 || !$stack[$level]['object'] || isset($stack[$level]['keys'][$key])) { throw new \RuntimeException('Duplicate JSON object member.'); }
                $stack[$level]['keys'][$key] = true;
            }
        }
        return $decoded;
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) === 1;
    }

    private static function valueHash(mixed $value): string
    {
        return hash('sha256', json_encode(['type' => 'text', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
