<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

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
        return TaskExecutor::hash([$settingsHash, $identity, self::normalize($e['value']), $e['meta'], $e['origin']]);
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

    /** Native collection GET calls this on its stored row. Fresh READ/guard validation has no settlement side effect. */
    public static function assertReadable(array $row): void
    {
        $body = RestWork::decode($row['body'], $row['hash']);
        $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
        self::validateResult($body, $result);
        if (!isset($result['after'])) { return; }
        $settings = RestPeer::work($body);
        $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
        ServiceContext::run($target, static function () use ($target, $body, $result): void {
            ServiceContext::readComputer($target);
            FieldEvidence::assertGuard($body['items_id'], 'native:name', $result['after']);
        });
    }

    /** Called only by admitted POST while holding the existing worker lock. GET never enters here. */
    public static function run(array $row, array $body, array $settings, int $deadline): void
    {
        global $DB;
        $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id`=' . $DB->quote($body['id']));
        if ($journal) {
            self::sameJournal($journal, $row);
            if ($journal['state'] === 'origin_pending') { self::recover($row, $body, $settings, $journal, $deadline); }
            elseif ($journal['result'] === '{}') { self::terminal($row, ['outcome' => 'uncertain', 'changed' => []]); }
            return;
        }
        if ($body['expires'] < time() || hrtime(true) >= $deadline) { return; }
        $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
        $before = ServiceContext::run($target, static function () use ($target): array {
            ServiceContext::computer($target);
            return FieldEvidence::envelope((int) $target['items_id'], 'native:name');
        });
        $winner = self::decide($body['source'], $before);
        $started = time();
        self::transactionalTables();
        Outbox::sql('START TRANSACTION');
        try {
            self::write($DB->insert(TaskJournal::TABLE, ['id' => $body['id'], 'hash' => $row['hash'], 'body' => $row['body'],
                'state' => 'executing', 'result' => '{}', 'created_at' => $started, 'registration' => $body['registration'],
                'generation' => $body['generation'], 'installation' => $body['installation'], 'connection_id' => $body['connection_id'], 'report_status' => 'rest']));
            RestWork::projectName($row, 'executing', []);
            Outbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        // A second transaction owns the model. A crash after intent is uncertainty, never permission to retry it.
        Outbox::sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        Outbox::sql('START TRANSACTION');
        try {
            RestWork::authorizeName($row, $body, true, $deadline);
            $receipt = ServiceContext::run($target, static function () use ($target, $before, $body, $winner): ?array {
                FieldEvidence::assertGuard($body['items_id'], 'native:name', $before);
                $item = ServiceContext::computer($target);
                if (array_intersect_key(TaskExecutor::identity($item->fields), $body['identity']) !== $body['identity']) { throw new \RuntimeException('Confirmed B identity changed.'); }
                if ($winner !== 'a') { return null; }
                self::writable($item);
                $afterHash = hash('sha256', json_encode(['type' => 'text', 'value' => self::normalize($body['source']['value'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return FieldEvidence::withOrigin($body['items_id'], 'native:name', $before['hash'], $afterHash, $body['source']['origin'],
                    static function () use ($item, $body, $target): array {
                        if (!$item->update(['id' => $body['items_id'], 'name' => self::normalize($body['source']['value'])])) { throw new \RuntimeException('Native B Name update failed.'); }
                        self::writable(ServiceContext::computer($target));
                        return FieldEvidence::inheritedReceipt($body['items_id'], 'native:name', $body['source']['origin']);
                    });
            });
            RestWork::authorizeName($row, $body, true, $deadline);
            $result = ['outcome' => ['a' => 'verified', 'b' => 'verified', 'equal' => 'unchanged', 'conflict' => 'conflict'][$winner],
                'changed' => $winner === 'a' ? ['name'] : [], 'winner' => $winner, 'mutation' => $winner === 'a' ? 'committed' : 'none',
                'source_hash' => $body['source_hash'], 'before' => $before, 'after' => $before,
                'started_at' => $started, 'model_ended_at' => time(), 'within_deadline' => true];
            if ($receipt !== null) {
                self::saveJournal($row, 'origin_pending', ['receipt' => $receipt, 'result' => $result]);
                RestWork::projectName($row, 'origin_pending', []);
            } else { self::saveJournal($row, $result['outcome'], $result); RestWork::projectName($row, $result['outcome'], $result); }
            Outbox::sql('COMMIT');
        } catch (\Throwable $error) {
            $DB->doQuery('ROLLBACK');
            // Do not manufacture a terminal success from a possibly ambiguous COMMIT.
            throw $error;
        } finally { FieldEvidence::finishWork(); }
        if ($receipt !== null) {
            $saved = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id`=' . $DB->quote($body['id']));
            self::sameJournal($saved, $row);
            if ($saved['state'] === 'origin_pending') { self::recover($row, $body, $settings, $saved, $deadline); }
        }
    }

    private static function recover(array $row, array $body, array $settings, array $journal, int $deadline): void
    {
        global $DB;
        if (hrtime(true) >= $deadline) { return; }
        RestWork::authorizeName($row, $body, false, $deadline);
        $pending = json_decode($journal['result'], true, 32, JSON_THROW_ON_ERROR);
        $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
        $after = ServiceContext::run($target, static function () use ($target, $pending): array {
            ServiceContext::computer($target);
            return FieldEvidence::settleInherited($pending['receipt']);
        });
        $result = $pending['result']; $result['after'] = $after;
        self::validateResult($body, $result);
        Outbox::sql('START TRANSACTION');
        try {
            RestWork::authorizeName($row, $body, false, $deadline);
            ServiceContext::run($target, static function () use ($body, $target, $after): void {
                FieldEvidence::assertGuard($body['items_id'], 'native:name', $after);
                ServiceContext::computer($target);
            });
            self::saveJournal($row, $result['outcome'], $result, 'origin_pending', $journal['result']);
            RestWork::projectName($row, $result['outcome'], $result);
            Outbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function terminal(array $row, array $result): void
    {
        global $DB;
        Outbox::sql('START TRANSACTION');
        try { self::saveJournal($row, $result['outcome'], $result); RestWork::projectName($row, $result['outcome'], $result); Outbox::sql('COMMIT'); }
        catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function sameJournal(?array $journal, array $row): void
    {
        if (!$journal || $journal['hash'] !== $row['hash'] || $journal['body'] !== $row['body']) { throw new \RuntimeException('Name journal identity changed.'); }
    }

    private static function saveJournal(array $row, string $state, array $result, string $oldState = 'executing', string $oldResult = '{}'): void
    {
        global $DB;
        self::write($DB->update(TaskJournal::TABLE, ['state' => $state, 'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            ['id' => $row['work_id'], 'hash' => $row['hash'], 'body' => $row['body'], 'state' => $oldState, 'result' => $oldResult]));
        if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Name journal receipt changed.'); }
    }

    private static function transactionalTables(): void
    {
        global $DB;
        foreach (['glpi_computers', 'glpi_logs', TaskJournal::TABLE, RestWork::getTable(), 'glpi_plugin_assetsync20agent_fieldevidence'] as $table) {
            $engine = Outbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $DB->quote($table));
            if (strtolower($engine['ENGINE'] ?? '') !== 'innodb') { throw new \RuntimeException('Transactional Name evidence storage is required.'); }
        }
    }

    private static function writable(object $item): void
    {
        $options = array_values(array_filter($item->rawSearchOptions(), static fn ($o): bool => is_array($o)
            && ($o['table'] ?? '') === 'glpi_computers' && ($o['field'] ?? '') === 'name'));
        if (count($options) !== 1 || !empty($options[0]['readonly']) || !empty($options[0]['is_readonly'])) { throw new \RuntimeException('Native Name is not writable.'); }
    }

    private static function write(mixed $ok): void { if (!$ok) { throw new \RuntimeException('Name journal storage failed.'); } }
}
