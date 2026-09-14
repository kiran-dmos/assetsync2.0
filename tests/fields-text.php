<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\FieldsText;

class Config
{
    public static array $values = [];
    public static function getConfigurationValues($context, $keys): array { return self::$values; }
    public static function setConfigurationValues($context, $values): void { self::$values = $values; }
}
class Search
{
    public static function getOptions($itemtype): array { return [1 => ['name' => 'Name', 'field' => 'name', 'table' => 'glpi_computers']]; }
}
class Computer
{
    public static function getTable(): string { return 'glpi_computers'; }
}
function getItemTypeForTable($table): string { return 'PluginFieldsComputerdmosasset'; }
function getTableForItemType($class): string
{
    if ($class === 'PluginFieldsDepartmentfieldDropdown') {
        return 'glpi_plugin_fields_departmentfielddropdowns';
    }

    return 'glpi_plugin_fields_computerdmosassets';
}
class PluginFieldsContainer
{
    public static string $type = 'text';
    public static array $options = [];
    public static function getAddSearchOptions($itemtype): array
    {
        if (self::$options !== []) {
            return self::$options;
        }

        return [884776 => ['name' => 'DMOS Asset - Name', 'field' => 'namefield',
            'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => self::$type, 'pfields_fields_id' => 1]];
    }
}
class PluginFieldsField
{
    public static array $definitions = [
        1 => ['id' => 1, 'name' => 'namefield', 'type' => 'text', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        2 => ['id' => 2, 'name' => 'hwbillablefieldtwo', 'type' => 'yesno', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        3 => ['id' => 3, 'name' => 'swsdbillablefieldtwo', 'type' => 'yesno', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        4 => ['id' => 4, 'name' => 'notesfield', 'type' => 'textarea', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        5 => ['id' => 5, 'name' => 'countfield', 'type' => 'number', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        6 => ['id' => 6, 'name' => 'datefield', 'type' => 'date', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        7 => ['id' => 7, 'name' => 'datetimefield', 'type' => 'datetime', 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
        8 => ['id' => 8, 'name' => 'departmentfield', 'type' => 'dropdown', 'multiple' => 0, 'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0],
    ];
    public array $fields = [];
    public function getFromDB($id): bool
    {
        $this->fields = self::$definitions[(int) $id] ?? [];
        return $this->fields !== [];
    }
}
class PluginFieldsComputerdmosasset
{
    public static string $value = 'DMOS sync test 20260911';
    public static array $row = [];
    public static bool $found = true;
    public array $fields = [];
    public function getFromDBByCrit(array $criteria): bool
    {
        check($criteria === ['items_id' => 2, 'itemtype' => 'Computer'], 'Read must scope to Computer 2');
        if (!self::$found) {
            $this->fields = [];
            return false;
        }
        $this->fields = self::$row !== [] ? self::$row : ['id' => 10, 'namefield' => self::$value];
        return true;
    }
    public function update(array $input): bool
    {
        self::$found = true;
        self::$row = array_merge(self::$row, $input);
        $this->fields = self::$row;
        return true;
    }
    public function add(array $input): bool
    {
        self::$found = true;
        self::$row = ['id' => 10] + $input;
        $this->fields = self::$row;
        return true;
    }
}
class PluginFieldsDepartmentfieldDropdown
{
    public static array $rows = [
        10 => ['id' => 10, 'name' => 'Hardware', 'completename' => 'Operations > Hardware'],
        11 => ['id' => 11, 'name' => 'Software', 'completename' => 'Operations > Software'],
        12 => ['id' => 12, 'name' => 'Fallback Only', 'completename' => ''],
    ];
    public array $fields = [];

    public function getFromDB($id): bool
    {
        $this->fields = self::$rows[(int) $id] ?? [];

        return $this->fields !== [];
    }

    public function find(array $criteria = []): array
    {
        return self::$rows;
    }
}
function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function expectRuntime(callable $work, string $contains, string $message): void
{
    try {
        $work();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $contains), $message);
        return;
    }

    throw new RuntimeException($message);
}

function fieldOption(int $fieldId, string $label, string $column, string $type): array
{
    return [
        'name' => $label,
        'field' => $column,
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => $type,
        'pfields_fields_id' => $fieldId,
        'plugin_fields_containers_id' => 1,
    ];
}

function dropdownFieldOption(int $fieldId, string $label, string $fieldName, int $multiple = 0): array
{
    return [
        'name' => $label,
        'field' => 'completename',
        'table' => 'glpi_plugin_fields_' . $fieldName . 'dropdowns',
        'linkfield' => 'plugin_fields_' . $fieldName . 'dropdowns_id',
        'datatype' => 'dropdown',
        'pfields_type' => 'dropdown',
        'pfields_fields_id' => $fieldId,
        'is_multiple' => $multiple,
        'joinparams' => [
            'beforejoin' => [
                'table' => 'glpi_plugin_fields_computerdmosassets',
            ],
        ],
    ];
}

$key = 'Computer.PluginFieldsComputerdmosasset.namefield';
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => ['884776' => [
    'glpi_b_field_key' => $key, 'glpi_b_field_uid' => $key,
    'glpi_b_field_label' => 'Name', 'source_of_truth' => 'glpi_a',
]]]]);
$mappings = FieldMapping::syncMappings('test', 'Computer');
check($mappings === [['glpi_a_field' => $key, 'glpi_b_field' => $key, 'source_of_truth' => 'glpi_a']], 'Legacy local option id must resolve to stable custom identifiers, never native Name');
check(in_array($key, array_column(FieldMapping::fieldsFor('Computer'), 'key'), true), 'Discovery includes stable custom identifier');
$metadata = FieldsText::customMetadata('Computer', [$key])[$key];
check($metadata['key'] === $key, 'Generated metadata must keep the stable custom key');
check($metadata['class'] === 'PluginFieldsComputerdmosasset', 'Generated metadata must identify the generated class');
check($metadata['table'] === 'glpi_plugin_fields_computerdmosassets', 'Generated metadata must identify the generated table');
check($metadata['field'] === 'namefield', 'Generated metadata must identify the generated column');
check($metadata['type'] === 'text', 'Generated metadata must identify the canonical field type');
check($metadata['field_id'] === 1, 'Generated metadata must identify the Fields field id');
check($metadata['search_option_id'] === 884776, 'Generated metadata must identify the parent history search option id');
$historyRefs = FieldsText::customHistoryRefs('Computer', [$key]);
check($historyRefs === [$key => ['option_id' => '884776', 'itemtype_link' => 'PluginFieldsComputerdmosasset']], 'Custom history refs must include the option id and generated class');
$historyFilter = new ReflectionMethod(\GlpiPlugin\Assetsync20\GlpiBConnection::class, 'latestHistoryDatesByRef');
$historyFilter->setAccessible(true);
$historyDates = $historyFilter->invoke(null, [
    ['id' => 936, 'id_search_option' => 884776, 'itemtype_link' => '', 'date_mod' => '2026-09-14 08:15:30'],
    ['id' => 937, 'id_search_option' => 0, 'itemtype_link' => 'PluginFieldsComputerdmosasset', 'date_mod' => '2026-09-14 08:16:21'],
], $historyRefs);
check($historyDates === [$key => '2026-09-14 08:16:21'], 'Generated container history must outrank older field-option history');
$metadata = FieldsText::metadataWithDefinition($metadata, PluginFieldsField::$definitions[1]);
check($metadata['container_id'] === 1, 'Generated metadata must identify the Fields container id');
check($metadata['writable'] === true, 'Generated metadata must identify writable fields');
$asset = ['id' => 2, 'serial' => 'test', 'name' => 'native'] + FieldsText::localValues('Computer', 2, [$key]);
$route = ['id' => 'route', 'glpi_b_target_entity_id' => '0'];
$service = new AssetSyncService();
$before = $service->payloadHash($asset, $route, $mappings);
$asset[$key] = 'custom-only edit';
check($before !== $service->payloadHash($asset, $route, $mappings), 'Custom-only edit must change payload hash');
$compare = new ReflectionMethod(AssetSyncService::class, 'existingChanges');
$remote = ['entities_id' => 0, 'name' => 'native'];
$changes = $compare->invoke($service, $asset, $remote, $route, $mappings);
check($changes['remote'] === [$key => 'custom-only edit'], 'Missing custom row must receive A text');
$remote[$key] = $asset[$key];
check($compare->invoke($service, $asset, $remote, $route, $mappings)['remote'] === [], 'Equal custom values must not write');
$asset[$key] = '';
check($compare->invoke($service, $asset, $remote, $route, $mappings)['remote'] === [$key => ''], 'A source must clear remote text');
$asset[$key] = 'custom-only edit';
$create = new ReflectionMethod(AssetSyncService::class, 'createInput');
check(!array_key_exists($key, $create->invoke($service, $asset, $route, $mappings)), 'Custom column must not be sent to native create');
$mappings[0]['source_of_truth'] = 'glpi_b';
$remote[$key] = 'remote text';
check($compare->invoke($service, $asset, $remote, $route, $mappings)['local'] === [$key => 'remote text'], 'B source must update local custom value');
$mappings[0]['source_of_truth'] = 'both';
$asset['date_mod'] = '2026-09-14 06:05:43';
$remote['date_mod'] = '2026-09-14 06:05:49';
$asset[$key] = '';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => '2026-09-14 10:00:00'], [$key => '2026-09-13 10:00:00'])['remote'] === [$key => ''], 'A newer custom blank must clear B even when parent date_mod is stale');
$asset[$key] = 'local text';
$remote[$key] = '';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => '2026-09-13 10:00:00'], [$key => '2026-09-14 10:00:00'])['local'] === [$key => ''], 'B newer custom blank must clear A even when parent date_mod is stale');
$asset['date_mod'] = '2026-09-14 06:05:43';
$remote['date_mod'] = '2026-09-14 06:05:49';
$asset[$key] = 'local text';
$remote[$key] = 'remote text';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => '2026-09-14 10:00:00'], [$key => '2026-09-13 10:00:00'])['remote'] === [$key => 'local text'], 'A newer custom filled value must update B');
$asset['date_mod'] = '2026-09-14 06:05:43';
$remote['date_mod'] = '2026-09-14 06:05:49';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => '2026-09-13 10:00:00'], [$key => '2026-09-14 10:00:00'])['local'] === [$key => 'remote text'], 'B newer custom filled value must update A');
$asset['date_mod'] = '2026-09-14 10:30:00';
$remote['date_mod'] = '2026-09-14 06:05:49';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => '2026-09-14 08:00:00'], [$key => '2026-09-14 10:00:00'])['remote'] === [$key => 'local text'], 'A parent date_mod may raise a custom timestamp only when custom history exists');
$asset['date_mod'] = '';
$remote['date_mod'] = 'not-a-date';
$asset[$key] = 'same text';
$remote[$key] = 'same text';
$noChanges = $compare->invoke($service, $asset, $remote, $route, $mappings);
check($noChanges['remote'] === [] && $noChanges['local'] === [] && $noChanges['conflicts'] === [], 'Both equal values must not write or conflict');
$asset['date_mod'] = '2026-09-14 10:00:00';
$remote['date_mod'] = '2026-09-14 10:00:00';
$asset[$key] = 'local text';
$remote[$key] = 'remote text';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => '2026-09-14 10:00:00'], [$key => '2026-09-14 10:00:00'])['conflicts'] === [$key], 'Both different custom values with equal timestamps must conflict');
unset($asset['date_mod']);
$remote['date_mod'] = '2026-09-14 10:00:00';
check($compare->invoke($service, $asset, $remote, $route, $mappings)['conflicts'] === [$key], 'Both different custom values with missing history must conflict even when parent dates exist');
$asset['date_mod'] = 'not-a-date';
$remote['date_mod'] = '2026-09-14 10:00:00';
check($compare->invoke($service, $asset, $remote, $route, $mappings, [], [$key => 'not-a-date'], [$key => '2026-09-14 10:00:00'])['conflicts'] === [$key], 'Both different custom values with invalid history must conflict');

