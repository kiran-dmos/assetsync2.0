<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class GlpiBConnection
{
    public const HTTP_CLEANUP_RESERVE_NS = 1_000_000_000;
    private const CONTEXT = 'plugin:assetsync20';
    private const CONNECTIONS_KEY = 'glpib_connections';
    private const CUSTOM_HISTORY_RANGE = '0-20';
    private const DATE_MOD_TIMEZONE_KEY = 'date_mod_timezone';
    private static ?array $httpMetrics = null;
    private static ?string $httpMetricsConnection = null;
    private static ?array $runCache = null;
    private static ?int $httpDeadlineNs = null;

    public static function setHttpDeadline(?int $deadlineNs): ?int
    {
        $previous = self::$httpDeadlineNs;
        self::$httpDeadlineNs = $deadlineNs;
        return $previous;
    }

    public static function beginRunCache(): void
    {
        self::$runCache = [];
    }

    public static function endRunCache(): void
    {
        self::$runCache = null;
    }

    private static function invalidateRunCache(): void
    {
        if (self::$runCache !== null) {
            self::$runCache = [];
        }
    }

    public static function beginHttpMetrics(): void
    {
        self::$httpMetrics = [];
        self::$httpMetricsConnection = null;
    }

    public static function setHttpMetricsConnection(?string $connectionId): ?string
    {
        $previous = self::$httpMetricsConnection;
        if (self::$httpMetrics !== null) {
            self::$httpMetricsConnection = $connectionId;
        }
        return $previous;
    }

    public static function finishHttpMetrics(): array
    {
        $metrics = self::$httpMetrics ?? [];
        self::$httpMetrics = null;
        self::$httpMetricsConnection = null;
        foreach ($metrics as &$connection) {
            $connection['latency_ms'] = round($connection['latency_ms'], 3);
        }
        unset($connection);
        return $metrics;
    }

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
        $connections = self::decodeConnections();

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
        self::invalidateRunCache();
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
        self::invalidateRunCache();
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
        self::invalidateRunCache();
        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'getConfigurationValues')
            || !method_exists('\Config', 'setConfigurationValues')
        ) {
            return;
        }

        try {
            if (self::loadAll() === []) {
                self::saveAll([]);
            }
        } finally {
            self::invalidateRunCache();
        }
    }

    public static function uninstall(): void
    {
        self::invalidateRunCache();
        try {
            if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
                \Config::deleteConfigurationValues(self::CONTEXT, array_merge(
                    [self::CONNECTIONS_KEY],
                    array_keys(self::OLD_KEYS)
                ));
            }
        } finally {
            self::invalidateRunCache();
        }
    }

    /**
     * @param array{name:string,base_url:string,app_token:string,user_token:string,active:bool} $connection
     * @return array{success:bool,message:string}
     */
    public static function test(array $connection): array
    {
        return self::withSession($connection, static function (string $sessionToken) use ($connection): array {
            $fullSession = self::request('GET', self::apiUrl($connection['base_url'], 'getFullSession'), [
                'App-Token: ' . $connection['app_token'],
                'Session-Token: ' . $sessionToken,
            ]);
            if (!$fullSession['success']) {
                return $fullSession;
            }
            return ['success' => true, 'message' => 'Connection to GLPI B succeeded.'];
        });
    }

    /**
     * @param array{name:string,base_url:string,app_token:string,user_token:string,active:bool} $connection
     * @return array{success:bool,message:string,fields:list<array{key:string,id:string,uid:string,label:string}>}
     */
    public static function fetchNativeFields(array $connection, string $itemtype): array
    {
        $result = self::withSession($connection, static function (string $sessionToken) use ($connection, $itemtype): array {
            $headers = ['App-Token: ' . $connection['app_token'], 'Session-Token: ' . $sessionToken];
            $fieldsResponse = self::request('GET', self::apiUrl($connection['base_url'], 'listSearchOptions/' . rawurlencode($itemtype)), $headers);
            $rawFieldsResponse = self::request('GET', self::apiUrlWithQuery($connection['base_url'], 'listSearchOptions/' . rawurlencode($itemtype), ['raw' => 1]), $headers);
            if (!$fieldsResponse['success']) {
                return $fieldsResponse;
            }
            if (!$rawFieldsResponse['success']) {
                return $rawFieldsResponse;
            }
            $fields = self::fieldsFromSearchOptions(
                self::searchOptionsWithRawMetadata($fieldsResponse['body'], $rawFieldsResponse['body']),
                $itemtype
            );
            return [
                'success' => $fields !== [],
                'message' => $fields !== [] ? 'GLPI B fields loaded.' : 'GLPI B did not return any native fields for this asset type.',
                'fields' => $fields,
            ];
        });
        return $result + ['fields' => []];
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
                return self::searchFailure($optionsResponse['message'], $optionsResponse['transient'], $optionsResponse);
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
                return self::searchFailure($searchResponse['message'], $searchResponse['transient'], $searchResponse);
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
     * @return array{success:bool,message:string,item:array<string,mixed>,missing:bool,transient:bool,date_mod_timezone?:string}
     */
    public static function getItem(array $connection, string $itemtype, int $itemsId, bool $needsDateModTimezone = true): array
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

        return self::withSession($connection, static function (string $sessionToken, string $dateModTimezone = '') use ($connection, $itemtype, $itemsId): array {
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
                    'status_code' => $response['status_code'],
                    'cause' => $response['cause'],
                    'executed' => $response['executed'],
                ];
            }

            return [
                'success'   => true,
                'message'   => 'GLPI B asset loaded.',
                'item'      => $response['body'],
                'missing'   => false,
                'transient' => false,
                self::DATE_MOD_TIMEZONE_KEY => $dateModTimezone,
            ];
        }, $needsDateModTimezone);
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
                    'status_code' => $response['status_code'],
                    'cause' => $response['cause'],
                    'executed' => $response['executed'],
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
                    'status_code' => $response['status_code'],
                    'cause' => $response['cause'],
                    'executed' => $response['executed'],
                ];
            }

            return [
                'success'   => true,
                'message'   => 'GLPI B asset updated.',
                'transient' => false,
            ];
        });
    }

    /** Read and optionally write mapped Fields-plugin values through child-row REST endpoints. */
    public static function customTextValues(array $connection, string $itemtype, int $itemsId, array $keys, array $changes = [], array $expectedTypes = [], bool $allowReadonly = false): array
    {
        $keys = array_values(array_filter(array_unique($keys), [FieldsText::class, 'isCustom']));
        if ($keys === []) {
            return ['success' => true, 'item' => [], 'history_option_ids' => [], 'history_refs' => []];
        }

        return self::withSession($connection, static function (string $sessionToken) use ($connection, $itemtype, $itemsId, $keys, $changes, $expectedTypes, $allowReadonly): array {
            $headers = ['App-Token: ' . $connection['app_token'], 'Session-Token: ' . $sessionToken];
            $options = self::request('GET', self::apiUrlWithQuery($connection['base_url'], 'listSearchOptions/' . rawurlencode($itemtype), ['raw' => 1]), $headers);
            if (!$options['success']) {
                return $options;
            }

            $containers = [];
            $historyOptionIds = [];
            $historyRefs = [];
            foreach ($keys as $key) {
                $metadata = null;
                foreach ($options['body'] as $optionId => $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    if (FieldsText::key($itemtype, $option) !== $key) {
                        continue;
                    }

                    $candidate = FieldsText::metadataFromOption($itemtype, $option, $optionId);
                    if ($candidate['key'] === $key) {
                        $metadata = $candidate;
                        break;
                    }
                }
                if ($metadata === null) {
                    throw new \RuntimeException('The mapped remote Fields-plugin field is unavailable: ' . $key);
                }
                if ($metadata['field_id'] <= 0) {
                    throw new \RuntimeException('The mapped remote Fields-plugin field is missing its field id metadata: ' . $key);
                }
                $definition = self::request('GET', self::apiUrl($connection['base_url'], 'PluginFieldsField/' . $metadata['field_id']), $headers);
                if (!$definition['success']) {
                    $definition['message'] = 'Cannot read GLPI B Fields field configuration (PluginFieldsField). Verify that the API account has configuration-read permission for the Fields plugin. ' . $definition['message'];
                    return $definition;
                }
                $metadata = FieldsText::metadataWithDefinition($metadata, $definition['body']);
                $expectedType = (string) ($expectedTypes[$key] ?? 'text');
                if ($metadata['type'] !== $expectedType) {
                    $foundType = $metadata['type'] !== '' ? $metadata['type'] : 'unsupported';
                    throw new \RuntimeException('The remote Fields-plugin definition is not an active ' . $expectedType . ' field: ' . $key . ' (found ' . $foundType . ').');
                }
                if (array_key_exists($key, $changes) && !$allowReadonly && !$metadata['writable']) {
                    throw new \RuntimeException('The remote Fields-plugin field is read-only: ' . $key);
                }
                if ($metadata['search_option_id'] > 0) {
                    $historyOptionIds[$key] = (string) $metadata['search_option_id'];
                }
                $class = $metadata['class'];
                $historyRefs[$key] = [
                    'option_id' => (string) ($historyOptionIds[$key] ?? ''),
                    'itemtype_link' => $class,
                ];
                $containers[$class]['id'] = $metadata['container_id'];
                $containers[$class]['fields'][$key] = [
                    'column' => $metadata['field'],
                    'type' => $metadata['type'],
                    'metadata' => $metadata,
                ];
            }

            $values = [];
            foreach ($containers as $class => $container) {
                if ($itemsId <= 0) {
                    continue; // Validate before creating a native asset.
                }
                $writing = array_intersect_key($changes, $container['fields']) !== [];
                self::assertCustomContainerAccess($itemtype, $itemsId, $container['id'], $writing, static function (string $endpoint) use ($connection, $headers): array {
                    $result = self::request('GET', self::apiUrl($connection['base_url'], $endpoint), $headers);
                    if (!$result['success']) {
                        $result['message'] = 'Cannot establish GLPI B Fields container permission. Verify the API account\'s Fields configuration/profile-read and asset/entity access. ' . $result['message'];
                        throw new RemoteRequestFailure($result);
                    }
                    return $result['body'];
                }, static function (string $endpoint) use ($connection, $headers): array {
                    return self::remoteCollectionRows($connection, $headers, $endpoint);
                });
                $endpoint = rawurlencode($itemtype) . '/' . $itemsId . '/' . rawurlencode($class);
                $response = self::request('GET', self::apiUrlWithQuery($connection['base_url'], $endpoint, ['range' => '0-1']), $headers);
                if (!$response['success']) {
                    return $response;
                }
                $rows = $response['body'];
                if (!array_is_list($rows) || count($rows) > 1) {
                    throw new \RuntimeException('Expected at most one remote Fields-plugin container row.');
                }
                $row = $rows[0] ?? [];
                if ($row !== [] && ((int) ($row['items_id'] ?? 0) !== $itemsId || ($row['itemtype'] ?? '') !== $itemtype || (int) ($row['plugin_fields_containers_id'] ?? 0) !== $container['id'] || (int) ($row['id'] ?? 0) <= 0)) {
                    throw new \RuntimeException('The remote Fields-plugin row does not belong to the mapped asset/container.');
                }
                $input = [];
                foreach ($container['fields'] as $key => $fieldInfo) {
                    $column = $fieldInfo['column'];
                    $type = $fieldInfo['type'];
                    $values[$key] = $row === [] ? FieldsText::missingValue($type) : self::customReadValue($connection, $headers, $fieldInfo['metadata'], $row[$column] ?? null);
                    if (array_key_exists($key, $changes)) {
                        $input[$column] = self::customWriteValue($connection, $headers, $fieldInfo['metadata'], $changes[$key]);
                    }
                }
                if ($input === []) {
                    continue;
                }
                if ($row === []) {
                    $input += ['items_id' => $itemsId, 'itemtype' => $itemtype, 'plugin_fields_containers_id' => $container['id']];
                    $written = self::request('POST', self::apiUrl($connection['base_url'], rawurlencode($class)), $headers, ['input' => $input]);
                    $rowId = self::createdItemId($written['body']);
                    if (!empty($written['success']) && $rowId <= 0) {
                        throw new \RuntimeException('GLPI B did not return a Fields-plugin row id.');
                    }
                } else {
                    $rowId = (int) $row['id'];
                    $written = self::request('PUT', self::apiUrl($connection['base_url'], rawurlencode($class) . '/' . $rowId), $headers, ['input' => $input]);
                }
                if (empty($written['success'])) {
                    return $written;
                }
                $verified = self::request('GET', self::apiUrl($connection['base_url'], rawurlencode($class) . '/' . $rowId), $headers);
                if (empty($verified['success'])) {
                    return $verified;
                }
                foreach ($container['fields'] as $key => $fieldInfo) {
                    $column = $fieldInfo['column'];
                    $type = $fieldInfo['type'];
                    $persisted = self::customReadValue($connection, $headers, $fieldInfo['metadata'], $verified['body'][$column] ?? null);
                    if (array_key_exists($key, $changes) && $persisted !== FieldsText::normalizeValue($type, $changes[$key])) {
                        throw new \RuntimeException('GLPI B did not persist the Fields-plugin ' . $type . ' value: ' . $key);
                    }
                    $values[$key] = $persisted;
                }
            }

            return ['success' => true, 'item' => $values, 'history_option_ids' => $historyOptionIds, 'history_refs' => $historyRefs];
        });
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param list<string> $headers
     * @param array<string,mixed> $metadata
     */
    private static function customReadValue(array $connection, array $headers, array $metadata, $value)
    {
        $type = (string) ($metadata['type'] ?? '');
        if ($type !== 'dropdown') {
            return FieldsText::normalizeReadValue($type, $value);
        }

        $id = self::cleanRemoteId($value);
        if ($id <= 0) {
            return '';
        }

        return self::remoteDropdownLabelForId(
            self::remoteDropdownRows($connection, $headers, $metadata),
            $id,
            (string) ($metadata['key'] ?? 'unknown')
        );
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param list<string> $headers
     * @param array<string,mixed> $metadata
     */
    private static function customWriteValue(array $connection, array $headers, array $metadata, $value)
    {
        $type = (string) ($metadata['type'] ?? '');
        if ($type !== 'dropdown') {
            return FieldsText::normalizeValue($type, $value);
        }

        $label = FieldsText::normalizeValue($type, $value);
        if ($label === '') {
            return 0;
        }

        return self::remoteDropdownIdForLabel(
            self::remoteDropdownRows($connection, $headers, $metadata),
            $label,
            (string) ($metadata['key'] ?? 'unknown')
        );
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param list<string> $headers
     * @param array<string,mixed> $metadata
     * @return list<array<string,mixed>>
     */
    private static function remoteDropdownRows(array $connection, array $headers, array $metadata): array
    {
        $class = (string) ($metadata['dropdown_class'] ?? '');
        if ($class === '') {
            throw new \RuntimeException('The remote Fields-plugin dropdown class is unavailable: ' . ($metadata['key'] ?? 'unknown'));
        }

        return self::remoteCollectionRows($connection, $headers, rawurlencode($class));
    }

    /** Return a catalog only after proving every page complete; never expose a partial list. */
    private static function remoteCollectionRows(array $connection, array $headers, string $endpoint): array
    {
        $rows = [];
        $offset = 0;
        $total = null;
        $lastId = 0;
        for ($page = 0; $page < 10; $page++) {
            $response = self::request('GET', self::apiUrlWithQuery($connection['base_url'], $endpoint, [
                'range' => $offset . '-' . ($offset + 999), 'sort' => 'id', 'order' => 'ASC', 'get_hateoas' => 0,
            ]), $headers);
            if (!$response['success']) {
                if ($offset > 0 && $response['status_code'] === 400 && ($response['body'][0] ?? '') === 'ERROR_RANGE_EXCEED_TOTAL') {
                    $response['transient'] = true;
                    $response['cause'] = 'catalog_changed';
                    $response['message'] = 'GLPI B catalog shrank during pagination; retry with a fresh catalog.';
                }
                throw new RemoteRequestFailure($response);
            }
            $body = $response['body'];
            $ranges = $response['headers']['content-range'] ?? [];
            if (!$response['json_valid'] || !$response['json_list'] || !array_is_list($body)) {
                throw new \RuntimeException('GLPI B catalog is incomplete: expected a valid JSON row list.');
            }
            // GLPI returns 200 [] without Content-Range for a genuinely empty collection.
            if ($body === [] && $offset === 0 && $response['status_code'] === 200 && $ranges === []) {
                return [];
            }
            if (count($ranges) !== 1 || !preg_match('/\A(\d+)-(\d+)\/(\d+)\z/', $ranges[0], $range)) {
                throw new \RuntimeException('GLPI B catalog is incomplete: missing or invalid Content-Range.');
            }
            $start = (int) $range[1];
            $end = (int) $range[2];
            $pageTotal = (int) $range[3];
            if ($total !== null && $pageTotal !== $total) {
                throw new RemoteRequestFailure([
                    'success' => false, 'message' => 'GLPI B catalog changed during pagination; retry with a fresh catalog.',
                    'status_code' => $response['status_code'], 'transient' => true, 'cause' => 'catalog_changed',
                    'executed' => $response['executed'],
                ]);
            }
            if ($pageTotal > 10000) {
                throw new \RuntimeException('GLPI B catalog is incomplete: exceeds the 10000-row limit.');
            }
            if ($start !== $offset || $end < $start || $end >= $pageTotal || $end > $offset + 999 || count($body) !== $end - $start + 1) {
                throw new \RuntimeException('GLPI B catalog is incomplete: inconsistent range or row count.');
            }
            foreach ($body as $row) {
                $id = is_array($row) ? self::cleanRemoteId($row['id'] ?? 0) : 0;
                if ($id <= $lastId) {
                    throw new \RuntimeException('GLPI B catalog is incomplete: IDs must be unique and ascending.');
                }
                $lastId = $id;
                $rows[] = $row;
            }
            $total = $pageTotal;
            $offset = $end + 1;
            if ($offset === $total) {
                return $rows; // A valid final page may still use HTTP 206.
            }
        }
        throw new \RuntimeException('GLPI B catalog is incomplete: exceeds the 10-page limit.');
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function remoteDropdownLabelForId(array $rows, int $id, string $key): string
    {
        foreach ($rows as $row) {
            if (self::cleanRemoteId($row['id'] ?? 0) !== $id) {
                continue;
            }

            $label = FieldsText::dropdownLabel($row);
            if ($label === '') {
                throw new \RuntimeException('The remote Fields-plugin dropdown option has no label for ' . $key . ': ' . $id);
            }

            return $label;
        }

        throw new \RuntimeException('The remote Fields-plugin dropdown option is missing for ' . $key . ': ' . $id);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function remoteDropdownIdForLabel(array $rows, string $label, string $key): int
    {
        $matches = [];
        foreach ($rows as $row) {
            if (FieldsText::dropdownLabel($row) !== $label) {
                continue;
            }

            $id = self::cleanRemoteId($row['id'] ?? 0);
            if ($id > 0) {
                $matches[$id] = true;
            }
        }

        if ($matches === []) {
            throw new \RuntimeException('The destination GLPI B Fields-plugin dropdown option is missing for ' . $key . ': ' . $label);
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('The destination GLPI B Fields-plugin dropdown option label is duplicated for ' . $key . ': ' . $label);
        }

        return (int) array_key_first($matches);
    }

    /**
     * Read bounded GLPI history rows for custom Fields search-option and container refs.
     *
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     * @param array<string,array{option_id?:int|string,itemtype_link?:string}> $historyRefs
     * @return array{success:bool,message:string,dates:array<string,string>,transient:bool,date_mod_timezone?:string}
     */
    public static function customHistoryDates(array $connection, string $itemtype, int $itemsId, array $historyRefs): array
    {
        $historyRefs = self::cleanHistoryRefs($historyRefs);
        if ($itemsId <= 0 || $historyRefs === []) {
            return [
                'success' => true,
                'message' => 'No GLPI B custom history needed.',
                'dates' => [],
                'transient' => false,
            ];
        }

        return self::withSession($connection, static function (string $sessionToken, string $dateModTimezone = '') use ($connection, $itemtype, $itemsId, $historyRefs): array {
            $response = self::request(
                'GET',
                self::apiUrlWithQuery($connection['base_url'], rawurlencode($itemtype) . '/' . $itemsId . '/Log', [
                    'range' => self::CUSTOM_HISTORY_RANGE,
                    'sort' => 'id',
                    'order' => 'DESC',
                    'get_hateoas' => 0,
                ]),
                [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ]
            );

            if (!$response['success']) {
                return [
                    'success' => false,
                    'message' => $response['message'],
                    'dates' => [],
                    'transient' => $response['transient'],
                    'status_code' => $response['status_code'],
                    'cause' => $response['cause'],
                    'executed' => $response['executed'],
                    self::DATE_MOD_TIMEZONE_KEY => $dateModTimezone,
                ];
            }

            $logs = $response['body'] ?? null;
            if (!is_array($logs)) {
                return [
                    'success' => true,
                    'message' => 'GLPI B did not expose item history for this asset.',
                    'dates' => [],
                    'transient' => false,
                    self::DATE_MOD_TIMEZONE_KEY => $dateModTimezone,
                ];
            }

            return [
                'success' => true,
                'message' => 'GLPI B custom history loaded.',
                'dates' => self::latestHistoryDatesByRef($logs, $historyRefs),
                'transient' => false,
                self::DATE_MOD_TIMEZONE_KEY => $dateModTimezone,
            ];
        }, true);
    }

    /** Mirror Fields' profile and container entity checks before accessing a generated child row. */
    private static function assertCustomContainerAccess(string $itemtype, int $itemsId, int $containerId, bool $writing, callable $read, ?callable $readCollection = null): void
    {
        $profile = $read('getActiveProfile')['active_profile'] ?? [];
        $profileId = (int) ($profile['id'] ?? 0);
        // Generated child rows do not consistently enforce the parent's update permission.
        $parentRight = $writing ? 2 : 1; // GLPI UPDATE / READ.
        if ($profileId <= 0 || (((int) ($profile[strtolower($itemtype)] ?? 0)) & $parentRight) === 0) {
            throw new \RuntimeException('GLPI B Fields access denied: an active profile with parent asset ' . ($writing ? 'update' : 'read') . ' permission is required.');
        }
        $container = $read('PluginFieldsContainer/' . $containerId);
        $itemtypes = json_decode((string) ($container['itemtypes'] ?? ''), true);
        if ((int) ($container['id'] ?? 0) !== $containerId || (int) ($container['is_active'] ?? 0) !== 1 || !is_array($itemtypes) || !in_array($itemtype, $itemtypes, true)
            || !isset($container['entities_id'], $container['is_recursive'])) {
            throw new \RuntimeException('Cannot establish GLPI B Fields container permission: container is unavailable, inactive, or not applicable to this asset type.');
        }
        if ($readCollection === null) {
            throw new \RuntimeException('Cannot establish GLPI B Fields container permission: a complete profile catalog reader is required.');
        }
        $rights = $readCollection('PluginFieldsContainer/' . $containerId . '/PluginFieldsProfile');
        $right = 0;
        foreach ($rights as $row) {
            if (is_array($row) && (int) ($row['profiles_id'] ?? 0) === $profileId && (int) ($row['plugin_fields_containers_id'] ?? 0) === $containerId) {
                $right = max($right, (int) ($row['right'] ?? 0));
            }
        }
        // Fields uses right > READ for writes, and right >= READ for reads.
        if ($right < 1 || ($writing && $right <= 1)) {
            throw new \RuntimeException('GLPI B Fields container permission denied or unavailable for the active API profile. Grant the required container ' . ($writing ? 'write' : 'read') . ' right in the Fields plugin profile settings.');
        }
        $asset = $read(rawurlencode($itemtype) . '/' . $itemsId);
        if ((int) ($asset['id'] ?? 0) !== $itemsId || !isset($asset['entities_id']) || !empty($asset['is_deleted'])) {
            throw new \RuntimeException('Cannot establish GLPI B Fields container permission: parent asset is unavailable.');
        }
        $entityId = (int) $asset['entities_id'];
        $containerEntityId = (int) $container['entities_id'];
        $seen = [];
        while ($entityId !== $containerEntityId) {
            if ((int) $container['is_recursive'] !== 1 || $entityId <= 0 || isset($seen[$entityId]) || count($seen) >= 100) {
                throw new \RuntimeException('GLPI B Fields container permission denied: the parent asset entity is outside the container scope.');
            }
            $seen[$entityId] = true;
            $entity = $read('Entity/' . $entityId);
            if ((int) ($entity['id'] ?? -1) !== $entityId || !isset($entity['entities_id'])) {
                throw new \RuntimeException('Cannot establish GLPI B Fields container permission: entity ancestry is unavailable.');
            }
            $entityId = (int) $entity['entities_id'];
        }
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

    /**
     * @param array<string,array{option_id?:int|string,itemtype_link?:string}> $historyRefs
     * @return array<string,array{option_id:string,itemtype_link:string}>
     */
    private static function cleanHistoryRefs(array $historyRefs): array
    {
        $clean = [];

        foreach ($historyRefs as $key => $ref) {
            if (!is_scalar($key) || !is_array($ref)) {
                continue;
            }

            $optionId = self::cleanHistoryOptionId($ref['option_id'] ?? '');
            $itemtypeLink = is_scalar($ref['itemtype_link'] ?? null) ? trim((string) $ref['itemtype_link']) : '';
            if ($optionId === '' && $itemtypeLink === '') {
                continue;
            }

            $clean[(string) $key] = [
                'option_id' => $optionId,
                'itemtype_link' => $itemtypeLink,
            ];
        }

        return $clean;
    }

    /**
     * @param array<string|int,mixed> $logs
     * @param array<string,array{option_id:string,itemtype_link:string}> $historyRefs
     * @return array<string,string>
     */
    private static function latestHistoryDatesByRef(array $logs, array $historyRefs): array
    {
        $latestRows = [];

        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }

            $optionId = self::cleanHistoryOptionId($log['id_search_option'] ?? 0);
            $logId = self::cleanRemoteId($log['id'] ?? 0);
            $itemtypeLink = is_scalar($log['itemtype_link'] ?? null) ? trim((string) $log['itemtype_link']) : '';
            if (($optionId === '' && $itemtypeLink === '') || $logId <= 0) {
                continue;
            }

            foreach ($historyRefs as $key => $ref) {
                $matchesOption = $ref['option_id'] !== '' && $optionId === $ref['option_id'];
                $matchesItemtypeLink = $ref['itemtype_link'] !== '' && $itemtypeLink === $ref['itemtype_link'];
                if (!$matchesOption && !$matchesItemtypeLink) {
                    continue;
                }

                if (!isset($latestRows[$key]) || $logId > $latestRows[$key]['id']) {
                    $latestRows[$key] = [
                        'id' => $logId,
                        'date_mod' => is_scalar($log['date_mod'] ?? null) ? trim((string) $log['date_mod']) : '',
                    ];
                }
            }
        }

        $dates = [];
        foreach ($latestRows as $key => $row) {
            $dates[$key] = $row['date_mod'];
        }

        return $dates;
    }

    private static function cleanHistoryOptionId($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) && (int) $value > 0 ? $value : '';
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
    private static function fieldsFromSearchOptions(array $options, string $itemtype = 'Computer'): array
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
            $customKey = FieldsText::key($itemtype, $option);
            if ($customKey !== '') {
                try {
                    FieldsText::validate($option);
                } catch (\RuntimeException) {
                    continue;
                }

                if (isset($seenKeys[$customKey])) {
                    continue;
                }

                $seenKeys[$customKey] = true;
                $fields[] = [
                    'key' => $customKey,
                    'id' => $id,
                    'uid' => $customKey,
                    'label' => $label,
                ];
                continue;
            }

            if (self::hasRawFieldsPluginMetadata($option)) {
                continue;
            }

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
     * @param array<string,mixed> $options
     * @param array<string,mixed> $rawOptions
     * @return array<string,mixed>
     */
    private static function searchOptionsWithRawMetadata(array $options, array $rawOptions): array
    {
        foreach ($options as $optionId => $option) {
            if (!is_array($option) || !isset($rawOptions[$optionId]) || !is_array($rawOptions[$optionId])) {
                continue;
            }

            foreach (['pfields_type', 'pfields_fields_id', 'plugin_fields_containers_id', 'is_multiple', 'multiple'] as $metadataKey) {
                if (!array_key_exists($metadataKey, $option) && array_key_exists($metadataKey, $rawOptions[$optionId])) {
                    $option[$metadataKey] = $rawOptions[$optionId][$metadataKey];
                }
            }

            $options[$optionId] = $option;
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $option
     */
    private static function hasRawFieldsPluginMetadata(array $option): bool
    {
        return isset($option['pfields_type'])
            && is_scalar($option['pfields_type'])
            && trim((string) $option['pfields_type']) !== '';
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
     * @return list<array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}>
     */
    private static function decodeConnections(): array
    {
        if (isset(self::$runCache['connections'])) {
            $savedConnections = self::$runCache['connections'];
        } else {
            $savedValues = self::getSavedValues([self::CONNECTIONS_KEY]);
            $savedConnections = json_decode((string) ($savedValues[self::CONNECTIONS_KEY] ?? ''), true);
            if (!is_array($savedConnections)) {
                return [];
            }
            if (self::$runCache !== null) {
                self::$runCache['connections'] = $savedConnections;
            }
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
        self::invalidateRunCache();
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

        try {
            if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
                \Config::setConfigurationValues(self::CONTEXT, [
                    self::CONNECTIONS_KEY => json_encode($savedConnections, JSON_THROW_ON_ERROR),
                ]);
            }
        } finally {
            self::invalidateRunCache();
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
    private static function withSession(array $connection, callable $callback, bool $needsDateModTimezone = false): array
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
            return $session;
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
            if ($needsDateModTimezone) {
                $dateModTimezone = self::remoteDateModTimezone($connection, $sessionToken);
                $result = $callback($sessionToken, $dateModTimezone);
            } else {
                $result = $callback($sessionToken);
            }
        } catch (\Throwable $failure) {
            $primaryFailure = $failure;
        } finally {
            try {
                $cleanup = self::request('GET', self::apiUrl($connection['base_url'], 'killSession'), [
                    'App-Token: ' . $connection['app_token'],
                    'Session-Token: ' . $sessionToken,
                ], null, true);
            } catch (RemoteRequestFailure $failure) {
                $cleanup = $failure->result;
            } catch (\Throwable) {
                // Best-effort cleanup must not replay a successful mutation or hide its failure.
            }
        }
        $cleanupFailure = isset($cleanup) && !$cleanup['success']
            ? array_intersect_key($cleanup, array_flip(['status_code', 'cause', 'executed'])) : [];
        if (isset($primaryFailure)) {
            if ($cleanupFailure !== []) {
                $result = $primaryFailure instanceof RemoteRequestFailure ? $primaryFailure->result : [
                    'success' => false, 'message' => 'GLPI B request failed: ' . $primaryFailure->getMessage(),
                    'transient' => !($primaryFailure instanceof \RuntimeException),
                    'status_code' => 0, 'cause' => '', 'executed' => false,
                ];
                throw new RemoteRequestFailure($result + ['cleanup_failure' => $cleanupFailure]);
            }
            throw $primaryFailure;
        }
        if ($cleanupFailure !== []) {
            $result['cleanup_failure'] = $cleanupFailure;
        }
        return $result;
    }

    /**
     * @param array{id?:string,name?:string,base_url:string,app_token:string,user_token:string,active?:bool} $connection
     */
    private static function remoteDateModTimezone(array $connection, string $sessionToken): string
    {
        $fullSession = self::request('GET', self::apiUrl($connection['base_url'], 'getFullSession'), [
            'App-Token: ' . $connection['app_token'],
            'Session-Token: ' . $sessionToken,
        ]);

        if (!$fullSession['success']) {
            throw new RemoteRequestFailure($fullSession);
        }

        return self::sessionTimezone($fullSession['body']);
    }

    /**
     * @param array<string,mixed> $session
     */
    private static function sessionTimezone(array $session): string
    {
        $timezone = self::sessionScalar($session, 'glpitimezone');
        if ($timezone !== '' && $timezone !== '0' && self::isValidTimezone($timezone)) {
            return $timezone;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function sessionScalar(array $values, string $key): string
    {
        if (isset($values[$key]) && is_scalar($values[$key])) {
            return trim((string) $values[$key]);
        }

        foreach ($values as $value) {
            if (is_array($value)) {
                $found = self::sessionScalar($value, $key);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    private static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * @return array{success:bool,message:string,items:list<array{id:int>>,total_count:int,transient:bool}
     */
    private static function searchFailure(string $message, bool $transient, array $request = []): array
    {
        return [
            'success'     => false,
            'message'     => $message,
            'items'       => [],
            'total_count' => 0,
            'transient'   => $transient,
            'status_code' => (int) ($request['status_code'] ?? 0),
            'cause' => (string) ($request['cause'] ?? ''),
            'executed' => (bool) ($request['executed'] ?? false),
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
     * @return array{success:bool,message:string,body:array,status_code:int,transient:bool,headers?:array,json_valid?:bool,json_list?:bool}
     */
    private static function request(string $method, string $url, array $headers, ?array $payload = null, bool $cleanup = false): array
    {
        // All operations in the phase share this cutoff; sessions never replenish it.
        $cutoffNs = self::$httpDeadlineNs === null ? null
            : self::$httpDeadlineNs - ($cleanup ? 0 : self::HTTP_CLEANUP_RESERVE_NS);
        if ($cutoffNs !== null && $cutoffNs - hrtime(true) < 1_000_000) {
            return ['success' => false, 'message' => 'GLPI B request budget exhausted.', 'body' => [],
                'status_code' => 0, 'transient' => true, 'cause' => 'budget_deadline', 'executed' => false];
        }
        if (!function_exists('curl_init')) {
            return [
                'success'     => false,
                'message'     => 'The PHP cURL extension is required to test the connection.',
                'body'        => [],
                'status_code' => 0,
                'transient'   => false,
                'cause'       => '',
                'executed'    => false,
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
                'cause'       => '',
                'executed'    => false,
            ];
        }

        $executed = false;
        $rawBody = false;
        $statusCode = 0;
        $latencyMs = 0.0;
        $timeoutMs = 15000;
        try {
            $requestHeaders = array_merge($headers, ['Accept: application/json']);
            if ($payload !== null) {
                $requestHeaders[] = 'Content-Type: application/json';
            }

            $responseHeaders = [];
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $requestHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $header = trim($line);
                    if (preg_match('/\AHTTP\/\S+\s+\d{3}\b/i', $header)) {
                        $responseHeaders = []; // Only the final response block supplies pagination proof.
                    } elseif (str_contains($header, ':')) {
                        [$name, $value] = explode(':', $header, 2);
                        if (strtolower(trim($name)) === 'content-range') {
                            $responseHeaders['content-range'][] = trim($value);
                        }
                    }
                    return $length;
                },
            ]);

            if ($payload !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            }

            $remainingMs = $cutoffNs === null ? 15000 : intdiv($cutoffNs - hrtime(true), 1_000_000);
            if ($remainingMs < 1) {
                return ['success' => false, 'message' => 'GLPI B request budget exhausted.', 'body' => [],
                    'status_code' => 0, 'transient' => true, 'cause' => 'budget_deadline', 'executed' => false];
            }
            $timeoutMs = min($cleanup && $cutoffNs !== null ? 1000 : 15000, $remainingMs);
            curl_setopt($curl, CURLOPT_TIMEOUT_MS, $timeoutMs);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
            $started = hrtime(true);
            if ($cutoffNs !== null && $cutoffNs - $started < 1_000_000) {
                return ['success' => false, 'message' => 'GLPI B request budget exhausted.', 'body' => [],
                    'status_code' => 0, 'transient' => true, 'cause' => 'budget_deadline', 'executed' => false];
            }
            $executed = true;
            try {
                $rawBody = curl_exec($curl);
            } catch (\Throwable $failure) {
                throw new RemoteRequestFailure(['success' => false, 'message' => 'HTTP request failed: ' . $failure->getMessage(),
                    'body' => [], 'status_code' => 0, 'transient' => true, 'cause' => 'transport', 'executed' => true]);
            } finally {
                $latencyMs = (hrtime(true) - $started) / 1_000_000;
            }
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            $errorNumber = curl_errno($curl);
        } finally {
            $connectionId = self::$httpMetricsConnection;
            if ($executed && self::$httpMetrics !== null && $connectionId !== null && $connectionId !== '') {
                self::$httpMetrics[$connectionId] ??= ['requests' => 0, 'errors' => 0, 'latency_ms' => 0.0];
                self::$httpMetrics[$connectionId]['requests']++;
                // Each executed request contributes at most one transport/non-2xx error.
                if ($rawBody === false || $statusCode < 200 || $statusCode >= 300) {
                    self::$httpMetrics[$connectionId]['errors']++;
                }
                self::$httpMetrics[$connectionId]['latency_ms'] += $latencyMs;
            }
            curl_close($curl);
        }

        if ($rawBody === false) {
            return [
                'success'     => false,
                'message'     => 'HTTP request failed: ' . $error,
                'body'        => [],
                'status_code' => $statusCode,
                'transient'   => true,
                'cause'       => $errorNumber === 28 && $timeoutMs < 15000 && $cutoffNs !== null && $cutoffNs - hrtime(true) < 1_000_000
                    ? 'budget_deadline' : 'transport',
                'executed'    => true,
            ];
        }

        $body = [];
        $jsonValid = false;
        $jsonList = false;
        try {
            $decoded = json_decode((string) $rawBody, false, 512, JSON_THROW_ON_ERROR);
            $jsonValid = true;
            $jsonList = is_array($decoded);
            $body = json_decode((string) $rawBody, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                $body = [];
            }
        } catch (\JsonException) {
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success'     => false,
                'message'     => 'GLPI B returned HTTP ' . $statusCode . '.',
                'body'        => $body,
                'status_code' => $statusCode,
                'transient'   => self::isTransientStatusCode($statusCode),
                'cause'       => 'http',
                'executed'    => true,
                'headers'     => $responseHeaders,
                'json_valid'  => $jsonValid,
                'json_list'   => $jsonList,
            ];
        }

        return [
            'success'     => true,
            'message'     => 'Request succeeded.',
            'body'        => $body,
            'status_code' => $statusCode,
            'transient'   => false,
            'cause'       => 'http',
            'executed'    => true,
            'headers'     => $responseHeaders,
            'json_valid'  => $jsonValid,
            'json_list'   => $jsonList,
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
