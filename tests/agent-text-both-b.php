<?php

declare(strict_types=1);

namespace Glpi\Security {
    final class TOTPManager {
        public const ENFORCEMENT_OPTIONAL = 0;
        public function is2FAEnabled(int $id): bool { return false; }
        public function get2FAEnforcement(int $id): int { return 0; }
    }
}
namespace GlpiPlugin\Assetsync20agent {
    const PHP_SAPI = 'fpm-fcgi';
    function time(): int { return \TextBFixture::$wall; }
    function hrtime(bool $numeric = false): int { return \TextBFixture::$monotonic; }
    function session_status(): int { return PHP_SESSION_NONE; }
    function session_id(?string $id = null): string { $old = \TextBFixture::$session; if ($id !== null) { \TextBFixture::$session = $id; } return $old; }
    function session_write_close(): bool { return true; }
    function session_destroy(): bool { return true; }
    function ini_get(string $key): string { return \TextBFixture::$ini[$key] ?? '0'; }
    function ini_set(string $key, string $value): string { $old = ini_get($key); \TextBFixture::$ini[$key] = $value; return $old; }
    function file_get_contents(string $path, mixed ...$args): string {
        if ($path !== 'php://input') { throw new \LogicException('No network or external files in B component tests.'); }
        return \TextBFixture::$input;
    }
    final class AgentApi {
        public static function validatePeer(array $settings): void {
            foreach (['agent_peer_user', 'agent_peer_profile', 'agent_peer_api_client'] as $key) {
                if ((int) ($settings[$key] ?? 0) < 1) { throw new \RuntimeException('Invalid fixture peer.'); }
            }
        }
    }
}
namespace {
    require __DIR__ . '/agent-sqlite.php';
    require __DIR__ . '/query-expression.php';
    require __DIR__ . '/../assetsync20agent/src/autoload.php';

    use GlpiPlugin\Assetsync20agent\FieldEvidence;
    use GlpiPlugin\Assetsync20agent\RestPeer;
    use GlpiPlugin\Assetsync20agent\RestSettings;
    use GlpiPlugin\Assetsync20agent\RestWork;
    use GlpiPlugin\Assetsync20agent\Settings;
    use GlpiPlugin\Assetsync20agent\Snapshot;
    use GlpiPlugin\Assetsync20agent\TaskExecutor;
    use GlpiPlugin\Assetsync20agent\TaskJournal;
    use GlpiPlugin\Assetsync20agent\TextBoth;

    $sharedEvidence = getenv('ASSETSYNC_TEXT_B_FIELD_EVIDENCE');
    if ($sharedEvidence !== false && $sharedEvidence !== '') { require_once $sharedEvidence; }
    if (!method_exists(FieldEvidence::class, 'withOrigins')) {
        throw new \RuntimeException('Central shared origin API not integrated; set ASSETSYNC_TEXT_B_FIELD_EVIDENCE to its read-only PHP source for component checks.');
    }

