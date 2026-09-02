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
     * @return array<string,string>
     */
    public static function load(string $connectionId, string $itemtype): array
    {
        $connectionId = trim($connectionId);
        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $savedMappings = $allMappings[$connectionId][$itemtype] ?? [];

        return is_array($savedMappings) ? self::cleanMappings($itemtype, $savedMappings) : [];
    }

    /**
     * @param array<string,mixed> $fieldMappings
     */
    public static function save(string $connectionId, string $itemtype, array $fieldMappings): void
    {
        $connectionId = trim($connectionId);
        if ($connectionId === '') {
            return;
        }

        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $allMappings[$connectionId][$itemtype] = self::cleanMappings($itemtype, $fieldMappings);

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
     * @return array<string,array<string,array<string,string>>>
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
     * @return array<string,string>
     */
    private static function cleanMappings(string $itemtype, array $fieldMappings): array
    {
        $validSources = array_keys(self::sourceOptions());
        $validFields = [];

        foreach (self::fieldsFor($itemtype) as $field) {
            $validFields[$field['key']] = true;
        }

        $cleanMappings = [];

        foreach ($fieldMappings as $fieldKey => $source) {
            $fieldKey = (string) $fieldKey;
            $source = (string) $source;

            if (!isset($validFields[$fieldKey]) || !in_array($source, $validSources, true)) {
                continue;
            }

            $cleanMappings[$fieldKey] = $source;
        }

        return $cleanMappings;
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

            $label = trim(strip_tags((string) $option['name']));
            if ($label === '') {
                continue;
            }

            $fields[] = [
                'key'   => (string) $optionId,
                'label' => $label,
            ];
        }

        return $fields;
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
