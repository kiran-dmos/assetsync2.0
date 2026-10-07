<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

use Glpi\DBAL\QueryExpression;

final class SyncActivity
{
    public const PAGE_SIZE = 50;
    public const STATES = ['pending' => 'Waiting', 'running' => 'Marked as running', 'retry' => 'Retry pending', 'blocked' => 'Blocked', 'done' => 'Done'];
    private const ASSETS = [
        'Computer' => 'glpi_computers',
        'Monitor' => 'glpi_monitors',
        'Peripheral' => 'glpi_peripherals',
        'Printer' => 'glpi_printers',
        'Phone' => 'glpi_phones',
        'NetworkEquipment' => 'glpi_networkequipments',
    ];
    private array $scope = [];
    private array $connections = [];
    private bool $recheckRecorded = false;

    public function __construct(private object $db)
    {
        self::checkAccess();
        $this->recheckRecorded = $this->db->fieldExists(AssetSyncQueue::TABLE, 'needs_recheck');
        $entities = array_values(array_unique(array_filter(\Session::getActiveEntities(),
            static fn ($id): bool => (is_int($id) || is_string($id)) && preg_match('/^\d{1,10}$/D', (string) $id) === 1 && (int) $id <= 4294967295)));
        $entities = array_map('intval', $entities);
        if ($entities !== []) {
            $ancestors = $this->ancestorEntities($entities);
            foreach (self::ASSETS as $type => $table) {
                if (!class_exists($type) || !method_exists($type, 'getAssignableVisiblityCriteria') || !$type::canView()) {
                    continue;
                }
                $entityScope = [$table . '.entities_id' => $entities];
                if ($ancestors !== []) {
                    $entityScope = [['OR' => [$entityScope, [$table . '.entities_id' => $ancestors, $table . '.is_recursive' => 1]]]];
                }
                // Apply entity recursion and core assigned/owned visibility before any query limit.
                $this->scope[$type] = [
                    AssetSyncQueue::TABLE . '.itemtype' => $type,
                    $table . '.is_deleted' => 0,
                    $table . '.is_template' => 0,
                ] + $entityScope;
                $this->scope[$type][] = $type::getAssignableVisiblityCriteria($table);
            }
        }
        $values = \Config::getConfigurationValues('plugin:assetsync20', ['glpib_connections']);
        $saved = is_string($values['glpib_connections'] ?? null) ? json_decode($values['glpib_connections'], true) : null;
        foreach (is_array($saved) ? $saved : [] as $connection) {
            if (!is_array($connection) || !self::validKey($connection['id'] ?? null)) {
                continue;
            }
            $id = $connection['id'];
            if (isset($this->connections[$id])) {
                // Duplicate configuration identities must not produce remote links.
                $this->connections[$id] = ['name' => 'Connection unavailable', 'base_url' => ''];
                continue;
            }
            $name = $connection['name'] ?? '';
            $this->connections[$id] = [
                'name' => is_string($name) && $name !== '' && strlen($name) <= 120 && !preg_match('/[\x00-\x1f\x7f]|https?:|token\s*=/i', $name) ? $name : 'Saved connection',
                'base_url' => is_string($connection['base_url'] ?? null) ? $connection['base_url'] : '',
            ];
        }
    }

