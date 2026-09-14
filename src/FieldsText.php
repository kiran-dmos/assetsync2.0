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

    public static function isCustom(string $key): bool
    {
        return str_contains($key, 'PluginFields');
    }

    public static function descriptor(string $itemtype, string $key): array
    {
        if (!preg_match('/^' . preg_quote($itemtype, '/') . '\.(PluginFields[A-Za-z0-9]+)\.([a-z][a-z0-9_]*)$/D', $key, $parts)) {
            throw new \RuntimeException('Invalid Fields-plugin identifier: ' . $key);
        }

        return ['class' => $parts[1], 'field' => $parts[2], 'table' => \getTableForItemType($parts[1])];
    }

    public static function key(string $itemtype, array $option): string
    {
        $table = (string) ($option['table'] ?? '');
        if (!str_starts_with($table, 'glpi_plugin_fields_')) {
            return '';
        }

        return $itemtype . '.' . \getItemTypeForTable($table) . '.' . ($option['field'] ?? '');
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
            default => '',
        };
    }

    public static function validate(array $option): string
    {
        $type = self::typeFromMetadata($option);
        if ($type === '') {
            throw new \RuntimeException('Only Fields-plugin scalar fields are supported (text, textarea, yesno, number, date, datetime): ' . ($option['name'] ?? $option['field'] ?? 'unknown'));
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
            return self::normalizeDate($value);
        }

        if ($type === self::TYPE_DATETIME) {
            return self::normalizeDateTime($value);
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

        return [
            'key' => $key,
            'itemtype' => $itemtype,
            'class' => $descriptor['class'],
            'table' => $descriptor['table'],
            'field' => $descriptor['field'],
            'type' => self::validate($option),
            'field_id' => self::positiveInt($option['pfields_fields_id'] ?? 0),
            'container_id' => self::positiveInt($option['plugin_fields_containers_id'] ?? 0),
            'search_option_id' => self::positiveInt($option['id'] ?? $optionId ?? 0),
            'writable' => empty($option['is_readonly']),
        ];
    }

    public static function metadataWithDefinition(array $metadata, array $definition): array
    {
        $type = self::typeFromMetadata($definition);
        if ($type === '' || $type !== ($metadata['type'] ?? '')) {
            throw new \RuntimeException('The Fields-plugin definition type does not match the generated field metadata: ' . ($metadata['key'] ?? 'unknown'));
        }

        if (($definition['name'] ?? '') !== ($metadata['field'] ?? '') || empty($definition['is_active'])) {
            throw new \RuntimeException('The Fields-plugin definition is missing, inactive, or does not match the generated column: ' . ($metadata['key'] ?? 'unknown'));
        }

        $containerId = self::positiveInt($definition['plugin_fields_containers_id'] ?? 0);
        if ($containerId <= 0) {
            throw new \RuntimeException('The Fields-plugin definition is missing a container id: ' . ($metadata['key'] ?? 'unknown'));
        }

        $metadata['field_id'] = self::positiveInt($definition['id'] ?? ($metadata['field_id'] ?? 0));
        $metadata['container_id'] = $containerId;
        $metadata['writable'] = empty($definition['is_readonly']);

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
            $row = new $field['class']();
            $found = $row->getFromDBByCrit(['items_id' => $itemsId, 'itemtype' => $itemtype]);
            if (!$found) {
                $values[$key] = self::missingValue($field['type']);
                continue;
            }
            $values[$key] = self::normalizeReadValue($field['type'], $row->fields[$field['field']] ?? null);
        }

        return $values;
    }

    public static function updateLocal(string $itemtype, int $itemsId, array $values): bool
    {
        foreach ($values as $key => $value) {
            $metadata = null;
            foreach (self::localOptions($itemtype) as $optionId => $option) {
                if (self::key($itemtype, $option) === $key) {
                    $metadata = self::metadataFromOption($itemtype, $option, $optionId);
                    $definition = new \PluginFieldsField();
                    if (!$definition->getFromDB((int) $metadata['field_id'])) {
                        return false;
                    }
                    $metadata = self::metadataWithDefinition($metadata, $definition->fields);
                    break;
                }
            }
            if ($metadata === null || !$metadata['writable']) {
                return false;
            }
            $value = self::normalizeValue($metadata['type'], $value);
            $row = new $metadata['class']();
            $input = ['items_id' => $itemsId, 'itemtype' => $itemtype,
                'plugin_fields_containers_id' => $metadata['container_id'],
                $metadata['field'] => $value];
            if ($row->getFromDBByCrit(['items_id' => $itemsId, 'itemtype' => $itemtype])) {
                $ok = $row->update(['id' => $row->fields['id']] + $input);
            } else {
                $ok = $row->add($input);
            }
            if (!$ok) {
                return false;
            }
            if (!$row->getFromDBByCrit(['items_id' => $itemsId, 'itemtype' => $itemtype]) || self::normalizeReadValue($metadata['type'], $row->fields[$metadata['field']] ?? null) !== $value) {
                return false;
            }
        }

        return true;
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
            default => '',
        };
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