$notesKeyA = 'Computer.PluginFieldsComputerdmosasset.notesfield';
$notesKeyB = 'Computer.PluginFieldsComputerdmosasset.notesfieldb';
$countKeyA = 'Computer.PluginFieldsComputerdmosasset.countfield';
$countKeyB = 'Computer.PluginFieldsComputerdmosasset.countfieldb';
$dateKeyA = 'Computer.PluginFieldsComputerdmosasset.datefield';
$dateKeyB = 'Computer.PluginFieldsComputerdmosasset.datefieldb';
$dateTimeKeyA = 'Computer.PluginFieldsComputerdmosasset.datetimefield';
$dateTimeKeyB = 'Computer.PluginFieldsComputerdmosasset.datetimefieldb';
PluginFieldsContainer::$options = [
    884779 => fieldOption(4, 'DMOS Asset - Notes', 'notesfield', 'textarea'),
    884780 => fieldOption(5, 'DMOS Asset - Count', 'countfield', 'number'),
    884781 => fieldOption(6, 'DMOS Asset - Date', 'datefield', 'date'),
    884782 => fieldOption(7, 'DMOS Asset - Date Time', 'datetimefield', 'datetime'),
];
PluginFieldsComputerdmosasset::$found = true;
PluginFieldsComputerdmosasset::$row = [
    'id' => 10,
    'notesfield' => 'Long notes',
    'countfield' => '42',
    'datefield' => '2026-09-14',
    'datetimefield' => '2026-09-14 08:09:10',
];
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => [
    '884779' => ['glpi_b_field_key' => $notesKeyB, 'glpi_b_field_uid' => $notesKeyB, 'glpi_b_field_label' => 'Notes', 'source_of_truth' => 'glpi_a'],
    '884780' => ['glpi_b_field_key' => $countKeyB, 'glpi_b_field_uid' => $countKeyB, 'glpi_b_field_label' => 'Count', 'source_of_truth' => 'glpi_a'],
    '884781' => ['glpi_b_field_key' => $dateKeyB, 'glpi_b_field_uid' => $dateKeyB, 'glpi_b_field_label' => 'Date', 'source_of_truth' => 'glpi_a'],
    '884782' => ['glpi_b_field_key' => $dateTimeKeyB, 'glpi_b_field_uid' => $dateTimeKeyB, 'glpi_b_field_label' => 'Date Time', 'source_of_truth' => 'glpi_a'],
]]]);
$scalarMappings = FieldMapping::syncMappings('test', 'Computer');
$scalarTypes = FieldMapping::expectedCustomTypes('Computer', $scalarMappings);
check($scalarTypes === [
    $notesKeyB => 'textarea',
    $countKeyB => 'int',
    $dateKeyB => 'date',
    $dateTimeKeyB => 'datetime',
], 'Custom scalar mappings must keep canonical destination types');
expectRuntime(static fn (): array => FieldMapping::expectedCustomTypes('Computer', [
    ['glpi_a_field' => $notesKeyA, 'glpi_b_field' => $notesKeyB, 'source_of_truth' => 'glpi_a'],
    ['glpi_a_field' => $countKeyA, 'glpi_b_field' => $notesKeyB, 'source_of_truth' => 'glpi_a'],
]), 'conflicting expected types', 'Custom-to-custom mappings with conflicting canonical types must block');
$scalarAsset = ['id' => 2, 'serial' => 'test'] + FieldsText::localValues('Computer', 2, [$notesKeyA, $countKeyA, $dateKeyA, $dateTimeKeyA]);
check($scalarAsset[$notesKeyA] === 'Long notes', 'Textarea values must remain strings');
check($scalarAsset[$countKeyA] === 42, 'Number values must normalize to int');
check($scalarAsset[$dateKeyA] === '2026-09-14', 'Date values must accept YYYY-MM-DD');
check($scalarAsset[$dateTimeKeyA] === '2026-09-14 08:09:10', 'Datetime values must accept YYYY-MM-DD HH:MM:SS');
$scalarRemote = ['entities_id' => 0, $notesKeyB => '', $countKeyB => 0, $dateKeyB => '', $dateTimeKeyB => ''];
$scalarChanges = $compare->invoke($service, $scalarAsset, $scalarRemote, $route, $scalarMappings, $scalarTypes);
check($scalarChanges['remote'][$notesKeyB] === 'Long notes', 'Textarea custom value must write remotely');
check($scalarChanges['remote'][$countKeyB] === 42, 'Integer custom value must write remotely as int');
check($scalarChanges['remote'][$dateKeyB] === '2026-09-14', 'Date custom value must write remotely');
check($scalarChanges['remote'][$dateTimeKeyB] === '2026-09-14 08:09:10', 'Datetime custom value must write remotely');
expectRuntime(static fn (): array => FieldsText::customMetadata('Computer', ['Computer.PluginFieldsComputerdmosasset.missingfield']), 'unavailable', 'Missing generated metadata must block clearly');
expectRuntime(static fn (): int => FieldsText::normalizeValue('int', '42.5'), 'number value', 'Decimal number values must block clearly');
expectRuntime(static fn (): int => FieldsText::normalizeValue('int', 'forty-two'), 'number value', 'Invalid number strings must block clearly');
check(FieldsText::normalizeValue('date', '2026-09-14') === '2026-09-14', 'Valid dates must normalize');
expectRuntime(static fn (): string => FieldsText::normalizeValue('date', '2026-02-29'), 'date value', 'Invalid dates must block clearly');
check(FieldsText::normalizeValue('datetime', '2026-09-14 08:09:10') === '2026-09-14 08:09:10', 'Valid datetimes must normalize');
expectRuntime(static fn (): string => FieldsText::normalizeValue('datetime', '2026-09-14T08:09:10'), 'datetime value', 'Invalid datetimes must block clearly');
$notesMetadata = FieldsText::customMetadata('Computer', [$notesKeyA])[$notesKeyA];
expectRuntime(static fn (): array => FieldsText::metadataWithDefinition($notesMetadata, PluginFieldsField::$definitions[5]), 'definition type', 'Type mismatches must block clearly');
$inactiveDefinition = PluginFieldsField::$definitions[4];
$inactiveDefinition['is_active'] = 0;
expectRuntime(static fn (): array => FieldsText::metadataWithDefinition($notesMetadata, $inactiveDefinition), 'inactive', 'Inactive Fields metadata must block clearly');
PluginFieldsField::$definitions[4]['is_readonly'] = 1;
check(!FieldsText::updateLocal('Computer', 2, [$notesKeyA => 'Blocked']), 'Read-only destination custom fields must block');
PluginFieldsField::$definitions[4]['is_readonly'] = 0;

