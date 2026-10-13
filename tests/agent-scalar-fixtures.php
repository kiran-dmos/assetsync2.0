<?php

declare(strict_types=1);

// Host-only GLPI doubles. Production task, journal, normalization and SQL transaction code runs unchanged.
require_once __DIR__ . '/query-expression.php';
require_once __DIR__ . '/agent-sqlite.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../assetsync20agent/src/autoload.php';
define('READ', 1);
define('UPDATE', 2);
define('CREATE', 4);

class ScalarDB
{
    public AgentSqlite $sql;
    public string $nativeTimestampType = 'timestamp';
    public function __construct() { $this->sql = new AgentSqlite(); }
    public function quote(string $value): string { return $this->sql->quote($value); }
    public function tableExists(string $table): bool { return $this->sql->tableExists($table); }
    public function fieldExists(string $table, string $column): bool { return $this->sql->fieldExists($table, $column); }
    public function request(array $query): ArrayIterator { return $this->sql->request($query); }
    public function insert(string $table, array $fields): bool { return $this->sql->insert($table, $fields); }
    public function update(string $table, array $fields, array $where): bool { return $this->sql->update($table, $fields, $where); }
    public function fetchAssoc(PDOStatement $result): ?array { return $this->sql->fetchAssoc($result); }
    public function affectedRows(): int { return $this->sql->affectedRows(); }
    public function firstRow(string $table, array $where): ?array { return $this->sql->firstRow($table, $where); }
    public function doQuery(string $sql): mixed
    {
        // SQLite verifies rollback, not InnoDB lock semantics (reserved for independent live verification).
        if (str_starts_with($sql, 'SELECT ENGINE FROM information_schema.TABLES')) {
            return $this->sql->pdo->query("SELECT 'InnoDB' AS ENGINE");
        }
        if (str_starts_with($sql, 'SELECT DATA_TYPE FROM information_schema.COLUMNS')) {
            return $this->sql->pdo->query('SELECT ' . $this->quote($this->nativeTimestampType) . ' AS DATA_TYPE');
        }
        return $this->sql->doQuery($sql);
    }
}

class Config
{
    public static array $values = [];
    public static function getConfigurationValues($context, array $names = []): array
    {
        $values = self::$values[$context] ?? [];
        return $names === [] ? $values : array_intersect_key($values, array_flip($names));
    }
    public static function setConfigurationValues($context, array $values): void
    {
        self::$values[$context] = array_replace(self::$values[$context] ?? [], $values);
    }
}
class GLPIKey
{
    public function encrypt($value): string { return $value; }
    public function decrypt($value): string { return $value; }
}
class DBConnection
{
    public static function getDefaultCharset(): string { return 'utf8mb4'; }
    public static function getDefaultCollation(): string { return 'utf8mb4_unicode_ci'; }
    public static function getDefaultPrimaryKeySignOption(): string { return 'UNSIGNED'; }
}
class Plugin
{
    public static function isPluginActive($name): bool { return $name === 'fields'; }
}
class Session
{
    public static bool $entityAccess = true;
    public static function checkRight($right, $level): void {}
    public static function getNewCSRFToken(): string { return 'test-csrf'; }
    public static function destroy(): void { $_SESSION = []; }
    public static function start(): void {}
    public static function loadLanguage(): void {}
    public static function changeProfile($id): void { $_SESSION['glpiactiveprofile'] = ['id' => $id]; }
    public static function changeActiveEntities($id, $recursive): bool { $_SESSION['glpiactive_entity'] = $id; return true; }
    public static function getLoginUserID(bool $forceHuman = true): int|string { return !$forceHuman && isset($_SESSION['glpicronuserrunning']) ? $_SESSION['glpicronuserrunning'] : ($_SESSION['glpiID'] ?? 0); }
    public static function isCron(): bool { return isset($_SESSION['glpicronuserrunning']); }
    public static function isInventory(): bool { return false; }
    public static function isRightChecksDisabled(): bool { return false; }
    public static function haveAccessToEntity($id): bool { return self::$entityAccess; }
}
class Auth
{
    public function login($name, $password, $remember): bool
    {
        $_SESSION = ['glpiID' => 7, 'glpiprofiles' => [4 => []]];
        return true;
    }
}
class User
{
    public array $fields = [];
    public static bool $active = true;
    public function getFromDB($id): bool { $this->fields = ['is_active' => self::$active, 'is_deleted' => 0]; return true; }
    public static function getNameForLog($id): string { return 'Scalar service (' . $id . ')'; }
}
class Profile
{
    public array $fields = [];
    public static int $rights = READ | UPDATE;
    public function getFromDB($id): bool { $this->fields = ['computer' => self::$rights, 'printer' => self::$rights]; return true; }
}
class Profile_User
{
    public static bool $granted = true;
    public function getFromDBByCrit($criteria): bool { return self::$granted; }
}
class Dropdown
{
    public static function getDropdownName($table, $id): string { return 'Entity ' . $id; }
    public static function getYesNo($value): string { return $value ? 'Oui' : 'Non'; }
}
class Search
{
    public static array $options = [];
    public static array $typedOptions = [];
    public static int $clears = 0;
    public static ?Closure $onClear = null;
    public static function getOptions($type): array { return self::$typedOptions[$type] ?? self::$options; }
    public static function getOptionsForItemtype($type): array { return self::getOptions($type); }
    public static function clearSearchOptionCache($type): void
    {
        self::$clears++;
        if (self::$onClear !== null) { (self::$onClear)(); }
    }
}
class_alias(Search::class, 'Glpi\\Search\\SearchOption');

