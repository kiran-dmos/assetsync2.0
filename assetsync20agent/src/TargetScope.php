<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Protocol subscriptions for notifications, never a local business policy or write authority. */
final class TargetScope
{
    public const TABLE = 'glpi_plugin_assetsync20agent_targets';

    public static function install(): bool
    {
        global $DB;
        return (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `installation` CHAR(32) NOT NULL, `registration` CHAR(32) NOT NULL, `generation` CHAR(32) NOT NULL,
            `connection_id` VARCHAR(255) NOT NULL, `pair_id` CHAR(32) NOT NULL,
            `itemtype` VARCHAR(100) NOT NULL, `items_id` INT NOT NULL, `entity_id` INT NOT NULL,
            `revision` BIGINT NOT NULL, `issued_at` BIGINT NOT NULL, `expires` BIGINT NOT NULL,
            `active` TINYINT NOT NULL, `dirty` BIGINT NOT NULL DEFAULT 0,
            UNIQUE KEY `subscription` (`registration`, `generation`, `pair_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public static function types(): array
    {
        global $CFG_GLPI;
        $types = [];
        foreach ($CFG_GLPI['inventory_types'] ?? [] as $type) {
            if (!is_string($type) || !class_exists($type)) { continue; }
            $types[$type] = in_array($type, ['Computer', 'Printer'], true) ? '' : 'Not supported by this agent version.';
        }
        return $types;
    }

    public static function model(string $type): object
    {
        if (!array_key_exists($type, self::types()) || self::types()[$type] !== '') {
            throw new \RuntimeException('Inventory type is unsupported or unavailable.');
        }
        return new $type();
    }

    /** Called only for a successful response from the configured authenticated HTTPS pull. */
    public static function receive(array $page, array $settings): void
    {
        global $DB;
        $current = Settings::load();
        foreach (['mode', 'installation', 'registration', 'generation', 'connection_id', 'endpoint', 'token'] as $key) {
            if (($settings[$key] ?? null) !== ($current[$key] ?? null) || ($current['mode'] ?? '') !== 'agent') {
                throw new \RuntimeException('Subscription transport configuration changed.');
            }
        }
        foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
            if (($page[$key] ?? null) !== ($settings[$key] ?? null)) { throw new \RuntimeException('Subscription binding mismatch.'); }
        }
        if (!is_array($page['updates'] ?? null) || count($page['updates']) > 50) { throw new \RuntimeException('Invalid subscription page.'); }
        Outbox::sql('START TRANSACTION');
        try {
            foreach ($page['updates'] as $update) {
                if (!is_array($update) || array_keys($update) !== ['pair_id', 'itemtype', 'items_id', 'entity_id', 'revision', 'issued_at', 'expires', 'active']
                    || !is_string($update['pair_id']) || !preg_match('/^[a-f0-9]{32}$/D', $update['pair_id'])
                    || !is_int($update['items_id']) || $update['items_id'] <= 0 || !is_int($update['entity_id']) || $update['entity_id'] < 0
                    || !is_int($update['revision']) || $update['revision'] <= 0 || !is_int($update['issued_at']) || !is_int($update['expires'])
                    || $update['issued_at'] > time() + 60 || $update['expires'] !== $update['issued_at'] + 900 || !is_bool($update['active'])) {
                    throw new \RuntimeException('Invalid subscription update.');
                }
                self::model($update['itemtype']);
                $where = ['registration' => $settings['registration'], 'generation' => $settings['generation'], 'pair_id' => $update['pair_id']];
                $old = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1])->current();
                if ($old) {
                    if ((int) $old['revision'] > $update['revision']) { continue; }
                    foreach (['itemtype', 'items_id', 'entity_id'] as $key) {
                        if ((string) $old[$key] !== (string) $update[$key]) { throw new \RuntimeException('Subscription target changed.'); }
                    }
                    if ((int) $old['revision'] === $update['revision']) {
                        if ((int) $old['issued_at'] !== $update['issued_at'] || (int) $old['expires'] !== $update['expires']
                            || (bool) $old['active'] !== $update['active']) { throw new \RuntimeException('Conflicting subscription replay.'); }
                        continue;
                    }
                    $fields = $update; unset($fields['pair_id']);
                    $fields['active'] = (int) $fields['active'];
                    if (!$DB->update(self::TABLE, $fields, $where)) { throw new \RuntimeException('Subscription update failed.'); }
                } else {
                    $fields = array_intersect_key($settings, array_flip(['installation', 'registration', 'generation', 'connection_id'])) + $update;
                    $fields['active'] = (int) $fields['active'];
                    if (!$DB->insert(self::TABLE, $fields)) { throw new \RuntimeException('Subscription insert failed.'); }
                }
            }
            Outbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    public static function subscription(string $type, int $id, array $settings): ?array
    {
        global $DB;
        $rows = iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => [
            'installation' => $settings['installation'], 'registration' => $settings['registration'], 'generation' => $settings['generation'],
            'connection_id' => $settings['connection_id'], 'itemtype' => $type, 'items_id' => $id, 'active' => 1,
            'expires' => ['>', time()]], 'LIMIT' => 2]));
        return count($rows) === 1 ? array_values($rows)[0] : null;
    }

    public static function dirty(string $type, int $id, array $settings): void
    {
        $subscription = self::subscription($type, $id, $settings);
        if ($subscription) {
            Outbox::sql('UPDATE `' . self::TABLE . '` SET `dirty` = `dirty` + 1 WHERE `id` = ' . (int) $subscription['id']);
        }
    }

    /** Fingerprint only values visible through fresh service permissions, never hook-user metadata. */
    public static function snapshot(array $subscription, array $settings): array
    {
        TaskExecutor::configuration($settings);
        $current = self::subscription($subscription['itemtype'], (int) $subscription['items_id'], $settings);
        if (!$current || $current['pair_id'] !== $subscription['pair_id'] || (int) $current['entity_id'] !== (int) $subscription['entity_id']) {
            throw new \RuntimeException('Subscription expired, revoked or rebound.');
        }
        $target = array_replace($settings, ['itemtype' => $current['itemtype'], 'items_id' => (int) $current['items_id'],
            'entity_id' => (int) $current['entity_id'], 'pair_id' => $current['pair_id']]);
        return ServiceContext::run($target, static function () use ($target): array {
            $snapshot = TaskExecutor::snapshot($target, 3);
            $item = ServiceContext::computer($target);
            if (array_key_exists('otherserial', $item->fields)) { $snapshot['otherserial'] = $item->fields['otherserial']; }
            if (\Plugin::isPluginActive('fields')) {
                foreach (Snapshot::containers($target['itemtype']) as $container) {
                    if ((int) $container['entities_id'] === $target['entity_id'] && !$container['is_recursive']
                        && \PluginFieldsProfile::getRightOnContainer((int) $target['service_profile'], (int) $container['id']) <= READ) {
                        throw new \RuntimeException('Subscription Fields permission unavailable.');
                    }
                }
            }
            unset($snapshot['observed_at'], $snapshot['pair_id']);
            return ['fingerprint' => TaskExecutor::hash($snapshot), 'lifecycle' => 'present'];
        });
    }

    /** Permission-filtered discovery is independent of subscriptions and cannot enroll a target. */
    public static function page(string $type, int $entity, string $search, int $cursor): array
    {
        global $DB;
        $item = self::model($type);
        if ($entity < 0 || $cursor < 0 || strlen($search) > 100 || !\Session::haveAccessToEntity($entity)) {
            throw new \RuntimeException('Invalid inventory filter or entity permission.');
        }
        $table = $item::getTable();
        $filter = 'a.`id` > ' . $cursor . ' AND a.`entities_id` = ' . $entity . ' AND a.`is_deleted` = 0 AND a.`is_template` = 0';
        if ($search !== '') { $filter .= ' AND (a.`name` LIKE ' . $DB->quote('%' . addcslashes($search, '%_') . '%')
            . ' OR a.`serial` LIKE ' . $DB->quote('%' . addcslashes($search, '%_') . '%') . ')'; }
        $result = Outbox::sql('SELECT a.`id`, a.`name`, a.`serial`, a.`entities_id` FROM `' . $table . '` a WHERE ' . $filter . ' ORDER BY a.`id` LIMIT 51');
        $rows = []; $next = null; $scanned = 0; $last = $cursor;
        while ($row = $DB->fetchAssoc($result)) {
            if (++$scanned > 50) { $next = $last; break; }
            $id = (int) $row['id'];
            $last = $id;
            if (!$item->can($id, READ)) { continue; }
            $reason = !$item->can($id, UPDATE) ? 'Update permission required.'
                : (trim((string) $row['serial']) === '' ? 'Serial is missing.' : '');
            $rows[] = ['itemtype' => $type, 'items_id' => $id, 'entity_id' => (int) $row['entities_id'],
                'name' => (string) $row['name'], 'serial' => trim((string) $row['serial']), 'reason' => $reason];
        }
        return ['rows' => $rows, 'next_cursor' => $next, 'observed_at' => time()];
    }
}
