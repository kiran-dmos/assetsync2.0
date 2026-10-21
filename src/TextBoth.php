<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Settings5/Work12 only: selected text groups and their original-edit receipts. */
final class TextBoth
{
    public static function validatePin(array $p): void
    {
        if (array_keys($p) !== ['kind', 'key', 'capture_key', 'type', 'search_id', 'definition_id', 'container_id',
            'class', 'table', 'column', 'definition_hash', 'limits']
            || !in_array($p['kind'], ['native', 'fields'], true) || !in_array($p['type'], ['text', 'textarea'], true)
            || !is_int($p['search_id']) || $p['search_id'] < 1 || !is_array($p['limits'])
            || array_keys($p['limits']) !== ['characters', 'bytes'] || !is_int($p['limits']['characters']) || $p['limits']['characters'] < 1
            || !is_int($p['limits']['bytes']) || $p['limits']['bytes'] < 1) { throw new \RuntimeException('Invalid selected text pin.'); }
        foreach (['key', 'capture_key', 'class', 'table', 'column', 'definition_hash'] as $key) {
            if (!is_string($p[$key])) { throw new \RuntimeException('Invalid selected text identity.'); }
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $p['definition_hash']) || !preg_match('/^[a-z][a-z0-9_]*$/D', $p['column'])) {
            throw new \RuntimeException('Invalid selected text identity.');
        }
        if ($p['kind'] === 'native') {
            if (!in_array($p['column'], ['name', 'comment'], true) || $p['key'] !== $p['column']
                || $p['capture_key'] !== 'native:' . $p['column'] || $p['class'] !== 'Computer' || $p['table'] !== 'glpi_computers'
                || $p['definition_id'] !== null || $p['container_id'] !== null
                || $p['type'] !== ($p['column'] === 'name' ? 'text' : 'textarea')) { throw new \RuntimeException('Unsupported native text pin.'); }
        } elseif (!is_int($p['definition_id']) || $p['definition_id'] < 1 || !is_int($p['container_id']) || $p['container_id'] < 1
            || $p['capture_key'] !== 'fields:' . $p['definition_id'] || $p['key'] !== 'Computer.' . $p['class'] . '.' . $p['column']
            || !preg_match('/^PluginFieldsComputer[a-zA-Z0-9_]+$/D', $p['class'])
            || !preg_match('/^glpi_plugin_fields_[a-z0-9_]+$/D', $p['table'])) { throw new \RuntimeException('Unsupported custom text pin.'); }
        if (($p['kind'] === 'native' && $p['column'] === 'name' || $p['kind'] === 'fields' && $p['type'] === 'text')
            && $p['limits']['characters'] > 255) { throw new \RuntimeException('Unsupported text character limit.'); }
    }

    public static function normalize(mixed $value, array $pin): string
    {
        self::validatePin($pin);
        if ($value !== null && !is_string($value)) { throw new \RuntimeException('Selected text requires a string or NULL.'); }
        $value ??= '';
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $pin['limits']['characters']
            || strlen($value) > $pin['limits']['bytes']) { throw new \RuntimeException('destination_limit'); }
        return $value;
    }

    public static function valueHash(mixed $value): string
    {
        return hash('sha256', json_encode(['type' => 'text', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function validateEnvelope(array $e, array $pin): void
    {
        if (array_keys($e) !== ['value', 'hash', 'meta', 'origin', 'guard']) { throw new \RuntimeException('Invalid selected text envelope.'); }
        self::normalize($e['value'], $pin);
        if ($e['hash'] !== self::valueHash($e['value']) || !is_string($e['meta']) || !preg_match('/^[a-f0-9]{64}$/D', $e['meta'])) {
            throw new \RuntimeException('Invalid selected text value hash.');
        }
        $o = $e['origin'];
        if ($o !== null && (!is_array($o) || array_keys($o) !== ['identity', 'revision', 'kind', 'from_epoch', 'to_epoch']
            || !is_string($o['identity']) || !preg_match('/^[a-f0-9]{32}:[a-f0-9]{32}$/D', $o['identity'])
            || !is_int($o['revision']) || $o['revision'] < 1 || $o['kind'] !== 'user'
            || !is_int($o['from_epoch']) || !is_int($o['to_epoch']) || $o['from_epoch'] < 0 || $o['to_epoch'] < $o['from_epoch'])) {
            throw new \RuntimeException('Invalid original selected field interval.');
        }
        $g = $e['guard'];
        if (!is_array($g) || array_keys($g) !== ['epoch', 'generation', 'head', 'history']
            || !is_string($g['epoch']) || !preg_match('/^[a-f0-9]{32}$/D', $g['epoch'])
            || ($g['generation'] !== null && (!is_int($g['generation']) || $g['generation'] < 0))
            || ($g['generation'] === null ? $g['head'] !== null : (!is_string($g['head']) || !preg_match('/^[a-f0-9]{64}$/D', $g['head'])))
            || !is_array($g['history']) || array_keys($g['history']) !== ['latest', 'anchor']
            || !is_int($g['history']['latest']) || $g['history']['latest'] < 0) { throw new \RuntimeException('Invalid selected field execution guard.'); }
        $anchor = $g['history']['anchor'];
        if ($anchor !== null && (!is_array($anchor) || array_keys($anchor) !== ['id', 'hash'] || !is_int($anchor['id']) || $anchor['id'] < 1
            || !is_string($anchor['hash']) || !preg_match('/^[a-f0-9]{64}$/D', $anchor['hash']))) { throw new \RuntimeException('Invalid selected field History guard.'); }
        if (($anchor['id'] ?? 0) !== $g['history']['latest'] || ($o !== null && ($anchor === null || $g['head'] === null))) {
            throw new \RuntimeException('Incomplete original selected field evidence.');
        }
    }

    public static function decide(array $a, array $b, array $mapping): string
    {
        if (!in_array(array_keys($mapping), [['source_of_truth', 'a', 'b'], ['mapping_id', 'source_of_truth', 'a', 'b']], true) || $mapping['source_of_truth'] !== 'both'
            || !is_array($mapping['a']) || !is_array($mapping['b'])) { throw new \RuntimeException('Invalid selected Both mapping.'); }
        self::validateEnvelope($a, $mapping['a']); self::validateEnvelope($b, $mapping['b']);
        if (self::normalize($a['value'], $mapping['a']) === self::normalize($b['value'], $mapping['b'])) { return 'equal'; }
        if ($a['origin'] === null || $b['origin'] === null) { return 'conflict'; }
        if ($a['origin']['from_epoch'] > $b['origin']['to_epoch']) { return 'a'; }
        if ($b['origin']['from_epoch'] > $a['origin']['to_epoch']) { return 'b'; }
        return 'conflict';
    }

    public static function mappingId(array $a, array $b): string
    {
        return substr(AgentTask::hash(['text-row', $a, $b]), 0, 32);
    }

    public static function groupId(string $workId, string $side, array $pin): string
    {
        return substr(AgentTask::hash(['text-group', $workId, $side, $pin['kind'], $pin['container_id']]), 0, 32);
    }

    public static function validateMappings(array $mappings): void
    {
        if (!array_is_list($mappings) || count($mappings) < 1 || count($mappings) > 8) { throw new \RuntimeException('Text processing requires one to eight complete rows.'); }
        $last = ''; $seen = ['a' => [], 'b' => []];
        foreach ($mappings as $m) {
            if (!is_array($m) || array_keys($m) !== ['mapping_id', 'source_of_truth', 'a', 'b'] || $m['source_of_truth'] !== 'both') { throw new \RuntimeException('Invalid complete text mapping.'); }
            self::validatePin($m['a']); self::validatePin($m['b']);
            if ($m['mapping_id'] !== self::mappingId($m['a'], $m['b']) || strcmp($m['mapping_id'], $last) <= 0) { throw new \RuntimeException('Text rows are not uniquely sorted.'); }
            $last = $m['mapping_id'];
            foreach (['a', 'b'] as $side) {
                $pin = $m[$side]; $physical = AgentTask::hash([$pin['table'], $pin['container_id'], $pin['column']]);
                if (isset($seen[$side][$physical]) || isset($seen[$side][$pin['capture_key']])) { throw new \RuntimeException('Duplicate text endpoint.'); }
                $seen[$side][$physical] = $seen[$side][$pin['capture_key']] = true;
            }
        }
    }

    public static function validateSources(array $sources, array $mappings): void
    {
        self::validateMappings($mappings);
        if (!array_is_list($sources) || count($sources) !== count($mappings)) { throw new \RuntimeException('Incomplete selected source coverage.'); }
        foreach ($mappings as $i => $m) {
            if (!is_array($sources[$i]) || array_keys($sources[$i]) !== ['mapping_id', 'envelope'] || $sources[$i]['mapping_id'] !== $m['mapping_id']) {
                throw new \RuntimeException('Source mapping order changed.');
            }
            self::validateEnvelope($sources[$i]['envelope'], $m['a']);
        }
    }

    public static function sameEvidence(array $a, array $b): bool
    {
        return $a['hash'] === $b['hash'] && $a['meta'] === $b['meta'] && $a['origin'] === $b['origin'];
    }

    public static function fingerprint(array $policy, array $identity, array $sources): string
    {
        self::validateSources($sources, $policy['mappings']); $facts = [];
        foreach ($sources as $source) {
            $e = $source['envelope']; $facts[] = [$source['mapping_id'], $e['hash'], $e['meta'], $e['origin']];
        }
        return AgentTask::hash([AgentTask::hash($policy), $identity, $facts]);
    }

    /** The public plan accounts for EVERY field, including unavailable or conflicting fields. */
    public static function validateResult(array $work, array $policy, array $r): void
    {
        self::validateSources($work['sources'], $policy['mappings']);
        if (array_keys($r) !== ['revision', 'source_hash', 'b_complete', 'fields', 'groups'] || !is_int($r['revision']) || $r['revision'] < 1 || $r['revision'] > 25
            || $r['source_hash'] !== $work['source_hash'] || $work['source_hash'] !== AgentTask::hash($work['sources']) || !is_bool($r['b_complete'])
            || !is_array($r['fields']) || !array_is_list($r['fields']) || count($r['fields']) !== count($policy['mappings'])
            || !is_array($r['groups']) || !array_is_list($r['groups']) || count($r['groups']) > 8) { throw new \RuntimeException('Invalid complete text progress.'); }
        $members = []; $groupPins = []; $fields = [];
        foreach ($policy['mappings'] as $i => $m) {
            $f = $r['fields'][$i]; $source = $work['sources'][$i]['envelope'];
            if (!is_array($f) || array_keys($f) !== ['mapping_id', 'source_hash', 'winner', 'outcome', 'mutation', 'before', 'after', 'group_id', 'reason']
                || $f['mapping_id'] !== $m['mapping_id'] || $f['source_hash'] !== AgentTask::hash($source)
                || !in_array($f['winner'], ['a', 'b', 'equal', 'conflict', null], true)
                || !in_array($f['outcome'], ['pending', 'intent', 'origin_pending', 'verified', 'offered', 'unchanged', 'conflict', 'blocked', 'uncertain'], true)
                || !in_array($f['mutation'], ['unstarted', 'none', 'committed', 'unknown'], true) || !self::reason($f['reason'])) { throw new \RuntimeException('Invalid per-field text fact.'); }
            if ($f['before'] !== null) { self::validateEnvelope($f['before'], $m['b']); }
            if ($f['after'] !== null) { self::validateEnvelope($f['after'], $m['b']); }
            if ($f['winner'] === null) {
                if ($f['before'] !== null || $f['after'] !== null || $f['group_id'] !== null || $f['outcome'] !== 'blocked'
                    || $f['mutation'] !== 'none' || $f['reason'] === null) { throw new \RuntimeException('Failed preparation must be explicit, not blank.'); }
            } else {
                if ($f['before'] === null || self::decide($source, $f['before'], $m) !== $f['winner']) { throw new \RuntimeException('Per-field winner does not match original evidence.'); }
                if (in_array($f['winner'], ['equal', 'conflict'], true)) {
                    if ($f['group_id'] !== null || $f['after'] !== $f['before'] || $f['mutation'] !== 'none'
                        || $f['outcome'] !== ($f['winner'] === 'equal' ? 'unchanged' : 'conflict') || $f['reason'] !== null) { throw new \RuntimeException('Read-only text decision changed.'); }
                } else {
                    $side = $f['winner'] === 'a' ? 'b' : 'a'; $pin = $m[$side]; $id = self::groupId($work['id'], $side, $pin);
                    if ($f['group_id'] !== $id) { throw new \RuntimeException('Wrong text destination group.'); }
                    $members[$id][] = $m['mapping_id']; $groupPins[$id] = [$side, $pin];
                    if ($f['winner'] === 'b' && ($f['after'] !== $f['before'] || !in_array($f['outcome'], ['offered', 'blocked'], true) || $f['mutation'] !== 'none')) {
                        throw new \RuntimeException('B offer must not claim an A mutation.');
                    }
                    if ($f['outcome'] === 'verified') {
                        if ($f['winner'] !== 'a' || $f['mutation'] !== 'committed' || $f['after'] === null
                            || $f['after']['value'] !== self::normalize($source['value'], $m['b'])
                            || $f['after']['origin'] !== $source['origin'] || $f['after']['meta'] !== $f['before']['meta']) { throw new \RuntimeException('B inherited field is not verified.'); }
                    }
                }
            }
            $fields[$m['mapping_id']] = $f;
        }
        $last = ''; $bComplete = true;
        foreach ($r['groups'] as $g) {
            if (!is_array($g) || array_keys($g) !== ['id', 'side', 'kind', 'container_id', 'members', 'phase', 'mutation', 'reason', 'started_at', 'model_ended_at']
                || !isset($members[$g['id']]) || strcmp($g['id'], $last) <= 0
                || !in_array($g['phase'], ['pending', 'intent', 'origin_pending', 'done', 'offered', 'blocked', 'uncertain'], true)
                || !in_array($g['mutation'], ['unstarted', 'none', 'committed', 'unknown'], true) || !self::reason($g['reason'])) { throw new \RuntimeException('Invalid text group progress.'); }
            $last = $g['id']; [$side, $pin] = $groupPins[$g['id']];
            if ($g['side'] !== $side || $g['kind'] !== $pin['kind'] || $g['container_id'] !== $pin['container_id'] || $g['members'] !== $members[$g['id']]) { throw new \RuntimeException('Text atomic membership changed.'); }
            $mutation = ['pending' => 'unstarted', 'intent' => 'unknown', 'origin_pending' => 'committed', 'done' => 'committed', 'offered' => 'none', 'blocked' => 'none', 'uncertain' => 'unknown'][$g['phase']];
            if ($g['mutation'] !== $mutation || (in_array($g['phase'], ['blocked','uncertain'], true) ? $g['reason'] === null : $g['reason'] !== null)) { throw new \RuntimeException('Invalid text group mutation or reason.'); }
            foreach (['started_at', 'model_ended_at'] as $key) {
                if ($g[$key] !== null && (!is_int($g[$key]) || $g[$key] < $work['expires'] - 300 || $g[$key] > $work['expires'])) { throw new \RuntimeException('Invalid text model timing.'); }
            }
            if ((in_array($g['phase'], ['intent','origin_pending','done','uncertain'], true) && $g['started_at'] === null)
                || (in_array($g['phase'], ['origin_pending','done'], true) && $g['model_ended_at'] === null)
                || ($g['model_ended_at'] !== null && ($g['started_at'] === null || $g['model_ended_at'] < $g['started_at']))
                || (in_array($g['phase'], ['pending','offered'], true) && ($g['started_at'] !== null || $g['model_ended_at'] !== null))) { throw new \RuntimeException('Invalid text model interval.'); }
            if ($side === 'a' && (!in_array($g['phase'], ['offered', 'blocked'], true) || $g['mutation'] !== 'none')) { throw new \RuntimeException('B cannot complete A groups.'); }
            if ($side === 'b' && $g['phase'] === 'offered') { throw new \RuntimeException('B destination cannot be an A offer.'); }
            if ($side === 'b') {
                if (!in_array($g['phase'], ['done', 'blocked'], true)) { $bComplete = false; }
                if ($g['phase'] === 'done' && ($g['mutation'] !== 'committed' || $g['started_at'] === null || $g['model_ended_at'] === null)) { throw new \RuntimeException('Incomplete B group commit evidence.'); }
            }
            foreach ($g['members'] as $id) {
                $expected = $side === 'a' ? ($g['phase'] === 'offered' ? 'offered' : 'blocked') : ['done' => 'verified'][$g['phase']] ?? $g['phase'];
                $field = $fields[$id];
                if ($field['outcome'] !== $expected || $field['mutation'] !== $g['mutation'] || $field['reason'] !== $g['reason']) { throw new \RuntimeException('Field and group progress disagree.'); }
                if (in_array($g['phase'], ['offered','blocked'], true)) {
                    if ($field['after'] !== $field['before']) { throw new \RuntimeException('Read-only group changed field evidence.'); }
                    if ($g['phase'] === 'offered') { self::normalize($field['before']['value'], $pin); }
                } elseif ($g['phase'] !== 'done' && $field['after'] !== null) { throw new \RuntimeException('Unqualified text after evidence.'); }
            }
            unset($members[$g['id']]);
        }
        if ($members !== [] || $r['b_complete'] !== $bComplete) { throw new \RuntimeException('Text progress omitted a group.'); }
        if (strlen(json_encode($r, JSON_THROW_ON_ERROR)) > 60000) { throw new \RuntimeException('Text progress exceeds its bound.'); }
    }

    private static function reason(mixed $reason): bool
    {
        return $reason === null || (is_string($reason) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason));
    }

    public static function validateProgress(array $work, array $policy, array $previous, array $next): void
    {
        self::validateResult($work, $policy, $next);
        if ($previous === []) { if ($next['revision'] < 1) { throw new \RuntimeException('Missing text plan.'); } return; }
        self::validateResult($work, $policy, $previous);
        if ($next['revision'] === $previous['revision']) {
            if ($next !== $previous) { throw new \RuntimeException('Same text revision changed.'); } return;
        }
        if ($next['revision'] < $previous['revision'] || count($next['groups']) !== count($previous['groups'])) { throw new \RuntimeException('Text revision regressed or group coverage changed.'); }
        $paths = ['pending' => ['pending','intent','origin_pending','done','blocked','uncertain'], 'intent' => ['intent','origin_pending','done','blocked','uncertain'],
            'origin_pending' => ['origin_pending','done'], 'done' => ['done'], 'offered' => ['offered'], 'blocked' => ['blocked'], 'uncertain' => ['uncertain']];
        $transitions = 0;
        foreach ($previous['groups'] as $i => $old) {
            $new = $next['groups'][$i] ?? [];
            foreach (['id','side','kind','container_id','members'] as $key) { if (($new[$key] ?? null) !== $old[$key]) { throw new \RuntimeException('Text group plan changed.'); } }
            if (!in_array($new['phase'], $paths[$old['phase']], true)
                || ($old['phase'] === $new['phase'] && $old !== $new)) { throw new \RuntimeException('Text group facts regressed.'); }
            if ($old['phase'] !== $new['phase']) {
                $transitions += ['pending' => ['intent' => 1, 'origin_pending' => 2, 'done' => 3, 'blocked' => $new['started_at'] === null ? 1 : 2, 'uncertain' => 2],
                    'intent' => ['origin_pending' => 1, 'done' => 2, 'blocked' => 1, 'uncertain' => 1], 'origin_pending' => ['done' => 1]][$old['phase']][$new['phase']];
            }
            foreach (['started_at','model_ended_at'] as $key) { if ($old[$key] !== null && $old[$key] !== $new[$key]) { throw new \RuntimeException('Text model timing changed.'); } }
        }
        if (count($next['groups']) !== count($previous['groups'])) { throw new \RuntimeException('Text group coverage changed.'); }
        foreach ($previous['fields'] as $i => $old) {
            $new = $next['fields'][$i];
            foreach (['mapping_id','source_hash','winner','before','group_id'] as $key) { if ($new[$key] !== $old[$key]) { throw new \RuntimeException('Text field plan changed.'); } }
            if (in_array($old['outcome'], ['verified','offered','unchanged','conflict','blocked','uncertain'], true) && $new !== $old) { throw new \RuntimeException('Stable text facts changed.'); }
            if ($old['after'] !== null && $new['after'] !== $old['after']) { throw new \RuntimeException('Recorded text after changed.'); }
        }
        if ($transitions === 0 || $next['revision'] - $previous['revision'] !== $transitions) { throw new \RuntimeException('Unexplained text progress revision.'); }
    }

    public static function stable(array $field): bool
    {
        return in_array($field['outcome'], ['verified','offered','unchanged','conflict','blocked','uncertain'], true);
    }

    public static function validateReadObservation(array $result, array $obs): void
    {
        if (array_keys($obs) !== ['revision','receipt_hash','fields'] || $obs['revision'] !== $result['revision'] || $obs['receipt_hash'] !== AgentTask::hash($result)
            || !is_array($obs['fields']) || !array_is_list($obs['fields']) || count($obs['fields']) !== count($result['fields'])) { throw new \RuntimeException('Invalid complete text observation.'); }
        foreach ($result['fields'] as $i => $field) {
            $o = $obs['fields'][$i];
            if (!is_array($o) || array_keys($o) !== ['mapping_id','receipt_hash','current_matches'] || $o['mapping_id'] !== $field['mapping_id']
                || $o['receipt_hash'] !== (self::stable($field) ? AgentTask::hash($field) : null)
                || (!is_bool($o['current_matches']) && $o['current_matches'] !== null)
                || ((!self::stable($field) || $field['after'] === null || $field['outcome'] === 'uncertain') && $o['current_matches'] !== null)) { throw new \RuntimeException('Invalid per-field observation.'); }
        }
    }

    private static function policyContext(array $pair): array
    {
        global $DB;
        if (!AgentWake::transactionIdle()) {
            // Shared current policy reads do not serialize unrelated asset writers with each other.
            AgentInbox::sql('SELECT name,value FROM `glpi_configs` WHERE context=' . $DB->quote(GlpiBConnection::CONTEXT)
                . " AND name IN ('rest_settings_v2','field_mappings','glpib_connections','entity_sync_routes') LOCK IN SHARE MODE");
            $currentPair = AgentInbox::one('SELECT * FROM ' . AgentTask::PAIRS . ' WHERE id=' . $DB->quote($pair['id']) . ' FOR UPDATE');
            if ($currentPair !== $pair) { throw new \RuntimeException('Selected confirmed pair changed.'); }
            AgentInbox::sql('SELECT id FROM ' . AgentInbox::REGISTRATIONS . ' WHERE id=' . $DB->quote($pair['registration']) . ' LOCK IN SHARE MODE');
            AgentInbox::sql('SELECT * FROM ' . AssetSyncLink::TABLE . ' WHERE glpi_b_connection_id=' . $DB->quote($pair['connection_id'])
                . ' AND itemtype=\'Computer\' AND (items_id=' . (int) $pair['local_id'] . ' OR remote_items_id=' . (int) $pair['computer_id'] . ') FOR UPDATE');
            AgentInbox::sql('SELECT * FROM glpi_computers WHERE id=' . (int) $pair['local_id'] . ' FOR UPDATE');
        }
        $scope = AgentTask::scope($pair);
        if ($pair['itemtype'] !== 'Computer' || ($scope['connection']['agent_transport'] ?? '') !== 'rest_records') { throw new \RuntimeException('Selected Both scope unavailable.'); }
        FieldMapping::endRunCache(); $mappings = FieldMapping::textMappings(FieldMapping::load($pair['connection_id'], 'Computer'));
        if (!$mappings) { throw new \RuntimeException('Explicit selected Both mappings required.'); }
        $state = json_decode(\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2'])['rest_settings_v2'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        $key = AgentTask::hash(['installation' => $pair['installation'], 'registration' => $pair['registration'], 'generation' => $scope['registration']['generation'],
            'connection_id' => $pair['connection_id'], 'route_id' => $pair['route_id']]);
        $entry = $state[$key] ?? null; $policy = $entry ? json_decode($entry['body'], true, 32, JSON_THROW_ON_ERROR) : null;
        $types = $scope['route']['asset_types']; sort($types, SORT_STRING);
        if (!$policy || $policy['version'] !== 5 || !$policy['enabled'] || $entry['pending'] || $entry['hash'] !== AgentTask::hash($policy)
            || $policy['mappings'] !== $mappings || $policy['source_entity'] !== (int) $scope['route']['glpi_a_source_entity_id']
            || $policy['target_entity'] !== (int) $pair['entity_id'] || $policy['include_child_entities'] !== $scope['route']['include_child_entities']
            || $policy['asset_types'] !== $types) { throw new \RuntimeException('Selected Both policy changed or publication pending.'); }
        self::validateMappings($mappings);
        FieldMapping::validateOwnership($pair['connection_id'], 'Computer', array_map(static fn ($m) => [
            'glpi_a_field' => $m['a']['key'], 'glpi_b_field' => $m['b']['key'], 'source_of_truth' => 'both'], $mappings), $scope['asset']);
        $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
        $identity = !empty($pair['identity_basis']) ? json_decode($pair['identity_basis'], true, 32, JSON_THROW_ON_ERROR)['b']
            : (array_key_exists('otherserial', $snapshot) ? AgentTask::identity($snapshot) : ['serial' => $pair['serial']]);
        return $scope + ['settings' => $policy, 'identity' => $identity];
    }

    private static function readSource(array $pair, array $mapping): array
    {
        $selected = FieldsText::readSelectedText($mapping['a'], (int) $pair['local_id'], (int) $pair['local_entity']);
        $e = FieldEvidence::envelope((int) $pair['local_id'], $mapping['a']['capture_key']);
        self::validateEnvelope($e, $mapping['a']);
        if ($e['value'] !== $selected['value']) { throw new \RuntimeException('Selected source changed during read.'); }
        return $e;
    }

    public static function context(array $pair, ?array $captured = null): array
    {
        $scope = self::policyContext($pair); $sources = [];
        if ($captured !== null) { self::validateSources($captured, $scope['settings']['mappings']); }
        foreach ($scope['settings']['mappings'] as $i => $m) {
            $e = $captured[$i]['envelope'] ?? self::readSource($pair, $m);
            if ($captured !== null) {
                $selected = FieldsText::readSelectedText($m['a'], (int) $pair['local_id'], (int) $pair['local_entity']);
                if ($selected['value'] !== $e['value']) { throw new \RuntimeException('Selected guarded source changed.'); }
            }
            $sources[] = ['mapping_id' => $m['mapping_id'], 'envelope' => $e];
        }
        return $scope + ['evidence' => $sources, 'source_hash' => AgentTask::hash($sources),
            'fingerprint' => self::fingerprint($scope['settings'], AgentTask::identity($scope['asset']), $sources)];
    }

    public static function workBody(array $pair, array $context): array
    {
        return ['version' => 12, 'id' => bin2hex(random_bytes(16)), 'installation' => $pair['installation'], 'registration' => $pair['registration'],
            'generation' => $context['registration']['generation'], 'connection_id' => $pair['connection_id'], 'settings_id' => $context['settings']['id'],
            'settings_hash' => AgentTask::hash($context['settings']), 'pair_id' => $pair['id'], 'itemtype' => 'Computer',
            'source_items_id' => (int) $pair['local_id'], 'source_asset_entity' => (int) $pair['local_entity'],
            'items_id' => (int) $pair['computer_id'], 'entity_id' => (int) $pair['entity_id'], 'identity' => $context['identity'],
            'sources' => $context['evidence'], 'source_hash' => $context['source_hash'], 'source_fingerprint' => $context['fingerprint'],
            'expires' => time() + 300, 'batch_id' => bin2hex(random_bytes(16))];
    }

    public static function assertSource(array $pair, array $body, array $captured, ?array $preparingPolicy = null): array
    {
        $fresh = self::context($pair, $captured);
        if ($body['installation'] !== $pair['installation'] || $body['registration'] !== $pair['registration'] || $body['connection_id'] !== $pair['connection_id']
            || $body['generation'] !== $fresh['registration']['generation'] || $body['settings_id'] !== $fresh['settings']['id']
            || $body['settings_hash'] !== AgentTask::hash($fresh['settings']) || $body['pair_id'] !== $pair['id'] || $body['source_items_id'] !== (int) $pair['local_id']
            || $body['source_asset_entity'] !== (int) $pair['local_entity'] || $body['items_id'] !== (int) $pair['computer_id']
            || $body['entity_id'] !== (int) $pair['entity_id'] || $body['identity'] !== $fresh['identity']) { throw new \RuntimeException('Text work scope or policy changed.'); }
        foreach ($fresh['settings']['mappings'] as $i => $m) { FieldEvidence::assertGuard((int) $pair['local_id'], $m['a']['capture_key'], $captured[$i]['envelope']); }
        return $fresh;
    }

    /** Restrictive continuation mask: a changed source can close only never-started B groups. */
    public static function allowRows(array $pair, array $body, array $policy): array
    {
        $scope = self::policyContext($pair);
        if (AgentTask::hash($scope['settings']) !== $body['settings_hash'] || $scope['identity'] !== $body['identity']) { return []; }
        $allowed = [];
        foreach ($policy['mappings'] as $i => $m) {
            try { $e = self::readSource($pair, $m); }
            catch (\RuntimeException) { continue; }
            if (self::sameEvidence($e, $body['sources'][$i]['envelope'])) { $allowed[] = $m['mapping_id']; }
        }
        return $allowed;
    }

    /** Only a fully accounted partial can be retried; uncertainty is never absence. */
    public static function partialRetryDue(array $task, array $pair): bool
    {
        $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
        if ($task['state'] !== 'partial' || !self::knownPartial($task)) { return false; }
        if (($c['rest_next'] ?? PHP_INT_MAX) <= time()) { return true; }
        try { return self::context($pair)['fingerprint'] !== ($c['text_retry_fingerprint'] ?? null); }
        catch (\RuntimeException) { return true; }
    }

    public static function knownPartial(array $task): bool
    {
        try {
            $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR); $b = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            $r = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            self::validateResult($b, $c['settings'], $r);
            if (isset($c['text_billing']) && ($c['text_billing']['phase'] ?? null) !== 'done') { return false; }
            if (!$r['b_complete']) { return false; }
            foreach ($r['groups'] as $g) {
                if ($g['side'] === 'b' && !in_array($g['phase'], ['done','blocked'], true)) { return false; }
                if ($g['side'] === 'a' && $g['phase'] === 'offered' && !in_array($c['text_groups'][$g['id']]['phase'] ?? '', ['done','blocked'], true)) { return false; }
            }
            foreach ($c['text_groups'] ?? [] as $g) { if (!in_array($g['phase'], ['done','blocked'], true)) { return false; } }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Reprepare known partials only in an ordinary owned claim, preserving every old receipt. */
    public static function replacePartial(array $task, array $job, array $pair, array $fresh): void
    {
        global $DB;
        if (!self::partialRetryDue($task, $pair)) { throw new \RuntimeException('Text partial is not eligible for replacement.'); }
        $body = self::workBody($pair, $fresh); $json = self::bodyJson($body); $hash = AgentTask::hash($body);
        RestWork::configure(static function () use ($task, $job, $pair, $fresh, $body, $json, $hash, $DB): void {
            self::lockTask($task, $job);
            if (!self::knownPartial($task)) { throw new \RuntimeException('Text partial changed.'); }
            $lockedPair = AgentInbox::one('SELECT * FROM ' . AgentTask::PAIRS . ' WHERE id=' . $DB->quote($pair['id']) . ' FOR UPDATE');
            if ($lockedPair !== $pair) { throw new \RuntimeException('Text confirmed pair changed.'); }
            self::assertSource($pair, $body, $fresh['evidence'], $fresh['settings']);
            $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR); $c['superseded_by'] = $body['id'];
            $old = $task; self::saveTask($old, ['state' => 'superseded', 'slot' => null, 'context' => json_encode($c, JSON_THROW_ON_ERROR)]);
            AgentTask::write($DB->insert(AgentTask::TABLE, ['id' => $body['id'], 'connection_id' => $pair['connection_id'], 'slot' => $task['slot'],
                'body' => $json, 'hash' => $hash, 'state' => 'accepted', 'result' => '{}', 'queue_id' => $job['id'], 'job' => json_encode($job, JSON_THROW_ON_ERROR),
                'context' => json_encode(['settings' => $fresh['settings'], 'rest_next' => 0, 'rest_sent' => false, 'rest_lease' => '', 'rest_until' => 0], JSON_THROW_ON_ERROR),
                'created_at' => time(), 'expires' => $body['expires']]));
            AgentTask::write(AssetSyncQueue::waitForAgent($job));
        }, true);
    }

    public static function bodyJson(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 24576 || strlen(json_encode(['session_write' => false, 'input' => [['work_id' => $body['id'],
            'hash' => AgentTask::hash($body), 'body' => $json, 'allow_rows' => array_column($body['sources'], 'mapping_id')]]], JSON_THROW_ON_ERROR)) > 49152) {
            throw new \RuntimeException('Complete text work exceeds its byte budget.');
        }
        return $json;
    }

    /** One GET authorizes one claimed pass for all independent destination groups. */
    public static function finish(array $task, array $job, array $pair, array $context, array $body, array $result, array $observation, ?callable $afterInbound = null): void
    {
        global $DB;
        $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR); $policy = $c['settings'];
        self::validateResult($body, $policy, $result); self::validateReadObservation($result, $observation);
        if ($task['hash'] !== AgentTask::hash($body)) { throw new \RuntimeException('Immutable selected work changed.'); }
        $scope = self::policyContext($pair);
        if ($scope['identity'] !== $body['identity']) { throw new \RuntimeException('Selected current identity changed.'); }
        if (AgentTask::hash($scope['settings']) !== $body['settings_hash']) {
            // An accounted no-write plan can be replaced, but no old-policy group may execute here.
            if ($result['groups'] === [] && empty($c['text_groups']) && self::knownPartial($task)) {
                $c['text_retry_fingerprint'] = $body['source_fingerprint']; $c['rest_next'] = 0;
                self::persist($task, $job, $c, 'partial'); self::park($task, $job, 'waiting_agent'); return;
            }
            throw new \RuntimeException('Selected current policy changed.');
        }
        if (($c['text_observation_ready'] ?? null) !== true) { self::park($task, $job, 'waiting_agent'); return; }
        self::storageBudget($c);
        $c['text_observation_ready'] = false;
        $c['text_readiness'] = ['revision' => $result['revision'], 'hash' => AgentTask::hash($result), 'claim' => $job['claim_token'], 'used' => []];
        self::persist($task, $job, $c);
        $fields = array_column($result['fields'], null, 'mapping_id'); $observed = array_column($observation['fields'], null, 'mapping_id');
        $mappings = array_column($policy['mappings'], null, 'mapping_id'); $sources = array_column($body['sources'], 'envelope', 'mapping_id');
        $deadline = hrtime(true) + 5_000_000_000; $yielded = false;
        foreach ($result['groups'] as $group) {
            if ($group['side'] !== 'a' || $group['phase'] !== 'offered') { continue; }
            $id = $group['id']; $local = $c['text_groups'][$id] ?? ['phase' => 'pending', 'reason' => null, 'receipts' => [], 'after' => []];
            if (in_array($local['phase'], ['done','blocked','uncertain'], true)) { continue; }
            if ($local['phase'] === 'intent') {
                $local['phase'] = 'uncertain'; $local['reason'] = 'interrupted_local_intent'; $c['text_groups'][$id] = $local;
                self::persist($task, $job, $c); continue;
            }
            if (hrtime(true) >= $deadline) { $yielded = true; break; }
            // An already committed local receipt is proved locally, not by B's later current value.
            if ($local['phase'] === 'origin_pending') { self::settleGroup($task, $job, $c, $id); continue; }
            $current = true;
            foreach ($group['members'] as $member) { if ($observed[$member]['current_matches'] !== true) { $current = false; break; } }
            if (!$current) {
                $knownChanged = (bool) array_filter($group['members'], static fn ($member): bool => $observed[$member]['current_matches'] === false);
                if ($knownChanged) { $local['phase'] = 'blocked'; $local['reason'] = 'remote_changed'; $c['text_groups'][$id] = $local; self::persist($task, $job, $c); }
                continue;
            }
            $c['text_readiness']['used'][] = $id;
            $before = []; $changes = []; $origins = []; $failure = null;
            try {
                foreach ($group['members'] as $member) {
                    $m = $mappings[$member]; $e = self::readSource($pair, $m);
                    if (!self::sameEvidence($e, $sources[$member])) { throw new \RuntimeException('source_superseded'); }
                    $value = self::normalize($fields[$member]['after']['value'], $m['a']);
                    $before[$member] = $e; $changes[] = ['pin' => $m['a'], 'value' => $value];
                    $origins[] = ['key' => $m['a']['capture_key'], 'before_hash' => $e['hash'], 'after_hash' => self::valueHash($value), 'origin' => $fields[$member]['after']['origin']];
                }
            } catch (\RuntimeException) { $failure = 'source_or_destination_changed'; }
            if ($failure !== null) {
                $local['phase'] = 'blocked'; $local['reason'] = $failure; $c['text_groups'][$id] = $local; self::persist($task, $job, $c); continue;
            }
            self::transactionalTables($changes);
            $local['phase'] = 'intent'; $c['text_groups'][$id] = $local; self::persist($task, $job, $c);
            $intentTask = $task;
            $commitAttempted = false; $ownedDb = $DB;
            self::start();
            $ownedConnection = AgentInbox::one('SELECT CONNECTION_ID() AS connection_id')['connection_id'] ?? null;
            $savepoint = 'as20_text_' . bin2hex(random_bytes(12));
            try {
                AgentInbox::sql('SAVEPOINT ' . $savepoint);
                self::lockTask($task, $job);
                $fresh = self::policyContext($pair);
                if (AgentTask::hash($fresh['settings']) !== $body['settings_hash'] || $fresh['identity'] !== $body['identity']) { throw new \RuntimeException('Current selected policy changed.'); }
                foreach ($group['members'] as $member) { FieldEvidence::assertGuard((int) $pair['local_id'], $mappings[$member]['a']['capture_key'], $before[$member]); }
                $receipts = [];
                AssetChangeHook::withoutQueue(static function () use (&$receipts, $pair, $changes, $origins, $group, $mappings, $fields): bool {
                    FieldEvidence::withOrigins((int) $pair['local_id'], $origins, static function () use (&$receipts, $pair, $changes, $group, $mappings, $fields): void {
                        FieldsText::updateSelectedTextGroup($changes, (int) $pair['local_id'], (int) $pair['local_entity']);
                        foreach ($group['members'] as $member) {
                            $receipts[$member] = FieldEvidence::inheritedReceipt((int) $pair['local_id'], $mappings[$member]['a']['capture_key'], $fields[$member]['after']['origin']);
                        }
                    });
                    return true;
                });
                $fresh = self::policyContext($pair);
                if (AgentTask::hash($fresh['settings']) !== $body['settings_hash'] || $fresh['identity'] !== $body['identity']) { throw new \RuntimeException('Selected policy changed after model write.'); }
                $c['text_groups'][$id] = ['phase' => 'origin_pending', 'reason' => null, 'receipts' => $receipts, 'after' => []];
                self::saveTask($task, ['context' => json_encode($c, JSON_THROW_ON_ERROR), 'state' => 'origin_pending']);
                if ($DB !== $ownedDb || (AgentInbox::one('SELECT CONNECTION_ID() AS connection_id')['connection_id'] ?? null) !== $ownedConnection) {
                    throw new \RuntimeException('Selected model connection ownership changed.');
                }
                // Fails if a wrapper implicitly ended our TX. Never COMMIT a replacement/foreign TX.
                AgentInbox::sql('RELEASE SAVEPOINT ' . $savepoint);
                $commitAttempted = true; AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) {
                $sameConnection = $DB === $ownedDb
                    && (AgentInbox::one('SELECT CONNECTION_ID() AS connection_id')['connection_id'] ?? null) === $ownedConnection;
                $rollbackFence = false; $rolledBack = false;
                if (!$commitAttempted && $sameConnection) {
                    try {
                        $rollbackFence = $DB->doQuery('ROLLBACK TO SAVEPOINT ' . $savepoint) !== false;
                        if ($rollbackFence) { $rolledBack = $DB->doQuery('ROLLBACK') !== false; }
                    } catch (\Throwable) { $rolledBack = false; }
                }
                if ($commitAttempted) {
                    // COMMIT may have succeeded. Only a durable exact receipt can resolve it.
                    if (!$sameConnection || !AgentWake::transactionIdle()) { throw $error; }
                    $saved = AgentInbox::one('SELECT * FROM ' . AgentTask::TABLE . ' WHERE id=' . $DB->quote($task['id']));
                    if ($saved && $saved['body'] === $task['body'] && $saved['result'] === $task['result'] && $saved['context'] === $task['context']) { $task = $saved; }
                    else { throw $error; }
                } elseif ($sameConnection && $rollbackFence && $rolledBack && AgentWake::transactionIdle()
                    && (AgentInbox::one('SELECT CONNECTION_ID() AS connection_id')['connection_id'] ?? null) === $ownedConnection) {
                    $task = AgentInbox::one('SELECT * FROM ' . AgentTask::TABLE . ' WHERE ' . self::fence($intentTask));
                    if (!$task) { throw $error; }
                    $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
                    $c['text_groups'][$id] = ['phase' => 'blocked', 'reason' => 'destination_validation', 'receipts' => [], 'after' => []];
                    self::persist($task, $job, $c);
                } else { throw $error; }
            } finally { FieldEvidence::finishWork(); }
            $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            if (($c['text_groups'][$id]['phase'] ?? '') === 'origin_pending') { self::settleGroup($task, $job, $c, $id); }
        }
        // Re-read ALL fields before overall completion. No partial success advances pair/link evidence.
        $complete = $result['b_complete']; $unresolved = false; $remotePending = !$result['b_complete']; $pendingLocal = $yielded; $expectedSources = [];
        foreach ($result['fields'] as $field) {
            $id = $field['mapping_id']; $m = $mappings[$id]; $local = $c['text_groups'][$field['group_id']] ?? null;
            if ($field['winner'] === 'b' && ($local['phase'] ?? '') === 'done') { $expected = $local['after'][$id]; }
            elseif (in_array($field['outcome'], ['verified','unchanged'], true)) { $expected = $sources[$id]; }
            else { $complete = false; $expected = null; }
            if ($expected !== null) {
                $expectedSources[$id] = $expected;
                try { $current = self::readSource($pair, $m); $matches = self::sameEvidence($expected, $current); }
                catch (\RuntimeException) { $matches = false; }
                if (!$matches || $observed[$id]['current_matches'] !== true) { $complete = false; if ($observed[$id]['current_matches'] === null) { $unresolved = true; } }
            } elseif (($local['phase'] ?? '') === 'pending') { $pendingLocal = true; }
            if (($field['mutation'] ?? '') === 'unknown' || in_array($local['phase'] ?? '', ['intent','origin_pending','uncertain'], true)) { $unresolved = true; }
            if (in_array($field['outcome'], ['offered','unchanged','conflict','verified'], true)
                && ($local['phase'] ?? '') !== 'blocked' && $observed[$id]['current_matches'] === null) { $unresolved = true; }
        }
        // Billing never replays inbound values. A pending marker survives callback failure/crash.
        if (!$unresolved && !$pendingLocal && !$remotePending && $afterInbound !== null
            && ($c['text_billing']['phase'] ?? null) !== 'done') {
            $billingScope = self::policyContext($pair);
            if (AgentTask::hash($billingScope['settings']) !== $body['settings_hash'] || $billingScope['identity'] !== $body['identity']) {
                throw new \RuntimeException('Selected billing scope changed.');
            }
            $recoveringBilling = isset($c['text_billing']);
            $c['text_billing'] = ['phase' => 'pending']; self::persist($task, $job, $c);
            $billingMappings = array_map(static fn (array $m): array => ['glpi_a_field' => $m['a']['key'],
                'glpi_b_field' => $m['b']['key'], 'source_of_truth' => $m['source_of_truth']], $policy['mappings']);
            try { $billingChanged = FieldEvidence::withoutOrigin(static fn (): mixed => $afterInbound($billingMappings)); }
            finally { FieldEvidence::finishWork(); }
            if (!is_bool($billingChanged)) { throw new \RuntimeException('Billing callback did not account for its result.'); }
            // A lost response after a derived write may require the existing follow-up scan.
            $c['text_billing'] = ['phase' => 'done', 'changed' => $billingChanged || $recoveringBilling];
            self::persist($task, $job, $c);
        }
        if (isset($c['text_billing']) && ($c['text_billing']['phase'] ?? null) !== 'done') { self::park($task, $job, 'blocked'); return; }
        if ($complete) {
            $fresh = self::context($pair); self::start();
            try {
                self::lockTask($task, $job); self::assertSource($pair, $body, $fresh['evidence']);
                // Exact guards above lock this snapshot; it must still prove the original/committed facts.
                foreach ($fresh['evidence'] as $source) {
                    if (!self::sameEvidence($expectedSources[$source['mapping_id']], $source['envelope'])) { throw new \RuntimeException('Expected text completion evidence changed.'); }
                }
                self::saveTask($task, ['state' => 'finalizing']); AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
            $captured = json_decode($task['job'], true, 32, JSON_THROW_ON_ERROR); $job['agent_snapshot'] = (int) ($captured['agent_snapshot'] ?? 0);
            AgentTask::finish($task, $job, 'verified', !empty($job['previous_needs_recheck']) || !empty($c['text_billing']['changed']), $fresh['fingerprint'], $fresh['evidence']); return;
        }
        if ($unresolved) { self::park($task, $job, 'blocked'); return; }
        if ($pendingLocal) { self::park($task, $job, 'pending'); return; }
        if ($remotePending) { self::park($task, $job, 'waiting_agent'); return; }
        $retrySources = $body['sources'];
        foreach ($retrySources as &$source) {
            foreach ($c['text_groups'] ?? [] as $local) {
                if ($local['phase'] === 'done' && isset($local['after'][$source['mapping_id']])) { $source['envelope'] = $local['after'][$source['mapping_id']]; }
            }
        }
        unset($source);
        $c['text_retry_fingerprint'] = self::fingerprint($policy, AgentTask::identity($scope['asset']), $retrySources);
        $c['rest_next'] = time() + 60;
        self::persist($task, $job, $c, 'partial');
        self::park($task, $job, 'waiting_agent');
    }

    private static function settleGroup(array &$task, array $job, array &$c, string $id): void
    {
        $local = $c['text_groups'][$id]; $after = [];
        try {
            foreach ($local['receipts'] as $member => $receipt) { $after[$member] = FieldEvidence::settleInherited($receipt); }
        } catch (\RuntimeException) { return; } // Durable receipt remains held; no second model call.
        if (count($after) !== count($local['receipts']) || $after === []) { throw new \RuntimeException('Incomplete local group receipts.'); }
        $local['phase'] = 'done'; $local['after'] = $after; $c['text_groups'][$id] = $local;
        self::persist($task, $job, $c);
    }

    private static function storageBudget(array $c): void
    {
        $received = count($c['text_snapshots'] ?? []);
        if ($received > 25 || strlen(json_encode($c, JSON_THROW_ON_ERROR)) + (25 - $received) * 60000 + 131072 > 2_000_000) {
            throw new \RuntimeException('Text local progress storage budget unavailable.');
        }
    }

    private static function start(): void
    {
        if (!AgentWake::transactionIdle()) { throw new \RuntimeException('Text group requires an idle connection.'); }
        AgentInbox::sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); AgentInbox::sql('START TRANSACTION');
    }

    private static function fence(array $task): string
    {
        global $DB; $where = [];
        foreach (['id','connection_id','body','hash','slot','state','result','context','queue_id','job'] as $key) {
            $where[] = $task[$key] === null ? $key . ' IS NULL' : 'CAST(' . $key . ' AS BINARY)=CAST(' . $DB->quote((string) $task[$key]) . ' AS BINARY)';
        }
        return implode(' AND ', $where);
    }

    private static function lockTask(array $task, array $job): void
    {
        global $DB;
        AgentInbox::sql('SELECT connection_id FROM ' . AgentTask::BINDINGS . ' WHERE connection_id=' . $DB->quote($task['connection_id']) . ' FOR UPDATE');
        if (!AgentInbox::one('SELECT * FROM ' . AgentTask::TABLE . ' WHERE ' . self::fence($task) . ' FOR UPDATE')) { throw new \RuntimeException('Text task ownership changed.'); }
        AgentInbox::sql('SELECT id FROM ' . AssetSyncQueue::TABLE . ' WHERE id=' . (int) $job['id'] . ' FOR UPDATE');
        if ((int) $task['queue_id'] !== (int) $job['id'] || !AssetSyncQueue::owns($job)) { throw new \RuntimeException('Text queue ownership changed.'); }
    }

    private static function saveTask(array &$task, array $fields): void
    {
        global $DB;
        AgentTask::write($DB->update(AgentTask::TABLE, $fields, [new \Glpi\DBAL\QueryExpression(self::fence($task))]));
        $next = array_replace($task, $fields);
        if ($DB->affectedRows() !== 1 && !AgentInbox::one('SELECT * FROM ' . AgentTask::TABLE . ' WHERE ' . self::fence($next))) { throw new \RuntimeException('Text task fence changed.'); }
        $task = $next;
    }

    private static function persist(array &$task, array $job, array $c, ?string $state = null): void
    {
        global $DB; self::storageBudget($c); self::start();
        try {
            self::lockTask($task, $job); $fields = ['context' => json_encode($c, JSON_THROW_ON_ERROR)];
            if ($state !== null) { $fields['state'] = $state; }
            self::saveTask($task, $fields); AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function park(array $task, array $job, string $status): void
    {
        global $DB; self::start();
        try {
            self::lockTask($task, $job);
            if ($status === 'blocked') { AgentTask::write(AssetSyncQueue::block($job, 'Text reconciliation unfinished; original evidence or group receipt is unresolved.')); }
            else {
                AgentTask::write(AssetSyncQueue::waitForAgent($job));
                if ($status === 'pending') {
                    AgentTask::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => $job['id'], 'status' => 'waiting_agent', 'claim_token' => '']));
                }
            }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function transactionalTables(array $changes): void
    {
        global $DB;
        $tables = ['glpi_computers','glpi_logs',AgentTask::TABLE,AgentTask::PAIRS,AssetSyncQueue::TABLE,AssetSyncLink::TABLE,'glpi_plugin_assetsync20_fieldevidence'];
        foreach ($changes as $change) { $tables[] = $change['pin']['table']; }
        foreach (array_unique($tables) as $table) {
            $row = AgentInbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $DB->quote($table));
            if (strtolower($row['ENGINE'] ?? '') !== 'innodb') { throw new \RuntimeException('Transactional group storage required.'); }
        }
    }

    public static function neverReserved(array $task): bool
    {
        try {
            $b = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR); $c = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            if (array_keys($b) !== ['version','id','installation','registration','generation','connection_id','settings_id','settings_hash','pair_id','itemtype',
                'source_items_id','source_asset_entity','items_id','entity_id','identity','sources','source_hash','source_fingerprint','expires','batch_id']
                || $b['version'] !== 12 || $task['id'] !== $b['id'] || $task['hash'] !== AgentTask::hash($b) || $task['connection_id'] !== $b['connection_id']
                || $task['slot'] !== 'pair:' . $b['pair_id'] || $task['state'] !== 'accepted' || $task['result'] !== '{}' || !is_array($c)
                || ($c['rest_sent'] ?? null) !== false || ($c['rest_lease'] ?? null) !== '' || ($c['rest_until'] ?? null) !== 0 || !is_int($c['rest_next'] ?? null)
                || array_diff(array_keys($c), ['settings','rest_next','rest_sent','rest_lease','rest_until','rest_observe_only'])
                || (isset($c['rest_observe_only']) && !is_bool($c['rest_observe_only'])) || !is_array($c['settings'] ?? null)
                || $c['settings']['version'] !== 5 || $c['settings']['id'] !== $b['settings_id'] || AgentTask::hash($c['settings']) !== $b['settings_hash']) { return false; }
            foreach (['id','installation','registration','generation','settings_id','pair_id','batch_id'] as $key) {
                if (!is_string($b[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $b[$key])) { return false; }
            }
            foreach (['settings_hash','source_hash','source_fingerprint'] as $key) { if (!is_string($b[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $b[$key])) { return false; } }
            foreach (['source_items_id','source_asset_entity','items_id','entity_id','expires'] as $key) { if (!is_int($b[$key])) { return false; } }
            if ($b['source_items_id'] < 1 || $b['items_id'] < 1 || $b['source_asset_entity'] < 0 || $b['entity_id'] < 0 || $b['itemtype'] !== 'Computer'
                || !is_array($b['identity']) || !in_array(array_keys($b['identity']), [['serial'],['serial','otherserial']], true)) { return false; }
            $identity = AgentTask::identity($b['identity'] + ['otherserial' => '']);
            if (array_intersect_key($identity, $b['identity']) !== $b['identity'] || $b['expires'] < 1) { return false; }
            self::validateSources($b['sources'], $c['settings']['mappings']);
            return AgentTask::hash($b['sources']) === $b['source_hash'];
        } catch (\Throwable) { return false; }
    }
}
