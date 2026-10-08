<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Reconcile native UUIDs on existing links only. Ordinary mappings never enter this path. */
final class AssetUuidService
{
    private array $remoteFailures = [];

    public const OUTCOMES = [
        'upgrade_required' => 'Plugin update required to install UUID operation storage',
        'pending' => 'Waiting for UUID reconciliation', 'running' => 'UUID reconciliation running',
        'equal' => 'UUID already equal', 'verified' => 'UUID readback verified',
        'unknown' => 'UUID or required evidence unavailable; no consensus',
        'conflict' => 'Conflicting or malformed UUID values; preserved',
        'locked' => 'Existing UUID lock prevents a write',
        'permission' => 'Asset or locked-field permission unavailable',
        'changed' => 'Asset, participant or configuration changed; fresh retry required',
        'unverified' => 'UUID write was not verified', 'deadline' => 'UUID time budget exhausted',
        'audit_unsupported' => 'Native UUID audit unsupported on this destination',
        'audit_missing' => 'UUID readback verified but history evidence unavailable',
        'audit_verified' => 'UUID history evidence verified',
        'time_uncertain' => 'UUID timestamp effect uncertain; differing Both fields deferred',
        'time_verified' => 'UUID-only timestamp effect verified',
        'no_participants' => 'No eligible existing linked destinations',
    ];

