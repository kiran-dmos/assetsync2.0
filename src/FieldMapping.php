<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class FieldMapping
{
    private const CONTEXT = 'plugin:assetsync20';
    private const FIELD_MAPPINGS_KEY = 'field_mappings';
    private static ?array $runCache = null;

    public static function beginRunCache(): void
    {
        self::$runCache = [];
    }

    public static function endRunCache(): void
    {
        self::$runCache = null;
    }

    private static function invalidateRunCache(): void
    {
        if (self::$runCache !== null) {
            self::$runCache = [];
        }
    }

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
     * @return list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}>
     */
    public static function syncMappings(string $connectionId, string $itemtype): array
    {
        $itemtype = self::validItemtype($itemtype);
        $mappings = [];

        foreach (self::load($connectionId, $itemtype) as $mapping) {
            $glpiAField = self::safeLocalSyncField($itemtype, $mapping['glpi_a_field_key']);
            $glpiBField = self::safeRemoteSyncField($mapping);

            if ($glpiAField === '' || $glpiBField === '') {
                if (FieldsText::isCustom($glpiBField)) {
                    throw new \RuntimeException('The local field for the custom mapping could not be resolved: ' . $mapping['glpi_a_field_key']);
                }
                continue;
            }

            self::validateCustomMapping($itemtype, $glpiAField, $glpiBField);

            $mappings[] = [
                'glpi_a_field'    => $glpiAField,
                'glpi_b_field'    => $glpiBField,
                'source_of_truth' => $mapping['source_of_truth'],
            ];
        }

        return $mappings;
    }

    /**
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     * @return array<string,string>
     */
    public static function expectedCustomTypes(string $itemtype, array $mappings): array
    {
        $itemtype = self::validItemtype($itemtype);
        $localTypes = FieldsText::customTypes($itemtype, array_column($mappings, 'glpi_a_field'));
        $expectedTypes = [];

        foreach ($mappings as $mapping) {
            $glpiAField = $mapping['glpi_a_field'];
            $glpiBField = $mapping['glpi_b_field'];
            $localType = FieldsText::isCustom($glpiAField) ? ($localTypes[$glpiAField] ?? '') : 'text';

            if (FieldsText::isCustom($glpiAField) && !FieldsText::isCustom($glpiBField)
                && !in_array($localType, ['text', 'textarea'], true)) {
                throw new \RuntimeException('The mapped local Fields-plugin field requires a compatible remote Fields-plugin ' . ($localType !== '' ? $localType : 'scalar') . ' field: ' . $glpiAField);
            }

            if ($localType === 'dropdown' && (!FieldsText::isCustom($glpiBField) || !FieldsText::isDropdownKey($glpiBField))) {
                throw new \RuntimeException('The mapped local Fields-plugin dropdown field requires a compatible remote Fields-plugin dropdown field: ' . $glpiAField);
            }

            if (FieldsText::isCustom($glpiBField) && FieldsText::isDropdownKey($glpiBField) && $localType !== 'dropdown') {
                throw new \RuntimeException('The mapped remote Fields-plugin dropdown field requires a compatible local Fields-plugin dropdown field: ' . $glpiBField);
            }

            if (!FieldsText::isCustom($glpiBField)) {
                continue;
            }

            $expectedType = $localType;
            if ($expectedType === '') {
                throw new \RuntimeException('The mapped local Fields-plugin field type could not be resolved: ' . $glpiAField);
            }

            if (isset($expectedTypes[$glpiBField]) && $expectedTypes[$glpiBField] !== $expectedType) {
                throw new \RuntimeException('The mapped remote Fields-plugin field has conflicting expected types: ' . $glpiBField);
            }

            $expectedTypes[$glpiBField] = $expectedType;
        }

        return $expectedTypes;
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
        self::invalidateRunCache();
        $connectionId = trim($connectionId);
        if ($connectionId === '') {
            return;
        }

        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $allMappings[$connectionId][$itemtype] = self::cleanMappings($itemtype, $fieldMappings, $glpiBFields, false);

        try {
            if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
                \Config::setConfigurationValues(self::CONTEXT, [
                    self::FIELD_MAPPINGS_KEY => json_encode($allMappings, JSON_THROW_ON_ERROR),
                ]);
            }
        } finally {
            self::invalidateRunCache();
        }
    }

    public static function install(): void
    {
        self::invalidateRunCache();
        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'getConfigurationValues')
            || !method_exists('\Config', 'setConfigurationValues')
        ) {
            return;
        }

        try {
            $savedValues = \Config::getConfigurationValues(self::CONTEXT, [self::FIELD_MAPPINGS_KEY]);
            if (!array_key_exists(self::FIELD_MAPPINGS_KEY, $savedValues)) {
                \Config::setConfigurationValues(self::CONTEXT, [
                    self::FIELD_MAPPINGS_KEY => json_encode([], JSON_THROW_ON_ERROR),
                ]);
            }
        } finally {
            self::invalidateRunCache();
        }
    }

    public static function uninstall(): void
    {
        self::invalidateRunCache();
        try {
            if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
                \Config::deleteConfigurationValues(self::CONTEXT, [self::FIELD_MAPPINGS_KEY]);
            }
        } finally {
            self::invalidateRunCache();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadAll(): array
    {
        if (isset(self::$runCache['mappings'])) {
            return self::$runCache['mappings'];
        }
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            return [];
        }

        $savedValues = \Config::getConfigurationValues(self::CONTEXT, [self::FIELD_MAPPINGS_KEY]);
        $savedJson = (string) ($savedValues[self::FIELD_MAPPINGS_KEY] ?? '');
        if ($savedJson === '') {
            return [];
        }

        $decoded = json_decode($savedJson, true);

        if (!is_array($decoded)) {
            return [];
        }
        if (self::$runCache !== null) {
            self::$runCache['mappings'] = $decoded;
        }
        return $decoded;
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
        $customOptions = FieldsText::localOptions($itemtype);

        foreach ($fieldMappings as $fieldKey => $mapping) {
            $fieldKey = (string) $fieldKey;

            foreach ($customOptions as $optionId => $option) {
                if ((string) $optionId === $fieldKey) {
                    $fieldKey = FieldsText::key($itemtype, $option);
                    break;
                }
            }

            $savedCustomMapping = $keepRowsWithoutGlpiB && (FieldsText::isCustom($fieldKey)
                || (is_array($mapping) && FieldsText::isCustom((string) ($mapping['glpi_b_field_uid'] ?? $mapping['glpi_b_field_key'] ?? ''))));
            if (!isset($validGlpiAFields[$fieldKey]) && !$savedCustomMapping) {
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
            if (str_starts_with((string) ($option['table'] ?? ''), 'glpi_plugin_fields_')) {
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

        foreach (FieldsText::localOptions($itemtype) as $option) {
            $key = FieldsText::key($itemtype, $option);
            if ($key !== '') {
                try {
                    FieldsText::validate($option);
                } catch (\RuntimeException) {
                    continue;
                }
                $fields[] = ['key' => $key, 'label' => (string) $option['name']];
            }
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

    private static function safeLocalSyncField(string $itemtype, string $fieldKey): string
    {
        foreach (FieldsText::localOptions($itemtype) as $optionId => $option) {
            $key = FieldsText::key($itemtype, $option);
            if ($fieldKey === (string) $optionId || $fieldKey === $key) {
                FieldsText::validate($option);
                return $key;
            }
        }
        if (FieldsText::isCustom($fieldKey)) {
            throw new \RuntimeException('The mapped local Fields-plugin field is unavailable: ' . $fieldKey);
        }

        $directField = self::safeSyncFieldName($fieldKey);
        if ($directField !== '') {
            return $directField;
        }

        if (!class_exists('\Search') || !method_exists('\Search', 'getOptions')) {
            return '';
        }

        try {
            $options = \Search::getOptions($itemtype);
        } catch (\Throwable) {
            return '';
        }

        if (!is_array($options)) {
            return '';
        }

        $itemTable = self::itemTable($itemtype);

        foreach ($options as $optionId => $option) {
            if (!is_array($option) || !self::searchOptionMatches($fieldKey, $optionId, $option)) {
                continue;
            }

            $optionTable = isset($option['table']) && is_scalar($option['table']) ? trim((string) $option['table']) : '';
            if ($optionTable !== '' && $itemTable !== '' && $optionTable !== $itemTable) {
                continue;
            }

            $field = isset($option['field']) && is_scalar($option['field']) ? trim((string) $option['field']) : '';
            $safeField = self::safeSyncFieldName($field);
            if ($safeField !== '') {
                return $safeField;
            }
        }

        return '';
    }

    private static function validateCustomMapping(string $itemtype, string $glpiAField, string $glpiBField): void
    {
        self::expectedCustomTypes($itemtype, [[
            'glpi_a_field' => $glpiAField,
            'glpi_b_field' => $glpiBField,
            'source_of_truth' => 'glpi_a',
        ]]);
    }

    /**
     * @param array{glpi_b_field_key:string,glpi_b_field_id:string,glpi_b_field_uid:string,glpi_b_field_label:string} $mapping
     */
    private static function safeRemoteSyncField(array $mapping): string
    {
        foreach (['glpi_b_field_key', 'glpi_b_field_uid'] as $fieldName) {
            $key = (string) ($mapping[$fieldName] ?? '');
            if (FieldsText::isCustom($key)) {
                return $key;
            }
        }

        foreach (['glpi_b_field_key', 'glpi_b_field_uid'] as $fieldName) {
            $safeField = self::safeSyncFieldName((string) ($mapping[$fieldName] ?? ''));
            if ($safeField !== '') {
                return $safeField;
            }
        }

        return self::safeSyncFieldFromLabel((string) ($mapping['glpi_b_field_label'] ?? ''));
    }

    private static function safeSyncFieldName(string $fieldKey): string
    {
        $fieldKey = trim($fieldKey);
        if ($fieldKey === '') {
            return '';
        }

        foreach (array_keys(self::safeSyncFields()) as $fieldName) {
            if ($fieldKey === $fieldName || str_ends_with($fieldKey, '.' . $fieldName)) {
                return $fieldName;
            }
        }

        return '';
    }

    private static function safeSyncFieldFromLabel(string $label): string
    {
        $label = strtolower(trim(strip_tags($label)));
        $labels = [
            'name'             => 'name',
            'serial number'    => 'serial',
            'serial'           => 'serial',
            'inventory number' => 'otherserial',
            'asset tag'        => 'otherserial',
            'comments'         => 'comment',
            'comment'          => 'comment',
        ];

        return $labels[$label] ?? '';
    }

    /**
     * @return array<string,true>
     */
    private static function safeSyncFields(): array
    {
        return [
            'name'        => true,
            'serial'      => true,
            'otherserial' => true,
            'comment'     => true,
        ];
    }

    /**
     * @param int|string $optionId
     * @param array<string,mixed> $option
     */
    private static function searchOptionMatches(string $fieldKey, $optionId, array $option): bool
    {
        $optionId = (string) $optionId;
        $id = isset($option['id']) && is_scalar($option['id']) ? trim((string) $option['id']) : $optionId;
        $uid = isset($option['uid']) && is_scalar($option['uid']) ? trim((string) $option['uid']) : '';

        return $fieldKey === $id || ($uid !== '' && $fieldKey === $uid);
    }

    private static function itemTable(string $itemtype): string
    {
        if (class_exists($itemtype) && method_exists($itemtype, 'getTable')) {
            return (string) $itemtype::getTable();
        }

        return '';
    }
}