class Computer
{
    public static function getTable(): string { return 'glpi_computers'; }
    public array $fields = [];
    public static bool $allowUpdate = true;
    public static bool $allowRead = true;
    public static bool $allowCreate = false;
    public static int $adds = 0;
    public static ?Closure $afterAdd = null;
    public function getFromDB($id): bool
    {
        global $DB;
        $this->fields = $DB->firstRow(static::getTable(), ['id' => $id]) ?? [];
        return $this->fields !== [];
    }
    public function can($id, $right, ?array &$input = null): bool
    {
        if ($right === CREATE) { return self::$allowCreate; }
        return ($right !== READ || self::$allowRead) && ($right !== UPDATE || self::$allowUpdate) && $this->getFromDB($id);
    }
    public function add(array $input): int
    {
        global $DB;
        if (!self::$allowCreate) { throw new LogicException('Unexpected parent creation'); }
        self::$adds++;
        $id = (int) $DB->sql->pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM ' . static::getTable())->fetchColumn();
        $DB->insert(static::getTable(), ['id' => $id] + $input + ['name' => '', 'comment' => '', 'states_id' => 0]);
        $DB->insert('glpi_logs', ['items_id' => $id, 'itemtype' => static::class, 'id_search_option' => 0,
            'itemtype_link' => '0', 'linked_action' => Log::HISTORY_CREATE_ITEM, 'field' => 'Creation',
            'user_name' => User::getNameForLog(Session::getLoginUserID()), 'old_value' => '', 'new_value' => '']);
        if (self::$afterAdd) { (self::$afterAdd)($id); }
        return $id;
    }
    public function update(array $input): bool
    {
        global $DB;
        $id = $input['id'];
        foreach (array_diff_key($input, ['id' => true]) as $column => $value) {
            $old = $DB->firstRow(static::getTable(), ['id' => $id])[$column];
            $DB->update(static::getTable(), [$column => $value], ['id' => $id]);
            Log::record($id, $column, $old, $value, static::class);
        }
        if ($DB->fieldExists(static::getTable(), 'date_mod')) {
            $DB->update(static::getTable(), ['date_mod' => $_SESSION['glpi_currenttime'] ?? gmdate('Y-m-d H:i:s')], ['id' => $id]);
        }
        return true;
    }
}

