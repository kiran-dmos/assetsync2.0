<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\BillingFieldConfig;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\FieldsText;
use GlpiPlugin\Assetsync20\GlpiBConnection;

$checks = 0;
$owners = [GlpiBConnection::class, EntitySyncRoute::class, FieldMapping::class, BillingFieldConfig::class, FieldsText::class];
$configOwners = [
    'glpib_connections' => GlpiBConnection::class,
    'entity_sync_routes' => EntitySyncRoute::class,
    'field_mappings' => FieldMapping::class,
    'billing_fields' => BillingFieldConfig::class,
];

function cacheCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cacheError(callable $work, string $message): void
{
    try {
        $work();
    } catch (RuntimeException $error) {
        cacheCheck(str_contains($error->getMessage(), $message), 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected error: ' . $message);
}

function cacheRead(string $owner): ?array
{
    return match ($owner) {
        GlpiBConnection::class => GlpiBConnection::loadAll(),
        EntitySyncRoute::class => EntitySyncRoute::loadAll(),
        FieldMapping::class => FieldMapping::load('cache', 'Computer'),
        BillingFieldConfig::class => BillingFieldConfig::load(),
    };
}

function cacheState(string $owner): ?array
{
    return (new ReflectionProperty($owner, 'runCache'))->getValue();
}

function cachesEnded(): void
{
    global $owners;
    foreach ($owners as $owner) {
        cacheCheck(cacheState($owner) === null, $owner . ' must clear all run state.');
    }
}

// Separate mode checks missing GLPI classes before the smoke bootstrap defines them.
if (($argv[1] ?? '') === '--unavailable') {
    foreach ($owners as $owner) {
        $owner::beginRunCache();
    }
    foreach (array_slice($owners, 0, 3) as $owner) {
        cacheCheck(cacheRead($owner) === [] && cacheState($owner) === [], 'Unavailable config must not be memoized.');
    }
    cacheError(static fn () => BillingFieldConfig::load(), 'storage is unavailable');
    cacheCheck(cacheState(BillingFieldConfig::class) === [], 'Unavailable billing storage must not be memoized.');
    class Config
    {
        public static function getConfigurationValues($context, $keys): array
        {
            return array_intersect_key(array_fill_keys([
                'glpib_connections', 'entity_sync_routes', 'field_mappings', 'billing_fields',
            ], '[]'), array_flip($keys));
        }
    }
    foreach ($configOwners as $owner) {
        cacheCheck(cacheRead($owner) === [] && cacheState($owner) !== [], 'A later available read must recover in the same run.');
    }
    foreach ($owners as $owner) {
        $owner::endRunCache();
    }
    cachesEnded();
    echo "Unavailable run cache tests passed ($checks checks).\n";
    exit;
}

final class Toolbox
{
    public static ?Closure $onLog = null;
    public static array $entries = [];

    public static function logInFile(string $file, string $entry): void
    {
        self::$entries[] = $entry;
        if (self::$onLog !== null) {
            (self::$onLog)();
        }
    }
}

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

function cacheSetup(): void
{
    global $owners;
    foreach ($owners as $owner) {
        $owner::endRunCache();
    }
    Config::$reads = [];
    Config::$beforeRead = Config::$beforeWrite = Config::$beforeDelete = null;
    Config::$values = ['plugin:assetsync20' => [
        'glpib_connections' => json_encode([[
            'id' => 'cache', 'name' => 'First', 'base_url' => 'https://test.invalid',
            'app_token' => 'encrypted:' . base64_encode('app'), 'user_token' => 'encrypted:' . base64_encode('user'), 'active' => true,
        ]], JSON_THROW_ON_ERROR),
        'entity_sync_routes' => json_encode([[
            'id' => 'route', 'name' => 'First', 'glpi_b_connection_id' => 'cache',
            'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '2', 'asset_types' => ['Computer'], 'active' => true,
        ]], JSON_THROW_ON_ERROR),
        'field_mappings' => json_encode(['cache' => ['Computer' => [
            'name' => ['glpi_b_field_key' => 'Computer.name', 'source_of_truth' => 'glpi_a'],
        ]]], JSON_THROW_ON_ERROR),
        'billing_fields' => '{"hardware":{"enabled":false},"swsd":{"enabled":false}}',
    ]];
    PluginFieldsContainer::$options = [];
    FakeItemTypes::$classReads = FakeItemTypes::$tableReads = [];
    FakeItemTypes::$classResult = FakeItemTypes::$tableResult = null;
    Toolbox::$onLog = null;
    Toolbox::$entries = [];
    $GLOBALS['DB'] = new FakeDB();
}

cacheSetup();
foreach ($configOwners as $key => $owner) {
    $first = cacheRead($owner);
    cacheCheck(cacheRead($owner) === $first && Config::$reads[$key] === 2, $owner . ' must remain fresh outside a run.');
    $owner::beginRunCache();
    $first = cacheRead($owner);
    Config::$values['plugin:assetsync20'][$key] = '[]';
    cacheCheck(cacheRead($owner) === $first && Config::$reads[$key] === 3, $owner . ' must reuse its raw snapshot within a run.');
    $owner::endRunCache();
    cacheCheck(cacheRead($owner) === [] && Config::$reads[$key] === 4, $owner . ' must reread after a run.');
}
cachesEnded();

foreach ($configOwners as $key => $owner) {
    foreach (['[]', null, '', 'null', 'false', '{"broken"'] as $json) {
        cacheSetup();
        $owner::beginRunCache();
        if ($json === null) {
            unset(Config::$values['plugin:assetsync20'][$key]);
        } else {
            Config::$values['plugin:assetsync20'][$key] = $json;
        }
        if ($owner === BillingFieldConfig::class && $json !== null && $json !== '[]') {
            cacheError(static fn () => cacheRead($owner), 'invalid JSON');
            cacheError(static fn () => cacheRead($owner), 'invalid JSON');
        } else {
            $expected = $owner === BillingFieldConfig::class && $json === null ? null : [];
            cacheCheck(cacheRead($owner) === $expected && cacheRead($owner) === $expected, 'Empty/absent config behavior must be preserved.');
        }
        cacheCheck(Config::$reads[$key] === ($json === '[]' ? 1 : 2), 'Only a successfully decoded array may be memoized: ' . $key);
        cacheCheck(($json === '[]') === (cacheState($owner) !== []), 'Missing/malformed config must leave its slot empty.');
        Config::$values['plugin:assetsync20'][$key] = '[]';
        cacheCheck(cacheRead($owner) === [], 'A valid read must recover after an absent/malformed read.');
        $owner::endRunCache();
    }
    cacheSetup();
    $owner::beginRunCache();
    Config::$beforeRead = static function (): void {
        throw new RuntimeException('read failed');
    };
    cacheError(static fn () => cacheRead($owner), 'read failed');
    cacheCheck(cacheState($owner) === [], 'Failed reads must not be memoized.');
    Config::$beforeRead = null;
    cacheRead($owner);
    cacheCheck(Config::$reads[$key] === 2, 'A failed read must retry storage in the same run.');
    $owner::endRunCache();
}

cacheSetup();
GlpiBConnection::beginRunCache();
Config::$values['plugin:assetsync20']['glpib_connections'] = '[{"name":"Missing ID"}]';
cacheCheck(GlpiBConnection::loadAll()[0]['id'] !== GlpiBConnection::loadAll()[0]['id'], 'Raw connection caching must not freeze generated IDs.');
GlpiBConnection::endRunCache();
EntitySyncRoute::beginRunCache();
Config::$values['plugin:assetsync20']['entity_sync_routes'] = '[{"name":"Missing ID"}]';
cacheCheck(EntitySyncRoute::loadAll()[0]['id'] !== EntitySyncRoute::loadAll()[0]['id'], 'Raw route caching must not freeze normalization.');
EntitySyncRoute::endRunCache();

cacheSetup();
foreach ($configOwners as $owner) {
    $owner::beginRunCache();
    cacheRead($owner);
}
$connections = json_decode(Config::$values['plugin:assetsync20']['glpib_connections'], true);
$connections[0]['app_token'] = 'encrypted:' . base64_encode('changed');
$connections[] = ['id' => 'external', 'name' => 'External'];
Config::$values['plugin:assetsync20']['glpib_connections'] = json_encode($connections);
GlpiBConnection::save(['id' => 'cache', 'name' => 'Saved']);
cacheCheck(GlpiBConnection::find('cache')['app_token'] === 'changed' && GlpiBConnection::find('external') !== null, 'Connection save must read current tokens and preserve new rows.');
$routes = json_decode(Config::$values['plugin:assetsync20']['entity_sync_routes'], true);
$routes[] = ['id' => 'external', 'name' => 'External'];
Config::$values['plugin:assetsync20']['entity_sync_routes'] = json_encode($routes);
EntitySyncRoute::save(['id' => 'route', 'name' => 'Saved', 'glpi_a_source_entity_name' => 'Source']);
cacheCheck(EntitySyncRoute::find('external') !== null && EntitySyncRoute::find('route')['name'] === 'Saved', 'Route save must merge fresh rows and reload after writing.');
Config::$values['plugin:assetsync20']['field_mappings'] = '{"external":{"Monitor":{"name":{"glpi_b_field_key":"Monitor.name","source_of_truth":"glpi_b"}}}}';
FieldMapping::save('cache', 'Computer', ['name' => ['glpi_b_field_key' => 'Computer.name', 'source_of_truth' => 'glpi_b']]);
cacheCheck(FieldMapping::load('cache', 'Computer')['name']['source_of_truth'] === 'glpi_b' && FieldMapping::load('external', 'Monitor') !== [], 'Mapping save must preserve fresh other mappings and reload its write.');
unset(Config::$values['plugin:assetsync20']['billing_fields']);
cacheError(static fn () => BillingFieldConfig::save([]), 'Confirm that you want to stop legacy billing');
BillingFieldConfig::save([], true);
cacheCheck(BillingFieldConfig::workflow('hardware') === ['enabled' => false], 'Billing save must invalidate before checking legacy mode and after writing.');
GlpiBConnection::delete('external');
EntitySyncRoute::delete('external');
cacheCheck(GlpiBConnection::find('external') === null && EntitySyncRoute::find('external') === null, 'Deletes must invalidate current snapshots.');

foreach ($configOwners as $key => $owner) {
    $owner::uninstall();
    cacheCheck(cacheState($owner) === [], 'Uninstall must clear the owning snapshot.');
    cacheCheck(cacheRead($owner) === ($owner === BillingFieldConfig::class ? null : []), 'Uninstall must expose missing storage immediately.');
    if ($owner !== BillingFieldConfig::class) {
        $owner::install();
        cacheCheck(Config::$values['plugin:assetsync20'][$key] === '[]' && cacheRead($owner) === [], 'Install must initialize and expose current config.');
    }
    $owner::endRunCache();
}

foreach ($configOwners as $key => $owner) {
    cacheSetup();
    $owner::beginRunCache();
    cacheRead($owner);
    Config::$beforeWrite = static function () use ($owner): void {
        cacheRead($owner);
        throw new RuntimeException('write failed');
    };
    cacheError(static function () use ($owner): void {
        match ($owner) {
            GlpiBConnection::class => GlpiBConnection::save(['id' => 'cache', 'name' => 'Failed']),
            EntitySyncRoute::class => EntitySyncRoute::save(['id' => 'route', 'glpi_a_source_entity_name' => 'Source']),
            FieldMapping::class => FieldMapping::save('cache', 'Computer', []),
            BillingFieldConfig::class => BillingFieldConfig::save([], true),
        };
    }, 'write failed');
    cacheCheck(cacheState($owner) === [], 'A failed write must clear even a snapshot loaded by a write callback.');
    Config::$beforeWrite = null;
    cacheRead($owner);
    Config::$beforeDelete = static function () use ($owner): void {
        cacheRead($owner);
        throw new RuntimeException('delete failed');
    };
    cacheError(static fn () => $owner::uninstall(), 'delete failed');
    cacheCheck(cacheState($owner) === [], 'Failed uninstall must clear the owning snapshot.');
    Config::$beforeDelete = null;
    $owner::endRunCache();
}

cacheSetup();
$textKey = 'Computer.PluginFieldsComputerdmosasset.namefield';
$option = ['table' => 'glpi_plugin_fields_computerdmosassets', 'field' => 'namefield'];
FieldsText::descriptor('Computer', $textKey);
FieldsText::descriptor('Computer', $textKey);
FieldsText::key('Computer', $option);
FieldsText::key('Computer', $option);
cacheCheck(FakeItemTypes::$tableReads['PluginFieldsComputerdmosasset'] === 2 && FakeItemTypes::$classReads[$option['table']] === 2, 'Direct descriptors and conversions must be fresh.');
FieldsText::beginRunCache();
FieldsText::descriptor('Computer', $textKey);
FieldsText::descriptor('Computer', $textKey);
FieldsText::descriptor('Computer', 'Computer.PluginFieldsComputerdmosasset.otherfield');
FieldsText::key('Computer', $option);
FieldsText::key('Computer', $option);
cacheCheck(FakeItemTypes::$tableReads['PluginFieldsComputerdmosasset'] === 3 && FakeItemTypes::$classReads[$option['table']] === 3, 'A run must share successful deterministic conversions, including across descriptors.');
cacheError(static fn () => FieldsText::descriptor('Monitor', $textKey), 'Invalid Fields-plugin identifier');
FieldsText::endRunCache();
FieldsText::beginRunCache();
FakeItemTypes::$tableResult = static fn () => false;
cacheCheck(FieldsText::descriptor('Computer', $textKey)['table'] === false && FieldsText::descriptor('Computer', $textKey)['table'] === false, 'Missing table conversions must preserve their original result.');
cacheCheck(FakeItemTypes::$tableReads['PluginFieldsComputerdmosasset'] === 5 && cacheState(FieldsText::class) === [], 'Missing conversions/descriptors must not be memoized.');
FakeItemTypes::$tableResult = static function (): never { throw new RuntimeException('conversion failed'); };
cacheError(static fn () => FieldsText::descriptor('Computer', $textKey), 'conversion failed');
FakeItemTypes::$tableResult = null;
cacheCheck(FieldsText::descriptor('Computer', $textKey)['table'] === $option['table'], 'Failed descriptor conversion must recover in the same run.');
FakeItemTypes::$classResult = static fn () => '';
FieldsText::key('Computer', $option);
FieldsText::key('Computer', $option);
cacheCheck(FakeItemTypes::$classReads[$option['table']] === 5, 'Missing class conversions must not be memoized.');
FakeItemTypes::$classResult = null;
cacheCheck(FieldsText::key('Computer', $option) === $textKey, 'A later class conversion must recover.');
FieldsText::endRunCache();

cacheSetup();
FieldsText::beginRunCache();
FieldMapping::beginRunCache();
BillingFieldConfig::beginRunCache();
$dropdownKey = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_departmentfielddropdowns_id';
PluginFieldsContainer::$options = [
    1 => ['name' => 'Name', 'field' => 'namefield', 'table' => $option['table'], 'pfields_type' => 'text', 'pfields_fields_id' => 1],
    8 => ['name' => 'Department', 'field' => 'plugin_fields_departmentfielddropdowns_id', 'table' => $option['table'], 'pfields_type' => 'dropdown', 'pfields_fields_id' => 8],
];
Config::$values['plugin:assetsync20']['field_mappings'] = json_encode(['cache' => ['Computer' => [
    $textKey => ['glpi_b_field_key' => $textKey, 'source_of_truth' => 'glpi_a'],
]]]);
$mapping = FieldMapping::syncMappings('cache', 'Computer');
cacheCheck($mapping[0]['glpi_a_field'] === $textKey, 'Valid custom mappings must resolve.');
PluginFieldsContainer::$options[1]['pfields_type'] = 'date';
cacheCheck(FieldMapping::expectedCustomTypes('Computer', $mapping)[$textKey] === 'date', 'Current custom types must not be cached with mapping config.');
PluginFieldsContainer::$options[1]['pfields_type'] = 'unsupported';
cacheError(static fn () => FieldMapping::syncMappings('cache', 'Computer'), 'scalar');
PluginFieldsContainer::$options[1]['pfields_type'] = 'text';
unset(PluginFieldsContainer::$options[1]);
cacheError(static fn () => FieldMapping::syncMappings('cache', 'Computer'), 'unavailable');
PluginFieldsContainer::$options[1] = ['name' => 'Name', 'field' => 'namefield', 'table' => $option['table'], 'pfields_type' => 'text', 'pfields_fields_id' => 1];
PluginFieldsComputerdmosasset::$rows[10] = ['id' => 10, 'items_id' => 10, 'itemtype' => 'Computer', 'namefield' => 'Before', 'plugin_fields_departmentfielddropdowns_id' => 10];
cacheCheck(FieldsText::localValues('Computer', 10, [$textKey])[$textKey] === 'Before', 'Local values must be read normally.');
PluginFieldsComputerdmosasset::$rows[10]['namefield'] = 'After';
cacheCheck(FieldsText::localValues('Computer', 10, [$textKey])[$textKey] === 'After', 'Local values must remain fresh within a run.');
PluginFieldsContainer::$options[1]['is_readonly'] = 1;
cacheCheck(!FieldsText::customMetadata('Computer', [$textKey])[$textKey]['writable'], 'Current option readonly metadata must remain fresh.');
PluginFieldsField::$definitions[1]['is_readonly'] = 1;
cacheCheck(!FieldsText::updateLocal('Computer', 10, [$textKey => 'Denied']), 'Current definition readonly state must block writes.');
PluginFieldsField::$definitions[1]['is_readonly'] = 0;
PluginFieldsContainer::$options[1]['is_readonly'] = 0;
cacheCheck(FieldsText::updateLocal('Computer', 10, [$textKey => 'Written']) && FieldsText::localValues('Computer', 10, [$textKey])[$textKey] === 'Written', 'Fresh permitted writes must still perform readback verification.');
PluginFieldsField::$definitions[1]['is_active'] = 0;
cacheError(static fn () => FieldsText::updateLocal('Computer', 10, [$textKey => 'Inactive']), 'inactive');
PluginFieldsField::$definitions[1]['is_active'] = 1;
cacheCheck(FieldsText::localValues('Computer', 10, [$dropdownKey])[$dropdownKey] === 'Operations > Hardware', 'Dropdown labels must resolve current content.');
PluginFieldsDepartmentfieldDropdown::$rows[10]['completename'] = 'Changed label';
cacheCheck(FieldsText::localValues('Computer', 10, [$dropdownKey])[$dropdownKey] === 'Changed label', 'Dropdown content must not be cached.');
PluginFieldsField::$definitions[8]['multiple'] = 1;
cacheError(static fn () => FieldsText::updateLocal('Computer', 10, [$dropdownKey => 'Changed label']), 'multi-select');
PluginFieldsField::$definitions[8]['multiple'] = 0;
PluginFieldsContainer::$options[2] = ['name' => 'Redeployed', 'field' => 'redeployed', 'table' => $option['table'], 'pfields_type' => 'date', 'pfields_fields_id' => 2];
PluginFieldsContainer::$options[3] = ['name' => 'Billable', 'field' => 'billable', 'table' => $option['table'], 'pfields_type' => 'yesno', 'pfields_fields_id' => 3];
PluginFieldsContainer::$options[4] = ['name' => 'Start', 'field' => 'start', 'table' => $option['table'], 'pfields_type' => 'date', 'pfields_fields_id' => 4];
foreach ([2 => ['redeployed', 'date'], 3 => ['billable', 'yesno'], 4 => ['start', 'date']] as $id => [$name, $type]) {
    PluginFieldsField::$definitions[$id] = ['id' => $id, 'name' => $name, 'type' => $type, 'is_active' => 1, 'plugin_fields_containers_id' => 1];
}
Config::$values['plugin:assetsync20']['billing_fields'] = json_encode(['swsd' => [
    'enabled' => true, 'status' => 'states_id',
    'redeployed_date' => 'Computer.PluginFieldsComputerdmosasset.redeployed',
    'swsd_billable' => 'Computer.PluginFieldsComputerdmosasset.billable',
    'swsd_billing_start_date' => 'Computer.PluginFieldsComputerdmosasset.start',
]]);
cacheCheck(BillingFieldConfig::workflow('swsd')['enabled'], 'Valid billing config must validate normally.');
PluginFieldsField::$definitions[2]['type'] = 'text';
cacheError(static fn () => BillingFieldConfig::workflow('swsd'), 'type does not match');
PluginFieldsField::$definitions[2]['type'] = 'date';
PluginFieldsField::$definitions[2]['is_active'] = 0;
cacheError(static fn () => BillingFieldConfig::workflow('swsd'), 'inactive');
PluginFieldsField::$definitions[2]['is_active'] = 1;
PluginFieldsField::$definitions[2]['plugin_fields_containers_id'] = 0;
cacheError(static fn () => BillingFieldConfig::workflow('swsd'), 'container id');
PluginFieldsField::$definitions[2]['plugin_fields_containers_id'] = 1;
cacheCheck(BillingFieldConfig::workflow('swsd')['enabled'] && Config::$reads['billing_fields'] === 1, 'Workflow validation must be fresh while raw billing config is reused.');

cacheSetup();
AssetSyncQueue::install();
AssetSyncLink::install();
$seen = [];
$GLOBALS['DB']->afterRequest = static function () use (&$seen, $configOwners, $textKey): void {
    foreach ($configOwners as $owner) {
        cacheRead($owner);
    }
    FieldsText::descriptor('Computer', $textKey);
    $seen[] = GlpiBConnection::find('cache')['name'];
};
$service = new AssetSyncService(new FakeGlpiBClient(), null, static fn (): float => 0.0);
cacheCheck($service->run(1) === 0 && $seen !== [] && array_unique($seen) === ['First'], 'Run-scoped snapshots must be available throughout normal processing.');
foreach ($configOwners as $key => $owner) {
    cacheCheck(Config::$reads[$key] === 1, 'A normal run must read each stable config once: ' . $key);
}
cachesEnded();
Config::$values['plugin:assetsync20']['glpib_connections'] = str_replace('First', 'Second', Config::$values['plugin:assetsync20']['glpib_connections']);
$seen = [];
cacheCheck($service->run(1) === 0 && array_unique($seen) === ['Second'], 'A reused service must see changed config in the next run.');
foreach ($configOwners as $key => $owner) {
    cacheCheck(Config::$reads[$key] === 2, 'Each new run must reread config: ' . $key);
}
cachesEnded();
$GLOBALS['DB']->afterRequest = static function () use ($configOwners, $textKey): void {
    foreach ($configOwners as $owner) {
        cacheRead($owner);
    }
    FieldsText::descriptor('Computer', $textKey);
    throw new RuntimeException('processing failed');
};
cacheError(static fn () => $service->run(1), 'processing failed');
cachesEnded();
cacheCheck((new ReflectionProperty(GlpiBConnection::class, 'httpMetrics'))->getValue() === null, 'HTTP observations must still clear after processing exceptions.');
$GLOBALS['DB']->afterRequest = null;
$GLOBALS['DB']->queryResult = static function (string $sql) {
    if (!str_starts_with($sql, 'SELECT category,')) {
        return null;
    }
    cachesEnded();
    throw new RuntimeException('reporting query failed');
};
Toolbox::$onLog = static function (): void {
    cachesEnded();
    GlpiBConnection::loadAll();
    GlpiBConnection::loadAll();
    throw new RuntimeException('reporting log failed');
};
$reads = Config::$reads['glpib_connections'];
cacheCheck($service->run(1) === 0 && Config::$reads['glpib_connections'] === $reads + 3, 'Reporting must run after cache cleanup; direct reads in reporting stay fresh.');
cachesEnded();
Toolbox::$onLog = null;
$GLOBALS['DB']->queryResult = null;
$GLOBALS['DB']->afterRequest = static function () use ($configOwners): void {
    cachesEnded();
    foreach ($configOwners as $owner) {
        cacheRead($owner);
    }
};
$reads = Config::$reads['glpib_connections'];
$service->processQueue(1);
$service->processQueue(1);
cacheCheck(Config::$reads['glpib_connections'] === $reads + 2, 'Standalone queue processing must not enable caches.');
$reads = Config::$reads['entity_sync_routes'];
$service->enqueueBackfill(1);
$service->enqueueBackfill(1);
cacheCheck(Config::$reads['entity_sync_routes'] >= $reads + 2, 'Standalone backfill must not enable caches.');
cachesEnded();
$mutated = false;
$seen = [];
$GLOBALS['DB']->afterRequest = static function () use (&$mutated, &$seen): void {
    GlpiBConnection::find('cache');
    if (!$mutated) {
        $mutated = true;
        GlpiBConnection::save(['id' => 'cache', 'name' => 'During run', 'active' => true]);
    }
    $seen[] = GlpiBConnection::find('cache')['name'];
};
cacheCheck($service->run(1) === 0 && array_unique($seen) === ['During run'], 'An owning mutation during service processing must invalidate the earlier run snapshot.');
cachesEnded();
cacheCheck(count(Toolbox::$entries) === 5, 'The cache lifecycle must preserve one summary per run, including failure runs.');

echo "Run cache tests passed ($checks checks).\n";
