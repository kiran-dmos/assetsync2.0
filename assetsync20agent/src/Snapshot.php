<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Snapshot
{
    /** Discover Fields containers here, independently of A metadata or mappings. */
    public static function containers(): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_fields_containers')) {
            return [];
        }
        $result = [];
        $checked = 0;
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_containers', 'WHERE' => ['is_active' => 1], 'ORDER' => 'id ASC', 'LIMIT' => 101]) as $row) {
            if (++$checked > 100) {
                throw new \RuntimeException('Fields container budget exceeded.');
            }
            $types = json_decode((string) ($row['itemtypes'] ?? ''), true);
            if (!is_array($types) || !in_array('Computer', $types, true)) {
                continue;
            }
            if (!is_callable(['PluginFieldsContainer', 'getClassname']) || !function_exists('getTableForItemType')) {
                continue;
            }
            $class = \PluginFieldsContainer::getClassname('Computer', (string) $row['name']);
            $table = is_callable([$class, 'getTable']) ? $class::getTable() : \getTableForItemType($class);
            if (is_string($table) && preg_match('/^glpi_plugin_fields_[a-z0-9_]+$/D', $table) && $DB->tableExists($table)) {
                $row['table'] = $table;
                $row['class'] = $class;
                $result[] = $row;
            }
        }
        return $result;
    }

    public static function hookTypes(): array
    {
        if (!isset($GLOBALS['DB'])) {
            return [];
        }
        try {
            return array_values(array_unique(array_column(self::containers(), 'class')));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function read(int $id, ?int $deadline = null): array
    {
        global $DB;
        $deadline ??= hrtime(true) + 2_000_000_000;
        $asset = null;
        foreach ($DB->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            $asset = $row;
        }
        if ($asset === null) {
            return ['fingerprint' => hash('sha256', 'purged'), 'lifecycle' => 'purged'];
        }
        $lifecycle = !empty($asset['is_deleted']) || !empty($asset['is_template']) ? 'deleted' : 'present';
        // Dates modified by inventory/our own writes are not changes in asset values.
        foreach (['id', 'date_mod', 'date_creation', 'last_inventory_update', 'last_contact', 'date_last_inventory'] as $key) {
            unset($asset[$key]);
        }
        $custom = [];
        foreach (self::containers() as $container) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('Snapshot budget exceeded.');
            }
            $columns = [];
            $fieldCount = 0;
            foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => [
                'plugin_fields_containers_id' => (int) $container['id'], 'is_active' => 1], 'ORDER' => 'id ASC', 'LIMIT' => 501]) as $field) {
                if (++$fieldCount > 500 || hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Fields snapshot budget exceeded.');
                }
                if (!in_array($field['type'], ['text', 'textarea', 'number', 'integer', 'int', 'yesno', 'date', 'datetime', 'dropdown'], true)
                    || !preg_match('/^[a-z][a-z0-9_]*$/D', $field['name'])) {
                    continue;
                }
                $column = $field['type'] === 'dropdown' ? 'plugin_fields_' . $field['name'] . 'dropdowns_id' : $field['name'];
                if ($DB->fieldExists($container['table'], $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns === []) {
                continue;
            }
            $values = [];
            foreach ($DB->request(['FROM' => $container['table'], 'WHERE' => ['items_id' => $id,
                'itemtype' => 'Computer', 'plugin_fields_containers_id' => (int) $container['id']], 'ORDER' => 'id ASC', 'LIMIT' => 2]) as $row) {
                $values[] = array_intersect_key($row, array_flip($columns));
            }
            $custom[$container['table']] = $values;
        }
        ksort($asset);
        ksort($custom);
        return ['fingerprint' => hash('sha256', json_encode([$asset, $custom], JSON_THROW_ON_ERROR)), 'lifecycle' => $lifecycle];
    }
}
