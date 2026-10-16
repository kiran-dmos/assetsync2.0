<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Stock REST lifecycle with the existing native credentials, bounded to one A dispatch. */
final class RestSession
{
    private string $token = '';
    public function __construct(private array $connection, private int $deadline) {}

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/apirest.php') && !preg_match('/[\x00-\x20\x7f]/', $url);
    }

    public function request(string $method, string $resource, ?array $input = null): array
    {
        if ($this->token === '') {
            $login = $this->http('GET', 'initSession', null);
            $token = $login['body']['session_token'] ?? '';
            if ($login['status'] !== 200 || !is_string($token) || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
                return ['status' => $login['status'], 'body' => []];
            }
            $this->token = $token;
        }
        return $this->http($method, $resource, $input === null ? null : ['session_write' => false, 'input' => $input]);
    }

    public function close(): void
    {
        if ($this->token === '') { return; }
        try { $this->http('GET', 'killSession', null, true); } finally { $this->token = ''; }
    }

    private function http(string $method, string $path, ?array $body, bool $cleanup = false): array
    {
        if (!self::validUrl($this->connection['agent_rest_url'] ?? '')) { return ['status' => 0, 'body' => []]; }
        $remaining = (int) (($this->deadline - hrtime(true)) / 1_000_000) - ($cleanup ? 0 : 500);
        if ($remaining <= 0) { return ['status' => 0, 'body' => []]; }
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'App-Token: ' . $this->connection['agent_app_token']];
        $headers[] = $this->token === '' ? 'Authorization: user_token ' . $this->connection['agent_user_token'] : 'Session-Token: ' . $this->token;
        foreach ($headers as $header) { if (preg_match('/[\r\n\x00]/', $header)) { throw new \RuntimeException('Invalid REST header.'); } }
        $curl = curl_init($this->connection['agent_rest_url'] . '/' . $path);
        $response = '';
        try {
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $remaining), CURLOPT_TIMEOUT_MS => min($cleanup ? 500 : 7000, $remaining),
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 65536) { return 0; }
                    $response .= $chunk; return strlen($chunk);
                }]);
            if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
            $ok = curl_exec($curl);
            $decoded = json_decode($response, true, 32);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'body' => is_array($decoded) ? $decoded : []];
        } finally { curl_close($curl); }
    }
}
