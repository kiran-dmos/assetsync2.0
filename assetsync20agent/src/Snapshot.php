<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Snapshot
{
    /** Resolve one local definition, without looking for a child row or trusting supplied classes. */
    public static function textPin(string $captureKey, int $entity, bool $write = false, bool $lock = false): array
    {
        global $DB;
        $field = null; $container = null;
        if (in_array($captureKey, ['native:name', 'native:comment'], true)) {
            $column = substr($captureKey, 7);
            $options = array_values(array_filter((new \Computer())->rawSearchOptions(), static fn ($o): bool => is_array($o)
                && ($o['table'] ?? '') === 'glpi_computers' && ($o['field'] ?? '') === $column));
            if (count($options) !== 1 || ($write && (!empty($options[0]['readonly']) || !empty($options[0]['is_readonly'])))) { throw new \RuntimeException('field_unavailable'); }
            $kind = 'native'; $class = 'Computer'; $table = 'glpi_computers';
            $type = $column === 'name' ? 'text' : 'textarea'; $search = (int) $options[0]['id'];
            $definitionId = null; $containerId = null; $key = $column;
        } else {
            if (!preg_match('/^fields:([1-9][0-9]*)$/D', $captureKey, $match) || !\Plugin::isPluginActive('fields')
                || !class_exists('PluginFieldsContainer') || !defined('PluginFieldsField::SEARCH_OPTION_STARTING_INDEX')) { throw new \RuntimeException('field_unavailable'); }
            $suffix = $lock ? ' LOCK IN SHARE MODE' : '';
            $definition = Outbox::one('SELECT * FROM `glpi_plugin_fields_fields` WHERE `id`=' . (int) $match[1] . $suffix);
            $block = $definition ? Outbox::one('SELECT * FROM `glpi_plugin_fields_containers` WHERE `id`=' . (int) $definition['plugin_fields_containers_id'] . $suffix) : null;
            if (!$definition || !$block || !self::textContainerReadable($block, $entity, $write)) { throw new \RuntimeException('field_unavailable'); }
            if (!in_array($definition['type'], ['text', 'textarea'], true) || !empty($definition['multiple']) || empty($definition['is_active'])
                || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $definition['name'])) { throw new \RuntimeException('unsupported_field'); }
            $kind = 'fields'; $type = $definition['type']; $column = $definition['name'];
            $class = \PluginFieldsContainer::getClassname('Computer', $block['name']);
            if (!preg_match('/^PluginFieldsComputer[A-Za-z0-9_]+$/D', $class) || !is_callable([$class, 'getTable'])) { throw new \RuntimeException('metadata_invalid'); }
            $table = $class::getTable();
            if (!preg_match('/^glpi_plugin_fields_[a-z0-9_]{1,45}$/D', $table) || !$DB->tableExists($table)) { throw new \RuntimeException('metadata_invalid'); }
            $definitionId = (int) $definition['id']; $containerId = (int) $block['id'];
            $search = \PluginFieldsField::SEARCH_OPTION_STARTING_INDEX + $definitionId;
            $key = 'Computer.' . $class . '.' . $column;
            $types = json_decode($block['itemtypes'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($types) || !array_is_list($types) || count(array_filter($types, 'is_string')) !== count($types)) { throw new \RuntimeException('metadata_invalid'); }
            $types = array_values(array_unique($types)); sort($types, SORT_STRING);
            $field = ['active' => !empty($definition['is_active']), 'readonly' => !empty($definition['is_readonly']),
                'mandatory' => !empty($definition['mandatory']), 'multiple' => !empty($definition['multiple'])];
            $container = ['active' => !empty($block['is_active']), 'type' => (string) $block['type'],
                'entity_id' => (int) $block['entities_id'], 'recursive' => !empty($block['is_recursive']), 'itemtypes' => $types];
        }
        $storage = Outbox::one('SELECT CHARACTER_MAXIMUM_LENGTH AS characters, CHARACTER_OCTET_LENGTH AS bytes FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $DB->quote($table) . ' AND COLUMN_NAME=' . $DB->quote($column));
        if (!$storage || !ctype_digit((string) $storage['characters']) || !ctype_digit((string) $storage['bytes'])
            || (int) $storage['characters'] < 1 || (int) $storage['bytes'] < 1) { throw new \RuntimeException('metadata_invalid'); }
        $limits = ['characters' => $type === 'text' ? min(255, (int) $storage['characters']) : (int) $storage['characters'], 'bytes' => (int) $storage['bytes']];
        $preimage = ['itemtype' => 'Computer', 'kind' => $kind, 'key' => $key, 'capture_key' => $captureKey, 'type' => $type,
            'search_id' => $search, 'definition_id' => $definitionId, 'container_id' => $containerId,
            'class' => $class, 'table' => $table, 'column' => $column, 'limits' => $limits, 'field' => $field, 'container' => $container];
        $pin = array_intersect_key($preimage, array_flip(['kind', 'key', 'capture_key', 'type', 'search_id', 'definition_id', 'container_id', 'class', 'table', 'column']));
        $pin += ['definition_hash' => TaskExecutor::hash($preimage), 'limits' => $limits];
        TextBoth::validatePin($pin);
        return $pin;
    }

    private static function textContainerReadable(array $container, int $entity, bool $write): bool
    {
        $types = json_decode((string) $container['itemtypes'], true);
        if (empty($container['is_active']) || !is_array($types) || !in_array('Computer', $types, true)) { return false; }
        $scope = (int) $container['entities_id'] === $entity;
        if (!$scope && !empty($container['is_recursive'])) {
            $current = $entity; $seen = [];
            for ($i = 0; $i < 64 && !isset($seen[$current]); $i++) {
                $seen[$current] = true;
                $row = Outbox::one('SELECT `id`,`entities_id` FROM `glpi_entities` WHERE `id`=' . $current);
                if (!$row || $current === 0) { break; }
                $current = (int) $row['entities_id'];
                if ($current === (int) $container['entities_id']) { $scope = true; break; }
            }
        }
        $profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        $rights = (int) \PluginFieldsProfile::getRightOnContainer($profile, (int) $container['id']);
        // Fields uses the legacy None/Read/Write enum, not core Computer permission bits.
        return $scope && in_array($rights, [0, READ, CREATE], true) && ($write ? $rights > READ : $rights >= READ);
    }

    public static function textReadPermission(array $pin, int $entity): void
    {
        TextBoth::validatePin($pin);
        if ($pin['kind'] === 'native') { return; }
        $container = Outbox::one('SELECT * FROM `glpi_plugin_fields_containers` WHERE `id`=' . $pin['container_id']);
        if (!$container || !self::textContainerReadable($container, $entity, false)) { throw new \RuntimeException('field_unavailable'); }
    }

    /** Guard the exact existing child, including the wrapper's broader parent-only lookup. */
    public static function textState(array $pin, int $id, int $entity, bool $write = false, bool $lock = false): array
    {
        global $DB;
        $actual = self::textPin($pin['capture_key'], $entity, $write, $lock);
        if ($actual !== $pin) { throw new \RuntimeException('metadata_invalid'); }
        $suffix = $lock ? ' FOR UPDATE' : '';
        $parent = Outbox::one('SELECT * FROM `glpi_computers` WHERE `id`=' . $id . $suffix);
        if (!$parent || (int) $parent['entities_id'] !== $entity || !empty($parent['is_deleted']) || !empty($parent['is_template'])) { throw new \RuntimeException('field_unavailable'); }
        $row = $parent;
        if ($pin['kind'] === 'fields') {
            $rows = self::textRows('SELECT * FROM `' . $pin['table'] . '` WHERE `items_id`=' . $id . ' LIMIT 2' . $suffix);
            if (count($rows) !== 1) { throw new \RuntimeException('field_unavailable'); }
            $row = array_values($rows)[0];
            if (($row['itemtype'] ?? '') !== 'Computer' || (int) ($row['plugin_fields_containers_id'] ?? 0) !== $pin['container_id']) { throw new \RuntimeException('field_unavailable'); }
        }
        if (!array_key_exists($pin['column'], $row)) { throw new \RuntimeException('field_unavailable'); }
        return ['parent' => $parent, 'row' => $row];
    }

    /** The official wrapper validates every mandatory sibling, including status overrides. */
    public static function textInput(array $pin, array $state, string $value): array
    {
        return self::textGroupInput([$pin], $state, [$pin['column'] => $value]);
    }

    /** Only selected group values may replace fresh mandatory sibling values. */
    public static function textGroupInput(array $pins, array $state, array $values, bool $lock = false): array
    {
        if ($pins === [] || count($pins) > 8) { throw new \RuntimeException('metadata_invalid'); }
        $pin = $pins[0]; $columns = [];
        foreach ($pins as $member) {
            TextBoth::validatePin($member);
            if ($member['kind'] !== $pin['kind'] || $member['container_id'] !== $pin['container_id'] || $member['table'] !== $pin['table']
                || isset($columns[$member['column']]) || !array_key_exists($member['column'], $values)) { throw new \RuntimeException('metadata_invalid'); }
            TextBoth::normalize($values[$member['column']], $member); $columns[$member['column']] = true;
        }
        if (array_diff_key($values, $columns)) { throw new \RuntimeException('metadata_invalid'); }
        $id = (int) $state['parent']['id'];
        if ($pin['kind'] === 'native') { return ['id' => $id] + $values; }
        $input = ['items_id' => $id, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => $pin['container_id']];
        $definitions = self::textRows('SELECT * FROM `glpi_plugin_fields_fields` WHERE `plugin_fields_containers_id`=' . $pin['container_id'] . ' ORDER BY `id` ASC LIMIT 501'
            . ($lock ? ' LOCK IN SHARE MODE' : ''));
        if (count($definitions) > 500) { throw new \RuntimeException('response_limit'); }
        $statusField = \PluginFieldsStatusOverride::getStatusFieldName('Computer');
        if (!is_string($statusField) || !array_key_exists($statusField, $state['parent'])) { throw new \RuntimeException('field_unavailable'); }
        $overrideTable = \PluginFieldsStatusOverride::getTable();
        if (!is_string($overrideTable) || !preg_match('/^glpi_plugin_fields_[a-z0-9_]+$/D', $overrideTable)) { throw new \RuntimeException('metadata_invalid'); }
        $overrideRows = self::textRows('SELECT * FROM `' . $overrideTable . '` WHERE `plugin_fields_fields_id` IN '
            . '(SELECT `id` FROM `glpi_plugin_fields_fields` WHERE `plugin_fields_containers_id`=' . $pin['container_id'] . ') LIMIT 501'
            . ($lock ? ' LOCK IN SHARE MODE' : ''));
        if (count($overrideRows) > 500) { throw new \RuntimeException('response_limit'); }
        $overrides = \PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus($pin['container_id'], 'Computer', (int) $state['parent'][$statusField]);
        foreach ($definitions as $definition) {
            $mandatory = !empty($definition['mandatory']);
            foreach ($overrides as $override) {
                if ((int) $override['plugin_fields_fields_id'] === (int) $definition['id']) {
                    $mandatory = !empty($override['mandatory']);
                }
            }
            if (empty($definition['is_active']) || !$mandatory || in_array($definition['type'], ['yesno', 'header'], true)) { continue; }
            $column = $definition['type'] === 'dropdown' ? 'plugin_fields_' . $definition['name'] . 'dropdowns_id' : $definition['name'];
            if (!array_key_exists($column, $state['row'])) { throw new \RuntimeException('field_unavailable'); }
            $candidate = array_key_exists($column, $values) ? $values[$column] : $state['row'][$column];
            if (!is_scalar($candidate) || empty($candidate)
                || (in_array($definition['type'], ['date', 'datetime'], true) && $candidate === 'NULL')) { throw new \RuntimeException('destination_validation'); }
            $input[$column] = $candidate;
        }
        $input = array_replace($input, $values);
        if (!\PluginFieldsContainer::validateValues($input, 'Computer', false)) { throw new \RuntimeException('destination_validation'); }
        return $input;
    }

    public static function textCatalog(array $body, int $deadline): array
    {
        global $DB;
        $selector = $body['selector']; $fields = []; $next = null; $complete = true;
        if ($selector['kind'] === 'selected') {
            foreach ($selector['capture_keys'] as $key) {
                if (hrtime(true) >= $deadline || time() > $body['expires']) { throw new \RuntimeException('expired'); }
                try { $fields[] = ['capture_key' => $key, 'descriptor' => self::textPin($key, $body['entity_id']), 'reason' => null]; }
                catch (\Throwable $error) {
                    $reason = in_array($error->getMessage(), ['field_unavailable', 'unsupported_field', 'metadata_invalid'], true) ? $error->getMessage() : 'field_unavailable';
                    $fields[] = ['capture_key' => $key, 'descriptor' => null, 'reason' => $reason];
                }
            }
        } elseif ($selector['kind'] === 'native') {
            foreach (['native:name', 'native:comment'] as $key) { $fields[] = self::textPin($key, $body['entity_id']); }
        } else {
            if (!\Plugin::isPluginActive('fields')) { throw new \RuntimeException('field_unavailable'); }
            $rows = self::textRows('SELECT `id` FROM `glpi_plugin_fields_fields` WHERE `id`>' . $selector['after_id'] . ' ORDER BY `id` ASC LIMIT 33');
            $examined = 0; $last = $selector['after_id'];
            foreach ($rows as $row) {
                if ($examined === 32 || count($fields) === 8) { break; }
                if (hrtime(true) >= $deadline || time() > $body['expires']) { throw new \RuntimeException('expired'); }
                $last = (int) $row['id']; $examined++;
                try { $fields[] = self::textPin('fields:' . $last, $body['entity_id']); }
                catch (\RuntimeException) { continue; }
            }
            $complete = $examined === count($rows); $next = $complete ? null : $last;
        }
        return ['outcome' => 'observed', 'entity_id' => $body['entity_id'], 'fields' => $fields,
            'next_after_id' => $next, 'complete' => $complete, 'observed_at' => time()];
    }

    private static function textRows(string $sql): array
    {
        global $DB;
        $result = $DB->doQuery($sql);
        if ($result === false) { throw new \RuntimeException('metadata_invalid'); }
        $rows = [];
        while ($row = $DB->fetchAssoc($result)) { $rows[] = $row; }
        return $rows;
    }

    /** Discover Fields containers here, independently of A metadata or mappings. */
    public static function containers(string $type = 'Computer'): array
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
            if (!is_array($types) || !in_array($type, $types, true)) {
                continue;
            }
            if (!is_callable(['PluginFieldsContainer', 'getClassname']) || !function_exists('getTableForItemType')) {
                continue;
            }
            $class = \PluginFieldsContainer::getClassname($type, (string) $row['name']);
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
            $classes = [];
            foreach (TargetScope::types() as $type => $reason) {
                if ($reason === '') { $classes = array_merge($classes, array_column(self::containers($type), 'class')); }
            }
            return array_values(array_unique($classes));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function read(int $id, ?int $deadline = null, string $type = 'Computer'): array
    {
        global $DB;
        $deadline ??= hrtime(true) + 2_000_000_000;
        $asset = null;
        $item = TargetScope::model($type);
        foreach ($DB->request(['FROM' => $item::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
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
        foreach (self::containers($type) as $container) {
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
                'itemtype' => $type, 'plugin_fields_containers_id' => (int) $container['id']], 'ORDER' => 'id ASC', 'LIMIT' => 2]) as $row) {
                $values[] = array_intersect_key($row, array_flip($columns));
            }
            $custom[$container['table']] = $values;
        }
        ksort($asset);
        ksort($custom);
        return ['fingerprint' => hash('sha256', json_encode([$asset, $custom], JSON_THROW_ON_ERROR)), 'lifecycle' => $lifecycle];
    }
}
