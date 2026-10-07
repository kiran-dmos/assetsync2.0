<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** The same native-field contract is checked against each installation's search metadata. */
final class NativeField
{
    public static function definitions(string $itemtype): array
    {
        if (!isset(FieldMapping::assetTypes()[$itemtype])) {
            throw new \RuntimeException('Unsupported asset type: ' . $itemtype);
        }
        $fields = array_fill_keys(['name', 'serial', 'otherserial', 'contact', 'contact_num', 'uuid'], 'text');
        $fields['comment'] = 'textarea';
        $specific = match ($itemtype) {
            'Computer' => ['last_boot' => 'datetime'],
            'Monitor' => array_fill_keys(['have_micro', 'have_speaker', 'have_subd', 'have_bnc', 'have_dvi', 'have_pivot', 'have_hdmi', 'have_displayport'], 'yesno'),
            'Printer' => array_fill_keys(['have_serial', 'have_parallel', 'have_usb', 'have_ethernet', 'have_wifi'], 'yesno')
                + ['memory_size' => 'int', 'init_pages_counter' => 'int', 'last_pages_counter' => 'int'],
            'Phone' => ['number_line' => 'int', 'have_headset' => 'yesno', 'have_hp' => 'yesno'],
            'NetworkEquipment' => ['ram' => 'int'],
            default => [],
        };
        $definitions = [];
        $table = 'glpi_' . strtolower($itemtype) . 's';
        foreach ($fields + $specific as $field => $type) {
            $definitions[$field] = ['field' => $field, 'type' => $type, 'table' => $table, 'relation' => '', 'itemtype' => $itemtype];
        }
        foreach ([$itemtype . 'Type', $itemtype . 'Model', 'State'] as $relation) {
            $field = strtolower($relation) . 's_id';
            $definitions[$field] = ['field' => $field, 'type' => 'dropdown', 'table' => 'glpi_' . strtolower($relation) . 's', 'relation' => $relation, 'itemtype' => $itemtype];
        }
        return $definitions;
    }

    public static function fromOption(string $itemtype, array $option): array
    {
        foreach (self::definitions($itemtype) as $field => $descriptor) {
            if (($option['table'] ?? '') !== $descriptor['table']) {
                continue;
            }
            $relation = $descriptor['relation'];
            if ($relation === '') {
                if (($option['field'] ?? '') !== $field || !empty($option['joinparams'])
                    || (isset($option['linkfield']) && !in_array($option['linkfield'], ['', $field], true))) {
                    continue;
                }
                $uid = $itemtype . '.' . $field;
            } else {
                if (!in_array($option['field'] ?? '', ['name', 'completename'], true) || !empty($option['joinparams'])) {
                    continue;
                }
                $uid = $itemtype . '.' . $relation . '.' . $option['field'];
                if (($option['linkfield'] ?? '') !== $field
                    && (isset($option['linkfield']) || ($option['uid'] ?? '') !== $uid)) {
                    continue;
                }
            }
            if (!empty($option['uid']) && $option['uid'] !== $uid) {
                continue;
            }
            if (!empty($option['is_readonly']) || !empty($option['readonly'])) {
                continue;
            }
            $datatype = $option['datatype'] ?? '';
            $allowed = match ($descriptor['type']) {
                'yesno' => ['bool', 'boolean'],
                'int' => ['int', 'integer', 'number'],
                'datetime' => ['datetime'],
                'dropdown' => ['', 'dropdown', 'string'],
                default => $field === 'name' ? ['', 'string', 'text', 'itemlink'] : ['', 'string', 'text', 'email', 'weblink'],
            };
            if (!in_array($datatype, $allowed, true)) {
                continue;
            }
            return $descriptor + ['uid' => $uid];
        }
        throw new \RuntimeException('Unsupported native field metadata: ' . ($option['uid'] ?? $option['name'] ?? $option['field'] ?? 'unknown'));
    }

