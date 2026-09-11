<?php

declare(strict_types=1);

define('GLPI_VERSION', '11.0.7');

class Config
{
    public static array $values = [];

    public static function getConfigurationValues($context, array $names = []): array
    {
        $values = self::$values[$context] ?? [];

        if ($names === []) {
            return $values;
        }

        return array_intersect_key($values, array_flip($names));
    }

    public static function setConfigurationValues($context, array $values = []): void
    {
        self::$values[$context] = array_merge(self::$values[$context] ?? [], $values);
    }

    public static function deleteConfigurationValues($context, array $values = []): void
    {
        foreach ($values as $name) {
            unset(self::$values[$context][$name]);
        }
    }
}

class GLPIKey
{
    public function encrypt(string $string, ?string $key = null): string
    {
        return 'encrypted:' . base64_encode($string);
    }

    public function decrypt(?string $string, ?string $key = null): ?string
    {
        if ($string === null || !str_starts_with($string, 'encrypted:')) {
            return $string;
        }

        $decoded = base64_decode(substr($string, strlen('encrypted:')), true);

        return is_string($decoded) ? $decoded : $string;
    }
}

class DBConnection
{
    public static function getDefaultCharset(): string
    {
        return 'utf8mb4';
    }

    public static function getDefaultCollation(): string
    {
        return 'utf8mb4_unicode_ci';
    }

    public static function getDefaultPrimaryKeySignOption(): string
    {
        return 'UNSIGNED';
    }
}

class CronTask
{
    public const STATE_DISABLE = 0;
    public const STATE_WAITING = 1;

    /** @var list<array<string,mixed>> */
    public static array $registered = [];
    public array $fields = [];
    public int $volume = 0;

    public function __construct(array $fields = [])
    {
        $this->fields = $fields;
    }

    public static function register($itemtype, $name, $frequency, $options = []): bool
    {
        self::$registered[] = [
            'itemtype'  => $itemtype,
            'name'      => $name,
            'frequency' => $frequency,
            'options'   => $options,
        ];

        return true;
    }

    public static function unregister($plugin): bool
    {
        self::$registered = array_values(array_filter(
            self::$registered,
            static fn (array $task): bool => !str_contains((string) $task['itemtype'], '\\' . $plugin . '\\')
        ));

        return true;
    }

    public function addVolume($volume): void
    {
        $this->volume += (int) $volume;
    }
}

final class FakeDBResult implements IteratorAggregate, Countable
{
    /** @var list<array<string,mixed>> */
    private array $rows;

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}

final class FakeDB
{
    /** @var array<string,list<array<string,mixed>>> */
    public array $tables = [
        'glpi_entities' => [],
        'glpi_computers' => [],
    ];

    /** @var array<string,int> */
    private array $nextIds = [];

    public function tableExists(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    public function doQuery(string $sql): bool
    {
        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $sql, $match)) {
            $this->tables[$match[1]] ??= [];
            return true;
        }

