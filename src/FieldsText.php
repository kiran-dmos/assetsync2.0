<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class FieldsText
{
    private const TYPE_TEXT = 'text';
    private const TYPE_TEXTAREA = 'textarea';
    private const TYPE_YESNO = 'yesno';
    private const TYPE_INT = 'int';
    private const TYPE_DATE = 'date';
    private const TYPE_DATETIME = 'datetime';
    private const TYPE_DROPDOWN = 'dropdown';
    private static ?array $runCache = null;

    /** Work12 selected metadata only. No asset row, legacy default, or run-cache fallback. */
    public static function selectedTextPin(string $key, ?int $entity = null, bool $update = false): array
    {
        global $DB;
        $field = null; $container = null;
        $native = in_array($key, ['name', 'comment', 'Computer.name', 'Computer.comment', 'native:name', 'native:comment'], true);
        if ($native) {
            $column = str_replace(['Computer.', 'native:'], '', $key); $options = [];
            foreach ((new \Computer())->rawSearchOptions() as $option) {
                if (is_array($option) && ($option['table'] ?? '') === 'glpi_computers' && ($option['field'] ?? '') === $column) { $options[] = $option; }
            }
            if (count($options) !== 1 || !is_numeric($options[0]['id'] ?? null)) { throw new \RuntimeException('Native selected field metadata unavailable.'); }
            NativeField::fromOption('Computer', $options[0]);
            $pin = ['kind' => 'native', 'key' => $column, 'capture_key' => 'native:' . $column,
                'type' => $column === 'name' ? 'text' : 'textarea', 'search_id' => (int) $options[0]['id'],
                'definition_id' => null, 'container_id' => null, 'class' => 'Computer', 'table' => 'glpi_computers', 'column' => $column];
        } else {
            if (!class_exists('PluginFieldsContainer') || !defined('PluginFieldsField::SEARCH_OPTION_STARTING_INDEX')) { throw new \RuntimeException('Selected Fields metadata unavailable.'); }
            $expectedClass = null;
            if (preg_match('/^fields:([1-9][0-9]*)$/D', $key, $match)) { $where = '`id`=' . $DB->quote($match[1]); }
            elseif (preg_match('/^Computer\.(PluginFieldsComputer[a-zA-Z0-9_]+)\.([a-z][a-z0-9_]*)$/D', $key, $match)) {
                $expectedClass = $match[1]; $where = '`name`=' . $DB->quote($match[2]);
            } else { throw new \RuntimeException('Selected Fields identity unavailable.'); }
            if (!AgentWake::transactionIdle()) {
                AgentInbox::sql('SELECT id FROM glpi_plugin_fields_fields WHERE ' . $where . ' LIMIT 33 LOCK IN SHARE MODE');
            }
            $matches = []; $inspected = 0;
            foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => [new \Glpi\DBAL\QueryExpression($where)], 'LIMIT' => 33]) as $candidate) {
                if (++$inspected > 32) { throw new \RuntimeException('Selected Fields definition budget exceeded.'); }
                if (!AgentWake::transactionIdle()) { AgentInbox::sql('SELECT id FROM glpi_plugin_fields_containers WHERE id=' . (int) $candidate['plugin_fields_containers_id'] . ' LOCK IN SHARE MODE'); }
                $owner = AgentInbox::one('SELECT * FROM `glpi_plugin_fields_containers` WHERE `id`=' . (int) $candidate['plugin_fields_containers_id']);
                if (!$owner) { continue; }
                $class = \PluginFieldsContainer::getClassname('Computer', (string) $owner['name']);
                if ($expectedClass === null || $expectedClass === $class) { $matches[] = [$candidate, $owner, $class]; }
            }
            if (count($matches) !== 1) { throw new \RuntimeException('Selected Fields identity unavailable or ambiguous.'); }
            [$field, $container, $class] = $matches[0];
            $types = json_decode((string) $container['itemtypes'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($types) || !in_array('Computer', $types, true) || empty($container['is_active']) || empty($field['is_active'])
                || !in_array($field['type'], ['text', 'textarea'], true) || !empty($field['multiple'])
                || !preg_match('/^PluginFieldsComputer[a-zA-Z0-9_]+$/D', $class) || !is_callable([$class, 'getTable'])
                || !preg_match('/^[a-z][a-z0-9_]*$/D', $field['name'])) { throw new \RuntimeException('Selected Fields definition unsupported.'); }
            $table = $class::getTable();
            if (!is_string($table) || !preg_match('/^glpi_plugin_fields_[a-z0-9_]+$/D', $table)) { throw new \RuntimeException('Selected Fields table unsupported.'); }
            if ($entity !== null && !in_array((string) $container['entities_id'], empty($container['is_recursive']) ? [(string) $entity] : EntitySyncRoute::strictEntityPath($entity), true)) {
                throw new \RuntimeException('Selected container does not apply to this asset.');
            }
            if (!\Session::isCron()) {
                $profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
                $right = $profile > 0 && is_callable(['PluginFieldsProfile', 'getRightOnContainer'])
                    ? \PluginFieldsProfile::getRightOnContainer($profile, (int) $container['id']) : 0;
                // Fields uses a Read / Read-Write enum, not core CRUD permission bits.
                if (!is_int($right) && !ctype_digit((string) $right)) { $right = 0; }
                if (!\Session::haveAccessToEntity((int) $container['entities_id'], true) || (int) $right < READ || ($update && (int) $right <= READ)) {
                    throw new \RuntimeException('Selected Fields permission denied.');
                }
            }
            $pin = ['kind' => 'fields', 'key' => 'Computer.' . $class . '.' . $field['name'], 'capture_key' => 'fields:' . (int) $field['id'],
                'type' => $field['type'], 'search_id' => \PluginFieldsField::SEARCH_OPTION_STARTING_INDEX + (int) $field['id'],
                'definition_id' => (int) $field['id'], 'container_id' => (int) $container['id'], 'class' => $class, 'table' => $table, 'column' => $field['name']];
        }
        $limits = AgentInbox::one('SELECT CHARACTER_MAXIMUM_LENGTH AS characters, CHARACTER_OCTET_LENGTH AS bytes FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $DB->quote($pin['table']) . ' AND COLUMN_NAME=' . $DB->quote($pin['column']));
        if (!$limits || !is_numeric($limits['characters']) || !is_numeric($limits['bytes']) || $limits['characters'] < 1 || $limits['bytes'] < 1) {
            throw new \RuntimeException('Selected text storage bounds unavailable.');
        }
        $pin['limits'] = ['characters' => $pin['type'] === 'text' ? min(255, (int) $limits['characters']) : (int) $limits['characters'], 'bytes' => (int) $limits['bytes']];
        $fieldFacts = $field ? ['active' => (bool) $field['is_active'], 'readonly' => (bool) $field['is_readonly'],
            'mandatory' => (bool) $field['mandatory'], 'multiple' => (bool) $field['multiple']] : null;
        $containerFacts = null;
        if ($container) {
            $types = array_values(array_unique($types)); sort($types, SORT_STRING);
            $containerFacts = ['active' => (bool) $container['is_active'], 'type' => (string) $container['type'],
                'entity_id' => (int) $container['entities_id'], 'recursive' => (bool) $container['is_recursive'], 'itemtypes' => $types];
        }
        $preimage = ['itemtype' => 'Computer'] + $pin + ['field' => $fieldFacts, 'container' => $containerFacts];
        $hash = AgentTask::hash($preimage); $limits = $pin['limits']; unset($pin['limits']);
        $pin += ['definition_hash' => $hash, 'limits' => $limits];
        TextBoth::validatePin($pin);
        return $pin;
    }

    /** Selected strict read for Work12. Missing/duplicate child is never the legacy blank default. */
    public static function readSelectedText(array $pin, int $id, int $entity, bool $update = false): array
    {
        global $DB;
        TextBoth::validatePin($pin);
        if (self::selectedTextPin($pin['key'], $entity, $update) !== $pin) { throw new \RuntimeException('Selected text definition changed; explicit reselection required.'); }
        $item = new \Computer();
        if (!$item->getFromDB($id) || (int) $item->fields['entities_id'] !== $entity || !empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])
            || (!\Session::isCron() && (!$item->can($id, READ) || ($update && !$item->can($id, UPDATE)) || !\Session::haveAccessToEntity($entity)))) {
            throw new \RuntimeException('Selected A asset permission or scope changed.');
        }
        $row = $item->fields;
        if ($pin['kind'] === 'fields') {
            $rows = iterator_to_array($DB->request(['FROM' => $pin['table'], 'WHERE' => ['items_id' => $id,
                'itemtype' => 'Computer', 'plugin_fields_containers_id' => $pin['container_id']], 'LIMIT' => 2]));
            if (count($rows) !== 1) { throw new \RuntimeException('Selected Fields child missing or ambiguous.'); }
            $row = array_values($rows)[0];
        }
        if (!array_key_exists($pin['column'], $row)) { throw new \RuntimeException('Selected text column unavailable.'); }
        TextBoth::normalize($row[$pin['column']], $pin);
        return ['value' => $row[$pin['column']], 'row' => $row, 'parent' => $item->fields];
    }

    /** One supported model call for an actual incoming storage group, never one call per field. */
    public static function updateSelectedTextGroup(array $changes, int $id, int $entity): void
    {
        global $DB;
        if (AgentWake::transactionIdle() || !array_is_list($changes) || count($changes) < 1 || count($changes) > 8) {
            throw new \RuntimeException('Selected group requires an owned model transaction.');
        }
        AgentInbox::sql('SELECT * FROM glpi_computers WHERE id=' . $id . ' FOR UPDATE');
        $first = $changes[0]['pin']; $proposed = []; $pins = []; $before = null;
        foreach ($changes as $change) {
            if (!is_array($change) || array_keys($change) !== ['pin','value']) { throw new \RuntimeException('Invalid selected group.'); }
            $pin = $change['pin']; TextBoth::validatePin($pin);
            if ($pin['kind'] !== $first['kind'] || $pin['table'] !== $first['table'] || $pin['container_id'] !== $first['container_id']
                || isset($pins[$pin['column']])) { throw new \RuntimeException('Mixed or duplicate selected storage group.'); }
            $selected = self::readSelectedText($pin, $id, $entity, true);
            if ($before !== null && ($before['row'] !== $selected['row'] || $before['parent'] !== $selected['parent'])) { throw new \RuntimeException('Selected group changed during validation.'); }
            $before = $selected; $pins[$pin['column']] = $pin; $proposed[$pin['column']] = TextBoth::normalize($change['value'], $pin);
        }
        if ($first['kind'] === 'native') {
            $options = (new \Computer())->rawSearchOptions();
            foreach ($pins as $pin) {
                $matches = array_filter($options, static fn ($o): bool => is_array($o) && ($o['table'] ?? '') === $pin['table'] && ($o['field'] ?? '') === $pin['column']);
                if (count($matches) !== 1 || array_filter($matches, static fn ($o): bool => !empty($o['readonly']) || !empty($o['is_readonly']))) { throw new \RuntimeException('Selected native field is not writable.'); }
            }
            $item = new \Computer(); $item->getFromDB($id);
            if (!$item->update(['id' => $id] + $proposed)) { throw new \RuntimeException('destination_validation'); }
        } else {
            $where = ' WHERE items_id=' . $id . ' AND itemtype=\'Computer\' AND plugin_fields_containers_id=' . $first['container_id'];
            $rows = AgentInbox::sql('SELECT * FROM ' . $first['table'] . $where . ' LIMIT 2 FOR UPDATE');
            $locked = [];
            while ($row = $DB->fetchAssoc($rows)) { $locked[] = $row; }
            if (count($locked) !== 1 || $locked[0] !== $before['row']) { throw new \RuntimeException('Selected child missing or changed.'); }
            $input = ['items_id' => $id, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => $first['container_id']] + $proposed;
            $fields = []; $overrides = [];
            $rows = AgentInbox::sql('SELECT * FROM glpi_plugin_fields_fields WHERE plugin_fields_containers_id=' . $first['container_id'] . ' LIMIT 129 LOCK IN SHARE MODE');
            while ($field = $DB->fetchAssoc($rows)) {
                if (count($fields) >= 128) { throw new \RuntimeException('Selected container validation budget exceeded.'); }
                $fields[(int) $field['id']] = $field;
            }
            $rows = AgentInbox::sql('SELECT o.* FROM glpi_plugin_fields_statusoverrides o INNER JOIN glpi_plugin_fields_fields f'
                . ' ON f.id=o.plugin_fields_fields_id WHERE f.plugin_fields_containers_id=' . $first['container_id'] . ' LIMIT 129 LOCK IN SHARE MODE');
            $count = 0;
            while ($override = $DB->fetchAssoc($rows)) {
                if (++$count > 128) { throw new \RuntimeException('Selected status validation budget exceeded.'); }
                $states = json_decode((string) ($override['states'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($states)) { throw new \RuntimeException('destination_validation'); }
                if ($override['itemtype'] !== 'Computer' || !in_array((int) ($before['parent']['states_id'] ?? 0), $states, false)) { continue; }
                $fieldId = (int) $override['plugin_fields_fields_id'];
                if (isset($overrides[$fieldId])) { throw new \RuntimeException('destination_validation'); }
                $overrides[$fieldId] = $override;
            }
            // Validate the complete proposal first: a selected sibling may fill an old blank.
            foreach ($fields as $fieldId => $field) {
                if (empty($field['is_active']) || in_array($field['type'], ['header','yesno'], true)) { continue; }
                $mandatory = (int) ($overrides[$fieldId]['mandatory'] ?? $field['mandatory']) === 1;
                if (!$mandatory && empty($field['mandatory'])) { continue; }
                $column = $field['type'] === 'dropdown' ? 'plugin_fields_' . $field['name'] . 'dropdowns_id' : $field['name'];
                if (!preg_match('/^[a-z][a-z0-9_]*$/D', $column) || !array_key_exists($column, $before['row'])
                    || !empty($field['multiple']) || $field['type'] === 'glpi_item') { throw new \RuntimeException('destination_validation'); }
                $value = $proposed[$column] ?? $before['row'][$column];
                if ($mandatory && (empty($value) || (in_array($field['type'], ['date','datetime'], true) && $value === 'NULL'))) { throw new \RuntimeException('destination_validation'); }
                $input[$column] = $value;
            }
            $container = new \PluginFieldsContainer();
            if (!$container->getFromDB($first['container_id']) || !$container->updateFieldsValues($input, 'Computer', false)) { throw new \RuntimeException('destination_validation'); }
        }
        foreach ($pins as $column => $pin) {
            $after = self::readSelectedText($pin, $id, $entity, true);
            if ($after['value'] !== $proposed[$column]) { throw new \RuntimeException('Selected group readback was lossy.'); }
            foreach (['row','parent'] as $part) {
                $allowed = ['date_mod' => true];
                if ($part === 'row' || $first['kind'] === 'native') { $allowed += array_fill_keys(array_keys($proposed), true); }
                if (array_diff_key($before[$part], $allowed) !== array_diff_key($after[$part], $allowed)) { throw new \RuntimeException('Selected group changed an unselected value or identity.'); }
            }
        }
    }

    public static function updateSelectedText(array $pin, int $id, int $entity, string $value): void
    {
        self::updateSelectedTextGroup([['pin' => $pin, 'value' => $value]], $id, $entity);
    }

    public static function beginRunCache(): void
    {
        self::$runCache = [];
    }

    public static function endRunCache(): void
    {
        self::$runCache = null;
    }

    public static function isCustom(string $key): bool
    {
        return str_contains($key, 'PluginFields');
    }

    public static function descriptor(string $itemtype, string $key): array
    {
        if (isset(self::$runCache['descriptors'][$itemtype][$key])) {
            return self::$runCache['descriptors'][$itemtype][$key];
        }
        if (!preg_match('/^' . preg_quote($itemtype, '/') . '\.(PluginFields[A-Za-z0-9]+)\.([a-z][a-z0-9_]*)$/D', $key, $parts)) {
            throw new \RuntimeException('Invalid Fields-plugin identifier: ' . $key);
        }

        $descriptor = ['class' => $parts[1], 'field' => $parts[2], 'table' => self::tableForItemType($parts[1])];
        if (self::$runCache !== null && is_string($descriptor['table']) && $descriptor['table'] !== '') {
            self::$runCache['descriptors'][$itemtype][$key] = $descriptor;
        }
        return $descriptor;
    }

    public static function key(string $itemtype, array $option): string
    {
        $table = self::rowTableFromOption($option);
        if (!str_starts_with($table, 'glpi_plugin_fields_')) {
            return '';
        }

        $field = self::columnFromOption($option);
        if ($field === '') {
            return '';
        }

        return $itemtype . '.' . self::itemTypeForTable($table) . '.' . $field;
    }

    private static function tableForItemType(string $class): mixed
    {
        if (isset(self::$runCache['tables'][$class])) {
            return self::$runCache['tables'][$class];
        }
        $table = \getTableForItemType($class);
        if (self::$runCache !== null && is_string($table) && $table !== '') {
            self::$runCache['tables'][$class] = $table;
        }
        return $table;
    }

    private static function itemTypeForTable(string $table): mixed
    {
        if (isset(self::$runCache['classes'][$table])) {
            return self::$runCache['classes'][$table];
        }
        $class = \getItemTypeForTable($table);
        if (self::$runCache !== null && is_string($class) && $class !== '') {
            self::$runCache['classes'][$table] = $class;
        }
        return $class;
    }

    public static function isDropdownKey(string $key): bool
    {
        return preg_match('/\.plugin_fields_[a-z][a-z0-9_]*dropdowns_id$/D', $key) === 1;
    }

    public static function typeFromMetadata(array $metadata): string
    {
        foreach (['pfields_type', 'type'] as $field) {
            if (!isset($metadata[$field]) || !is_scalar($metadata[$field])) {
                continue;
            }

            $type = self::canonicalType((string) $metadata[$field]);
            if ($type !== '') {
                return $type;
            }

            if (trim((string) $metadata[$field]) !== '') {
                return '';
            }
        }

        $datatype = isset($metadata['datatype']) && is_scalar($metadata['datatype'])
            ? strtolower(trim((string) $metadata['datatype']))
            : '';

        return match ($datatype) {
            'bool', 'boolean' => self::TYPE_YESNO,
            'int', 'integer', 'number' => self::TYPE_INT,
            'date' => self::TYPE_DATE,
            'datetime' => self::TYPE_DATETIME,
            self::TYPE_DROPDOWN => self::TYPE_DROPDOWN,
            default => '',
        };
    }

    public static function validate(array $option): string
    {
        $type = self::typeFromMetadata($option);
        if ($type === '') {
            throw new \RuntimeException('Only Fields-plugin scalar fields and single-select Fields dropdown fields are supported (text, textarea, yesno, number, date, datetime, dropdown): ' . ($option['name'] ?? $option['field'] ?? 'unknown'));
        }

        if ($type === self::TYPE_DROPDOWN && self::truthy($option['is_multiple'] ?? $option['multiple'] ?? 0)) {
            throw new \RuntimeException('Fields-plugin multi-select dropdown fields are not supported: ' . ($option['name'] ?? $option['field'] ?? 'unknown'));
        }

        return $type;
    }

    public static function normalizeValue(string $type, $value)
    {
        if ($type === self::TYPE_TEXT || $type === self::TYPE_TEXTAREA) {
            if ($value === null) {
                return '';
            }

            if (!is_scalar($value)) {
                throw new \RuntimeException('Unsupported Fields-plugin ' . $type . ' value. Expected string or blank.');
            }

            return (string) $value;
        }

        if ($type === self::TYPE_YESNO) {
            return self::normalizeYesNo($value);
        }

        if ($type === self::TYPE_INT) {
            return self::normalizeInt($value);
        }

        if ($type === self::TYPE_DATE) {
            if ($value === null || $value === '') {
                return '';
            }

            return self::normalizeDate($value);
        }

        if ($type === self::TYPE_DATETIME) {
            if ($value === null || $value === '') {
                return '';
            }

            return self::normalizeDateTime($value);
        }

        if ($type === self::TYPE_DROPDOWN) {
            if ($value === null) {
                return '';
            }

            if (!is_scalar($value)) {
                throw new \RuntimeException('Unsupported Fields-plugin dropdown value. Expected a label or blank.');
            }

            return trim((string) $value);
        }

        throw new \RuntimeException('Unsupported Fields-plugin field type: ' . $type);
    }

    public static function normalizeReadValue(string $type, $value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::normalizeValue($type, $value);
    }

    public static function missingValue(string $type)
    {
        return $type === self::TYPE_YESNO ? 0 : '';
    }

    public static function metadataFromOption(string $itemtype, array $option, $optionId = null): array
    {
        $key = self::key($itemtype, $option);
        if ($key === '') {
            throw new \RuntimeException('Invalid Fields-plugin metadata: missing generated table or column.');
        }

        $descriptor = self::descriptor($itemtype, $key);
        $type = self::validate($option);
        $definitionName = $type === self::TYPE_DROPDOWN
            ? self::dropdownDefinitionName($descriptor['field'])
            : $descriptor['field'];

        return [
            'key' => $key,
            'itemtype' => $itemtype,
            'class' => $descriptor['class'],
            'table' => $descriptor['table'],
            'field' => $descriptor['field'],
            'definition_name' => $definitionName,
            'type' => $type,
            'dropdown_class' => $type === self::TYPE_DROPDOWN ? self::dropdownClass($definitionName) : '',
            'dropdown_table' => $type === self::TYPE_DROPDOWN ? self::dropdownTable($definitionName, $option) : '',
            'field_id' => self::positiveInt($option['pfields_fields_id'] ?? 0),
            'container_id' => self::positiveInt($option['plugin_fields_containers_id'] ?? 0),
            'search_option_id' => self::positiveInt($option['id'] ?? $optionId ?? 0),
            'writable' => empty($option['is_readonly']),
        ];
    }

    /** Remote generated classes must never be resolved through the local plugin installation. */
    public static function remoteKey(string $itemtype, array $option): string
    {
        $table = self::nestedScalar($option, ['joinparams', 'beforejoin', 'table']);
        if ($table === '') {
            $table = (string) ($option['table'] ?? '');
        }
        if (self::typeFromMetadata($option) === 'dropdown' && self::dropdownDefinitionNameFromTable($table) !== '') {
            $class = self::rowClassFromOptionUid($option);
            if ($class !== '') {
                $table = 'glpi_plugin_fields_' . strtolower(substr($class, strlen('PluginFields'))) . 's';
            }
        }
        if (!preg_match('/^glpi_plugin_fields_(' . strtolower($itemtype) . '[a-z0-9_]*)s$/D', $table, $match)) {
            return '';
        }
        $class = 'PluginFields' . ucfirst($match[1]);
        $column = self::columnFromOption($option);
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $column)) {
            return '';
        }
        $key = $itemtype . '.' . $class . '.' . $column;
        $uid = (string) ($option['uid'] ?? '');
        $dropdownUid = $itemtype . '.' . $class . '.' . self::dropdownClass(self::dropdownDefinitionName($column)) . '.' . ($option['field'] ?? '');
        if ($uid !== '' && $uid !== $key && !(self::typeFromMetadata($option) === 'dropdown' && $uid === $dropdownUid)) {
            throw new \RuntimeException('Remote Fields UID does not match its table and column: ' . $uid);
        }
        return $key;
    }

    public static function remoteMetadataFromOption(string $itemtype, array $option, mixed $optionId, array $definition, ?array $container = null): array
    {
        $key = self::remoteKey($itemtype, $option);
        $class = explode('.', $key)[1] ?? '';
        $column = self::columnFromOption($option);
        if ($key === '' || (int) ($definition['id'] ?? 0) !== self::positiveInt($option['pfields_fields_id'] ?? 0)) {
            throw new \RuntimeException('Remote Fields definition identity does not match: ' . $key);
        }
        $type = self::validate($option);
        $metadata = [
            'key' => $key, 'itemtype' => $itemtype, 'class' => $class,
            'table' => 'glpi_plugin_fields_' . strtolower(substr($class, strlen('PluginFields'))) . 's',
            'field' => $column, 'type' => $type,
            'definition_name' => $type === 'dropdown' ? self::dropdownDefinitionName($column) : $column,
            'search_option_id' => self::positiveInt($option['id'] ?? $optionId),
        ];
        $metadata = self::metadataWithDefinition($metadata, $definition);
        if ($container !== null) {
            self::validateRemoteContainer($metadata, $container);
        }
        return $metadata;
    }

    public static function validateRemoteContainer(array $metadata, array $container): void
    {
        $name = (string) ($container['name'] ?? '');
        $itemtype = $metadata['itemtype'];
        $class = 'PluginFields' . ucfirst(strtolower($itemtype . preg_replace('/s$/', '', $name)));
        $itemtypes = json_decode((string) ($container['itemtypes'] ?? ''), true);
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || $metadata['class'] !== $class
            || empty($container['is_active']) || !is_array($itemtypes) || !in_array($itemtype, $itemtypes, true)
            || (int) ($container['id'] ?? 0) !== $metadata['container_id']) {
            throw new \RuntimeException('Remote Fields definition/container identity does not match: ' . $metadata['key']);
        }
    }

    public static function metadataWithDefinition(array $metadata, array $definition): array
    {
        $type = self::typeFromMetadata($definition);
        if ($type === '' || $type !== ($metadata['type'] ?? '')) {
            throw new \RuntimeException('The Fields-plugin definition type does not match the generated field metadata: ' . ($metadata['key'] ?? 'unknown'));
        }

        if ($type === self::TYPE_DROPDOWN && self::truthy($definition['multiple'] ?? 0)) {
            throw new \RuntimeException('Fields-plugin multi-select dropdown fields are not supported: ' . ($metadata['key'] ?? 'unknown'));
        }

        $definitionName = (string) ($metadata['definition_name'] ?? $metadata['field'] ?? '');
        if (($definition['name'] ?? '') !== $definitionName || empty($definition['is_active'])) {
            throw new \RuntimeException('The Fields-plugin definition is missing, inactive, or does not match the generated column: ' . ($metadata['key'] ?? 'unknown'));
        }

        $containerId = self::positiveInt($definition['plugin_fields_containers_id'] ?? 0);
        if ($containerId <= 0) {
            throw new \RuntimeException('The Fields-plugin definition is missing a container id: ' . ($metadata['key'] ?? 'unknown'));
        }

        $metadata['field_id'] = self::positiveInt($definition['id'] ?? ($metadata['field_id'] ?? 0));
        $metadata['container_id'] = $containerId;
        $metadata['writable'] = empty($definition['is_readonly']);
        if ($type === self::TYPE_DROPDOWN) {
            $metadata['dropdown_class'] = self::dropdownClass($definitionName);
            $metadata['dropdown_table'] = self::dropdownTable($definitionName);
        }

        return $metadata;
    }

    public static function localOptions(string $itemtype): array
    {
        if (!class_exists('\PluginFieldsContainer')) {
            return [];
        }

        return \PluginFieldsContainer::getAddSearchOptions($itemtype);
    }

    public static function customMetadata(string $itemtype, array $keys): array
    {
        $wanted = [];
        foreach (array_unique($keys) as $key) {
            if (self::isCustom((string) $key)) {
                $wanted[(string) $key] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $metadata = [];
        foreach (self::localOptions($itemtype) as $optionId => $option) {
            if (!is_array($option)) {
                continue;
            }

            $key = self::key($itemtype, $option);
            if ($key !== '' && isset($wanted[$key])) {
                $metadata[$key] = self::metadataFromOption($itemtype, $option, $optionId);
            }
        }

        foreach ($wanted as $key => $_) {
            if (!isset($metadata[$key])) {
                throw new \RuntimeException('The mapped local Fields-plugin field is unavailable: ' . $key);
            }
        }

        return $metadata;
    }

    public static function customTypes(string $itemtype, array $keys): array
    {
        $types = [];
        foreach (self::customMetadata($itemtype, $keys) as $key => $metadata) {
            $types[$key] = $metadata['type'];
        }

        return $types;
    }

    /**
     * @return array<string,array{option_id:string,itemtype_link:string}>
     */
    public static function customHistoryRefs(string $itemtype, array $keys): array
    {
        $refs = [];

        foreach (self::customMetadata($itemtype, $keys) as $key => $metadata) {
            $refs[$key] = [
                'option_id' => self::positiveInt($metadata['search_option_id'] ?? 0) > 0 ? (string) $metadata['search_option_id'] : '',
                'itemtype_link' => (string) ($metadata['class'] ?? ''),
            ];
        }

        return $refs;
    }

    public static function localValues(string $itemtype, int $itemsId, array $keys): array
    {
        $values = [];
        $metadata = self::customMetadata($itemtype, $keys);
        foreach (array_unique($keys) as $key) {
            if (!self::isCustom($key)) {
                continue;
            }
            $field = $metadata[$key];
            $field['items_id'] = $itemsId;
            $row = new $field['class']();
            $found = $row->getFromDBByCrit(['items_id' => $itemsId, 'itemtype' => $itemtype]);
            if (!$found) {
                $values[$key] = self::missingValue($field['type']);
                continue;
            }
            $values[$key] = self::readValue($field, $row->fields[$field['field']] ?? null);
        }

        return $values;
    }

    public static function updateLocal(string $itemtype, int $itemsId, array $values, bool $allowReadonly = false): bool
    {
        try {
            $preparedValues = self::prepareLocalWrite($itemtype, $itemsId, $values, $allowReadonly);
        } catch (\UnexpectedValueException) {
            return false;
        }
        foreach ($preparedValues as $key => $prepared) {
            $metadata = $prepared['metadata'];
            $value = $prepared['value'];
            $storedValue = $prepared['stored'];
            $row = new $metadata['class']();
            $input = ['items_id' => $itemsId, 'itemtype' => $itemtype,
                'plugin_fields_containers_id' => $metadata['container_id'],
                $metadata['field'] => $storedValue];
            if ($row->getFromDBByCrit(['items_id' => $itemsId, 'itemtype' => $itemtype])) {
                // GLPI loosely compares changes: NULL equals integer 0, but not string "0".
                if ($storedValue === 0 && ($row->fields[$metadata['field']] ?? null) === null) {
                    $input[$metadata['field']] = '0';
                }
                $ok = $row->update(['id' => $row->fields['id']] + $input);
            } else {
                $ok = $row->add($input);
            }
            if (!$ok) {
                return false;
            }
            if (!$row->getFromDBByCrit(['items_id' => $itemsId, 'itemtype' => $itemtype]) || self::readValue($metadata, $row->fields[$metadata['field']] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }

    public static function prepareLocalWrite(string $itemtype, int $itemsId, array $values, bool $allowReadonly = false): array
    {
        $prepared = [];
        foreach ($values as $key => $value) {
            $metadata = null;
            foreach (self::localOptions($itemtype) as $optionId => $option) {
                if (self::key($itemtype, $option) === $key) {
                    $metadata = self::metadataFromOption($itemtype, $option, $optionId);
                    $definition = new \PluginFieldsField();
                    if (!$definition->getFromDB((int) $metadata['field_id'])) {
                        throw new \UnexpectedValueException('The local Fields definition is unavailable: ' . $key);
                    }
                    $metadata = self::metadataWithDefinition($metadata, $definition->fields);
                    break;
                }
            }
            if ($metadata === null || (!$allowReadonly && !$metadata['writable'])) {
                throw new \UnexpectedValueException('The local Fields field is unavailable or read-only: ' . $key);
            }
            $value = self::normalizeValue($metadata['type'], $value);
            $metadata['items_id'] = $itemsId;
            $storedValue = $metadata['type'] === self::TYPE_DROPDOWN
                ? self::localDropdownIdForLabel($metadata, $value)
                : $value;
            $prepared[$key] = ['metadata' => $metadata, 'value' => $value, 'stored' => $storedValue];
        }
        return $prepared;
    }

    public static function readValue(array $metadata, $value)
    {
        if (($metadata['type'] ?? '') === self::TYPE_DROPDOWN) {
            return self::localDropdownLabelForId($metadata, $value);
        }

        return self::normalizeReadValue((string) ($metadata['type'] ?? ''), $value);
    }

    public static function dropdownLabel(array $row): string
    {
        foreach (['completename', 'name'] as $field) {
            if (isset($row[$field]) && is_scalar($row[$field])) {
                $label = trim((string) $row[$field]);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '';
    }

    private static function canonicalType(string $type): string
    {
        return match (strtolower(trim($type))) {
            self::TYPE_TEXT => self::TYPE_TEXT,
            self::TYPE_TEXTAREA => self::TYPE_TEXTAREA,
            self::TYPE_YESNO => self::TYPE_YESNO,
            'number', self::TYPE_INT, 'integer' => self::TYPE_INT,
            self::TYPE_DATE => self::TYPE_DATE,
            self::TYPE_DATETIME => self::TYPE_DATETIME,
            self::TYPE_DROPDOWN => self::TYPE_DROPDOWN,
            default => '',
        };
    }

    private static function rowTableFromOption(array $option): string
    {
        $beforeJoinTable = self::nestedScalar($option, ['joinparams', 'beforejoin', 'table']);
        if (str_starts_with($beforeJoinTable, 'glpi_plugin_fields_')) {
            return $beforeJoinTable;
        }

        $table = isset($option['table']) && is_scalar($option['table']) ? trim((string) $option['table']) : '';
        if (self::dropdownDefinitionNameFromTable($table) !== '') {
            $class = self::rowClassFromOptionUid($option);
            if ($class !== '') {
                return self::tableForItemType($class);
            }

            return '';
        }

        return $table;
    }

    private static function columnFromOption(array $option): string
    {
        if (str_starts_with(self::nestedScalar($option, ['joinparams', 'beforejoin', 'table']), 'glpi_plugin_fields_')) {
            $linkfield = isset($option['linkfield']) && is_scalar($option['linkfield']) ? trim((string) $option['linkfield']) : '';
            if ($linkfield !== '') {
                return $linkfield;
            }
        }

        if (self::typeFromMetadata($option) === self::TYPE_DROPDOWN) {
            foreach (['linkfield', 'field'] as $fieldName) {
                $column = isset($option[$fieldName]) && is_scalar($option[$fieldName]) ? trim((string) $option[$fieldName]) : '';
                if (self::dropdownDefinitionName($column) !== '') {
                    return $column;
                }
            }

            $definitionName = self::dropdownDefinitionNameFromTable(
                isset($option['table']) && is_scalar($option['table']) ? trim((string) $option['table']) : ''
            );

            return $definitionName === '' ? '' : 'plugin_fields_' . $definitionName . 'dropdowns_id';
        }

        return isset($option['field']) && is_scalar($option['field']) ? trim((string) $option['field']) : '';
    }

    private static function dropdownDefinitionName(string $column): string
    {
        if (preg_match('/^plugin_fields_([a-z][a-z0-9_]*)dropdowns_id$/D', $column, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private static function dropdownDefinitionNameFromTable(string $table): string
    {
        if (preg_match('/^glpi_plugin_fields_([a-z][a-z0-9_]*)dropdowns$/D', $table, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private static function rowClassFromOptionUid(array $option): string
    {
        $uid = isset($option['uid']) && is_scalar($option['uid']) ? trim((string) $option['uid']) : '';
        if (preg_match('/^[A-Za-z0-9_]+\\.(PluginFields[A-Za-z0-9]+)\\.PluginFields[A-Za-z0-9]+Dropdown\\.(?:name|completename)$/D', $uid, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private static function dropdownClass(string $fieldName): string
    {
        return $fieldName === '' ? '' : 'PluginFields' . ucfirst($fieldName) . 'Dropdown';
    }

    private static function dropdownTable(string $fieldName, array $option = []): string
    {
        $table = isset($option['table']) && is_scalar($option['table']) ? trim((string) $option['table']) : '';
        if ($table !== '' && $table !== self::rowTableFromOption($option)) {
            return $table;
        }

        return $fieldName === '' ? '' : 'glpi_plugin_fields_' . $fieldName . 'dropdowns';
    }

    private static function localDropdownLabelForId(array $metadata, $value): string
    {
        $id = NativeField::referenceId($value);
        if ($id <= 0) {
            return '';
        }

        $row = self::localDropdownRowById($metadata, $id);
        if ($row === []) {
            throw new \RuntimeException('The local Fields-plugin dropdown option is missing for ' . ($metadata['key'] ?? 'unknown') . ': ' . $id);
        }

        $label = self::dropdownLabel($row);
        if ($label === '') {
            throw new \RuntimeException('The local Fields-plugin dropdown option has no label for ' . ($metadata['key'] ?? 'unknown') . ': ' . $id);
        }

        return $label;
    }

    private static function localDropdownIdForLabel(array $metadata, string $label): int
    {
        $label = self::normalizeValue(self::TYPE_DROPDOWN, $label);
        if ($label === '') {
            return 0;
        }

        $matches = [];
        foreach (self::localDropdownRows($metadata) as $row) {
            if (!is_array($row) || self::dropdownLabel($row) !== $label) {
                continue;
            }
            if (!self::localDropdownPermitted($metadata, $row)) {
                continue;
            }

            $id = self::positiveInt($row['id'] ?? 0);
            if ($id > 0) {
                $matches[$id] = true;
            }
        }

        if ($matches === []) {
            throw new \RuntimeException('The destination Fields-plugin dropdown option is missing for ' . ($metadata['key'] ?? 'unknown') . ': ' . $label);
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('The destination Fields-plugin dropdown option label is duplicated for ' . ($metadata['key'] ?? 'unknown') . ': ' . $label);
        }

        return (int) array_key_first($matches);
    }

    private static function localDropdownRowById(array $metadata, int $id): array
    {
        $class = (string) ($metadata['dropdown_class'] ?? '');
        if ($class !== '' && class_exists($class)) {
            $dropdown = new $class();
            if (method_exists($dropdown, 'getFromDB') && $dropdown->getFromDB($id)) {
                return is_array($dropdown->fields ?? null) && self::localDropdownPermitted($metadata, $dropdown->fields) ? $dropdown->fields : [];
            }
        }

        foreach (self::localDropdownRows($metadata) as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
                return self::localDropdownPermitted($metadata, $row) ? $row : [];
            }
        }

        return [];
    }

    private static function localDropdownPermitted(array $metadata, array $row): bool
    {
        $class = $metadata['dropdown_class'];
        if (!class_exists($class)) {
            throw new \RuntimeException('The local dropdown class is unavailable: ' . $class);
        }
        $dropdown = new $class();
        $id = NativeField::referenceId($row['id'] ?? null);
        if (!$dropdown->getFromDB($id) || self::dropdownLabel($row) !== self::dropdownLabel($dropdown->fields)) {
            throw new \RuntimeException('The local dropdown option changed during validation.');
        }
        if (method_exists($dropdown, 'can') && !$dropdown->can($id, defined('READ') ? READ : 1)) {
            return false;
        }
        $path = [0];
        if (isset($dropdown->fields['entities_id'])) {
            $itemtype = $metadata['itemtype'];
            $asset = new $itemtype();
            if (!$asset->getFromDB((int) ($metadata['items_id'] ?? 0)) || !isset($asset->fields['entities_id'])) {
                throw new \RuntimeException('Cannot establish the local dropdown entity scope.');
            }
            $path = NativeField::localEntityPath((int) $asset->fields['entities_id']);
        }
        return NativeField::permitted($dropdown->fields, ['relation' => $class, 'itemtype' => $metadata['itemtype']], $path);
    }

    private static function localDropdownRows(array $metadata): array
    {
        $class = (string) ($metadata['dropdown_class'] ?? '');
        if ($class !== '' && class_exists($class)) {
            $dropdown = new $class();
            if (method_exists($dropdown, 'find')) {
                $rows = $dropdown->find([]);
                if (is_array($rows)) {
                    return $rows;
                }
            }
        }

        $db = $GLOBALS['DB'] ?? null;
        $table = (string) ($metadata['dropdown_table'] ?? '');
        if (!is_object($db) || $table === '' || !method_exists($db, 'request')) {
            return [];
        }

        $rows = $db->request(['FROM' => $table]);
        $found = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $found[] = $row;
            }
        }

        return $found;
    }

    private static function nestedScalar(array $source, array $path): string
    {
        $value = $source;
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return '';
            }

            $value = $value[$part];
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function truthy($value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function normalizeYesNo($value): int
    {
        if ($value === true) {
            return 1;
        }

        if ($value === false) {
            return 0;
        }

        if ($value === 0 || $value === '0' || $value === 'no') {
            return 0;
        }

        if ($value === 1 || $value === '1' || $value === 'yes') {
            return 1;
        }

        throw new \RuntimeException('Unsupported Fields-plugin yesno value. Expected 0, 1, "0", "1", true, false, "yes", or "no".');
    }

    private static function normalizeInt($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $digits = ltrim(ltrim($value, '-'), '0');
            $canonical = ($digits !== '' && str_starts_with($value, '-') ? '-' : '') . ($digits === '' ? '0' : $digits);
            if (filter_var($canonical, FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('Unsupported Fields-plugin number value. Integer is out of range.');
            }
            return (int) $value;
        }

        throw new \RuntimeException('Unsupported Fields-plugin number value. Expected an integer-shaped value.');
    }

    private static function normalizeDate($value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \RuntimeException('Unsupported Fields-plugin date value. Expected YYYY-MM-DD.');
        }

        return self::validDate('Y-m-d', $value);
    }

    private static function normalizeDateTime($value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new \RuntimeException('Unsupported Fields-plugin datetime value. Expected YYYY-MM-DD HH:MM:SS.');
        }

        return self::validDate('Y-m-d H:i:s', $value);
    }

    private static function validDate(string $format, string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0);

        if ($date === false || $hasErrors || $date->format($format) !== $value) {
            throw new \RuntimeException('Unsupported Fields-plugin ' . ($format === 'Y-m-d' ? 'date' : 'datetime') . ' value.');
        }

        return $value;
    }

    private static function positiveInt($value): int
    {
        return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 0;
    }
}
