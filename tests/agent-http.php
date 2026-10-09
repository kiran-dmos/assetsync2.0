<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    final class AgentTransport
    {
        public static array $options = [];
        public static int $calls = 0;
        public static int $closed = 0;
        public static int $status = 200;
        public static string $body = '{}';
    }
    function curl_init(string $url): object { AgentTransport::$calls++; return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { AgentTransport::$options = $options; return true; }
    function curl_exec(object $handle): bool { return (AgentTransport::$options[CURLOPT_WRITEFUNCTION])($handle, AgentTransport::$body) === strlen(AgentTransport::$body); }
    function curl_getinfo(object $handle, int $option): int { return AgentTransport::$status; }
    function curl_close(object $handle): void { AgentTransport::$closed++; }
}

namespace Glpi\Http {
    class SessionManager
    {
        public static array $patterns = [];
        public static function registerPluginStatelessPath(string $plugin, string $pattern): void { self::$patterns[] = $pattern; }
    }
    class Firewall
    {
        public const STRATEGY_NO_CHECK = 'no_check';
        public static array $patterns = [];
        public static function addPluginStrategyForLegacyScripts(string $plugin, string $pattern, string $strategy): void { self::$patterns[] = $pattern; }
    }
}

namespace {
    require __DIR__ . '/../assetsync20agent/src/autoload.php';
    use GlpiPlugin\Assetsync20agent\AgentTransport;
    use GlpiPlugin\Assetsync20agent\Settings;
    use GlpiPlugin\Assetsync20agent\Worker;

    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        $checks++;
        if (!$ok) { throw new RuntimeException($message); }
    };
    $settings = ['endpoint' => 'https://controller.invalid/plugins/assetsync20/front/agent-notify.php', 'token' => str_repeat('a', 64)];
    $sent = ['registration' => str_repeat('b', 32), 'generation' => str_repeat('c', 32), 'computer_id' => 101, 'revision' => 3,
        'lifecycle' => 'present', 'fingerprint' => 'local-only', 'claim_token' => 'local-only'];
    Worker::send($settings, $sent, hrtime(true) + 900_000_000);
    $options = AgentTransport::$options;
    $check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS certificate and hostname verification are mandatory.');
    $check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS
        && $options[CURLOPT_REDIR_PROTOCOLS] === CURLPROTO_HTTPS, 'No redirects or HTTP protocol are permitted.');
    $check($options[CURLOPT_TIMEOUT_MS] <= 900 && $options[CURLOPT_CONNECTTIMEOUT_MS] <= 900, 'HTTP is bounded by the remaining monotonic cycle budget.');
    $payload = json_decode($options[CURLOPT_POSTFIELDS], true, 8, JSON_THROW_ON_ERROR);
    $check($payload === array_intersect_key($sent, array_flip(['registration', 'generation', 'computer_id', 'revision', 'lifecycle'])), 'Only identity/revision/lifecycle cross the network.');
    $health = ['kind' => 'health', 'registration' => $sent['registration'], 'generation' => $sent['generation'],
        'reconcile_status' => 'degraded', 'computer_id' => 101, 'revision' => 3];
    Worker::send($settings, $health, hrtime(true) + 900_000_000);
    $check(json_decode(AgentTransport::$options[CURLOPT_POSTFIELDS], true) === array_intersect_key($health,
        array_flip(['kind', 'registration', 'generation', 'reconcile_status'])), 'Health payload cannot claim an asset revision.');
    foreach (['http://controller.invalid/front/agent-notify.php', 'https://u:p@controller.invalid/front/agent-notify.php',
        'https://controller.invalid/front/agent-notify.php?token=bad', 'https://controller.invalid/front/agent-notify.php#bad'] as $url) {
        $check(!Settings::validEndpoint($url), 'Reject unsafe endpoint: ' . $url);
    }
    $calls = AgentTransport::$calls;
    Worker::send(array_replace($settings, ['endpoint' => 'http://controller.invalid/front/agent-notify.php']), $sent, hrtime(true) + 1_000_000_000);
    $check(AgentTransport::$calls === $calls, 'HTTP endpoint rejection happens before transport.');
    AgentTransport::$body = str_repeat('x', 2049);
    $result = Worker::send($settings, $sent, hrtime(true) + 1_000_000_000);
    $check($result['status'] === 0 && $result['body'] === [] && AgentTransport::$closed === AgentTransport::$calls, 'Oversized ACK aborts and closes transport.');
    $hook = file_get_contents(__DIR__ . '/../assetsync20agent/src/ChangeHook.php');
    $check(!str_contains($hook, 'Worker::') && !str_contains($hook, 'curl_') && !str_contains($hook, 'http'), 'Save hooks have no HTTP/worker path.');
    require __DIR__ . '/../setup.php';
    plugin_init_assetsync20();
    foreach (array_merge(\Glpi\Http\SessionManager::$patterns, \Glpi\Http\Firewall::$patterns) as $pattern) {
        $check(preg_match($pattern, '/front/agent-notify.php') === 1 && preg_match($pattern, '/front/agents.php') === 0
            && preg_match($pattern, '/front/agent-notify.php/other') === 0 && preg_match($pattern, '/front/agent-notify.phpx') === 0,
            'Actual GLPI stateless/firewall patterns exempt only the exact endpoint.');
    }
    $endpoint = file_get_contents(__DIR__ . '/../front/agent-notify.php');
    $check(str_contains($endpoint, "\$_SERVER['HTTPS']") && !str_contains($endpoint, 'FORWARDED'), 'Receiver does not trust forwarded protocol headers.');
    foreach (['../front/agents.php', '../assetsync20agent/front/config.php'] as $file) {
        $page = file_get_contents(__DIR__ . '/' . $file);
        $check(str_contains($page, "Session::checkRight('config', UPDATE)") && str_contains($page, '_glpi_csrf_token')
            && !str_contains($page, 'STRATEGY_NO_CHECK'), 'Setup pages retain config UPDATE and core CSRF protection.');
    }
    $admin = file_get_contents(__DIR__ . '/../front/agents.php');
    $check(str_contains($admin, 'global $DB;') && str_contains($admin, 'http_response_code(403)'),
        'Registration page supports GLPI scoped inclusion and explicit access denial.');
    $agentConfig = file_get_contents(__DIR__ . '/../assetsync20agent/front/config.php');
    $check(str_contains($agentConfig, 'global $DB;'), 'B configuration page imports DB for GLPI legacy controller scoped inclusion.');
    echo "Agent HTTP/security tests passed ($checks checks; transport entirely mocked).\n";
}
