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
        if (in_array((int) ($fields['compiler'] ?? 0), [8, 9], true)) {
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
        $read = ($_GET['searchText']['compiler'] ?? null) === '9';
        if ($read) {
            $id = $_GET['searchText']['work_id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || isset($_GET['searchText']['batch_id']) || isset($_GET['debug']) || ($_GET['get_hateoas'] ?? null) !== '0') {
                throw new \RuntimeException('Exact bounded REST read lookup required.');
            }
            $where['AND'][$table . '.compiler'] = 9;
            $where['AND'][$table . '.work_id'] = $id;
            $settings = RestPeer::settings();
            ServiceContext::account((int) $settings['service_user']);
            $profile = new \Profile();
            if (!$profile->getFromDB((int) $settings['service_profile']) || ((int) ($profile->fields['computer'] ?? 0) & READ) !== READ) { throw new \RuntimeException('Service Computer READ denied.'); }
            $entities = array_keys(Settings::entities((int) $settings['service_user'], (int) $settings['service_profile']));
            $where['AND'][$table . '.entities_id'] = array_values(array_intersect($where['AND'][$table . '.entities_id'], $entities)) ?: [-1];
        } else { $where[] = new \Glpi\DBAL\QueryExpression('`' . $table . '`.`compiler` <> 9'); }
        // v8 is exposed only through explicitly bounded batch reads, not legacy collections.
        if (!isset($_GET['searchText']['batch_id'])) { $where[] = new \Glpi\DBAL\QueryExpression('`' . $table . '`.`compiler` <> 8'); }
        else {
            $batch = $_GET['searchText']['batch_id'];
            if (!is_string($batch) || !preg_match('/^[a-f0-9]{32}$/D', $batch)) { throw new \RuntimeException('Exact REST batch ID required.'); }
            $where['AND'][$table . '.batch_id'] = $batch;
            $where['AND'][$table . '.compiler'] = 8;
        }
        $where[] = new \Glpi\DBAL\QueryExpression('((' . ($read ? '`' . $table . '`.`compiler` = 9 AND JSON_EXTRACT(`' . $table . '`.`body`, \'$.kind\') = \'metadata\'' : '0') . ') OR EXISTS (SELECT 1 FROM `glpi_computers` AS `rest_target`
            WHERE `rest_target`.`id` = JSON_EXTRACT(`' . $table . '`.`body`, \'$.items_id\')
            AND `rest_target`.`entities_id` = `' . $table . '`.`entities_id`
            AND `rest_target`.`is_deleted` = 0 AND `rest_target`.`is_template` = 0))');
        return $where;
    }

    public static function decode(string $json, string $hash): array
    {
        $body = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (is_array($body) && ($body['version'] ?? null) === 9) {
            $keys = ['version', 'id', 'kind', 'installation', 'registration', 'generation', 'connection_id', 'itemtype', 'entity_id', 'items_id', 'pair_id', 'expires'];
            if (array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body)) || strlen($json) > 4096
                || !hash_equals(TaskExecutor::hash($body), $hash) || !in_array($body['kind'], ['metadata', 'inspect'], true)
                || $body['itemtype'] !== 'Computer' || !is_int($body['entity_id']) || $body['entity_id'] < 0
                || !is_int($body['items_id']) || !is_int($body['expires'])
                || !is_string($body['connection_id']) || $body['connection_id'] === '' || strlen($body['connection_id']) > 255
                || preg_match('/[\x00-\x20\x7f]/', $body['connection_id'])) { throw new \RuntimeException('Invalid read-only REST work.'); }
            foreach (['id', 'installation', 'registration', 'generation'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $body[$key])) { throw new \RuntimeException('Invalid REST read ID.'); }
            }
            if ($body['kind'] === 'metadata' ? ($body['items_id'] !== 0 || $body['pair_id'] !== '')
                : ($body['items_id'] <= 0 || !is_string($body['pair_id']) || !preg_match('/^[a-f0-9]{32}$/D', $body['pair_id']))) { throw new \RuntimeException('Invalid REST read target.'); }
            return $body;
        }
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
        if ($body['version'] === 9) {
            $settings = RestPeer::read($body);
            ServiceContext::readPermission(array_replace($settings, ['entity_id' => $body['entity_id']]));
            return $settings;
        }
        $settings = RestPeer::work($body);
        global $DB;
        $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
        if (!$policy || $policy['hash'] !== $body['settings_hash']) {
            throw new \RuntimeException('Pinned REST settings unavailable.');
        }
        $document = RestSettings::decode($policy['body'], $policy['hash']);
        if ($policy['binding'] !== RestSettings::binding($settings, $document['version'])) { throw new \RuntimeException('Pinned REST binding changed.'); }
        RestSettings::authorize($document);
        if ($document['target_entity'] !== $body['entity_id']) { throw new \RuntimeException('Pinned REST route changed.'); }
        return $settings;
    }

    private static function workBinding(array $body, array $settings): string
    {
        if ($body['version'] === 9) { return RestSettings::binding($settings, 2); }
        global $DB;
        $row = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
        $document = RestSettings::decode($row['body'], $row['hash']);
        return RestSettings::binding($settings, $document['version']);
    }

    public function add(array $input, $options = [], $history = true)
    {
        $this->deadline = hrtime(true) + 5_000_000_000;
        if (array_diff(array_keys($input), ['work_id', 'hash', 'body', 'entities_id', '_add'])) { throw new \RuntimeException('Unknown REST work input.'); }
        $body = self::decode((string) ($input['body'] ?? ''), (string) ($input['hash'] ?? ''));
        if (in_array($body['version'], [8, 9], true)) { $this->deadline = RestPeer::deadline(); }
        $settings = self::authorize($body);
        if (($input['work_id'] ?? '') !== $body['id'] || !RestPeer::idle()) { throw new \RuntimeException('REST acceptance unavailable.'); }
        global $DB;
        $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($body['id']));
        if (!$old) {
            if ($body['version'] === 9 && ($settings['transport'] ?? 'adapter') !== 'rest_records') { throw new \RuntimeException('REST read transport unavailable.'); }
            if (Outbox::one('SELECT `id` FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']))) {
                throw new \RuntimeException('REST work ID is already used by retained journal evidence.');
            }
            if ($body['expires'] < time() || $body['expires'] > time() + 300) { throw new \RuntimeException('REST work expired.'); }
            $fields = ['work_id' => $body['id'], 'hash' => $input['hash'], 'body' => $input['body'],
                'binding' => self::workBinding($body, $settings), 'entities_id' => $body['entity_id'], 'accepted_at' => time(),
                'task_body' => '{}', 'execution_settings' => '{}'];
            foreach (['connection_id', 'registration', 'generation', 'installation'] as $key) { $fields[$key] = $body[$key]; }
            if (in_array($body['version'], [8, 9], true)) {
                if (hrtime(true) >= $this->deadline) { throw new \RuntimeException('REST batch acceptance deadline reached.'); }
                $fields += ['compiler' => $body['version'], 'batch_id' => $body['batch_id'] ?? null, 'state' => 'accepted', 'result' => '{}', 'expires' => $body['expires']];
                self::assertResponseSize($fields + ['id' => PHP_INT_MAX]);
            }
            try { return parent::add($fields, $options, false); }
            catch (\RuntimeException $error) {
                $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($body['id']));
                if (!$old) { throw $error; }
                if ((int) ($this->fields['id'] ?? 0) === (int) $old['id']) { throw $error; }
            }
        }
        if ($old['hash'] !== $input['hash'] || $old['body'] !== $input['body'] || $old['binding'] !== self::workBinding($body, $settings)) {
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
            if ($this->fields['binding'] !== self::workBinding($body, $settings) || !RestPeer::idle()) { throw new \RuntimeException('REST execution binding changed.'); }
            if (($this->fields['result'] ?? '{}') !== '{}') { return; }
            if ($body['version'] === 9) { $this->observe($body, $settings); return; }
            $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']));
            if (!$journal) {
                $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
                $document = RestSettings::decode($policy['body'], $policy['hash']);
                if (!RestSettings::active($document) || ($document['version'] === 2 && ($settings['transport'] ?? 'adapter') !== 'rest_records')) {
                    $this->storeObservation(['outcome' => 'stale', 'changed' => [], 'reason' => 'settings_superseded']);
                    return;
                }
            }
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

    private function observe(array $body, array $settings): void
    {
        if (($settings['transport'] ?? 'adapter') !== 'rest_records' || $body['expires'] < time() || hrtime(true) >= $this->deadline) { return; }
        $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
        $result = ServiceContext::run($target, static function () use ($body, $target): array {
            $item = ServiceContext::readComputer($target);
            $options = array_values(array_filter($item->rawSearchOptions(), static fn ($o): bool => is_array($o)
                && ($o['table'] ?? '') === 'glpi_computers' && ($o['field'] ?? '') === 'name'));
            if (count($options) !== 1) { throw new \RuntimeException('Core Name metadata unavailable.'); }
            $option = $options[0];
            $result = ['outcome' => 'observed', 'changed' => [], 'itemtype' => 'Computer', 'entity_id' => $body['entity_id'],
                'observed_at' => time(), 'fields' => [['key' => 'name', 'uid' => 'name', 'id' => (string) $option['id'],
                    'label' => (string) $option['name'], 'type' => 'text', 'group' => 'Native', 'supported' => true, 'reason' => '']]];
            if ($body['kind'] === 'inspect') {
                $identity = TaskExecutor::identity($item->fields);
                $result['snapshot'] = ['itemtype' => 'Computer', 'items_id' => $body['items_id'], 'entity_id' => $body['entity_id'],
                    'pair_id' => $body['pair_id'], 'name' => (string) $item->fields['name']] + $identity;
            }
            return $result;
        });
        if ($body['expires'] < time() || hrtime(true) >= $this->deadline) { $result = ['outcome' => 'stale', 'changed' => []]; }
        $this->storeObservation($result);
    }

    /** Projection-only transaction: no asset mutation and no execution on GET. */
    private function storeObservation(array $result): void
    {
        global $DB;
        if (!RestPeer::idle()) { throw new \RuntimeException('REST observation requires an idle database connection.'); }
        $fields = ['state' => $result['outcome'], 'result' => json_encode($result, JSON_THROW_ON_ERROR)];
        self::assertResponseSize(array_replace($this->fields, $fields));
        Outbox::sql('START TRANSACTION');
        try {
            if (!$DB->update(self::getTable(), $fields, ['id' => $this->getID(), 'hash' => $this->fields['hash'], 'task_body' => $this->fields['task_body']])
                || $DB->affectedRows() !== 1) { throw new \RuntimeException('REST observation storage failed.'); }
            $saved = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($this->fields['work_id']));
            if (!$saved || $saved['state'] !== $fields['state'] || $saved['result'] !== $fields['result']) { throw new \RuntimeException('REST observation readback failed.'); }
            Outbox::sql('COMMIT');
            $this->fields = $saved;
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
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
        if ($row['binding'] !== self::workBinding($body, $settings)) { throw new \RuntimeException('REST compiler binding changed.'); }
        if ($execution) {
            $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
            $document = RestSettings::decode($policy['body'], $policy['hash']);
            if (!RestSettings::active($document) || ($document['version'] === 2 && ($settings['transport'] ?? 'adapter') !== 'rest_records')) { throw new \RuntimeException('REST settings superseded.'); }
        }
    }

    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) { return false; }
        $body = self::decode($this->fields['body'], $this->fields['hash']);
        if ($this->fields['binding'] !== self::workBinding($body, self::authorize($body))) { return false; }
        if (in_array($body['version'], [8, 9], true)) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
                && (isset($_GET['debug']) || !in_array($_GET['get_hateoas'] ?? null, ['0', 0], true))) { throw new \RuntimeException('Bounded REST response options required.'); }
            return true;
        }
        if (($this->fields['result'] ?? null) !== null && $this->fields['result'] !== '{}') { return true; }
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
