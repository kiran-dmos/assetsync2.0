<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\FieldsText;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\NativeField;

$checks = 0;
function interopCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) { throw new RuntimeException($message); }
}
function interopError(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException $error) {
        interopCheck(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new LogicException('Expected error: ' . $message);
}

class ComputerType
{
    public static int $detailReads = 0;
    public static array $rows = [4 => ['id' => 4, 'name' => 'Notebook'], 5 => ['id' => 5, 'name' => 'Desktop']];
    public static array $denied = [];
    public array $fields = [];
    public function find(array $criteria): array { return self::$rows; }
    public function getFromDB($id): bool { self::$detailReads++; $this->fields = self::$rows[$id] ?? []; return $this->fields !== []; }
    public function can($id, $right): bool { return !in_array($id, self::$denied, true); }
}

class Entity
{
    public static array $rows = [];
    public array $fields = [];
    public function getFromDB($id): bool { $this->fields = self::$rows[$id] ?? []; return $this->fields !== []; }
}

$typeOption = ['name' => 'Type', 'table' => 'glpi_computertypes', 'field' => 'name',
    'linkfield' => 'computertypes_id', 'datatype' => 'dropdown', 'uid' => 'Computer.ComputerType.name'];
$type = NativeField::fromOption('Computer', $typeOption);
interopCheck($type['field'] === 'computertypes_id' && $type['relation'] === 'ComputerType', 'Type metadata resolves an FK, never native Name.');
$withoutLink = $typeOption;
unset($withoutLink['linkfield']);
interopCheck(NativeField::fromOption('Computer', $withoutLink) === $type, 'Exact enriched relation UID can identify a known FK.');
interopCheck(NativeField::fromOption('Computer', ['table' => 'glpi_computers', 'field' => 'name', 'datatype' => 'itemlink', 'uid' => 'Computer.name', 'massiveaction' => false])['field'] === 'name', 'Native Name itemlink remains writable.');
foreach ([
    ['table' => 'glpi_users', 'field' => 'name', 'uid' => 'Computer.User.name', 'datatype' => 'itemlink'],
    ['table' => 'glpi_computers', 'field' => 'name', 'uid' => 'Anything.name'],
    ['table' => 'glpi_computers', 'field' => 'date_mod', 'datatype' => 'datetime'],
    ['table' => 'glpi_infocoms', 'field' => 'buy_date', 'datatype' => 'date'],
    ['table' => 'glpi_computers', 'field' => 'name', 'joinparams' => ['beforejoin' => ['table' => 'glpi_users']]],
] as $option) {
    interopError(fn () => NativeField::fromOption('Computer', $option), 'Unsupported native');
}
foreach ([
    ['Monitor', 'have_hdmi', 'bool', 'yesno'], ['Printer', 'memory_size', 'integer', 'int'],
    ['Computer', 'last_boot', 'datetime', 'datetime'], ['Phone', 'number_line', 'integer', 'int'],
    ['NetworkEquipment', 'ram', 'number', 'int'],
] as [$itemtype, $field, $datatype, $expected]) {
    interopCheck(NativeField::fromOption($itemtype, ['table' => 'glpi_' . strtolower($itemtype) . 's', 'field' => $field, 'datatype' => $datatype])['type'] === $expected, 'Genuine native scalar metadata is supported.');
}
foreach (['text', 'textarea', 'yesno', 'int', 'date', 'datetime', 'dropdown'] as $a) {
    foreach (['text', 'textarea', 'yesno', 'int', 'date', 'datetime', 'dropdown'] as $b) {
        interopCheck(NativeField::compatible($a, $b) === ($a === $b || (in_array($a, ['text', 'textarea'], true) && in_array($b, ['text', 'textarea'], true))), 'Only matching scalar families and text/textarea are compatible.');
    }
}
interopError(fn () => FieldsText::normalizeValue('int', '9999999999999999999999999999'), 'out of range');
foreach ([-1, '1.5', 'invalid', true] as $value) {
    interopError(fn () => NativeField::referenceId($value), 'Invalid reference');
}
interopCheck(NativeField::labelForId(ComputerType::$rows, 4) === 'Notebook', 'Source ID resolves an exact label.');
interopCheck(NativeField::idForLabel([['id' => 88, 'name' => 'Notebook']], 'Notebook') === 88, 'Destination uses its own ID.');
interopError(fn () => NativeField::idForLabel([['id' => 88, 'name' => 'Notebook'], ['id' => 89, 'name' => 'Notebook']], 'Notebook'), 'duplicated');
ComputerType::$denied = [4];
interopError(fn () => NativeField::labelForId(NativeField::localCatalog($type, 123, [4]), 4), 'not permitted');
ComputerType::$denied = [];
$originalTypes = ComputerType::$rows;
for ($id = 10; $id < 1010; $id++) { ComputerType::$rows[$id] = ['id' => $id, 'name' => 'Other ' . $id]; }
ComputerType::$detailReads = 0;
NativeField::localCatalog($type, 123, [4], ['Desktop']);
interopCheck(ComputerType::$detailReads === 2, 'Local complete catalogs hydrate only source and exact-label candidates.');
ComputerType::$rows = $originalTypes;
$state = NativeField::definitions('Computer')['states_id'];
foreach ([
    ['id' => 1, 'name' => 'Visible?', 'entities_id' => 0, 'is_recursive' => 1],
    ['id' => 1, 'name' => 'Hidden', 'entities_id' => 0, 'is_recursive' => 1, 'is_visible_computer' => 0],
    ['id' => 1, 'name' => 'Other entity', 'entities_id' => 9, 'is_recursive' => 1, 'is_visible_computer' => 1],
] as $row) {
    interopCheck(!NativeField::permitted($row, $state, [2, 1, 0]), 'State requires visibility and target entity scope.');
}
interopCheck(NativeField::permitted(['id' => 1, 'entities_id' => 1, 'is_recursive' => 1, 'is_visible_computer' => 1], $state, [2, 1, 0]), 'Visible recursive ancestor State is allowed.');
foreach ([
    ['entities_id' => 'invalid'], ['entities_id' => '0invalid'], ['entities_id' => -1],
    ['is_recursive' => 'invalid'], ['is_visible_computer' => '1invalid'],
] as $invalid) {
    interopCheck(!NativeField::permitted(array_replace(['id' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'is_visible_computer' => 1], $invalid), $state, [2, 1, 0]), 'Malformed State scope/visibility must not be cast into permission.');
}
Entity::$rows = [2 => ['id' => 2, 'entities_id' => 1], 1 => ['id' => 1, 'entities_id' => 0]];
interopCheck(NativeField::localEntityPath(2) === [2, 1, 0], 'Local ancestry retains valid parent IDs.');
Entity::$rows[2]['entities_id'] = 'invalid';
interopError(fn () => NativeField::localEntityPath(2), 'Invalid reference ID');
Entity::$rows[2]['entities_id'] = 2;
interopError(fn () => NativeField::localEntityPath(2), 'Invalid local entity ancestry');
Entity::$rows = [];

// Deliberately no local generated class or resolver entry for this remote container.
$remoteKey = 'Computer.PluginFieldsComputerremoteonly.notesfield';
$raw = ['table' => 'glpi_plugin_fields_computerremoteonlys', 'field' => 'notesfield', 'pfields_type' => 'textarea', 'pfields_fields_id' => 81];
$definition = ['id' => 81, 'name' => 'notesfield', 'type' => 'textarea', 'is_active' => 1, 'plugin_fields_containers_id' => 91];
$container = ['id' => 91, 'name' => 'remoteonly', 'is_active' => 1, 'itemtypes' => '["Computer"]'];
FakeItemTypes::$classResult = static fn () => throw new LogicException('B must not use the A class resolver');
FakeItemTypes::$tableResult = static fn () => throw new LogicException('B must not use the A table resolver');
$metadata = FieldsText::remoteMetadataFromOption('Computer', $raw, 181, $definition, $container);
interopCheck(!class_exists($metadata['class']) && $metadata['key'] === $remoteKey && $metadata['table'] === $raw['table'], 'Remote-only container resolves independently.');
interopError(fn () => FieldsText::remoteMetadataFromOption('Computer', $raw, 181, $definition, array_replace($container, ['name' => 'different'])), 'identity');
interopError(fn () => FieldsText::remoteKey('Computer', $raw + ['uid' => 'Computer.name']), 'UID');
FakeItemTypes::$classResult = FakeItemTypes::$tableResult = null;

function interopSetup(string $a, string $b, string $authority, bool $create = false): array
{
    global $typeOption;
    $GLOBALS['DB'] = new FakeDB();
    Config::$values = [];
    Computer::$allowUpdate = true;
    Computer::$ignoreUpdate = false;
    Search::$nativeOptions = [40 => $typeOption];
    PluginFieldsContainer::$options = [801 => [
        'name' => 'Local type', 'table' => 'glpi_plugin_fields_departmentfielddropdowns', 'field' => 'completename',
        'linkfield' => 'plugin_fields_departmentfielddropdowns_id', 'pfields_type' => 'dropdown', 'pfields_fields_id' => 81,
        'joinparams' => ['beforejoin' => ['table' => 'glpi_plugin_fields_computerdmosassets']],
    ]];
    PluginFieldsField::$definitions[81] = ['id' => 81, 'name' => 'departmentfield', 'type' => 'dropdown', 'is_active' => 1, 'plugin_fields_containers_id' => 1];
    PluginFieldsDepartmentfieldDropdown::$rows = [4 => ['id' => 4, 'name' => 'Notebook'], 5 => ['id' => 5, 'name' => 'Desktop']];
    PluginFieldsComputerdmosasset::$rows = [101 => ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1, 'plugin_fields_departmentfielddropdowns_id' => 4]];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    GlpiBConnection::save(['id' => 'interop', 'active' => true]);
    EntitySyncRoute::save(['id' => 'route', 'glpi_b_connection_id' => 'interop', 'glpi_a_source_entity_id' => '1',
        'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
    $GLOBALS['DB']->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0,
        'name' => 'A', 'serial' => 'INTEROP', 'computertypes_id' => 4, 'date_mod' => '2026-10-02 00:00:00']);
    \saveTestMappings('interop', 'Computer', [$a => ['glpi_b_field_key' => $b, 'source_of_truth' => $authority]]);
    $remote = new FakeGlpiBClient();
    $remote->nativeOptions = [140 => $typeOption];
    $remote->nativeCatalogs = ['computertypes_id' => [['id' => 88, 'name' => 'Notebook'], ['id' => 89, 'name' => 'Desktop']]];
    $remote->customDropdownOptions[$b] = $remote->nativeCatalogs['computertypes_id'];
    if (!$create) {
        $remote->records['interop:Computer'][201] = ['id' => 201, 'entities_id' => 100, 'is_deleted' => 0,
            'name' => 'B', 'serial' => 'INTEROP', 'computertypes_id' => 89, 'date_mod' => '2026-10-01 00:00:00'];
        $remote->customRecords['interop:Computer'][201][$b] = 89;
        AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'interop',
            'route_id' => 'route', 'remote_items_id' => 201, 'status' => AssetSyncLink::STATUS_SYNCED]);
    }
    $service = new AssetSyncService($remote);
    interopCheck($service->queueAssetIfNeeded('Computer', 101, 'interop'), 'Fixture queues one asset.');
    return [$service, $remote];
}
function interopStatus(): string { return $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status']; }

$localCustom = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_departmentfielddropdowns_id';
$remoteCustom = 'Computer.PluginFieldsComputerremoteonly.plugin_fields_categoryfielddropdowns_id';
foreach (['computertypes_id', $localCustom] as $a) {
    foreach (['computertypes_id', $remoteCustom] as $b) {
        foreach (['glpi_a', 'glpi_b'] as $authority) {
            [$service, $remote] = interopSetup($a, $b, $authority);
            $service->processQueue(1);
            interopCheck(interopStatus() === 'done', 'Every native/custom endpoint combination synchronizes in either direction.');
            if ($authority === 'glpi_a') {
                $stored = FieldsText::isCustom($b) ? $remote->customRecords['interop:Computer'][201][$b] : $remote->records['interop:Computer'][201][$b];
                interopCheck($stored === 88, 'Outbound reference writes remote ID 88, never local ID 4.');
            } else {
                $stored = FieldsText::isCustom($a) ? PluginFieldsComputerdmosasset::$rows[101]['plugin_fields_departmentfielddropdowns_id'] : $GLOBALS['DB']->tables['glpi_computers'][0][$a];
                interopCheck($stored === 5, 'Inbound reference writes local ID 5, never remote ID 89.');
            }
        }
    }
}
foreach (['computertypes_id', $remoteCustom] as $b) {
    [$service, $remote] = interopSetup('computertypes_id', $b, 'glpi_a', true);
    if (FieldsText::isCustom($b)) { $remote->customDropdownOptions[$b] = []; }
    else { $remote->nativeCatalogs[$b] = []; }
    $service->processQueue(1);
    interopCheck(interopStatus() === 'blocked' && $remote->records === [], 'Missing native/custom destination options block before creating a base asset.');
}
foreach ([false, true] as $ignored) {
    [$service, $remote] = interopSetup('computertypes_id', 'computertypes_id', 'glpi_b');
    Computer::$allowUpdate = $ignored;
    Computer::$ignoreUpdate = $ignored;
    $service->processQueue(1);
    interopCheck(interopStatus() === 'blocked' && $GLOBALS['DB']->tables['glpi_computers'][0]['computertypes_id'] === 4, 'Denied or ignored local native writes cannot report success.');
}
[$service, $remote] = interopSetup('computertypes_id', 'computertypes_id', 'glpi_a', true);
$remote->createFailure = ['success' => false, 'transient' => true, 'message' => 'Readback transport failure'];
$service->processQueue(1);
interopCheck(interopStatus() === 'retry' && (int) AssetSyncLink::find('Computer', 101, 'interop')['remote_items_id'] === 1000, 'A failed create readback retains its remote ID for retry.');
interopCheck(count($remote->records['interop:Computer']) === 1, 'Only one remote native asset was created.');
$remote->createFailure = null;
$GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['available_at' => '2000-01-01 00:00:00', 'finished_at' => '2000-01-01 00:00:00'], ['id' => 1]);
$service->processQueue(1);
interopCheck(interopStatus() === 'done' && count($remote->records['interop:Computer']) === 1, 'Retry uses the retained remote ID without a duplicate create.');

