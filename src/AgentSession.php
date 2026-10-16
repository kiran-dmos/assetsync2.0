<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** One in-memory native API session per bounded worker turn. */
final class AgentSession
{
    private static ?self $cycle = null;
    private string $token = '';
    private bool $failed = false;
    private int $operationDeadline;

    public function __construct(private array $settings, private int $deadline)
    {
        $this->operationDeadline = $deadline;
    }

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('#/(plugins|marketplace)/assetsync20(?:agent)?/front/agent-api\.php$#D', $parts['path'] ?? '') === 1
            && !preg_match('/[\x00-\x20\x7f]/', $url);
    }

    public static function configuration(array $input, array $old): array
    {
        $settings = ['agent_auth' => 'glpi_session'];
        foreach (['agent_api_url', 'agent_app_token', 'agent_user_token',
            'agent_peer_user', 'agent_peer_api_client', 'agent_peer_profile', 'agent_peer_entity'] as $key) {
            $value = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            if (in_array($key, ['agent_app_token', 'agent_user_token'], true) && $value === '') {
                $value = (string) ($old[$key] ?? '');
            }
            $settings[$key] = $value;
        }
        if ($settings['agent_api_url'] !== '') {
            if (!self::validUrl($settings['agent_api_url'])) {
                throw new \InvalidArgumentException('Enter a direct HTTPS Agent API URL.');
            }
            foreach (['agent_app_token', 'agent_user_token'] as $key) {
                if ($settings[$key] === '' || strlen($settings[$key]) > 1024 || preg_match('/[\x00-\x20\x7f]/', $settings[$key])) {
                    throw new \InvalidArgumentException('Native app and user tokens are required.');
                }
            }
            try { AgentApi::validatePeer($settings); }
            catch (\UnexpectedValueException $error) { throw new \InvalidArgumentException($error->getMessage(), 0, $error); }
        }
        return $settings;
    }

    public static function begin(int $deadline): void
    {
        if (self::$cycle !== null) { throw new \LogicException('Agent API cycle already active.'); }
        self::$cycle = new self([], $deadline);
    }

    public static function end(): void
    {
        try { self::$cycle?->close(); } finally { self::$cycle = null; }
    }

    public static function send(array $settings, string $operation, array $payload, int $deadline): array
    {
        if (self::$cycle === null) {
            $client = new self($settings, $deadline);
            try { return $client->request($operation, $payload); } finally { $client->close(); }
        }
        $client = self::$cycle;
        if ($client->settings === []) { $client->settings = $settings; }
        if (self::fingerprint($client->settings) !== self::fingerprint($settings)) {
            return ['status' => 0, 'body' => []];
        }
        $client->operationDeadline = min($client->deadline, $deadline);
        return $client->request($operation, $payload);
    }

    public static function fingerprint(array $settings): string
    {
        $values = [];
        foreach (['agent_auth', 'agent_api_url', 'agent_app_token', 'agent_user_token',
            'agent_peer_user', 'agent_peer_api_client', 'agent_peer_profile', 'agent_peer_entity',
            'id', 'connection_id', 'installation', 'registration', 'generation'] as $key) {
            $values[$key] = (string) ($settings[$key] ?? '');
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function request(string $operation, array $payload): array
    {
        if ($this->failed || ($this->settings['agent_auth'] ?? '') !== 'glpi_session'
            || !self::validUrl($this->settings['agent_api_url'] ?? '')
            || empty($this->settings['agent_app_token']) || empty($this->settings['agent_user_token'])
            || hrtime(true) + 600_000_000 >= $this->operationDeadline) { return ['status' => 0, 'body' => []]; }
        if ($this->token === '') {
            $login = $this->http('initSession', [], false);
            $token = $login['body']['session_token'] ?? '';
            if ($login['status'] !== 200 || !is_string($token) || $token === '' || strlen($token) > 1024
                || preg_match('/[\x00-\x20\x7f]/', $token)) {
                $this->failed = true;
                return ['status' => $login['status'], 'body' => [], 'auth_failed' => true];
            }
            $this->token = $token;
        }
        $response = $this->http($operation, $payload, false);
        $nativeSessionError = $response['status'] === 400 && array_is_list($response['body'])
            && count($response['body']) === 2 && is_string($response['body'][1])
            && in_array($response['body'][0], ['ERROR_SESSION_TOKEN_INVALID', 'ERROR_SESSION_TOKEN_MISSING'], true);
        if (in_array($response['status'], [401, 403], true) || $nativeSessionError) {
            $this->failed = true;
            $response['auth_failed'] = true;
        }
        // A failed or ambiguous mutation is never automatically replayed.
        return $response;
    }

    private function close(): void
    {
        if ($this->token === '') { return; }
        try { $this->http('killSession', [], true); } finally { $this->token = ''; }
    }

    private function http(string $operation, array $payload, bool $cleanup): array
    {
        $remaining = (int) ((($cleanup ? $this->deadline : $this->operationDeadline) - hrtime(true)) / 1_000_000) - ($cleanup ? 0 : 500);
        if ($remaining <= 0) { return ['status' => 0, 'body' => []]; }
        $body = json_encode((object) $payload, JSON_THROW_ON_ERROR);
        if (strlen($body) > 65536) { throw new \RuntimeException('Agent API body exceeds limit.'); }
        $headers = ['Content-Type: application/json', 'Accept: application/json',
            'App-Token: ' . $this->settings['agent_app_token'], 'X-Assetsync-Operation: ' . $operation,
            'X-Assetsync-Connection: ' . ($this->settings['connection_id'] ?? $this->settings['id'] ?? '')];
        $headers[] = $this->token === '' ? 'Authorization: user_token ' . $this->settings['agent_user_token']
            : 'Session-Token: ' . $this->token;
        foreach ($headers as $header) {
            if (preg_match('/[\r\n\x00]/', $header)) { throw new \RuntimeException('Invalid Agent API header.'); }
        }
        $curl = curl_init($this->settings['agent_api_url']);
        $response = '';
        $limit = in_array($operation, ['initSession', 'killSession', 'wake', 'notify'], true) ? 2048 : 65536;
        $timeout = min($cleanup ? 500 : ($operation === 'wake' ? 10000 : 5000), $remaining);
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $limit): int {
                    if (strlen($response) + strlen($chunk) > $limit) { return 0; }
                    $response .= $chunk;
                    return strlen($chunk);
                }]);
            $ok = curl_exec($curl);
            $decoded = json_decode($response, true, 32);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'body' => is_array($decoded) ? $decoded : []];
        } finally { curl_close($curl); }
    }
}