    public function __construct(private object|string $remote = GlpiBConnection::class)
    {
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value)
            || !class_exists(\Ramsey\Uuid\Uuid::class) || !\Ramsey\Uuid\Uuid::isValid($value)) {
            return null;
        }
        return strtolower($value);
    }

    public static function validAsset(array $item, int $id, int $entityId): bool
    {
        return $id > 0 && in_array($item['id'] ?? null, [$id, (string) $id], true)
            && in_array($item['entities_id'] ?? null, [$entityId, (string) $entityId], true)
            && empty($item['is_deleted']) && empty($item['is_template']) && array_key_exists('uuid', $item)
            && (is_string($item['uuid']) || $item['uuid'] === null) && is_string($item['date_mod'] ?? null);
    }

    public static function snapshotHash(array $item): string
    {
        $values = [];
        foreach ($item as $key => $value) {
            if ($key !== 'uuid' && $key !== 'date_mod' && !FieldsText::isCustom((string) $key) && !str_starts_with((string) $key, '_') && (is_scalar($value) || $value === null)) {
                $values[$key] = $value === null ? null : (string) $value;
            }
        }
        ksort($values, SORT_STRING);
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public static function lockOutcome(string $type, int $id, array $assetLocks, array $globalLocks): string
    {
        foreach ([$assetLocks, $globalLocks] as $index => $rows) {
            foreach ($rows as $row) {
                if (!is_array($row) || ($row['itemtype'] ?? null) !== $type || ($row['field'] ?? null) !== 'uuid'
                    || !in_array($row['is_global'] ?? null, [0, 1, '0', '1'], true)
                    || !isset($row['items_id']) || !ctype_digit((string) $row['items_id'])
                    || ($index === 1 && (int) $row['is_global'] !== 1)
                    || ($index === 0 && (int) $row['items_id'] !== $id && (int) $row['is_global'] !== 1)) {
                    return 'unknown';
                }
            }
        }
        return $assetLocks === [] && $globalLocks === [] ? 'unlocked' : 'locked';
    }

    public static function uuidOptionIds(string $type, array $options): array
    {
        $ids = [];
        foreach ($options as $id => $option) {
            if (!is_array($option) || !ctype_digit((string) $id)) {
                continue;
            }
            try {
                if (NativeField::fromOption($type, $option)['field'] === 'uuid') {
                    $ids[] = (int) $id;
                }
            } catch (\RuntimeException) {
            }
        }
        return $ids;
    }

    /** B consensus takes precedence over A and any older durable selection. */
    public static function select(mixed $local, array $remoteValues, ?string $selected, ?string $generated): array
    {
        $valid = [];
        foreach ($remoteValues as $value) {
            $uuid = self::normalize($value);
            if ($uuid === null) {
                return ['outcome' => 'conflict'];
            }
            if ($uuid !== '') {
                $valid[$uuid] = true;
            }
        }
        if (count($valid) > 1) {
            return ['outcome' => 'conflict'];
        }
        if ($valid !== []) {
            return ['outcome' => 'selected', 'uuid' => array_key_first($valid), 'origin' => 'glpi_b'];
        }
        $a = self::normalize($local);
        if ($a === null) {
            return ['outcome' => 'conflict'];
        }
        if ($a !== null && $a !== '') {
            return ['outcome' => 'selected', 'uuid' => $a, 'origin' => 'glpi_a'];
        }
        foreach ([$selected, $generated] as $value) {
            if (($uuid = self::normalize($value)) !== null && $uuid !== '') {
                return ['outcome' => 'selected', 'uuid' => $uuid, 'origin' => 'retained'];
            }
        }
        return ['outcome' => 'generate'];
    }

    /** Failure facts remain available even if persisting the UUID outcome throws. */
    public function remoteFailures(): array
    {
        return $this->remoteFailures;
    }

    /** At most one asset, 50 bootstrap links and 16 participants per cron phase. */
    public function run(int $deadlineNs, array $paused = [], bool $bootstrap = true): array
    {
        $this->remoteFailures = [];
        $metrics = ['attempted' => 0, 'seeded' => 0, 'outcome' => 'no_participants'];
        if (!AssetUuidOperation::available()) {
            $metrics['outcome'] = 'upgrade_required';
            return $metrics;
        }
        if (hrtime(true) + GlpiBConnection::HTTP_CLEANUP_RESERVE_NS >= $deadlineNs) {
            $metrics['outcome'] = 'deadline';
            return $metrics;
        }
        $db = $GLOBALS['DB'];
        $runLockName = $db->quote('assetsync20:run:' . AssetSyncLockName::databaseHash($db));
        $result = $db->doQuery('SELECT GET_LOCK(' . $runLockName . ', 0) AS acquired');
        $lock = $result === false ? null : $db->fetchAssoc($result);
        if (!in_array($lock['acquired'] ?? null, [1, '1'], true)) {
            return $metrics + ['busy' => true];
        }
        try {
            if ($bootstrap) {
                $metrics['seeded'] = AssetUuidOperation::bootstrap($deadlineNs);
            }
            $claim = AssetUuidOperation::claim();
            if ($claim === null) {
                return $metrics;
            }
            $metrics['attempted'] = 1;
            AssetUuidOperation::withAssetLock((string) $claim['itemtype'], (int) $claim['items_id'], function () use ($claim, $deadlineNs, $paused, &$metrics): void {
                $state = AssetUuidOperation::state($claim);
                try {
                    $outcome = $this->reconcile($claim, $state, $deadlineNs, $paused);
                } catch (\Throwable) {
                    // The latest durable pending evidence must survive a thrown/uncertain write.
                    $stored = AssetUuidOperation::find((string) $claim['itemtype'], (int) $claim['items_id']);
                    $state = $stored === null ? $state : AssetUuidOperation::state($stored);
                    $outcome = 'unknown';
                }
                $state['outcome'] = $outcome;
                $metrics['outcome'] = $outcome;
                AssetUuidOperation::finish($claim, $state, $outcome);
            });
            if ($this->remoteFailures !== []) {
                $metrics['remote_failures'] = $this->remoteFailures;
            }
            return $metrics;
        } finally {
            $db->doQuery('SELECT RELEASE_LOCK(' . $runLockName . ') AS released');
        }
    }

    private function reconcile(array $claim, array &$state, int $deadline, array $paused): string
    {
        $type = (string) $claim['itemtype'];
        $id = (int) $claim['items_id'];
        $local = $this->localSnapshot($type, $id);
        if (empty($local['success'])) {
            return $local['outcome'] ?? 'unknown';
        }
        $participants = $this->participants($type, $id, $local['item'], $paused);
        if (isset($participants['outcome'])) {
            return $participants['outcome'];
        }
        $fingerprint = $participants['fingerprint'];
        $state['participant_fingerprint'] = $fingerprint;
        $sides = ['a' => $local];
        foreach ($participants['rows'] as $key => $participant) {
            if (!$this->current($claim, $fingerprint, $deadline, $paused, (string) ($sides['a']['item']['uuid'] ?? ''))) {
                return 'changed';
            }
            $read = $this->remoteSnapshot($participant, $type, false, $deadline);
            if (empty($read['success'])) {
                return $read['outcome'] ?? 'unknown';
            }
            $sides[$key] = $read;
        }
        // Disabled/changed participants must not erase evidence needed if that endpoint returns.
        // Bound current evidence without silently discarding unresolved timestamp guards.
        if (count(array_unique(array_merge(array_keys($state['sides'] ?? []), array_keys($sides)))) > 32) {
            return 'unknown';
        }
        $choice = self::select($local['item']['uuid'], array_map(static fn (array $side): mixed => $side['item']['uuid'], array_diff_key($sides, ['a' => true])),
            $state['selected_uuid'] ?? null, $claim['generated_value'] ?? null);
        if ($choice['outcome'] === 'conflict') {
            return 'conflict';
        }
        $generated = null;
        if (hrtime(true) + GlpiBConnection::HTTP_CLEANUP_RESERVE_NS >= $deadline) {
            return 'deadline';
        }
        if ($choice['outcome'] === 'generate') {
            $generated = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $choice = ['uuid' => $generated, 'origin' => 'generated'];
        }
        $target = $choice['uuid'];
        $state['selected_uuid'] = $target;
        $state['origin'] = $choice['origin'];
        foreach ($sides as $key => $side) {
            $state['sides'][$key]['observed'] = $side['item']['uuid'];
            $state['sides'][$key]['outcome'] = self::normalize($side['item']['uuid']) === $target ? 'equal' : 'pending';
            if (!empty($state['sides'][$key]['pending'])) {
                $state['sides'][$key]['time_uncertain'] = true;
            }
        }
        if (!AssetUuidOperation::persist($claim, $state, $generated)) {
            return 'unknown';
        }
        $destinations = array_filter($sides, static fn (array $side): bool => self::normalize($side['item']['uuid']) !== $target);
        // Establish every destination's permission and lock state before the first mutation.
        foreach ($destinations as $key => $side) {
            if (!$this->current($claim, $fingerprint, $deadline, $paused, (string) ($sides['a']['item']['uuid'] ?? ''))) {
                return 'changed';
            }
            $check = $key === 'a' ? $this->localSnapshot($type, $id, true)
                : $this->remoteSnapshot($participants['rows'][$key], $type, true, $deadline, null, null, false);
            if (empty($check['success'])) {
                return $check['outcome'] ?? 'unknown';
            }
            if ($check['item'] !== $side['item']) {
                return 'changed';
            }
        }
        foreach ($destinations as $key => $before) {
            // Re-read the complete B set before each mutation, including retries after partial success.
            foreach ($participants['rows'] as $remoteKey => $participant) {
                if (!$this->current($claim, $fingerprint, $deadline, $paused, (string) ($sides['a']['item']['uuid'] ?? ''))) {
                    return 'changed';
                }
                $check = $this->remoteSnapshot($participant, $type, false, $deadline, null, null, false);
                if (empty($check['success']) || self::normalize($check['item']['uuid']) !== self::normalize($sides[$remoteKey]['item']['uuid'])) {
                    return 'changed';
                }
            }
            if (!$this->current($claim, $fingerprint, $deadline, $paused, (string) ($sides['a']['item']['uuid'] ?? ''))) {
                return 'changed';
            }
            $oldGuard = $state['sides'][$key];
            $pending = self::pendingEvidence($before, $oldGuard, $target);
            $state['sides'][$key]['pending'] = $pending;
            $state['sides'][$key]['time_uncertain'] = true;
            if (!AssetUuidOperation::persist($claim, $state)) {
                return 'unknown';
            }
            if (!$this->current($claim, $fingerprint, $deadline, $paused, (string) ($sides['a']['item']['uuid'] ?? ''))) {
                return 'changed';
            }
            $after = $key === 'a' ? $this->writeLocal($type, $id, $before['item'], $target)
                : $this->remoteSnapshot($participants['rows'][$key], $type, true, $deadline, (string) ($before['item']['uuid'] ?? ''), $target);
            if (empty($after['success'])) {
                $state['sides'][$key]['outcome'] = $after['outcome'] ?? 'unverified';
                AssetUuidOperation::persist($claim, $state);
                return $state['sides'][$key]['outcome'];
            }
            $proved = self::uuidOnlyProof($pending, $after);
            $timeKnown = $key === 'a' ? is_int($pending['effective_epoch'] ?? null)
                : !empty($pending['timezone']) && $pending['timezone'] === ($after['date_mod_timezone'] ?? null);
            $uncertain = !empty($oldGuard['time_uncertain']) || !empty($oldGuard['pending']) || !$proved || !$timeKnown;
            $state['sides'][$key] = [
                'observed' => $before['item']['uuid'], 'verified' => $target, 'outcome' => 'verified',
                'raw_date' => $after['item']['date_mod'], 'effective_date' => $uncertain ? '' : $pending['effective_date'],
                'effective_epoch' => $uncertain ? null : ($pending['effective_epoch'] ?? null),
                'timezone' => $pending['timezone'] ?? '',
                'snapshot_hash' => self::snapshotHash($after['item']), 'anchor' => self::historyAnchor($after['history']),
                'time_uncertain' => $uncertain,
                'audit' => self::hasExpectedUuidLog($pending, $after['history']) || !empty($after['audit_fallback'])
                    ? 'audit_verified' : ($key !== 'a' && $type === 'Peripheral' ? 'audit_unsupported' : 'audit_missing'),
            ];
            if (!AssetUuidOperation::persist($claim, $state)) {
                return 'unknown';
            }
            $sides[$key] = $after;
        }
        return $destinations === [] ? 'equal' : 'verified';
    }

    private function participants(string $type, int $id, array $asset, array $paused): array
    {
        $connections = [];
        foreach (GlpiBConnection::loadAll(true) as $connection) {
            if (!empty($connection['active'])) {
                $connections[$connection['id']] = $connection;
            }
        }
        EntitySyncRoute::loadAll(true);
        $matched = EntitySyncRoute::matchAsset($type, (string) $asset['entities_id']);
        foreach ($matched['conflicts'] as $conflict) {
            if (isset($connections[$conflict['glpi_b_connection_id']])) {
                return ['outcome' => 'unknown'];
            }
        }
        $rows = [];
        $endpoints = [];
        foreach ($matched['matches'] as $match) {
            $connectionId = $match['glpi_b_connection_id'];
            if (!isset($connections[$connectionId])) {
                continue;
            }
            $endpoint = FieldMapping::canonicalApiEndpoint($connections[$connectionId]['base_url']);
            if ($endpoint !== '' && isset($endpoints[$endpoint])) {
                return ['outcome' => 'conflict'];
            }
            $endpoints[$endpoint] = true;
            $link = AssetSyncLink::find($type, $id, $connectionId);
            if ($link === null || (int) ($link['remote_items_id'] ?? 0) <= 0) {
                continue;
            }
            if (isset($paused[$connectionId]) || in_array($link['status'], [AssetSyncLink::STATUS_BLOCKED_IDENTITY, AssetSyncLink::STATUS_BLOCKED_DUPLICATE, AssetSyncLink::STATUS_BLOCKED_MISSING_REMOTE], true)
                || $match['route']['glpi_b_target_entity_id'] === '') {
                return ['outcome' => 'unknown'];
            }
            $rows[self::remoteSideKey($connectionId, (int) $link['remote_items_id'], $connections[$connectionId]['base_url'])] = [
                'connection' => $connections[$connectionId], 'id' => (int) $link['remote_items_id'],
                'entity' => (int) $match['route']['glpi_b_target_entity_id'], 'route' => $match['route'],
            ];
        }
        if ($rows === [] || count($rows) > 16) {
            return ['outcome' => $rows === [] ? 'no_participants' : 'unknown'];
        }
        ksort($rows, SORT_STRING);
        // Only the hash is persisted; connection credentials and route payloads remain in memory.
        return ['rows' => $rows, 'fingerprint' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    private function current(array $claim, string $fingerprint, int $deadline, array $paused, string $expectedLocalUuid): bool
    {
        if (hrtime(true) + GlpiBConnection::HTTP_CLEANUP_RESERVE_NS + 1_000_000 >= $deadline || !AssetUuidOperation::owns($claim)) {
            return false;
        }
        $local = $this->localSnapshot((string) $claim['itemtype'], (int) $claim['items_id'], false, false);
        if (empty($local['success']) || (string) ($local['item']['uuid'] ?? '') !== $expectedLocalUuid) {
            return false;
        }
        $current = $this->participants((string) $claim['itemtype'], (int) $claim['items_id'], $local['item'], $paused);
        return ($current['fingerprint'] ?? null) === $fingerprint;
    }

    private function remoteSnapshot(array $participant, string $type, bool $writing, int $deadline, ?string $old = null, ?string $new = null, bool $withHistory = true): array
    {
        $previousDeadline = GlpiBConnection::setHttpDeadline($deadline);
        $previousConnection = GlpiBConnection::setHttpMetricsConnection($participant['connection']['id']);
        try {
            $args = [$participant['connection'], $type, $participant['id'], $participant['entity'], $writing, $old, $new, $withHistory];
            $result = is_string($this->remote) ? $this->remote::uuidSnapshot(...$args) : $this->remote->uuidSnapshot(...$args);
        } catch (RemoteRequestFailure $failure) {
            $result = $failure->result;
        } catch (\Throwable) {
            $result = ['success' => false, 'outcome' => 'unknown'];
        } finally {
            GlpiBConnection::setHttpDeadline($previousDeadline);
            GlpiBConnection::setHttpMetricsConnection($previousConnection);
        }
        $failures = empty($result['success']) ? [$result] : [];
        if (isset($result['cleanup_failure'])) {
            $failures[] = $result['cleanup_failure'];
        }
        foreach ($failures as $failure) {
            $this->remoteFailures[] = ['connection_id' => $participant['connection']['id'],
                'status_code' => (int) ($failure['status_code'] ?? 0), 'cause' => (string) ($failure['cause'] ?? ''),
                'executed' => !empty($failure['executed'])];
        }
        if ($failures !== []) {
            return ['success' => false, 'outcome' => isset(self::OUTCOMES[$result['outcome'] ?? '']) ? $result['outcome'] : 'unknown'];
        }
        return $result;
    }

    private function localSnapshot(string $type, int $id, bool $writing = false, bool $withHistory = true): array
    {
        if (!isset(FieldMapping::assetTypes()[$type]) || !class_exists($type)) {
            return ['success' => false, 'outcome' => 'unknown'];
        }
        $item = new $type();
        if (!$item->getFromDB($id) || !self::validAsset($item->fields, $id, (int) ($item->fields['entities_id'] ?? -1))) {
            return ['success' => false, 'outcome' => 'unknown'];
        }
        $cron = class_exists('Session') && method_exists('Session', 'isCron') && \Session::isCron();
        if (!$cron && (!$item->can($id, defined('READ') ? READ : 1) || ($writing && !$item->can($id, defined('UPDATE') ? UPDATE : 2)))) {
            return ['success' => false, 'outcome' => 'permission'];
        }
        if ($writing) {
            if (!$cron && (!class_exists('Session') || !\Session::haveRight('locked_field', defined('UPDATE') ? UPDATE : 2))) {
                return ['success' => false, 'outcome' => 'permission'];
            }
            $db = $GLOBALS['DB'];
            if (!$db->tableExists('glpi_lockedfields')) {
                return ['success' => false, 'outcome' => 'unknown'];
            }
            $locks = iterator_to_array($db->request(['FROM' => 'glpi_lockedfields', 'WHERE' => [
                'itemtype' => $type, 'field' => 'uuid', 'OR' => ['items_id' => $id, 'is_global' => 1],
            ], 'LIMIT' => 1001]));
            $outcome = count($locks) > 1000 ? 'unknown' : self::lockOutcome($type, $id, $locks, []);
            if ($outcome !== 'unlocked') {
                return ['success' => false, 'outcome' => $outcome];
            }
        }
        $fields = $item->fields;
        if (!$withHistory) {
            return ['success' => true, 'item' => $fields];
        }
        foreach ($GLOBALS['DB']->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`date_mod`)', 'epoch')],
            'FROM' => $type::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            if (is_numeric($row['epoch'] ?? null)) {
                $fields['_date_mod_epoch'] = (int) $row['epoch'];
            }
        }
        return ['success' => true, 'item' => $fields, 'history' => $this->localHistory($type, $id)];
    }

    private function localHistory(string $type, int $id): array
    {
        try {
            $db = $GLOBALS['DB'];
            if (!$db->tableExists('glpi_logs')) {
                return ['known' => false];
            }
            $rows = [];
            foreach ($db->request(['FROM' => 'glpi_logs', 'WHERE' => ['itemtype' => $type, 'items_id' => $id], 'ORDER' => ['id DESC'], 'LIMIT' => 101]) as $row) {
                $rows[] = $row;
            }
            $options = \Search::getOptions($type);
            return ['known' => true, 'total' => count($rows), 'rows' => array_slice($rows, 0, 100),
                'uuid_options' => self::uuidOptionIds($type, $options), 'native_options' => self::nativeHistoryFields($type, $options)];
        } catch (\Throwable) {
            return ['known' => false];
        }
    }

    private function writeLocal(string $type, int $id, array $before, string $target): array
    {
        $fresh = $this->localSnapshot($type, $id, true);
        if (empty($fresh['success']) || $fresh['item'] !== $before) {
            return ['success' => false, 'outcome' => $fresh['outcome'] ?? 'changed'];
        }
        $item = new $type();
        if (!AssetChangeHook::withoutQueue(static fn (): bool => (bool) $item->update(['id' => $id, 'uuid' => $target]))) {
            return ['success' => false, 'outcome' => 'unverified'];
        }
        $after = $this->localSnapshot($type, $id);
        if (empty($after['success']) || self::normalize($after['item']['uuid']) !== $target) {
            return ['success' => false, 'outcome' => 'unverified'];
        }
        if ($type === 'Peripheral' && class_exists('Log') && defined('Log::HISTORY_LOG_SIMPLE_MESSAGE')) {
            // No identity impersonation and no audit claim before native readback succeeds.
            $message = 'AssetSync verified native UUID update: ' . $target;
            try {
                \Log::history($id, $type, [0, '', $message], 0, \Log::HISTORY_LOG_SIMPLE_MESSAGE);
                $history = $this->localHistory($type, $id);
            } catch (\Throwable) {
                $history = ['known' => false];
            }
            foreach ($history['rows'] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) <= (int) ($fresh['history']['rows'][0]['id'] ?? 0)) {
                    break;
                }
                if ((int) ($row['linked_action'] ?? -1) === \Log::HISTORY_LOG_SIMPLE_MESSAGE && ($row['new_value'] ?? null) === $message) {
                    $after['audit_fallback'] = true;
                    break;
                }
            }
        }
        return $after;
    }

    public static function historyAnchor(array $history): array
    {
        return ['known' => !empty($history['known']), 'id' => (int) ($history['rows'][0]['id'] ?? 0)];
    }

    public static function pendingEvidence(array $before, array $guard, string $target): array
    {
        $item = $before['item'];
        $reusable = empty($guard['time_uncertain']) && empty($guard['pending']) && ($guard['raw_date'] ?? null) === $item['date_mod']
            && ($guard['snapshot_hash'] ?? null) === self::snapshotHash($item)
            && ($guard['timezone'] ?? '') === ($before['date_mod_timezone'] ?? '');
        return ['old' => $item['uuid'], 'intended' => $target, 'snapshot_hash' => self::snapshotHash($item),
            'raw_date' => $item['date_mod'], 'effective_date' => $reusable ? $guard['effective_date'] : $item['date_mod'],
            'effective_epoch' => $reusable ? ($guard['effective_epoch'] ?? null) : ($item['_date_mod_epoch'] ?? null),
            'timezone' => $before['date_mod_timezone'] ?? '',
            'anchor' => self::historyAnchor($before['history'])];
    }

    public static function uuidOnlyProof(array $pending, array $after): bool
    {
        $history = $after['history'];
        if (empty($pending['anchor']['known']) || empty($history['known']) || empty($history['uuid_options'])
            || self::snapshotHash($after['item']) !== $pending['snapshot_hash']) {
            return false;
        }
        $anchor = (int) $pending['anchor']['id'];
        if ($anchor <= 0) {
            return false;
        }
        $foundAnchor = false;
        $uuidLog = false;
        $expectedId = (int) ($history['rows'][0]['id'] ?? 0);
        foreach ($history['rows'] as $row) {
            // Global log gaps may be other assets or purged rows. Neither proves this interval complete.
            if ((int) $row['id'] !== $expectedId--) {
                return false;
            }
            if ((int) $row['id'] === $anchor) {
                $foundAnchor = true;
                break;
            }
            if ((int) $row['id'] < $anchor || !in_array((int) ($row['id_search_option'] ?? 0), $history['uuid_options'], true)
                || (int) ($row['linked_action'] ?? -1) !== 0 || !empty($row['itemtype_link'])
                || !array_key_exists('old_value', $row) || !array_key_exists('new_value', $row)
                || self::normalize($row['old_value'] ?? null) !== self::normalize($pending['old'])
                || (self::normalize($pending['old']) === null && $row['old_value'] !== $pending['old'])
                || self::normalize($row['new_value'] ?? null) !== $pending['intended']) {
                return false;
            }
            $uuidLog = true;
        }
        return $foundAnchor && $uuidLog;
    }

    private static function hasExpectedUuidLog(array $pending, array $history): bool
    {
        foreach ($history['rows'] ?? [] as $row) {
            if ((int) ($row['id'] ?? 0) > (int) ($pending['anchor']['id'] ?? 0)
                && in_array((int) ($row['id_search_option'] ?? 0), $history['uuid_options'] ?? [], true)
                && (int) ($row['linked_action'] ?? -1) === 0 && empty($row['itemtype_link'])
                && array_key_exists('old_value', $row) && array_key_exists('new_value', $row)
                && (string) $row['old_value'] === (string) $pending['old'] && self::normalize($row['new_value']) === $pending['intended']) {
                return true;
            }
        }
        return false;
    }

    /** Unproved timestamp changes remain explicitly uncertain. */
    public static function guardItem(array $item, array $guard): array
    {
        if ($guard === [] || (!isset($guard['raw_date']) && empty($guard['pending']))) {
            return $item;
        }
        if (!empty($guard['time_uncertain']) || !empty($guard['pending']) || ($guard['raw_date'] ?? null) !== ($item['date_mod'] ?? null)
            || ($guard['snapshot_hash'] ?? null) !== self::snapshotHash($item)) {
            $item['_uuid_time_uncertain'] = true;
        } else {
            $item['date_mod'] = $guard['effective_date'];
            unset($item['_date_mod_epoch']);
            if (is_int($guard['effective_epoch'] ?? null)) {
                $item['_date_mod_epoch'] = $guard['effective_epoch'];
            }
        }
        return $item;
    }

    public static function remoteSideKey(string $connectionId, int $remoteId, string $endpoint): string
    {
        return 'b:' . $connectionId . ':' . $remoteId . ':' . hash('sha256', FieldMapping::canonicalApiEndpoint($endpoint));
    }

    public static function guardPair(string $type, int $id, string $connectionId, int $remoteId, string $endpoint, array $local, array $remote, string $remoteTimezone, callable $readRemote): array
    {
        $row = AssetUuidOperation::find($type, $id);
        if ($row === null) {
            return [$local, $remote];
        }
        try {
            $state = AssetUuidOperation::state($row);
        } catch (\Throwable) {
            $local['_uuid_time_uncertain'] = $remote['_uuid_time_uncertain'] = true;
            return [$local, $remote];
        }
        $localGuard = $state['sides']['a'] ?? [];
        $remoteGuard = $state['sides'][self::remoteSideKey($connectionId, $remoteId, $endpoint)] ?? [];
        if (isset($localGuard['raw_date']) || !empty($localGuard['pending'])) {
            $history = (new self())->localHistory($type, $id);
            $local = self::resolveGuard($local, $localGuard, $history);
        }
        if (isset($remoteGuard['raw_date']) || !empty($remoteGuard['pending'])) {
            // Only guarded Both comparisons need this bounded fresh history read. It also detects same-second edit/revert.
            $read = $readRemote();
            if (!empty($read['success']) && $remoteTimezone !== '' && ($read['date_mod_timezone'] ?? '') === $remoteTimezone) {
                $remote = self::resolveGuard($read['item'], $remoteGuard, $read['history'], ($remoteGuard['timezone'] ?? '') === $remoteTimezone);
            } else {
                $remote['_uuid_time_uncertain'] = true;
            }
        }
        return [$local, $remote];
    }

    public static function nativeHistoryFields(string $type, array $options): array
    {
        $fields = [];
        foreach ($options as $id => $option) {
            if (!ctype_digit((string) $id) || !is_array($option) || ($option['table'] ?? '') !== 'glpi_' . strtolower($type) . 's'
                || !empty($option['joinparams']) || !is_string($option['field'] ?? null)
                || !in_array($option['linkfield'] ?? '', ['', $option['field']], true)
                || in_array($option['field'], ['id', 'uuid', 'date_mod', 'date_creation'], true)) {
                continue;
            }
            $fields[(int) $id] = $option['field'];
        }
        return $fields;
    }

    public static function resolveGuard(array $item, array $guard, array $history, bool $allowPriorTime = true): array
    {
        $uncertain = $item;
        $uncertain['_uuid_time_uncertain'] = true;
        $anchor = $guard['pending']['anchor'] ?? $guard['anchor'] ?? [];
        if (empty($anchor['known']) || empty($history['known'])) {
            return $uncertain;
        }
        $id = (int) ($anchor['id'] ?? 0);
        if ($id <= 0) {
            return $uncertain;
        }
        $covered = false;
        $ordinaryDate = null;
        $changed = [];
        $restored = $item;
        $expectedId = (int) ($history['rows'][0]['id'] ?? 0);
        foreach ($history['rows'] as $row) {
            if ((int) $row['id'] !== $expectedId--) {
                return $uncertain;
            }
            if ((int) $row['id'] === $id) {
                $covered = true;
                break;
            }
            if ((int) $row['id'] < $id) {
                return $uncertain;
            }
            // Fields history has its own comparison rule; it must not manufacture native parent time.
            if (str_starts_with((string) ($row['itemtype_link'] ?? ''), 'PluginFields')) {
                continue;
            }
            $optionId = (int) ($row['id_search_option'] ?? 0);
            if ((int) ($row['linked_action'] ?? -1) !== 0 || !empty($row['itemtype_link'])) {
                return $uncertain;
            }
            if (in_array($optionId, $history['uuid_options'] ?? [], true)) {
                continue;
            }
            $field = $history['native_options'][$optionId] ?? null;
            if ($field === null || !array_key_exists($field, $item) || !is_scalar($row['old_value'] ?? null) || !is_scalar($row['new_value'] ?? null)) {
                return $uncertain;
            }
            if ((string) $row['old_value'] !== (string) $row['new_value']) {
                if ((string) ($restored[$field] ?? '') !== (string) $row['new_value']) {
                    return $uncertain;
                }
                $restored[$field] = (string) $row['old_value'];
                $ordinaryDate ??= $row['date_mod'] ?? null;
                $changed[$field] = true;
            }
        }
        if (!$covered) {
            return $uncertain;
        }
        $priorHash = $guard['pending']['snapshot_hash'] ?? $guard['snapshot_hash'] ?? '';
        if ($changed !== [] && self::snapshotHash($restored) === $priorHash && is_string($ordinaryDate) && $ordinaryDate !== '' && $ordinaryDate === ($item['date_mod'] ?? null)) {
            // A complete later native interval attributes current parent time, even after edit/revert in one second.
            return $item;
        }
        return $allowPriorTime ? self::guardItem($item, $guard) : $uncertain;
    }

    /** A verified ordinary native mutation is new timestamp evidence, unlike date_mod alone. */
    public static function ordinaryWriteVerified(string $type, int $id, string $side, array $before, array $after, array $written, string $timezone = '', array $history = []): void
    {
        $row = AssetUuidOperation::find($type, $id);
        if ($row === null || ($before['date_mod'] ?? null) === ($after['date_mod'] ?? null) || !is_string($after['date_mod'] ?? null)) {
            return;
        }
        $changed = false;
        foreach ($written as $field => $value) {
            if ($field !== 'uuid' && $field !== 'id' && array_key_exists($field, $after) && (string) ($before[$field] ?? '') !== (string) $after[$field]) {
                $changed = true;
            }
        }
        $state = AssetUuidOperation::state($row);
        if (!$changed || !isset($state['sides'][$side])) {
            return;
        }
        if ($side === 'a') {
            $history = (new self())->localHistory($type, $id);
            foreach ($GLOBALS['DB']->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`date_mod`)', 'epoch')],
                'FROM' => $type::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $date) {
                if (is_numeric($date['epoch'] ?? null)) { $after['_date_mod_epoch'] = (int) $date['epoch']; }
            }
            if (!is_int($after['_date_mod_epoch'] ?? null)) {
                return;
            }
        } elseif ($timezone === '') {
            return;
        }
        if (empty($history['known'])) {
            return;
        }
        $guard = $state['sides'][$side];
        if (!empty($guard['pending'])) {
            $guard['outcome'] = 'unverified';
            unset($guard['pending']);
        }
        $guard['raw_date'] = $guard['effective_date'] = $after['date_mod'];
        $guard['effective_epoch'] = $after['_date_mod_epoch'] ?? null;
        $guard['timezone'] = $timezone;
        $guard['snapshot_hash'] = self::snapshotHash($after);
        $guard['time_uncertain'] = false;
        $guard['anchor'] = self::historyAnchor($history);
        $state['sides'][$side] = $guard;
        $db = $GLOBALS['DB'];
        // The caller holds the asset mutex; retain a SQL fence against a concurrent claim/state change.
        AssetSyncDbTime::write($db, static fn (): bool => $db->update(AssetUuidOperation::TABLE,
            ['state_json' => json_encode($state, JSON_THROW_ON_ERROR)], ['id' => (int) $row['id'], 'state_json' => $row['state_json'],
                new \Glpi\DBAL\QueryExpression('(`lease_until` IS NULL OR UNIX_TIMESTAMP(`lease_until`) <= UNIX_TIMESTAMP())')]));
    }
}
