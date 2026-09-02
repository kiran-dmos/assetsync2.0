<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class GlpiBConnection
{
    private const CONTEXT = 'plugin:assetsync20';

    private const DEFAULTS = [
        'glpib_name'       => '',
        'glpib_base_url'   => '',
        'glpib_app_token'  => '',
        'glpib_user_token' => '',
        'glpib_active'     => '0',
    ];

    /**
     * @return array{name:string,base_url:string,app_token:string,user_token:string,active:bool}
     */
    public static function load(): array
    {
        $values = self::DEFAULTS;

        if (class_exists('\Config') && method_exists('\Config', 'getConfigurationValues')) {
            $savedValues = \Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));

            if (is_array($savedValues)) {
                $values = array_merge($values, $savedValues);
            }
        }

        return [
            'name'       => (string) $values['glpib_name'],
            'base_url'   => (string) $values['glpib_base_url'],
            'app_token'  => self::decryptToken((string) $values['glpib_app_token']),
            'user_token' => self::decryptToken((string) $values['glpib_user_token']),
            'active'     => (string) $values['glpib_active'] === '1',
        ];
    }

    /**
     * @param array{name?:string,base_url?:string,app_token?:string,user_token?:string,active?:mixed} $input
     * @return array{name:string,base_url:string,app_token:string,user_token:string,active:bool}
     */
    public static function fromInput(array $input): array
    {
        $current = self::load();

        $appToken = trim((string) ($input['app_token'] ?? ''));
        if ($appToken === '') {
            $appToken = $current['app_token'];
        }

        $userToken = trim((string) ($input['user_token'] ?? ''));
        if ($userToken === '') {
            $userToken = $current['user_token'];
        }

        return [
            'name'       => trim((string) ($input['name'] ?? '')),
            'base_url'   => self::cleanBaseUrl((string) ($input['base_url'] ?? '')),
            'app_token'  => $appToken,
            'user_token' => $userToken,
            'active'     => !empty($input['active']),
        ];
    }

    /**
     * @param array{name?:string,base_url?:string,app_token?:string,user_token?:string,active?:mixed} $input
     */
    public static function save(array $input): void
    {
        $connection = self::fromInput($input);

        $values = [
            'glpib_name'       => $connection['name'],
            'glpib_base_url'   => $connection['base_url'],
            'glpib_app_token'  => self::encryptToken($connection['app_token']),
            'glpib_user_token' => self::encryptToken($connection['user_token']),
            'glpib_active'     => $connection['active'] ? '1' : '0',
        ];

        if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
            \Config::setConfigurationValues(self::CONTEXT, $values);
        }
    }

    public static function install(): void
    {
        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'getConfigurationValues')
            || !method_exists('\Config', 'setConfigurationValues')
        ) {
            return;
        }

        $savedValues = \Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
        $missingValues = [];

        foreach (self::DEFAULTS as $key => $value) {
            if (!array_key_exists($key, $savedValues)) {
                $missingValues[$key] = $value;
            }
        }

        if ($missingValues !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missingValues);
        }
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
        }
    }

    /**
     * @param array{name:string,base_url:string,app_token:string,user_token:string,active:bool} $connection
     * @return array{success:bool,message:string}
     */
    public static function test(array $connection): array
    {
        if ($connection['base_url'] === '' || $connection['app_token'] === '' || $connection['user_token'] === '') {
            return [
                'success' => false,
                'message' => 'Base URL, app token, and user token are required.',
            ];
        }

        $session = self::request('GET', self::apiUrl($connection['base_url'], 'initSession'), [
            'App-Token: ' . $connection['app_token'],
            'Authorization: user_token ' . $connection['user_token'],
        ]);

        if (!$session['success']) {
            return $session;
        }

        $sessionToken = (string) ($session['body']['session_token'] ?? '');
        if ($sessionToken === '') {
            return [
                'success' => false,
                'message' => 'GLPI B did not return a session token.',
            ];
        }

        $fullSession = self::request('GET', self::apiUrl($connection['base_url'], 'getFullSession'), [
            'App-Token: ' . $connection['app_token'],
            'Session-Token: ' . $sessionToken,
        ]);

        self::request('GET', self::apiUrl($connection['base_url'], 'killSession'), [
            'App-Token: ' . $connection['app_token'],
            'Session-Token: ' . $sessionToken,
        ]);

        if (!$fullSession['success']) {
            return $fullSession;
        }

        return [
            'success' => true,
            'message' => 'Connection to GLPI B succeeded.',
        ];
    }

    private static function cleanBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $apiPath = '/apirest.php';

        if (substr($baseUrl, -strlen($apiPath)) === $apiPath) {
            $baseUrl = substr($baseUrl, 0, -strlen($apiPath));
        }

        return rtrim($baseUrl, '/');
    }

    private static function apiUrl(string $baseUrl, string $endpoint): string
    {
        return self::cleanBaseUrl($baseUrl) . '/apirest.php/' . $endpoint;
    }

    /**
     * @param list<string> $headers
     * @return array{success:bool,message:string,body:array<string,mixed>}
     */
    private static function request(string $method, string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'The PHP cURL extension is required to test the connection.',
                'body'    => [],
            ];
        }

        $curl = curl_init($url);

        if ($curl === false) {
            return [
                'success' => false,
                'message' => 'Unable to initialize the HTTP request.',
                'body'    => [],
            ];
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Accept: application/json']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $rawBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($rawBody === false) {
            return [
                'success' => false,
                'message' => 'HTTP request failed: ' . $error,
                'body'    => [],
            ];
        }

        $body = json_decode((string) $rawBody, true);
        if (!is_array($body)) {
            $body = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success' => false,
                'message' => 'GLPI B returned HTTP ' . $statusCode . '.',
                'body'    => $body,
            ];
        }

        return [
            'success' => true,
            'message' => 'Request succeeded.',
            'body'    => $body,
        ];
    }

    private static function encryptToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        if (class_exists('\GLPIKey')) {
            try {
                $key = new \GLPIKey();

                if (method_exists($key, 'encrypt')) {
                    return (string) $key->encrypt($token);
                }
            } catch (\Throwable) {
                return $token;
            }
        }

        return $token;
    }

    private static function decryptToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        if (class_exists('\GLPIKey')) {
            try {
                $key = new \GLPIKey();

                if (method_exists($key, 'decrypt')) {
                    $decrypted = $key->decrypt($token);

                    if (is_string($decrypted) && $decrypted !== '') {
                        return $decrypted;
                    }
                }
            } catch (\Throwable) {
                return $token;
            }
        }

        return $token;
    }
}
