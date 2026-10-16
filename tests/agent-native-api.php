<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    final class EntitySyncRoute {
        public static function loadAll(bool $fresh = false): array {
            return [['active' => true, 'glpi_b_connection_id' => 'peer',
                'glpi_a_source_entity_id' => \NativeApiFixture::$routeEntity,
                'include_child_entities' => false, 'asset_types' => ['Computer']]];
        }
    }
    final class FieldMapping {
        public static function endRunCache(): void {}
        public static function load(string $connection, string $type): array { return [['glpi_a_field_key' => 'Computer.PluginFieldsFixture.value']]; }
    }
    final class FieldsText {
        public static function endRunCache(): void {}
        public static function customMetadata(string $type, array $keys): array {
            if (!\NativeApiFixture::$fieldsRead) { throw new \RuntimeException('Container read denied'); }
            return [['field_id' => \NativeApiFixture::$wrongFieldEntity ? 1 : (($_SESSION['glpiactive_entity'] ?? 2) === 3 ? 2 : 1)]];
        }
    }
    function file_get_contents(string $path, mixed ...$args): string { return \NativeApiFixture::$body; }
    function session_id(?string $id = null): string { $before = \NativeApiFixture::$id; if ($id !== null) { \NativeApiFixture::$id = $id; } return $before; }
    function session_status(): int { return \NativeApiFixture::$active ? PHP_SESSION_ACTIVE : PHP_SESSION_NONE; }
    function session_write_close(): bool { \NativeApiFixture::close(); return true; }
    function session_destroy(): bool { unset(\NativeApiFixture::$sessions[\NativeApiFixture::$id]); \NativeApiFixture::$active = false; return true; }
    function ini_get(string $key): string { return \NativeApiFixture::$options[$key]; }
    function ini_set(string $key, string $value): string { $old = \NativeApiFixture::$options[$key]; \NativeApiFixture::$options[$key] = $value; return $old; }
}

namespace GlpiPlugin\Assetsync20agent {
    function file_get_contents(string $path, mixed ...$args): string { return \NativeApiFixture::$body; }
    function session_id(?string $id = null): string { $before = \NativeApiFixture::$id; if ($id !== null) { \NativeApiFixture::$id = $id; } return $before; }
    function session_status(): int { return \NativeApiFixture::$active ? PHP_SESSION_ACTIVE : PHP_SESSION_NONE; }
    function session_write_close(): bool { \NativeApiFixture::close(); return true; }
    function session_destroy(): bool { unset(\NativeApiFixture::$sessions[\NativeApiFixture::$id]); \NativeApiFixture::$active = false; return true; }
    function ini_get(string $key): string { return \NativeApiFixture::$options[$key]; }
    function ini_set(string $key, string $value): string { $old = \NativeApiFixture::$options[$key]; \NativeApiFixture::$options[$key] = $value; return $old; }
}

