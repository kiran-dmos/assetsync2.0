<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use GlpiPlugin\Assetsync20\BillingFieldConfig;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\FieldsText;
use GlpiPlugin\Assetsync20\HardwareBilling;
use GlpiPlugin\Assetsync20\SwsdBilling;

class Config
{
    public static array $values = [];
    public static function getConfigurationValues($context, $keys): array { return array_intersect_key(self::$values, array_flip($keys)); }
    public static function setConfigurationValues($context, $values): void { self::$values = array_merge(self::$values, $values); }
}

class PluginFieldsContainer
{
    public static array $options = [];
    public static function getAddSearchOptions($itemtype): array { return self::$options; }
}

class PluginFieldsField
{
    public static array $definitions = [];
    public array $fields = [];
    public function getFromDB($id): bool
    {
        $this->fields = self::$definitions[$id] ?? [];
        return $this->fields !== [];
    }
}

class PluginFieldsComputerbilling
{
    public static array $row = ['id' => 10];
    public array $fields = [];
    public function getFromDBByCrit(array $criteria): bool
    {
        $this->fields = self::$row;
        return true;
    }
    public function update(array $input): bool
    {
        self::$row = array_merge(self::$row, $input);
        return true;
    }
}

class State
{
    public array $fields = [];
    public function getFromDB($id): bool
    {
        $this->fields = [1 => ['name' => 'In Use'], 2 => ['name' => 'Retired - Disposed']][$id] ?? [];
        return $this->fields !== [];
    }
}

class ComputerModel
{
    public array $fields = [];
    public function getFromDB($id): bool
    {
        $this->fields = [5 => ['name' => 'Lenovo ThinkStation P7']][$id] ?? [];
        return $this->fields !== [];
    }
}

function getItemTypeForTable($table): string { return 'PluginFieldsComputerbilling'; }
function getTableForItemType($class): string { return 'glpi_plugin_fields_computerbillings'; }

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function expectError(callable $work, string $contains): void
{
    try {
        $work();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $contains), 'Expected "' . $contains . '": ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected error containing ' . $contains);
}

$fields = [
    'ownership' => ['Ownership', 'text'],
    'billable' => ['Billable', 'yesno'],
    'installed' => ['Installed', 'date'],
    'hwstart' => ['HW Start', 'date'],
    'hwend' => ['HW End', 'date'],
    'frequency' => ['Frequency', 'text'],
    'month' => ['Month', 'int'],
    'redeployed' => ['Redeployed', 'date'],
    'swsdbillable' => ['SW/SD Billable', 'yesno'],
    'swsdstart' => ['SW/SD Start', 'date'],
];
$keys = [];
$id = 1;
foreach ($fields as $column => [$label, $type]) {
    $keys[$column] = 'Computer.PluginFieldsComputerbilling.' . $column;
    PluginFieldsContainer::$options[$id] = [
        'name' => $label,
        'field' => $column,
        'table' => 'glpi_plugin_fields_computerbillings',
        'pfields_type' => $type,
        'pfields_fields_id' => $id,
    ];
    PluginFieldsField::$definitions[$id] = [
        'id' => $id,
        'name' => $column,
        'type' => $type,
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => in_array($column, ['hwstart', 'swsdstart'], true) ? 1 : 0,
    ];
    $id++;
}

check(BillingFieldConfig::load() === null, 'Absent config keeps legacy mode');
check(HardwareBilling::localInputKeys('Computer') === [], 'Legacy label discovery stays unchanged');
$legacyDraft = BillingFieldConfig::legacyDraft();
check(!$legacyDraft['config']['hardware']['enabled'] && !$legacyDraft['config']['swsd']['enabled'], 'Undetected legacy workflows are not guessed active');
check(count($legacyDraft['warnings']) === 2, 'Undetected legacy workflows show clear warnings');
expectError(static fn () => BillingFieldConfig::save([]), 'Confirm that you want to stop legacy billing');
check(BillingFieldConfig::load() === null, 'Unconfirmed all-disabled save leaves legacy config absent');
BillingFieldConfig::save([], true);
check(BillingFieldConfig::load()['hardware']['enabled'] === false, 'Explicit confirmation may disable legacy billing');
$config = [
    'hardware' => [
        'enabled' => true,
        'status' => 'states_id',
        'ownership' => $keys['ownership'],
        'hw_billable' => $keys['billable'],
        'installed_date' => $keys['installed'],
        'model' => 'computermodels_id',
        'hw_billing_start_date' => $keys['hwstart'],
        'hw_billing_end_date' => $keys['hwend'],
        'hw_billing_frequency' => $keys['frequency'],
        'hw_billing_month' => $keys['month'],
    ],
    'swsd' => [
        'enabled' => true,
        'status' => 'states_id',
        'redeployed_date' => $keys['redeployed'],
        'swsd_billable' => $keys['swsdbillable'],
        'swsd_billing_start_date' => $keys['swsdstart'],
    ],
];
BillingFieldConfig::save($config);
check(BillingFieldConfig::load() === $config, 'Config saves and loads stable native/custom keys');
check(isset(Config::$values['billing_fields']), 'One billing_fields JSON value is stored');
check(BillingFieldConfig::options('hardware', 'status')['native']['states_id'] === 'Status', 'Status selector offers native Status');
check(!array_key_exists('computermodels_id', BillingFieldConfig::options('hardware', 'status')['native']), 'Status selector excludes native Model relation');
check(BillingFieldConfig::options('hardware', 'model')['native']['computermodels_id'] === 'Model', 'Model selector offers native Model relation');
check(!array_key_exists('states_id', BillingFieldConfig::options('hardware', 'ownership')['native']), 'Ownership selector must not offer native Status');
check(BillingFieldConfig::options('hardware', 'hw_billing_frequency')['native'] === [], 'Native identity/contact columns are not billing outputs');