[$service, $remote] = interopSetup('computertypes_id', 'computertypes_id', 'glpi_a', true);
AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'interop', 'route_id' => 'route', 'status' => AssetSyncLink::STATUS_BLOCKED_CONFIGURATION]);
$GLOBALS['DB']->beforeUpdate = static fn (string $table): bool => $table !== AssetSyncLink::TABLE;
$service->processQueue(1);
interopCheck(interopStatus() === 'retry' && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['remote_items_id'] === 1000, 'A failed link save must retain the created B ID in the fenced retry row.');
$GLOBALS['DB']->beforeUpdate = null;
$GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['available_at' => '2000-01-01 00:00:00', 'finished_at' => '2000-01-01 00:00:00'], ['id' => 1]);
$remote->requests = [];
$service->processQueue(1);
interopCheck(interopStatus() === 'done' && count($remote->records['interop:Computer']) === 1, 'Retry with an empty stored link uses the retained queue B ID.');
interopCheck(!array_intersect(['createItem', 'searchBySerial'], array_column($remote->requests, 'method')), 'Known created B ID must not be rediscovered or recreated after link failure.');

foreach ([0, 42] as $number) {
    [$service, $remote] = interopSetup($localCustom, $remoteCustom, 'both', true);
    $numberA = 'Computer.PluginFieldsComputerdmosasset.countfield';
    $numberB = 'Computer.PluginFieldsComputerremoteonly.countfield';
    PluginFieldsContainer::$options = [801 => ['name' => 'Count', 'field' => 'countfield', 'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => 'number', 'pfields_fields_id' => 81]];
    PluginFieldsField::$definitions[81] = ['id' => 81, 'name' => 'countfield', 'type' => 'number', 'is_active' => 1, 'plugin_fields_containers_id' => 1];
    PluginFieldsComputerdmosasset::$rows[101]['countfield'] = $number;
    \saveTestMappings('interop', 'Computer', [$numberA => ['glpi_b_field_key' => $numberB, 'source_of_truth' => 'both']]);
    $remote->customRequestResult = static function (int $id, array $keys, array $changes) use ($number, $numberB): ?array {
        interopCheck($id === 0 && $changes === [$numberB => $number], 'Both numeric creation preflight preserves integer zero and nonzero values.');
        return ['success' => false, 'transient' => false, 'message' => 'Injected preflight rejection'];
    };
    $service->processQueue(1);
    interopCheck(interopStatus() === 'blocked' && $remote->records === [], 'Numeric Both preflight failure must resolve the job without a TypeError or base creation.');
}

echo "Interoperability tests passed ($checks checks).\n";
