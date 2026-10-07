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
    public static function syncMappings(string $connectionId, string $itemtype, ?array $asset = null): array
    {
        $itemtype = self::validItemtype($itemtype);
        $mappings = [];

        foreach (self::load($connectionId, $itemtype) as $mapping) {
            if (!isset(self::sourceOptions()[$mapping['source_of_truth']])) {
                throw new \RuntimeException('Invalid saved mapping authority: ' . $mapping['glpi_a_field_key']);
            }
            $glpiAField = self::safeLocalSyncField($itemtype, $mapping['glpi_a_field_key']);
            $glpiBField = self::safeRemoteSyncField($itemtype, $mapping);

            if ($glpiAField === '' || $glpiBField === '') {
                throw new \RuntimeException('The mapping is incomplete or unavailable: ' . $mapping['glpi_a_field_key']);
            }

            if (($mapping['_legacy'] ?? false) && (
                (!FieldsText::isCustom($glpiAField) && !in_array($glpiAField, ['name', 'serial', 'otherserial', 'comment'], true))
                || (!FieldsText::isCustom($glpiBField) && !in_array($glpiBField, ['name', 'serial', 'otherserial', 'comment'], true))
            )) {
                throw new \RuntimeException('Legacy mapping requires explicit save and reselection before use: ' . $mapping['glpi_a_field_key']);
            }

            $mappings[] = [
                'glpi_a_field'    => $glpiAField,
                'glpi_b_field'    => $glpiBField,
                'source_of_truth' => $mapping['source_of_truth'],
            ];
        }

        self::validateOwnership($connectionId, $itemtype, $mappings, $asset);
        self::expectedCustomTypes($itemtype, $mappings);
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
            if (!FieldsText::isCustom($glpiAField)) {
                $localTypes[$glpiAField] ??= NativeField::local($itemtype, $glpiAField)['type'];
            }
            $localType = $localTypes[$glpiAField] ?? '';
            $remoteType = FieldsText::isCustom($glpiBField)
                ? (FieldsText::isDropdownKey($glpiBField) ? 'dropdown' : '')
                : (NativeField::definitions($itemtype)[$glpiBField]['type'] ?? '');
            if ($remoteType !== '' && !NativeField::compatible($localType, $remoteType)) {
                throw new \RuntimeException('The mapping requires compatible endpoint types: ' . $glpiAField . ' (' . $localType . ') -> ' . $glpiBField . ' (' . $remoteType . ').');
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

        return self::nativeFieldsFor($itemtype);
    }

    /**
     * @return list<array{glpi_a_field_key:string,glpi_b_field_key:string,glpi_b_field_id:string,glpi_b_field_uid:string,glpi_b_field_label:string,source_of_truth:string,_legacy:bool}>
     */
    public static function load(string $connectionId, string $itemtype): array
    {
        $connectionId = trim($connectionId);
        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $savedMappings = [];

        if (array_key_exists($connectionId, $allMappings) && !is_array($allMappings[$connectionId])) {
            throw new \RuntimeException('Malformed saved connection mappings.');
        }
        if (isset($allMappings[$connectionId])) {
            $connectionMappings = $allMappings[$connectionId];

            if (array_key_exists($itemtype, $connectionMappings)) {
                if (!is_array($connectionMappings[$itemtype])) {
                    throw new \RuntimeException('Malformed saved asset mappings.');
                }
                $savedMappings = $connectionMappings[$itemtype];
            }
        }

        return self::rowsFromScope($savedMappings);
    }

    /**
     * @param array<array-key,mixed> $fieldMappings Indexed rows (legacy keyed callers are accepted).
     * @param list<array<string,mixed>> $glpiBFields Current independent B discovery, not POST data.
     */
    public static function save(string $connectionId, string $itemtype, array $fieldMappings, array $glpiBFields = []): void
    {
        self::invalidateRunCache();
        $connectionId = trim($connectionId);
        if ($connectionId === '') {
            throw new \RuntimeException('Select a connection before saving mappings.');
        }

        $itemtype = self::validItemtype($itemtype);
        $allMappings = self::loadAll();
        $rowInput = array_is_list($fieldMappings);
        foreach ($fieldMappings as $value) {
            if (is_array($value) && array_key_exists('glpi_a_field_key', $value)) {
                $rowInput = true;
            }
        }
        $rows = self::rowsFromScope($rowInput ? ['version' => 2, 'rows' => $fieldMappings] : $fieldMappings);
        $savedRows = [];
        $runtimeRows = [];
        $localTypes = [];
        foreach ($rows as $index => $row) {
            try {
                if (!isset(self::sourceOptions()[$row['source_of_truth']])) {
                    throw new \RuntimeException('Invalid source of truth.');
                }
                $a = self::safeLocalSyncField($itemtype, $row['glpi_a_field_key']);
                $remote = self::selectedField($glpiBFields, $row['glpi_b_field_key']);
                if ($remote === null || !($remote['supported'] ?? false)) {
                    throw new \RuntimeException('GLPI B field unavailable or unsupported: ' . $row['glpi_b_field_key'] . '. ' . ($remote['reason'] ?? 'Reload current field metadata.'));
                }
                $b = (string) $remote['key'];
                $localTypes[$a] ??= FieldsText::isCustom($a)
                    ? (FieldsText::customTypes($itemtype, [$a])[$a] ?? '')
                    : NativeField::local($itemtype, $a)['type'];
                $localType = $localTypes[$a];
                if ($localType === '' || !NativeField::compatible($localType, (string) ($remote['type'] ?? ''))) {
                    throw new \RuntimeException('The selected fields have incompatible types.');
                }
                $savedRows[] = [
                    'glpi_a_field_key' => $a,
                    'glpi_b_field_key' => $b,
                    'source_of_truth' => $row['source_of_truth'],
                    'glpi_b_field_label' => (string) ($remote['label'] ?? $b),
                ];
                $runtimeRows[] = ['glpi_a_field' => $a, 'glpi_b_field' => $b, 'source_of_truth' => $row['source_of_truth']];
            } catch (\RuntimeException $error) {
                throw new \RuntimeException('Mapping row ' . ($index + 1) . ': ' . $error->getMessage(), 0, $error);
            }
        }
        self::validateOwnership($connectionId, $itemtype, $runtimeRows);
        $allMappings[$connectionId][$itemtype] = ['version' => 2, 'rows' => $savedRows];

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

        try {
            $decoded = json_decode($savedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Invalid saved field mapping configuration: invalid JSON.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid saved field mapping configuration.');
        }
        if (self::$runCache !== null) {
            self::$runCache['mappings'] = $decoded;
        }
        return $decoded;
    }

    /** Return rows without repairing or discarding saved data. Never writes configuration. */
    private static function rowsFromScope(array $scope): array
    {
        $legacy = !array_key_exists('version', $scope);
        if (!$legacy && ($scope['version'] !== 2 || !isset($scope['rows']) || !is_array($scope['rows'])
            || array_diff(array_keys($scope), ['version', 'rows']) !== [])) {
            throw new \RuntimeException('Unsupported or malformed saved mapping version.');
        }
        $rows = [];
        foreach ($legacy ? $scope : $scope['rows'] as $key => $value) {
            $record = is_array($value) ? $value : ['source_of_truth' => $value];
            $row = [];
            foreach (['glpi_a_field_key', 'glpi_b_field_key', 'glpi_b_field_id', 'glpi_b_field_uid', 'glpi_b_field_label', 'source_of_truth'] as $field) {
                $raw = $record[$field] ?? ($field === 'glpi_a_field_key' && $legacy ? $key : '');
                $row[$field] = is_scalar($raw) ? trim((string) $raw) : '[invalid value]';
            }
            $row['_legacy'] = $legacy;
            $rows[] = $row;
        }
        return $rows;
    }

    /** Resolve discovery aliases, never labels, and reject ambiguous search metadata. */
    public static function selectedField(array $fields, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $matches = [];
        foreach ($fields as $field) {
            $aliases = [$field['key'], (string) ($field['id'] ?? ''), (string) ($field['uid'] ?? '')];
            if (in_array($key, $aliases, true)) {
                $matches[$field['key']] = $field;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException('Ambiguous field identity: ' . $key);
        }
        return $matches === [] ? null : reset($matches);
    }

    /** Local and remote discovery share presentation, but resolve custom identities independently. */
    public static function discoverFields(string $itemtype, array $options, bool $remote = false): array
    {
        $fields = [];
        foreach ($options as $id => $option) {
            if (!is_array($option) || !isset($option['name']) || !is_scalar($option['name'])
                || self::isHeaderOnlySearchOption($option)) {
                continue;
            }
            $custom = str_starts_with((string) ($option['table'] ?? ''), 'glpi_plugin_fields_');
            $uid = (string) ($option['uid'] ?? '');
            $field = [
                'key' => $uid !== '' ? $uid : (string) $id,
                'id' => (string) ($option['id'] ?? $id),
                'uid' => $uid,
                'label' => trim(strip_tags((string) $option['name'])),
                'group' => $custom ? 'Custom' : 'Native',
                'supported' => false,
                'type' => '',
                'reason' => '',
            ];
            try {
                if ($custom) {
                    $key = $remote ? FieldsText::remoteKey($itemtype, $option) : FieldsText::key($itemtype, $option);
                    if ($key === '') {
                        throw new \RuntimeException('Custom field identity is unavailable.');
                    }
                    $field['key'] = $key;
                    $field['uid'] = $key;
                    $field['type'] = FieldsText::validate($option);
                } else {
                    $descriptor = NativeField::fromOption($itemtype, $option);
                    $field['key'] = $descriptor['field'];
                    $field['uid'] = $descriptor['uid'];
                    $field['type'] = $descriptor['type'];
                }
                $field['supported'] = true;
            } catch (\RuntimeException $error) {
                $field['reason'] = $error->getMessage();
            }
            // Repeated keys cannot safely pick a search-option winner.
            if (isset($fields[$field['key']])) {
                $field['supported'] = false;
                $field['reason'] = 'Ambiguous duplicate metadata for this field.';
            }
            $fields[$field['key']] = $field;
        }
        return array_values($fields);
    }

    /**
     * Exclusive destinations and exclusive inbound A endpoints rule out chains and cycles.
     * A-authority fanout is the only allowed repeated endpoint within a mapping scope.
     * Runtime additionally checks connections whose routes resolve for this asset.
     * Save has no asset and checks only its scope, without remote requests or route-overlap guesses.
     */
    public static function validateOwnership(string $connectionId, string $itemtype, array $mappings, ?array $asset = null): void
    {
        $aOwners = [];
        $bOwners = [];
        foreach ($mappings as $row) {
            $a = $row['glpi_a_field'];
            $b = $row['glpi_b_field'];
            if (isset($bOwners[$b])) {
                throw new \RuntimeException('Duplicate or shared GLPI B destination: ' . $b);
            }
            $bOwners[$b] = true;
            $aOwners[$a][] = $row['source_of_truth'];
        }
        foreach ($aOwners as $a => $authorities) {
            if (count($authorities) > 1 && array_filter($authorities, static fn ($source) => $source !== 'glpi_a')) {
                throw new \RuntimeException('GLPI B/Both requires exclusive GLPI A ownership: ' . $a);
            }
        }
        if ($asset === null) {
            return;
        }

        $routeMatches = EntitySyncRoute::matchAsset($itemtype, (string) ($asset['entities_id'] ?? ''));
        $matchingConnections = array_fill_keys(array_column($routeMatches['matches'], 'glpi_b_connection_id'), true);
        if (!isset($matchingConnections[$connectionId])) {
            return;
        }

        $connections = GlpiBConnection::loadAll();
        $current = null;
        foreach ($connections as $connection) {
            if ($connection['id'] === $connectionId) {
                $current = $connection;
                break;
            }
        }
        if ($current === null || !$current['active']) {
            return;
        }
        $endpoint = self::canonicalApiEndpoint($current['base_url']);
        foreach ($connections as $connection) {
            if ($connection['id'] === $connectionId || !$connection['active']
                || !isset($matchingConnections[$connection['id']])) {
                continue;
            }
            if ($endpoint !== '' && $endpoint === self::canonicalApiEndpoint($connection['base_url'])) {
                throw new \RuntimeException('Connections matching this asset refer to the same GLPI B API endpoint: ' . $connectionId . ' and ' . $connection['id'] . '. Keep overlapping mappings in one connection.');
            }
            if ($aOwners === []) {
                continue;
            }
            $otherRows = self::load($connection['id'], $itemtype);
            foreach ($otherRows as $row) {
                $a = self::safeLocalSyncField($itemtype, $row['glpi_a_field_key']);
                if (isset($aOwners[$a]) && ($row['source_of_truth'] !== 'glpi_a'
                    || array_filter($aOwners[$a], static fn ($source) => $source !== 'glpi_a'))) {
                    throw new \RuntimeException('GLPI A endpoint has inbound ownership across active connections matching this asset: ' . $a . ' (' . $connection['id'] . ').');
                }
            }
        }
    }

    public static function canonicalApiEndpoint(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = rtrim($parts['path'] ?? '', '/');
        if (str_ends_with($path, '/apirest.php')) {
            $path = substr($path, 0, -strlen('/apirest.php'));
        }
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port . $path;
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

        foreach (FieldsText::localOptions($itemtype) as $id => $option) {
            $options[$id] = $option;
        }
        return self::discoverFields($itemtype, $options);
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
    private static function safeLocalSyncField(string $itemtype, string $fieldKey): string
    {
        if ($fieldKey === '') {
            throw new \RuntimeException('The GLPI A field is missing.');
        }
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

        return NativeField::local($itemtype, $fieldKey)['field'];
    }

    /**
     * @param array{glpi_b_field_key:string,glpi_b_field_id:string,glpi_b_field_uid:string,glpi_b_field_label:string} $mapping
     */
    private static function safeRemoteSyncField(string $itemtype, array $mapping): string
    {
        $matches = [];
        foreach (['glpi_b_field_key', 'glpi_b_field_uid'] as $fieldName) {
            $key = (string) ($mapping[$fieldName] ?? '');
            if (FieldsText::isCustom($key)) {
                $matches[$key] = true;
            }
        }

        foreach (['glpi_b_field_key', 'glpi_b_field_uid'] as $fieldName) {
            $key = (string) ($mapping[$fieldName] ?? '');
            foreach (NativeField::definitions($itemtype) as $field => $descriptor) {
                $aliases = [$field, $itemtype . '.' . $field];
                if ($descriptor['relation'] !== '') {
                    $aliases[] = $itemtype . '.' . $descriptor['relation'] . '.name';
                    $aliases[] = $itemtype . '.' . $descriptor['relation'] . '.completename';
                }
                if (in_array($key, $aliases, true)) {
                    $matches[$field] = true;
                }
            }
        }
        if (count($matches) === 1) {
            return (string) array_key_first($matches);
        }
        $key = (string) ($mapping['glpi_b_field_key'] ?? '');
        throw new \RuntimeException('Unsupported or ambiguous remote native mapping; reselect the field: ' . $key);
    }

}