        if (preg_match('/DROP TABLE IF EXISTS `([^`]+)`/i', $sql, $match)) {
            unset($this->tables[$match[1]]);
            return true;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function insert(string $table, array $params): bool
    {
        $this->tables[$table] ??= [];

        if (!isset($params['id'])) {
            $params['id'] = $this->nextId($table);
        } else {
            $this->nextIds[$table] = max($this->nextIds[$table] ?? 1, (int) $params['id'] + 1);
        }

        $this->tables[$table][] = $params;

        return true;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $params, array $where): bool
    {
        foreach ($this->tables[$table] ?? [] as $index => $row) {
            if ($this->rowMatches($row, $where)) {
                $this->tables[$table][$index] = array_merge($row, $params);
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $query
     */
    public function request(array $query): FakeDBResult
    {
        $table = (string) ($query['FROM'] ?? '');
        $rows = array_values($this->tables[$table] ?? []);
        $where = is_array($query['WHERE'] ?? null) ? $query['WHERE'] : [];
        $filtered = [];

        foreach ($rows as $row) {
            if ($this->rowMatches($row, $where)) {
                $filtered[] = $row;
            }
        }

        if (isset($query['ORDER'])) {
            $filtered = $this->sortRows($filtered, (string) $query['ORDER']);
        }

        if (isset($query['LIMIT'])) {
            $filtered = array_slice($filtered, 0, (int) $query['LIMIT']);
        }

        return new FakeDBResult($filtered);
    }

    /**
     * @param array<string,mixed> $where
     */
    public function firstRow(string $table, array $where): ?array
    {
        foreach ($this->tables[$table] ?? [] as $row) {
            if ($this->rowMatches($row, $where)) {
                return $row;
            }
        }

        return null;
    }

    private function nextId(string $table): int
    {
        $nextId = $this->nextIds[$table] ?? 1;
        $this->nextIds[$table] = $nextId + 1;

        return $nextId;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $where
     */
    private function rowMatches(array $row, array $where): bool
    {
        foreach ($where as $field => $expected) {
            $actual = $this->rowValue($row, (string) $field);

            if (
                is_array($expected)
                && isset($expected[0])
                && is_string($expected[0])
                && in_array($expected[0], ['>', '<', '<=', '>=', '!=', '<>'], true)
            ) {
                if (!$this->compare($actual, $expected[0], $expected[1] ?? null)) {
                    return false;
                }
                continue;
            }

            if (is_array($expected)) {
                if (!in_array($actual, $expected, false) && !in_array((int) $actual, $expected, false)) {
                    return false;
                }
                continue;
            }

            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowValue(array $row, string $field)
    {
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            $field = (string) end($parts);
        }

        return $row[$field] ?? null;
    }

    private function compare($actual, string $operator, $expected): bool
    {
        return match ($operator) {
            '>' => (int) $actual > (int) $expected,
            '<' => (string) $actual < (string) $expected,
            '<=' => (string) $actual <= (string) $expected,
            '>=' => (string) $actual >= (string) $expected,
            default => (string) $actual === (string) $expected,
        };
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function sortRows(array $rows, string $order): array
    {
        $parts = preg_split('/\s+/', trim($order));
        $field = (string) ($parts[0] ?? 'id');
        $descending = strtolower((string) ($parts[1] ?? 'asc')) === 'desc';

        usort($rows, static function (array $left, array $right) use ($field, $descending): int {
            $result = ((int) ($left[$field] ?? 0)) <=> ((int) ($right[$field] ?? 0));

            return $descending ? -$result : $result;
        });

        return $rows;
    }
}

class Computer
{
    /** @var array<string,mixed> */
    public array $fields = [];

    public static function getTable(): string
    {
        return 'glpi_computers';
    }

    public function getFromDB($id): bool
    {
        global $DB;

        $row = $DB->firstRow(self::getTable(), ['id' => (int) $id]);
        if ($row === null) {
            return false;
        }

        $this->fields = $row;

        return true;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function update(array $input): bool
    {
        global $DB;

        $id = (int) ($input['id'] ?? 0);
        unset($input['id']);

        if ($id <= 0) {
            return false;
        }

        return $DB->update(self::getTable(), $input, ['id' => $id]);
    }
}

final class FakeGlpiBClient
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $records = [];
    private int $nextId = 1000;

    /**
     * @param array{id:string} $connection
     * @return array{success:bool,message:string,items:list<array{id:int>>,total_count:int,transient:bool}
     */
    public function searchBySerial(array $connection, string $itemtype, string $serial): array
    {
        $matches = [];

        foreach ($this->records[$this->key($connection, $itemtype)] ?? [] as $id => $record) {
            if ((string) ($record['serial'] ?? '') === $serial) {
                $matches[] = ['id' => $id];
            }
        }

        return [
            'success' => true,
            'message' => 'searched',
            'items' => $matches,
            'total_count' => count($matches),
            'transient' => false,
        ];
    }

    /**
     * @param array{id:string} $connection
     * @return array{success:bool,message:string,item:array<string,mixed>,missing:bool,transient:bool}
     */
    public function getItem(array $connection, string $itemtype, int $itemsId): array
    {
        $record = $this->records[$this->key($connection, $itemtype)][$itemsId] ?? null;

        return [
            'success' => $record !== null,
            'message' => $record !== null ? 'loaded' : 'missing',
            'item' => $record ?? [],
            'missing' => $record === null,
            'transient' => false,
        ];
    }

    /**
     * @param array{id:string} $connection
     * @param array<string,mixed> $input
     * @return array{success:bool,message:string,id:int,transient:bool}
     */
    public function createItem(array $connection, string $itemtype, array $input): array
    {
        $id = $this->nextId++;
        $this->records[$this->key($connection, $itemtype)][$id] = array_merge($input, ['id' => $id]);

        return [
            'success' => true,
            'message' => 'created',
            'id' => $id,
            'transient' => false,
        ];
    }

    /**
     * @param array{id:string} $connection
     * @param array<string,mixed> $input
     * @return array{success:bool,message:string,transient:bool}
     */
    public function updateItem(array $connection, string $itemtype, int $itemsId, array $input): array
    {
        $key = $this->key($connection, $itemtype);
        if (!isset($this->records[$key][$itemsId])) {
            return [
                'success' => false,
                'message' => 'missing',
                'transient' => false,
            ];
        }

        $this->records[$key][$itemsId] = array_merge($this->records[$key][$itemsId], $input);

        return [
            'success' => true,
            'message' => 'updated',
            'transient' => false,
        ];
    }

    /**
     * @param array{id:string} $connection
     */
    private function key(array $connection, string $itemtype): string
    {
        return $connection['id'] . ':' . $itemtype;
    }
}

$DB = new FakeDB();

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/hook.php';

$metadata = plugin_version_assetsync20();

$expectations = [
    'id' => 'assetsync20',
    'name' => 'AssetSync2.0',
    'version' => '0.1.5',
];

foreach ($expectations as $key => $expected) {
    if (($metadata[$key] ?? null) !== $expected) {
        throw new RuntimeException(sprintf(
            'Expected metadata %s to be %s, got %s.',
            $key,
            $expected,
            var_export($metadata[$key] ?? null, true)
        ));
    }
}

if (!plugin_assetsync20_check_prerequisites()) {
    throw new RuntimeException('Prerequisite check failed.');
}

if (!plugin_assetsync20_check_config()) {
    throw new RuntimeException('Configuration check failed.');
}

if (!plugin_assetsync20_install()) {
    throw new RuntimeException('Install hook failed.');
}

$menu = \GlpiPlugin\Assetsync20\Menu::getMenuContent();

if (($menu['title'] ?? null) !== 'AssetSync2.0') {
    throw new RuntimeException('Menu title is incorrect.');
}

if (($menu['icon'] ?? null) !== 'ti ti-refresh') {
    throw new RuntimeException('Menu icon is incorrect.');
}

if (($menu['links']['fieldmapping'] ?? null) !== '/plugins/assetsync20/front/fieldmapping.php') {
    throw new RuntimeException('Field Mapping menu link is incorrect.');
}

if (array_key_exists('entitysyncroutes', $menu['links'] ?? [])) {
    throw new RuntimeException('Entity Sync Routes should not be exposed as a top-level menu link.');
}

if (\GlpiPlugin\Assetsync20\Menu::entitySyncRoutesUrl() !== '/plugins/assetsync20/front/entitysyncroutes.php') {
    throw new RuntimeException('Entity Sync Routes internal URL is incorrect.');
}

$installedValues = Config::getConfigurationValues('plugin:assetsync20');

if (!array_key_exists('entity_sync_routes', $installedValues)) {
    throw new RuntimeException('Entity sync routes config key should be installed.');
}

if (!$DB->tableExists(\GlpiPlugin\Assetsync20\AssetSyncLink::TABLE)) {
    throw new RuntimeException('Asset sync link table should be installed.');
}

if (!$DB->tableExists(\GlpiPlugin\Assetsync20\AssetSyncQueue::TABLE)) {
    throw new RuntimeException('Asset sync queue table should be installed.');
}

if (CronTask::$registered === []) {
    throw new RuntimeException('Asset sync automatic action should be registered.');
}

$registeredTask = CronTask::$registered[0];
if (
    $registeredTask['itemtype'] !== \GlpiPlugin\Assetsync20\AssetSyncCron::class
    || $registeredTask['name'] !== \GlpiPlugin\Assetsync20\AssetSyncCron::TASK_NAME
    || ($registeredTask['options']['param'] ?? null) !== 10
    || ($registeredTask['options']['state'] ?? null) !== CronTask::STATE_WAITING
) {
    throw new RuntimeException('Asset sync automatic action registration is incorrect.');
}

$connections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if ($connections !== []) {
    throw new RuntimeException('GLPI B connections should be empty by default.');
}

$connection = \GlpiPlugin\Assetsync20\GlpiBConnection::load();
$test = \GlpiPlugin\Assetsync20\GlpiBConnection::test($connection);

if ($test['success'] !== false || $test['message'] !== 'Base URL, app token, and user token are required.') {
    throw new RuntimeException('Empty GLPI B connection test result is incorrect.');
}

$connectionFromInput = \GlpiPlugin\Assetsync20\GlpiBConnection::fromInput([
    'name' => ' GLPI B ',
    'base_url' => ' https://glpi-b.example.com/apirest.php/ ',
    'active' => '1',
]);

if ($connectionFromInput['name'] !== 'GLPI B') {
    throw new RuntimeException('GLPI B connection name was not normalized.');
}

if ($connectionFromInput['base_url'] !== 'https://glpi-b.example.com') {
    throw new RuntimeException('GLPI B base URL was not normalized.');
}

if ($connectionFromInput['active'] !== true) {
    throw new RuntimeException('GLPI B active flag was not normalized.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'id' => 'production',
    'name' => 'Production GLPI B',
    'base_url' => 'https://glpi-b.example.com/apirest.php',
    'app_token' => 'app-secret',
    'user_token' => 'user-secret',
    'active' => '1',
]);

$savedRawValues = Config::getConfigurationValues('plugin:assetsync20');
$savedRawConnections = json_decode((string) ($savedRawValues['glpib_connections'] ?? ''), true);

if (!is_array($savedRawConnections) || count($savedRawConnections) !== 1) {
    throw new RuntimeException('Saved GLPI B connections should be stored as a JSON list.');
}

if (($savedRawConnections[0]['app_token'] ?? '') === 'app-secret') {
    throw new RuntimeException('Saved app token should not be stored in plain text.');
}

$savedConnection = \GlpiPlugin\Assetsync20\GlpiBConnection::find('production');

if ($savedConnection === null) {
    throw new RuntimeException('Saved GLPI B connection could not be found.');
}

if ($savedConnection['base_url'] !== 'https://glpi-b.example.com') {
    throw new RuntimeException('Saved GLPI B base URL was not normalized.');
}

if ($savedConnection['app_token'] !== 'app-secret' || $savedConnection['user_token'] !== 'user-secret') {
    throw new RuntimeException('Saved GLPI B tokens could not be loaded.');
}

$emptyFieldFetch = \GlpiPlugin\Assetsync20\GlpiBConnection::fetchNativeFields($connection, 'Computer');

if ($emptyFieldFetch['success'] !== false || $emptyFieldFetch['message'] !== 'Base URL, app token, and user token are required.') {
    throw new RuntimeException('Empty GLPI B field fetch result is incorrect.');
}

$fieldsFromSearchOptions = new ReflectionMethod(\GlpiPlugin\Assetsync20\GlpiBConnection::class, 'fieldsFromSearchOptions');
$fieldsFromSearchOptions->setAccessible(true);
$glpiBFields = $fieldsFromSearchOptions->invoke(null, ['common' => [
    'name' => 'Characteristics',
], 1 => [
    'name' => 'Name',
    'field' => 'name',
    'table' => 'glpi_computers',
    'uid' => 'Computer.name',
], 2 => [
    'name' => '<strong>Serial number</strong>',
    'field' => 'serial',
    'table' => 'glpi_computers',
], 'notes' => [
    'name' => 'Notes',
    'field' => 'comment',
    'uid' => 'Computer.comment',
]]);

if ($glpiBFields !== [
    [
        'key' => 'Computer.name',
        'id' => '1',
        'uid' => 'Computer.name',
        'label' => 'Name',
    ],
    [
        'key' => '2',
        'id' => '2',
        'uid' => '',
        'label' => 'Serial number',
    ],
    [
        'key' => 'Computer.comment',
        'id' => '',
        'uid' => 'Computer.comment',
        'label' => 'Notes',
    ],
]) {
    throw new RuntimeException('GLPI B search options were not normalized correctly.');
}

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_b',
    ],
    'serial' => [
        'glpi_b_field_key' => '2',
        'source_of_truth' => 'both',
    ],
    'invalid_field' => [
        'glpi_b_field_key' => 'Computer.comment',
        'source_of_truth' => 'glpi_a',
    ],
    'comment' => [
        'glpi_b_field_key' => 'Computer.comment',
        'source_of_truth' => 'invalid_source',
    ],
    'otherserial' => [
        'glpi_b_field_key' => 'missing',
        'source_of_truth' => 'glpi_a',
    ],
], $glpiBFields);

$savedMappings = \GlpiPlugin\Assetsync20\FieldMapping::load('production', 'Computer');

if ($savedMappings !== [
    'name' => [
        'glpi_a_field_key' => 'name',
        'glpi_b_field_key' => 'Computer.name',
        'glpi_b_field_id' => '1',
        'glpi_b_field_uid' => 'Computer.name',
        'glpi_b_field_label' => 'Name',
        'source_of_truth' => 'glpi_b',
    ],
    'serial' => [
        'glpi_a_field_key' => 'serial',
        'glpi_b_field_key' => '2',
        'glpi_b_field_id' => '2',
        'glpi_b_field_uid' => '',
        'glpi_b_field_label' => 'Serial number',
        'source_of_truth' => 'both',
    ],
]) {
    throw new RuntimeException('Field mappings should save valid GLPI A fields, GLPI B fields, and source selections only.');
}

Config::setConfigurationValues('plugin:assetsync20', [
    'field_mappings' => json_encode([
        'production' => [
            'Computer' => [
                'name' => 'glpi_b',
                'serial' => 'both',
                'invalid_field' => 'glpi_a',
                'comment' => 'invalid_source',
            ],
        ],
    ], JSON_THROW_ON_ERROR),
]);

$legacyMappings = \GlpiPlugin\Assetsync20\FieldMapping::load('production', 'Computer');

if ($legacyMappings !== [
    'name' => [
        'glpi_a_field_key' => 'name',
        'glpi_b_field_key' => '',
        'glpi_b_field_id' => '',
        'glpi_b_field_uid' => '',
        'glpi_b_field_label' => '',
        'source_of_truth' => 'glpi_b',
    ],
    'serial' => [
        'glpi_a_field_key' => 'serial',
        'glpi_b_field_key' => '',
        'glpi_b_field_id' => '',
        'glpi_b_field_uid' => '',
        'glpi_b_field_label' => '',
        'source_of_truth' => 'both',
    ],
]) {
    throw new RuntimeException('Legacy field mappings should load as normalized records.');
}

$assetTypes = \GlpiPlugin\Assetsync20\FieldMapping::assetTypes();

if (($assetTypes['NetworkEquipment'] ?? null) !== 'Network Equipment') {
    throw new RuntimeException('Network equipment asset type label is incorrect.');
}

$sourceOptions = \GlpiPlugin\Assetsync20\FieldMapping::sourceOptions();

if (($sourceOptions['both'] ?? null) !== 'Both') {
    throw new RuntimeException('Both source option is missing.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'id' => 'production',
    'name' => 'Renamed GLPI B',
    'base_url' => 'https://glpi-b.example.com',
    'active' => '1',
]);

$savedConnection = \GlpiPlugin\Assetsync20\GlpiBConnection::find('production');

if ($savedConnection === null) {
    throw new RuntimeException('Edited GLPI B connection could not be found.');
}

if ($savedConnection['name'] !== 'Renamed GLPI B') {
    throw new RuntimeException('Saved GLPI B connection name was not updated.');
}

if ($savedConnection['app_token'] !== 'app-secret' || $savedConnection['user_token'] !== 'user-secret') {
    throw new RuntimeException('Blank token fields should keep saved GLPI B tokens.');
}

$savedConnections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if (count($savedConnections) !== 1) {
    throw new RuntimeException('Editing an existing GLPI B connection should not create a duplicate.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'id' => 'staging',
    'name' => 'Staging GLPI B',
    'base_url' => 'https://staging-glpi-b.example.com',
    'app_token' => 'staging-app-secret',
    'user_token' => 'staging-user-secret',
    'active' => '1',
]);

$savedConnections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if (count($savedConnections) !== 2) {
    throw new RuntimeException('Multiple GLPI B connections should be saved.');
}

$activeCount = 0;
foreach ($savedConnections as $savedConnection) {
    if ($savedConnection['active']) {
        $activeCount++;
    }
}

if ($activeCount !== 2) {
    throw new RuntimeException('Multiple GLPI B connections should be allowed to be active.');
}

$routeFromInput = \GlpiPlugin\Assetsync20\EntitySyncRoute::fromInput([
    'id' => ' normalized-route ',
    'name' => ' Warehouse route ',
    'glpi_b_connection_id' => ' production ',
    'glpi_a_source_entity_id' => ' 10 ',
    'glpi_a_source_entity_name' => ' <b>Parent Entity</b> ',
    'glpi_b_target_entity_id' => ' 100 ',
    'glpi_b_target_entity_name' => ' <i>Target Entity</i> ',
    'asset_types' => ['Computer', 'InvalidType', 'Printer'],
    'include_child_entities' => '1',
    'active' => '1',
]);

if ($routeFromInput !== [
    'id' => 'normalized-route',
    'name' => 'Warehouse route',
    'glpi_b_connection_id' => 'production',
    'glpi_a_source_entity_id' => '10',
    'glpi_a_source_entity_name' => 'Parent Entity',
    'glpi_b_target_entity_id' => '100',
    'glpi_b_target_entity_name' => 'Target Entity',
    'asset_types' => ['Computer', 'Printer'],
    'include_child_entities' => true,
    'active' => true,
]) {
    throw new RuntimeException('Entity sync route input was not normalized.');
}

\GlpiPlugin\Assetsync20\EntitySyncRoute::save([
    'id' => 'prod-parent',
    'name' => 'Production parent',
    'glpi_b_connection_id' => 'production',
    'glpi_a_source_entity_id' => '10',
    'glpi_a_source_entity_name' => 'Parent Entity',
    'glpi_b_target_entity_id' => '100',
    'glpi_b_target_entity_name' => 'Production Target',
    'asset_types' => ['Computer', 'Printer'],
    'include_child_entities' => '1',
    'active' => '1',
]);

\GlpiPlugin\Assetsync20\EntitySyncRoute::save([
    'id' => 'prod-child',
    'name' => 'Production child',
    'glpi_b_connection_id' => 'production',
    'glpi_a_source_entity_id' => '20',
    'glpi_a_source_entity_name' => 'Child Entity',
    'glpi_b_target_entity_id' => '200',
    'glpi_b_target_entity_name' => 'Production Child Target',
    'asset_types' => ['Computer'],
    'include_child_entities' => '',
    'active' => '1',
]);

\GlpiPlugin\Assetsync20\EntitySyncRoute::save([
    'id' => 'staging-parent',
    'name' => 'Staging parent',
    'glpi_b_connection_id' => 'staging',
    'glpi_a_source_entity_id' => '10',
    'glpi_a_source_entity_name' => 'Parent Entity',
    'glpi_b_target_entity_id' => '300',
    'glpi_b_target_entity_name' => 'Staging Target',
    'asset_types' => ['Computer'],
    'include_child_entities' => '1',
    'active' => '1',
]);

\GlpiPlugin\Assetsync20\EntitySyncRoute::save([
    'id' => 'disabled-staging-child',
    'name' => 'Disabled staging child',
    'glpi_b_connection_id' => 'staging',
    'glpi_a_source_entity_id' => '20',
    'glpi_a_source_entity_name' => 'Child Entity',
    'glpi_b_target_entity_id' => '400',
    'glpi_b_target_entity_name' => 'Disabled Target',
    'asset_types' => ['Computer'],
    'include_child_entities' => '',
    'active' => '',
]);

$savedRoutes = \GlpiPlugin\Assetsync20\EntitySyncRoute::loadAll();

if (count($savedRoutes) !== 4) {
    throw new RuntimeException('Entity sync routes should be saved as multiple records.');
}

$savedRoute = \GlpiPlugin\Assetsync20\EntitySyncRoute::find('prod-parent');

if ($savedRoute === null || $savedRoute['glpi_b_target_entity_id'] !== '100') {
    throw new RuntimeException('Saved entity sync route could not be found.');
}

$matchResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Computer', '20', ['1', '10', '20']);
$matchedRouteIds = [];

foreach ($matchResult['matches'] as $match) {
    $matchedRouteIds[$match['glpi_b_connection_id']] = $match['route']['id'];
}

if ($matchResult['conflicts'] !== []) {
    throw new RuntimeException('Non-conflicting entity route match should not report conflicts.');
}

if ($matchedRouteIds !== [
    'production' => 'prod-child',
    'staging' => 'staging-parent',
]) {
    throw new RuntimeException('Entity route matching should return the deepest route per GLPI B connection.');
}

$productionOnlyResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Computer', '20', ['1', '10', '20'], 'production');

if (count($productionOnlyResult['matches']) !== 1 || $productionOnlyResult['matches'][0]['route']['id'] !== 'prod-child') {
    throw new RuntimeException('Entity route matching should filter by GLPI B connection id.');
}

$printerResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Printer', '20', ['1', '10', '20']);

if (count($printerResult['matches']) !== 1 || $printerResult['matches'][0]['route']['id'] !== 'prod-parent') {
    throw new RuntimeException('Entity route matching should require the selected asset type.');
}

$phoneResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Phone', '20', ['1', '10', '20']);

if ($phoneResult['matches'] !== []) {
    throw new RuntimeException('Entity route matching should ignore routes for other asset types.');
}

$grandchildResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Computer', '21', ['1', '10', '20', '21'], 'production');

if (count($grandchildResult['matches']) !== 1 || $grandchildResult['matches'][0]['route']['id'] !== 'prod-parent') {
    throw new RuntimeException('Entity route matching should not include children unless the route allows it.');
}

$exactParentResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Computer', '10', ['1', '10']);

if (count($exactParentResult['matches']) !== 2) {
    throw new RuntimeException('Exact entity route matching should work for each GLPI B connection.');
}

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_a',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
        'source_of_truth' => 'both',
    ],
    'otherserial' => [
        'glpi_b_field_key' => 'Computer.otherserial',
        'source_of_truth' => 'both',
    ],
    'comment' => [
        'glpi_b_field_key' => 'Computer.comment',
        'source_of_truth' => 'glpi_a',
    ],
    'locations_id' => [
        'glpi_b_field_key' => 'Computer.locations_id',
        'source_of_truth' => 'glpi_a',
    ],
], [
    [
        'key' => 'Computer.name',
        'id' => '1',
        'uid' => 'Computer.name',
        'label' => 'Name',
    ],
    [
        'key' => 'Computer.serial',
        'id' => '5',
        'uid' => 'Computer.serial',
        'label' => 'Serial number',
    ],
    [
        'key' => 'Computer.otherserial',
        'id' => '6',
        'uid' => 'Computer.otherserial',
        'label' => 'Inventory number',
    ],
    [
        'key' => 'Computer.comment',
        'id' => '16',
        'uid' => 'Computer.comment',
        'label' => 'Comments',
    ],
]);

$syncMappings = \GlpiPlugin\Assetsync20\FieldMapping::syncMappings('production', 'Computer');

if ($syncMappings !== [
    [
        'glpi_a_field' => 'name',
        'glpi_b_field' => 'name',
        'source_of_truth' => 'glpi_a',
    ],
    [
        'glpi_a_field' => 'serial',
        'glpi_b_field' => 'serial',
        'source_of_truth' => 'both',
    ],
    [
        'glpi_a_field' => 'otherserial',
        'glpi_b_field' => 'otherserial',
        'source_of_truth' => 'both',
    ],
    [
        'glpi_a_field' => 'comment',
        'glpi_b_field' => 'comment',
        'source_of_truth' => 'glpi_a',
    ],
]) {
    throw new RuntimeException('Field mappings should resolve only safe scalar sync fields.');
}

$DB->insert('glpi_entities', ['id' => 1, 'entities_id' => 0, 'name' => 'Root child']);
$DB->insert('glpi_entities', ['id' => 10, 'entities_id' => 1, 'name' => 'Parent Entity']);
$DB->insert('glpi_entities', ['id' => 20, 'entities_id' => 10, 'name' => 'Child Entity']);
$DB->insert('glpi_computers', [
    'id' => 501,
    'entities_id' => 20,
    'is_deleted' => 0,
    'name' => 'Local Laptop',
    'serial' => 'SER-501',
    'otherserial' => '',
    'comment' => 'Ready',
    'date_mod' => '2026-01-01 00:00:00',
]);

$remoteClient = new FakeGlpiBClient();
$syncService = new \GlpiPlugin\Assetsync20\AssetSyncService($remoteClient);
$enqueued = $syncService->enqueueBackfill(10);

if ($enqueued !== 2) {
    throw new RuntimeException('Route backfill should queue one local asset for each matching active GLPI B connection.');
}

$queueRows = $DB->tables[\GlpiPlugin\Assetsync20\AssetSyncQueue::TABLE] ?? [];

if (count($queueRows) !== 2) {
    throw new RuntimeException('Sync queue should keep one row per local asset and GLPI B connection.');
}

$processed = $syncService->processQueue(10);

if ($processed !== 2) {
    throw new RuntimeException('Sync queue should process the queued GLPI B jobs.');
}

$finishedQueueRows = $DB->tables[\GlpiPlugin\Assetsync20\AssetSyncQueue::TABLE] ?? [];
foreach ($finishedQueueRows as $finishedQueueRow) {
    if (($finishedQueueRow['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncQueue::STATUS_DONE) {
        throw new RuntimeException('Processed queue rows should be marked done.');
    }

    if ((int) ($finishedQueueRow['remote_items_id'] ?? 0) <= 0) {
        throw new RuntimeException('Processed queue rows should store the GLPI B remote asset id.');
    }
}

$linkRows = $DB->tables[\GlpiPlugin\Assetsync20\AssetSyncLink::TABLE] ?? [];

if (count($linkRows) !== 2) {
    throw new RuntimeException('Sync should create one durable link per local asset and GLPI B connection.');
}

$linksByConnection = [];
foreach ($linkRows as $linkRow) {
    $linksByConnection[$linkRow['glpi_b_connection_id']] = $linkRow;
}

if (($linksByConnection['production']['route_id'] ?? '') !== 'prod-child') {
    throw new RuntimeException('Production sync should use the deepest matching route.');
}

if (($linksByConnection['staging']['route_id'] ?? '') !== 'staging-parent') {
    throw new RuntimeException('Staging sync should independently use its matching route.');
}

$productionRecords = $remoteClient->records['production:Computer'] ?? [];
$stagingRecords = $remoteClient->records['staging:Computer'] ?? [];
$productionRecord = reset($productionRecords);
$stagingRecord = reset($stagingRecords);

if (
    (int) ($productionRecord['entities_id'] ?? 0) !== 200
    || ($productionRecord['name'] ?? '') !== 'Local Laptop'
    || ($productionRecord['serial'] ?? '') !== 'SER-501'
    || ($productionRecord['comment'] ?? '') !== 'Ready'
) {
    throw new RuntimeException('Production remote asset should be created with route entity and mapped scalar fields.');
}

if ((int) ($stagingRecord['entities_id'] ?? 0) !== 300 || ($stagingRecord['serial'] ?? '') !== 'SER-501') {
    throw new RuntimeException('Staging remote asset should be created independently for the same local asset.');
}

if ($syncService->queueAssetIfNeeded('Computer', 501, 'production') !== false) {
    throw new RuntimeException('Queueing an already synced asset with the same payload should be idempotent.');
}

$productionLink = $linksByConnection['production'];
$productionRemoteId = (int) $productionLink['remote_items_id'];
$remoteClient->records['production:Computer'][$productionRemoteId]['name'] = 'Remote Laptop';

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_b',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
        'source_of_truth' => 'both',
    ],
], [
    [
        'key' => 'Computer.name',
        'id' => '1',
        'uid' => 'Computer.name',
        'label' => 'Name',
    ],
    [
        'key' => 'Computer.serial',
        'id' => '5',
        'uid' => 'Computer.serial',
        'label' => 'Serial number',
    ],
]);