$yesKeyA = 'Computer.PluginFieldsComputerdmosasset.hwbillablefieldtwo';
$yesKeyB = 'Computer.PluginFieldsComputerdmosasset.hwbillablefield';
$noKeyA = 'Computer.PluginFieldsComputerdmosasset.swsdbillablefieldtwo';
$noKeyB = 'Computer.PluginFieldsComputerdmosasset.swsdbillablefield';
PluginFieldsContainer::$options = [
    884777 => ['name' => 'DMOS Asset - HW Billable', 'field' => 'hwbillablefieldtwo',
        'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => 'yesno', 'pfields_fields_id' => 2],
    884778 => ['name' => 'DMOS Asset - SW/SD Billable', 'field' => 'swsdbillablefieldtwo',
        'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => 'yesno', 'pfields_fields_id' => 3],
];
PluginFieldsComputerdmosasset::$row = ['id' => 10, 'hwbillablefieldtwo' => true, 'swsdbillablefieldtwo' => '0'];
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => [
    '884777' => [
        'glpi_b_field_key' => $yesKeyB, 'glpi_b_field_uid' => $yesKeyB,
        'glpi_b_field_label' => 'HW Billable', 'source_of_truth' => 'glpi_a',
    ],
    '884778' => [
        'glpi_b_field_key' => $noKeyB, 'glpi_b_field_uid' => $noKeyB,
        'glpi_b_field_label' => 'SW/SD Billable', 'source_of_truth' => 'glpi_a',
    ],
]]]);
$yesMappings = FieldMapping::syncMappings('test', 'Computer');
$fieldTypes = FieldMapping::expectedCustomTypes('Computer', $yesMappings);
check($fieldTypes === [$yesKeyB => 'yesno', $noKeyB => 'yesno'], 'Custom yesno mappings must expect remote yesno fields');
$yesAsset = ['id' => 2, 'serial' => 'test'] + FieldsText::localValues('Computer', 2, [$yesKeyA, $noKeyA]);
check($yesAsset[$yesKeyA] === 1, 'Local yesno true must normalize to 1');
check($yesAsset[$noKeyA] === 0, 'Local yesno 0 must normalize to 0');
PluginFieldsComputerdmosasset::$found = false;
$missingYesAsset = FieldsText::localValues('Computer', 2, [$yesKeyA, $noKeyA]);
check($missingYesAsset === [$yesKeyA => 0, $noKeyA => 0], 'Missing local yesno row must use the Fields-plugin default 0');
PluginFieldsComputerdmosasset::$found = true;
PluginFieldsComputerdmosasset::$row = ['id' => 10, 'hwbillablefieldtwo' => true, 'swsdbillablefieldtwo' => '0'];
$yesRemote = ['entities_id' => 0, $yesKeyB => 0, $noKeyB => 1];
$yesChanges = $compare->invoke($service, $yesAsset, $yesRemote, $route, $yesMappings, $fieldTypes);
check($yesChanges['remote'][$yesKeyB] === 1, 'A true yesno source must write remote 1');
check($yesChanges['remote'][$noKeyB] === 0, 'A false yesno source must write remote 0');
check(FieldsText::updateLocal('Computer', 2, [$noKeyA => false]), 'Local yesno false update should be accepted');
check(PluginFieldsComputerdmosasset::$row['swsdbillablefieldtwo'] === 0, 'Local yesno false update must persist 0');

