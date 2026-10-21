<?php
declare(strict_types=1);

namespace Glpi\Search { final class SearchOption { public static function clearSearchOptionCache(string $type): void {} } }
namespace Glpi\Security {
    final class TOTPManager {
        public const ENFORCEMENT_OPTIONAL = 0;
        public function is2FAEnabled(int $id): bool { return false; }
        public function get2FAEnforcement(int $id): int { return 0; }
    }
}
namespace GlpiPlugin\Assetsync20agent {
    const PHP_SAPI = 'apache2handler';
    final class TargetScope { public static function model(string $type): string { return $type; } }
    function ini_get(string $key): string|false { return \HttpSessionFixture::$fault === 'read' && str_ends_with($key, 'trans_sid') ? false : \HttpSessionFixture::$options[$key]; }
    function ini_set(string $key, string $value): string|false {
        $old = \HttpSessionFixture::$options[$key];
        if ($value === '0' && str_ends_with($key, 'trans_sid') && \HttpSessionFixture::$fault !== '') {
            return \HttpSessionFixture::$fault === 'lie' ? $old : false;
        }
        \HttpSessionFixture::$options[$key] = $value;
        return $old;
    }
    function session_status(): int { return \HttpSessionFixture::$active ? PHP_SESSION_ACTIVE : PHP_SESSION_NONE; }
    function session_id(?string $id = null): string { $old = \HttpSessionFixture::$id; if ($id !== null) { \HttpSessionFixture::$id = $id; } return $old; }
    function session_start(): bool { \Session::start(); return true; }
    function session_destroy(): bool { \HttpSessionFixture::$active = false; return true; }
    function session_write_close(): bool { \HttpSessionFixture::$active = false; return true; }
}
namespace {
    require __DIR__ . '/../assetsync20agent/src/autoload.php';
    require __DIR__ . '/../assetsync20agent/src/ServiceContext.php';
    final class HttpSessionFixture {
        public static array $options;
        public static string $fault = '';
        public static string $id = 'browser-sentinel';
        public static bool $active = false;
        public static int $starts = 0;
    }
    final class User {
        public array $fields = [];
        public function getFromDB(int $id): bool {
            $this->fields = ['is_active' => 1, 'is_deleted' => 0, '2fa' => null, '2fa_unenforced' => 0]; return true;
        }
    }
    final class Profile_User { public function getFromDBByCrit(array $criteria): bool { return true; } }
    final class Session {
        public static function start(): void {
            foreach (HttpSessionFixture::$options as $value) { if ($value !== '0') { throw new LogicException('Session started without isolation'); } }
            if (HttpSessionFixture::$id === 'browser-sentinel') { throw new LogicException('Browser session adopted'); }
            HttpSessionFixture::$id = 'owned-transient'; HttpSessionFixture::$active = true; HttpSessionFixture::$starts++;
        }
        public static function destroy(): void { self::start(); $_SESSION = []; }
        public static function getLoginUserID(): int { return 7; }
        public static function changeProfile(int $id): void {}
        public static function changeActiveEntities(int $id, bool $recursive): bool { return true; }
        public static function loadLanguage(): void {}
    }
    final class Auth {
        public function login(string $name, string $password, bool $noauto): bool { $_SESSION['glpiprofiles'] = [4 => []]; return true; }
    }
    $checks = 0;
    $check = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
    $settings = ['service_user' => 7, 'service_name' => 'owned', 'service_password' => 'private', 'service_profile' => 4, 'entity_id' => 1];
    foreach (['blocked', 'lie', 'read', ''] as $fault) {
        HttpSessionFixture::$options = ['session.use_cookies' => '1', 'session.use_only_cookies' => '1', 'session.use_trans_sid' => '1'];
        $before = HttpSessionFixture::$options;
        HttpSessionFixture::$fault = $fault; HttpSessionFixture::$id = 'browser-sentinel'; HttpSessionFixture::$active = false; HttpSessionFixture::$starts = 0;
        $_SESSION = ['browser_marker' => 'unchanged']; $CFG_GLPI = ['2fa_enforced' => 0]; $called = false;
        try {
            \GlpiPlugin\Assetsync20agent\ServiceContext::run($settings, static function () use (&$called): void { $called = true; });
            $check($fault === '', 'Hardened or unreadable settings must reject');
        } catch (RuntimeException $error) {
            $check($fault !== '' && str_starts_with($error->getMessage(), 'HTTP service session'), 'Failure is explicit session isolation rejection');
        }
        $check($called === ($fault === ''), 'No operation runs after preparation failure');
        $check($fault === '' || HttpSessionFixture::$starts === 0, 'Failure precedes every session start');
        $check(HttpSessionFixture::$options === $before, 'Partially changed options restored');
        $check($_SESSION === ['browser_marker' => 'unchanged'] && HttpSessionFixture::$id === 'browser-sentinel' && !HttpSessionFixture::$active,
            'Browser state preserved and transient session closed');
    }
    echo "HTTP session isolation tests passed ({$checks} checks; HTTP-context doubles, actual HTTPS separate).\n";
}