if (!$syncService->queueAssetIfNeeded('Computer', 501, 'production')) {
    throw new RuntimeException('Changing field source of truth should enqueue the linked asset.');
}

$syncService->processQueue(10);
$localComputer = $DB->firstRow('glpi_computers', ['id' => 501]);

if (($localComputer['name'] ?? '') !== 'Remote Laptop') {
    throw new RuntimeException('GLPI B source of truth should pull remote values into the linked GLPI A asset.');
}

$DB->update(
    \GlpiPlugin\Assetsync20\AssetSyncLink::TABLE,
    ['last_sync_at' => '2000-01-01 00:00:00'],
    ['id' => (int) $productionLink['id']]
);
$remoteClient->records['production:Computer'][$productionRemoteId]['name'] = 'Remote Laptop Rechecked';

if (!$syncService->queueAssetIfNeeded('Computer', 501, 'production')) {
    throw new RuntimeException('Old linked assets with GLPI B mappings should be queued for periodic remote recheck.');
}

$syncService->processQueue(10);
$localComputer = $DB->firstRow('glpi_computers', ['id' => 501]);

if (($localComputer['name'] ?? '') !== 'Remote Laptop Rechecked') {
    throw new RuntimeException('Periodic remote recheck should pull changed GLPI B values.');
}