$asset = [
    'states_id' => 1,
    'computermodels_id' => 5,
    $keys['ownership'] => 'Customer Leased',
    $keys['billable'] => 1,
    $keys['installed'] => '2026-01-06',
    $keys['redeployed'] => '2026-03-06',
];
$hw = HardwareBilling::syncData('Computer', $asset, new DateTimeImmutable('2026-02-10'));
check($hw['values'] === [
    $keys['hwstart'] => '2026-02-01',
    $keys['hwend'] => '2030-01-31',
    $keys['frequency'] => 'Annual',
    $keys['month'] => 1,
], 'Native Status and Model IDs resolve to labels for HW calculation');
$sw = SwsdBilling::syncData('Computer', $asset);
check($sw['values'] === [$keys['swsdbillable'] => 1, $keys['swsdstart'] => '2026-04-01'], 'Native Status works for SW/SD calculation');
check(SwsdBilling::syncData('Computer', array_merge($asset, ['states_id' => 2]))['values'] === [
    $keys['swsdbillable'] => 0, $keys['swsdstart'] => '',
], 'Native retired Status resets SW/SD output');
check(SwsdBilling::syncData('Computer', array_merge($asset, [$keys['redeployed'] => '2026-03-05']))['values'][$keys['swsdstart']] === '2026-03-01', 'SW/SD same-run inbound date uses day-five rule');
check(!FieldsText::updateLocal('Computer', 2, [$keys['swsdstart'] => '2026-04-01']), 'Readonly configured output rejects ordinary writes');
$service = new AssetSyncService(null, new DateTimeImmutable('2026-02-10'));
$applyHardware = new ReflectionMethod(AssetSyncService::class, 'applyHardwareBilling');
$applyHardware->setAccessible(true);
$applyHardware->invoke($service, 'Computer', 2, $asset);
check(PluginFieldsComputerbilling::$row['hwstart'] === '2026-02-01', 'Trusted HW Billing writes configured readonly output');
$applySwsd = new ReflectionMethod(AssetSyncService::class, 'applySwsdBilling');
$applySwsd->setAccessible(true);
$applySwsd->invoke($service, 'Computer', 2, $asset, []);
check(PluginFieldsComputerbilling::$row['swsdstart'] === '2026-04-01', 'Trusted SW/SD Billing writes configured readonly output');
PluginFieldsComputerbilling::$row['swsdstart'] = '2026-05-01';
$applySwsd->invoke($service, 'Computer', 2, $asset + [$keys['swsdstart'] => '2026-05-01'], [[
    'glpi_a_field' => $keys['swsdstart'], 'glpi_b_field' => $keys['swsdstart'], 'source_of_truth' => 'glpi_b',
]]);
check(PluginFieldsComputerbilling::$row['swsdstart'] === '2026-05-01', 'GLPI B-owned SW/SD output is not overwritten');

foreach (PluginFieldsContainer::$options as &$option) {
    $option['name'] = 'Renamed ' . $option['name'];
}
unset($option);
check(HardwareBilling::syncData('Computer', $asset, new DateTimeImmutable('2026-02-10'))['values'] === $hw['values'], 'Renamed Fields labels do not change configured behavior');
check(FieldsText::isCustom($keys['hwstart']), 'Configured outputs keep generated field keys');

$missing = $config;
unset($missing['hardware']['hw_billing_month']);
expectError(static fn () => BillingFieldConfig::save($missing), 'requires HW Billing Month');
$wrong = $config;
$wrong['hardware']['hw_billing_month'] = $keys['hwstart'];
expectError(static fn () => BillingFieldConfig::save($wrong), 'wrong type');
$colliding = $config;
$colliding['hardware']['installed_date'] = $keys['hwstart'];
expectError(static fn () => BillingFieldConfig::save($colliding), 'also be an input');
$unsafe = $config;
$unsafe['hardware']['hw_billing_frequency'] = 'serial';
expectError(static fn () => BillingFieldConfig::save($unsafe), 'as a billing output');
$swapped = $config;
$swapped['hardware']['model'] = 'states_id';
expectError(static fn () => BillingFieldConfig::save($swapped), 'cannot use native field');
$unavailable = $config;
$unavailable['hardware']['ownership'] = 'Computer.PluginFieldsComputerbilling.missing';
expectError(static fn () => BillingFieldConfig::save($unavailable), 'unavailable');

$removedOption = PluginFieldsContainer::$options[4];
unset(PluginFieldsContainer::$options[4]);
expectError(static fn () => HardwareBilling::syncData('Computer', $asset, new DateTimeImmutable('2026-02-10')), 'unavailable');
check(BillingFieldConfig::load() === $config, 'A failed save never replaces the stored config');
PluginFieldsContainer::$options[4] = $removedOption;
Config::$values['billing_fields'] = json_encode($wrong);
expectError(static fn () => HardwareBilling::localInputKeys('Computer'), 'wrong type');
Config::$values['billing_fields'] = json_encode($config);
BillingFieldConfig::save(['hardware' => ['enabled' => false], 'swsd' => ['enabled' => false]]);
check(HardwareBilling::localInputKeys('Computer') === [] && SwsdBilling::localInputKeys('Computer') === [], 'Saved disabled workflows never use legacy fallback');
BillingFieldConfig::save($config);
Config::$values['billing_fields'] = '{bad';
expectError(static fn () => HardwareBilling::localInputKeys('Computer'), 'invalid JSON');

echo "Billing field configuration tests passed.\n";
