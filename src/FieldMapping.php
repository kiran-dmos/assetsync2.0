<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class FieldMapping
{
    private const CONTEXT = 'plugin:assetsync20';
    private const FIELD_MAPPINGS_KEY = 'field_mappings';

    /**
     * @return array<string,string>
     */
    public static function assetTypes(): array
    {
        return [
            'Computer'         => 'Computers',
            'Monitor'          => 'Monitors',
            'Peripheral'       => 'Peripherals',
            'Printer'          => 'Printers',
            'Phone'            => 'Phones',
            'NetworkEquipment' => 'Network Equipment',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function sourceOptions(): array
    {
        return [
            'glpi_a' => 'GLPI A',
            'glpi_b' => 'GLPI B',
            'both'   => 'Both',
        ];
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    public static function fieldsFor(string $itemtype): array
    {
        if (!array_key_exists($itemtype, self::assetTypes())) {
            $itemtype = 'Computer';
        }

        $nativeFields = self::nativeFieldsFor($itemtype);
        if ($nativeFields !== []) {
            return $nativeFields;
        }

        return self::fallbackFieldsFor($itemtype);
    }

    /**
     * @return array<string,array{glpi_a_field_key:string,glpi_b_field_key:string,glpi_b_field_id:string,glpi_b_field_uid:string,glpi_b_field_label:string,source_of_truth:string}>
     */
    public static function load(string $connectionId, string $itemtype): array
    {
        $connectionId = trim($connectionId);
        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $savedMappings = [];

        if (isset($allMappings[$connectionId]) && is_array($allMappings[$connectionId])) {
            $connectionMappings = $allMappings[$connectionId];

            if (isset($connectionMappings[$itemtype]) && is_array($connectionMappings[$itemtype])) {
                $savedMappings = $connectionMappings[$itemtype];
            }
        }

        return self::cleanMappings($itemtype, $savedMappings, [], true);
    }

    /**
     * @param array<string,mixed> $fieldMappings
     * @param list<array{key:string,id:string,uid:string,label:string}> $glpiBFields
     */
    public static function save(string $connectionId, string $itemtype, array $fieldMappings, array $glpiBFields = []): void
    {
        $connectionId = trim($connectionId);
        if ($connectionId === '') {
            return;
        }

        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $allMappings[$connectionId][$itemtype] = self::cleanMappings($itemtype, $fieldMappings, $glpiBFields, false);

        if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
            \Config::setConfigurationValues(self::CONTEXT, [
                self::FIELD_MAPPINGS_KEY => json_encode($allMappings, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public static function install(): void
    {
        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'getConfigurationValues')
            || !method_exists('\Config', 'setConfigurationValues')
        ) {
            return;
        }

        $savedValues = \Config::getConfigurationValues(self::CONTEXT, [self::FIELD_MAPPINGS_KEY]);
        if (!array_key_exists(self::FIELD_MAPPINGS_KEY, $savedValues)) {
            \Config::setConfigurationValues(self::CONTEXT, [
                self::FIELD_MAPPINGS_KEY => json_encode([], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::CONTEXT, [self::FIELD_MAPPINGS_KEY]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadAll(): array
    {
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            return [];
        }

        $savedValues = \Config::getConfigurationValues(self::CONTEXT, [self::FIELD_MAPPINGS_KEY]);
        $savedJson = (string) ($savedValues[self::FIELD_MAPPINGS_KEY] ?? '');
        if ($savedJson === '') {
            return [];
        }

        $decoded = json_decode($savedJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $fieldMappings
     * @param list<array{key:string,id:string,uid:string,label:string}> $glpiBFields
     * @return array<string,array{glpi_a_field_key:string,glpi_b_field_key:string,glpi_b_field_id:string,glpi_b_field_uid:string,glpi_b_field_label:string,source_of_truth:string}>
     */
    private static function cleanMappings(
        string $itemtype,
        array $fieldMappings,
        array $glpiBFields,
        bool $keepRowsWithoutGlpiB
    ): array {
        $validSources = array_keys(self::sourceOptions());
        $validGlpiAFields = [];
        $glpiBFieldsByKey = self::glpiBFieldsByKey($glpiBFields);

        foreach (self::fieldsFor($itemtype) as $field) {
            $validGlpiAFields[$field['key']] = true;
        }

        $cleanMappings = [];

        foreach ($fieldMappings as $fieldKey => $mapping) {
            $fieldKey = (string) $fieldKey;

            if (!isset($validGlpiAFields[$fieldKey])) {
                continue;
            }

            $record = self::mappingRecord($fieldKey, $mapping, $validSources);
            if ($record === null) {
                continue;
            }

            $glpiBFieldKey = $record['glpi_b_field_key'];

            if ($glpiBFieldKey === '' && !$keepRowsWithoutGlpiB) {
                continue;
            }

            if ($glpiBFieldKey !== '' && isset($glpiBFieldsByKey[$glpiBFieldKey])) {
                $glpiBField = $glpiBFieldsByKey[$glpiBFieldKey];
                $record['glpi_b_field_id'] = $glpiBField['id'];
                $record['glpi_b_field_uid'] = $glpiBField['uid'];
                $record['glpi_b_field_label'] = $glpiBField['label'];
            } elseif ($glpiBFieldKey !== '' && $glpiBFieldsByKey !== []) {
                continue;
            }

            $cleanMappings[$fieldKey] = $record;
        }

        return $cleanMappings;
    }

    /**
     * @param mixed $mapping
     * @param list<string> $validSources
     * @return array{glpi_a_field_key:string,glpi_b_field_key:string,glpi_b_field_id:string,glpi_b_field_uid:string,glpi_b_field_label:string,source_of_truth:string}|null
     */
    private static function mappingRecord(string $fieldKey, $mapping, array $validSources): ?array
    {
        if (is_array($mapping)) {
            $source = (string) ($mapping['source_of_truth'] ?? '');
            $glpiBFieldKey = (string) ($mapping['glpi_b_field_key'] ?? '');
            $glpiBFieldId = (string) ($mapping['glpi_b_field_id'] ?? '');
            $glpiBFieldUid = (string) ($mapping['glpi_b_field_uid'] ?? '');
            $glpiBFieldLabel = (string) ($mapping['glpi_b_field_label'] ?? '');
        } else {
            $source = (string) $mapping;
            $glpiBFieldKey = '';
            $glpiBFieldId = '';
            $glpiBFieldUid = '';
            $glpiBFieldLabel = '';
        }

        $source = trim($source);
        if (!in_array($source, $validSources, true)) {
            return null;
        }

        return [
            'glpi_a_field_key'   => $fieldKey,
            'glpi_b_field_key'   => trim($glpiBFieldKey),
            'glpi_b_field_id'    => trim($glpiBFieldId),
            'glpi_b_field_uid'   => trim($glpiBFieldUid),
            'glpi_b_field_label' => trim(strip_tags($glpiBFieldLabel)),
            'source_of_truth'    => $source,
        ];
    }

    /**
     * @param list<array{key:string,id:string,uid:string,label:string}> $glpiBFields
     * @return array<string,array{key:string,id:string,uid:string,label:string}>
     */
    private static function glpiBFieldsByKey(array $glpiBFields): array
    {
        $fieldsByKey = [];

        foreach ($glpiBFields as $field) {
            $key = trim((string) ($field['key'] ?? ''));
            $label = trim(strip_tags((string) ($field['label'] ?? '')));

            if ($key === '' || $label === '') {
                continue;
            }

            $fieldsByKey[$key] = [
                'key'   => $key,
                'id'    => trim((string) ($field['id'] ?? '')),
                'uid'   => trim((string) ($field['uid'] ?? '')),
                'label' => $label,
            ];
        }

        return $fieldsByKey;
    }

    private static function validItemtype(string $itemtype): string
    {
        return array_key_exists($itemtype, self::assetTypes()) ? $itemtype : 'Computer';
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    private static function nativeFieldsFor(string $itemtype): array
    {
        if (!class_exists('\Search') || !method_exists('\Search', 'getOptions')) {
            return [];
        }

        try {
            $options = \Search::getOptions($itemtype);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($options)) {
            return [];
        }

        $fields = [];

        foreach ($options as $optionId => $option) {
            if (!is_array($option) || !isset($option['name']) || !is_scalar($option['name'])) {
                continue;
            }

            $fieldKey = (string) $optionId;
            $hasFieldKey = is_int($optionId) || ctype_digit($fieldKey);
            $label = trim(strip_tags((string) $option['name']));
            if ($label === '' || (!$hasFieldKey && self::isHeaderOnlySearchOption($option))) {
                continue;
            }

            $fields[] = [
                'key'   => $fieldKey,
                'label' => $label,
            ];
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $option
     */
    private static function isHeaderOnlySearchOption(array $option): bool
    {
        foreach (['field', 'table', 'datatype', 'uid'] as $fieldName) {
            if (isset($option[$fieldName]) && is_scalar($option[$fieldName]) && trim((string) $option[$fieldName]) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    private static function fallbackFieldsFor(string $itemtype): array
    {
        $fields = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'serial', 'label' => 'Serial number'],
            ['key' => 'otherserial', 'label' => 'Inventory number'],
            ['key' => 'locations_id', 'label' => 'Location'],
            ['key' => 'states_id', 'label' => 'Status'],
            ['key' => 'manufacturers_id', 'label' => 'Manufacturer'],
            ['key' => 'comment', 'label' => 'Comments'],
        ];

        if ($itemtype === 'Computer') {
            $fields[] = ['key' => 'computermodels_id', 'label' => 'Model'];
        }

        return $fields;
    }
}