$remoteClient->records['production:Computer'][$productionRemoteId]['name'] = 'Conflicting Remote Laptop';

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'both',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
        'source_of_truth' => 'both',
    ],
], [
    [
        'key' => 'Computer.name',
        'id' => '1',
        'uid' => 'Computer.name',
        'label' => 'Name',
    ],
    [
        'key' => 'Computer.serial',
        'id' => '5',
        'uid' => 'Computer.serial',
        'label' => 'Serial number',
    ],
]);

if (!$syncService->queueAssetIfNeeded('Computer', 501, 'production')) {
    throw new RuntimeException('Changing to Both source of truth should enqueue the linked asset.');
}

$syncService->processQueue(10);
$localComputer = $DB->firstRow('glpi_computers', ['id' => 501]);
$blockedLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 501, 'production');

if (($localComputer['name'] ?? '') !== 'Remote Laptop Rechecked') {
    throw new RuntimeException('Both source conflict should not change the local field.');
}

if (($remoteClient->records['production:Computer'][$productionRemoteId]['name'] ?? '') !== 'Conflicting Remote Laptop') {
    throw new RuntimeException('Both source conflict should not change the remote field.');
}

if (($blockedLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT) {
    throw new RuntimeException('Both source conflict should block the link state.');
}

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_a',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
        'source_of_truth' => 'both',
    ],
], [
    [
        'key' => 'Computer.name',
        'id' => '1',
        'uid' => 'Computer.name',
        'label' => 'Name',
    ],
    [
        'key' => 'Computer.serial',
        'id' => '5',
        'uid' => 'Computer.serial',
        'label' => 'Serial number',
    ],
]);