    private function ancestorEntities(array $entities): array
    {
        $parents = [];
        $pending = $entities;
        // Read parent edges directly; GLPI's ancestor helper may persist its cache.
        while ($pending !== []) {
            foreach ($this->db->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $pending]]) as $row) {
                // GLPI's root entity can have no parent; it is the traversal terminus.
                if (in_array($row['id'] ?? null, [0, '0'], true) && array_key_exists('entities_id', $row) && $row['entities_id'] === null) {
                    $row['entities_id'] = 0;
                }
                foreach (['id', 'entities_id'] as $key) {
                    $id = $row[$key] ?? null;
                    if ((!is_int($id) && !is_string($id)) || !preg_match('/^\d{1,10}$/D', (string) $id) || (int) $id > 4294967295) {
                        throw new \RuntimeException('Entity scope is unavailable.');
                    }
                }
                if (!in_array((int) $row['id'], $pending, true) || ((int) $row['id'] === 0 && (int) $row['entities_id'] !== 0)) {
                    throw new \RuntimeException('Entity scope is unavailable.');
                }
                $parents[(int) $row['id']] = (int) $row['entities_id'];
            }
            if (array_diff($pending, array_keys($parents)) !== []) {
                throw new \RuntimeException('Entity scope is unavailable.');
            }
            $pending = array_values(array_unique(array_diff(array_values($parents), array_keys($parents))));
        }
        $ancestors = [];
        foreach ($entities as $entity) {
            $seen = [];
            while ($entity !== 0) {
                if (isset($seen[$entity])) {
                    throw new \RuntimeException('Entity scope is unavailable.');
                }
                $seen[$entity] = true;
                $entity = $parents[$entity];
                $ancestors[$entity] = $entity;
            }
        }
        return array_values(array_diff($ancestors, $entities));
    }

    public static function checkAccess(): void
    {
        if (!class_exists('Session') || !defined('READ') || !method_exists('Session', 'checkCentralAccess')
            || !method_exists('Session', 'getActiveEntities') || !method_exists('Session', 'getLoginUserID')) {
            throw new \RuntimeException('Access denied.');
        }
        \Session::checkCentralAccess();
        if (\Session::getCurrentInterface() !== 'central' || (int) \Session::getLoginUserID() <= 0
            || !\Session::haveRight('config', READ)
            || (method_exists('Session', 'isCron') && \Session::isCron())
            || (method_exists('Session', 'isRightChecksDisabled') && \Session::isRightChecksDisabled())) {
            throw new \RuntimeException('Access denied.');
        }
    }

    public static function header(string $script): void
    {
        $present = array_key_exists('glpicrontimer', $_SESSION);
        $previous = $_SESSION['glpicrontimer'] ?? null;
        $_SESSION['glpicrontimer'] = time();
        try {
            \Html::header(Plugin::NAME . ' - Sync Activity', $script, 'config', 'Plugin');
        } finally {
            if ($present) {
                $_SESSION['glpicrontimer'] = $previous;
            } else {
                unset($_SESSION['glpicrontimer']);
            }
        }
    }

    public static function filters(array $input): array
    {
        $result = ['connection' => '', 'entity' => '', 'type' => '', 'state' => '', 'search' => '', 'before' => '', 'after' => '', 'id' => ''];
        foreach ($result as $key => $default) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > ($key === 'search' ? 120 : 64) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Invalid filters.');
            }
            $result[$key] = trim($value);
        }
        foreach (['before', 'after', 'id', 'entity'] as $key) {
            $value = $result[$key];
            if ($value !== '' && (!preg_match('/^\d{1,10}$/D', $value) || (int) $value > 4294967295 || ($key !== 'entity' && (int) $value === 0))) {
                throw new \InvalidArgumentException('Invalid filters.');
            }
        }
        if (($result['before'] !== '' && $result['after'] !== '')
            || ($result['connection'] !== '' && !self::validKey($result['connection']))
            || ($result['type'] !== '' && !isset(self::ASSETS[$result['type']]))
            || ($result['state'] !== '' && !isset(self::STATES[$result['state']]))
            || (isset($input['sort']) && $input['sort'] !== 'id_desc') || isset($input['limit'])) {
            throw new \InvalidArgumentException('Invalid filters.');
        }
        return $result;
    }

    public function listing(array $filters): array
    {
        $options = ['connection' => [], 'entity' => [], 'type' => [], 'state' => []];
        $counts = array_fill_keys(array_keys(self::STATES), 0);
        $total = 0;
        $all = 0;
        $recheck = $this->recheckRecorded ? 0 : null;
        $recheckSelect = $this->recheckRecorded ? AssetSyncQueue::TABLE . '.needs_recheck' : new QueryExpression('NULL', 'needs_recheck');
        $minimum = null;
        $maximum = null;
        $rows = [];
        foreach ($this->scope as $type => $where) {
            $table = self::ASSETS[$type];
            $query = $this->query($type, $where);
            $query['SELECT'] = [AssetSyncQueue::TABLE . '.glpi_b_connection_id', $table . '.entities_id', AssetSyncQueue::TABLE . '.status',
                $recheckSelect, 'glpi_entities.completename', new QueryExpression('COUNT(*)', 'row_count')];
            $query['GROUPBY'] = [AssetSyncQueue::TABLE . '.glpi_b_connection_id', $table . '.entities_id', AssetSyncQueue::TABLE . '.status',
                'glpi_entities.completename'];
            if ($this->recheckRecorded) {
                $query['GROUPBY'][] = AssetSyncQueue::TABLE . '.needs_recheck';
            }
            foreach ($this->db->request($query) as $group) {
                $all += (int) $group['row_count'];
                $id = (string) $group['glpi_b_connection_id'];
                if (self::validKey($id)) {
                    $options['connection'][$id] = $this->connectionName($id);
                }
                $entity = (int) $group['entities_id'];
                $options['entity'][$entity] = (string) ($group['completename'] ?: 'Entity #' . $entity);
                $options['type'][$type] = $type;
                $state = self::currentState($group);
                if (isset(self::STATES[$state])) {
                    $options['state'][$state] = self::STATES[$state];
                }
            }
            if ($filters['type'] !== '' && $filters['type'] !== $type) {
                continue;
            }
            $filtered = $this->filteredWhere($type, $where, $filters);
            $query = $this->query($type, $filtered);
            $query['SELECT'] = [AssetSyncQueue::TABLE . '.status', $recheckSelect, new QueryExpression('COUNT(*)', 'row_count'),
                new QueryExpression('MIN(`' . AssetSyncQueue::TABLE . '`.`id`)', 'min_id'), new QueryExpression('MAX(`' . AssetSyncQueue::TABLE . '`.`id`)', 'max_id')];
            $query['GROUPBY'] = [AssetSyncQueue::TABLE . '.status'];
            if ($this->recheckRecorded) {
                $query['GROUPBY'][] = AssetSyncQueue::TABLE . '.needs_recheck';
            }
            foreach ($this->db->request($query) as $group) {
                $count = (int) $group['row_count'];
                $total += $count;
                $state = self::currentState($group);
                if (isset($counts[$state])) {
                    $counts[$state] += $count;
                }
                if ((int) $group['needs_recheck'] === 1) {
                    $recheck += $count;
                }
                $minimum = $minimum === null ? (int) $group['min_id'] : min($minimum, (int) $group['min_id']);
                $maximum = $maximum === null ? (int) $group['max_id'] : max($maximum, (int) $group['max_id']);
            }
            if ($filters['before'] !== '') {
                $filtered[AssetSyncQueue::TABLE . '.id'] = ['<', (int) $filters['before']];
            } elseif ($filters['after'] !== '') {
                $filtered[AssetSyncQueue::TABLE . '.id'] = ['>', (int) $filters['after']];
            }
            $query = $this->rowQuery($type, $filtered);
            $query['ORDER'] = AssetSyncQueue::TABLE . '.id ' . ($filters['after'] !== '' ? 'ASC' : 'DESC');
            $query['LIMIT'] = self::PAGE_SIZE;
            foreach ($this->db->request($query) as $row) {
                $rows[] = $row;
            }
        }
        $ascending = $filters['after'] !== '';
        usort($rows, static fn (array $a, array $b): int => $ascending ? (int) $a['id'] <=> (int) $b['id'] : (int) $b['id'] <=> (int) $a['id']);
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        if ($ascending) {
            $rows = array_reverse($rows);
        }
        foreach ($options as &$values) {
            natcasesort($values);
        }
        unset($values);
        return ['rows' => $rows, 'options' => $options, 'counts' => $counts, 'total' => $total, 'all' => $all, 'recheck' => $recheck,
            'newer' => $rows !== [] && $maximum > (int) $rows[0]['id'],
            'older' => $rows !== [] && $minimum < (int) $rows[count($rows) - 1]['id']];
    }

    public function detail(int $id): ?array
    {
        if ($id <= 0 || $id > 4294967295) {
            return null;
        }
        foreach ($this->scope as $type => $where) {
            $where[AssetSyncQueue::TABLE . '.id'] = $id;
            $query = $this->rowQuery($type, $where);
            $query['LIMIT'] = 1;
            foreach ($this->db->request($query) as $row) {
                // Only the selected, already authorized asset detail may read UUID evidence.
                $row['uuid_outcomes'] = ['upgrade_required'];
                if (AssetUuidOperation::available()) {
                    $row['uuid_outcomes'] = [];
                    $operation = AssetUuidOperation::find($type, (int) $row['items_id']);
                    if ($operation !== null) {
                        try {
                            $state = AssetUuidOperation::state($operation);
                            $codes = [$operation['status']];
                            $connection = $this->connections[$row['glpi_b_connection_id']] ?? [];
                            $remoteSide = AssetUuidService::remoteSideKey((string) $row['glpi_b_connection_id'], (int) $row['link_remote_items_id'], $connection['base_url'] ?? '');
                            foreach (['a', $remoteSide] as $side) {
                                $evidence = $state['sides'][$side] ?? [];
                                $codes[] = $evidence['outcome'] ?? '';
                                $codes[] = $evidence['audit'] ?? '';
                                if (isset($evidence['time_uncertain'])) {
                                    $codes[] = $evidence['time_uncertain'] ? 'time_uncertain' : 'time_verified';
                                }
                            }
                            $row['uuid_outcomes'] = array_values(array_unique(array_filter($codes, static fn (string $code): bool => isset(AssetUuidService::OUTCOMES[$code]))));
                        } catch (\Throwable) {
                            $row['uuid_outcomes'] = ['unknown'];
                        }
                    }
                }
                return $row;
            }
        }
        return null;
    }

    private function query(string $type, array $where): array
    {
        $table = self::ASSETS[$type];
        return ['FROM' => AssetSyncQueue::TABLE,
            'INNER JOIN' => [$table => ['ON' => [AssetSyncQueue::TABLE => 'items_id', $table => 'id']]],
            'LEFT JOIN' => ['glpi_entities' => ['ON' => [$table => 'entities_id', 'glpi_entities' => 'id']]],
            'WHERE' => $where];
    }

    private function rowQuery(string $type, array $where): array
    {
        $query = $this->query($type, $where);
        $q = AssetSyncQueue::TABLE;
        $l = AssetSyncLink::TABLE;
        $query['LEFT JOIN'][$l] = ['AND' => [
            ['FKEY' => [$q => 'items_id', $l => 'items_id']],
            ['FKEY' => [$q => 'itemtype', $l => 'itemtype']],
            ['FKEY' => [$q => 'glpi_b_connection_id', $l => 'glpi_b_connection_id']],
        ]];
        $query['SELECT'] = [];
        foreach (['id', 'itemtype', 'items_id', 'glpi_b_connection_id', 'route_id', 'remote_items_id', 'status', 'needs_recheck', 'attempts', 'started_at', 'finished_at', 'available_at', 'date_mod'] as $column) {
            $query['SELECT'][] = $column === 'needs_recheck' && !$this->recheckRecorded ? new QueryExpression('NULL', 'needs_recheck') : $q . '.' . $column;
        }
        $query['SELECT'][] = new QueryExpression('`' . self::ASSETS[$type] . '`.`name`', 'asset_name');
        $query['SELECT'][] = self::ASSETS[$type] . '.entities_id';
        $query['SELECT'][] = 'glpi_entities.completename';
        foreach (['id', 'remote_items_id', 'route_id', 'status', 'last_sync_at'] as $column) {
            $query['SELECT'][] = new QueryExpression('`' . $l . '`.`' . $column . '`', 'link_' . $column);
        }
        return $query;
    }

    private function filteredWhere(string $type, array $where, array $filters): array
    {
        $q = AssetSyncQueue::TABLE;
        if ($filters['connection'] !== '') {
            $where[$q . '.glpi_b_connection_id'] = $filters['connection'];
        }
        if ($filters['state'] !== '') {
            if ($filters['state'] === 'pending' && $this->recheckRecorded) {
                $where[] = ['OR' => [[$q . '.status' => 'pending'], [$q . '.status' => ['done', 'blocked'], $q . '.needs_recheck' => 1]]];
            } else {
                $where[$q . '.status'] = $filters['state'];
                if ($this->recheckRecorded && in_array($filters['state'], ['done', 'blocked'], true)) {
                    $where[$q . '.needs_recheck'] = 0;
                }
            }
        }
        if ($filters['entity'] !== '') {
            // Keep the original entity predicate; a forged filter cannot broaden it.
            $where[] = [self::ASSETS[$type] . '.entities_id' => (int) $filters['entity']];
        }
        if ($filters['search'] !== '') {
            $or = [self::ASSETS[$type] . '.name' => ['LIKE', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']) . '%']];
            if (ctype_digit($filters['search']) && strlen($filters['search']) <= 10 && (int) $filters['search'] <= 4294967295) {
                $or[] = [$q . '.items_id' => (int) $filters['search']];
                $or[] = [$q . '.remote_items_id' => (int) $filters['search']];
            }
            $where[] = ['OR' => $or];
        }
        return $where;
    }

    public function connectionName(string $id): string
    {
        return $this->connections[$id]['name'] ?? 'Connection unavailable';
    }

    public static function validKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }

    public static function assetPath(string $type, int $id): ?string
    {
        return isset(self::ASSETS[$type]) && $id > 0 && $id <= 4294967295 ? '/front/' . strtolower($type) . '.form.php?id=' . $id : null;
    }

    public function remoteUrl(array $row): ?string
    {
        $id = (int) ($row['remote_items_id'] ?? 0);
        if ($id <= 0 || $id !== (int) ($row['link_remote_items_id'] ?? 0) || (int) ($row['link_id'] ?? 0) <= 0
            || !self::validKey($row['route_id'] ?? null) || $row['route_id'] !== ($row['link_route_id'] ?? null)
            || in_array($row['link_status'] ?? '', ['blocked_identity', 'blocked_duplicate', 'blocked_missing_remote'], true)) {
            return null;
        }
        $base = $this->connections[$row['glpi_b_connection_id']]['base_url'] ?? '';
        if ($base === '' || strlen($base) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]|%(?:0[0-9a-f]|1[0-9a-f]|20|7f)/i', $base) || !filter_var($base, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            return null;
        }
        $path = self::assetPath((string) $row['itemtype'], $id);
        if ($path === null) {
            return null;
        }
        $base = preg_replace('~/apirest\.php/?$~i', '', rtrim($base, '/'));
        return $base . $path;
    }

    public static function currentState(array $row): string
    {
        return (int) ($row['needs_recheck'] ?? 0) === 1 && in_array($row['status'], ['done', 'blocked'], true) ? 'pending' : $row['status'];
    }

    public static function reason(array $row): string
    {
        if ((int) ($row['needs_recheck'] ?? 0) === 1 && in_array($row['status'], ['done', 'blocked'], true)) {
            return 'An asset save needs another check. This older result may not describe current work. Wait for the next scheduled sync.';
        }
        switch ($row['status']) {
            case 'pending': return 'Waiting for a sync check. No transfer is confirmed by this queue row.';
            case 'running': return 'Marked as running; current worker activity is not verified. Its saved result does not confirm whether any fields changed.';
            case 'retry': return 'Waiting for another scheduled attempt. Some values may already have changed; this row does not record individual field results.';
            case 'done': return 'This job finished. It may have found equal values or an asset outside its route; completion does not prove that fields changed.';
            case 'blocked':
                return 'Stored link condition (not a confirmed error from this attempt): ' . match ($row['link_status'] ?? '') {
                    'blocked_configuration' => 'Sync stopped because its setup needs attention. Check the connection, Entity Route and Field Mapping.',
                    'blocked_uuid_time' => 'UUID timestamp evidence is uncertain. Differing Both fields are deferred; one-way mappings can continue on retry.',
                    'blocked_identity', 'blocked_duplicate' => 'The GLPI B asset could not be identified safely. Check for missing or duplicate assets before trying again.',
                    'blocked_route_conflict' => 'More than one Entity Route matches this asset. Check which route should be used.',
                    'blocked_field_conflict' => 'The values differ, but the latest change could not be chosen. Check update times or choose GLPI A or GLPI B in Field Mapping.',
                    'blocked_missing_remote' => 'The linked GLPI B asset was not found. Ask its administrator to check the asset before trying again.',
                    'blocked_local_update' => 'A GLPI A update could not be completed. Ask its administrator to check the asset and permissions.',
                    'blocked_remote_error' => 'A GLPI B request could not be completed. Check the connection and asset; some values may already have changed.',
                    default => 'Sync stopped and needs attention. Ask an administrator to check the connection, routes and permissions.',
                };
            default: return 'This queue state is not recognized. Ask an administrator to check the local queue.';
        }
    }
}
