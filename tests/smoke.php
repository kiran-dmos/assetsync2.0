<?php

declare(strict_types=1);

require_once __DIR__ . '/query-expression.php';

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
        'glpi_logs' => [],
    ];

    /** @var array<string,int> */
    private array $nextIds = [];
    public string $timezone = '';

    public function guessTimezone(): string
    {
        return $this->timezone !== '' ? $this->timezone : date_default_timezone_get();
    }

    public function tableExists(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    public function doQuery(string $sql)
    {
        if ($sql === 'SELECT @@SESSION.time_zone AS session_timezone') {
            return [['session_timezone' => $this->guessTimezone()]];
        }

        if (preg_match("/^SET SESSION time_zone = '([^']+)'$/", $sql, $match)) {
            $previous = new DateTimeZone($this->guessTimezone());
            $next = new DateTimeZone($match[1]);
            foreach ($this->tables as &$rows) {
                foreach ($rows as &$row) {
                    foreach (['date_creation', 'date_mod', 'last_sync_at', 'last_payload_date', 'blocked_at', 'payload_date', 'available_at', 'started_at', 'finished_at'] as $field) {
                        if (is_string($row[$field] ?? null) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row[$field])) {
                            $row[$field] = (new DateTimeImmutable($row[$field], $previous))->setTimezone($next)->format('Y-m-d H:i:s');
                        }
                    }
                }
                unset($row);
            }
            unset($rows);
            $this->timezone = $match[1];
            return true;
        }

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

    public function fetchAssoc(array $rows): ?array
    {
        return $rows[0] ?? null;
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @param array<string,mixed> $params
     */
    public function insert(string $table, array $params): bool
    {
        $params = $this->resolveExpressions($params);
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
        $params = $this->resolveExpressions($params);
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

        if (isset($query['SELECT']) && is_array($query['SELECT'])) {
            $selected = array_filter($query['SELECT'], 'is_string');
            $keepAll = in_array('*', $selected, true);
            $selected = array_flip($selected);
            $filtered = array_map(function (array $row) use ($query, $keepAll, $selected): array {
                $result = $keepAll ? $row : array_intersect_key($row, $selected);
                foreach ($query['SELECT'] as $column) {
                    if (!$column instanceof \Glpi\DBAL\QueryExpression || !preg_match('/^UNIX_TIMESTAMP\(`(\w+)`\)$/', $column->expression, $matches)) {
                        continue;
                    }
                    $value = $row[$matches[1]] ?? null;
                    $result[$column->alias] = $value === null ? null : (new DateTimeImmutable((string) $value, new DateTimeZone($this->guessTimezone())))->getTimestamp();
                }
                return $result;
            }, $filtered);
        }

        return new FakeDBResult($filtered);
    }

    private function resolveExpressions(array $params): array
    {
        foreach ($params as $key => $value) {
            if (!$value instanceof \Glpi\DBAL\QueryExpression) {
                continue;
            }
            $time = new DateTimeImmutable('now', new DateTimeZone($this->guessTimezone()));
            if (preg_match('/^DATE_ADD\(NOW\(\), INTERVAL (\d+) SECOND\)$/', $value->expression, $matches)) {
                $time = $time->modify('+' . $matches[1] . ' seconds');
            } elseif ($value->expression !== 'NOW()') {
                throw new RuntimeException('Unexpected SQL expression: ' . $value->expression);
            }
            $params[$key] = $time->format('Y-m-d H:i:s');
        }
        return $params;
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

class Search
{
    public static function getOptions($itemtype): array
    {
        if (PluginFieldsContainer::$options === []) {
            return [];
        }

        return [
            1 => ['name' => 'Name', 'field' => 'name', 'table' => 'glpi_computers', 'uid' => 'Computer.name'],
            5 => ['name' => 'Serial number', 'field' => 'serial', 'table' => 'glpi_computers', 'uid' => 'Computer.serial'],
            6 => ['name' => 'Inventory number', 'field' => 'otherserial', 'table' => 'glpi_computers', 'uid' => 'Computer.otherserial'],
            16 => ['name' => 'Comments', 'field' => 'comment', 'table' => 'glpi_computers', 'uid' => 'Computer.comment'],
        ] + PluginFieldsContainer::$options;
    }
}

function getItemTypeForTable($table): string
{
    return 'PluginFieldsComputerdmosasset';
}

function getTableForItemType($class): string
{
    if ($class === 'PluginFieldsDepartmentfieldDropdown') {
        return 'glpi_plugin_fields_departmentfielddropdowns';
    }

    if ($class === 'PluginFieldsHwbillingfrequencyfieldDropdown') {
        return 'glpi_plugin_fields_hwbillingfrequencyfielddropdowns';
    }

    if ($class === 'PluginFieldsStatusfieldDropdown') {
        return 'glpi_plugin_fields_statusfielddropdowns';
    }

    return 'glpi_plugin_fields_computerdmosassets';
}

class PluginFieldsContainer
{
    public static array $options = [];

    public static function getAddSearchOptions($itemtype): array
    {
        return self::$options;
    }
}

class PluginFieldsField
{
    public static array $definitions = [
        1 => [
            'id' => 1,
            'name' => 'namefield',
            'type' => 'text',
            'is_active' => 1,
            'plugin_fields_containers_id' => 1,
            'is_readonly' => 0,
        ],
        8 => [
            'id' => 8,
            'name' => 'departmentfield',
            'type' => 'dropdown',
            'multiple' => 0,
            'is_active' => 1,
            'plugin_fields_containers_id' => 1,
            'is_readonly' => 0,
        ],
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
    /** @var array<int,array<string,mixed>> */
    public static array $rows = [];
    private static int $nextId = 1;
    public array $fields = [];

    public static function getType(): string
    {
        return self::class;
    }

    public function getFromDBByCrit(array $criteria): bool
    {
        $itemsId = (int) ($criteria['items_id'] ?? 0);
        $itemtype = (string) ($criteria['itemtype'] ?? '');
        $row = self::$rows[$itemsId] ?? null;

        if ($row === null || (string) ($row['itemtype'] ?? '') !== $itemtype) {
            $this->fields = [];
            return false;
        }

        $this->fields = $row;

        return true;
    }

    public function update(array $input): bool
    {
        $itemsId = (int) ($input['items_id'] ?? 0);
        if ($itemsId <= 0) {
            foreach (self::$rows as $savedItemsId => $row) {
                if ((int) ($row['id'] ?? 0) === (int) ($input['id'] ?? 0)) {
                    $itemsId = $savedItemsId;
                    break;
                }
            }
        }

        if ($itemsId <= 0) {
            return false;
        }

        self::$rows[$itemsId] = array_merge(self::$rows[$itemsId] ?? [], $input);
        $this->fields = self::$rows[$itemsId];
        \GlpiPlugin\Assetsync20\AssetChangeHook::onUpdate($this);

        return true;
    }

    public function add(array $input): bool
    {
        $itemsId = (int) ($input['items_id'] ?? 0);
        if ($itemsId <= 0) {
            return false;
        }

        $input['id'] = self::$nextId++;
        self::$rows[$itemsId] = $input;
        $this->fields = self::$rows[$itemsId];
        \GlpiPlugin\Assetsync20\AssetChangeHook::onAdd($this);

        return true;
    }
}

class PluginFieldsDepartmentfieldDropdown
{
    public static array $rows = [
        10 => ['id' => 10, 'name' => 'Hardware', 'completename' => 'Operations > Hardware'],
        11 => ['id' => 11, 'name' => 'Software', 'completename' => 'Operations > Software'],
        12 => ['id' => 12, 'name' => 'Empty branch', 'completename' => 'Operations > Empty'],
    ];
    public array $fields = [];

    public static function getTable(): string
    {
        return 'glpi_plugin_fields_departmentfielddropdowns';
    }

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

class PluginFieldsHwbillingfrequencyfieldDropdown
{
    public static array $rows = [
        1 => ['id' => 1, 'name' => 'Monthly', 'completename' => 'Monthly'],
        2 => ['id' => 2, 'name' => 'Annual', 'completename' => 'Annual'],
    ];
    public array $fields = [];

    public static function getTable(): string
    {
        return 'glpi_plugin_fields_hwbillingfrequencyfielddropdowns';
    }

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

class PluginFieldsStatusfieldDropdown
{
    public static array $rows = [
        20 => ['id' => 20, 'name' => 'In Use', 'completename' => 'In Use'],
        21 => ['id' => 21, 'name' => 'In Stock - Available', 'completename' => 'In Stock - Available'],
    ];
    public array $fields = [];

    public static function getTable(): string
    {
        return 'glpi_plugin_fields_statusfielddropdowns';
    }

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

final class FakeGlpiBClient
{
    public array $unavailableCustomKeys = [];
    public string $dateModTimezone = 'UTC';
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $records = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $customRecords = [];
    /** @var array<string,bool> */
    public array $readonlyCustomKeys = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $customDropdownOptions = [];
    /** @var array<string,string> */
    public array $customHistoryOptionIds = [];
    /** @var array<string,array<int,array<string,string>>> */
    public array $customHistoryDates = [];
    /** @var array<string,array<int,list<array<string,mixed>>>> */
    public array $customHistoryLogs = [];
    /** @var list<array{itemtype:string,items_id:int,option_ids:list<string>,itemtype_links:list<string>}> */
    public array $customHistoryRequests = [];
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
            'date_mod_timezone' => $this->dateModTimezone,
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
        $record = $input;
        $record['id'] = $id;
        $record['date_mod'] = $record['date_mod'] ?? '2026-01-01 00:00:00';
        $this->records[$this->key($connection, $itemtype)][$id] = $record;

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
     * @param list<string> $keys
     * @param array<string,mixed> $changes
     * @param array<string,string> $expectedTypes
     * @return array{success:bool,message:string,item:array<string,mixed>,history_option_ids:array<string,string>,history_refs:array<string,array{option_id:string,itemtype_link:string}>,transient:bool}
     */
    public function customTextValues(array $connection, string $itemtype, int $itemsId, array $keys, array $changes = [], array $expectedTypes = [], bool $allowReadonly = false): array
    {
        if (array_intersect($keys, $this->unavailableCustomKeys) !== []) {
            return ['success' => false, 'message' => 'Mapped remote field unavailable.', 'transient' => false];
        }
        $recordKey = $this->key($connection, $itemtype);
        $values = [];
        $historyOptionIds = array_intersect_key($this->customHistoryOptionIds, array_flip($keys));
        $historyRefs = [];

        if ($itemsId > 0) {
            $this->customRecords[$recordKey][$itemsId] ??= [];
            foreach ($changes as $key => $value) {
                $key = (string) $key;
                if (!$allowReadonly && !empty($this->readonlyCustomKeys[$key])) {
                    return ['success' => false, 'message' => 'Remote custom field is read-only.', 'transient' => false];
                }
                $type = (string) ($expectedTypes[$key] ?? 'text');
                $this->customRecords[$recordKey][$itemsId][$key] = $type === 'dropdown'
                    ? $this->dropdownIdForLabel($key, (string) $value)
                    : $value;
            }

            foreach ($keys as $key) {
                $key = (string) $key;
                $type = (string) ($expectedTypes[$key] ?? 'text');
                $storedValue = $this->customRecords[$recordKey][$itemsId][$key] ?? \GlpiPlugin\Assetsync20\FieldsText::missingValue($type);
                $values[$key] = $type === 'dropdown'
                    ? $this->dropdownLabelForId($key, $storedValue)
                    : $storedValue;
            }
        }

        foreach ($historyOptionIds as $key => $optionId) {
            $historyRefs[$key] = [
                'option_id' => $optionId,
                'itemtype_link' => 'PluginFieldsComputerdmosasset',
            ];
        }

        return [
            'success' => true,
            'message' => 'custom values loaded',
            'item' => $values,
            'history_option_ids' => $historyOptionIds,
            'history_refs' => $historyRefs,
            'transient' => false,
        ];
    }

    /**
     * @param array{id:string} $connection
     * @param array<string,array{option_id?:int|string,itemtype_link?:string}> $historyRefs
     * @return array{success:bool,message:string,dates:array<string,string>,transient:bool}
     */
    public function customHistoryDates(array $connection, string $itemtype, int $itemsId, array $historyRefs): array
    {
        $recordKey = $this->key($connection, $itemtype);
        $optionIds = [];
        $itemtypeLinks = [];
        $dates = [];
        foreach ($historyRefs as $key => $ref) {
            $optionId = (string) ($ref['option_id'] ?? '');
            $itemtypeLink = (string) ($ref['itemtype_link'] ?? '');
            if ($optionId !== '') {
                $optionIds[] = $optionId;
            }
            if ($itemtypeLink !== '') {
                $itemtypeLinks[] = $itemtypeLink;
            }
        }

        if (($this->customHistoryLogs[$recordKey][$itemsId] ?? []) !== []) {
            $filter = new ReflectionMethod(\GlpiPlugin\Assetsync20\GlpiBConnection::class, 'latestHistoryDatesByRef');
            $filter->setAccessible(true);
            $dates = $filter->invoke(null, $this->customHistoryLogs[$recordKey][$itemsId], $historyRefs);
        } else {
            foreach ($historyRefs as $key => $ref) {
                $optionId = (string) ($ref['option_id'] ?? '');
                if (array_key_exists($key, $this->customHistoryDates[$recordKey][$itemsId] ?? [])) {
                    $dates[$key] = $this->customHistoryDates[$recordKey][$itemsId][$key];
                    continue;
                }
                if ($optionId !== '' && array_key_exists($optionId, $this->customHistoryDates[$recordKey][$itemsId] ?? [])) {
                    $dates[$key] = $this->customHistoryDates[$recordKey][$itemsId][$optionId];
                }
            }
        }
        $this->customHistoryRequests[] = [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'option_ids' => $optionIds,
            'itemtype_links' => $itemtypeLinks,
        ];

        return [
            'success' => true,
            'message' => 'custom history loaded',
            'dates' => $dates,
            'transient' => false,
            'date_mod_timezone' => $this->dateModTimezone,
        ];
    }

    /**
     * @param array{id:string} $connection
     */
    private function key(array $connection, string $itemtype): string
    {
        return $connection['id'] . ':' . $itemtype;
    }

    private function dropdownIdForLabel(string $key, string $label): int
    {
        $label = \GlpiPlugin\Assetsync20\FieldsText::normalizeValue('dropdown', $label);
        if ($label === '') {
            return 0;
        }

        $matches = [];
        foreach ($this->customDropdownOptions[$key] ?? [] as $row) {
            if (!is_array($row) || \GlpiPlugin\Assetsync20\FieldsText::dropdownLabel($row) !== $label) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $matches[$id] = true;
            }
        }

        if ($matches === []) {
            throw new RuntimeException('The destination GLPI B Fields-plugin dropdown option is missing for ' . $key . ': ' . $label);
        }

        if (count($matches) > 1) {
            throw new RuntimeException('The destination GLPI B Fields-plugin dropdown option label is duplicated for ' . $key . ': ' . $label);
        }

        return (int) array_key_first($matches);
    }

    private function dropdownLabelForId(string $key, $value): string
    {
        $id = is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 0;
        if ($id <= 0) {
            return '';
        }

        foreach ($this->customDropdownOptions[$key] ?? [] as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
                continue;
            }

            $label = \GlpiPlugin\Assetsync20\FieldsText::dropdownLabel($row);
            if ($label !== '') {
                return $label;
            }
        }

        throw new RuntimeException('The remote Fields-plugin dropdown option is missing for ' . $key . ': ' . $id);
    }
}

function configureCustomNameMapping(string $source, string $customKey, string $remoteOptionId): array
{
    $remoteFields = [[
        'key' => $customKey,
        'id' => $remoteOptionId,
        'uid' => $customKey,
        'label' => 'DMOS Name',
    ]];

    \GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
        $customKey => [
            'glpi_b_field_key' => $customKey,
            'glpi_b_field_uid' => $customKey,
            'glpi_b_field_label' => 'DMOS Name',
            'source_of_truth' => $source,
        ],
    ], $remoteFields);

    return $remoteFields;
}

function configureCustomDropdownMapping(string $source, string $customKey, string $remoteOptionId): array
{
    $remoteFields = [[
        'key' => $customKey,
        'id' => $remoteOptionId,
        'uid' => $customKey,
        'label' => 'DMOS Department',
    ]];

    \GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
        $customKey => [
            'glpi_b_field_key' => $customKey,
            'glpi_b_field_uid' => $customKey,
            'glpi_b_field_label' => 'DMOS Department',
            'source_of_truth' => $source,
        ],
    ], $remoteFields);

    return $remoteFields;
}

function seedLinkedCustomComputer(
    FakeDB $DB,
    FakeGlpiBClient $remoteClient,
    int $assetId,
    int $remoteId,
    string $customKey,
    string $customOptionId,
    string $remoteOptionId,
    string $localValue,
    ?string $localHistoryDate,
    string $remoteValue,
    ?string $remoteHistoryDate,
    bool $localHistoryUsesParentOption = true
): void {
    static $logId = 80000;

    $DB->insert('glpi_computers', [
        'id' => $assetId,
        'entities_id' => 20,
        'is_deleted' => 0,
        'name' => 'Custom test ' . $assetId,
        'serial' => 'SER-' . $assetId,
        'otherserial' => '',
        'comment' => '',
        'date_mod' => '2026-09-14 06:05:43',
    ]);
    PluginFieldsComputerdmosasset::$rows[$assetId] = [
        'id' => 1000 + $assetId,
        'items_id' => $assetId,
        'itemtype' => 'Computer',
        'plugin_fields_containers_id' => 1,
        'namefield' => $localValue,
    ];

    if ($localHistoryDate !== null) {
        $DB->insert('glpi_logs', [
            'id' => ++$logId,
            'itemtype' => 'Computer',
            'items_id' => $assetId,
            'itemtype_link' => $localHistoryUsesParentOption ? '' : 'PluginFieldsComputerdmosasset',
            'linked_action' => $localHistoryUsesParentOption ? 0 : 18,
            'date_mod' => $localHistoryDate,
            'id_search_option' => $localHistoryUsesParentOption ? (int) $customOptionId : 0,
            'old_value' => 'old custom value',
            'new_value' => $localValue,
        ]);
    }

    $remoteClient->records['production:Computer'][$remoteId] = [
        'id' => $remoteId,
        'entities_id' => 200,
        'is_deleted' => 0,
        'name' => 'Custom remote test ' . $remoteId,
        'serial' => 'SER-' . $assetId,
        'date_mod' => '2026-09-14 06:05:49',
    ];
    $remoteClient->customRecords['production:Computer'][$remoteId] = [$customKey => $remoteValue];
    $remoteClient->customHistoryDates['production:Computer'][$remoteId] = $remoteHistoryDate === null
        ? []
        : [$remoteOptionId => $remoteHistoryDate];

    \GlpiPlugin\Assetsync20\AssetSyncLink::save([
        'itemtype' => 'Computer',
        'items_id' => $assetId,
        'glpi_b_connection_id' => 'production',
        'route_id' => 'prod-child',
        'remote_items_id' => $remoteId,
        'status' => \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED,
        'last_payload_hash' => '',
        'last_payload_date' => '2026-09-14 06:05:43',
    ]);
}

function seedLinkedDropdownComputer(
    FakeDB $DB,
    FakeGlpiBClient $remoteClient,
    int $assetId,
    int $remoteId,
    string $customKey,
    int $localDropdownId,
    int $remoteDropdownId
): void {
    $DB->insert('glpi_computers', [
        'id' => $assetId,
        'entities_id' => 20,
        'is_deleted' => 0,
        'name' => 'Dropdown test ' . $assetId,
        'serial' => 'SER-' . $assetId,
        'otherserial' => '',
        'comment' => '',
        'date_mod' => '2026-09-14 06:05:43',
    ]);
    PluginFieldsComputerdmosasset::$rows[$assetId] = [
        'id' => 1000 + $assetId,
        'items_id' => $assetId,
        'itemtype' => 'Computer',
        'plugin_fields_containers_id' => 1,
        'plugin_fields_departmentfielddropdowns_id' => $localDropdownId,
    ];

    $remoteClient->records['production:Computer'][$remoteId] = [
        'id' => $remoteId,
        'entities_id' => 200,
        'is_deleted' => 0,
        'name' => 'Dropdown remote test ' . $remoteId,
        'serial' => 'SER-' . $assetId,
        'date_mod' => '2026-09-14 06:05:49',
    ];
    $remoteClient->customRecords['production:Computer'][$remoteId] = [$customKey => $remoteDropdownId];

    \GlpiPlugin\Assetsync20\AssetSyncLink::save([
        'itemtype' => 'Computer',
        'items_id' => $assetId,
        'glpi_b_connection_id' => 'production',
        'route_id' => 'prod-child',
        'remote_items_id' => $remoteId,
        'status' => \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED,
        'last_payload_hash' => '',
        'last_payload_date' => '2026-09-14 06:05:43',
    ]);
}

function hasPendingProductionQueue(FakeDB $DB, int $assetId): bool
{
    foreach ($DB->tables[\GlpiPlugin\Assetsync20\AssetSyncQueue::TABLE] ?? [] as $queueRow) {
        if (
            (int) ($queueRow['items_id'] ?? 0) === $assetId
            && (string) ($queueRow['glpi_b_connection_id'] ?? '') === 'production'
            && (string) ($queueRow['status'] ?? '') === \GlpiPlugin\Assetsync20\AssetSyncQueue::STATUS_PENDING
        ) {
            return true;
        }
    }

    return false;
}

function cronManualForceRequestedFor(array $post, array $argv): bool
{
    $previousPost = $_POST;
    $hadArgv = array_key_exists('argv', $_SERVER);
    $previousArgv = $_SERVER['argv'] ?? null;

    $_POST = $post;
    $_SERVER['argv'] = $argv;

    try {
        $method = new ReflectionMethod(\GlpiPlugin\Assetsync20\AssetSyncCron::class, 'manualForceRequested');
        $method->setAccessible(true);

        return (bool) $method->invoke(null);
    } finally {
        $_POST = $previousPost;
        if ($hadArgv) {
            $_SERVER['argv'] = $previousArgv;
        } else {
            unset($_SERVER['argv']);
        }
    }
}

$DB = new FakeDB();
$PLUGIN_HOOKS = [];

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/hook.php';
plugin_init_assetsync20();

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

$updateHook = $PLUGIN_HOOKS['item_update']['assetsync20']['Computer'] ?? null;
if ($updateHook !== [\GlpiPlugin\Assetsync20\AssetChangeHook::class, 'onUpdate']) {
    throw new RuntimeException('Computer update hook should enqueue changed GLPI A assets.');
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

$dashboard = file_get_contents(dirname(__DIR__) . '/front/config.php');
if ($dashboard === false) {
    throw new RuntimeException('Dashboard page could not be read.');
}

foreach ([
    'AssetSync2.0 dashboard',
    'GLPI B Connections',
    'Open entity routes',
    'Open field mapping',
    'Open automatic actions',
    'AssetSyncCron::TASK_NAME',
] as $dashboardText) {
    if (!str_contains($dashboard, $dashboardText)) {
        throw new RuntimeException('Dashboard is missing expected text: ' . $dashboardText);
    }
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

if (cronManualForceRequestedFor(['execute' => '63'], ['front/cron.php'])) {
    throw new RuntimeException('Automatic action list Execute should not be treated as a manual force run.');
}

if (!cronManualForceRequestedFor(['execute' => \GlpiPlugin\Assetsync20\AssetSyncCron::TASK_NAME], ['front/cron.php'])) {
    throw new RuntimeException('Automatic action form Execute should be treated as a manual force run.');
}

if (!cronManualForceRequestedFor([], ['front/cron.php', '--force', \GlpiPlugin\Assetsync20\AssetSyncCron::TASK_NAME])) {
    throw new RuntimeException('CLI cron --force should be treated as a manual force run for the named asset sync task.');
}

if (cronManualForceRequestedFor([], ['front/cron.php', \GlpiPlugin\Assetsync20\AssetSyncCron::TASK_NAME, '--force'])) {
    throw new RuntimeException('CLI cron arguments should only force tasks after the --force option has been parsed.');
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

$DB->update('glpi_computers', [
    'name' => 'Local Laptop Hooked',
    'date_mod' => '2026-01-01 00:10:00',
], ['id' => 501]);
$hookedComputer = new Computer();
$hookedComputer->fields = ['id' => 501, 'entities_id' => 20];
\GlpiPlugin\Assetsync20\AssetChangeHook::onUpdate($hookedComputer);
$hookQueued = false;
foreach ($DB->tables[\GlpiPlugin\Assetsync20\AssetSyncQueue::TABLE] ?? [] as $queueRow) {
    if (
        (string) ($queueRow['itemtype'] ?? '') === 'Computer'
        && (int) ($queueRow['items_id'] ?? 0) === 501
        && (string) ($queueRow['glpi_b_connection_id'] ?? '') === 'production'
        && (string) ($queueRow['status'] ?? '') === \GlpiPlugin\Assetsync20\AssetSyncQueue::STATUS_PENDING
    ) {
        $hookQueued = true;
        break;
    }
}
if (!$hookQueued) {
    throw new RuntimeException('GLPI A asset update hook should enqueue changed linked assets.');
}
$syncService->processQueue(10);
if (($remoteClient->records['production:Computer'][$productionRecord['id']]['name'] ?? '') !== 'Local Laptop Hooked') {
    throw new RuntimeException('GLPI A asset update hook should push changed A values to GLPI B.');
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

$remoteClient->records['production:Computer'][$productionRemoteId]['name'] = 'Remote Laptop Recent';

if ($syncService->queueAssetIfNeeded('Computer', 501, 'production')) {
    throw new RuntimeException('Scheduled runs should keep the inbound hourly recheck cooldown for recently synced links.');
}

if (!$syncService->queueAssetIfNeeded('Computer', 501, 'production', true)) {
    throw new RuntimeException('Manual force runs should bypass the inbound hourly recheck cooldown for recently synced links.');
}

$syncService->processQueue(10);
$localComputer = $DB->firstRow('glpi_computers', ['id' => 501]);

if (($localComputer['name'] ?? '') !== 'Remote Laptop Recent') {
    throw new RuntimeException('Manual force runs should pull recent GLPI B changes without waiting for the hourly recheck.');
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

$forceMappings = \GlpiPlugin\Assetsync20\FieldMapping::syncMappings('production', 'Computer');
$forceRoute = \GlpiPlugin\Assetsync20\EntitySyncRoute::find('prod-child');
if ($forceRoute === null) {
    throw new RuntimeException('Manual force smoke test route could not be found.');
}

foreach ([506, 507] as $forceAssetId) {
    $DB->insert('glpi_computers', [
        'id' => $forceAssetId,
        'entities_id' => 20,
        'is_deleted' => 0,
        'name' => 'Manual Local ' . $forceAssetId,
        'serial' => 'SER-' . $forceAssetId,
        'otherserial' => '',
        'comment' => '',
        'date_mod' => '2026-09-14 09:11:03',
    ]);

    $remoteId = 2500 + $forceAssetId;
    $remoteClient->records['production:Computer'][$remoteId] = [
        'id' => $remoteId,
        'entities_id' => 200,
        'is_deleted' => 0,
        'name' => 'Manual Remote ' . $forceAssetId,
        'serial' => 'SER-' . $forceAssetId,
        'date_mod' => '2026-09-14 09:15:54',
    ];

    $forceAsset = $DB->firstRow('glpi_computers', ['id' => $forceAssetId]);
    if ($forceAsset === null) {
        throw new RuntimeException('Manual force smoke test asset could not be loaded.');
    }

    \GlpiPlugin\Assetsync20\AssetSyncLink::save([
        'itemtype' => 'Computer',
        'items_id' => $forceAssetId,
        'glpi_b_connection_id' => 'production',
        'route_id' => 'prod-child',
        'remote_items_id' => $remoteId,
        'status' => \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED,
        'last_payload_hash' => $syncService->payloadHash($forceAsset, $forceRoute, $forceMappings),
        'last_payload_date' => '2026-09-14 09:11:03',
    ]);
}

$syncService->run(1, 25, true);
$firstForcedAsset = $DB->firstRow('glpi_computers', ['id' => 506]);
$secondForcedAsset = $DB->firstRow('glpi_computers', ['id' => 507]);

if (($firstForcedAsset['name'] ?? '') !== 'Manual Remote 506') {
    throw new RuntimeException('Manual force backfill should process the first scanned recently synced inbound asset.');
}

if (($secondForcedAsset['name'] ?? '') !== 'Manual Local 507' || hasPendingProductionQueue($DB, 507)) {
    throw new RuntimeException('Manual force backfill should not exceed the configured batch limit.');
}

$syncService->run(1, 25, true);
$secondForcedAsset = $DB->firstRow('glpi_computers', ['id' => 507]);

if (($secondForcedAsset['name'] ?? '') !== 'Manual Remote 507') {
    throw new RuntimeException('Manual force backfill should pick up the next inbound asset on a later bounded run.');
}

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_a',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
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
]);

$DB->insert('glpi_computers', [
    'id' => 508,
    'entities_id' => 20,
    'is_deleted' => 0,
    'name' => 'One Way Local',
    'serial' => 'SER-508',
    'otherserial' => '',
    'comment' => '',
    'date_mod' => '2026-09-14 09:11:03',
]);
$remoteClient->records['production:Computer'][2508] = [
    'id' => 2508,
    'entities_id' => 200,
    'is_deleted' => 0,
    'name' => 'One Way Remote',
    'serial' => 'SER-508',
    'date_mod' => '2026-09-14 09:15:54',
];
$oneWayMappings = \GlpiPlugin\Assetsync20\FieldMapping::syncMappings('production', 'Computer');
$oneWayAsset = $DB->firstRow('glpi_computers', ['id' => 508]);
if ($oneWayAsset === null) {
    throw new RuntimeException('Manual force one-way smoke test asset could not be loaded.');
}
\GlpiPlugin\Assetsync20\AssetSyncLink::save([
    'itemtype' => 'Computer',
    'items_id' => 508,
    'glpi_b_connection_id' => 'production',
    'route_id' => 'prod-child',
    'remote_items_id' => 2508,
    'status' => \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED,
    'last_payload_hash' => $syncService->payloadHash($oneWayAsset, $forceRoute, $oneWayMappings),
    'last_payload_date' => '2026-09-14 09:11:03',
]);

if ($syncService->queueAssetIfNeeded('Computer', 508, 'production', true)) {
    throw new RuntimeException('Manual force runs should not queue unchanged one-way GLPI A mappings.');
}

$remoteClient->records['production:Computer'][$productionRemoteId]['name'] = 'Conflicting Remote Laptop';
$remoteClient->records['production:Computer'][$productionRemoteId]['date_mod'] = '2026-01-02 00:00:00';

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
$latestWinsLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 501, 'production');

if (($localComputer['name'] ?? '') !== 'Conflicting Remote Laptop') {
    throw new RuntimeException('Both source should pull a newer GLPI B value into GLPI A.');
}

if (($remoteClient->records['production:Computer'][$productionRemoteId]['name'] ?? '') !== 'Conflicting Remote Laptop') {
    throw new RuntimeException('Both source should keep the newer GLPI B value unchanged.');
}

if (($latestWinsLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED) {
    throw new RuntimeException('Both source latest-update-wins should sync the link state.');
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

$customKey = 'Computer.PluginFieldsComputerdmosasset.namefield';
$customOptionId = '884776';
$remoteCustomOptionId = '76666';
$DB->tables['glpi_plugin_fields_containers'] = [[
    'id' => 108,
    'name' => 'dmosasset',
    'itemtypes' => '["Computer"]',
    'is_active' => 1,
]];
$DB->tables['glpi_plugin_fields_computerdmosassets'] = [];
PluginFieldsContainer::$options = [];
$PLUGIN_HOOKS = [];
plugin_init_assetsync20();
$fallbackCustomUpdateHook = $PLUGIN_HOOKS['item_update']['assetsync20']['PluginFieldsComputerdmosasset'] ?? null;
if ($fallbackCustomUpdateHook !== [\GlpiPlugin\Assetsync20\AssetChangeHook::class, 'onUpdate']) {
    throw new RuntimeException('Fields-plugin container table fallback should register custom row update hooks during plugin init.');
}
PluginFieldsContainer::$options = [
    (int) $customOptionId => [
        'name' => 'DMOS Name',
        'field' => 'namefield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'text',
        'pfields_fields_id' => 1,
        'plugin_fields_containers_id' => 1,
    ],
];
$PLUGIN_HOOKS = [];
plugin_init_assetsync20();
$customUpdateHook = $PLUGIN_HOOKS['item_update']['assetsync20']['PluginFieldsComputerdmosasset'] ?? null;
if ($customUpdateHook !== [\GlpiPlugin\Assetsync20\AssetChangeHook::class, 'onUpdate']) {
    throw new RuntimeException('Fields-plugin row update hook should enqueue changed GLPI A custom values.');
}
$remoteClient->customHistoryOptionIds[$customKey] = $remoteCustomOptionId;

configureCustomNameMapping('both', $customKey, $remoteCustomOptionId);
seedLinkedCustomComputer($DB, $remoteClient, 601, 2601, $customKey, $customOptionId, $remoteCustomOptionId, 'AssetSync Both A test', '2026-09-14 08:15:30', 'Kiran PC 06 Test 1', '2026-09-14 08:15:35');
$DB->insert('glpi_logs', [
    'id' => 81001,
    'itemtype' => 'Computer',
    'items_id' => 601,
    'itemtype_link' => 'PluginFieldsComputerdmosasset',
    'linked_action' => 18,
    'date_mod' => '2026-09-14 08:15:39',
    'id_search_option' => 0,
    'old_value' => 'AssetSync Both A test',
    'new_value' => 'Kiran PC 06 Test 1',
]);
$historyRequestsBefore = count($remoteClient->customHistoryRequests);
if (!$syncService->queueAssetIfNeeded('Computer', 601, 'production')) {
    throw new RuntimeException('Stale parent date_mod custom A edit should queue from payload hash.');
}
$syncService->processQueue(10);
if (($remoteClient->customRecords['production:Computer'][2601][$customKey] ?? null) !== 'AssetSync Both A test') {
    throw new RuntimeException('Custom Both should push newer GLPI A history value even when GLPI B parent date_mod is newer.');
}
if (count($remoteClient->customHistoryRequests) <= $historyRequestsBefore) {
    throw new RuntimeException('Custom Both should acquire GLPI B history through the remote client.');
}
$lastHistoryRequest = end($remoteClient->customHistoryRequests);
if (($lastHistoryRequest['option_ids'] ?? []) !== [$remoteCustomOptionId]) {
    throw new RuntimeException('Custom Both history lookup should request the mapped remote search option id.');
}
if (($lastHistoryRequest['itemtype_links'] ?? []) !== ['PluginFieldsComputerdmosasset']) {
    throw new RuntimeException('Custom Both history lookup should request the generated remote Fields container class.');
}

seedLinkedCustomComputer($DB, $remoteClient, 602, 2602, $customKey, $customOptionId, $remoteCustomOptionId, 'Kiran PC 06 Test 1', '2026-09-14 08:15:30', 'AssetSync Both B test', '2026-09-14 08:15:00');
$remoteClient->customHistoryLogs['production:Computer'][2602] = [
    ['id' => 92001, 'id_search_option' => (int) $remoteCustomOptionId, 'itemtype_link' => '', 'date_mod' => '2026-09-14 08:15:00'],
    ['id' => 92002, 'id_search_option' => 0, 'itemtype_link' => 'PluginFieldsComputerdmosasset', 'date_mod' => '2026-09-14 08:16:21'],
];
if (!$syncService->queueAssetIfNeeded('Computer', 602, 'production')) {
    throw new RuntimeException('Stale parent date_mod custom B edit should queue when the linked asset is rechecked.');
}
$syncService->processQueue(10);
if ((PluginFieldsComputerdmosasset::$rows[602]['namefield'] ?? null) !== 'AssetSync Both B test') {
    throw new RuntimeException('Custom Both should pull newer GLPI B history value even when both parent date_mod values are stale.');
}
if (hasPendingProductionQueue($DB, 602)) {
    throw new RuntimeException('Sync-written local custom value should not feed back into a new queued job.');
}

$oldPhpTimezone = date_default_timezone_get();
$oldDbTimezone = $DB->timezone;
$remoteClient->dateModTimezone = 'UTC';
date_default_timezone_set('UTC');
$DB->timezone = 'Asia/Brunei';
try {
    seedLinkedCustomComputer($DB, $remoteClient, 613, 2613, $customKey, $customOptionId, $remoteCustomOptionId, 'Kiran PC 06', '2026-09-22 21:53:07', 'Both verification B newer', null);
    $DB->update('glpi_computers', ['date_mod' => '2026-09-14 14:05:43'], ['id' => 613]);
    $remoteClient->records['production:Computer'][2613]['date_mod'] = '2026-09-14 06:05:49';
    $remoteClient->customHistoryLogs['production:Computer'][2613] = [
        ['id' => 1091, 'id_search_option' => (int) $remoteCustomOptionId, 'itemtype_link' => '', 'date_mod' => '2026-09-22 13:56:20'],
        ['id' => 1092, 'id_search_option' => (int) $remoteCustomOptionId, 'itemtype_link' => '', 'date_mod' => '2026-09-22 13:56:20'],
        ['id' => 1093, 'id_search_option' => (int) $remoteCustomOptionId, 'itemtype_link' => '', 'date_mod' => '2026-09-22 13:56:41'],
    ];
    if (!$syncService->queueAssetIfNeeded('Computer', 613, 'production', true)) {
        throw new RuntimeException('Timezone-offset custom Both edit should queue for conflict resolution.');
    }
    $syncService->processQueue(10);
    if ((PluginFieldsComputerdmosasset::$rows[613]['namefield'] ?? null) !== 'Both verification B newer') {
        throw new RuntimeException('Custom Both should compare GLPI B UTC history against GLPI A local history before choosing a winner.');
    }
    if (($remoteClient->customRecords['production:Computer'][2613][$customKey] ?? null) !== 'Both verification B newer') {
        throw new RuntimeException('Custom Both timezone regression should keep the newer GLPI B value unchanged.');
    }
} finally {
    date_default_timezone_set($oldPhpTimezone);
    $DB->timezone = $oldDbTimezone;
    $remoteClient->dateModTimezone = 'UTC';
}

seedLinkedCustomComputer($DB, $remoteClient, 603, 2603, $customKey, $customOptionId, $remoteCustomOptionId, '', '2026-09-14 08:20:00', 'filled', '2026-09-14 08:10:00');
if (!$syncService->queueAssetIfNeeded('Computer', 603, 'production')) {
    throw new RuntimeException('Newer custom blank should queue for Both conflict resolution.');
}
$syncService->processQueue(10);
if (!array_key_exists($customKey, $remoteClient->customRecords['production:Computer'][2603]) || $remoteClient->customRecords['production:Computer'][2603][$customKey] !== '') {
    throw new RuntimeException('Custom Both should treat a newer blank as a real value.');
}

seedLinkedCustomComputer($DB, $remoteClient, 604, 2604, $customKey, $customOptionId, $remoteCustomOptionId, 'Local without reliable history', '2026-09-14 08:30:00', 'Remote without reliable history', null, false);
if (!$syncService->queueAssetIfNeeded('Computer', 604, 'production')) {
    throw new RuntimeException('Differing custom values without reliable history should still queue for blocking.');
}
$syncService->processQueue(10);
$customBlockedLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 604, 'production');
if (($customBlockedLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT) {
    throw new RuntimeException('Differing custom Both values without parent-style history should block instead of guessing parent timestamps.');
}
if (($remoteClient->customRecords['production:Computer'][2604][$customKey] ?? null) !== 'Remote without reliable history') {
    throw new RuntimeException('Blocked custom history conflict should not write GLPI B.');
}

$remoteClient->dateModTimezone = '';
try {
    seedLinkedCustomComputer($DB, $remoteClient, 614, 2614, $customKey, $customOptionId, $remoteCustomOptionId, 'Local with unknown remote timezone', '2026-09-22 21:53:07', 'Remote with unknown remote timezone', '2026-09-22 13:56:41');
    if (!$syncService->queueAssetIfNeeded('Computer', 614, 'production', true)) {
        throw new RuntimeException('Unknown remote timezone conflict should queue for blocking.');
    }
    $syncService->processQueue(10);
    $timezoneBlockedLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 614, 'production');
    if (($timezoneBlockedLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT
        || !str_contains((string) ($timezoneBlockedLink['last_error'] ?? ''), 'Set a named timezone such as UTC on the GLPI B API user')) {
        throw new RuntimeException('Unknown remote timezone conflict should explain the named API user timezone setup.');
    }
} finally {
    $remoteClient->dateModTimezone = 'UTC';
}

configureCustomNameMapping('glpi_a', $customKey, $remoteCustomOptionId);
seedLinkedCustomComputer($DB, $remoteClient, 605, 2605, $customKey, $customOptionId, $remoteCustomOptionId, 'One-way custom A value', null, 'Old one-way B value', null);
$historyRequestsBefore = count($remoteClient->customHistoryRequests);
if (!$syncService->queueAssetIfNeeded('Computer', 605, 'production')) {
    throw new RuntimeException('One-way custom A source should queue without requiring history.');
}
$syncService->processQueue(10);
if (($remoteClient->customRecords['production:Computer'][2605][$customKey] ?? null) !== 'One-way custom A value') {
    throw new RuntimeException('One-way custom A source should not fail when history is unavailable.');
}
if (count($remoteClient->customHistoryRequests) !== $historyRequestsBefore) {
    throw new RuntimeException('One-way custom mappings should not request conflict history.');
}

PluginFieldsComputerdmosasset::$rows[605]['namefield'] = 'One-way custom hook value';
$customRow = new PluginFieldsComputerdmosasset();
$customRow->fields = PluginFieldsComputerdmosasset::$rows[605];
\GlpiPlugin\Assetsync20\AssetChangeHook::onUpdate($customRow);
if (!hasPendingProductionQueue($DB, 605)) {
    throw new RuntimeException('Fields-plugin update hook should queue parent assets after custom GLPI A edits.');
}

$dropdownKey = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_departmentfielddropdowns_id';
$dropdownSearchOptionId = '884783';
$remoteDropdownSearchOptionId = '76667';
PluginFieldsContainer::$options = [
    (int) $dropdownSearchOptionId => [
        'name' => 'DMOS Department',
        'field' => 'completename',
        'table' => 'glpi_plugin_fields_departmentfielddropdowns',
        'linkfield' => 'plugin_fields_departmentfielddropdowns_id',
        'datatype' => 'dropdown',
        'pfields_type' => 'dropdown',
        'pfields_fields_id' => 8,
        'plugin_fields_containers_id' => 1,
        'is_multiple' => 0,
        'joinparams' => [
            'beforejoin' => [
                'table' => 'glpi_plugin_fields_computerdmosassets',
            ],
        ],
    ],
];
$fieldsWithDropdown = \GlpiPlugin\Assetsync20\FieldMapping::fieldsFor('Computer');
if (!in_array($dropdownKey, array_column($fieldsWithDropdown, 'key'), true)) {
    throw new RuntimeException('Fields-plugin dropdown fields should appear in the GLPI A mapping list.');
}
$remoteClient->customHistoryOptionIds[$dropdownKey] = $remoteDropdownSearchOptionId;
$remoteClient->customDropdownOptions[$dropdownKey] = [
    210 => ['id' => 210, 'name' => 'Hardware remote', 'completename' => 'Operations > Hardware'],
    211 => ['id' => 211, 'name' => 'Software remote', 'completename' => 'Operations > Software'],
    212 => ['id' => 212, 'name' => 'Empty remote', 'completename' => 'Operations > Empty'],
];

configureCustomDropdownMapping('glpi_a', $dropdownKey, $remoteDropdownSearchOptionId);
seedLinkedDropdownComputer($DB, $remoteClient, 606, 2606, $dropdownKey, 10, 211);
if (!$syncService->queueAssetIfNeeded('Computer', 606, 'production')) {
    throw new RuntimeException('One-way custom dropdown A source should queue when labels differ.');
}
$syncService->processQueue(10);
if (($remoteClient->customRecords['production:Computer'][2606][$dropdownKey] ?? null) !== 210) {
    throw new RuntimeException('Custom dropdown A-to-B sync should write the matching GLPI B option id, not the GLPI A option id.');
}

seedLinkedDropdownComputer($DB, $remoteClient, 607, 2607, $dropdownKey, 10, 211);
configureCustomDropdownMapping('glpi_b', $dropdownKey, $remoteDropdownSearchOptionId);
if (!$syncService->queueAssetIfNeeded('Computer', 607, 'production')) {
    throw new RuntimeException('One-way custom dropdown B source should queue when labels differ.');
}
$syncService->processQueue(10);
if ((PluginFieldsComputerdmosasset::$rows[607]['plugin_fields_departmentfielddropdowns_id'] ?? null) !== 11) {
    throw new RuntimeException('Custom dropdown B-to-A sync should write the matching GLPI A option id, not the GLPI B option id.');
}

seedLinkedDropdownComputer($DB, $remoteClient, 608, 2608, $dropdownKey, 0, 211);
configureCustomDropdownMapping('glpi_a', $dropdownKey, $remoteDropdownSearchOptionId);
if (!$syncService->queueAssetIfNeeded('Computer', 608, 'production')) {
    throw new RuntimeException('One-way custom dropdown blank A source should queue when B is filled.');
}
$syncService->processQueue(10);
if (!array_key_exists($dropdownKey, $remoteClient->customRecords['production:Computer'][2608]) || $remoteClient->customRecords['production:Computer'][2608][$dropdownKey] !== 0) {
    throw new RuntimeException('Custom dropdown A-to-B blank sync should clear the GLPI B option id.');
}

seedLinkedDropdownComputer($DB, $remoteClient, 609, 2609, $dropdownKey, 10, 211);
$remoteClient->customDropdownOptions[$dropdownKey] = [
    211 => ['id' => 211, 'name' => 'Software remote', 'completename' => 'Operations > Software'],
];
configureCustomDropdownMapping('glpi_a', $dropdownKey, $remoteDropdownSearchOptionId);
if (!$syncService->queueAssetIfNeeded('Computer', 609, 'production')) {
    throw new RuntimeException('Missing remote dropdown destination should still queue for blocking.');
}
$syncService->processQueue(10);
$missingDropdownLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 609, 'production');
if (($missingDropdownLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_BLOCKED_REMOTE_ERROR
    || !str_contains((string) ($missingDropdownLink['last_error'] ?? ''), 'dropdown option is missing')) {
    throw new RuntimeException('Missing remote dropdown destination should block with a clear error.');
}

seedLinkedDropdownComputer($DB, $remoteClient, 610, 2610, $dropdownKey, 10, 211);
$remoteClient->customDropdownOptions[$dropdownKey] = [
    210 => ['id' => 210, 'name' => 'Hardware remote', 'completename' => 'Operations > Hardware'],
    211 => ['id' => 211, 'name' => 'Software remote', 'completename' => 'Operations > Software'],
    213 => ['id' => 213, 'name' => 'Duplicate hardware remote', 'completename' => 'Operations > Hardware'],
];
configureCustomDropdownMapping('glpi_a', $dropdownKey, $remoteDropdownSearchOptionId);
if (!$syncService->queueAssetIfNeeded('Computer', 610, 'production')) {
    throw new RuntimeException('Duplicate remote dropdown destination should still queue for blocking.');
}
$syncService->processQueue(10);
$duplicateDropdownLink = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 610, 'production');
if (($duplicateDropdownLink['status'] ?? '') !== \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_BLOCKED_REMOTE_ERROR
    || !str_contains((string) ($duplicateDropdownLink['last_error'] ?? ''), 'dropdown option label is duplicated')) {
    throw new RuntimeException('Duplicate remote dropdown destination should block with a clear error.');
}
$remoteClient->customDropdownOptions[$dropdownKey] = [
    210 => ['id' => 210, 'name' => 'Hardware remote', 'completename' => 'Operations > Hardware'],
    211 => ['id' => 211, 'name' => 'Software remote', 'completename' => 'Operations > Software'],
    212 => ['id' => 212, 'name' => 'Empty remote', 'completename' => 'Operations > Empty'],
];

$billingStartKey = 'Computer.PluginFieldsComputerdmosasset.hwbillingstartdatefield';
$billingEndKey = 'Computer.PluginFieldsComputerdmosasset.hwbillingenddatefield';
$billingFrequencyKey = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_hwbillingfrequencyfielddropdowns_id';
$billingMonthKey = 'Computer.PluginFieldsComputerdmosasset.hwbillingmonthfield';
PluginFieldsField::$definitions += [
    20 => [
        'id' => 20,
        'name' => 'statusfield',
        'type' => 'text',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    21 => [
        'id' => 21,
        'name' => 'ownershipfield',
        'type' => 'text',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    22 => [
        'id' => 22,
        'name' => 'hwbillablefieldtwo',
        'type' => 'yesno',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    23 => [
        'id' => 23,
        'name' => 'installeddatevariantfield',
        'type' => 'date',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    24 => [
        'id' => 24,
        'name' => 'computermodelfield',
        'type' => 'text',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    25 => [
        'id' => 25,
        'name' => 'hwbillingstartdatefield',
        'type' => 'date',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    26 => [
        'id' => 26,
        'name' => 'hwbillingenddatefield',
        'type' => 'date',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    27 => [
        'id' => 27,
        'name' => 'hwbillingfrequencyfield',
        'type' => 'dropdown',
        'multiple' => 0,
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    28 => [
        'id' => 28,
        'name' => 'hwbillingmonthfield',
        'type' => 'number',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
];
PluginFieldsContainer::$options = [
    884790 => [
        'name' => 'DMOS Asset - Status',
        'field' => 'statusfield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'text',
        'pfields_fields_id' => 20,
    ],
    884791 => [
        'name' => 'DMOS Asset - Ownership',
        'field' => 'ownershipfield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'text',
        'pfields_fields_id' => 21,
    ],
    884792 => [
        'name' => 'DMOS Asset - HW Billable',
        'field' => 'hwbillablefieldtwo',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'yesno',
        'pfields_fields_id' => 22,
    ],
    884793 => [
        'name' => 'DMOS Asset - Installed Date',
        'field' => 'installeddatevariantfield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'date',
        'pfields_fields_id' => 23,
    ],
    884794 => [
        'name' => 'DMOS Asset - Computer Model',
        'field' => 'computermodelfield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'text',
        'pfields_fields_id' => 24,
    ],
    884795 => [
        'name' => 'DMOS Asset - HW Billing Start Date',
        'field' => 'hwbillingstartdatefield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'date',
        'pfields_fields_id' => 25,
    ],
    884796 => [
        'name' => 'DMOS Asset - HW Billing End Date',
        'field' => 'hwbillingenddatefield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'date',
        'pfields_fields_id' => 26,
    ],
    884797 => [
        'name' => 'DMOS Asset - HW Billing Frequency',
        'field' => 'completename',
        'table' => 'glpi_plugin_fields_hwbillingfrequencyfielddropdowns',
        'linkfield' => 'plugin_fields_hwbillingfrequencyfielddropdowns_id',
        'datatype' => 'dropdown',
        'pfields_type' => 'dropdown',
        'pfields_fields_id' => 27,
        'is_multiple' => 0,
        'joinparams' => [
            'beforejoin' => [
                'table' => 'glpi_plugin_fields_computerdmosassets',
            ],
        ],
    ],
    884798 => [
        'name' => 'DMOS Asset - HW Billing Month',
        'field' => 'hwbillingmonthfield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'number',
        'pfields_fields_id' => 28,
    ],
];
\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_a',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
        'source_of_truth' => 'glpi_a',
    ],
    $billingStartKey => [
        'glpi_b_field_key' => $billingStartKey,
        'source_of_truth' => 'glpi_a',
    ],
    $billingEndKey => [
        'glpi_b_field_key' => $billingEndKey,
        'source_of_truth' => 'glpi_a',
    ],
    $billingFrequencyKey => [
        'glpi_b_field_key' => $billingFrequencyKey,
        'source_of_truth' => 'glpi_a',
    ],
    $billingMonthKey => [
        'glpi_b_field_key' => $billingMonthKey,
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
        'key' => $billingStartKey,
        'id' => '884795',
        'uid' => $billingStartKey,
        'label' => 'HW Billing Start Date',
    ],
    [
        'key' => $billingEndKey,
        'id' => '884796',
        'uid' => $billingEndKey,
        'label' => 'HW Billing End Date',
    ],
    [
        'key' => $billingFrequencyKey,
        'id' => '884797',
        'uid' => $billingFrequencyKey,
        'label' => 'HW Billing Frequency',
    ],
    [
        'key' => $billingMonthKey,
        'id' => '884798',
        'uid' => $billingMonthKey,
        'label' => 'HW Billing Month',
    ],
]);
$DB->insert('glpi_computers', [
    'id' => 611,
    'entities_id' => 20,
    'is_deleted' => 0,
    'name' => 'Billing Local',
    'serial' => 'SER-611',
    'otherserial' => '',
    'comment' => '',
    'date_mod' => '2026-01-01 00:00:00',
]);
PluginFieldsComputerdmosasset::$rows[611] = [
    'id' => 1611,
    'items_id' => 611,
    'itemtype' => 'Computer',
    'plugin_fields_containers_id' => 1,
    'statusfield' => 'Active',
    'ownershipfield' => 'Customer Leased',
    'hwbillablefieldtwo' => 1,
    'installeddatevariantfield' => '2026-01-06',
    'computermodelfield' => 'Lenovo ThinkPad L14 Gen 6',
];
$remoteClient->records['production:Computer'][2611] = [
    'id' => 2611,
    'entities_id' => 200,
    'is_deleted' => 0,
    'name' => 'Billing Remote',
    'serial' => 'SER-611',
    'date_mod' => '2026-01-01 00:00:00',
];
$remoteClient->customDropdownOptions[$billingFrequencyKey] = [
    310 => ['id' => 310, 'name' => 'Monthly', 'completename' => 'Monthly'],
    311 => ['id' => 311, 'name' => 'Annual', 'completename' => 'Annual'],
];
$billingAprilService = new \GlpiPlugin\Assetsync20\AssetSyncService($remoteClient, new DateTimeImmutable('2026-04-20'));
if (!$billingAprilService->queueAssetIfNeeded('Computer', 611, 'production')) {
    throw new RuntimeException('Hardware Billing should queue an eligible asset when billing fields are mapped.');
}
$billingLocal = PluginFieldsComputerdmosasset::$rows[611] ?? [];
if (
    ($billingLocal['hwbillingstartdatefield'] ?? null) !== '2026-02-01'
    || ($billingLocal['hwbillingenddatefield'] ?? null) !== '2030-01-31'
    || ($billingLocal['plugin_fields_hwbillingfrequencyfielddropdowns_id'] ?? null) !== 1
    || ($billingLocal['hwbillingmonthfield'] ?? null) !== 3
) {
    throw new RuntimeException('Hardware Billing should write calculated monthly values to GLPI A custom fields.');
}
$billingAprilService->processQueue(10);
$billingRecord = $remoteClient->customRecords['production:Computer'][2611] ?? [];
if (
    ($billingRecord[$billingStartKey] ?? null) !== '2026-02-01'
    || ($billingRecord[$billingEndKey] ?? null) !== '2030-01-31'
    || ($billingRecord[$billingFrequencyKey] ?? null) !== 310
    || ($billingRecord[$billingMonthKey] ?? null) !== 3
) {
    throw new RuntimeException('Mapped Hardware Billing fields should sync calculated monthly values to GLPI B custom fields.');
}

$billingMayService = new \GlpiPlugin\Assetsync20\AssetSyncService($remoteClient, new DateTimeImmutable('2026-05-01'));
if (!$billingMayService->queueAssetIfNeeded('Computer', 611, 'production')) {
    throw new RuntimeException('Hardware Billing month rollover should queue without normal payload changes.');
}
$billingMayService->processQueue(10);
$billingLocal = PluginFieldsComputerdmosasset::$rows[611] ?? [];
if (($billingLocal['hwbillingmonthfield'] ?? null) !== 4) {
    throw new RuntimeException('Hardware Billing month rollover should update the GLPI A billing month.');
}
$billingRecord = $remoteClient->customRecords['production:Computer'][2611] ?? [];
if (($billingRecord[$billingMonthKey] ?? null) !== 4) {
    throw new RuntimeException('Hardware Billing month rollover should update the GLPI B billing month.');
}

PluginFieldsComputerdmosasset::$rows[611]['hwbillablefieldtwo'] = 0;
if (!$billingMayService->queueAssetIfNeeded('Computer', 611, 'production')) {
    throw new RuntimeException('Hardware Billing should queue when an asset becomes ineligible.');
}
$billingMayService->processQueue(10);
$billingLocal = PluginFieldsComputerdmosasset::$rows[611] ?? [];
if (
    ($billingLocal['hwbillingstartdatefield'] ?? null) !== ''
    || ($billingLocal['hwbillingenddatefield'] ?? null) !== ''
    || ($billingLocal['plugin_fields_hwbillingfrequencyfielddropdowns_id'] ?? null) !== 0
    || ($billingLocal['hwbillingmonthfield'] ?? null) !== 0
) {
    throw new RuntimeException('Hardware Billing should clear/reset GLPI A values when an asset becomes ineligible.');
}
$billingRecord = $remoteClient->customRecords['production:Computer'][2611] ?? [];
if (
    ($billingRecord[$billingStartKey] ?? null) !== ''
    || ($billingRecord[$billingEndKey] ?? null) !== ''
    || ($billingRecord[$billingFrequencyKey] ?? null) !== 0
    || ($billingRecord[$billingMonthKey] ?? null) !== 0
) {
    throw new RuntimeException('Hardware Billing should clear/reset GLPI B values when an asset becomes ineligible.');
}

$hwBillableKey = 'Computer.PluginFieldsComputerdmosasset.hwbillablefieldtwo';
$hwSavedConfig = Config::$values['plugin:assetsync20']['field_mappings'];
$hwMappings = json_decode($hwSavedConfig, true);
$hwMappings['production']['Computer'][$hwBillableKey] = [
    'glpi_a_field_key' => $hwBillableKey,
    'glpi_b_field_key' => $hwBillableKey,
    'glpi_b_field_id' => '884792',
    'glpi_b_field_uid' => $hwBillableKey,
    'glpi_b_field_label' => 'HW Billable',
    'source_of_truth' => 'glpi_b',
];
Config::$values['plugin:assetsync20']['field_mappings'] = json_encode($hwMappings);
$remoteClient->customRecords['production:Computer'][2611][$hwBillableKey] = 1;
if (!$billingMayService->queueAssetIfNeeded('Computer', 611, 'production', true)) {
    throw new RuntimeException('Inbound HW Billable change should queue for processing.');
}
$billingMayService->processQueue(10);
if (PluginFieldsComputerdmosasset::$rows[611]['hwbillablefieldtwo'] !== 1
    || PluginFieldsComputerdmosasset::$rows[611]['hwbillingstartdatefield'] !== '2026-02-01') {
    throw new RuntimeException('Inbound HW Billable must recalculate local HW outputs in the same run.');
}
if (($remoteClient->customRecords['production:Computer'][2611][$billingStartKey] ?? null) !== '2026-02-01') {
    throw new RuntimeException('Mapped HW outputs must reach GLPI B in the same run as an inbound HW input.');
}
Config::$values['plugin:assetsync20']['field_mappings'] = $hwSavedConfig;

$swsdStatusKey = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_statusfielddropdowns_id';
$swsdRedeployedKey = 'Computer.PluginFieldsComputerdmosasset.redeployeddatefield';
$swsdBillableKey = 'Computer.PluginFieldsComputerdmosasset.swsdbillablefieldtwo';
$swsdStartKey = 'Computer.PluginFieldsComputerdmosasset.swsdbillingstartdatefield';
PluginFieldsField::$definitions += [
    29 => [
        'id' => 29,
        'name' => 'statusfield',
        'type' => 'dropdown',
        'multiple' => 0,
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    30 => [
        'id' => 30,
        'name' => 'redeployeddatefield',
        'type' => 'date',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 0,
    ],
    31 => [
        'id' => 31,
        'name' => 'swsdbillablefieldtwo',
        'type' => 'yesno',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 1,
    ],
    32 => [
        'id' => 32,
        'name' => 'swsdbillingstartdatefield',
        'type' => 'date',
        'is_active' => 1,
        'plugin_fields_containers_id' => 1,
        'is_readonly' => 1,
    ],
];
PluginFieldsContainer::$options = [
    884810 => [
        'name' => 'DMOS Asset - Status',
        'field' => 'completename',
        'table' => 'glpi_plugin_fields_statusfielddropdowns',
        'linkfield' => 'plugin_fields_statusfielddropdowns_id',
        'datatype' => 'dropdown',
        'pfields_type' => 'dropdown',
        'pfields_fields_id' => 29,
        'is_multiple' => 0,
        'joinparams' => [
            'beforejoin' => [
                'table' => 'glpi_plugin_fields_computerdmosassets',
            ],
        ],
    ],
    884811 => [
        'name' => 'DMOS Asset - Redeployed Date',
        'field' => 'redeployeddatefield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'date',
        'pfields_fields_id' => 30,
    ],
    884812 => [
        'name' => 'DMOS Asset - SW/SD Billable',
        'field' => 'swsdbillablefieldtwo',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'yesno',
        'pfields_fields_id' => 31,
    ],
    884813 => [
        'name' => 'DMOS Asset - SW/SD Billing Start Date',
        'field' => 'swsdbillingstartdatefield',
        'table' => 'glpi_plugin_fields_computerdmosassets',
        'pfields_type' => 'date',
        'pfields_fields_id' => 32,
    ],
];
\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    $swsdBillableKey => [
        'glpi_b_field_key' => $swsdBillableKey,
        'source_of_truth' => 'glpi_b',
    ],
    $swsdStartKey => [
        'glpi_b_field_key' => $swsdStartKey,
        'source_of_truth' => 'glpi_b',
    ],
], [
    [
        'key' => $swsdBillableKey,
        'id' => '884812',
        'uid' => $swsdBillableKey,
        'label' => 'SW/SD Billable',
    ],
    [
        'key' => $swsdStartKey,
        'id' => '884813',
        'uid' => $swsdStartKey,
        'label' => 'SW/SD Billing Start Date',
    ],
]);
$swsdRemoteAuthoritySources = [];
foreach (\GlpiPlugin\Assetsync20\FieldMapping::syncMappings('production', 'Computer') as $swsdMapping) {
    $swsdRemoteAuthoritySources[$swsdMapping['glpi_a_field']] = $swsdMapping['source_of_truth'];
}
if (
    ($swsdRemoteAuthoritySources[$swsdBillableKey] ?? null) !== 'glpi_b'
    || ($swsdRemoteAuthoritySources[$swsdStartKey] ?? null) !== 'glpi_b'
) {
    throw new RuntimeException('SW/SD Billing output mappings should keep GLPI B authority when configured.');
}

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => [
        'glpi_b_field_key' => 'Computer.name',
        'source_of_truth' => 'glpi_a',
    ],
    'serial' => [
        'glpi_b_field_key' => 'Computer.serial',
        'source_of_truth' => 'glpi_a',
    ],
    $swsdStatusKey => [
        'glpi_b_field_key' => $swsdStatusKey,
        'source_of_truth' => 'glpi_b',
    ],
    $swsdRedeployedKey => [
        'glpi_b_field_key' => $swsdRedeployedKey,
        'source_of_truth' => 'glpi_b',
    ],
    $swsdBillableKey => [
        'glpi_b_field_key' => $swsdBillableKey,
        'source_of_truth' => 'glpi_a',
    ],
    $swsdStartKey => [
        'glpi_b_field_key' => $swsdStartKey,
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
        'key' => $swsdStatusKey,
        'id' => '884810',
        'uid' => $swsdStatusKey,
        'label' => 'Status',
    ],
    [
        'key' => $swsdRedeployedKey,
        'id' => '884811',
        'uid' => $swsdRedeployedKey,
        'label' => 'Redeployed Date',
    ],
    [
        'key' => $swsdBillableKey,
        'id' => '884812',
        'uid' => $swsdBillableKey,
        'label' => 'SW/SD Billable',
    ],
    [
        'key' => $swsdStartKey,
        'id' => '884813',
        'uid' => $swsdStartKey,
        'label' => 'SW/SD Billing Start Date',
    ],
]);
$swsdOutboundSources = [];
foreach (\GlpiPlugin\Assetsync20\FieldMapping::syncMappings('production', 'Computer') as $swsdMapping) {
    $swsdOutboundSources[$swsdMapping['glpi_a_field']] = $swsdMapping['source_of_truth'];
}
if (
    ($swsdOutboundSources[$swsdBillableKey] ?? null) !== 'glpi_a'
    || ($swsdOutboundSources[$swsdStartKey] ?? null) !== 'glpi_a'
) {
    throw new RuntimeException('SW/SD Billing output mappings should keep GLPI A authority when configured.');
}
$DB->insert('glpi_computers', [
    'id' => 612,
    'entities_id' => 20,
    'is_deleted' => 0,
    'name' => 'SWSD Local',
    'serial' => 'SER-612',
    'otherserial' => '',
    'comment' => '',
    'date_mod' => '2026-03-01 00:00:00',
]);
PluginFieldsComputerdmosasset::$rows[612] = [
    'id' => 1612,
    'items_id' => 612,
    'itemtype' => 'Computer',
    'plugin_fields_containers_id' => 1,
    'plugin_fields_statusfielddropdowns_id' => 21,
    'redeployeddatefield' => '',
    'swsdbillablefieldtwo' => 0,
    'swsdbillingstartdatefield' => '',
];
$remoteClient->records['production:Computer'][2612] = [
    'id' => 2612,
    'entities_id' => 200,
    'is_deleted' => 0,
    'name' => 'SWSD Remote',
    'serial' => 'SER-612',
    'date_mod' => '2026-03-06 00:00:00',
];
$remoteClient->customDropdownOptions[$swsdStatusKey] = [
    410 => ['id' => 410, 'name' => 'In Use', 'completename' => 'In Use'],
    411 => ['id' => 411, 'name' => 'In Stock - Available', 'completename' => 'In Stock - Available'],
];
$remoteClient->customRecords['production:Computer'][2612] = [
    $swsdStatusKey => 410,
    $swsdRedeployedKey => '2026-03-06',
    $swsdBillableKey => 0,
];
$remoteClient->readonlyCustomKeys[$swsdBillableKey] = true;
$remoteClient->readonlyCustomKeys[$swsdStartKey] = true;
$readonlyRemoteWrite = $remoteClient->customTextValues(
    ['id' => 'production'],
    'Computer',
    2612,
    [$swsdBillableKey],
    [$swsdBillableKey => 1],
    [$swsdBillableKey => 'yesno']
);
if ($readonlyRemoteWrite['success']) {
    throw new RuntimeException('Default GLPI B custom writes should still respect readonly protection.');
}
\GlpiPlugin\Assetsync20\AssetSyncLink::save([
    'itemtype' => 'Computer',
    'items_id' => 612,
    'glpi_b_connection_id' => 'production',
    'route_id' => 'prod-child',
    'remote_items_id' => 2612,
    'status' => \GlpiPlugin\Assetsync20\AssetSyncLink::STATUS_SYNCED,
    'last_payload_hash' => '',
    'last_payload_date' => '2026-03-01 00:00:00',
]);
$swsdService = new \GlpiPlugin\Assetsync20\AssetSyncService($remoteClient);
if (!$swsdService->queueAssetIfNeeded('Computer', 612, 'production', true)) {
    throw new RuntimeException('SW/SD Billing inbound source changes should queue for processing.');
}
$swsdService->processQueue(10);
$swsdLocal = PluginFieldsComputerdmosasset::$rows[612] ?? [];
if (
    ($swsdLocal['plugin_fields_statusfielddropdowns_id'] ?? null) !== 20
    || ($swsdLocal['redeployeddatefield'] ?? null) !== '2026-03-06'
    || ($swsdLocal['swsdbillablefieldtwo'] ?? null) !== 1
    || ($swsdLocal['swsdbillingstartdatefield'] ?? null) !== '2026-04-01'
) {
    throw new RuntimeException('SW/SD Billing should recalculate from inbound Status and Redeployed Date in the same run.');
}
if (($remoteClient->customRecords['production:Computer'][2612][$swsdBillableKey] ?? null) !== 1
    || ($remoteClient->customRecords['production:Computer'][2612][$swsdStartKey] ?? null) !== '2026-04-01') {
    throw new RuntimeException('A-authority SWSD outputs must reach read-only GLPI B in the same run as new inbound inputs.');
}

$swsdSavedConfig = Config::$values['plugin:assetsync20']['field_mappings'];
$swsdConfig = json_decode($swsdSavedConfig, true);
foreach (['glpi_b', 'both'] as $authority) {
    foreach ([$swsdBillableKey, $swsdStartKey] as $key) {
        $swsdConfig['production']['Computer'][$key]['source_of_truth'] = $authority;
    }
    Config::$values['plugin:assetsync20']['field_mappings'] = json_encode($swsdConfig);
    // Writable fixtures exercise ordinary inbound authority; readonly is checked separately below.
    PluginFieldsField::$definitions[31]['is_readonly'] = 0;
    PluginFieldsField::$definitions[32]['is_readonly'] = 0;
    PluginFieldsComputerdmosasset::$rows[612]['swsdbillablefieldtwo'] = 1;
    PluginFieldsComputerdmosasset::$rows[612]['swsdbillingstartdatefield'] = '2026-04-01';
    $remoteClient->customRecords['production:Computer'][2612][$swsdBillableKey] = 0;
    $remoteClient->customRecords['production:Computer'][2612][$swsdStartKey] = '2027-01-01';
    foreach ([$swsdBillableKey => '884812', $swsdStartKey => '884813'] as $key => $option) {
        $remoteClient->customHistoryOptionIds[$key] = $option;
        $remoteClient->customHistoryDates['production:Computer'][2612][$option] = '2027-02-01 00:00:00';
        $DB->insert('glpi_logs', ['itemtype' => 'Computer', 'items_id' => 612,
            'itemtype_link' => '', 'id_search_option' => (int) $option, 'date_mod' => '2026-01-01 00:00:00']);
    }
    for ($run = 0; $run < 2; $run++) {
        $swsdService->queueAssetIfNeeded('Computer', 612, 'production', true);
        $swsdService->processQueue(10);
        $link = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 612, 'production');
        if ($link['status'] !== 'synced'
            || PluginFieldsComputerdmosasset::$rows[612]['swsdbillablefieldtwo'] !== 0
            || PluginFieldsComputerdmosasset::$rows[612]['swsdbillingstartdatefield'] !== '2027-01-01') {
            throw new RuntimeException('SWSD ' . $authority . ' authority must persist across full sync runs.');
        }
    }
}
// Both must also preserve a newer local output instead of recalculating it.
foreach (['884812', '884813'] as $option) {
    $DB->insert('glpi_logs', ['itemtype' => 'Computer', 'items_id' => 612,
        'itemtype_link' => '', 'id_search_option' => (int) $option, 'date_mod' => '2028-01-01 00:00:00']);
}
PluginFieldsComputerdmosasset::$rows[612]['swsdbillingstartdatefield'] = '2028-03-01';
$swsdService->queueAssetIfNeeded('Computer', 612, 'production', true);
$swsdService->processQueue(10);
if ($remoteClient->customRecords['production:Computer'][2612][$swsdStartKey] !== '2028-03-01') {
    throw new RuntimeException('Both must send newer local SWSD output through normal mapping.');
}
foreach ([$swsdBillableKey, $swsdStartKey] as $key) {
    $swsdConfig['production']['Computer'][$key]['source_of_truth'] = 'glpi_b';
}
Config::$values['plugin:assetsync20']['field_mappings'] = json_encode($swsdConfig);
PluginFieldsField::$definitions[31]['is_readonly'] = 1;
PluginFieldsField::$definitions[32]['is_readonly'] = 1;
$remoteClient->customRecords['production:Computer'][2612][$swsdBillableKey] = 1;
$swsdService->queueAssetIfNeeded('Computer', 612, 'production', true);
$swsdService->processQueue(10);
$link = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 612, 'production');
if ($link['status'] !== 'synced' || PluginFieldsComputerdmosasset::$rows[612]['swsdbillablefieldtwo'] !== 1) {
    throw new RuntimeException('B authority must update read-only GLPI A destination fields.');
}
PluginFieldsField::$definitions[31]['is_readonly'] = 0;
PluginFieldsField::$definitions[32]['is_readonly'] = 0;
// Unmapped outputs stay local, including a start date that does not exist on B.
unset($swsdConfig['production']['Computer'][$swsdBillableKey], $swsdConfig['production']['Computer'][$swsdStartKey]);
Config::$values['plugin:assetsync20']['field_mappings'] = json_encode($swsdConfig);
unset($remoteClient->customRecords['production:Computer'][2612][$swsdStartKey]);
$remoteClient->unavailableCustomKeys = [$swsdStartKey];
$remoteClient->customRecords['production:Computer'][2612][$swsdRedeployedKey] = '2026-03-05';
$swsdService->queueAssetIfNeeded('Computer', 612, 'production', true);
$swsdService->processQueue(10);
$link = \GlpiPlugin\Assetsync20\AssetSyncLink::find('Computer', 612, 'production');
if ($link['status'] !== 'synced'
    || PluginFieldsComputerdmosasset::$rows[612]['swsdbillingstartdatefield'] !== '2026-03-01'
    || isset($remoteClient->customRecords['production:Computer'][2612][$swsdStartKey])) {
    throw new RuntimeException('Unmapped SWSD start date must calculate locally without creating a remote value.');
}
Config::$values['plugin:assetsync20']['field_mappings'] = $swsdSavedConfig;
$remoteClient->unavailableCustomKeys = [];

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
