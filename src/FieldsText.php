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
