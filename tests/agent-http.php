<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    final class AgentTransport
    {
        public static array $options = [];
        public static int $calls = 0;
        public static int $closed = 0;
        public static int $protocolCalls = 0;
        public static int $status = 200;
        public static string $body = '{}';
        public static array $responses = [];
        public static array $payloads = [];
    }
    function curl_init(string $url): object { AgentTransport::$calls++; return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool {
        AgentTransport::$options = $options;
        AgentTransport::$payloads[] = json_decode($options[CURLOPT_POSTFIELDS], true);
        return true;
    }
    function curl_exec(object $handle): bool {
        $headers = AgentTransport::$options[CURLOPT_HTTPHEADER];
        $lifecycle = in_array('X-Assetsync-Operation: initSession', $headers, true) ? 'init'
            : (in_array('X-Assetsync-Operation: killSession', $headers, true) ? 'kill' : '');
        if ($lifecycle !== '') {
            $body = $lifecycle === 'init' ? '{"session_token":"native-test-session"}' : '{}';
            $handle->status = 200;
            return (AgentTransport::$options[CURLOPT_WRITEFUNCTION])($handle, $body) === strlen($body);
        }
        AgentTransport::$protocolCalls++;
        if (AgentTransport::$responses !== []) {
            $response = array_shift(AgentTransport::$responses);
            AgentTransport::$status = $response['status'];
            AgentTransport::$body = json_encode($response['body']);
        }
        return (AgentTransport::$options[CURLOPT_WRITEFUNCTION])($handle, AgentTransport::$body) === strlen(AgentTransport::$body);
    }
    function curl_getinfo(object $handle, int $option): int { return $handle->status ?? AgentTransport::$status; }
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
    $smokeBootstrapOnly = true;
    require_once __DIR__ . '/smoke.php';
    require __DIR__ . '/../assetsync20agent/src/autoload.php';
    require __DIR__ . '/../assetsync20agent/setup.php';
    use GlpiPlugin\Assetsync20agent\AgentTransport;
    use GlpiPlugin\Assetsync20agent\Settings;
    use GlpiPlugin\Assetsync20agent\Worker;

    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        $checks++;
        if (!$ok) { throw new RuntimeException($message); }
    };
    $settings = ['mode' => 'agent', 'agent_auth' => 'glpi_session', 'agent_api_url' => 'https://controller.invalid/plugins/assetsync20/front/agent-api.php',
        'agent_app_token' => 'test-app', 'agent_user_token' => 'test-user'];
    function httpSettings(array $settings): void {
        foreach (['agent_app_token', 'agent_user_token'] as $key) { $settings[$key] = (new GLPIKey())->encrypt($settings[$key] ?? ''); }
        Config::setConfigurationValues(Settings::CONTEXT, $settings);
    }
    httpSettings($settings);
    \GlpiPlugin\Assetsync20agent\AgentSession::begin(hrtime(true) + 30_000_000_000);
    $check(plugin_version_assetsync20agent()['version'] === '0.8.5', 'B advertises the stock REST records package version.');
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
    foreach (['http://controller.invalid/front/agent-api.php', 'https://u:p@controller.invalid/front/agent-api.php',
        'https://controller.invalid/front/agent-api.php?token=bad', 'https://controller.invalid/front/agent-api.php#bad'] as $url) {
        $check(!\GlpiPlugin\Assetsync20agent\AgentSession::validUrl($url), 'Reject unsafe endpoint: ' . $url);
    }
    $calls = AgentTransport::$calls;
    Worker::send(array_replace($settings, ['agent_api_url' => 'http://controller.invalid/front/agent-api.php']), $sent, hrtime(true) + 1_000_000_000);
    $check(AgentTransport::$calls === $calls, 'HTTP endpoint rejection happens before transport.');
    AgentTransport::$body = str_repeat('x', 2049);
    $result = Worker::send($settings, $sent, hrtime(true) + 1_000_000_000);
    $check($result['status'] === 0 && $result['body'] === [] && AgentTransport::$closed === AgentTransport::$calls, 'Oversized ACK aborts and closes transport.');
    $hook = file_get_contents(__DIR__ . '/../assetsync20agent/src/ChangeHook.php');
    $check(!str_contains($hook, 'Worker::') && !str_contains($hook, 'curl_') && !str_contains($hook, 'http'), 'Save hooks have no HTTP/worker path.');
    require_once __DIR__ . '/../setup.php';
    plugin_assetsync20_boot();
    plugin_init_assetsync20();
    foreach (array_merge(\Glpi\Http\SessionManager::$patterns, \Glpi\Http\Firewall::$patterns) as $pattern) {
        $check(preg_match($pattern, '/front/agent-api.php') === 1
            && preg_match($pattern, '/front/agent-notify.php') === 0 && preg_match($pattern, '/front/agent-task.php') === 0
            && preg_match($pattern, '/front/agent-wake.php') === 0 && preg_match($pattern, '/front/agents.php') === 0,
            'Only the exact native API machine endpoint is exempt.');
        $check(preg_match($pattern, '/front/agent-task.php/other') === 0 && preg_match($pattern, '/front/agent-task.phpx') === 0
            && preg_match($pattern, '/front/agent-api.php/other') === 0 && preg_match($pattern, '/front/agent-api.phpx') === 0
            && preg_match($pattern, '/front/agent-pair.php') === 0, 'Task endpoint exemption excludes suffixes and protected pairing.');
    }
    $endpoint = file_get_contents(__DIR__ . '/../src/AgentApi.php');
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
    $settings += ['registration' => str_repeat('b', 32), 'generation' => str_repeat('c', 32),
        'installation' => str_repeat('d', 32), 'connection_id' => 'b'];
    httpSettings($settings);
    \GlpiPlugin\Assetsync20agent\AgentSession::end();
    \GlpiPlugin\Assetsync20agent\AgentSession::begin(hrtime(true) + 30_000_000_000);
    AgentTransport::$body = '{"task":null}';
    \GlpiPlugin\Assetsync20agent\TaskJournal::send($settings, ['kind' => 'pull'], hrtime(true) + 1_000_000_000);
    $options = AgentTransport::$options;
    $check($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2
        && !$options[CURLOPT_FOLLOWLOCATION] && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Task transport also requires strict HTTPS with no redirect.');
    $check(json_decode($options[CURLOPT_POSTFIELDS], true) === ['kind' => 'pull', 'task_versions' => [1, 2, 3, 4, 5, 6], 'recovery_version' => 1] + array_intersect_key($settings,
        array_flip(['registration', 'generation', 'installation', 'connection_id'])), 'Task request includes only capabilities and complete binding, not service credentials.');
    AgentTransport::$responses = [['status' => 400, 'body' => ['error' => 'Invalid task request']], ['status' => 200, 'body' => ['task' => null]]];
    $calls = AgentTransport::$calls;
    $response = \GlpiPlugin\Assetsync20agent\TaskJournal::send($settings, ['kind' => 'pull'], hrtime(true) + 1_000_000_000);
    $check($response['status'] === 400 && AgentTransport::$calls === $calls + 1, 'Rejected pulls are never retried or downgraded.');
    AgentTransport::$responses = [['status' => 400, 'body' => []]];
    $calls = AgentTransport::$calls;
    \GlpiPlugin\Assetsync20agent\TaskJournal::send($settings, ['kind' => 'result', 'id' => 'retained'], hrtime(true) + 1_000_000_000);
    $check(AgentTransport::$calls === $calls + 1, 'Capability compatibility never retries or downgrades a result/write.');
    AgentTransport::$status = 200;
    AgentTransport::$body = str_repeat('x', 65537);
    $check(\GlpiPlugin\Assetsync20agent\TaskJournal::send($settings, ['kind' => 'pull'], hrtime(true) + 1_000_000_000)['status'] === 0,
        'Task response is capped at 64 KiB.');
    $calls = AgentTransport::$calls;
    try {
        \GlpiPlugin\Assetsync20agent\TaskJournal::send($settings, ['kind' => 'result', 'result' => str_repeat('x', 65536)], hrtime(true) + 1_000_000_000);
        throw new LogicException('Oversized result accepted');
    } catch (RuntimeException) { $check(AgentTransport::$calls === $calls, 'Oversized task result is rejected before HTTP.'); }
    \GlpiPlugin\Assetsync20agent\AgentSession::end();
    echo "Agent HTTP/security tests passed ($checks checks; transport entirely mocked).\n";
}