function getTableForItemType($class): string { return $class::getTable(); }
function getItemTypeForTable($table): string { return $table === 'glpi_plugin_fields_printerscalars' ? 'PluginFieldsPrinterscalar' : 'PluginFieldsComputerscalar'; }
class PluginFieldsProfile
{
    public static int $right = UPDATE;
    public static function getRightOnContainer($profile, $container): int { return self::$right; }
}
class PluginFieldsField
{
    public array $fields = [];
    public function getFromDB($id): bool
    {
        global $DB;
        $this->fields = $DB->firstRow('glpi_plugin_fields_fields', ['id' => $id]) ?? [];
        return $this->fields !== [];
    }
}
class PluginFieldsStatusOverride
{
    public static array $overrides = [];
    public static function getStatusFieldName(string $type): string { return 'states_id'; }
    public static function getOverridesForItemtypeAndStatus(int $container, string $type, int $status): array
    {
        return array_filter(self::$overrides, static fn ($row) => $row['plugin_fields_containers_id'] === $container
            && $row['itemtype'] === $type && in_array($status, $row['states'], true));
    }
}
class PluginFieldsContainer
{
    public array $fields = [];
    public static string $fault = '';
    public static int $calls = 0;
    public static array $inputs = [];
    public static function getClassname($type, $name): string { return $type === 'Printer' ? 'PluginFieldsPrinterscalar' : 'PluginFieldsComputerscalar'; }
    public static function getAddSearchOptions($type): array
    {
        $class = self::getClassname($type, 'scalars');
        return array_filter(Search::getOptions($type), static fn ($option) => $option['table'] === $class::getTable());
    }
    public function getFromDB($id): bool
    {
        global $DB;
        $this->fields = $DB->firstRow('glpi_plugin_fields_containers', ['id' => $id]) ?? [];
        return $this->fields !== [];
    }
    public static function validateValues($input, $type, $massiveaction): bool
    {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields']) as $field) {
            if ($field['mandatory'] && array_key_exists($field['name'], $input) && $input[$field['name']] === '') { return false; }
        }
        return true;
    }
    public function updateFieldsValues(array $input, $type, $massiveaction): bool
    {
        self::$calls++;
        self::$inputs[] = $input;
        if (self::$fault === 'deny' || !self::validateValues($input, $type, $massiveaction)) { return false; }
        if (self::$fault === 'ignore') { return true; }
        $class = self::getClassname($type, 'scalars');
        $row = new $class();
        if (!$row->getFromDBByCrit(['items_id' => $input['items_id']])) { return $row->add($input); }
        return $row->update(['id' => $row->fields['id']] + $input);
    }
}
class PluginFieldsComputerscalar
{
    public array $fields = [];
    public static bool $ignoreUpdate = false;
    public static bool $dateNullSentinel = true;
    public static function getTable(): string { return 'glpi_plugin_fields_computerscalars'; }
    public function getFromDBByCrit($criteria): bool
    {
        global $DB;
        $this->fields = $DB->firstRow(static::getTable(), $criteria) ?? [];
        return $this->fields !== [];
    }
    public function update(array $input): bool
    {
        global $DB;
        if (self::$ignoreUpdate) { return true; }
        $before = $DB->firstRow(static::getTable(), ['id' => $input['id']]);
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => ['plugin_fields_containers_id' => $before['plugin_fields_containers_id']]]) as $field) {
            $column = $field['name'];
            if (!array_key_exists($column, $input)) { continue; }
            $old = $before[$column];
            $value = $input[$column];
            // Match the official model's loose comparison and date SQL NULL sentinel.
            $modelValue = $field['type'] === 'date' && $value === '' && self::$dateNullSentinel ? 'NULL' : $value;
            if ($old == $modelValue) { continue; }
            $stored = $modelValue === 'NULL' ? null : $value;
            $DB->update(static::getTable(), [$column => $stored], ['id' => $before['id']]);
            if (PluginFieldsContainer::$fault !== 'history_missing') {
                $auditOld = $field['type'] === 'yesno' ? Dropdown::getYesNo($old) : (string) $old;
                $auditNew = $field['type'] === 'yesno' ? Dropdown::getYesNo($value) : $modelValue;
                Log::record((int) $before['items_id'], $column, $auditOld, $auditNew, $before['itemtype']);
            }
        }
        return true;
    }
    public function add($input): bool
    {
        global $DB;
        if (!Computer::$allowCreate) { throw new LogicException('Unexpected child creation'); }
        unset($input['entities_id']);
        $input += ['id' => $input['items_id'], 'textfield' => null, 'yesfield' => 0, 'datefield' => null];
        if (!$DB->insert(static::getTable(), $input)) { return false; }
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => ['plugin_fields_containers_id' => $input['plugin_fields_containers_id']]]) as $field) {
            $value = $input[$field['name']] ?? null;
            if (empty($value) || PluginFieldsContainer::$fault === 'history_missing') { continue; }
            Log::record($input['items_id'], $field['name'], $field['type'] === 'yesno' ? '' : 'N/A',
                $field['type'] === 'yesno' ? Dropdown::getYesNo($value) : $value, $input['itemtype']);
        }
        return true;
    }
}
class Log
{
    public const HISTORY_CREATE_ITEM = 20;
    public static function record(int $id, string $column, mixed $old, mixed $new, string $type = 'Computer'): void
    {
        global $DB;
        foreach (Search::getOptions($type) as $optionId => $option) {
            if ($option['field'] !== $column) { continue; }
            foreach (['old', 'new'] as $side) {
                $value = (string) $$side;
                $decoded = json_decode($value);
                $$side = is_array($decoded) || is_object($decoded) ? '' : mb_substr($value, 0, 255);
            }
            $user = Session::getLoginUserID(false);
            $author = is_numeric($user) ? User::getNameForLog((int) $user) : $user;
            $audit = ['items_id' => $id, 'itemtype' => $type, 'id_search_option' => $optionId,
                'itemtype_link' => '', 'linked_action' => 0, 'field' => $option['name'],
                'user_name' => $author, 'old_value' => $old,
                'new_value' => PluginFieldsContainer::$fault === 'history_wrong' ? 'wrong' : $new];
            if ($DB->fieldExists('glpi_logs', 'date_mod')) { $audit['date_mod'] = $_SESSION['glpi_currenttime'] ?? gmdate('Y-m-d H:i:s'); }
            $DB->insert('glpi_logs', $audit);
        }
    }
    public static function getHistoryData($computer, $start, $limit): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => 'glpi_logs', 'WHERE' => ['itemtype' => $computer::class, 'items_id' => $computer->fields['id']], 'ORDER' => 'id DESC', 'LIMIT' => $limit]));
    }
}