$dropdownKeyA = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_departmentfielddropdowns_id';
$dropdownKeyB = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_departmentfieldbdropdowns_id';
$dropdownOption = dropdownFieldOption(8, 'DMOS Asset - Department', 'departmentfield');
PluginFieldsContainer::$options = [884783 => $dropdownOption];
PluginFieldsComputerdmosasset::$found = true;
PluginFieldsComputerdmosasset::$row = ['id' => 10, 'plugin_fields_departmentfielddropdowns_id' => 10];
$fields = FieldMapping::fieldsFor('Computer');
check(in_array($dropdownKeyA, array_column($fields, 'key'), true), 'Discovery includes local Fields-plugin dropdown identifiers');
$dropdownMetadata = FieldsText::customMetadata('Computer', [$dropdownKeyA])[$dropdownKeyA];
check($dropdownMetadata['field'] === 'plugin_fields_departmentfielddropdowns_id', 'Dropdown metadata must use the generated row foreign key column');
check($dropdownMetadata['definition_name'] === 'departmentfield', 'Dropdown metadata must keep the Fields field definition name');
check($dropdownMetadata['type'] === 'dropdown', 'Dropdown metadata must identify the canonical dropdown type');
check($dropdownMetadata['dropdown_class'] === 'PluginFieldsDepartmentfieldDropdown', 'Dropdown metadata must identify the generated dropdown class');
check($dropdownMetadata['dropdown_table'] === 'glpi_plugin_fields_departmentfielddropdowns', 'Dropdown metadata must identify the generated dropdown option table');
$dropdownMetadata = FieldsText::metadataWithDefinition($dropdownMetadata, PluginFieldsField::$definitions[8]);
check($dropdownMetadata['field'] === 'plugin_fields_departmentfielddropdowns_id', 'Definition validation must preserve the generated dropdown FK column');
$dropdownAsset = ['id' => 2, 'serial' => 'test'] + FieldsText::localValues('Computer', 2, [$dropdownKeyA]);
check($dropdownAsset[$dropdownKeyA] === 'Operations > Hardware', 'Local dropdown IDs must resolve to completename labels');
PluginFieldsComputerdmosasset::$row = ['id' => 10, 'plugin_fields_departmentfielddropdowns_id' => 12];
$fallbackAsset = FieldsText::localValues('Computer', 2, [$dropdownKeyA]);
check($fallbackAsset[$dropdownKeyA] === 'Fallback Only', 'Local dropdown labels must fall back to name when completename is blank');
PluginFieldsComputerdmosasset::$row = ['id' => 10, 'plugin_fields_departmentfielddropdowns_id' => 10];
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => [
    '884783' => [
        'glpi_b_field_key' => $dropdownKeyB,
        'glpi_b_field_uid' => $dropdownKeyB,
        'glpi_b_field_label' => 'Department',
        'source_of_truth' => 'glpi_a',
    ],
]]]);
$dropdownMappings = FieldMapping::syncMappings('test', 'Computer');
$dropdownTypes = FieldMapping::expectedCustomTypes('Computer', $dropdownMappings);
check($dropdownMappings === [['glpi_a_field' => $dropdownKeyA, 'glpi_b_field' => $dropdownKeyB, 'source_of_truth' => 'glpi_a']], 'Dropdown custom mappings must keep stable generated FK identifiers');
check($dropdownTypes === [$dropdownKeyB => 'dropdown'], 'Dropdown custom mappings must expect remote dropdown fields');
$dropdownRemote = ['entities_id' => 0, $dropdownKeyB => 'Operations > Software'];
$dropdownChanges = $compare->invoke($service, $dropdownAsset, $dropdownRemote, $route, $dropdownMappings, $dropdownTypes);
check($dropdownChanges['remote'] === [$dropdownKeyB => 'Operations > Hardware'], 'Dropdown comparisons must use labels, not copied option IDs');
$dropdownAsset[$dropdownKeyA] = '';
check($compare->invoke($service, $dropdownAsset, $dropdownRemote, $route, $dropdownMappings, $dropdownTypes)['remote'] === [$dropdownKeyB => ''], 'A blank dropdown source must clear the destination');
check(FieldsText::updateLocal('Computer', 2, [$dropdownKeyA => 'Operations > Software']), 'Local dropdown update should resolve a destination label');
check(PluginFieldsComputerdmosasset::$row['plugin_fields_departmentfielddropdowns_id'] === 11, 'Local dropdown update must persist the matching destination option ID');
check(FieldsText::updateLocal('Computer', 2, [$dropdownKeyA => '']), 'Local dropdown blank update should be accepted');
check(PluginFieldsComputerdmosasset::$row['plugin_fields_departmentfielddropdowns_id'] === 0, 'Local dropdown blank update must clear the FK value');
expectRuntime(static fn (): bool => FieldsText::updateLocal('Computer', 2, [$dropdownKeyA => 'Missing department']), 'dropdown option is missing', 'Missing local destination dropdown labels must block clearly');
PluginFieldsDepartmentfieldDropdown::$rows[13] = ['id' => 13, 'name' => 'Hardware duplicate', 'completename' => 'Operations > Hardware'];
expectRuntime(static fn (): bool => FieldsText::updateLocal('Computer', 2, [$dropdownKeyA => 'Operations > Hardware']), 'dropdown option label is duplicated', 'Duplicate local destination dropdown labels must block clearly');
unset(PluginFieldsDepartmentfieldDropdown::$rows[13]);
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => [
    '884783' => [
        'glpi_b_field_key' => 'Computer.comment',
        'glpi_b_field_uid' => 'Computer.comment',
        'glpi_b_field_label' => 'Comments',
        'source_of_truth' => 'glpi_a',
    ],
]]]);
expectRuntime(static fn (): array => FieldMapping::syncMappings('test', 'Computer'), 'compatible remote Fields-plugin dropdown field', 'Dropdown-to-text mappings must be rejected clearly');
PluginFieldsContainer::$options = [
    884776 => fieldOption(1, 'DMOS Asset - Name', 'namefield', 'text'),
    884783 => $dropdownOption,
];
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => [
    $key => [
        'glpi_b_field_key' => $dropdownKeyB,
        'glpi_b_field_uid' => $dropdownKeyB,
        'glpi_b_field_label' => 'Department',
        'source_of_truth' => 'glpi_a',
    ],
]]]);
expectRuntime(static fn (): array => FieldMapping::syncMappings('test', 'Computer'), 'compatible local Fields-plugin dropdown field', 'Text-to-dropdown mappings must be rejected clearly');
$fieldsFromSearchOptions = new ReflectionMethod(\GlpiPlugin\Assetsync20\GlpiBConnection::class, 'fieldsFromSearchOptions');
$fieldsFromSearchOptions->setAccessible(true);
$remoteFields = $fieldsFromSearchOptions->invoke(null, [884783 => ['id' => 884783] + $dropdownOption], 'Computer');
check($remoteFields === [[
    'key' => $dropdownKeyA,
    'id' => '884783',
    'uid' => $dropdownKeyA,
    'label' => 'DMOS Asset - Department',
]], 'GLPI B field discovery must expose Fields-plugin dropdowns with stable generated FK identifiers');
$restDropdownKey = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_companyfielddropdowns_id';
$restDropdownOption = [
    'name' => 'DMOS Asset - Company',
    'table' => 'glpi_plugin_fields_companyfielddropdowns',
    'field' => 'completename',
    'datatype' => 'dropdown',
    'uid' => 'Computer.PluginFieldsComputerdmosasset.PluginFieldsCompanyfieldDropdown.completename',
];
$restRemoteFields = $fieldsFromSearchOptions->invoke(null, [76680 => $restDropdownOption], 'Computer');
check($restRemoteFields === [[
    'key' => $restDropdownKey,
    'id' => '76680',
    'uid' => $restDropdownKey,
    'label' => 'DMOS Asset - Company',
]], 'GLPI B REST dropdown discovery must convert joined display options to stable generated FK identifiers');
$multiDropdownOption = dropdownFieldOption(8, 'DMOS Asset - Department', 'departmentfield', 1);
expectRuntime(static fn (): string => FieldsText::validate($multiDropdownOption), 'multi-select dropdown fields are not supported', 'Multi-select Fields dropdowns must block clearly');
$itemDropdownOption = fieldOption(8, 'DMOS Asset - Assigned User', 'assigneduserfield', 'dropdown-User');
expectRuntime(static fn (): string => FieldsText::validate($itemDropdownOption), 'Only Fields-plugin scalar fields', 'Non-Fields item dropdowns must stay unsupported');

