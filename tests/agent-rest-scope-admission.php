<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    // Only the HTTP environment is doubled; admission/criteria code is the real implementation.
    const PHP_SAPI = 'fpm-fcgi';
    function session_status(): int { return PHP_SESSION_NONE; }
    function session_id(): string { return 'native-session'; }
    function file_get_contents(...$arguments): string { return ''; }
    final class Settings {
        public static array $data = ['mode' => 'agent', 'transport' => 'rest_records', 'installation' => 'installation',
            'registration' => 'registration', 'generation' => 'generation', 'connection_id' => 'b',
            'agent_peer_user' => '3', 'agent_peer_profile' => '4', 'agent_peer_entity' => '1', 'agent_peer_api_client' => '5',
            'service_user' => '9', 'service_profile' => '10', 'service_name' => 'writer'];
        public static function load(): array { return self::$data; }
        public static function entities(int $user, int $profile): array { return array_fill_keys(\Profile_User::$grants[$user] ?? [], 'Direct'); }
    }
    final class AgentApi { public static function validatePeer(array $settings): void {} }
}
namespace {
    define('READ', 1); define('UPDATE', 2);
    require __DIR__ . '/query-expression.php';
    require __DIR__ . '/agent-sqlite.php';
    class CommonDBTM {
        public static function getTable(): string { return str_ends_with(static::class, 'RestWork') ? 'rest_work' : 'rest_settings'; }
    }
    class GLPIKey { public function decrypt($value): string { return $value; } }
    class User {
        public array $fields = [];
        public function getFromDB(int $id): bool { $this->fields = ['is_active' => 1, 'is_deleted' => 0]; return true; }
    }
    class Profile {
        public array $fields = [];
        public static array $rights = [4 => READ, 10 => READ];
        public function getFromDB(int $id): bool { $this->fields = ['computer' => self::$rights[$id] ?? 0]; return true; }
    }
    class Profile_User {
        public static array $grants = [3 => [1, 87, 88], 9 => [1, 87, 88]];
        public function getFromDBByCrit(array $where): bool {
            return $where['is_recursive'] === 0 && in_array($where['entities_id'], self::$grants[$where['users_id']] ?? [], true);
        }
    }
    class Session {
        public static array $activations = [];
        public static function getLoginUserID(): int { return 3; }
        public static function isCron(): bool { return false; }
        public static function isInventory(): bool { return false; }
        public static function isRightChecksDisabled(): bool { return false; }
        public static function initEntityProfiles(int $id): void {}
        public static function changeProfile(int $id): void { $_SESSION['glpiactiveprofile'] = ['id' => $id]; }
        public static function changeActiveEntities(int $id, bool $recursive): bool {
            if ($recursive) { throw new LogicException('Recursive activation forbidden.'); }
            self::$activations[] = $id; $_SESSION['glpiactiveentities'] = [$id]; return true;
        }
    }
    require __DIR__ . '/../assetsync20agent/src/TaskExecutor.php';
    require __DIR__ . '/../assetsync20agent/src/Outbox.php';
    require __DIR__ . '/../assetsync20agent/src/ServiceContext.php';
    require __DIR__ . '/../assetsync20agent/src/RestPeer.php';
    require __DIR__ . '/../assetsync20agent/src/RestSettings.php';
    require __DIR__ . '/../assetsync20agent/src/RestWork.php';
    require __DIR__ . '/../assetsync20agent/src/NameBoth.php';
    use GlpiPlugin\Assetsync20agent\RestPeer;
    use GlpiPlugin\Assetsync20agent\RestSettings;
    use GlpiPlugin\Assetsync20agent\RestWork;
    use GlpiPlugin\Assetsync20agent\Settings;
    function admissionScopeCheck(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
    function admissionScopeReject(Closure $operation): void {
        try { $operation(); throw new LogicException('Denied lookup accepted'); } catch (RuntimeException) {}
    }
    $DB = new AgentSqlite();
    $DB->doQuery('CREATE TABLE glpi_apiclients (id INTEGER, is_active INTEGER, app_token TEXT)');
    $DB->insert('glpi_apiclients', ['id' => 5, 'is_active' => 1, 'app_token' => 'native-app']);
    $DB->doQuery('CREATE TABLE rest_work (id INTEGER PRIMARY KEY, compiler INTEGER, work_id TEXT, batch_id TEXT, binding TEXT,
        installation TEXT, registration TEXT, generation TEXT, connection_id TEXT, entities_id INTEGER)');
    $binding = RestSettings::binding(Settings::load(), 2);
    foreach ([87, 88] as $id) {
        $DB->insert('rest_work', ['id' => $id, 'compiler' => 9, 'work_id' => md5('metadata-' . $id), 'batch_id' => null, 'binding' => $binding,
            'installation' => 'installation', 'registration' => 'registration', 'generation' => 'generation', 'connection_id' => 'b', 'entities_id' => $id]);
    }
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_SESSION_TOKEN' => base64_encode('native-session'),
        'HTTP_APP_TOKEN' => 'native-app', 'REQUEST_URI' => '/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork'];
    $_COOKIE = [];
    $lookup = ['range' => '0-0', 'get_hateoas' => '0', 'rest_entity' => '87', 'searchText' => ['compiler' => '9', 'work_id' => md5('metadata-87')]];
    $_GET = $lookup;
    $before = iterator_to_array($DB->request(['FROM' => 'rest_work']));
    RestPeer::settings();
    $criteria = RestWork::getSystemSQLCriteria('rest_work');
    RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [87] && $criteria['AND']['rest_work.entities_id'] === 87,
        'Repeated admission leaves exact lookup entity active for core criteria appended later.');
    admissionScopeCheck($criteria['AND']['rest_work.compiler'] === 9 && $criteria['AND']['rest_work.work_id'] === md5('metadata-87'), 'Lookup SQL binds exact compiler/work ID before LIMIT.');
    admissionScopeCheck(end(Session::$activations) === 87 && in_array(1, Session::$activations, true), 'Base admission still runs, followed by authorized target activation.');
    Profile_User::$grants[9] = [1];
    admissionScopeReject(static fn () => RestPeer::settings());
    Profile_User::$grants[9] = [1, 87, 88];
    RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [87], 'A grant revoked after resource SQL preparation is rechecked by the final admission before core filtering.');
    unset($_GET['rest_entity']); RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [1], 'Old lookup default retains base entity.');
    $_GET = $lookup; $_SERVER['REQUEST_URI'] .= '/87'; unset($_GET['searchText']);
    RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [87], 'Single GET activates only a persisted matching v9/v10 entity/binding.');
    $_GET['rest_entity'] = '88'; admissionScopeReject(static fn () => RestPeer::settings());
    $_GET['rest_entity'] = '87'; Settings::$data['connection_id'] = 'foreign'; admissionScopeReject(static fn () => RestPeer::settings()); Settings::$data['connection_id'] = 'b';
    $_SERVER['REQUEST_URI'] = '/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork';
    foreach (['/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWorkFake', '/apirest.php/Other%5CRestWork',
        '/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork/87/nested', '/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork/0',
        '/prefix%5CRestWork/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestSettings',
        '/nested/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork'] as $uri) {
        $_GET = $lookup; $_SERVER['REQUEST_URI'] = $uri;
        admissionScopeReject(static fn () => RestPeer::settings());
    }
    $_SERVER['REQUEST_URI'] = '/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork';
    $_GET=$lookup; $_SERVER['SCRIPT_NAME']='/glpi/apirest.php';
    $_SERVER['REQUEST_URI']='/glpi/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork';
    RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [87], 'A real configured script subdirectory is supported, not an arbitrary nested endpoint.');
    $_SERVER['SCRIPT_NAME']='/glpi/index.php';
    RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [87], 'Stock GLPI 11 front-controller SCRIPT_NAME retains the real API mount.');
    $_SERVER['REQUEST_URI']='/other/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork';
    admissionScopeReject(static fn () => RestPeer::settings());
    $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork';
    RestPeer::settings();
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [87], 'Stock GLPI 11 root front controller admits only its exact API resource.');
    $_SERVER['SCRIPT_NAME']='/unexpected.php'; admissionScopeReject(static fn () => RestPeer::settings());
    unset($_SERVER['SCRIPT_NAME']); $_SERVER['REQUEST_URI']='/apirest.php/GlpiPlugin%5CAssetsync20agent%5CRestWork';
    foreach ([['rest_entity' => '087'], ['rest_entity' => '-1'], ['rest_entity' => ['87']], ['rest_entity' => '89'],
        ['searchText' => ['compiler' => '8', 'batch_id' => md5('batch')]], ['searchText' => ['compiler' => '9', 'work_id' => '%']],
        ['searchText' => ['compiler' => '10', 'batch_id' => md5('batch'), 'work_id' => md5('work')]]] as $change) {
        $_GET = array_replace($lookup, $change); admissionScopeReject(static fn () => RestPeer::settings());
    }
    $_GET = $lookup; Profile_User::$grants[9] = [1]; admissionScopeReject(static fn () => RestPeer::settings()); Profile_User::$grants[9] = [1, 87, 88];
    Profile_User::$grants[3] = [1]; admissionScopeReject(static fn () => RestPeer::settings()); Profile_User::$grants[3] = [1, 87, 88];
    Profile::$rights[10] = 0; admissionScopeReject(static fn () => RestPeer::settings()); Profile::$rights[10] = READ;
    $_GET = ['range' => '0-3', 'get_hateoas' => '0', 'rest_entity' => '88', 'searchText' => ['compiler' => '10', 'batch_id' => md5('batch')]];
    $criteria = RestWork::getSystemSQLCriteria('rest_work');
    admissionScopeCheck($_SESSION['glpiactiveentities'] === [88] && $criteria['AND']['rest_work.compiler'] === 10
        && $criteria['AND']['rest_work.entities_id'] === 88 && $criteria['AND']['rest_work.batch_id'] === md5('batch'), 'New batch scope remains active and nested/exact even when collection is empty.');
    admissionScopeCheck(iterator_to_array($DB->request(['FROM' => 'rest_work'])) === $before, 'Lookup admission never changes persisted rows.');

    $work=['version'=>10,'id'=>str_repeat('a',32),'installation'=>str_repeat('b',32),'registration'=>str_repeat('c',32),
        'generation'=>str_repeat('d',32),'connection_id'=>'b','settings_id'=>str_repeat('e',32),'settings_hash'=>str_repeat('f',64),
        'pair_id'=>str_repeat('1',32),'itemtype'=>'Computer','items_id'=>201,'entity_id'=>87,'identity'=>['serial'=>'S'],
        'name'=>'Name','source_hash'=>str_repeat('2',64),'expires'=>time()+300,'batch_id'=>str_repeat('3',32),'source_asset_entity'=>12];
    $input=static fn ($w)=>['work_id'=>$w['id'],'hash'=>\GlpiPlugin\Assetsync20agent\TaskExecutor::hash($w),'body'=>json_encode($w)];
    $validate=new ReflectionMethod(RestPeer::class,'validateBatch');
    $validate->invoke(null,[$input($work),$input(array_replace($work,['id'=>md5('ten-sibling')]))]);
    $both=$work; $both['version']=11; unset($both['name'],$both['source_asset_entity']);
    $both['source']=['value'=>'Name','hash'=>hash('sha256','{"type":"text","value":"Name"}'),'meta'=>str_repeat('4',64),'origin'=>null,
        'guard'=>['epoch'=>str_repeat('5',32),'generation'=>1,'head'=>str_repeat('6',64),'history'=>['latest'=>0,'anchor'=>null]]];
    $both['source_hash']=\GlpiPlugin\Assetsync20agent\TaskExecutor::hash($both['source']); $both['source_fingerprint']=str_repeat('7',64);
    $validate->invoke(null,[$input($both),$input(array_replace($both,['id'=>md5('eleven-sibling')]))]);
    admissionScopeReject(static fn ()=>$validate->invoke(null,[$input($work),$input(array_replace($both,['id'=>md5('mixed')]))]));
    admissionScopeReject(static fn ()=>$validate->invoke(null,[$input($both),$input(array_replace($both,['id'=>md5('eleven-sibling')])),
        $input(array_replace($both,['id'=>md5('eleven-third')]))]));
    admissionScopeReject(static fn ()=>$validate->invoke(null,[$input($work),$input(array_replace($work,['id'=>[]]))]));
    admissionScopeReject(static fn ()=>$validate->invoke(null,[$input($work),$input(array_replace($work,['id'=>md5('bad-schema'),'source_asset_entity'=>'12']))]));
    admissionScopeCheck(iterator_to_array($DB->request(['FROM'=>'rest_work'])) === $before,'Whole-list Work10/11 schema validation rejects malformed/mixed inputs before any acceptance can write.');
    echo "REST lookup scope passed: repeated real admission/criteria, exact active entity, direct peer/service grants, single binding and spoof rejection (HTTP/session doubles, not stock core runtime).\n";
}
