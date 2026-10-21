<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Acceptance is separate from TaskJournal's durable mutation intent. */
final class RestWork extends \CommonDBTM
{
    private const ROW_BYTES = 16000;
    private int $deadline = 0;
    private static ?string $executing = null;
    private static int $executionDeadline = 0;

    public static function install(): bool
    {
        global $DB;
        $ok = (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::getTable() . '` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `work_id` CHAR(32) NOT NULL UNIQUE,
            `hash` CHAR(64) NOT NULL, `body` MEDIUMTEXT NOT NULL, `binding` CHAR(64) NOT NULL,
            `connection_id` VARCHAR(255) NOT NULL, `registration` CHAR(32) NOT NULL,
            `generation` CHAR(32) NOT NULL, `installation` CHAR(32) NOT NULL, `entities_id` INT NOT NULL,
            `accepted_at` BIGINT NOT NULL, `compiler` INT NOT NULL DEFAULT 7,
            `task_body` MEDIUMTEXT NOT NULL, `task_hash` CHAR(64) NOT NULL DEFAULT \'\',
            `execution_settings` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$ok) { return false; }
        foreach (['batch_id' => 'CHAR(32) DEFAULT NULL', 'state' => 'VARCHAR(20) DEFAULT NULL',
            'result' => 'MEDIUMTEXT DEFAULT NULL', 'expires' => 'BIGINT DEFAULT NULL'] as $column => $definition) {
            if (!$DB->fieldExists(self::getTable(), $column)
                && !$DB->doQuery('ALTER TABLE `' . self::getTable() . '` ADD `' . $column . '` ' . $definition)) { return false; }
        }
        if (!Outbox::one('SHOW INDEX FROM `' . self::getTable() . '` WHERE Key_name = \'rest_batch\' AND Seq_in_index = 1')
            && !$DB->doQuery('ALTER TABLE `' . self::getTable() . '` ADD KEY `rest_batch` (`binding`, `batch_id`, `entities_id`)')) { return false; }
        return true;
    }

    public static function unsetUndisclosedFields(&$fields)
    {
        parent::unsetUndisclosedFields($fields);
        if ((int) ($fields['compiler'] ?? 0) === 8) {
            unset($fields['execution_settings'], $fields['task_body'], $fields['task_hash']);
        } elseif ((int) ($fields['compiler'] ?? 0) === 7) {
            unset($fields['batch_id'], $fields['expires']);
            foreach (['state', 'result'] as $key) { if (array_key_exists($key, $fields) && $fields[$key] === null) { unset($fields[$key]); } }
        }
    }