PluginFieldsContainer::$options = [
    884777 => ['name' => 'DMOS Asset - HW Billable', 'field' => 'hwbillablefieldtwo',
        'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => 'yesno', 'pfields_fields_id' => 2],
];
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => [
    '884777' => [
        'glpi_b_field_key' => 'Computer.comment',
        'glpi_b_field_uid' => 'Computer.comment',
        'glpi_b_field_label' => 'Comments',
        'source_of_truth' => 'glpi_a',
    ],
]]]);
try {
    FieldMapping::syncMappings('test', 'Computer');
    throw new LogicException('Custom yesno was allowed to target a native text field');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'compatible remote Fields-plugin yesno'), 'Custom yesno/native type mismatch must be explicit');
}
try {
    FieldsText::normalizeValue('yesno', 'maybe');
    throw new LogicException('Unknown yesno value was silently accepted');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Unsupported Fields-plugin yesno value'), 'Unknown yesno values must block clearly');
}

PluginFieldsContainer::$options = [];
PluginFieldsContainer::$type = 'url';
Config::$values['field_mappings'] = json_encode(['test' => ['Computer' => ['884776' => [
    'glpi_b_field_key' => $key, 'glpi_b_field_uid' => $key,
    'glpi_b_field_label' => 'Name', 'source_of_truth' => 'glpi_a',
]]]]);
expectRuntime(static fn (): array => FieldMapping::syncMappings('test', 'Computer'), 'Only Fields-plugin scalar fields', 'Unsupported custom types must be explicit');
try {
    FieldsText::descriptor('Computer', 'Monitor.PluginFieldsComputerdmosasset.namefield');
    throw new LogicException('Wrong asset type accepted');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Invalid Fields-plugin'), 'Reject wrong asset type before row access');
}
echo "Fields-plugin custom field tests passed.\n";
