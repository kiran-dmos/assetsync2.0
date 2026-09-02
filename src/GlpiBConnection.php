<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class GlpiBConnection
{
    private const CONTEXT = 'plugin:assetsync20';
    private const CONNECTIONS_KEY = 'glpib_connections';

    private const OLD_KEYS = [
        'glpib_name'       => '',
        'glpib_base_url'   => '',
        'glpib_app_token'  => '',
        'glpib_user_token' => '',
        'glpib_active'     => '0',
    ];

    /**
     * @return array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}
     */
    public static function load(): array
    {
        $connections = self::loadAll();

        return $connections[0] ?? self::emptyConnection();
    }

    /**
     * @return list<array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}>
     */
    public static function loadAll(): array
    {
        $savedValues = self::getSavedValues([self::CONNECTIONS_KEY]);
        $connections = self::decodeConnections((string) ($savedValues[self::CONNECTIONS_KEY] ?? ''));

        if ($connections !== []) {
            return $connections;
        }

        $migrated = self::loadOldConnection();
        if ($migrated === null) {
            return [];
        }

        self::saveAll([$migrated]);

        return [$migrated];
    }

    /**
     * @param array{id?:string,name?:string,base_url?:string,app_token?:string,user_token?:string,active?:mixed} $input
     * @return array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}
     */
    public static function fromInput(array $input): array
    {
        $current = self::find((string) ($input['id'] ?? '')) ?? self::emptyConnection();

        $appToken = trim((string) ($input['app_token'] ?? ''));
        if ($appToken === '') {
            $appToken = $current['app_token'];
        }

        $userToken = trim((string) ($input['user_token'] ?? ''));
        if ($userToken === '') {
            $userToken = $current['user_token'];
        }

        return [
            'id'         => self::connectionId((string) ($input['id'] ?? '')),
            'name'       => trim((string) ($input['name'] ?? '')),
            'base_url'   => self::cleanBaseUrl((string) ($input['base_url'] ?? '')),
            'app_token'  => $appToken,
            'user_token' => $userToken,
            'active'     => !empty($input['active']),
        ];
    }

    /**
     * @param array{id?:string,name?:string,base_url?:string,app_token?:string,user_token?:string,active?:mixed} $input
     */
    public static function save(array $input): void
    {
        $connection = self::fromInput($input);
        $connections = self::loadAll();
        $saved = false;

        foreach ($connections as $index => $savedConnection) {
            if ($savedConnection['id'] === $connection['id']) {
                $connections[$index] = $connection;
                $saved = true;
                break;
            }
        }

        if (!$saved) {
            $connections[] = $connection;
        }

        self::saveAll($connections);
    }

    public static function delete(string $id): void
    {
        $id = self::connectionId($id);
        $connections = [];

        foreach (self::loadAll() as $connection) {
            if ($connection['id'] !== $id) {
                $connections[] = $connection;
            }
        }

        self::saveAll($connections);
    }

    /**
     * @return array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}|null
     */
    public static function find(string $id): ?array
    {
        $id = self::connectionId($id);

        foreach (self::loadAll() as $connection) {
            if ($connection['id'] === $id) {
                return $connection;
            }
        }

        return null;
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

        if (self::loadAll() === []) {
            self::saveAll([]);
        }
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::CONTEXT, array_merge(
                [self::CONNECTIONS_KEY],
                array_keys(self::OLD_KEYS)
            ));
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

    /**
     * @param array{name:string,base_url:string,app_token:string,user_token:string,active:bool} $connection
     * @return array{success:bool,message:string,fields:list<array{key:string,id:string,uid:string,label:string}>}
     */
    public static function fetchNativeFields(array $connection, string $itemtype): array
    {
        if ($connection['base_url'] === '' || $connection['app_token'] === '' || $connection['user_token'] === '') {
            return [
                'success' => false,
                'message' => 'Base URL, app token, and user token are required.',
                'fields'  => [],
            ];
        }

        $session = self::request('GET', self::apiUrl($connection['base_url'], 'initSession'), [
            'App-Token: ' . $connection['app_token'],
            'Authorization: user_token ' . $connection['user_token'],
        ]);

        if (!$session['success']) {
            return [
                'success' => false,
                'message' => $session['message'],
                'fields'  => [],
            ];
        }

        $sessionToken = (string) ($session['body']['session_token'] ?? '');
        if ($sessionToken === '') {
            return [
                'success' => false,
                'message' => 'GLPI B did not return a session token.',
                'fields'  => [],
            ];
        }

        $fieldsResponse = self::request(
            'GET',
            self::apiUrl($connection['base_url'], 'listSearchOptions/' . rawurlencode($itemtype)),
            [
                'App-Token: ' . $connection['app_token'],
                'Session-Token: ' . $sessionToken,
            ]
        );

        self::request('GET', self::apiUrl($connection['base_url'], 'killSession'), [
            'App-Token: ' . $connection['app_token'],
            'Session-Token: ' . $sessionToken,
        ]);

        if (!$fieldsResponse['success']) {
            return [
                'success' => false,
                'message' => $fieldsResponse['message'],
                'fields'  => [],
            ];
        }

        $fields = self::fieldsFromSearchOptions($fieldsResponse['body']);
        if ($fields === []) {
            return [
                'success' => false,
                'message' => 'GLPI B did not return any native fields for this asset type.',
                'fields'  => [],
            ];
        }

        return [
            'success' => true,
            'message' => 'GLPI B fields loaded.',
            'fields'  => $fields,
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
     * @param array<string,mixed> $options
     * @return list<array{key:string,id:string,uid:string,label:string}>
     */
    private static function fieldsFromSearchOptions(array $options): array
    {
        $fields = [];
        $seenKeys = [];

        foreach ($options as $optionId => $option) {
            if (!is_array($option) || !isset($option['name']) || !is_scalar($option['name'])) {
                continue;
            }

            $label = trim(strip_tags((string) $option['name']));
            if ($label === '') {
                continue;
            }

            $id = self::searchOptionId($optionId, $option);
            if ($id === '' && self::isHeaderOnlySearchOption($option)) {
                continue;
            }

            $uid = isset($option['uid']) && is_scalar($option['uid']) ? trim((string) $option['uid']) : '';
            $key = $uid !== '' ? $uid : $id;

            if ($key === '' || isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;
            $fields[] = [
                'key'   => $key,
                'id'    => $id,
                'uid'   => $uid,
                'label' => $label,
            ];
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $option
     */
    private static function isHeaderOnlySearchOption(array $option): bool
    {
        foreach (['field', 'table', 'datatype', 'uid'] as $fieldName) {
            if (isset($option[$fieldName]) && is_scalar($option[$fieldName]) && trim((string) $option[$fieldName]) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int|string $optionId
     * @param array<string,mixed> $option
     */
    private static function searchOptionId($optionId, array $option): string
    {
        if (isset($option['id']) && is_scalar($option['id']) && trim((string) $option['id']) !== '') {
            return trim((string) $option['id']);
        }

        if (is_int($optionId)) {
            return (string) $optionId;
        }

        $optionId = trim((string) $optionId);

        return ctype_digit($optionId) ? $optionId : '';
    }

    /**
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function getSavedValues(array $keys = []): array
    {
        if (class_exists('\Config') && method_exists('\Config', 'getConfigurationValues')) {
            $savedValues = \Config::getConfigurationValues(self::CONTEXT, $keys);

            if (is_array($savedValues)) {
                return $savedValues;
            }
        }

        return [];
    }

    /**
     * @return array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}
     */
    private static function emptyConnection(): array
    {
        return [
            'id'         => self::connectionId(''),
            'name'       => '',
            'base_url'   => '',
            'app_token'  => '',
            'user_token' => '',
            'active'     => false,
        ];
    }

    /**
     * @return array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}|null
     */
    private static function loadOldConnection(): ?array
    {
        $values = array_merge(self::OLD_KEYS, self::getSavedValues(array_keys(self::OLD_KEYS)));
        $hasOldValue = false;

        foreach ($values as $value) {
            if ((string) $value !== '' && (string) $value !== '0') {
                $hasOldValue = true;
                break;
            }
        }

        if (!$hasOldValue) {
            return null;
        }

        return [
            'id'         => self::connectionId(''),
            'name'       => (string) $values['glpib_name'],
            'base_url'   => self::cleanBaseUrl((string) $values['glpib_base_url']),
            'app_token'  => self::decryptToken((string) $values['glpib_app_token']),
            'user_token' => self::decryptToken((string) $values['glpib_user_token']),
            'active'     => (string) $values['glpib_active'] === '1',
        ];
    }

    /**
     * @param string $savedJson
     * @return list<array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}>
     */
    private static function decodeConnections(string $savedJson): array
    {
        if ($savedJson === '') {
            return [];
        }

        $savedConnections = json_decode($savedJson, true);
        if (!is_array($savedConnections)) {
            return [];
        }

        $connections = [];

        foreach ($savedConnections as $savedConnection) {
            if (!is_array($savedConnection)) {
                continue;
            }

            $connections[] = [
                'id'         => self::connectionId((string) ($savedConnection['id'] ?? '')),
                'name'       => (string) ($savedConnection['name'] ?? ''),
                'base_url'   => (string) ($savedConnection['base_url'] ?? ''),
                'app_token'  => self::decryptToken((string) ($savedConnection['app_token'] ?? '')),
                'user_token' => self::decryptToken((string) ($savedConnection['user_token'] ?? '')),
                'active'     => !empty($savedConnection['active']),
            ];
        }

        return $connections;
    }

    /**
     * @param list<array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}> $connections
     */
    private static function saveAll(array $connections): void
    {
        $savedConnections = [];

        foreach ($connections as $connection) {
            $savedConnections[] = [
                'id'         => self::connectionId($connection['id']),
                'name'       => $connection['name'],
                'base_url'   => self::cleanBaseUrl($connection['base_url']),
                'app_token'  => self::encryptToken($connection['app_token']),
                'user_token' => self::encryptToken($connection['user_token']),
                'active'     => $connection['active'],
            ];
        }

        if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
            \Config::setConfigurationValues(self::CONTEXT, [
                self::CONNECTIONS_KEY => json_encode($savedConnections, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private static function connectionId(string $id): string
    {
        $id = trim($id);

        if ($id !== '') {
            return $id;
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('glpib_', true));
        }
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