namespace Glpi\Search {
    final class SearchOption { public static function clearSearchOptionCache(?string $type = null): void {} }
}
namespace Glpi\Security {
    final class TOTPManager {
        public const ENFORCEMENT_OPTIONAL = 0;
        public function is2FAEnabled(int $id): bool { return \NativeApiFixture::$mfa; }
        public function get2FAEnforcement(int $id): int { return \NativeApiFixture::$mfa ? 1 : 0; }
    }
}
namespace Glpi\Api {
    class APIRest {
        protected array $parameters = [];
        protected array $app_tokens = [];
        protected int $apiclients_id = 0;
        protected string $request_uri;
        protected string $verb;
        protected array $url_elements;
        public function parseIncomingParams($doc = false) {
            $this->parameters = ['app_token' => $_SERVER['HTTP_APP_TOKEN']];
            if (isset($_SERVER['HTTP_SESSION_TOKEN'])) { $this->parameters['session_token'] = $_SERVER['HTTP_SESSION_TOKEN']; }
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) { $this->parameters['user_token'] = substr($_SERVER['HTTP_AUTHORIZATION'], 11); }
        }
        protected function retrieveSession() {
            \NativeApiFixture::$id = $this->parameters['session_token'];
            \NativeApiFixture::$active = true;
            $_SESSION = \NativeApiFixture::$sessions[\NativeApiFixture::$id] ?? [];
        }
        public function initApi() {
            global $CFG_GLPI, $TRANSLATE;
            \NativeApiFixture::$core['api']++;
            $this->app_tokens = \NativeApiFixture::$apiEnabled ? [9 => 'app'] : [];
            $CFG_GLPI['native_change'] = true; $TRANSLATE = 'native';
            date_default_timezone_set('Pacific/Auckland');
            if (!\NativeApiFixture::$apiEnabled) { $this->returnResponse(['error' => 'API disabled'], 401); }
        }
        protected function initSession($params = []) {
            \NativeApiFixture::$core['init']++;
            $this->apiclients_id = 9;
            \NativeApiFixture::$id = 'native-session-1234567890';
            $_SESSION['glpiID'] = \NativeApiFixture::$loginUser;
            $_SESSION['glpiactiveprofile'] = ['id' => 99, 'config' => 32767];
            $_REQUEST['user_token'] = 'temporary';
            \NativeApiFixture::close();
            if (\NativeApiFixture::$loginFailure) { $this->returnResponse(['error' => 'Core login denied'], 401); }
            return ['session_token' => \NativeApiFixture::$id];
        }
        protected function initEndpoint($unlock = true, $op = '') {
            \NativeApiFixture::$core['endpoint']++;
            $this->apiclients_id = 9;
            if (!isset($_SESSION['glpiID'])) { $this->returnResponse(['error' => 'Core session expired'], 401); }
            if ($unlock) { \NativeApiFixture::close(); }
        }
        protected function killSession() {
            \NativeApiFixture::$core['kill']++;
            \Session::start(); $_SESSION = [];
            return true;
        }
    }
}
namespace GlpiPlugin\Assetsync20 {
    final class AgentInbox {
        public static function connection(string $id): ?array { return $id === 'peer' ? \NativeApiFixture::$peer : null; }
        public static function decode(string $body): array { return json_decode($body, true); }
        public static function receive(array $payload, AgentApi $api): array {
            $api->assertPeer(\NativeApiFixture::$peer); return \NativeApiFixture::dispatch($payload);
        }
    }
    final class GlpiBConnection {
        public static function endRunCache(): void {}
        public static function isAgent(array $peer): bool { return ($peer['mode'] ?? '') === 'agent'; }
    }
    final class AgentTask {
        public static function decode(string $body): array { return json_decode($body, true); }
        public static function exchange(array $payload, AgentApi $api): array {
            $api->assertPeer(\NativeApiFixture::$peer);
            if (($payload['kind'] ?? '') !== 'result' && !$api->activateReadAsset('Computer', (int) ($payload['asset'] ?? 101))) {
                return ['status' => 200, 'task' => null];
            }
            $result = \NativeApiFixture::dispatch($payload);
            $api->restoreReadScope();
            return $result;
        }
    }
}
namespace GlpiPlugin\Assetsync20agent {
    final class Settings { public static function load(): array { return \NativeApiFixture::$peer; } }
    final class Wake {
        public static function receive(array $payload, AgentApi $api): array {
            $api->assertPeer(\NativeApiFixture::$peer);
            if (\NativeApiFixture::$rateLimited) { throw new \RuntimeException('Wake rate limited.', 429); }
            return \NativeApiFixture::dispatch($payload);
        }
    }
}
namespace {
    define('READ', 1);
    require __DIR__ . '/../src/AgentSession.php';
    require __DIR__ . '/../assetsync20agent/src/AgentSession.php';
    require __DIR__ . '/../src/AgentApi.php';
    require __DIR__ . '/../assetsync20agent/src/AgentApi.php';

