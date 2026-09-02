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

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @return array{success:bool,message:string,items:list<array{id:int>>,total_count:int,transient:bool}
     */
    public static function searchBySerial(array $connection, string $itemtype, string $serial): array
    {
        $serial = trim($serial);
        if ($serial === '') {
            return [
                'success'     => false,
                'message'     => 'Serial is required before searching GLPI B.',
                'items'       => [],
                'total_count' => 0,
                'transient'   => false,
            ];
        }

        return self::withSession($connection, static function (string $sessionToken) use ($connection, $itemtype, $serial): array {
            $optionsResponse = self::request(
                'GET',
                self::apiUrl($connection['base_url'], 'listSearchOptions/' . rawurlencode($itemtype)),
                [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ]
            );

            if (!$optionsResponse['success']) {
                return self::searchFailure($optionsResponse['message'], $optionsResponse['transient']);
            }

            $serialOptionId = self::searchOptionIdForNativeField($optionsResponse['body'], 'serial');
            if ($serialOptionId === '') {
                return self::searchFailure('GLPI B did not expose a serial search option for ' . $itemtype . '.', false);
            }

            $idOptionId = self::searchOptionIdForNativeField($optionsResponse['body'], 'id');
            $query = [
                'criteria' => [
                    [
                        'field'      => $serialOptionId,
                        'searchtype' => 'equals',
                        'value'      => $serial,
                    ],
                ],
                'range' => '0-2',
            ];

            if ($idOptionId !== '') {
                $query['forcedisplay'] = [$idOptionId];
            }

            $searchResponse = self::request(
                'GET',
                self::apiUrlWithQuery($connection['base_url'], 'search/' . rawurlencode($itemtype), $query),
                [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ]
            );

            if (!$searchResponse['success']) {
                return self::searchFailure($searchResponse['message'], $searchResponse['transient']);
            }

            $totalCount = self::searchTotalCount($searchResponse['body']);
            $items = self::itemsFromSearchResponse($searchResponse['body'], $idOptionId);

            if ($totalCount === 1 && $items === []) {
                return self::searchFailure('GLPI B search did not include a usable asset id.', false);
            }

            return [
                'success'     => true,
                'message'     => 'GLPI B serial search succeeded.',
                'items'       => $items,
                'total_count' => $totalCount,
                'transient'   => false,
            ];
        });
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @return array{success:bool,message:string,item:array<string,mixed>,missing:bool,transient:bool}
     */
    public static function getItem(array $connection, string $itemtype, int $itemsId): array
    {
        if ($itemsId <= 0) {
            return [
                'success'   => false,
                'message'   => 'A GLPI B asset id is required.',
                'item'      => [],
                'missing'   => false,
                'transient' => false,
            ];
        }

        return self::withSession($connection, static function (string $sessionToken) use ($connection, $itemtype, $itemsId): array {
            $response = self::request(
                'GET',
                self::apiUrl($connection['base_url'], rawurlencode($itemtype) . '/' . $itemsId),
                [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ]
            );

            if (!$response['success']) {
                return [
                    'success'   => false,
                    'message'   => $response['message'],
                    'item'      => [],
                    'missing'   => $response['status_code'] === 404,
                    'transient' => $response['transient'],
                ];
            }

            return [
                'success'   => true,
                'message'   => 'GLPI B asset loaded.',
                'item'      => $response['body'],
                'missing'   => false,
                'transient' => false,
            ];
        });
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param array<string,mixed> $input
     * @return array{success:bool,message:string,id:int,transient:bool}
     */
    public static function createItem(array $connection, string $itemtype, array $input): array
    {
        return self::withSession($connection, static function (string $sessionToken) use ($connection, $itemtype, $input): array {
            $response = self::request(
                'POST',
                self::apiUrl($connection['base_url'], rawurlencode($itemtype)),
                [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ],
                ['input' => $input]
            );

            if (!$response['success']) {
                return [
                    'success'   => false,
                    'message'   => $response['message'],
                    'id'        => 0,
                    'transient' => $response['transient'],
                ];
            }

            return [
                'success'   => true,
                'message'   => 'GLPI B asset created.',
                'id'        => self::createdItemId($response['body']),
                'transient' => false,
            ];
        });
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param array<string,mixed> $input
     * @return array{success:bool,message:string,transient:bool}
     */
    public static function updateItem(array $connection, string $itemtype, int $itemsId, array $input): array
    {
        if ($itemsId <= 0) {
            return [
                'success'   => false,
                'message'   => 'A GLPI B asset id is required.',
                'transient' => false,
            ];
        }

        if ($input === []) {
            return [
                'success'   => true,
                'message'   => 'No GLPI B fields needed an update.',
                'transient' => false,
            ];
        }

        return self::withSession($connection, static function (string $sessionToken) use ($connection, $itemtype, $itemsId, $input): array {
            $response = self::request(
                'PUT',
                self::apiUrl($connection['base_url'], rawurlencode($itemtype) . '/' . $itemsId),
                [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ],
                ['input' => $input]
            );

            if (!$response['success']) {
                return [
                    'success'   => false,
                    'message'   => $response['message'],
                    'transient' => $response['transient'],
                ];
            }

            return [
                'success'   => true,
                'message'   => 'GLPI B asset updated.',
                'transient' => false,
            ];
        });
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
     * @param array<string,mixed> $query
     */
    private static function apiUrlWithQuery(string $baseUrl, string $endpoint, array $query): string
    {
        return self::apiUrl($baseUrl, $endpoint) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param callable(string):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private static function withSession(array $connection, callable $callback): array
    {
        if ($connection['base_url'] === '' || $connection['app_token'] === '' || $connection['user_token'] === '') {
            return [
                'success'   => false,
                'message'   => 'Base URL, app token, and user token are required.',
                'transient' => false,
            ];
        }

        $session = self::request('GET', self::apiUrl($connection['base_url'], 'initSession'), [
            'App-Token: ' . $connection['app_token'],
            'Authorization: user_token ' . $connection['user_token'],
        ]);

        if (!$session['success']) {
            return [
                'success'   => false,
                'message'   => $session['message'],
                'transient' => $session['transient'],
            ];
        }

        $sessionToken = (string) ($session['body']['session_token'] ?? '');
        if ($sessionToken === '') {
            return [
                'success'   => false,
                'message'   => 'GLPI B did not return a session token.',
                'transient' => false,
            ];
        }

        try {
            return $callback($sessionToken);
        } finally {
            self::request('GET', self::apiUrl($connection['base_url'], 'killSession'), [
                'App-Token: ' . $connection['app_token'],
                'Session-Token: ' . $sessionToken,
            ]);
        }
    }

    /**
     * @return array{success:bool,message:string,items:list<array{id:int>>,total_count:int,transient:bool}
     */
    private static function searchFailure(string $message, bool $transient): array
    {
        return [
            'success'     => false,
            'message'     => $message,
            'items'       => [],
            'total_count' => 0,
            'transient'   => $transient,
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function searchOptionIdForNativeField(array $options, string $fieldName): string
    {
        foreach ($options as $optionId => $option) {
            if (!is_array($option)) {
                continue;
            }

            $field = isset($option['field']) && is_scalar($option['field']) ? trim((string) $option['field']) : '';
            $uid = isset($option['uid']) && is_scalar($option['uid']) ? trim((string) $option['uid']) : '';

            if ($field !== $fieldName && !str_ends_with($uid, '.' . $fieldName)) {
                continue;
            }

            $id = self::searchOptionId($optionId, $option);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function searchTotalCount(array $body): int
    {
        if (isset($body['totalcount']) && is_scalar($body['totalcount'])) {
            return (int) $body['totalcount'];
        }

        return isset($body['data']) && is_array($body['data']) ? count($body['data']) : 0;
    }

    /**
     * @param array<string,mixed> $body
     * @return list<array{id:int}>
     */
    private static function itemsFromSearchResponse(array $body, string $idOptionId): array
    {
        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = self::searchRowId($row, $idOptionId);
            if ($id > 0) {
                $items[] = ['id' => $id];
            }
        }

        return $items;
    }

    /**
     * @param array<string|int,mixed> $row
     */
    private static function searchRowId(array $row, string $idOptionId): int
    {
        $candidateKeys = ['id'];
        if ($idOptionId !== '') {
            $candidateKeys[] = $idOptionId;
        }
        $candidateKeys[] = '2';

        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $row)) {
                $id = self::cleanRemoteId($row[$key]);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return 0;
    }

    private static function cleanRemoteId($value): int
    {
        if (is_array($value) && isset($value['id'])) {
            $value = $value['id'];
        }

        if (!is_scalar($value)) {
            return 0;
        }

        $value = trim(strip_tags((string) $value));

        return ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function createdItemId(array $body): int
    {
        return self::cleanRemoteId($body['id'] ?? 0);
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed>|null $payload
     * @return array{success:bool,message:string,body:array<string,mixed>,status_code:int,transient:bool}
     */
    private static function request(string $method, string $url, array $headers, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success'     => false,
                'message'     => 'The PHP cURL extension is required to test the connection.',
                'body'        => [],
                'status_code' => 0,
                'transient'   => false,
            ];
        }

        $curl = curl_init($url);

        if ($curl === false) {
            return [
                'success'     => false,
                'message'     => 'Unable to initialize the HTTP request.',
                'body'        => [],
                'status_code' => 0,
                'transient'   => true,
            ];
        }

        $requestHeaders = array_merge($headers, ['Accept: application/json']);
        if ($payload !== null) {
            $requestHeaders[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $rawBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($rawBody === false) {
            return [
                'success'     => false,
                'message'     => 'HTTP request failed: ' . $error,
                'body'        => [],
                'status_code' => $statusCode,
                'transient'   => true,
            ];
        }

        $body = json_decode((string) $rawBody, true);
        if (!is_array($body)) {
            $body = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success'     => false,
                'message'     => 'GLPI B returned HTTP ' . $statusCode . '.',
                'body'        => $body,
                'status_code' => $statusCode,
                'transient'   => self::isTransientStatusCode($statusCode),
            ];
        }

        return [
            'success'     => true,
            'message'     => 'Request succeeded.',
            'body'        => $body,
            'status_code' => $statusCode,
            'transient'   => false,
        ];
    }

    private static function isTransientStatusCode(int $statusCode): bool
    {
        return $statusCode === 408 || $statusCode === 429 || $statusCode >= 500 || $statusCode === 0;
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