    /** Called only inside the journal/model transaction, never from GET. */
    public static function project(array $task, string $state, array $result): void
    {
        if ($task['version'] !== 8) { return; }
        global $DB;
        $row = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($task['id']));
        if (!$row || $row['hash'] !== $task['rest_work_hash'] || $row['task_hash'] !== $task['hash']
            || $row['batch_id'] !== $task['batch_id']) { throw new \RuntimeException('REST projection identity changed.'); }
        $fields = ['state' => $state, 'result' => $result === [] ? '{}' : json_encode($result, JSON_THROW_ON_ERROR)];
        self::assertResponseSize(array_replace($row, $fields));
        if (!$DB->update(self::getTable(), $fields, ['id' => $row['id'], 'hash' => $row['hash'], 'task_hash' => $task['hash']]) || $DB->affectedRows() !== 1) {
            throw new \RuntimeException('REST result projection failed.');
        }
    }

    private static function assertResponseSize(array $row): void
    {
        self::unsetUndisclosedFields($row);
        if (strlen(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::ROW_BYTES) {
            throw new \RuntimeException('REST public record exceeds response budget.');
        }
    }

    public static function canView(): bool { return RestPeer::canView(); }
    public static function canCreate(): bool { return RestPeer::canView(); }
    public static function canUpdate(): bool { return false; }
    public static function canDelete(): bool { return false; }
    public static function canPurge(): bool { return false; }
    public function can($ID, int $right, ?array &$input = null): bool
    {
        return in_array($right, [READ, CREATE], true) && RestPeer::canView() && parent::can($ID, $right, $input);
    }
    public static function getSystemSQLCriteria(?string $tablename = null): array
    {
        $table = $tablename ?? self::getTable();
        $where = RestSettings::getSystemSQLCriteria($table);
        // v8 is exposed only through explicitly bounded batch reads, not legacy collections.
        if (!isset($_GET['searchText']['batch_id'])) { $where[] = new \Glpi\DBAL\QueryExpression('`' . $table . '`.`compiler` <> 8'); }
        else {
            $batch = $_GET['searchText']['batch_id'];
            if (!is_string($batch) || !preg_match('/^[a-f0-9]{32}$/D', $batch)) { throw new \RuntimeException('Exact REST batch ID required.'); }
            $where['AND'][$table . '.batch_id'] = $batch;
            $where['AND'][$table . '.compiler'] = 8;
        }
        $where[] = new \Glpi\DBAL\QueryExpression('EXISTS (SELECT 1 FROM `glpi_computers` AS `rest_target`
            WHERE `rest_target`.`id` = JSON_EXTRACT(`' . $table . '`.`body`, \'$.items_id\')
            AND `rest_target`.`entities_id` = `' . $table . '`.`entities_id`
            AND `rest_target`.`is_deleted` = 0 AND `rest_target`.`is_template` = 0)');
        return $where;
    }

    public static function decode(string $json, string $hash): array
    {
        $body = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $keys = ['version', 'id', 'installation', 'registration', 'generation', 'connection_id', 'settings_id',
            'settings_hash', 'pair_id', 'itemtype', 'items_id', 'entity_id', 'identity', 'name', 'source_hash', 'expires'];
        if (($body['version'] ?? null) === 8) { $keys[] = 'batch_id'; }
        if (!is_array($body) || array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))
            || strlen($json) > 32768 || !hash_equals(TaskExecutor::hash($body), $hash) || !in_array($body['version'], [7, 8], true)
            || $body['itemtype'] !== 'Computer' || !is_int($body['items_id']) || $body['items_id'] <= 0
            || !is_int($body['entity_id']) || $body['entity_id'] < 0 || !is_int($body['expires'])
            || !is_string($body['name']) || strlen($body['name']) > 255
            || !is_array($body['identity']) || !in_array(array_keys($body['identity']), [['serial'], ['serial', 'otherserial']], true)
            || array_intersect_key(TaskExecutor::identity($body['identity'] + ['otherserial' => '']), $body['identity']) !== $body['identity']) {
            throw new \RuntimeException('Invalid Name REST work.');
        }
        foreach (['id', 'settings_id', 'pair_id', 'registration', 'generation', 'installation'] as $key) {
            if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $body[$key])) { throw new \RuntimeException('Invalid REST ID.'); }
        }
        if ($body['version'] === 8 && (!is_string($body['batch_id']) || !preg_match('/^[a-f0-9]{32}$/D', $body['batch_id']))) {
            throw new \RuntimeException('Invalid REST batch ID.');
        }
        foreach (['settings_hash', 'source_hash'] as $key) {
            if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $body[$key])) { throw new \RuntimeException('Invalid REST hash.'); }
        }
        return $body;
    }

    private static function authorize(array $body): array
    {
        $settings = RestPeer::work($body);
        global $DB;
        $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
        if (!$policy || $policy['hash'] !== $body['settings_hash'] || $policy['binding'] !== RestPeer::binding($settings)) {
            throw new \RuntimeException('Pinned REST settings unavailable.');
        }
        $document = RestSettings::decode($policy['body'], $policy['hash']);
        RestSettings::authorize($document);
        if ($document['target_entity'] !== $body['entity_id']) { throw new \RuntimeException('Pinned REST route changed.'); }
        return $settings;
    }

    public function add(array $input, $options = [], $history = true)
    {
        $this->deadline = hrtime(true) + 5_000_000_000;
        if (array_diff(array_keys($input), ['work_id', 'hash', 'body', 'entities_id', '_add'])) { throw new \RuntimeException('Unknown REST work input.'); }
        $body = self::decode((string) ($input['body'] ?? ''), (string) ($input['hash'] ?? ''));
        if ($body['version'] === 8) { $this->deadline = RestPeer::deadline(); }
        $settings = self::authorize($body);
        if (($input['work_id'] ?? '') !== $body['id'] || !RestPeer::idle()) { throw new \RuntimeException('REST acceptance unavailable.'); }
        global $DB;
        $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($body['id']));
        if (!$old) {
            if (Outbox::one('SELECT `id` FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']))) {
                throw new \RuntimeException('REST work ID is already used by retained journal evidence.');
            }
            if ($body['expires'] < time() || $body['expires'] > time() + 300) { throw new \RuntimeException('REST work expired.'); }
            $fields = ['work_id' => $body['id'], 'hash' => $input['hash'], 'body' => $input['body'],
                'binding' => RestPeer::binding($settings), 'entities_id' => $body['entity_id'], 'accepted_at' => time(),
                'task_body' => '{}', 'execution_settings' => '{}'];
            foreach (['connection_id', 'registration', 'generation', 'installation'] as $key) { $fields[$key] = $body[$key]; }
            if ($body['version'] === 8) {
                if (hrtime(true) >= $this->deadline) { throw new \RuntimeException('REST batch acceptance deadline reached.'); }
                $fields += ['compiler' => 8, 'batch_id' => $body['batch_id'], 'state' => 'accepted', 'result' => '{}', 'expires' => $body['expires']];
                self::assertResponseSize($fields + ['id' => PHP_INT_MAX]);
            }
            try { return parent::add($fields, $options, false); }
            catch (\RuntimeException $error) {
                $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($body['id']));
                if (!$old) { throw $error; }
                if ((int) ($this->fields['id'] ?? 0) === (int) $old['id']) { throw $error; }
            }
        }
        if ($old['hash'] !== $input['hash'] || $old['body'] !== $input['body'] || $old['binding'] !== RestPeer::binding($settings)) {
            throw new \RuntimeException('REST work ID/hash/binding collision.');
        }
        $this->fields = $old;
        $this->attempt();
        return (int) $old['id'];
    }

    public function post_addItem()
    {
        // The insert is already committed in autocommit mode before the bounded pass starts.
        $this->attempt();
    }

    private function attempt(): void
    {
        global $DB;
        $body = self::decode($this->fields['body'], $this->fields['hash']);
        self::authorize($body);
        $lock = $DB->quote('assetsync20agent:' . substr(hash('sha256', (string) $DB->dbdefault), 0, 32));
        if ((int) (Outbox::one('SELECT GET_LOCK(' . $lock . ', 0) AS acquired')['acquired'] ?? 0) !== 1) { return; }
        $lockWait = null;
        try {
            $fresh = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($body['id']));
            if (!$fresh || $fresh['hash'] !== $this->fields['hash'] || $fresh['body'] !== $this->fields['body']) {
                throw new \RuntimeException('Persisted REST work changed.');
            }
            $this->fields = $fresh;
            $settings = self::authorize($body);
            if ($this->fields['binding'] !== RestPeer::binding($settings) || !RestPeer::idle()) { throw new \RuntimeException('REST execution binding changed.'); }
            $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']));
            if (!$journal && ($body['expires'] < time() || hrtime(true) >= $this->deadline)) { return; }
            $lockWait = (int) Outbox::one('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')['seconds'];
            Outbox::sql('SET SESSION innodb_lock_wait_timeout = 1');
            $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
            if ($this->fields['task_body'] === '{}') {
                $snapshot = ServiceContext::run($target, static fn (): array => TaskExecutor::snapshot($target, $body['version']));
                $task = ['version' => $body['version'], 'id' => $body['id'], 'kind' => 'apply'];
                if ($body['version'] === 8) { $task['batch_id'] = $body['batch_id']; }
                foreach (['installation', 'registration', 'generation', 'connection_id', 'pair_id', 'itemtype', 'items_id', 'entity_id'] as $key) { $task[$key] = $body[$key]; }
                $task += ['serial' => $body['identity']['serial'], 'policy' => $body['settings_hash'], 'expires' => $body['expires'],
                    'changes' => ['name' => ['type' => 'text', 'metadata' => $snapshot['fields']['name']['metadata'],
                        'expected' => $snapshot['fields']['name']['value'], 'value' => $body['name']]],
                    'identity' => TaskExecutor::identity($snapshot), 'guard' => ['modification' => $snapshot['modification'],
                        'native_values' => $snapshot['native_values'], 'origin_epoch' => null], 'rest_work_hash' => $this->fields['hash']];
                $task['hash'] = TaskExecutor::hash($task);
                $execution = array_intersect_key($target, array_flip(['service_user', 'service_profile', 'service_name', 'itemtype', 'items_id', 'entity_id', 'pair_id']));
                $fields = ['task_body' => json_encode($task, JSON_THROW_ON_ERROR), 'task_hash' => $task['hash'],
                    'execution_settings' => json_encode($execution, JSON_THROW_ON_ERROR)];
                if (!$DB->update(self::getTable(), $fields, ['id' => $this->getID(), 'task_body' => '{}']) || $DB->affectedRows() !== 1) {
                    throw new \RuntimeException('REST compiler persistence failed.');
                }
                $this->fields = array_replace($this->fields, $fields);
            }
            if (!$journal && hrtime(true) >= $this->deadline) { return; }
            $task = json_decode($this->fields['task_body'], true, 32, JSON_THROW_ON_ERROR);
            self::$executing = $body['id'];
            self::$executionDeadline = $this->deadline;
            try { TaskJournal::run($task, $settings); }
            finally { self::$executing = null; self::$executionDeadline = 0; }
        } finally {
            try {
                if ($lockWait !== null) { Outbox::sql('SET SESSION innodb_lock_wait_timeout = ' . $lockWait); }
            } finally {
                if ((int) (Outbox::one('SELECT RELEASE_LOCK(' . $lock . ') AS released')['released'] ?? 0) !== 1) {
                    throw new \RuntimeException('REST execution lock release unavailable.');
                }
            }
        }
    }

    /** Also fences direct calls into the shared executor or journal. */
    public static function authorizeTask(array $task, bool $execution = true): void
    {
        global $DB;
        $row = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($task['id']));
        if (!$row || $row['hash'] !== $task['rest_work_hash'] || $row['task_hash'] !== $task['hash']
            || json_decode($row['task_body'], true, 32, JSON_THROW_ON_ERROR) !== $task) { throw new \RuntimeException('Uncompiled REST task denied.'); }
        $body = self::decode($row['body'], $row['hash']);
        if (self::$executing === $task['id']) {
            if ($execution && hrtime(true) >= self::$executionDeadline) { throw new \RuntimeException('REST execution deadline reached.'); }
            // The original peer was admitted before entering the separate local writer login.
            $settings = Settings::load();
            RestPeer::policy($settings);
            RestPeer::grant($settings, $body['entity_id']);
            ServiceContext::httpAccount((int) $settings['service_user']);
        } else { $settings = self::authorize($body); }
        if ($row['binding'] !== RestPeer::binding($settings)) { throw new \RuntimeException('REST compiler binding changed.'); }
    }

    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) { return false; }
        $body = self::decode($this->fields['body'], $this->fields['hash']);
        if ($this->fields['binding'] !== RestPeer::binding(self::authorize($body))) { return false; }
        if ($body['version'] === 8) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
                && (isset($_GET['debug']) || !in_array($_GET['get_hateoas'] ?? null, ['0', 0], true))) { throw new \RuntimeException('Bounded REST response options required.'); }
            return true;
        }
        global $DB;
        $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']));
        if ($journal && ($journal['hash'] !== $this->fields['task_hash']
            || json_decode($journal['body'], true, 32, JSON_THROW_ON_ERROR) !== json_decode($this->fields['task_body'], true, 32, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('REST journal ID/hash conflict.');
        }
        $this->fields['state'] = $journal['state'] ?? ($body['expires'] < time() ? 'expired' : 'accepted');
        $this->fields['result'] = $journal['result'] ?? '{}';
        if ($journal && $journal['state'] === 'executing' && $journal['result'] === '{}') {
            $lock = $DB->quote('assetsync20agent:' . substr(hash('sha256', (string) $DB->dbdefault), 0, 32));
            $owner = Outbox::one('SELECT IS_USED_LOCK(' . $lock . ') AS owner');
            if (($owner['owner'] ?? null) === null) {
                $fresh = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']));
                if (!$fresh || $fresh['hash'] !== $this->fields['task_hash'] || $fresh['body'] !== $journal['body']) {
                    throw new \RuntimeException('REST journal changed during observation.');
                }
                $this->fields['state'] = $fresh['state'];
                $this->fields['result'] = $fresh['result'];
                if ($fresh['state'] === 'executing' && $fresh['result'] === '{}') {
                    $this->fields['state'] = 'uncertain';
                    $this->fields['result'] = json_encode(['outcome' => 'uncertain', 'changed' => []], JSON_THROW_ON_ERROR);
                }
            }
        }
        return true;
    }

    public function update(array $input, $history = true, $options = []) { return false; }
    public function delete(array $input, $force = false, $history = true) { return false; }
}