    define('READ', 1); define('UPDATE', 2); define('CREATE', 4);
    final class TextBFixture {
        public static int $wall = 1900000000;
        public static int $epoch = 100;
        public static int $monotonic = 1000000000;
        public static string $session = 'native-session';
        public static string $input = '';
        public static array $ini = [];
        public static bool $human = false;
        public static bool $read = true;
        public static bool $update = true;
        public static bool $account = true;
        public static bool $grant = true;
        public static int $containerRight = CREATE;
        public static int $profileRights = READ | UPDATE;
        public static array $overrides = [];
        public static int $modelCalls = 0;
        public static int $wrapperCalls = 0;
        public static array $wrapperInput = [];
        public static int $validationCalls = 0;
        public static string $fault = '';
        public static bool $loseIntentCommit = false;
        public static bool $loseModelCommit = false;
        public static bool $blockSettlement = false;
        public static string $blockSettlementKey = '';
        public static ?\Closure $afterIntent = null;
        public static ?\Closure $afterDone = null;
        public static ?\Closure $afterModel = null;
        public static int $faultContainer = 0;
        public static array $snapshots = [];
    }
    final class TextBDB {
        public AgentSqlite $sql;
        public string $dbdefault = 'text_b_host_only';
        public int $error = 0;
        public function __construct() {
            $this->sql = new AgentSqlite();
            $this->sql->pdo->sqliteCreateFunction('CONNECTION_ID', static fn () => 17, 0);
            $this->sql->pdo->sqliteCreateFunction('IS_USED_LOCK', fn ($name) => $this->sql->workerLockHeld ? 17 : null, 1);
            $this->sql->pdo->sqliteCreateFunction('SHA2', static fn ($value, $bits) => hash('sha256', $value), 2);
            $this->sql->pdo->sqliteCreateFunction('JSON_KEYS', static fn ($json, $path) => json_encode(array_keys(json_decode($json, true)['attempts'] ?? [])), 2);
        }
        public function __call(string $name, array $args): mixed { return $this->sql->$name(...$args); }
        public function errno(): int { return $this->error; }
        public function doQuery(string $sql): mixed {
            $this->error = 0;
            $sql = str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            if (str_starts_with($sql, 'SELECT ENGINE FROM information_schema.TABLES')) { return $this->sql->pdo->query("SELECT 'InnoDB' AS ENGINE"); }
            if (str_starts_with($sql, 'SELECT CHARACTER_MAXIMUM_LENGTH')) {
                $area = str_contains($sql, "COLUMN_NAME='comment'") || str_contains($sql, "COLUMN_NAME='notes'");
                return $this->sql->pdo->query('SELECT ' . ($area ? 65535 : 255) . ' AS characters,' . ($area ? 65535 : 1020) . ' AS bytes');
            }
            if ($sql === 'SELECT UNIX_TIMESTAMP() AS epoch') { return $this->sql->pdo->query('SELECT ' . TextBFixture::$epoch . ' AS epoch'); }
            if ($sql === 'SELECT CONNECTION_ID() AS connection_id') { return $this->sql->pdo->query('SELECT 17 AS connection_id'); }
            if (str_starts_with($sql, 'SELECT @@SESSION.autocommit')) { return $this->sql->pdo->query('SELECT 1 AS enabled,17 AS id'); }
            if (str_starts_with($sql, 'SAVEPOINT ')) { return !$this->sql->pdo->inTransaction() || $this->sql->doQuery($sql) !== false; }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT ') && !$this->sql->pdo->inTransaction()) { $this->error = 1305; return false; }
            if (str_starts_with($sql, 'SELECT @@SESSION.innodb_lock_wait_timeout')) { return $this->sql->pdo->query('SELECT 50 AS seconds'); }
            if (str_starts_with($sql, 'SET SESSION innodb_lock_wait_timeout')) { return true; }
            if (str_starts_with($sql, 'SHOW INDEX FROM')) { return $this->sql->pdo->query("SELECT '" . (str_contains($sql, 'field_model') ? 'model_class' : 'binding') . "' AS Column_name"); }
            if ($sql === 'ROLLBACK' && !$this->sql->pdo->inTransaction()) { return false; }
            if (TextBFixture::$blockSettlement && str_starts_with($sql, 'UPDATE `glpi_plugin_assetsync20agent_fieldevidence`')
                && (TextBFixture::$blockSettlementKey === '' || str_contains($sql, "`field_key`='" . TextBFixture::$blockSettlementKey . "'"))) { return false; }
            $result = $this->sql->doQuery(str_replace(' LOCK IN SHARE MODE', '', $sql));
            if ($sql === 'COMMIT') {
                $phases = [];
                if ($this->sql->tableExists(TaskJournal::TABLE)) {
                    foreach ($this->sql->pdo->query('SELECT result FROM ' . TaskJournal::TABLE)->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                        $public = json_decode($raw, true)['result'] ?? null;
                        foreach ($public['groups'] ?? [] as $group) { $phases[] = $group['phase']; }
                        if ($public !== null && (TextBFixture::$snapshots === [] || end(TextBFixture::$snapshots) !== $public)) { TextBFixture::$snapshots[] = $public; }
                    }
                }
                $pending = in_array('origin_pending', $phases, true); $intent = in_array('intent', $phases, true);
                if ($pending && TextBFixture::$loseModelCommit) {
                    TextBFixture::$loseModelCommit = false; TextBFixture::$blockSettlement = true;
                    throw new \RuntimeException('Injected lost model commit reply');
                }
                if ($intent && TextBFixture::$loseIntentCommit) { TextBFixture::$loseIntentCommit = false; throw new \RuntimeException('Injected process loss after intent'); }
                if ($intent && TextBFixture::$afterIntent !== null) { $callback = TextBFixture::$afterIntent; TextBFixture::$afterIntent = null; $callback(); }
                if (in_array('done', $phases, true) && TextBFixture::$afterDone !== null) { $callback = TextBFixture::$afterDone; TextBFixture::$afterDone = null; $callback(); }
            }
            return $result;
        }
    }
    class CommonDBTM {
        public array $fields = [];
        public static function getTable(): string { return str_ends_with(static::class, 'RestWork') ? 'rest_work' : 'rest_settings'; }
        public static function unsetUndisclosedFields(&$fields) {}
        public function can($id, int $right, ?array &$input = null): bool { return true; }
        public function getID(): int { return (int) $this->fields['id']; }
        public function getFromDB($id) { global $DB; $this->fields = $DB->firstRow(static::getTable(), ['id' => $id]) ?? []; return $this->fields !== []; }
        public function add(array $input, $options = [], $history = true) {
            global $DB; $DB->insert(static::getTable(), $input); $id = (int) $DB->sql->pdo->lastInsertId();
            $this->fields = $DB->firstRow(static::getTable(), ['id' => $id]);
            if (method_exists($this, 'post_addItem')) { $this->post_addItem(); }
            return $id;
        }
    }
    final class Config {
        public static array $values = [];
        public static function getConfigurationValues($context, array $names = []): array { return $names === [] ? self::$values : array_intersect_key(self::$values, array_flip($names)); }
        public static function setConfigurationValues($context, array $values): void {
            global $DB; self::$values = array_replace(self::$values, $values);
            foreach ($values as $key => $value) {
                $q = $DB->sql->pdo->prepare('INSERT INTO glpi_configs(context,name,value) VALUES(?,?,?) ON CONFLICT(context,name) DO UPDATE SET value=excluded.value');
                $q->execute([$context, $key, $value]);
            }
        }
    }
    final class GLPIKey { public function decrypt($value): mixed { return $value; } }
    final class Session {
        public static function getLoginUserID(bool $human = true): int { return (int) ($_SESSION['glpiID'] ?? 0); }
        public static function isCron(): bool { return false; }
        public static function isInventory(): bool { return false; }
        public static function isRightChecksDisabled(): bool { return false; }
        public static function initEntityProfiles(int $user): void {}
        public static function changeProfile(int $id): void { $_SESSION['glpiactiveprofile'] = ['id' => $id]; }
        public static function changeActiveEntities(int $id, bool $recursive): bool { $_SESSION['glpiactive_entity'] = $id; return true; }
        public static function haveAccessToEntity(int $id): bool { return TextBFixture::$grant; }
        public static function destroy(): void { $_SESSION = []; }
        public static function start(): void { if (TextBFixture::$session === '') { TextBFixture::$session = 'isolated-service-session'; } }
        public static function loadLanguage(): void {}
    }
    final class Auth {
        public function login($name, $password, $remember): bool { $_SESSION = ['glpiID' => 7, 'glpiprofiles' => [4 => []]]; return TextBFixture::$account; }
    }
    final class User {
        public array $fields = [];
        public function getFromDB($id): bool { $this->fields = ['id' => $id, 'name' => 'owned-fixture', 'is_active' => TextBFixture::$account ? 1 : 0, 'is_deleted' => 0, '2fa' => null, '2fa_unenforced' => 0]; return true; }
        public static function getNameForLog($id): string { return 'Fixture actor ' . $id; }
    }
    final class Profile {
        public array $fields = [];
        public function getFromDB($id): bool { $this->fields = ['computer' => TextBFixture::$profileRights]; return true; }
    }
    final class Profile_User {
        public array $fields = [];
        public function getFromDBByCrit(array $criteria): bool { $this->fields = $criteria; return TextBFixture::$grant; }
    }
    final class Plugin { public static function isPluginActive(string $name): bool { return $name === 'fields'; } }
    final class Dropdown { public static function getDropdownName($table, $id): string { return 'Owned fixture entity'; } }
    final class Search { public static function clearSearchOptionCache($type): void {} }
    class_alias(Search::class, 'Glpi\\Search\\SearchOption');
    function isAPI(): bool { return !TextBFixture::$human; }
    function isCommandLine(): bool { return false; }
    function getTableForItemType(string $class): string { return $class::getTable(); }
    class Computer {
        public array $fields = [];
        public array|false $input = [];
        public array $updates = [];
        public array $oldvalues = [];
        public static bool $readonly = false;
        public static function getTable(): string { return 'glpi_computers'; }
        public function rawSearchOptions(): array {
            return [['id' => 1, 'table' => 'glpi_computers', 'field' => 'name', 'readonly' => self::$readonly], ['id' => 16, 'table' => 'glpi_computers', 'field' => 'comment']];
        }
        public function getFromDB($id): bool { global $DB; $this->fields = $DB->firstRow(static::getTable(), ['id' => $id]) ?? []; return $this->fields !== []; }
        public function can($id, int $right): bool { return ($right === READ ? TextBFixture::$read : TextBFixture::$update) && $this->getFromDB($id); }
        public function update(array $input): bool { return textBModelUpdate($this, $input); }
    }
    final class PluginFieldsComputerfixture extends Computer {
        public static function getTable(): string { return 'glpi_plugin_fields_computerfixtures'; }
    }
    final class PluginFieldsComputersecond extends Computer {
        public static function getTable(): string { return 'glpi_plugin_fields_computerseconds'; }
    }
    final class PluginFieldsField { public const SEARCH_OPTION_STARTING_INDEX = 1000; }
    final class PluginFieldsProfile {
        public static function getRightOnContainer(int $profile, int $container): int { return $profile === 4 ? TextBFixture::$containerRight : 0; }
    }
    final class PluginFieldsStatusOverride {
        public static function getTable(): string { return 'glpi_plugin_fields_statusoverrides'; }
        public static function getStatusFieldName(string $type): string { return 'states_id'; }
        public static function getOverridesForItemtypeAndStatus(int $container, string $type, int $status): array { return $status === 2 ? TextBFixture::$overrides : []; }
    }
    final class PluginFieldsContainer {
        public static function getClassname(string $type, string $name): string { return $name === 'second' ? 'PluginFieldsComputersecond' : 'PluginFieldsComputerfixture'; }
        public static function getAddSearchOptions($type): array { throw new \LogicException('No global Fields option scan.'); }
        public static function validateValues(array $input, string $type, bool $massive): bool {
            global $DB;
            TextBFixture::$validationCalls++;
            // Official non-DOM validation has no loaded parent: base mandatory is still enforced.
            foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => ['plugin_fields_containers_id' => $input['plugin_fields_containers_id']]]) as $field) {
                if ($field['is_active'] && $field['mandatory'] && empty($input[$field['name']])) { return false; }
            }
            return true;
        }
        public function updateFieldsValues(array $input, string $type, bool $massive): bool {
            TextBFixture::$wrapperCalls++; TextBFixture::$wrapperInput = $input;
            if (!self::validateValues($input, $type, $massive)) { return false; }
            if (TextBFixture::$fault === 'ignore' && (TextBFixture::$faultContainer === 0 || TextBFixture::$faultContainer === $input['plugin_fields_containers_id'])) { return true; }
            $class = $input['plugin_fields_containers_id'] === 12 ? PluginFieldsComputersecond::class : PluginFieldsComputerfixture::class;
            global $DB; $rows = $DB->request(['FROM' => $class::getTable(), 'WHERE' => ['items_id' => $input['items_id']]]);
            $row = array_values(iterator_to_array($rows))[0] ?? null;
            if (!$row) { throw new \LogicException('Unexpected implicit child creation'); }
            $item = new $class(); $item->getFromDB($row['id']);
            unset($input['items_id'], $input['itemtype'], $input['plugin_fields_containers_id']);
            return $item->update(['id' => $row['id']] + $input);
        }
    }
    function textBModelUpdate(Computer $item, array $input): bool {
        global $DB;
        TextBFixture::$modelCalls++;
        $item->input = $input; $item->updates = []; $item->oldvalues = [];
        FieldEvidence::before($item);
        foreach (array_diff_key($input, ['id' => true]) as $column => $value) {
            $old = $item->fields[$column];
            if ($old === $value) { continue; }
            $item->updates[] = $column; $item->oldvalues[$column] = $old;
            $DB->update($item::getTable(), [$column => $value], ['id' => $input['id']]);
            $reference = $column === 'name' ? 1 : 16;
            if ($item::class !== Computer::class) {
                $definition = $DB->firstRow('glpi_plugin_fields_fields', ['name' => $column, 'plugin_fields_containers_id' => $item->fields['plugin_fields_containers_id']]);
                $reference = 1000 + (int) $definition['id'];
            }
            $projection = static function ($v): string { $v = (string) $v; $decoded = json_decode($v); return mb_substr(is_array($decoded) || is_object($decoded) ? '' : $v, 0, 255); };
            $DB->insert('glpi_logs', ['items_id' => $item::class === 'Computer' ? $input['id'] : $item->fields['items_id'],
                'itemtype' => 'Computer', 'id_search_option' => $reference, 'old_value' => $projection($old),
                'new_value' => TextBFixture::$fault === 'history' ? 'wrong' : $projection($value), 'itemtype_link' => '',
                'linked_action' => 0, 'user_name' => User::getNameForLog(Session::getLoginUserID()), 'date_mod' => '2030-01-01 00:00:00']);
        }
        FieldEvidence::after($item);
        if (TextBFixture::$afterModel !== null) { $callback = TextBFixture::$afterModel; TextBFixture::$afterModel = null; $callback(); }
        return true;
    }
    $checks = 0;
    function textBCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new \RuntimeException($message); } }
    function textBReject(callable $operation, string $message): void {
        try { $operation(); } catch (\RuntimeException | \JsonException | \TypeError) { textBCheck(true, $message); return; }
        throw new \RuntimeException('Accepted: ' . $message);
    }
    function textBValueHash(mixed $value): string {
        return hash('sha256', json_encode(['type' => 'text', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    function textBRequest(array $body, string $method = 'POST', ?int $id = null): void {
        TextBFixture::$session = 'native-session'; TextBFixture::$human = false;
        $_SESSION = ['glpiID' => 9, 'glpiactiveprofile' => ['id' => 4], 'glpiactive_entity' => 100];
        $_SERVER = ['REQUEST_METHOD' => $method, 'HTTPS' => 'on', 'HTTP_SESSION_TOKEN' => base64_encode('native-session'), 'HTTP_APP_TOKEN' => 'fixture-app',
            'REQUEST_URI' => '/apirest.php/GlpiPlugin\\Assetsync20agent\\RestWork' . ($id === null ? '' : '/' . $id), 'SCRIPT_NAME' => '/apirest.php'];
        $_COOKIE = []; $_REQUEST = [];
        $_GET = $method === 'GET' ? ['rest_entity' => '100', 'get_hateoas' => '0'] : [];
        $input = ['work_id' => $body['id'], 'hash' => TaskExecutor::hash($body), 'body' => json_encode($body)];
        if ($body['version'] === 12) { $input['allow_rows'] = array_column($body['sources'], 'mapping_id'); }
        TextBFixture::$input = $method === 'POST' ? json_encode(['input' => $input, 'session_write' => false]) : '';
        (new \ReflectionProperty(RestPeer::class, 'requestDeadline'))->setValue(null, 0);
    }
    function textBHuman(string $key, string $value): array {
        global $DB;
        TextBFixture::$human = true; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SESSION = ['glpiID' => 13];
        if (str_starts_with($key, 'native:')) { $item = new Computer(); $id = 201; $column = substr($key, 7); }
        else {
            $definition = $DB->firstRow('glpi_plugin_fields_fields', ['id' => (int) substr($key, 7)]);
            $container = $DB->firstRow('glpi_plugin_fields_containers', ['id' => $definition['plugin_fields_containers_id']]);
            $class = PluginFieldsContainer::getClassname('Computer', $container['name']); $item = new $class();
            $child = $DB->firstRow($class::getTable(), ['items_id' => 201]); $id = $child['id']; $column = $definition['name'];
        }
        $item->getFromDB($id);
        $item->update(['id' => $item->fields['id'], $column => $value]); FieldEvidence::finishWork();
        TextBFixture::$human = false;
        return FieldEvidence::envelope(201, $key);
    }
    function textBSetup(string $key = 'native:name', bool $foreignCustom = false, bool $customReadonly = false): array {
        global $DB, $CFG_GLPI, $TRANSLATE;
        $DB = new TextBDB(); $CFG_GLPI = ['inventory_types' => ['Computer'], '2fa_enforced' => 0]; $TRANSLATE = null;
        TextBFixture::$wall = 1900000000; TextBFixture::$epoch = 100; TextBFixture::$monotonic = 1000000000;
        TextBFixture::$read = TextBFixture::$update = TextBFixture::$account = TextBFixture::$grant = true;
        TextBFixture::$profileRights = READ | UPDATE; TextBFixture::$containerRight = CREATE;
        TextBFixture::$fault = ''; TextBFixture::$blockSettlement = TextBFixture::$loseIntentCommit = TextBFixture::$loseModelCommit = false;
        TextBFixture::$blockSettlementKey = '';
        TextBFixture::$afterIntent = TextBFixture::$afterDone = TextBFixture::$afterModel = null;
        TextBFixture::$faultContainer = 0; TextBFixture::$snapshots = []; TextBFixture::$overrides = []; Computer::$readonly = false;
        foreach (['frames', 'owned', 'origins'] as $property) { (new \ReflectionProperty(FieldEvidence::class, $property))->setValue(null, []); }
        foreach ([
            'CREATE TABLE glpi_configs(context TEXT,name TEXT,value TEXT,PRIMARY KEY(context,name))',
            'CREATE TABLE glpi_computers(id INTEGER PRIMARY KEY,entities_id INTEGER,is_deleted INTEGER,is_template INTEGER,name TEXT,comment TEXT,serial TEXT,otherserial TEXT,states_id INTEGER)',
            "INSERT INTO glpi_computers VALUES(201,100,0,0,'Original','Original comments','OWNED','',2)",
            'CREATE TABLE glpi_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,items_id INTEGER,itemtype TEXT,id_search_option INTEGER,old_value TEXT,new_value TEXT,itemtype_link TEXT,linked_action INTEGER,user_name TEXT,date_mod TEXT)',
            'CREATE TABLE glpi_plugin_fields_containers(id INTEGER PRIMARY KEY,name TEXT,itemtypes TEXT,is_active INTEGER,type TEXT,entities_id INTEGER,is_recursive INTEGER)',
            "INSERT INTO glpi_plugin_fields_containers VALUES(11,'fixture','[\"Computer\"]',1,'tab',100,0)",
            'CREATE TABLE glpi_plugin_fields_fields(id INTEGER PRIMARY KEY,plugin_fields_containers_id INTEGER,name TEXT,type TEXT,multiple INTEGER,is_active INTEGER,label TEXT,is_readonly INTEGER,mandatory INTEGER)',
            'CREATE TABLE glpi_plugin_fields_statusoverrides(id INTEGER PRIMARY KEY,plugin_fields_fields_id INTEGER,itemtype TEXT,states TEXT,mandatory INTEGER,is_readonly INTEGER)',
            "INSERT INTO glpi_plugin_fields_fields VALUES(1,11,'textvalue','text',0,1,'Text',0,0),(2,11,'notes','textarea',0,1,'Notes',0,0)",
            'CREATE TABLE glpi_plugin_fields_computerfixtures(id INTEGER PRIMARY KEY,items_id INTEGER,itemtype TEXT,plugin_fields_containers_id INTEGER,textvalue TEXT,notes TEXT)',
            "INSERT INTO glpi_plugin_fields_computerfixtures VALUES(31,201,'Computer',11,'Old text','Old notes')",
            'CREATE TABLE glpi_profiles_users(id INTEGER PRIMARY KEY,users_id INTEGER,profiles_id INTEGER,entities_id INTEGER,is_recursive INTEGER)',
            'INSERT INTO glpi_profiles_users VALUES(1,7,4,100,0),(2,9,4,100,0)',
            'CREATE TABLE glpi_apiclients(id INTEGER PRIMARY KEY,is_active INTEGER,app_token TEXT)',
            "INSERT INTO glpi_apiclients VALUES(3,1,'fixture-app')",
        ] as $sql) { $DB->doQuery($sql); }
        if ($customReadonly) { $DB->update('glpi_plugin_fields_fields', ['is_readonly' => 1], ['id' => 1]); }
        Config::$values = ['mode' => 'agent', 'transport' => 'rest_records', 'installation' => str_repeat('a', 32), 'registration' => str_repeat('b', 32),
            'generation' => str_repeat('c', 32), 'connection_id' => 'b', 'service_user' => 7, 'service_profile' => 4, 'service_name' => 'fixture',
            'service_password' => 'host-double', 'agent_peer_user' => 9, 'agent_peer_profile' => 4, 'agent_peer_api_client' => 3, 'agent_peer_entity' => 100];
        textBCheck(TaskJournal::install() && RestSettings::install() && RestWork::install() && FieldEvidence::install(), 'Host schema install.');
        FieldEvidence::activate();
        $before = textBHuman($key, 'Genuine B');
        $_SESSION['glpiactiveprofile'] = ['id' => 4];
        $pin = Snapshot::textPin($key, 100);
        $a = Snapshot::textPin('native:name', 100);
        if ($foreignCustom) {
            $a = ['kind' => 'fields', 'key' => 'Computer.PluginFieldsComputerforeign.avalue', 'capture_key' => 'fields:211', 'type' => 'textarea',
                'search_id' => 3211, 'definition_id' => 211, 'container_id' => 67, 'class' => 'PluginFieldsComputerforeign', 'table' => 'glpi_plugin_fields_computerforeigns',
                'column' => 'avalue', 'definition_hash' => str_repeat('e', 64), 'limits' => ['characters' => 65535, 'bytes' => 65535]];
        }
        $policy = ['version' => 5, 'id' => md5('text-policy'), 'installation' => Config::$values['installation'], 'registration' => Config::$values['registration'],
            'generation' => Config::$values['generation'], 'connection_id' => 'b', 'route_id' => 'owned-route', 'source_entity' => 5, 'target_entity' => 100,
            'itemtype' => 'Computer', 'mappings' => [['mapping_id' => substr(TaskExecutor::hash(['text-row', $a, $pin]), 0, 32), 'source_of_truth' => 'both', 'a' => $a, 'b' => $pin]], 'revision' => 1, 'enabled' => true,
            'include_child_entities' => false, 'asset_types' => ['Computer']];
        $source = $before; $source['value'] = 'Genuine newer A'; $source['hash'] = textBValueHash($source['value']);
        $source['origin']['identity'] = str_repeat('d', 32) . ':' . str_repeat('e', 32); $source['origin']['from_epoch'] = 110; $source['origin']['to_epoch'] = 111;
        $work = ['version' => 12, 'id' => md5('text-work'), 'installation' => $policy['installation'], 'registration' => $policy['registration'], 'generation' => $policy['generation'],
            'connection_id' => 'b', 'settings_id' => $policy['id'], 'settings_hash' => TaskExecutor::hash($policy), 'pair_id' => md5('pair'), 'itemtype' => 'Computer',
            'source_items_id' => 101, 'source_asset_entity' => 5, 'items_id' => 201, 'entity_id' => 100, 'identity' => ['serial' => 'OWNED'],
            'sources' => [['mapping_id' => $policy['mappings'][0]['mapping_id'], 'envelope' => $source]], 'source_hash' => '', 'source_fingerprint' => str_repeat('f', 64), 'expires' => TextBFixture::$wall + 300, 'batch_id' => md5('batch')];
        $work['source_hash'] = TaskExecutor::hash($work['sources']);
        textBRequest($work);
        $_SERVER['REQUEST_URI'] = '/apirest.php/GlpiPlugin\\Assetsync20agent\\RestSettings';
        TextBFixture::$input = json_encode(['input' => ['settings_id' => $policy['id'], 'hash' => TaskExecutor::hash($policy), 'body' => json_encode($policy)]]);
        (new RestSettings())->add(['settings_id' => $policy['id'], 'hash' => TaskExecutor::hash($policy), 'body' => json_encode($policy)]);
        TextBFixture::$modelCalls = TextBFixture::$wrapperCalls = TextBFixture::$validationCalls = 0;
        return [$policy, $work, $before];
    }
    function textBOutcome(array $row): string {
        $result = json_decode($row['result'], true);
        return $result['fields'][0]['outcome'] ?? ($result['outcome'] ?? $row['state']);
    }
    function textBPost(array $body, ?array $allowRows = null): array {
        global $DB; textBRequest($body);
        $input = ['work_id' => $body['id'], 'hash' => TaskExecutor::hash($body), 'body' => json_encode($body)];
        if ($body['version'] === 12) { $input['allow_rows'] = $allowRows ?? array_column($body['sources'], 'mapping_id'); }
        TextBFixture::$input = json_encode(['input' => $input, 'session_write' => false]);
        // Stock GLPI REST injects both fields before invoking the model add method.
        $id = (new RestWork())->add($input + ['entities_id' => $body['entity_id'], '_add' => true]);
        return $DB->firstRow(RestWork::getTable(), ['id' => $id]);
    }
    function textBGet(array $body, int $id): array {
        textBRequest($body, 'GET', $id); $item = new RestWork();
        if (!$item->getFromDB($id)) { throw new \RuntimeException('GET denied.'); }
        $fields = $item->fields; RestWork::unsetUndisclosedFields($fields); return $fields;
    }

    /** Synthetic independent A pins; only B's own metadata/model is resolved here. */
    function textBMulti(array $specifications, array $mandatory = []): array {
        global $DB;
        [$policy, $work] = textBSetup();
        $DB->doQuery("INSERT INTO glpi_plugin_fields_containers VALUES(12,'second','[\"Computer\"]',1,'tab',100,0)");
        $DB->doQuery("INSERT INTO glpi_plugin_fields_fields VALUES(3,12,'textvalue','text',0,1,'Other text',0,0),(4,12,'notes','textarea',0,1,'Other notes',0,0)");
        $DB->doQuery('CREATE TABLE glpi_plugin_fields_computerseconds(id INTEGER PRIMARY KEY,items_id INTEGER,itemtype TEXT,plugin_fields_containers_id INTEGER,textvalue TEXT,notes TEXT)');
        $DB->doQuery("INSERT INTO glpi_plugin_fields_computerseconds VALUES(41,201,'Computer',12,'Other old text','Other old notes')");
        $DB->doQuery('ALTER TABLE glpi_plugin_fields_computerfixtures ADD COLUMN extraone TEXT');
        $DB->doQuery('ALTER TABLE glpi_plugin_fields_computerseconds ADD COLUMN extratwo TEXT');
        $DB->doQuery("INSERT INTO glpi_plugin_fields_fields VALUES(5,11,'extraone','text',0,1,'Additional text',0,0),(6,12,'extratwo','textarea',0,1,'Additional notes',0,0)");
        foreach ($mandatory as $id) { $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => $id]); }
        $rows = [];
        foreach ($specifications as $i => $spec) {
            TextBFixture::$epoch = 100 + $i * 5;
            $before = textBHuman($spec['b'], $spec['before'] ?? ('Genuine B ' . $spec['b']));
            $_SESSION['glpiactiveprofile'] = ['id' => 4]; $b = Snapshot::textPin($spec['b'], 100);
            if (isset($spec['a'])) { $a = Snapshot::textPin($spec['a'], 100); }
            else {
                $a = ['kind' => 'fields', 'key' => 'Computer.PluginFieldsComputerforeign.avalue' . $i, 'capture_key' => 'fields:' . (211 + $i),
                    'type' => 'textarea', 'search_id' => 3211 + $i, 'definition_id' => 211 + $i, 'container_id' => 67, 'class' => 'PluginFieldsComputerforeign',
                    'table' => 'glpi_plugin_fields_computerforeigns', 'column' => 'avalue' . $i, 'definition_hash' => hash('sha256', 'independent A definition ' . $i),
                    'limits' => ['characters' => 65535, 'bytes' => 65535]];
            }
            $mapping = ['mapping_id' => substr(TaskExecutor::hash(['text-row', $a, $b]), 0, 32), 'source_of_truth' => 'both', 'a' => $a, 'b' => $b];
            $source = $before; $winner = $spec['winner'] ?? 'a';
            $source['value'] = $winner === 'equal' ? ($spec['source'] ?? $before['value']) : ($spec['value'] ?? ('A value ' . $i));
            $source['hash'] = textBValueHash($source['value']);
            $source['origin'] = ['identity' => md5('a installation') . ':' . md5('independent A event ' . $i), 'revision' => $i + 7,
                'kind' => 'user', 'from_epoch' => $winner === 'b' ? 80 : 200 + $i * 5, 'to_epoch' => $winner === 'b' ? 81 : 201 + $i * 5];
            if ($winner === 'conflict') { $source['origin'] = null; }
            $rows[] = ['mapping' => $mapping, 'source' => ['mapping_id' => $mapping['mapping_id'], 'envelope' => $source], 'before' => $before];
        }
        usort($rows, static fn ($a, $b): int => strcmp($a['mapping']['mapping_id'], $b['mapping']['mapping_id']));
        $policy['id'] = md5('multi-policy'); $policy['revision'] = 2; $policy['mappings'] = array_column($rows, 'mapping');
        $work['settings_id'] = $policy['id']; $work['settings_hash'] = TaskExecutor::hash($policy);
        $work['id'] = md5('multi-work'); $work['sources'] = array_column($rows, 'source'); $work['source_hash'] = TaskExecutor::hash($work['sources']);
        textBRequest($work); $_SERVER['REQUEST_URI'] = '/apirest.php/GlpiPlugin\\Assetsync20agent\\RestSettings';
        TextBFixture::$input = json_encode(['input' => ['settings_id' => $policy['id'], 'hash' => TaskExecutor::hash($policy), 'body' => json_encode($policy)]]);
        (new RestSettings())->add(['settings_id' => $policy['id'], 'hash' => TaskExecutor::hash($policy), 'body' => json_encode($policy)]);
        TextBFixture::$modelCalls = TextBFixture::$wrapperCalls = 0; TextBFixture::$snapshots = [];
        return [$policy, $work];
    }
    function textBOutcomes(array $result, array $policy): array {
        $outcomes = [];
        foreach ($policy['mappings'] as $i => $mapping) { $outcomes[$mapping['b']['capture_key']] = $result['fields'][$i]['outcome']; }
        ksort($outcomes); return $outcomes;
    }

    foreach (['native:name', 'native:comment', 'fields:1', 'fields:2'] as $key) {
        foreach ([false, true] as $foreign) {
            [$policy, $work, $before] = textBSetup($key, $foreign);
            $row = textBPost($work); $result = json_decode($row['result'], true);
            textBCheck(textBOutcome($row) === 'verified' && ($result['fields'][0]['winner'] ?? '') === 'a', 'A winner executes selected endpoint: ' . $key . ' ' . json_encode($result));
            TextBoth::validateResult($work, $policy, $result);
            textBCheck(TextBFixture::$modelCalls === 1 && $result['fields'][0]['after']['origin'] === $work['sources'][0]['envelope']['origin'], 'One supported model call inherits exact A original.');
            $history = $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC);
            textBPost($work); $get = textBGet($work, $row['id']);
            textBCheck($get['result'] === $row['result'] && $get['read_observation']['fields'][0]['current_matches'] === true, 'Stored receipt and current observation are separate.');
            textBCheck($DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC) === $history && TextBFixture::$modelCalls === 1, 'Retry/GET adds no model write or History.');
        }
    }

    foreach (['', '0', " \t ", "First\r\nSecond", "\u{00e9}\u{1f642}", '<b>literal</b> " \\'] as $value) {
        [$policy, $work] = textBSetup('fields:2', true);
        $work['sources'][0]['envelope']['value'] = $value;
        $work['sources'][0]['envelope']['hash'] = textBValueHash($value); $work['source_hash'] = TaskExecutor::hash($work['sources']);
        $row = textBPost($work); $result = json_decode($row['result'], true);
        textBCheck(textBOutcome($row) === 'verified' && $result['fields'][0]['after']['value'] === $value, 'Exact permitted text/textarea value persists: ' . json_encode([$value, textBOutcome($row), $result]));
    }
    foreach (['b', 'equal', 'unknown-equal', 'missing-a', 'touching'] as $case) {
        [$policy, $work, $before] = textBSetup();
        if ($case === 'b') { $work['sources'][0]['envelope']['origin']['from_epoch'] = 90; $work['sources'][0]['envelope']['origin']['to_epoch'] = 99; }
        if (in_array($case, ['equal', 'unknown-equal'], true)) { $work['sources'][0]['envelope']['value'] = $before['value']; $work['sources'][0]['envelope']['hash'] = $before['hash']; }
        if ($case === 'unknown-equal') {
            $work['sources'][0]['envelope']['origin'] = null;
            $DB->doQuery("DELETE FROM glpi_plugin_assetsync20agent_fieldevidence WHERE field_key='native:name'");
        }
        if ($case === 'missing-a') { $work['sources'][0]['envelope']['origin'] = null; }
        if ($case === 'touching') { $work['sources'][0]['envelope']['origin']['from_epoch'] = 100; $work['sources'][0]['envelope']['origin']['to_epoch'] = 110; }
        $work['source_hash'] = TaskExecutor::hash($work['sources']);
        $heads = $DB->sql->pdo->query('SELECT * FROM glpi_plugin_assetsync20agent_fieldevidence')->fetchAll(PDO::FETCH_ASSOC);
        $row = textBPost($work); $result = json_decode($row['result'], true);
        $expected = $case === 'b' ? 'b' : (in_array($case, ['equal', 'unknown-equal'], true) ? 'equal' : 'conflict');
        textBCheck(($result['fields'][0]['winner'] ?? '') === $expected && TextBFixture::$modelCalls === 0, 'No mutation for B/equal/conflict: ' . $case);
        textBCheck($DB->sql->pdo->query('SELECT * FROM glpi_plugin_assetsync20agent_fieldevidence')->fetchAll(PDO::FETCH_ASSOC) === $heads, 'No-op does not mutate capture heads.');
        if ($case === 'unknown-equal') { textBCheck(textBGet($work, $row['id'])['read_observation']['fields'][0]['current_matches'] === true, 'Equal unknown origins can match without minting evidence.'); }
    }

    [$policy, $work] = textBSetup('fields:1', false, true);
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'verified' && TextBFixture::$wrapperCalls === 1, 'Trusted mapped custom write allows UI readonly under real UPDATE/Write permission.');
    foreach ([0, READ, UPDATE, 999] as $right) {
        [$policy, $work] = textBSetup('fields:1'); TextBFixture::$containerRight = $right;
        $row = textBPost($work);
        textBCheck(textBOutcome($row) === 'blocked' && TextBFixture::$wrapperCalls === 0, 'Fields enum denies non-Write/invalid right: ' . $right);
    }
    [$policy, $work] = textBSetup('fields:1');
    $_SESSION['glpiactiveprofile'] = ['id' => 99];
    textBReject(static fn () => Snapshot::textPin('fields:1', 100, true), 'Fresh invalid profile cannot use another profile container grant.');
    [$policy, $work] = textBSetup(); Computer::$readonly = true;
    textBCheck(textBOutcome(textBPost($work)) === 'blocked' && TextBFixture::$modelCalls === 0, 'Native readonly remains unsupported.');

    [$policy, $work] = textBSetup('fields:1');
    $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 2]);
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'verified' && (TextBFixture::$wrapperInput['notes'] ?? null) === 'Old notes', 'Unchanged mandatory sibling is supplied and preserved.');
    [$policy, $work] = textBSetup('fields:1');
    TextBFixture::$overrides = [['plugin_fields_fields_id' => 1, 'mandatory' => 1, 'is_readonly' => 1]];
    $work['sources'][0]['envelope']['value'] = ''; $work['sources'][0]['envelope']['hash'] = textBValueHash(''); $work['source_hash'] = TaskExecutor::hash($work['sources']);
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'blocked' && TextBFixture::$validationCalls === 0 && TextBFixture::$wrapperCalls === 0, 'Loaded parent mandatory override rejects blank before the unloaded-parent wrapper.');
    [$policy, $work] = textBSetup('fields:1');
    $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 2]);
    TextBFixture::$overrides = [['plugin_fields_fields_id' => 2, 'mandatory' => 0, 'is_readonly' => 0]];
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'blocked' && TextBFixture::$validationCalls === 1 && TextBFixture::$wrapperCalls === 0,
        'Cleared override is not ORed with base; stock wrapper may still reject omitted base-mandatory sibling visibly.');

    foreach (['missing', 'duplicate', 'wrong-parent', 'retyped'] as $case) {
        [$policy, $work] = textBSetup('fields:1');
        if ($case === 'missing') { $DB->doQuery('DELETE FROM glpi_plugin_fields_computerfixtures'); }
        if ($case === 'duplicate') { $DB->doQuery('INSERT INTO glpi_plugin_fields_computerfixtures SELECT 32,items_id,itemtype,plugin_fields_containers_id,textvalue,notes FROM glpi_plugin_fields_computerfixtures'); }
        if ($case === 'wrong-parent') { $DB->update('glpi_plugin_fields_computerfixtures', ['itemtype' => 'Printer'], ['id' => 31]); }
        if ($case === 'retyped') { $DB->update('glpi_plugin_fields_fields', ['type' => 'textarea'], ['id' => 1]); }
        textBCheck(textBOutcome(textBPost($work)) === 'blocked' && TextBFixture::$wrapperCalls === 0, 'Existing exact selected child/definition required: ' . $case);
    }

    [$policy, $work] = textBSetup('fields:2', true);
    TextBFixture::$afterIntent = static function (): void { TextBFixture::$epoch = 120; textBHuman('fields:2', 'Intervening B'); };
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'blocked' && TextBFixture::$wrapperCalls === 0, 'Fresh destination full guard rejects an edit after intent.');
    [$policy, $work] = textBSetup(); TextBFixture::$loseIntentCommit = true;
    textBCheck(textBPost($work)['state'] === 'held', 'Lost committed intent reply becomes explicit held uncertainty.');
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'uncertain' && TextBFixture::$modelCalls === 0 && json_decode($row['result'], true)['fields'][0]['mutation'] === 'unknown', 'Orphan intent never replays mutation.');
    [$policy, $work] = textBSetup('fields:2', true); TextBFixture::$loseModelCommit = true;
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'origin_pending' && json_decode($row['result'], true)['b_complete'] === false && TextBFixture::$modelCalls === 1, 'Committed receipt is not premature verification.');
    $history = $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC);
    textBCheck(textBGet($work, $row['id'])['read_observation']['fields'][0]['current_matches'] === null, 'GET never settles pending receipt.');
    TextBFixture::$blockSettlement = false; TextBFixture::$wall += 301;
    foreach (['frames', 'owned', 'origins'] as $property) { (new \ReflectionProperty(FieldEvidence::class, $property))->setValue(null, []); }
    $row = textBPost($work);
    textBCheck(textBOutcome($row) === 'verified' && TextBFixture::$modelCalls === 1 && $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC) === $history,
        'Expired identical POST qualifies committed metadata only, without model/History replay.');

    [$policy, $work] = textBSetup('fields:1'); $row = textBPost($work);
    TextBFixture::$epoch = 130; textBHuman('fields:1', 'Later genuine B');
    $stored = $DB->firstRow(RestWork::getTable(), ['id' => $row['id']]);
    TextBFixture::$profileRights = READ; TextBFixture::$update = false; TextBFixture::$containerRight = READ;
    $get = textBGet($work, $row['id']);
    textBCheck($get['result'] === $row['result'] && $get['read_observation']['fields'][0]['current_matches'] === false, 'Historical terminal remains READ-disclosable after later B edit and UPDATE revocation.');
    textBCheck($DB->firstRow(RestWork::getTable(), ['id' => $row['id']]) === $stored, 'Dynamic GET observation never persists.');
    TextBFixture::$containerRight = 0;
    textBReject(static fn () => textBGet($work, $row['id']), 'READ revocation denies disclosure, never returns null permission evidence.');

    [$policy, $work] = textBSetup();
    foreach ([['kind' => 'native'], ['kind' => 'fields', 'after_id' => 0], ['kind' => 'selected', 'capture_keys' => ['fields:2']]] as $selector) {
        $catalog = ['version' => 13, 'id' => md5(json_encode($selector)), 'kind' => 'catalog', 'installation' => $policy['installation'], 'registration' => $policy['registration'],
            'generation' => $policy['generation'], 'connection_id' => 'b', 'itemtype' => 'Computer', 'entity_id' => 100, 'selector' => $selector, 'expires' => TextBFixture::$wall + 300];
        $DB->doQuery('DELETE FROM glpi_plugin_fields_computerfixtures');
        $row = textBPost($catalog); $result = json_decode($row['result'], true);
        textBCheck(textBOutcome($row) === 'observed' && $result['complete'] === true && $result['next_after_id'] === null, 'Catalog definitions need no child or pair.');
        textBCheck(textBGet($catalog, $row['id'])['result'] === $row['result'], 'Catalog GET returns stored metadata only.');
        textBRequest($catalog, 'GET'); $_GET += ['range' => '0-0', 'searchText' => ['compiler' => '13', 'work_id' => $catalog['id']]];
        $criteria = RestWork::getSystemSQLCriteria();
        textBCheck($criteria['AND']['rest_work.work_id'] === $catalog['id'], 'Lost Catalog POST reply has exact native stored-resource lookup.');
        $_GET['only_id'] = '1'; textBReject(static fn () => RestWork::getSystemSQLCriteria(), 'ID-only projection is forbidden.');
    }
    [$policy, $work] = textBSetup();
    $decode = static fn ($body): array => RestWork::decode(json_encode($body), TaskExecutor::hash($body));
    textBCheck($decode($work) === $work, 'Work12 exact ordered contract roundtrip.');
    textBReject(static fn () => $decode($work + ['extra' => true]), 'Unknown Work12 member.');
    $json = json_encode($work); $duplicate = '{"version":12,' . substr($json, 1);
    textBReject(static fn () => RestWork::decode($duplicate, TaskExecutor::hash($work)), 'Duplicate new JSON members cannot be hidden by json_decode.');
    $batch = new \ReflectionMethod(RestPeer::class, 'validateBatch');
    $input = static fn ($body): array => ['work_id' => $body['id'], 'hash' => TaskExecutor::hash($body), 'body' => json_encode($body), 'allow_rows' => array_column($body['sources'], 'mapping_id')];
    $second = array_replace($work, ['id' => md5('second')]); $third = array_replace($work, ['id' => md5('third')]);
    $batch->invoke(null, [$input($work)]); textBCheck(true, 'One Work12 asset record admitted.');
    textBReject(static fn () => $batch->invoke(null, [$input($work), $input($second)]), 'Two Work12 rows rejected before any member SQL.');
    textBReject(static fn () => $batch->invoke(null, [$input($work), $input($second), $input($third)]), 'Three Work12 rows rejected before any member SQL.');
    [$policy, $work] = textBMulti([
        ['b' => 'native:name', 'a' => 'native:comment'], ['b' => 'native:comment', 'winner' => 'b'],
        ['b' => 'fields:1', 'value' => 'First independent origin'], ['b' => 'fields:2', 'value' => "Second\nindependent origin"],
        ['b' => 'fields:3', 'winner' => 'conflict'], ['b' => 'fields:4', 'winner' => 'equal', 'before' => ''],
    ]);
    $row = textBPost($work); $result = json_decode($row['result'], true);
    TextBoth::validateResult($work, $policy, $result);
    textBCheck($result['b_complete'] === true && !array_key_exists('outcome', $result) && $row['state'] === 'terminal', 'B terminal accounts all rows without claiming A completion.');
    textBCheck(textBOutcomes($result, $policy) === ['fields:1' => 'verified', 'fields:2' => 'verified', 'fields:3' => 'conflict', 'fields:4' => 'unchanged', 'native:comment' => 'offered', 'native:name' => 'verified'], 'Mixed winners/no-op/conflict remain independent in one asset pass.');
    textBCheck(TextBFixture::$modelCalls === 2 && TextBFixture::$wrapperCalls === 1, 'Two incoming custom fields share one wrapper; native group uses one Computer call.');
    foreach ($result['fields'] as $i => $field) {
        if ($field['winner'] !== 'a') { continue; }
        textBCheck($field['after']['origin'] === $work['sources'][$i]['envelope']['origin'], 'Each same-container member inherits its own independent original event.');
    }
    $previous = [];
    foreach (TextBFixture::$snapshots as $snapshot) {
        TextBoth::validateProgress($work, $policy, $previous, $snapshot);
        textBCheck($snapshot['revision'] === ($previous['revision'] ?? 0) + 1, 'Each committed public transition increments exactly once.');
        $previous = $snapshot;
    }
    $history = $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC);
    $observation = textBGet($work, $row['id'])['read_observation']; TextBoth::validateReadObservation($result, $observation);
    textBPost($work, []);
    textBCheck(TextBFixture::$modelCalls === 2 && $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC) === $history, 'Restrictive retry cannot cancel or replay completed groups.');

    [$policy, $work] = textBMulti([['b' => 'fields:1', 'before' => '', 'value' => 'Filled text'], ['b' => 'fields:2', 'before' => '', 'value' => 'Filled notes']], [1, 2]);
    $result = json_decode(textBPost($work)['result'], true);
    textBCheck(array_unique(array_column($result['fields'], 'outcome')) === ['verified'] && TextBFixture::$wrapperCalls === 1, 'Combined proposals fill both mandatory previously blank siblings in one wrapper.');
    [$policy, $work] = textBMulti([['b' => 'fields:1', 'value' => '0'], ['b' => 'fields:2', 'value' => 'Valid sibling'], ['b' => 'native:name']], [1]);
    $result = json_decode(textBPost($work)['result'], true);
    textBCheck(textBOutcomes($result, $policy) === ['fields:1' => 'blocked', 'fields:2' => 'blocked', 'native:name' => 'verified'] && TextBFixture::$wrapperCalls === 0, 'Mandatory string0 blocks its entire incoming group, not independent native work.');
    [$policy, $work] = textBMulti([['b' => 'fields:1', 'value' => str_repeat('x', 256)], ['b' => 'fields:2'], ['b' => 'fields:3']]);
    $result = json_decode(textBPost($work)['result'], true);
    textBCheck(textBOutcomes($result, $policy) === ['fields:1' => 'blocked', 'fields:2' => 'blocked', 'fields:3' => 'verified'], 'Over-limit incoming member stays in membership and blocks only its actual container.');

    [$policy, $work] = textBMulti([['b' => 'fields:1'], ['b' => 'fields:2'], ['b' => 'fields:3']]);
    TextBFixture::$fault = 'ignore'; TextBFixture::$faultContainer = 11;
    $result = json_decode(textBPost($work)['result'], true);
    textBCheck(textBOutcomes($result, $policy) === ['fields:1' => 'blocked', 'fields:2' => 'blocked', 'fields:3' => 'verified'], 'Exact readback failure rolls back one container and continues an independent container.');
    [$policy, $work] = textBMulti([['b' => 'fields:1'], ['b' => 'fields:2'], ['b' => 'fields:3']]);
    $mask = [];
    foreach ($policy['mappings'] as $mapping) { if ($mapping['b']['capture_key'] !== 'fields:2') { $mask[] = $mapping['mapping_id']; } }
    $row = textBPost($work, $mask); $result = json_decode($row['result'], true);
    textBCheck(textBOutcomes($result, $policy) === ['fields:1' => 'blocked', 'fields:2' => 'blocked', 'fields:3' => 'verified'], 'Omitting one never-started member atomically closes the entire group.');
    $again = textBPost($work);
    textBCheck($again['result'] === $row['result'] && TextBFixture::$modelCalls === 1, 'A later broad mask cannot reopen a closed group.');

    [$policy, $work] = textBMulti([['b' => 'native:name'], ['b' => 'fields:1'], ['b' => 'fields:3']]);
    TextBFixture::$afterDone = static function (): void { TextBFixture::$monotonic += 5_000_000_001; };
    $row = textBPost($work); $partial = json_decode($row['result'], true);
    textBCheck($partial['b_complete'] === false && $row['state'] === 'active' && count(array_filter($partial['groups'], static fn ($g) => $g['phase'] === 'pending')) === 2, 'One shared budget yields with untouched groups durably pending.');
    $row = textBPost($work); $complete = json_decode($row['result'], true);
    TextBoth::validateProgress($work, $policy, $partial, $complete);
    textBCheck($complete['b_complete'] === true && TextBFixture::$modelCalls === 3 && $complete['revision'] === 10, 'Same-ID continuation finishes pending groups without repeating completed models.');

    [$policy, $work] = textBMulti(array_map(static fn ($key): array => ['b' => $key], ['native:name', 'native:comment', 'fields:1', 'fields:2', 'fields:3', 'fields:4', 'fields:5', 'fields:6']));
    $row = textBPost($work); $result = json_decode($row['result'], true);
    textBCheck(count($result['fields']) === 8 && array_values(array_unique(array_column($result['fields'], 'outcome'))) === ['verified'], 'All eight configured rows execute without selecting a subset.');
    textBCheck(TextBFixture::$modelCalls === 3 && TextBFixture::$wrapperCalls === 2 && count($result['groups']) === 3, 'Two native fields and six custom fields use exactly their three real storage groups.');
    foreach ($result['fields'] as $i => $field) { textBCheck($field['after']['origin'] === $work['sources'][$i]['envelope']['origin'], 'Eight-row receipt preserves each distinct source event.'); }
    $get = textBGet($work, $row['id']);
    textBCheck(count($get['read_observation']['fields']) === 8 && strlen(json_encode($get)) <= 60000, 'One bounded GET observes the complete eight-field asset.');
    $beforeGet = $DB->firstRow(RestWork::getTable(), ['id' => $row['id']]); textBGet($work, $row['id']);
    textBCheck($DB->firstRow(RestWork::getTable(), ['id' => $row['id']]) === $beforeGet, 'Repeated all-field GET never persists progress or observations.');

    [$policy, $work] = textBMulti([['b' => 'fields:1'], ['b' => 'fields:3']]);
    // Block only the first committed group's metadata qualification, not another field's hooks.
    $firstId = null;
    foreach ($policy['mappings'] as $mapping) {
        $gid = substr(TaskExecutor::hash(['text-group', $work['id'], 'b', 'fields', $mapping['b']['container_id']]), 0, 32);
        if ($firstId === null || strcmp($gid, $firstId) < 0) { $firstId = $gid; TextBFixture::$blockSettlementKey = $mapping['b']['capture_key']; }
    }
    TextBFixture::$loseModelCommit = true;
    $row = textBPost($work); $pending = json_decode($row['result'], true);
    textBCheck($row['state'] === 'held' && TextBFixture::$modelCalls === 2 && count(array_filter($pending['groups'], static fn ($g) => $g['phase'] === 'done')) === 1,
        'Lost committed reply holds one receipt while another container completes independently.');
    $history = $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC);
    $get = textBGet($work, $row['id']);
    textBCheck(count(array_filter($get['read_observation']['fields'], static fn ($o) => $o['current_matches'] === null)) === 1, 'GET reports unresolved field as null without settling it.');
    TextBFixture::$blockSettlement = false; TextBFixture::$wall += 301;
    $row = textBPost($work, []); $done = json_decode($row['result'], true);
    TextBoth::validateProgress($work, $policy, $pending, $done);
    textBCheck($done['b_complete'] === true && TextBFixture::$modelCalls === 2 && $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC) === $history,
        'Expired restrictive retry may settle committed receipts but cannot repeat or cancel either model.');

    [$policy, $work] = textBMulti([['b' => 'fields:1'], ['b' => 'fields:3']]);
    $first = [];
    foreach ($policy['mappings'] as $mapping) {
        $gid = substr(TaskExecutor::hash(['text-group', $work['id'], 'b', 'fields', $mapping['b']['container_id']]), 0, 32);
        $first[$gid] = $mapping['b']['capture_key'];
    }
    ksort($first); $changedKey = reset($first); TextBFixture::$blockSettlementKey = $changedKey;
    TextBFixture::$loseModelCommit = true; $row = textBPost($work); $held = json_decode($row['result'], true);
    TextBFixture::$blockSettlement = false; TextBFixture::$epoch = 400;
    textBHuman($changedKey, 'Later genuine browser edit');
    $history = $DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC);
    $row = textBPost($work, []);
    textBCheck($row['state'] === 'held' && json_decode($row['result'], true) === $held && TextBFixture::$modelCalls === 3,
        'Later genuine edit cannot rewrite a committed pending receipt or replay either sync model.');
    textBCheck($DB->sql->pdo->query('SELECT * FROM glpi_logs')->fetchAll(PDO::FETCH_ASSOC) === $history
        && FieldEvidence::envelope(201, $changedKey)['value'] === 'Later genuine browser edit', 'Held metadata recovery preserves the newer edit and its History.');

    [$policy, $work] = textBMulti([['b' => 'fields:1'], ['b' => 'fields:2'], ['b' => 'native:name']]);
    TextBFixture::$loseIntentCommit = true;
    textBCheck(textBPost($work)['state'] === 'held', 'Multi-group lost intent reply retains complete plan and continues independent group.');
    $row = textBPost($work, []); $uncertain = json_decode($row['result'], true);
    textBCheck($row['state'] === 'held' && count(array_filter($uncertain['groups'], static fn ($g) => $g['phase'] === 'uncertain')) === 1 && TextBFixture::$modelCalls === 1,
        'A restrictive mask cannot turn a possibly started orphan intent into known-none or replay it.');
    $get = textBGet($work, $row['id']); TextBoth::validateReadObservation($uncertain, $get['read_observation']);
    foreach ($uncertain['fields'] as $i => $field) {
        if ($field['outcome'] !== 'uncertain') { continue; }
        textBCheck($get['read_observation']['fields'][$i]['receipt_hash'] === TaskExecutor::hash($field)
            && $get['read_observation']['fields'][$i]['current_matches'] === null, 'Uncertain facts have a stable receipt hash but never boolean readiness.');
    }

    [$policy, $work] = textBMulti([['b' => 'fields:2', 'a' => 'native:name', 'winner' => 'b', 'before' => str_repeat('b', 256)], ['b' => 'fields:3']]);
    $result = json_decode(textBPost($work)['result'], true);
    textBCheck(textBOutcomes($result, $policy) === ['fields:2' => 'blocked', 'fields:3' => 'verified'], 'Reverse native destination overflow blocks its offered group without blocking B work.');

    [$policy, $work] = textBMulti([['b' => 'native:name'], ['b' => 'fields:1']]);
    TextBFixture::$afterModel = static function (): void { TextBFixture::$update = false; };
    $row = textBPost($work); $result = json_decode($row['result'], true);
    textBCheck(count(array_filter($result['fields'], static fn ($f) => $f['outcome'] === 'verified')) === 0, 'Fresh post-model asset UPDATE revocation prevents commit.');
    foreach ($policy['mappings'] as $mapping) {
        $fresh = FieldEvidence::envelope(201, $mapping['b']['capture_key']);
        textBCheck($fresh['value'] !== 'A value 0' && $fresh['value'] !== 'A value 1', 'Revoked model transaction preserves prior destination values.');
    }

    [$policy, $work] = textBMulti([['b' => 'fields:1'], ['b' => 'fields:2'], ['b' => 'native:name']]);
    TextBFixture::$afterIntent = static function (): void {
        $session = $_SESSION; TextBFixture::$epoch = 300; textBHuman('fields:1', 'ABA temporary'); textBHuman('fields:1', 'Genuine B fields:1'); $_SESSION = $session;
    };
    $result = json_decode(textBPost($work)['result'], true);
    textBCheck(textBOutcomes($result, $policy)['fields:1'] === 'blocked' && textBOutcomes($result, $policy)['fields:2'] === 'blocked', 'Edit/revert changes original event and blocks the whole stale incoming group.');

    [$policy, $work] = textBSetup('fields:2', true);
    $before = textBHuman('fields:2', str_repeat('b', 59000));
    $work['sources'][0]['envelope']['value'] = str_repeat('a', 5000); $work['sources'][0]['envelope']['hash'] = textBValueHash(str_repeat('a', 5000));
    $work['source_hash'] = TaskExecutor::hash($work['sources']); TextBFixture::$modelCalls = TextBFixture::$wrapperCalls = 0;
    textBReject(static fn () => textBPost($work), 'Worst complete escaped response rejected before first mutation.');
    textBCheck(TextBFixture::$modelCalls === 0, 'Response preflight never truncates or starts an oversized plan.');

    [$policy, $work] = textBSetup();
    $catalog = ['version' => 13, 'id' => md5('selected-batch'), 'kind' => 'catalog', 'installation' => $policy['installation'], 'registration' => $policy['registration'],
        'generation' => $policy['generation'], 'connection_id' => 'b', 'itemtype' => 'Computer', 'entity_id' => 100,
        'selector' => ['kind' => 'selected', 'capture_keys' => ['fields:1', 'fields:999', 'native:comment']], 'expires' => TextBFixture::$wall + 300];
    $row = textBPost($catalog); $selected = json_decode($row['result'], true)['fields'];
    textBCheck(array_column($selected, 'capture_key') === $catalog['selector']['capture_keys'] && $selected[1]['descriptor'] === null && $selected[1]['reason'] === 'field_unavailable', 'Selected catalog returns exactly one ordered entry including unavailable definitions.');
    $bad = $policy; $bad['mappings'][] = $bad['mappings'][0];
    textBReject(static fn () => RestSettings::decode(json_encode($bad), TaskExecutor::hash($bad)), 'Duplicate endpoint/colliding mapping rejected as a complete scope.');
    $bad = $policy; $bad['mappings'] = array_fill(0, 9, $policy['mappings'][0]);
    textBReject(static fn () => RestSettings::decode(json_encode($bad), TaskExecutor::hash($bad)), 'Ninth Both mapping rejects entire scope.');
    textBReject(static fn () => textBPost($work, [md5('foreign')]), 'Unknown restrictive mask rejected before plan SQL.');
    echo "Text Both B: $checks checks PASS (host SQLite/GLPI doubles only; native runtime pending).\n";
}