    final class NativeApiFixture {
        public static string $routeEntity = '2';
        public static bool $fieldsRead = true;
        public static array $grantEntities = [2, 3];
        public static bool $wrongFieldEntity = false;
        public static array $peer;
        public static array $sessions = [];
        public static array $core = ['api' => 0, 'init' => 0, 'endpoint' => 0, 'kill' => 0];
        public static array $options = ['session.use_cookies' => '1', 'session.use_only_cookies' => '1', 'session.use_trans_sid' => '1'];
        public static string $body = '{}';
        public static string $id = '';
        public static bool $active = false;
        public static bool $mfa = false;
        public static bool $account = true;
        public static bool $grant = true;
        public static bool $apiEnabled = true;
        public static bool $loginFailure = false;
        public static bool $languageFailure = false;
        public static bool $rateLimited = false;
        public static bool $conflict = false;
        public static int $loginUser = 7;
        public static int $rights = 1;
        public static int $dispatches = 0;
        public static array $scope = [];
        public static function close(): void {
            if (self::$active) { self::$sessions[self::$id] = $_SESSION; }
            self::$active = false;
        }
        public static function dispatch(array $payload): array {
            if (self::$active) { throw new LogicException('API session lock still held'); }
            self::$dispatches++;
            self::$scope = $_SESSION;
            if (self::$conflict) { throw new UnexpectedValueException('Protocol conflict'); }
            return ['status' => 200, 'recorded' => true];
        }
    }
    final class User {
        public array $fields;
        public function getFromDB(int $id): bool {
            $this->fields = ['is_active' => NativeApiFixture::$account, 'is_deleted' => 0, '2fa' => null, '2fa_unenforced' => 0];
            return $id === 7 || $id === 8;
        }
    }
    final class Profile {
        public array $fields = ['id' => 4, 'interface' => 'central'];
        public function getFromDB(int $id): bool { return $id === 4; }
    }
    final class Profile_User { public function getFromDBByCrit(array $criteria): bool { return NativeApiFixture::$grant
        && in_array($criteria['entities_id'], NativeApiFixture::$grantEntities, true); } }
    final class Computer {
        public array $fields;
        public function getFromDB(int $id): bool { $this->fields=['entities_id'=>$id===102?3:2];return in_array($id,[101,102],true); }
        public function can(int $id,int $right): bool { return Session::haveRight('computer',$right)&&Session::haveAccessToEntity($this->fields['entities_id']); }
    }
    final class PluginFieldsField {
        public array $fields;
        public function getFromDB(int $id): bool { $this->fields=['plugin_fields_containers_id'=>$id];return true; }
    }
    final class PluginFieldsContainer {
        public array $fields;
        public function getFromDB(int $id): bool { $this->fields=['id'=>$id,'entities_id'=>$id===2?3:2,'is_active'=>1,'is_recursive'=>0];return true; }
    }
    final class PluginFieldsProfile { public static function getRightOnContainer(int $profile,int $container): int { return NativeApiFixture::$fieldsRead?1:0; } }
    final class APIClient {
        public array $fields = ['is_active' => 1, 'app_token' => 'encrypted'];
        public function getFromDB(int $id): bool { return $id === 9; }
    }
    final class Session {
        public static function start(): void {
            if (!NativeApiFixture::$active) {
                if (NativeApiFixture::$id === '') { NativeApiFixture::$id = 'temporary-session-1234567890'; }
                $_SESSION = NativeApiFixture::$sessions[NativeApiFixture::$id] ?? [];
                NativeApiFixture::$active = true;
            }
        }
        public static function getLoginUserID(): int { return (int) ($_SESSION['glpiID'] ?? 0); }
        public static function initEntityProfiles(int $id): void { $_SESSION['glpiprofiles'] = [4 => ['entities' => [2]]]; }
        public static function changeProfile(int $id): void { $_SESSION['glpiactiveprofile'] = ['id' => $id, 'computer' => NativeApiFixture::$rights]; }
        public static function changeActiveEntities(int $id, bool $recursive): bool {
            $_SESSION['glpiactive_entity'] = $id; $_SESSION['glpiactiveentities'] = [$id];
            $_SESSION['glpiactive_entity_recursive'] = $recursive; return true;
        }
        public static function isCron(): bool { return false; }
        public static function isInventory(): bool { return false; }
        public static function isRightChecksDisabled(): bool { return false; }
        public static function haveAccessToEntity(int $id): bool { return in_array($id,NativeApiFixture::$grantEntities,true); }
        public static function haveRight(string $right, int $mask): bool { return (NativeApiFixture::$rights & $mask) === $mask; }
        public static function loadLanguage(): void { if (NativeApiFixture::$languageFailure) { throw new RuntimeException('Language unavailable'); } }
    }
    $checks = 0;
    function apiCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
    function apiRequest(string $class, string $operation, string $token = '', array $payload = [], array $headers = []): array {
        global $CFG_GLPI, $TRANSLATE;
        $_GET = []; $_POST = []; $_FILES = []; $_COOKIE = [];
        $_SERVER = array_replace(['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json',
            'HTTP_APP_TOKEN' => 'app', 'HTTP_X_ASSETSYNC_OPERATION' => $operation, 'HTTP_X_ASSETSYNC_CONNECTION' => 'peer'], $headers);
        if ($token === '') { $_SERVER['HTTP_AUTHORIZATION'] = 'user_token native'; }
        else { $_SERVER['HTTP_SESSION_TOKEN'] = $token; }
        $_SERVER = array_replace($_SERVER, $headers);
        NativeApiFixture::$body = json_encode((object) $payload);
        $_SESSION = ['caller' => 'preserve'];
        $_REQUEST = ['foreign' => 'preserve', 'user_token' => 'prior-value'];
        $CFG_GLPI = ['2fa_enforced' => 0, 'marker' => 'preserve']; $TRANSLATE = 'original';
        date_default_timezone_set('UTC');
        ob_start();
        try { (new $class())->call(); $body = json_decode((string) ob_get_contents(), true); }
        finally { ob_end_clean(); }
        apiCheck($_SESSION === ['caller' => 'preserve'] && $_REQUEST === ['foreign' => 'preserve', 'user_token' => 'prior-value']
            && $CFG_GLPI === ['2fa_enforced' => 0, 'marker' => 'preserve'] && $TRANSLATE === 'original'
            && date_default_timezone_get() === 'UTC', 'Caller context restored on every path.');
        return ['status' => http_response_code(), 'body' => $body];
    }
    ob_start();
    foreach ([GlpiPlugin\Assetsync20\AgentApi::class, GlpiPlugin\Assetsync20agent\AgentApi::class] as $class) {
        $isB = str_contains($class, 'Assetsync20agent');
        NativeApiFixture::$rights = 1;
        $operation = $isB ? 'wake' : 'task';
        NativeApiFixture::$peer = ['id' => 'peer', 'connection_id' => 'peer', 'mode' => 'agent', 'active' => 1,
            'agent_auth' => 'glpi_session', 'agent_peer_user' => '7', 'agent_peer_api_client' => '9',
            'agent_peer_profile' => '4', 'agent_peer_entity' => '2'];
        NativeApiFixture::$id = ''; NativeApiFixture::$active = false;
        $before = NativeApiFixture::$core['init'];
        apiCheck(apiRequest($class, 'initSession', '', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('d', 64)])['status'] === 401
            && NativeApiFixture::$core['init'] === $before, 'Stale custom bearer headers are denied before native initialization.');
        NativeApiFixture::$mfa = true;
        $before = NativeApiFixture::$core['init'];
        apiCheck(apiRequest($class, 'initSession')['status'] === 401 && NativeApiFixture::$core['init'] === $before, 'MFA denied before native mint.');
        NativeApiFixture::$mfa = false;
        NativeApiFixture::$loginFailure = true;
        apiCheck(apiRequest($class, 'initSession')['status'] === 401, 'Core login 401 preserved.');
        NativeApiFixture::$loginFailure = false;
        NativeApiFixture::$loginUser = 8;
        apiCheck(apiRequest($class, 'initSession')['status'] === 401
            && !isset(NativeApiFixture::$sessions['native-session-1234567890']), 'Wrong authenticated user cannot retain minted session.');
        NativeApiFixture::$loginUser = 7;
        apiCheck(apiRequest($class, 'initSession', '', [], ['HTTP_APP_TOKEN' => 'wrong'])['status'] === 401, 'Wrong pinned app rejected before mint.');
        $login = apiRequest($class, 'initSession');
        apiCheck($login['status'] === 200, 'Native core initialization succeeds.');
        $token = $login['body']['session_token'];
        $reply = apiRequest($class, $operation, $token, ['kind' => 'pull']);
        apiCheck($reply['status'] === 200 && NativeApiFixture::$scope['glpiactiveprofile'] === ['id' => 4, 'computer' => 1]
            && NativeApiFixture::$scope['glpiactiveentities'] === [2], 'Default broad profile replaced by selected exact scope.');
        NativeApiFixture::$rights = 0;
        $reduced = apiRequest($class, $operation, $token);
        apiCheck($reduced['status'] === 200 && ($isB || array_key_exists('task',$reduced['body']) && $reduced['body']['task']===null),
            'Lost A READ withholds data; B execution retains its independent service authorization.');
        NativeApiFixture::$rights = 1;
        if (!$isB) {
            $before = NativeApiFixture::$dispatches;
            apiCheck(apiRequest($class, $operation, $token,['asset'=>102])['status']===200
                && NativeApiFixture::$scope['glpiactiveentities']===[3], 'A second directly granted route uses its actual source entity.');
            apiCheck(apiRequest($class, $operation, $token,['asset'=>101])['status']===200
                && NativeApiFixture::$scope['glpiactiveentities']===[2], 'Cross-entity context switches back freshly.');
            NativeApiFixture::$grantEntities = [2];$before=NativeApiFixture::$dispatches;
            apiCheck(apiRequest($class, $operation, $token,['asset'=>102])['body']['task']===null
                && NativeApiFixture::$dispatches===$before,'Revoked second route cannot disclose work.');
            apiCheck(apiRequest($class, 'notify', $token,['kind'=>'health'])['status']===200
                && apiRequest($class,$operation,$token,['kind'=>'result'])['status']===200,
                'Unrelated revoked route does not block health or durable result receipt.');
            NativeApiFixture::$grantEntities=[2,3];$before=NativeApiFixture::$dispatches;
            NativeApiFixture::$wrongFieldEntity=true;
            apiCheck(apiRequest($class,$operation,$token,['asset'=>102])['body']['task']===null
                && NativeApiFixture::$dispatches===$before,'A previous entity container cannot authorize another entity.');
            NativeApiFixture::$wrongFieldEntity=false;
            NativeApiFixture::$fieldsRead = false;
            apiCheck(apiRequest($class, $operation, $token)['body']['task']===null && NativeApiFixture::$dispatches === $before,
                'Mapped container READ is required before task disclosure.');
            NativeApiFixture::$fieldsRead = true;
        }
        NativeApiFixture::$grant = false;
        $dispatches = NativeApiFixture::$dispatches;
        apiCheck(apiRequest($class, $operation, $token)['status'] === 401 && NativeApiFixture::$dispatches === $dispatches
            && isset(NativeApiFixture::$sessions[$token]['glpiID']), 'Grant revocation blocks operation without killing reusable session.');
        NativeApiFixture::$grant = true;
        NativeApiFixture::$account = false;
        apiCheck(apiRequest($class, $operation, $token)['status'] === 401, 'Account disable blocks existing session.');
        NativeApiFixture::$account = true;
        NativeApiFixture::$conflict = true;
        apiCheck(apiRequest($class, $operation, $token)['status'] === 409, 'Protocol conflicts preserve 409.');
        NativeApiFixture::$conflict = false;
        if ($isB) {
            NativeApiFixture::$rateLimited = true;
            apiCheck(apiRequest($class, $operation, $token)['status'] === 429, 'Wake rate limit preserves 429.');
            NativeApiFixture::$rateLimited = false;
        }
        $dispatches = NativeApiFixture::$dispatches;
        apiCheck(apiRequest($class, 'Computer', $token)['status'] === 400 && NativeApiFixture::$dispatches === $dispatches, 'No generic asset CRUD dispatch.');
        apiCheck(apiRequest($class, $operation, $token, ['session_token' => 'body'])['status'] === 400, 'Body credentials rejected.');
        apiCheck(apiRequest($class, $operation, $token, [], ['QUERY_STRING' => 'user_token=query'])['status'] === 400, 'Query credentials rejected.');
        apiCheck(apiRequest($class, $operation, $token, [], ['HTTP_COOKIE' => 'browser=foreign'])['status'] === 401, 'Cookie session rejected.');
        NativeApiFixture::$active = true; NativeApiFixture::$id = 'foreign-browser';
        apiCheck(apiRequest($class, $operation, $token)['status'] === 401 && NativeApiFixture::$active
            && NativeApiFixture::$id === 'foreign-browser', 'Unexpected active browser is not closed or destroyed.');
        NativeApiFixture::$active = false; NativeApiFixture::$id = '';
        NativeApiFixture::$languageFailure = true;
        apiCheck(apiRequest($class, $operation, $token)['status'] === 503, 'Cleanup failure still restores caller state.');
        NativeApiFixture::$languageFailure = false;
        apiCheck(apiRequest($class, 'killSession', $token)['status'] === 200
            && NativeApiFixture::$sessions[$token] === [], 'Explicit native kill uses core lifecycle.');
        apiCheck(NativeApiFixture::$options === ['session.use_cookies' => '1', 'session.use_only_cookies' => '1', 'session.use_trans_sid' => '1'],
            'Session policies restored.');
    }
    ob_end_clean();
    echo "Native API adapter tests passed ({$checks} checks; core/session doubles, real GLPI lifecycle still requires runtime QA).\n";
}