    public static function resolve(string $itemtype, string $key, array $options): array
    {
        $matches = [];
        foreach ($options as $id => $option) {
            if (!is_array($option)) {
                continue;
            }
            $exact = $key === (string) $id || $key === (string) ($option['id'] ?? '') || $key === (string) ($option['uid'] ?? '');
            try {
                $descriptor = self::fromOption($itemtype, $option);
            } catch (\RuntimeException $error) {
                if ($exact) {
                    throw $error;
                }
                continue;
            }
            if ($exact || $key === $descriptor['field'] || $key === $descriptor['uid']) {
                $matches[$descriptor['field']] = $descriptor;
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException('Native field is unavailable or ambiguous: ' . $itemtype . '.' . $key);
        }
        return reset($matches);
    }

    public static function local(string $itemtype, string $key): array
    {
        if (!class_exists('Search') || !method_exists('Search', 'getOptions')) {
            throw new \RuntimeException('Local native search metadata is unavailable.');
        }
        return self::resolve($itemtype, $key, \Search::getOptions($itemtype));
    }

    public static function compatible(string $a, string $b): bool
    {
        return $a === $b || (in_array($a, ['text', 'textarea'], true) && in_array($b, ['text', 'textarea'], true));
    }

    public static function referenceId(mixed $value): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException('Invalid reference ID.');
        }
        return (int) $value;
    }

    public static function labelForId(array $rows, mixed $value): string
    {
        $id = self::referenceId($value);
        if ($id === 0) {
            return '';
        }
        foreach ($rows as $row) {
            if (self::referenceId($row['id'] ?? null) === $id && FieldsText::dropdownLabel($row) !== '') {
                return FieldsText::dropdownLabel($row);
            }
        }
        throw new \RuntimeException('Reference option is missing, inaccessible, or not permitted: ' . $id);
    }

    public static function idForLabel(array $rows, mixed $value): int
    {
        $label = FieldsText::normalizeValue('dropdown', $value);
        if ($label === '') {
            return 0;
        }
        $matches = [];
        foreach ($rows as $row) {
            if (FieldsText::dropdownLabel($row) === $label) {
                $id = self::referenceId($row['id'] ?? null);
                if ($id > 0) {
                    $matches[$id] = true;
                }
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException('Destination reference label is missing, duplicated, or not permitted: ' . $label);
        }
        return (int) array_key_first($matches);
    }

    public static function permitted(array $row, array $descriptor, array $entityPath, bool $requireVisibility = true): bool
    {
        if (!empty($row['is_deleted']) || !empty($row['is_template'])) {
            return false;
        }
        if ($descriptor['relation'] === 'State' && (!isset($row['entities_id'], $row['is_recursive'])
            || ($requireVisibility && !in_array($row['is_visible_' . strtolower($descriptor['itemtype'])] ?? null, [1, '1', true], true)))) {
            return false;
        }
        if (array_key_exists('entities_id', $row)) {
            if ($row['entities_id'] === null || !in_array($row['is_recursive'] ?? 0, [0, 1, '0', '1', false, true], true)) {
                return false;
            }
            try {
                $entityId = self::referenceId($row['entities_id']);
            } catch (\RuntimeException) {
                return false;
            }
            if ($entityId !== $entityPath[0] && (!in_array($row['is_recursive'] ?? 0, [1, '1', true], true) || !in_array($entityId, $entityPath, true))) {
                return false;
            }
        }
        return true;
    }

    public static function localCatalog(array $descriptor, int $entityId, array $sourceIds = [], array $labels = []): array
    {
        $class = $descriptor['relation'];
        if ($class === '' || !class_exists($class)) {
            throw new \RuntimeException('Local reference catalog is unavailable: ' . $class);
        }
        $dropdown = new $class();
        $candidates = $dropdown->find([]);
        $path = [$entityId];
        $rows = [];
        foreach ($candidates as $candidate) {
            $id = self::referenceId($candidate['id'] ?? null);
            if (!in_array($id, $sourceIds, true) && !in_array(FieldsText::dropdownLabel($candidate), $labels, true)) {
                continue;
            }
            if (!$dropdown->getFromDB($id)) {
                throw new \RuntimeException('Local reference disappeared during catalog read.');
            }
            if (method_exists($dropdown, 'can') && !$dropdown->can($id, defined('READ') ? READ : 1)) {
                continue;
            }
            if (isset($dropdown->fields['entities_id']) && count($path) === 1) {
                $path = self::localEntityPath($entityId);
            }
            if (self::permitted($dropdown->fields, $descriptor, $path)) {
                $rows[] = $dropdown->fields;
            }
        }
        return $rows;
    }

    public static function localEntityPath(int $entityId): array
    {
        $path = [$entityId];
        while ($entityId > 0) {
            $entity = new \Entity();
            if (!$entity->getFromDB($entityId) || !isset($entity->fields['entities_id'])) {
                throw new \RuntimeException('Local entity ancestry is unavailable.');
            }
            $entityId = self::referenceId($entity->fields['entities_id']);
            if (in_array($entityId, $path, true) || count($path) >= 100) {
                throw new \RuntimeException('Invalid local entity ancestry.');
            }
            $path[] = $entityId;
        }
        return $path;
    }
}