$DB->insert('glpi_computers', [
    'id' => 502,
    'entities_id' => 20,
    'is_deleted' => 0,
    'name' => 'Local Deleted Remote',
    'serial' => 'SER-502',
    'otherserial' => '',
    'comment' => '',
    'date_mod' => '2026-01-02 00:00:00',
]);

$deletedRemoteId = 2200;
$remoteClient->records['production:Computer'][$deletedRemoteId] = [
    'id' => $deletedRemoteId,
    'entities_id' => 200,
    'is_deleted' => 1,
    'name' => 'Deleted Remote',
    'serial' => 'SER-502',
];

\GlpiPlugin\Assetsync20\AssetSyncLink::save([
    'itemtype' => 'Computer',
    'items_id' => 502,
    'glpi_b_connection_id' => 'production',
    'route_id' => 'prod-child',
    'remote_items_id' => $deletedRemoteId,
    'status' => \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED,
    'last_payload_hash' => '',
    'last_payload_date' => '2026-01-02 00:00:00',
]);

if (!$syncService->queueAssetIfNeeded('Computer', 502, 'production')) {
    throw new RuntimeException('Linked assets should queue when their payload changed.');
}

$syncService->processQueue(10);
$deletedRemoteLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 502, 'production');

if (($deletedRemoteLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_BLOCKED_MISSING_REMOTE) {
    throw new RuntimeException('Linked deleted GLPI B assets should be blocked as missing remote.');
}

if (($remoteClient->records['production:Computer'][$deletedRemoteId]['name'] ?? '') !== 'Deleted Remote') {
    throw new RuntimeException('Linked deleted GLPI B assets should not be updated remotely.');
}

\GlpiPlugin\Assetsync20\EntitySyncRoute::save([
    'id' => 'prod-child-conflict',
    'name' => 'Production child conflict',
    'glpi_b_connection_id' => 'production',
    'glpi_a_source_entity_id' => '20',
    'glpi_a_source_entity_name' => 'Child Entity',
    'glpi_b_target_entity_id' => '201',
    'glpi_b_target_entity_name' => 'Production Child Target 2',
    'asset_types' => ['Computer'],
    'include_child_entities' => '',
    'active' => '1',
]);

$conflictResult = \GlpiPlugin\Assetsync20\EntitySyncRoute::matchAsset('Computer', '20', ['1', '10', '20'], 'production');

if (count($conflictResult['conflicts']) !== 1) {
    throw new RuntimeException('Equal-depth entity route overlaps should be reported as conflicts.');
}

$conflictRouteIds = $conflictResult['conflicts'][0]['route_ids'];
sort($conflictRouteIds);

if ($conflictRouteIds !== ['prod-child', 'prod-child-conflict']) {
    throw new RuntimeException('Entity route conflict should include the overlapping route ids.');
}

$configConflicts = \GlpiPlugin\Assetsync20\EntitySyncRoute::findConfigConflicts();

if (count($configConflicts) !== 1) {
    throw new RuntimeException('Entity route config conflicts should be detectable.');
}

\GlpiPlugin\Assetsync20\EntitySyncRoute::delete('prod-child-conflict');

if (\GlpiPlugin\Assetsync20\EntitySyncRoute::find('prod-child-conflict') !== null) {
    throw new RuntimeException('Deleted entity sync route should not be loaded.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::delete('staging');

if (\GlpiPlugin\Assetsync20\GlpiBConnection::find('staging') !== null) {
    throw new RuntimeException('Deleted GLPI B connection should not be loaded.');
}

Config::$values = [
    'plugin:assetsync20' => [
        'glpib_name' => 'Legacy GLPI B',
        'glpib_base_url' => 'https://legacy-glpi-b.example.com/apirest.php',
        'glpib_app_token' => (new GLPIKey())->encrypt('legacy-app-secret'),
        'glpib_user_token' => (new GLPIKey())->encrypt('legacy-user-secret'),
        'glpib_active' => '1',
    ],
];

$legacyConnections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if (count($legacyConnections) !== 1) {
    throw new RuntimeException('Legacy GLPI B connection should migrate into the new connection list.');
}

if (
    $legacyConnections[0]['name'] !== 'Legacy GLPI B'
    || $legacyConnections[0]['base_url'] !== 'https://legacy-glpi-b.example.com'
    || $legacyConnections[0]['app_token'] !== 'legacy-app-secret'
    || $legacyConnections[0]['user_token'] !== 'legacy-user-secret'
    || $legacyConnections[0]['active'] !== true
) {
    throw new RuntimeException('Migrated legacy GLPI B connection values are incorrect.');
}

if (!plugin_assetsync20_uninstall()) {
    throw new RuntimeException('Uninstall hook failed.');
}

$remainingValues = Config::getConfigurationValues('plugin:assetsync20');

foreach (['glpib_connections', 'field_mappings', 'entity_sync_routes', 'asset_sync_scan_cursors', 'glpib_name', 'glpib_base_url', 'glpib_app_token', 'glpib_user_token', 'glpib_active'] as $deletedKey) {
    if (array_key_exists($deletedKey, $remainingValues)) {
        throw new RuntimeException('Uninstall should delete ' . $deletedKey . '.');
    }
}

if ($DB->tableExists(\GlpiPlugin\Assetsync20\AssetSyncQueue::TABLE)) {
    throw new RuntimeException('Uninstall should drop the sync queue table.');
}

if ($DB->tableExists(\GlpiPlugin\Assetsync20\AssetSyncLink::TABLE)) {
    throw new RuntimeException('Uninstall should drop the asset link table.');
}

if (CronTask::$registered !== []) {
    throw new RuntimeException('Uninstall should unregister the automatic action.');
}

echo "assetsync2.0 smoke test passed.\n";
