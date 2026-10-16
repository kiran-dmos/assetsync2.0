<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Immutable A-owned policy records, without credentials or a B-wide asset binding. */
final class RestSettings extends \CommonDBTM
{
    public static function install(): bool
    {
        global $DB;
        return (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::getTable() . '` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `settings_id` CHAR(32) NOT NULL UNIQUE,
            `hash` CHAR(64) NOT NULL, `body` MEDIUMTEXT NOT NULL, `binding` CHAR(64) NOT NULL,
            `connection_id` VARCHAR(255) NOT NULL, `registration` CHAR(32) NOT NULL,
            `generation` CHAR(32) NOT NULL, `installation` CHAR(32) NOT NULL, `entities_id` INT NOT NULL
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
        $where[($tablename ?? self::getTable()) . '.binding'] = RestPeer::binding($settings);
        $entities = array_keys(Settings::entities((int) $settings['agent_peer_user'], (int) $settings['agent_peer_profile']));
        $where[($tablename ?? self::getTable()) . '.entities_id'] = $entities ?: [-1];
        // Stock REST adds searchText at the top level; keep authorization predicates separate.
        return ['AND' => $where];
    }

    public static function decode(string $json, string $hash): array
    {
        $body = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $keys = ['version', 'id', 'installation', 'registration', 'generation', 'connection_id',
            'route_id', 'source_entity', 'target_entity', 'itemtype', 'mappings'];
        if (!is_array($body) || array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))
            || !hash_equals(TaskExecutor::hash($body), $hash) || ($body['version'] ?? null) !== 1
            || !preg_match('/^[a-f0-9]{32}$/D', $body['id'] ?? '') || $body['itemtype'] !== 'Computer'
            || !is_int($body['source_entity']) || $body['source_entity'] < 0
            || !is_int($body['target_entity']) || $body['target_entity'] < 0
            || !is_string($body['route_id']) || $body['route_id'] === '' || strlen($body['route_id']) > 255
            || $body['mappings'] !== [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']]) {
            throw new \RuntimeException('Unsupported immutable REST settings.');
        }
        return $body;
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
            'binding' => RestPeer::binding($settings), 'entities_id' => $body['target_entity']];
        foreach (['connection_id', 'registration', 'generation', 'installation'] as $key) { $fields[$key] = $body[$key]; }
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

    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) { return false; }
        $body = self::decode($this->fields['body'], $this->fields['hash']);
        return RestPeer::binding(self::authorize($body)) === $this->fields['binding'];
    }

    public function update(array $input, $history = true, $options = []) { return false; }
    public function delete(array $input, $force = false, $history = true) { return false; }
}
