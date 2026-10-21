<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Immutable A-owned policy records, without credentials or a B-wide asset binding. */
final class RestSettings extends \CommonDBTM
{
    private const HEAD = 'glpi_plugin_assetsync20agent_restsettingsheads';

    public static function install(): bool
    {
        global $DB;
        return (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::getTable() . '` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `settings_id` CHAR(32) NOT NULL UNIQUE,
            `hash` CHAR(64) NOT NULL, `body` MEDIUMTEXT NOT NULL, `binding` CHAR(64) NOT NULL,
            `connection_id` VARCHAR(255) NOT NULL, `registration` CHAR(32) NOT NULL,
            `generation` CHAR(32) NOT NULL, `installation` CHAR(32) NOT NULL, `entities_id` INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') && (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::HEAD . '` (
            `scope_key` CHAR(64) PRIMARY KEY, `revision` BIGINT NOT NULL, `settings_id` CHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
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
        $settings = RestPeer::settings();
        $where = [];
        foreach (['connection_id', 'registration', 'generation', 'installation'] as $key) {
            $where[($tablename ?? self::getTable()) . '.' . $key] = $settings[$key];
        }
        $where[($tablename ?? self::getTable()) . '.binding'] = [RestPeer::binding($settings), self::binding($settings, 2)];
        $entities = array_keys(Settings::entities((int) $settings['agent_peer_user'], (int) $settings['agent_peer_profile']));
        $where[($tablename ?? self::getTable()) . '.entities_id'] = $entities ?: [-1];
        // Stock REST adds searchText at the top level; keep authorization predicates separate.
        return ['AND' => $where];
    }

    public static function decode(string $json, string $hash): array
    {
        $body = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (is_array($body) && ($body['version'] ?? null) === 5) {
            $body = TextBoth::decodeJson($json, 16384);
            $keys = ['version', 'id', 'installation', 'registration', 'generation', 'connection_id',
                'route_id', 'source_entity', 'target_entity', 'itemtype', 'mappings', 'revision', 'enabled', 'include_child_entities', 'asset_types'];
            if (array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))
                || !is_array($body['mappings']) || !is_bool($body['enabled'])
                || ($body['enabled'] ? !array_is_list($body['mappings']) : $body['mappings'] !== [])
                || !hash_equals(TaskExecutor::hash($body), $hash)) { throw new \RuntimeException('Invalid Text Both settings.'); }
            if ($body['enabled']) {
                TextBoth::validateMappings($body['mappings']);
            }
            $routing = $body; $routing['version'] = 3;
            $routing['mappings'] = $body['enabled'] ? [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']] : [];
            self::decode(json_encode($routing, JSON_THROW_ON_ERROR), TaskExecutor::hash($routing));
            return $body;
        }
        if (is_array($body) && ($body['version'] ?? null) === 4) {
            $legacy = $body;
            if (($body['include_child_entities'] ?? null) !== false || ($body['asset_types'] ?? null) !== ['Computer']
                || !in_array($body['mappings'] ?? null, [[], [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'both']]], true)) {
                throw new \RuntimeException('Unsupported Name Both scope.');
            }
            unset($legacy['include_child_entities'], $legacy['asset_types']);
            $legacy['version'] = 2;
            if ($legacy['mappings'] !== []) { $legacy['mappings'][0]['source_of_truth'] = 'glpi_a'; }
            self::decode(json_encode($legacy, JSON_THROW_ON_ERROR), TaskExecutor::hash($legacy));
            if (strlen($json) > 16384 || !hash_equals(TaskExecutor::hash($body), $hash)) { throw new \RuntimeException('Invalid Name Both settings hash.'); }
            return $body;
        }
        $keys = ['version', 'id', 'installation', 'registration', 'generation', 'connection_id',
            'route_id', 'source_entity', 'target_entity', 'itemtype', 'mappings'];
        if (in_array($body['version'] ?? null, [2, 3], true)) { $keys = array_merge($keys, ['revision', 'enabled']); }
        if (($body['version'] ?? null) === 3) { $keys = array_merge($keys, ['include_child_entities', 'asset_types']); }
        if (!is_array($body) || array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))
            || (in_array($body['version'] ?? null, [2, 3], true) && strlen($json) > 16384) || !hash_equals(TaskExecutor::hash($body), $hash) || !in_array($body['version'] ?? null, [1, 2, 3], true)
            || !preg_match('/^[a-f0-9]{32}$/D', $body['id'] ?? '') || $body['itemtype'] !== 'Computer'
            || !is_int($body['source_entity']) || $body['source_entity'] < 0
            || !is_int($body['target_entity']) || $body['target_entity'] < 0
            || !is_string($body['route_id']) || $body['route_id'] === '' || strlen($body['route_id']) > 255
            || ($body['mappings'] !== [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']]
                && !(in_array($body['version'], [2, 3], true) && ($body['enabled'] ?? null) === false && $body['mappings'] === []))) {
            throw new \RuntimeException('Unsupported immutable REST settings.');
        }
        if (in_array($body['version'], [2, 3], true)) {
            if (!is_int($body['revision']) || $body['revision'] < 1 || !is_bool($body['enabled'])) { throw new \RuntimeException('Invalid REST settings revision.'); }
            foreach (['installation', 'registration', 'generation'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $body[$key])) { throw new \RuntimeException('Invalid REST settings binding.'); }
            }
            if (!is_string($body['connection_id']) || $body['connection_id'] === '' || strlen($body['connection_id']) > 255
                || preg_match('/[\x00-\x20\x7f]/', $body['connection_id'])) { throw new \RuntimeException('Invalid REST connection.'); }
        }
        if ($body['version'] === 3) {
            $types = $body['asset_types'];
            if (!is_bool($body['include_child_entities']) || !is_array($types) || !array_is_list($types)
                || count(array_filter($types, 'is_string')) !== count($types)
                || array_diff($types, ['Computer', 'Monitor', 'Peripheral', 'Printer', 'Phone', 'NetworkEquipment'])) { throw new \RuntimeException('Invalid REST route types.'); }
            $canonical = array_values(array_unique($types)); sort($canonical, SORT_STRING);
            if ($canonical !== $types || ($body['enabled'] && !in_array('Computer', $types, true))) { throw new \RuntimeException('Unsupported REST route types.'); }
        }
        return $body;
    }

    public static function binding(array $settings, int $version): string
    {
        if ($version === 1) { return RestPeer::binding($settings); }
        return TaskExecutor::hash([RestPeer::binding($settings), 'rest_records', 2]);
    }

    private static function scope(array $body): string
    {
        $scope = [];
        foreach (['installation', 'registration', 'generation', 'connection_id', 'route_id'] as $key) { $scope[$key] = $body[$key]; }
        return TaskExecutor::hash($scope);
    }

    /** Called under the worker lock for execution, never to rewrite retained evidence. */
    public static function active(array $body): bool
    {
        global $DB;
        $head = Outbox::one('SELECT * FROM `' . self::HEAD . '` WHERE `scope_key` = ' . $DB->quote(self::scope($body)));
        if (!$head) { return $body['version'] === 1 && !self::history($body); }
        return in_array($body['version'], [2, 3, 4, 5], true) && $body['enabled'] && $head['settings_id'] === $body['id']
            && (int) $head['revision'] === $body['revision'];
    }

    private static function history(array $body): bool
    {
        global $DB;
        $where = [];
        foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) { $where[] = '`' . $key . '` = ' . $DB->quote($body[$key]); }
        return Outbox::one('SELECT `id` FROM `' . self::getTable() . '` WHERE ' . implode(' AND ', $where)
            . ' AND JSON_EXTRACT(`body`, \'$.version\') IN (2, 3, 4, 5) AND JSON_UNQUOTE(JSON_EXTRACT(`body`, \'$.route_id\')) = ' . $DB->quote($body['route_id']) . ' LIMIT 1') !== null;
    }

    public static function authorize(array $body): array
    {
        $settings = RestPeer::settings();
        foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
            if (($body[$key] ?? null) !== ($settings[$key] ?? null)) { throw new \RuntimeException('REST settings binding changed.'); }
        }
        RestPeer::entity($settings, $body['target_entity']);
        return $settings;
    }

    public function add(array $input, $options = [], $history = true)
    {
        if (array_diff(array_keys($input), ['settings_id', 'hash', 'body', 'entities_id', '_add'])) {
            throw new \RuntimeException('Unknown REST settings input.');
        }
        $body = self::decode((string) ($input['body'] ?? ''), (string) ($input['hash'] ?? ''));
        $settings = self::authorize($body);
        if (($input['settings_id'] ?? '') !== $body['id'] || !RestPeer::idle()) { throw new \RuntimeException('REST settings acceptance unavailable.'); }
        $fields = ['settings_id' => $body['id'], 'hash' => $input['hash'], 'body' => $input['body'],
            'binding' => self::binding($settings, $body['version']), 'entities_id' => $body['target_entity']];
        foreach (['connection_id', 'registration', 'generation', 'installation'] as $key) { $fields[$key] = $body[$key]; }
        if (in_array($body['version'], [2, 3, 4, 5], true)) { return $this->activate($body, $fields); }
        global $DB;
        $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['id']));
        if (!$old) {
            try { return parent::add($fields, $options, false); }
            catch (\RuntimeException $error) {
                $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['id']));
                if (!$old) { throw $error; }
            }
        }
        foreach ($fields as $key => $value) {
            if ((string) $old[$key] !== (string) $value) { throw new \RuntimeException('REST settings ID/hash collision.'); }
        }
        return (int) $old['id'];
    }

    private function activate(array $body, array $fields): int
    {
        return Settings::withWorkerLock(function () use ($body, $fields): int {
            global $DB;
            $settings = self::authorize($body);
            if (($settings['transport'] ?? 'adapter') !== 'rest_records' || self::binding($settings, 2) !== $fields['binding'] || !RestPeer::idle()) { throw new \RuntimeException('REST activation binding changed.'); }
            Outbox::sql('START TRANSACTION');
            try {
                if ($body['version'] === 5 && $body['enabled']) {
                    $target = array_replace($settings, ['itemtype' => 'Computer', 'items_id' => 0, 'entity_id' => $body['target_entity']]);
                    ServiceContext::run($target, static function () use ($body, $target): void {
                        ServiceContext::readComputer($target);
                        foreach ($body['mappings'] as $mapping) {
                            $pin = $mapping['b'];
                            if (Snapshot::textPin($pin['capture_key'], $body['target_entity'], true, true) !== $pin) { throw new \RuntimeException('Text Both B definition changed.'); }
                        }
                    });
                }
                $scope = self::scope($body);
                $head = Outbox::one('SELECT * FROM `' . self::HEAD . '` WHERE `scope_key` = ' . $DB->quote($scope) . ' FOR UPDATE');
                if (!$head && self::history($body)) { throw new \RuntimeException('REST settings head missing; repair required.'); }
                if ($head) {
                    $current = Outbox::one('SELECT `body`, `hash` FROM `' . self::getTable() . '` WHERE `settings_id` = ' . $DB->quote($head['settings_id']));
                    if (!$current || self::decode($current['body'], $current['hash'])['version'] > $body['version']) { throw new \RuntimeException('REST settings version downgrade denied.'); }
                }
                if ($head && (int) $head['revision'] > $body['revision']) { throw new \RuntimeException('REST settings superseded.'); }
                if ($head && (int) $head['revision'] === $body['revision'] && $head['settings_id'] !== $body['id']) { throw new \RuntimeException('REST settings revision conflict.'); }
                $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['id']));
                if ($old) {
                    foreach ($fields as $key => $value) {
                        if ((string) $old[$key] !== (string) $value) { throw new \RuntimeException('REST settings ID/hash collision.'); }
                    }
                    $id = (int) $old['id'];
                } else {
                    if (!$DB->insert(self::getTable(), $fields)) { throw new \RuntimeException('REST settings storage failed.'); }
                    $old = Outbox::one('SELECT * FROM `' . self::getTable() . '` WHERE `settings_id` = ' . $DB->quote($body['id']));
                    foreach ($fields as $key => $value) {
                        if ((string) ($old[$key] ?? '') !== (string) $value) { throw new \RuntimeException('REST settings readback failed.'); }
                    }
                    $id = (int) $old['id'];
                }
                if ($id <= 0) { throw new \RuntimeException('REST settings storage failed.'); }
                $next = ['revision' => $body['revision'], 'settings_id' => $body['id']];
                $ok = $head ? $DB->update(self::HEAD, $next, ['scope_key' => $scope]) : $DB->insert(self::HEAD, ['scope_key' => $scope] + $next);
                if (!$ok) { throw new \RuntimeException('REST head activation failed.'); }
                $saved = Outbox::one('SELECT * FROM `' . self::HEAD . '` WHERE `scope_key` = ' . $DB->quote($scope));
                if (!$saved || $saved['settings_id'] !== $body['id'] || (int) $saved['revision'] !== $body['revision']) { throw new \RuntimeException('REST head readback failed.'); }
                Outbox::sql('COMMIT');
                $this->fields = $old;
                return $id;
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        });
    }

    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) { return false; }
        $body = self::decode($this->fields['body'], $this->fields['hash']);
        return self::binding(self::authorize($body), $body['version']) === $this->fields['binding'];
    }

    public function update(array $input, $history = true, $options = []) { return false; }
    public function delete(array $input, $force = false, $history = true) { return false; }
}
