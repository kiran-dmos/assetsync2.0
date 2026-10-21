<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Work11 only: native Name decisions and committed inherited-origin recovery, not a generic writer. */
final class NameBoth
{
    public static function normalize(mixed $value): string
    {
        if ($value !== null && !is_string($value)) { throw new \RuntimeException('Native Name must be text.'); }
        $value ??= '';
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 255) { throw new \RuntimeException('Native Name exceeds its supported size.'); }
        return $value;
    }

    public static function validateEnvelope(array $e): void
    {
        if (array_keys($e) !== ['value', 'hash', 'meta', 'origin', 'guard']) { throw new \RuntimeException('Invalid Name evidence envelope.'); }
        self::normalize($e['value']);
        if ($e['hash'] !== hash('sha256', json_encode(['type' => 'text', 'value' => $e['value']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            || !is_string($e['meta']) || !preg_match('/^[a-f0-9]{64}$/D', $e['meta'])) { throw new \RuntimeException('Invalid Name evidence hash.'); }
        if ($e['origin'] !== null) {
            $o = $e['origin'];
            if (!is_array($o) || array_keys($o) !== ['identity', 'revision', 'kind', 'from_epoch', 'to_epoch']
                || !is_string($o['identity']) || !preg_match('/^[a-f0-9]{32}:[a-f0-9]{32}$/D', $o['identity'])
                || !is_int($o['revision']) || $o['revision'] < 1 || $o['kind'] !== 'user'
                || !is_int($o['from_epoch']) || !is_int($o['to_epoch']) || $o['from_epoch'] < 0 || $o['from_epoch'] > $o['to_epoch']) {
                throw new \RuntimeException('Invalid original Name interval.');
            }
        }
        $g = $e['guard'];
        if (!is_array($g) || array_keys($g) !== ['epoch', 'generation', 'head', 'history']
            || !is_string($g['epoch']) || !preg_match('/^[a-f0-9]{32}$/D', $g['epoch'])
            || ($g['generation'] !== null && (!is_int($g['generation']) || $g['generation'] < 0))
            || ($g['generation'] === null ? $g['head'] !== null : (!is_string($g['head']) || !preg_match('/^[a-f0-9]{64}$/D', $g['head'])))
            || !is_array($g['history']) || array_keys($g['history']) !== ['latest', 'anchor']
            || !is_int($g['history']['latest']) || $g['history']['latest'] < 0) { throw new \RuntimeException('Invalid Name execution guard.'); }
        $anchor = $g['history']['anchor'];
        if ($anchor !== null && (!is_array($anchor) || array_keys($anchor) !== ['id', 'hash'] || !is_int($anchor['id'])
            || $anchor['id'] < 1 || !is_string($anchor['hash']) || !preg_match('/^[a-f0-9]{64}$/D', $anchor['hash']))) { throw new \RuntimeException('Invalid Name History guard.'); }
        if (($anchor['id'] ?? 0) !== $g['history']['latest'] || ($e['origin'] !== null && ($anchor === null || $g['head'] === null))) {
            throw new \RuntimeException('Incomplete original Name guard.');
        }
    }

    /** Bookkeeping generations are deliberately absent: an unrelated Comments save is not Name work. */
    public static function fingerprint(string $settingsHash, array $identity, array $e): string
    {
        self::validateEnvelope($e);
        return AgentTask::hash([$settingsHash, $identity, self::normalize($e['value']), $e['meta'], $e['origin']]);
    }

    public static function decide(array $a, array $b): string
    {
        self::validateEnvelope($a); self::validateEnvelope($b);
        if (self::normalize($a['value']) === self::normalize($b['value'])) { return 'equal'; }
        if ($a['origin'] === null || $b['origin'] === null) { return 'conflict'; }
        if ($a['origin']['from_epoch'] > $b['origin']['to_epoch']) { return 'a'; }
        if ($b['origin']['from_epoch'] > $a['origin']['to_epoch']) { return 'b'; }
        return 'conflict';
    }

    public static function validateResult(array $body, array $r): void
    {
        if (!in_array($r['outcome'] ?? '', ['verified', 'unchanged', 'conflict'], true)) {
            if (in_array($r['outcome'] ?? '', ['uncertain', 'stale'], true) && ($r['changed'] ?? null) === []) { return; }
            throw new \RuntimeException('Invalid Name Both outcome.');
        }
        if (array_keys($r) !== ['outcome', 'changed', 'winner', 'mutation', 'source_hash', 'before', 'after', 'started_at', 'model_ended_at', 'within_deadline']
            || $r['source_hash'] !== $body['source_hash'] || !is_array($r['before']) || !is_array($r['after'])
            || !is_int($r['started_at']) || !is_int($r['model_ended_at']) || $r['started_at'] < $body['expires'] - 300 || $r['started_at'] > $r['model_ended_at']
            || $r['model_ended_at'] > $body['expires'] || $r['within_deadline'] !== true) { throw new \RuntimeException('Invalid Name Both result guard.'); }
        $winner = self::decide($body['source'], $r['before']);
        self::validateEnvelope($r['after']);
        if ($r['winner'] !== $winner || $r['changed'] !== ($winner === 'a' ? ['name'] : [])
            || $r['mutation'] !== ($winner === 'a' ? 'committed' : 'none')
            || $r['outcome'] !== (['a' => 'verified', 'b' => 'verified', 'equal' => 'unchanged', 'conflict' => 'conflict'][$winner])) {
            throw new \RuntimeException('Name Both decision does not match its evidence.');
        }
        if ($winner === 'a') {
            if (self::normalize($r['after']['value']) !== self::normalize($body['source']['value']) || $r['after']['origin'] !== $body['source']['origin']
                || $r['after']['meta'] !== $r['before']['meta']) { throw new \RuntimeException('Inherited B Name is not verified.'); }
        } elseif ($r['after'] !== $r['before']) { throw new \RuntimeException('Read-only Name result changed evidence.'); }
    }

    /** B's stored decision is not A completion. A may still owe a guarded local model commit. */
    public static function finish(array $task, array $job, array $pair, array $context, array $body, array $result): void
    {
        global $DB;
        if (AgentTask::hash($body) !== $task['hash']) { throw new \RuntimeException('Immutable Name work hash changed.'); }
        self::validateResult($body, $result);
        $stored = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
        if (isset($stored['name_origin_pending'])) {
            $after = FieldEvidence::settleInherited($stored['name_origin_pending']);
            self::finalize($task, $job, $pair, $body, $result, $after);
            return;
        }
        if (!in_array($result['outcome'], ['verified', 'unchanged', 'conflict'], true)) {
            AgentTask::write(AssetSyncQueue::block($job, 'Name Both result remains uncertain or stale; immutable slot retained.'));
            return;
        }
        if ($context['source_hash'] !== $body['source_hash']) {
            // Even a no-write source race does not erase a possibly committed B write.
            AgentTask::write(AssetSyncQueue::block($job, 'Original A Name guard changed; retained work requires reconciliation.'));
            return;
        }
        if ($result['winner'] === 'conflict') {
            self::releaseConflict($task, $job, $pair, $body, $context);
            return;
        }
        if ($result['winner'] !== 'b') {
            self::finalize($task, $job, $pair, $body, $result, $context['evidence']);
            return;
        }
        self::transactionalTables();
        if (!AgentWake::transactionIdle()) { throw new \RuntimeException('Name Both local write requires an idle connection.'); }
        AgentInbox::sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        AgentInbox::sql('START TRANSACTION');
        try {
            self::lockTask($task, $job);
            RestWork::assertNameSource($pair, $body, $context['evidence']);
            $item = self::localItem($pair, true);
            self::writable($item);
            $value = self::normalize($result['after']['value']);
            $hash = hash('sha256', json_encode(['type' => 'text', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $receipt = null;
            AssetChangeHook::withoutQueue(static function () use (&$receipt, $pair, $context, $hash, $result, $item, $value): bool {
                $receipt = FieldEvidence::withOrigin((int) $pair['local_id'], 'native:name', $context['evidence']['hash'], $hash,
                    $result['after']['origin'], static function () use ($item, $pair, $value, $result): array {
                        if (!$item->update(['id' => (int) $pair['local_id'], 'name' => $value])) { throw new \RuntimeException('Native A Name update failed.'); }
                        return FieldEvidence::inheritedReceipt((int) $pair['local_id'], 'native:name', $result['after']['origin']);
                    });
                return true;
            });
            // Full fresh scope after the supported model call, without reading/minting an idle origin in this TX.
            RestWork::assertNamePolicy($pair, $body);
            self::writable(self::localItem($pair, true));
            $stored['name_origin_pending'] = $receipt;
            AgentTask::write($DB->update(AgentTask::TABLE, ['state' => 'origin_pending', 'context' => json_encode($stored, JSON_THROW_ON_ERROR)],
                ['id' => $task['id'], 'hash' => $task['hash'], 'context' => $task['context'], 'state' => $task['state'], 'slot' => $task['slot']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('A inherited receipt ownership changed.'); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        finally { FieldEvidence::finishWork(); }
        // Re-read durable state after COMMIT, never infer it from the in-memory model return value.
        $saved = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id`=' . $DB->quote($task['id']));
        if (!$saved || $saved['hash'] !== $task['hash'] || $saved['state'] !== 'origin_pending') { throw new \RuntimeException('A inherited receipt is not committed.'); }
        $pending = json_decode($saved['context'], true, 32, JSON_THROW_ON_ERROR);
        $after = FieldEvidence::settleInherited($pending['name_origin_pending']);
        self::finalize($saved, $job, $pair, $body, $result, $after);
    }

    private static function finalize(array $task, array $job, array $pair, array $body, array $result, array $after): void
    {
        global $DB;
        if (self::normalize($after['value']) !== self::normalize($result['after']['value'])
            || ($result['winner'] === 'b' && $after['origin'] !== $result['after']['origin'])) {
            throw new \RuntimeException('A inherited Name has not been qualified.');
        }
        $context = RestWork::assertNameSource($pair, $body, $after);
        if ($task['state'] !== 'finalizing') {
            AgentTask::write($DB->update(AgentTask::TABLE, ['state' => 'finalizing'],
                ['id' => $task['id'], 'hash' => $task['hash'], 'state' => $task['state'], 'context' => $task['context'], 'slot' => $task['slot']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Name Both finalization ownership changed.'); }
        }
        $captured = json_decode($task['job'], true, 32, JSON_THROW_ON_ERROR);
        $job['agent_snapshot'] = (int) ($captured['agent_snapshot'] ?? 0);
        AgentTask::finish($task, $job, $result['outcome'], !empty($job['previous_needs_recheck']), $context['fingerprint'], $after);
    }

    private static function releaseConflict(array $task, array $job, array $pair, array $body, array $context): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            self::lockTask($task, $job);
            $fresh = RestWork::assertNameSource($pair, $body, $context['evidence']);
            // Only a validated read-only conflict has no B intent/model write to recover.
            AgentTask::write($DB->update(AgentTask::TABLE, ['state' => 'conflict', 'slot' => null],
                ['id' => $task['id'], 'state' => $task['state'], 'slot' => $task['slot'], 'hash' => $task['hash']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Name conflict receipt changed.'); }
            AgentTask::write($DB->update(AgentTask::PAIRS, ['local_fingerprint' => $fresh['fingerprint'], 'checked_at' => time()], ['id' => $pair['id']]));
            AgentTask::write(AssetSyncQueue::block($job, 'Name conflict: original intervals are missing or overlap; no mutation.'));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function lockTask(array $task, array $job): void
    {
        global $DB;
        AgentInbox::sql('SELECT `connection_id` FROM `' . AgentTask::BINDINGS . '` WHERE `connection_id`=' . $DB->quote($task['connection_id']) . ' FOR UPDATE');
        $current = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id`=' . $DB->quote($task['id']) . ' FOR UPDATE');
        if (!$current || $current['hash'] !== $task['hash'] || $current['body'] !== $task['body'] || $current['result'] !== $task['result']
            || $current['state'] !== $task['state'] || $current['context'] !== $task['context'] || $current['slot'] !== $task['slot']
            || (int) $current['queue_id'] !== (int) $job['id'] || !AssetSyncQueue::owns($job)) { throw new \RuntimeException('Name Both task or queue changed.'); }
    }

    private static function writable(object $item): void
    {
        $options = array_values(array_filter($item->rawSearchOptions(), static fn ($o): bool => is_array($o)
            && ($o['table'] ?? '') === 'glpi_computers' && ($o['field'] ?? '') === 'name'));
        if (count($options) !== 1 || !empty($options[0]['readonly']) || !empty($options[0]['is_readonly'])) { throw new \RuntimeException('Native Name is not writable.'); }
    }

    /** Same A-owned cron boundary as AssetSyncService::assertLocalNativeAccess, not a fabricated login. */
    public static function localItem(array $pair, bool $update): object
    {
        AgentTask::scope($pair); // Fresh active route, original entity, confirmed IDs/identity and reverse ownership.
        $item = new \Computer();
        if (!$item->getFromDB((int) $pair['local_id']) || (int) $item->fields['entities_id'] !== (int) $pair['local_entity']
            || !empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])) { throw new \RuntimeException('A Name scope changed.'); }
        // The existing CLI worker has only glpicronuserrunning/currenttime, no user's active entity session.
        // Interactive callers retain explicit READ/UPDATE and entity checks; B's service login is unchanged.
        if (!\Session::isCron() && (!$item->can((int) $pair['local_id'], READ)
            || ($update && !$item->can((int) $pair['local_id'], UPDATE)) || !\Session::haveAccessToEntity((int) $pair['local_entity']))) {
            throw new \RuntimeException('A Name authorization denied.');
        }
        return $item;
    }

    private static function transactionalTables(): void
    {
        global $DB;
        foreach (['glpi_computers', 'glpi_logs', AgentTask::TABLE, AgentTask::PAIRS, AssetSyncQueue::TABLE,
            AssetSyncLink::TABLE, 'glpi_plugin_assetsync20_fieldevidence'] as $table) {
            $engine = AgentInbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $DB->quote($table));
            if (strtolower($engine['ENGINE'] ?? '') !== 'innodb') { throw new \RuntimeException('Transactional Name evidence storage is required.'); }
        }
    }
}