function scalarSetup(): array
{
    global $DB, $CFG_GLPI;
    $DB = new ScalarDB();
    $CFG_GLPI = ['inventory_types' => ['Computer', 'Printer']];
    $_SESSION = ['caller' => 'preserved'];
    Config::$values = [];
    User::$active = true;
    Computer::$allowUpdate = true;
    Computer::$allowRead = true;
    Computer::$allowCreate = false;
    Computer::$adds = 0;
    Computer::$afterAdd = null;
    Session::$entityAccess = true;
    PluginFieldsComputerscalar::$ignoreUpdate = false;
    PluginFieldsComputerscalar::$dateNullSentinel = true;
    Profile_User::$granted = true;
    Profile::$rights = READ | UPDATE;
    PluginFieldsProfile::$right = UPDATE;
    PluginFieldsContainer::$fault = '';
    PluginFieldsContainer::$calls = 0;
    PluginFieldsContainer::$inputs = [];
    PluginFieldsStatusOverride::$overrides = [];
    Search::$clears = 0;
    Search::$typedOptions = [];
    Search::$onClear = null;
    $DB->doQuery('CREATE TABLE glpi_computers (id INTEGER PRIMARY KEY, entities_id INTEGER, name TEXT, comment TEXT,
        serial TEXT, states_id INTEGER DEFAULT 0, is_deleted INTEGER DEFAULT 0, is_template INTEGER DEFAULT 0)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_containers (id INTEGER PRIMARY KEY, name TEXT, label TEXT,
        itemtypes TEXT, entities_id INTEGER, is_active INTEGER, is_recursive INTEGER)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_fields (id INTEGER PRIMARY KEY, name TEXT, label TEXT, type TEXT,
        plugin_fields_containers_id INTEGER, is_active INTEGER, is_readonly INTEGER, mandatory INTEGER)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_computerscalars (id INTEGER PRIMARY KEY, items_id INTEGER, itemtype TEXT,
        plugin_fields_containers_id INTEGER, textfield TEXT, yesfield INTEGER, datefield TEXT)');
    $DB->doQuery('CREATE TABLE glpi_logs (id INTEGER PRIMARY KEY, items_id INTEGER, itemtype TEXT, id_search_option INTEGER,
        itemtype_link TEXT, linked_action INTEGER, field TEXT, user_name TEXT, old_value TEXT, new_value TEXT)');
    foreach ([101, 201] as $id) {
        $DB->insert('glpi_computers', ['id' => $id, 'entities_id' => 100, 'name' => 'Original', 'comment' => '', 'serial' => 'SCALAR-1']);
        $DB->insert(PluginFieldsComputerscalar::getTable(), ['id' => $id, 'items_id' => $id, 'itemtype' => 'Computer',
            'plugin_fields_containers_id' => 1, 'textfield' => 'old', 'yesfield' => null, 'datefield' => '2026-10-12']);
    }
    $DB->insert('glpi_plugin_fields_containers', ['id' => 1, 'name' => 'scalars', 'label' => 'Scalars',
        'itemtypes' => '["Computer"]', 'entities_id' => 100, 'is_active' => 1, 'is_recursive' => 0]);
    Search::$options = [
        1 => ['table' => 'glpi_computers', 'field' => 'name', 'name' => 'Name', 'uid' => 'Computer.name'],
        16 => ['table' => 'glpi_computers', 'field' => 'comment', 'name' => 'Comments', 'uid' => 'Computer.comment'],
    ];
    foreach ([['text', 'textfield', 'Text', 'string'], ['yesno', 'yesfield', 'Yes/No', 'bool'], ['date', 'datefield', 'Date', 'date']] as $index => [$type, $column, $label, $datatype]) {
        $DB->insert('glpi_plugin_fields_fields', ['id' => $index + 1, 'name' => $column, 'label' => $label, 'type' => $type,
            'plugin_fields_containers_id' => 1, 'is_active' => 1, 'is_readonly' => 1, 'mandatory' => 0]);
        Search::$options[101 + $index] = ['table' => PluginFieldsComputerscalar::getTable(), 'field' => $column,
            'name' => 'Scalars - ' . $label, 'uid' => scalarKey($column), 'datatype' => $datatype,
            'pfields_type' => $type, 'pfields_fields_id' => $index + 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 1];
    }
    $settings = ['mode' => 'agent', 'installation' => str_repeat('a', 32), 'registration' => str_repeat('b', 32),
        'generation' => str_repeat('c', 32), 'connection_id' => 'b', 'computer_id' => 201, 'entity_id' => 100,
        'service_user' => 7, 'service_profile' => 4, 'service_name' => 'scalar', 'service_password' => 'test-only'];
    Config::$values['plugin:assetsync20agent'] = $settings;
    \GlpiPlugin\Assetsync20agent\TargetScope::install();
    return $settings;
}
function scalarKey(string $column): string { return 'Computer.PluginFieldsComputerscalar.' . $column; }
function scalarSnapshot(array $settings, int $version = 2): array
{
    return \GlpiPlugin\Assetsync20agent\ServiceContext::run($settings,
        static fn () => \GlpiPlugin\Assetsync20agent\TaskExecutor::snapshot($settings, $version));
}
function scalarTask(array $settings, array $values, int $version = 2): array
{
    $snapshot = scalarSnapshot($settings, $version);
    $changes = [];
    foreach ($values as $column => $value) {
        $key = in_array($column, ['name', 'comment'], true) ? $column : scalarKey($column);
        $field = $snapshot['fields'][$key];
        $changes[$key] = $version === 2 ? ['type' => $field['type']] : [];
        $changes[$key] += ['metadata' => $field['metadata'], 'expected' => $field['value'], 'value' => $value];
    }
    $task = ['version' => $version, 'id' => bin2hex(random_bytes(16)), 'kind' => $values === [] ? 'inspect' : 'apply',
        'installation' => $settings['installation'], 'registration' => $settings['registration'], 'generation' => $settings['generation'],
        'connection_id' => 'b', 'computer_id' => 201, 'entity_id' => 100, 'serial' => 'SCALAR-1',
        'policy' => '', 'expires' => time() + 300, 'changes' => $changes];
    return scalarHash($task);
}
function scalarHash(array $task): array
{
    unset($task['hash']);
    $task['hash'] = \GlpiPlugin\Assetsync20agent\TaskExecutor::hash($task);
    return $task;
}
function scalarRow(int $id = 201): array
{
    global $DB;
    return $DB->firstRow(PluginFieldsComputerscalar::getTable(), ['items_id' => $id]) ?? [];
}
