<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Acceptance is separate from TaskJournal's durable mutation intent. */
final class RestWork extends \CommonDBTM
{
    private const ROW_BYTES = 16000;
    private int $deadline = 0;
    private array $allowRows = [];
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
        if (in_array((int) ($fields['compiler'] ?? 0), [12, 13], true) && isset($fields['id'], $fields['body'], $fields['hash'], $fields['binding'])) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
                RestPeer::textReadOptions((int) $fields['compiler']);
                if ((int) $fields['compiler'] === 12) { $fields['read_observation'] = TextBoth::disclose($fields); }
                else { self::discloseCatalog($fields); }
            }
            unset($fields['execution_settings'], $fields['task_body'], $fields['task_hash']);
        }
        if ((int) ($fields['compiler'] ?? 0) === 11 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && ($fields['result'] ?? '{}') !== '{}') { NameBoth::assertReadable($fields); }
        if (in_array((int) ($fields['compiler'] ?? 0), [8, 9, 10, 11], true)) {
            unset($fields['execution_settings'], $fields['task_body'], $fields['task_hash']);
        } elseif ((int) ($fields['compiler'] ?? 0) === 7) {
            unset($fields['batch_id'], $fields['expires']);
            foreach (['state', 'result'] as $key) { if (array_key_exists($key, $fields) && $fields[$key] === null) { unset($fields[$key]); } }
        }
    }

    /** Called only inside the journal/model transaction, never from GET. */
    public static function project(array $task, string $state, array $result): void
    {
        if (!in_array($task['version'], [8, 10], true)) { return; }
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
        $limit = (int) ($row['compiler'] ?? 0) === 12 ? 60000 : (in_array((int) ($row['compiler'] ?? 0), [11, 13], true) ? 31000 : self::ROW_BYTES);
        if (strlen(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > $limit) {
            throw new \RuntimeException('REST public record exceeds response budget.');
        }
    }

    public static function assertTextResponse(array $row, array $result): void
    {
        $row['result'] = $result === [] ? '{}' : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        unset($row['execution_settings'], $row['task_body'], $row['task_hash']);
        $fields = [];
        foreach ($result['fields'] ?? [] as $field) {
            $fields[] = ['mapping_id' => $field['mapping_id'], 'receipt_hash' => str_repeat('f', 64), 'current_matches' => false];
        }
        $row['read_observation'] = ['revision' => 25, 'receipt_hash' => str_repeat('f', 64), 'fields' => $fields];
        $bytes = strlen(json_encode($row, JSON_THROW_ON_ERROR));
        if ($bytes > 60000 || $bytes + 4096 > 65536) { throw new \RuntimeException('response_limit'); }
    }

    public static function projectText(array $row, string $state, array $result): void
    {
        global $DB;
        $fresh = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id`=' . $DB->quote($row['work_id']) . ' FOR UPDATE');
        $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id`=' . $DB->quote($row['work_id']) . ' FOR UPDATE');
        if (!$fresh || !$journal || (int) $fresh['compiler'] !== 12 || $fresh['body'] !== $row['body'] || $fresh['hash'] !== $row['hash']
            || $journal['body'] !== $row['body'] || $journal['hash'] !== $row['hash']) { throw new \RuntimeException('Text projection identity changed.'); }
        $raw = $result === [] ? '{}' : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $document = TextBoth::decodeJson($journal['result'], 131072);
        if ($journal['state'] !== $state || ($document['result'] ?? null) !== $result || TextBoth::state($result) !== $state) { throw new \RuntimeException('Text journal projection mismatch.'); }
        $body = self::decode($row['body'], $row['hash']);
        $stored = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id`=' . $DB->quote($body['settings_id']));
        $policy = RestSettings::decode($stored['body'], $stored['hash']);
        TextBoth::validateProgress($body, $policy, TextBoth::decodeJson($fresh['result'], 65536), $result);
        self::assertTextResponse(array_replace($fresh, ['state' => $state]), $result);
        if (!$DB->update(self::getTable(), ['state' => $state, 'result' => $raw], ['id' => $fresh['id'], 'hash' => $row['hash'], 'body' => $row['body'], 'result' => $fresh['result']])
            || $DB->affectedRows() !== 1) { throw new \RuntimeException('Text projection changed.'); }
    }

    /** Work11 stores its external identity directly in the existing private journal. */
    public static function projectName(array $row, string $state, array $result): void
    {
        global $DB;
        $fresh = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id`=' . $DB->quote($row['work_id']) . ' FOR UPDATE');
        $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id`=' . $DB->quote($row['work_id']) . ' FOR UPDATE');
        if (!$fresh || !$journal || (int) $fresh['compiler'] !== 11 || $fresh['body'] !== $row['body']
            || $fresh['hash'] !== $row['hash'] || $journal['body'] !== $row['body'] || $journal['hash'] !== $row['hash']) {
            throw new \RuntimeException('Name REST projection identity changed.');
        }
        $fields = ['state' => $state, 'result' => $result === [] ? '{}' : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        self::assertResponseSize(array_replace($fresh, $fields));
        if (!$DB->update(self::getTable(), $fields, ['id' => $fresh['id'], 'hash' => $row['hash']]) || $DB->affectedRows() !== 1) {
            throw new \RuntimeException('Name REST projection failed.');
        }
    }

    public static function authorizeName(array $row, array $body, bool $mutation, int $deadline): void
    {
        if ($mutation && ($body['expires'] < time() || hrtime(true) >= $deadline)) { throw new \RuntimeException('Name REST execution deadline reached.'); }
        global $DB;
        $settings = self::authorize($body);
        $fresh = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id`=' . $DB->quote($body['id']));
        if (!$fresh || $fresh['hash'] !== $row['hash'] || $fresh['body'] !== $row['body']
            || $fresh['binding'] !== self::workBinding($body, $settings)) { throw new \RuntimeException('Name REST binding changed.'); }
        if ($mutation) {
            $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id`=' . $DB->quote($body['settings_id']));
            $document = RestSettings::decode($policy['body'], $policy['hash']);
            if ($document['version'] !== 4 || !RestSettings::active($document) || ($settings['transport'] ?? '') !== 'rest_records') {
                throw new \RuntimeException('Name REST settings superseded.');
            }
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
        $compiler = $_GET['searchText']['compiler'] ?? null;
        if (in_array($compiler, ['12', '13'], true)) {
            RestPeer::textReadOptions((int) $compiler);
            $where = RestSettings::getSystemSQLCriteria($table);
            $where['AND'][$table . '.compiler'] = (int) $compiler;
            $where['AND'][$table . '.entities_id'] = (int) $_GET['rest_entity'];
            $key = $compiler === '13' ? 'work_id' : 'batch_id';
            $where['AND'][$table . '.' . $key] = $_GET['searchText'][$key];
            if ($compiler === '12') {
                $where[] = new \Glpi\DBAL\QueryExpression('EXISTS (SELECT 1 FROM `glpi_computers` AS `text_target` WHERE `text_target`.`id`=JSON_EXTRACT(`' . $table . '`.`body`, \'$.items_id\') AND `text_target`.`entities_id`=`' . $table . '`.`entities_id` AND `text_target`.`is_deleted`=0 AND `text_target`.`is_template`=0)');
            }
            return $where;
        }
        $where = RestSettings::getSystemSQLCriteria($table);
        $where[] = new \Glpi\DBAL\QueryExpression('`' . $table . '`.`compiler` NOT IN (12, 13)');
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
        if (!isset($_GET['searchText']['batch_id'])) { $where[] = new \Glpi\DBAL\QueryExpression('`' . $table . '`.`compiler` NOT IN (8, 10, 11)'); }
        else {
            $batch = $_GET['searchText']['batch_id'];
            if (!is_string($batch) || !preg_match('/^[a-f0-9]{32}$/D', $batch)) { throw new \RuntimeException('Exact REST batch ID required.'); }
            $where['AND'][$table . '.batch_id'] = $batch;
            $compiler = $_GET['searchText']['compiler'] ?? '8';
            if (!in_array($compiler, ['8', '10', '11'], true)) { throw new \RuntimeException('Unsupported REST batch compiler.'); }
            if ($compiler === '10' && !isset($_GET['rest_entity'])) { throw new \RuntimeException('Exact REST lookup entity required.'); }
            if ($compiler === '11' && (isset($_GET['debug']) || ($_GET['get_hateoas'] ?? null) !== '0'
                || !is_string($_GET['range'] ?? null) || !preg_match('/^([0-9]+)-([0-9]+)$/D', $_GET['range'], $page)
                || (int) $page[2] < (int) $page[1] || (int) $page[2] - (int) $page[1] >= 2)) {
                throw new \RuntimeException('Name Both requires at most two bounded result rows.');
            }
            $where['AND'][$table . '.compiler'] = (int) $compiler;
        }
        if (isset($_GET['rest_entity'])) { $where['AND'][$table . '.entities_id'] = (int) $_GET['rest_entity']; }
        $where[] = new \Glpi\DBAL\QueryExpression('((' . ($read ? '`' . $table . '`.`compiler` = 9 AND JSON_EXTRACT(`' . $table . '`.`body`, \'$.kind\') = \'metadata\'' : '0') . ') OR EXISTS (SELECT 1 FROM `glpi_computers` AS `rest_target`
            WHERE `rest_target`.`id` = JSON_EXTRACT(`' . $table . '`.`body`, \'$.items_id\')
            AND `rest_target`.`entities_id` = `' . $table . '`.`entities_id`
            AND `rest_target`.`is_deleted` = 0 AND `rest_target`.`is_template` = 0))');
        return $where;
    }

    public static function decode(string $json, string $hash): array
    {
        $body = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (is_array($body) && in_array($body['version'] ?? null, [12, 13], true)) {
            $body = TextBoth::decodeJson($json, $body['version'] === 12 ? 24576 : 4096);
            $keys = $body['version'] === 12 ? ['version', 'id', 'installation', 'registration', 'generation', 'connection_id', 'settings_id',
                'settings_hash', 'pair_id', 'itemtype', 'source_items_id', 'source_asset_entity', 'items_id', 'entity_id', 'identity', 'sources', 'source_hash', 'source_fingerprint', 'expires', 'batch_id']
                : ['version', 'id', 'kind', 'installation', 'registration', 'generation', 'connection_id', 'itemtype', 'entity_id', 'selector', 'expires'];
            if (array_keys($body) !== $keys || !hash_equals(TaskExecutor::hash($body), $hash) || $body['itemtype'] !== 'Computer'
                || !is_int($body['entity_id']) || $body['entity_id'] < 0 || !is_int($body['expires']) || $body['expires'] < 0
                || !is_string($body['connection_id']) || $body['connection_id'] === '' || strlen($body['connection_id']) > 255
                || preg_match('/[\x00-\x20\x7f]/', $body['connection_id'])) { throw new \RuntimeException('Invalid text REST work.'); }
            foreach ($body['version'] === 12 ? ['id', 'installation', 'registration', 'generation', 'settings_id', 'pair_id', 'batch_id'] : ['id', 'installation', 'registration', 'generation'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $body[$key])) { throw new \RuntimeException('Invalid text REST ID.'); }
            }
            if ($body['version'] === 12) {
                foreach (['settings_hash', 'source_hash', 'source_fingerprint'] as $key) {
                    if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $body[$key])) { throw new \RuntimeException('Invalid text REST hash.'); }
                }
                if (!is_int($body['source_items_id']) || $body['source_items_id'] < 1 || !is_int($body['source_asset_entity']) || $body['source_asset_entity'] < 0
                    || !is_int($body['items_id']) || $body['items_id'] < 1 || !is_array($body['identity']) || !in_array(array_keys($body['identity']), [['serial'], ['serial', 'otherserial']], true)
                    || array_intersect_key(TaskExecutor::identity($body['identity'] + ['otherserial' => '']), $body['identity']) !== $body['identity']
                    || !is_array($body['sources']) || !array_is_list($body['sources']) || count($body['sources']) < 1 || count($body['sources']) > 8
                    || $body['source_hash'] !== TaskExecutor::hash($body['sources'])) { throw new \RuntimeException('Invalid text REST source.'); }
                $previous = '';
                foreach ($body['sources'] as $source) {
                    if (!is_array($source) || array_keys($source) !== ['mapping_id', 'envelope'] || !is_string($source['mapping_id'])
                        || !preg_match('/^[a-f0-9]{32}$/D', $source['mapping_id']) || strcmp($source['mapping_id'], $previous) <= 0
                        || !is_array($source['envelope'])) { throw new \RuntimeException('Invalid text source order.'); }
                    $previous = $source['mapping_id'];
                }
            } else {
                $selector = $body['selector'];
                if ($body['kind'] !== 'catalog' || !is_array($selector)) { throw new \RuntimeException('Invalid text catalog.'); }
                $valid = $selector === ['kind' => 'native']
                    || (array_keys($selector) === ['kind', 'after_id'] && $selector['kind'] === 'fields' && is_int($selector['after_id']) && $selector['after_id'] >= 0)
                    || (array_keys($selector) === ['kind', 'capture_keys'] && $selector['kind'] === 'selected' && is_array($selector['capture_keys'])
                        && array_is_list($selector['capture_keys']) && count($selector['capture_keys']) >= 1 && count($selector['capture_keys']) <= 8);
                if (!$valid) { throw new \RuntimeException('Invalid text catalog selector.'); }
                if ($selector['kind'] === 'selected') {
                    $sorted = array_values(array_unique($selector['capture_keys'])); sort($sorted, SORT_STRING);
                    if ($sorted !== $selector['capture_keys']) { throw new \RuntimeException('Invalid selected catalog order.'); }
                    foreach ($sorted as $key) {
                        if (!is_string($key) || !preg_match('/^(?:native:(?:name|comment)|fields:[1-9][0-9]*)$/D', $key)) { throw new \RuntimeException('Invalid selected catalog key.'); }
                    }
                }
            }
            return $body;
        }
        if (is_array($body) && ($body['version'] ?? null) === 11) {
            $keys = ['version', 'id', 'installation', 'registration', 'generation', 'connection_id', 'settings_id', 'settings_hash',
                'pair_id', 'itemtype', 'items_id', 'entity_id', 'identity', 'source', 'source_hash', 'source_fingerprint', 'expires', 'batch_id'];
            if (array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body)) || strlen($json) > 12000
                || !hash_equals(TaskExecutor::hash($body), $hash) || !is_array($body['source'])
                || $body['itemtype'] !== 'Computer' || !is_int($body['items_id']) || $body['items_id'] < 1
                || !is_int($body['entity_id']) || $body['entity_id'] < 0 || !is_int($body['expires'])
                || !is_string($body['connection_id']) || $body['connection_id'] === '' || strlen($body['connection_id']) > 255
                || preg_match('/[\x00-\x20\x7f]/', $body['connection_id']) || !is_array($body['identity'])
                || !in_array(array_keys($body['identity']), [['serial'], ['serial', 'otherserial']], true)
                || array_intersect_key(TaskExecutor::identity($body['identity'] + ['otherserial' => '']), $body['identity']) !== $body['identity']) {
                throw new \RuntimeException('Invalid Name Both REST work.');
            }
            foreach (['id', 'installation', 'registration', 'generation', 'settings_id', 'pair_id', 'batch_id'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $body[$key])) { throw new \RuntimeException('Invalid Name Both ID.'); }
            }
            foreach (['settings_hash', 'source_hash', 'source_fingerprint'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $body[$key])) { throw new \RuntimeException('Invalid Name Both hash.'); }
            }
            NameBoth::validateEnvelope($body['source']);
            if ($body['source_hash'] !== TaskExecutor::hash($body['source'])) { throw new \RuntimeException('Name Both source guard mismatch.'); }
            return $body;
        }
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
        if (in_array($body['version'] ?? null, [8, 10], true)) { $keys[] = 'batch_id'; }
        if (($body['version'] ?? null) === 10) { $keys[] = 'source_asset_entity'; }
        if (!is_array($body) || array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))
            || strlen($json) > 32768 || !hash_equals(TaskExecutor::hash($body), $hash) || !in_array($body['version'], [7, 8, 10], true)
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
        if (in_array($body['version'], [8, 10], true) && (!is_string($body['batch_id']) || !preg_match('/^[a-f0-9]{32}$/D', $body['batch_id']))) {
            throw new \RuntimeException('Invalid REST batch ID.');
        }
        if ($body['version'] === 10 && (!is_int($body['source_asset_entity']) || $body['source_asset_entity'] < 0)) { throw new \RuntimeException('Invalid REST source asset entity.'); }
        foreach (['settings_hash', 'source_hash'] as $key) {
            if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $body[$key])) { throw new \RuntimeException('Invalid REST hash.'); }
        }
        return $body;
    }

    private static function authorize(array $body): array
    {
        if ($body['version'] === 13) {
            $settings = RestPeer::read($body + ['items_id' => 0]);
            ServiceContext::readPermission(array_replace($settings, ['entity_id' => $body['entity_id']]));
            return $settings;
        }
        if ($body['version'] === 12 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { $settings = RestPeer::read($body); }
        else { $settings = null; }
        if ($body['version'] === 9) {
            $settings = RestPeer::read($body);
            ServiceContext::readPermission(array_replace($settings, ['entity_id' => $body['entity_id']]));
            return $settings;
        }
        $settings ??= RestPeer::work($body);
        global $DB;
        $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
        if (!$policy || $policy['hash'] !== $body['settings_hash']) {
            throw new \RuntimeException('Pinned REST settings unavailable.');
        }
        $document = RestSettings::decode($policy['body'], $policy['hash']);
        if ($body['version'] === 11 && $document['version'] !== 4) { throw new \RuntimeException('Name Both requires settings4.'); }
        if ($policy['binding'] !== RestSettings::binding($settings, $document['version'])) { throw new \RuntimeException('Pinned REST binding changed.'); }
        RestSettings::authorize($document);
        if ($document['target_entity'] !== $body['entity_id']) { throw new \RuntimeException('Pinned REST route changed.'); }
        if ($body['version'] === 12) {
            if ($document['version'] !== 5 || $document['mappings'] === [] || (!$document['include_child_entities'] && $body['source_asset_entity'] !== $document['source_entity'])) { throw new \RuntimeException('Text REST source scope changed.'); }
            TextBoth::validateSources($body, $document);
            return $settings;
        }
        if ($document['version'] === 5) { throw new \RuntimeException('Text settings require Work12.'); }
        if ($body['version'] === 10) {
            if ($document['version'] !== 3 || !in_array($body['itemtype'], $document['asset_types'], true)
                || (!$document['include_child_entities'] && $body['source_asset_entity'] !== $document['source_entity'])) { throw new \RuntimeException('Pinned REST source scope changed.'); }
        } elseif ($document['version'] === 3 || ($document['version'] === 4 && $body['version'] !== 11)) {
            throw new \RuntimeException('Versioned REST scope requires its matching work compiler.');
        }
        return $settings;
    }

    private static function workBinding(array $body, array $settings): string
    {
        if (in_array($body['version'], [9, 13], true)) { return RestSettings::binding($settings, 2); }
        global $DB;
        $row = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
        $document = RestSettings::decode($row['body'], $row['hash']);
        return RestSettings::binding($settings, $document['version']);
    }

    public function add(array $input, $options = [], $history = true)
    {
        $this->deadline = hrtime(true) + 5_000_000_000;
        if (array_diff(array_keys($input), ['work_id', 'hash', 'body', 'entities_id', '_add', 'allow_rows'])) { throw new \RuntimeException('Unknown REST work input.'); }
        $body = self::decode((string) ($input['body'] ?? ''), (string) ($input['hash'] ?? ''));
        if ($body['version'] === 12) {
            if (array_diff(array_keys($input), ['work_id', 'hash', 'body', 'allow_rows', 'entities_id', '_add'])) { throw new \RuntimeException('Unknown Text Both work input.'); }
            if (!is_array($input['allow_rows'] ?? null) || strlen(json_encode(['input' => $input], JSON_THROW_ON_ERROR)) > 49152) { throw new \RuntimeException('Restrictive row mask or request budget invalid.'); }
            TextBoth::validateAllowRows($input['allow_rows'], array_column($body['sources'], 'mapping_id'));
            $this->allowRows = $input['allow_rows'];
        } elseif (array_key_exists('allow_rows', $input)) { throw new \RuntimeException('Unexpected restrictive row mask.'); }
        if (in_array($body['version'], [8, 9, 10, 11, 12, 13], true)) { $this->deadline = RestPeer::deadline(); }
        $settings = self::authorize($body);
        if (($input['work_id'] ?? '') !== $body['id'] || !RestPeer::idle()) { throw new \RuntimeException('REST acceptance unavailable.'); }
        global $DB;
        $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `work_id` = ' . $DB->quote($body['id']));
        if (!$old) {
            if (in_array($body['version'], [9, 13], true) && ($settings['transport'] ?? 'adapter') !== 'rest_records') { throw new \RuntimeException('REST read transport unavailable.'); }
            if (Outbox::one('SELECT `id` FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']))) {
                throw new \RuntimeException('REST work ID is already used by retained journal evidence.');
            }
            if ($body['expires'] < time() || $body['expires'] > time() + 300) { throw new \RuntimeException('REST work expired.'); }
            $fields = ['work_id' => $body['id'], 'hash' => $input['hash'], 'body' => $input['body'],
                'binding' => self::workBinding($body, $settings), 'entities_id' => $body['entity_id'], 'accepted_at' => time(),
                'task_body' => '{}', 'execution_settings' => '{}'];
            foreach (['connection_id', 'registration', 'generation', 'installation'] as $key) { $fields[$key] = $body[$key]; }
            if (in_array($body['version'], [8, 9, 10, 11, 12, 13], true)) {
                if (hrtime(true) >= $this->deadline) { throw new \RuntimeException('REST batch acceptance deadline reached.'); }
                $fields += ['compiler' => $body['version'], 'batch_id' => $body['batch_id'] ?? null, 'state' => 'accepted', 'result' => '{}', 'expires' => $body['expires']];
                self::assertResponseSize($fields + ['id' => PHP_INT_MAX]);
                if ($body['version'] === 12) { self::assertTextResponse($fields + ['id' => PHP_INT_MAX], []); }
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
            if ($body['version'] !== 12 && ($this->fields['result'] ?? '{}') !== '{}') { return; }
            if ($body['version'] === 13) { $this->observeCatalog($body, $settings); return; }
            if ($body['version'] === 12) {
                $lockWait = (int) Outbox::one('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')['seconds'];
                Outbox::sql('SET SESSION innodb_lock_wait_timeout = 1');
                $stored = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id`=' . $DB->quote($body['settings_id']));
                $policy = RestSettings::decode($stored['body'], $stored['hash']);
                TextBoth::run($this->fields, $body, $policy, $this->deadline, $this->allowRows);
                return;
            }
            if ($body['version'] === 9) { $this->observe($body, $settings); return; }
            $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($body['id']));
            if (!$journal) {
                $policy = Outbox::one('SELECT * FROM `' . RestSettings::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['settings_id']));
                $document = RestSettings::decode($policy['body'], $policy['hash']);
                if (!RestSettings::active($document) || (in_array($document['version'], [2, 3, 4], true) && ($settings['transport'] ?? 'adapter') !== 'rest_records')) {
                    $this->storeObservation(['outcome' => 'stale', 'changed' => [], 'reason' => 'settings_superseded']);
                    return;
                }
            }
            if (!$journal && ($body['expires'] < time() || hrtime(true) >= $this->deadline)) { return; }
            $lockWait = (int) Outbox::one('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')['seconds'];
            Outbox::sql('SET SESSION innodb_lock_wait_timeout = 1');
            if ($body['version'] === 11) {
                try { NameBoth::run($this->fields, $body, $settings, $this->deadline); }
                catch (\Throwable $error) { throw new \RuntimeException('Name REST work remains unverified; retained journal reconciliation required.', 0, $error); }
                return;
            }
            $target = array_replace($settings, array_intersect_key($body, array_flip(['itemtype', 'items_id', 'entity_id', 'pair_id'])));
            if ($this->fields['task_body'] === '{}') {
                $snapshot = ServiceContext::run($target, static fn (): array => TaskExecutor::snapshot($target, $body['version']));
                $task = ['version' => $body['version'], 'id' => $body['id'], 'kind' => 'apply'];
                if (in_array($body['version'], [8, 10], true)) { $task['batch_id'] = $body['batch_id']; }
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

    private function observeCatalog(array $body, array $settings): void
    {
        $target = array_replace($settings, ['itemtype' => 'Computer', 'items_id' => 0, 'entity_id' => $body['entity_id']]);
        try {
            if (time() > $body['expires'] || hrtime(true) >= $this->deadline) { throw new \RuntimeException('expired'); }
            $result = ServiceContext::run($target, function () use ($target, $body): array {
                ServiceContext::readComputer($target);
                return Snapshot::textCatalog($body, $this->deadline);
            });
            if (time() > $body['expires'] || hrtime(true) >= $this->deadline) { throw new \RuntimeException('expired'); }
            self::assertResponseSize(array_replace($this->fields, ['state' => 'observed', 'result' => json_encode($result, JSON_THROW_ON_ERROR)]));
        } catch (\Throwable $error) {
            $reason = in_array($error->getMessage(), ['field_unavailable', 'unsupported_field', 'metadata_invalid', 'response_limit', 'expired'], true) ? $error->getMessage() : 'field_unavailable';
            $result = ['outcome' => 'blocked', 'reason' => $reason];
        }
        $this->storeObservation($result);
    }

    private static function discloseCatalog(array $row): void
    {
        $body = self::decode($row['body'], $row['hash']);
        $settings = self::authorize($body);
        if ($row['binding'] !== self::workBinding($body, $settings)) { throw new \RuntimeException('Catalog binding changed.'); }
        $result = TextBoth::decodeJson($row['result'], 65536);
        $target = array_replace($settings, ['itemtype' => 'Computer', 'items_id' => 0, 'entity_id' => $body['entity_id']]);
        ServiceContext::run($target, static function () use ($target, $body, $result): void {
            ServiceContext::readComputer($target);
            foreach ($result['fields'] ?? [] as $entry) {
                $pin = $body['selector']['kind'] === 'selected' ? $entry['descriptor'] : $entry;
                if ($pin !== null) { Snapshot::textReadPermission($pin, $body['entity_id']); }
            }
        });
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
            if (!RestSettings::active($document) || (in_array($document['version'], [2, 3, 4], true) && ($settings['transport'] ?? 'adapter') !== 'rest_records')) { throw new \RuntimeException('REST settings superseded.'); }
        }
    }

    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) { return false; }
        $body = self::decode($this->fields['body'], $this->fields['hash']);
        if ($this->fields['binding'] !== self::workBinding($body, self::authorize($body))) { return false; }
        if (in_array($body['version'], [8, 9, 10, 11, 12, 13], true)) {
            if (in_array($body['version'], [12, 13], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { RestPeer::textReadOptions($body['version']); }
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
