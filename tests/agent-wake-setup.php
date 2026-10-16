<?php
declare(strict_types=1);

namespace Glpi\Security {
    final class TOTPManager {
        public const ENFORCEMENT_OPTIONAL = 0;
        public function is2FAEnabled(int $id): bool { return false; }
        public function get2FAEnforcement(int $id): int { return 0; }
    }
}
namespace Glpi\DBAL {
    final class QuerySubQuery { public function __construct(array $query) {} }
}
namespace {
    require_once __DIR__ . '/agent-api-fixtures.php';
    $smokeBootstrapOnly = true;
    require __DIR__ . '/smoke.php';
    require __DIR__ . '/../assetsync20agent/src/autoload.php';
    define('READ', 1); define('UPDATE', 2);
    class Session {
        public static bool $canUpdate = true;
        public static array $checked = [];
        public static function checkCentralAccess(): void {}
        public static function haveRight(string $right, int $level): bool { return $level === READ || self::$canUpdate; }
        public static function checkRight(string $right, int $level): void {
            self::$checked[] = $level;
            if (!self::haveRight($right, $level)) { throw new RuntimeException('Update denied'); }
        }
        public static function getNewCSRFToken(): string { return 'test-csrf'; }
        public static function getActiveEntities(): array { return [0]; }
    }
    class User {
        public array $fields = [];
        public function getFromDB(int $id): bool { $this->fields = ['name' => 'fixture', 'is_active' => 1, 'is_deleted' => 0, '2fa' => null, '2fa_unenforced' => 0]; return true; }
        public static function dropdown(array $options): void { echo '<select name="service_user"><option>Fixture</option></select>'; }
    }
    class Dropdown {
        public static function show(string $type, array $options): void { echo '<select name="service_profile"><option>Fixture</option></select>'; }
        public static function getDropdownName(string $table, int $id): string { return 'Fixture'; }
    }
    class Html {
        public static function header(...$args): void {}
        public static function footer(): void {}
    }
    $CFG_GLPI = ['2fa_enforced' => 0];
    $DB = new FakeDB();
    $DB->queryResult = static fn (string $sql) => str_contains($sql, 'glpi_plugin_assetsync20agent_outbox') ? [] : null;
    $_SESSION = ['caller' => 'preserved'];
    $checks = 0;
    function setupCheck(bool $ok, string $message): void {
        global $checks;
        $checks++;
        if (!$ok) { throw new RuntimeException($message); }
    }
    function setupRender(string $side, array $post = []): string {
        $_POST = $post; $_GET = $side === 'a' ? ['id' => 'setup'] : [];
        $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
        $_SERVER['PHP_SELF'] = '/plugins/' . ($side === 'a' ? 'assetsync20' : 'assetsync20agent') . '/front/config.php';
        $path = dirname(__DIR__) . ($side === 'a' ? '/front' : '/assetsync20agent/front');
        $source = file_get_contents($path . '/config.php');
        $source = str_replace("include GLPI_ROOT . '/inc/includes.php';", '', $source);
        $source = str_replace('__DIR__', var_export($path, true), $source);
        ob_start();
        try { eval(substr($source, strlen('<?php'))); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    use GlpiPlugin\Assetsync20\GlpiBConnection;
    use GlpiPlugin\Assetsync20agent\Settings;
    GlpiBConnection::save(['id' => 'setup', 'mode' => 'agent', 'name' => '<Setup>', 'active' => true,
        'base_url' => 'http://browser.invalid', 'wake_enabled' => true,
        'agent_api_url' => 'https://backend.invalid/plugins/assetsync20agent/front/agent-api.php',
        'agent_app_token' => 'test-app', 'agent_user_token' => 'test-user', 'agent_peer_user' => '7',
        'agent_peer_api_client' => '9', 'agent_peer_profile' => '4', 'agent_peer_entity' => '2']);
    $settings = ['mode' => 'agent', 'installation' => str_repeat('a', 32), 'registration' => str_repeat('b', 32),
        'generation' => str_repeat('c', 32), 'connection_id' => 'setup',
        'agent_auth' => 'glpi_session', 'agent_api_url' => 'https://a.invalid/plugins/assetsync20/front/agent-api.php',
        'agent_app_token' => 'test-app', 'agent_user_token' => 'test-user', 'service_user' => '7',
        'service_profile' => '4', 'service_name' => 'fixture', 'service_password' => 'private-service-password', 'agent_peer_user' => '7', 'agent_peer_api_client' => '9', 'agent_peer_profile' => '4', 'agent_peer_entity' => '2'];
    Config::$values[Settings::CONTEXT] = $settings;
    $before = Config::$values;
    $a = setupRender('a'); $b = setupRender('b');
    setupCheck(Config::$values === $before, 'GET views leave settings unchanged.');
    setupCheck(str_contains($a, '&lt;Setup&gt;') && !str_contains($a, str_repeat('e', 64)), 'A escapes labels and does not echo its saved key.');
    setupCheck(str_contains($a, 'http://browser.invalid') && str_contains($a, 'https://backend.invalid'), 'A distinguishes browser and backend URLs.');
    setupCheck(!str_contains($a, 'name="wake_token"') && str_contains($b, 'name="service_password"')
        && !str_contains($b, 'private-service-password') && !str_contains($b, str_repeat('d', 64)), 'Saved secrets stay out of rendered fields.');
    setupCheck(str_contains($a, '_glpi_csrf_token') && str_contains($b, '_glpi_csrf_token'), 'Both setup forms carry the core CSRF field.');
    foreach (['/front/config.php', '/assetsync20agent/front/config.php'] as $file) {
        setupCheck(str_contains(file_get_contents(dirname(__DIR__) . $file), "header('Cache-Control: no-store')"), 'Setup responses prohibit caching: ' . $file);
    }
    $a = setupRender('a', ['save' => '1', 'id' => 'setup', 'mode' => 'agent', 'name' => '<Setup>', 'active' => '1',
        'base_url' => 'http://browser.invalid', 'wake_enabled' => '1', 'agent_app_token' => '', 'agent_user_token' => '',
        'agent_api_url' => 'https://backend.invalid/plugins/assetsync20agent/front/agent-api.php',
        'agent_peer_user' => '7', 'agent_peer_api_client' => '9', 'agent_peer_profile' => '4', 'agent_peer_entity' => '2']);
    setupCheck(GlpiBConnection::find('setup')['agent_user_token'] === 'test-user' && str_contains($a, 'connection saved'),
        'Blank native password keeps the saved GLPI token.');
    $savedA = Config::$values;
    $a = setupRender('a', ['save' => '1', 'id' => 'setup', 'mode' => 'agent', 'base_url' => 'http://browser.invalid',
        'active' => '1', 'agent_api_url' => 'http://insecure.invalid']);
    setupCheck(Config::$values === $savedA && str_contains($a, 'direct HTTPS'), 'Invalid native URL reports an error without saving.');
    $screens = getenv('WAKE_SETUP_SCREENS');
    if (is_string($screens) && $screens !== '') {
        $css = file_get_contents(dirname(__DIR__) . '/public/css/assetsync20.css');
        foreach (['a' => setupRender('a'), 'b' => setupRender('b')] as $side => $body) {
            file_put_contents($screens . '/' . $side . '-setup.html', '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px Arial;margin:0}table{border-collapse:collapse}th,td{padding:8px;border:1px solid #d7dce0;text-align:left;overflow-wrap:anywhere}h2{font-size:22px}h3{font-size:18px}button,input,select{font:inherit}fieldset{min-width:0}' . $css . '</style>' . $body);
        }
    }
    $b = setupRender('b', ['save_wake' => '1', 'wake_enabled' => '1', '_glpi_csrf_token' => 'test-csrf']);
    setupCheck(Settings::load()['wake_enabled'] === '1' && !str_contains($b, 'new_wake_key')
        && !str_contains($b, 'rotate_wake') && !str_contains($b, 'Legacy registration'),
        'Native wake enables without custom key generation, display or rotation.');
    setupRender('b', ['disable_wake' => '1']);
    setupCheck(Settings::load()['wake_enabled'] === '0' && Settings::load()['wake_binding'] === '', 'Disable clears only derived wake admission.');
    Session::$canUpdate = false;
    $before = Config::$values;
    setupCheck(str_contains(setupRender('a'), ' disabled>') && str_contains(setupRender('b'), ' disabled>'), 'Read-only views disable controls.');
    foreach (['a', 'b'] as $side) {
        $denied = false;
        try { setupRender($side, ['save_wake' => '1', 'save' => '1']); }
        catch (RuntimeException $error) { $denied = $error->getMessage() === 'Update denied'; }
        setupCheck($denied && Config::$values === $before, 'Read-only POST cannot mutate ' . $side . ' settings.');
    }
    setupCheck(in_array(READ, Session::$checked, true) && in_array(UPDATE, Session::$checked, true), 'Actual render path checks view and update permissions.');
    echo "Wake setup tests passed ($checks checks; GLPI bootstrap/CSRF listener mocked, no client requests).\n";
}
