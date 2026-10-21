<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function hrtime(bool $asNumber = false): int { return \NativeTransport::$now; }
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { return \NativeTransport::configure($options); }
    function curl_exec(object $handle): bool { return \NativeTransport::execute(); }
    function curl_getinfo(object $handle, int $option): int { return \NativeTransport::$options['status']; }
    function curl_close(object $handle): void { \NativeTransport::$closed++; }
}

namespace GlpiPlugin\Assetsync20agent {
    class Settings {
        public static function load(): array { return \NativeTransport::$settings; }
        public static function transportReady(array $settings): bool { return ($settings['transport'] ?? 'adapter') === 'adapter' && !empty($settings['agent_api_url']); }
    }
    function hrtime(bool $asNumber = false): int { return \NativeTransport::$now; }
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { return \NativeTransport::configure($options); }
    function curl_exec(object $handle): bool { return \NativeTransport::execute(); }
    function curl_getinfo(object $handle, int $option): int { return \NativeTransport::$options['status']; }
    function curl_close(object $handle): void { \NativeTransport::$closed++; }
}

namespace {
    require __DIR__ . '/../src/AgentSession.php';
    require __DIR__ . '/../assetsync20agent/src/AgentSession.php';

    final class NativeTransport
    {
        public static int $now = 1_000_000_000;
        public static array $calls = [];
        public static array $options = [];
        public static array $queue = [];
        public static int $closed = 0;
        public static array $settings = [];
        public static function configure(array $options): bool
        {
            self::$options = $options;
            $headers = [];
            foreach ($options[CURLOPT_HTTPHEADER] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[$name] = $value;
            }
            self::$calls[] = ['headers' => $headers, 'payload' => json_decode($options[CURLOPT_POSTFIELDS], true), 'options' => $options];
            return true;
        }
        public static function execute(): bool
        {
            $operation = self::$calls[array_key_last(self::$calls)]['headers']['X-Assetsync-Operation'];
            $reply = array_shift(self::$queue) ?? ['status' => 200,
                'body' => $operation === 'initSession' ? ['session_token' => 'opaque-native-token'] : ['recorded' => true]];
            self::$options['status'] = $reply['status'];
            $body = is_string($reply['body']) ? $reply['body'] : json_encode($reply['body']);
            return (self::$options[CURLOPT_WRITEFUNCTION])(null, $body) === strlen($body);
        }
        public static function reset(): void { self::$calls = []; self::$queue = []; self::$closed = 0; self::$now = 1_000_000_000; }
    }
    $checks = 0;
    function nativeCheck(bool $ok, string $message): void
    {
        global $checks; $checks++;
        if (!$ok) { throw new RuntimeException($message); }
    }
    $a = file_get_contents(__DIR__ . '/../src/AgentSession.php');
    $b = file_get_contents(__DIR__ . '/../assetsync20agent/src/AgentSession.php');
    $fence = <<<'PHP'
    private static function current(array $settings): bool
    {
        $current = Settings::load();
        return Settings::transportReady($current) && self::fingerprint($settings) === self::fingerprint($current);
    }

PHP;
    nativeCheck(str_contains($b, $fence), 'B has its explicit fresh local transport fence.');
    $shared = str_replace([$fence . "\n", "        if (!self::current(\$settings)) { return ['status' => 0, 'body' => []]; }\n",
        "        if (!\$cleanup && !self::current(\$this->settings)) { return ['status' => 0, 'body' => []]; }\n"], '', $b);
    nativeCheck(str_replace('GlpiPlugin\\Assetsync20agent', 'GlpiPlugin\\Assetsync20', $shared) === $a, 'Client transport/lifecycle code has parity apart from B local policy admission.');
    foreach ([GlpiPlugin\Assetsync20\AgentSession::class, GlpiPlugin\Assetsync20agent\AgentSession::class] as $client) {
        NativeTransport::reset();
        $settings = ['agent_auth' => 'glpi_session', 'agent_api_url' => 'https://peer.invalid/plugins/assetsync20/front/agent-api.php',
            'agent_app_token' => 'native-app', 'agent_user_token' => 'native-user', 'connection_id' => 'peer',
            'installation' => 'installation', 'registration' => 'registration', 'generation' => 'generation'];
        NativeTransport::$settings = $settings;
        $deadline = NativeTransport::$now + 20_000_000_000;
        $client::begin($deadline);
        try {
            foreach ([['task', 'pull'], ['task', 'result'], ['task', 'recovery'], ['notify', 'health'], ['notify', 'notification']] as [$op, $kind]) {
                $payload = ['kind' => $kind, 'immutable' => 'task-body'];
                $result = $client::send($settings, $op, $payload, NativeTransport::$now + 5_000_000_000);
                nativeCheck($result['status'] === 200, 'Each protocol call succeeds.');
                nativeCheck(NativeTransport::$calls[array_key_last(NativeTransport::$calls)]['payload'] === $payload, 'Protocol payload preserved.');
            }
            NativeTransport::$now += 6_000_000_000;
            nativeCheck($client::send($settings, 'notify', ['kind' => 'health'], $deadline)['status'] === 200,
                'Elapsed task subdeadline does not shorten the worker session.');
            $calls = count(NativeTransport::$calls);
            nativeCheck($client::send(array_replace($settings, ['generation' => 'new']), 'task', [], $deadline)['status'] === 0
                && count(NativeTransport::$calls) === $calls, 'Binding change is rejected before network.');
            nativeCheck($client::send(array_replace($settings, ['agent_user_token' => 'rotated']), 'task', [], $deadline)['status'] === 0,
                'Credential changes cannot reuse the session.');
        } finally { $client::end(); }
        $ops = array_column(array_column(NativeTransport::$calls, 'headers'), 'X-Assetsync-Operation');
        nativeCheck($ops === ['initSession', 'task', 'task', 'task', 'notify', 'notify', 'notify', 'killSession'],
            'Six protocol calls use exactly two lifecycle calls.');
        nativeCheck(NativeTransport::$closed === 8, 'All HTTP handles close.');
        foreach (NativeTransport::$calls as $call) {
            $options = $call['options']; $headers = $call['headers'];
            nativeCheck($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2
                && !$options[CURLOPT_FOLLOWLOCATION] && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS,
                'Native sessions retain strict TLS and no redirects.');
            nativeCheck(!isset($headers['Cookie']) && !array_key_exists('session_token', $call['payload']), 'No cookies/body credentials.');
            nativeCheck($headers['X-Assetsync-Operation'] === 'initSession'
                ? ($headers['Authorization'] === 'user_token native-user' && !isset($headers['Session-Token']))
                : (isset($headers['Session-Token']) && !isset($headers['Authorization'])), 'Credentials are header-only.');
        }
        NativeTransport::reset();
        NativeTransport::$queue = [['status' => 401, 'body' => ['error' => 'invalid login']]];
        $client::begin($deadline);
        try {
            $result = $client::send($settings, 'task', ['kind' => 'result'], $deadline);
            nativeCheck($result['status'] === 401 && $result['auth_failed'], 'Core auth status is distinguished from protocol rejection.');
            $client::send($settings, 'notify', [], $deadline);
        } finally { $client::end(); }
        nativeCheck(count(NativeTransport::$calls) === 1, 'Failed login never dispatches or downgrades.');
        NativeTransport::reset();
        NativeTransport::$queue = [['status' => 200, 'body' => ['session_token' => 'opaque']],
            ['status' => 400, 'body' => ['ERROR_SESSION_TOKEN_INVALID', 'Session expired']]];
        $client::begin($deadline);
        try {
            $expired = $client::send($settings, 'task', ['kind' => 'result'], $deadline);
            nativeCheck($expired['status'] === 400 && !empty($expired['auth_failed']), 'Native core 400 session failure is authentication, not protocol rejection.');
            $client::send($settings, 'task', ['kind' => 'result'], $deadline);
        } finally { $client::end(); }
        nativeCheck(count(NativeTransport::$calls) === 3, 'Expired-session mutation is not replayed or reauthenticated within the cycle.');
        NativeTransport::reset();
        NativeTransport::$queue = [['status' => 200, 'body' => ['session_token' => 'opaque']], ['status' => 0, 'body' => '']];
        $client::begin($deadline);
        try { nativeCheck($client::send($settings, 'task', ['kind' => 'result'], $deadline)['status'] === 0, 'Ambiguous result is retained.'); }
        finally { $client::end(); }
        nativeCheck(count(NativeTransport::$calls) === 3, 'Ambiguous result is not replayed; cleanup still attempted.');
        NativeTransport::reset();
        $client::send($settings, 'wake', ['kind' => 'wake'], $deadline);
        nativeCheck(NativeTransport::$calls[1]['options'][CURLOPT_TIMEOUT_MS] === 10000, 'Wake retains the existing ten-second allowance.');
        nativeCheck(NativeTransport::$calls[2]['options'][CURLOPT_TIMEOUT_MS] <= 500, 'Cleanup remains bounded.');
        NativeTransport::reset();
        foreach (['http://peer.invalid/plugins/assetsync20/front/agent-api.php',
            'https://x@y.invalid/plugins/assetsync20/front/agent-api.php',
            'https://peer.invalid/plugins/assetsync20/front/agent-api.php?session_token=x'] as $url) {
            nativeCheck(!$client::validUrl($url), 'Unsafe native URL rejected.');
        }
        $client::send(array_replace($settings, ['agent_api_url' => 'http://unsafe.invalid']), 'task', [], $deadline);
        nativeCheck(NativeTransport::$calls === [], 'Invalid transport never opens a connection.');
    }
    echo "Native session tests passed ({$checks} checks; mocked HTTP only, lifecycle calls counted separately).\n";
}

