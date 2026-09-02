<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncService
{
    private const SCAN_CONTEXT = 'plugin:assetsync20';
    private const SCAN_CURSORS_KEY = 'asset_sync_scan_cursors';
    private const MAX_ROUTE_ENTITY_IDS = 1000;
    private const INBOUND_RECHECK_SECONDS = 3600;

    /** @var object|string */
    private $remoteClient;

    /**
     * @param object|string|null $remoteClient
     */
    public function __construct($remoteClient = null)
    {
        $this->remoteClient = $remoteClient ?? GlpiBConnection::class;
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::SCAN_CONTEXT, [self::SCAN_CURSORS_KEY]);
        }
    }

    public function run(int $batchSize = 10, int $timeLimitSeconds = 25): int
    {
        $batchSize = max(1, $batchSize);
        $deadline = time() + max(1, $timeLimitSeconds);
        $enqueued = $this->enqueueBackfill($batchSize, $deadline);
        $processed = $this->processQueue($batchSize, $deadline);

        return $enqueued + $processed;
    }

    public function enqueueBackfill(int $limit = 10, ?int $deadline = null): int
    {
        $db = $this->db();
        if ($db === null || !method_exists($db, 'request')) {
            return 0;
        }

        $limit = max(1, $limit);
        $deadline = $deadline ?? (time() + 25);
        $connections = $this->activeConnectionsById();
        $cursors = $this->loadScanCursors();
        $enqueued = 0;

        foreach (EntitySyncRoute::loadAll() as $route) {
            if ($enqueued >= $limit || time() >= $deadline) {
                break;
            }

            if (!$route['active'] || !isset($connections[$route['glpi_b_connection_id']])) {
                continue;
            }

            foreach ($route['asset_types'] as $itemtype) {
                if ($enqueued >= $limit || time() >= $deadline) {
                    break;
                }

                $cursorKey = $route['id'] . ':' . $itemtype;
                $cursor = (int) ($cursors[$cursorKey] ?? 0);
                $rows = $this->routeAssetRows($route, $itemtype, $cursor, $limit - $enqueued);

                if ($rows === [] && $cursor > 0) {
                    $cursor = 0;
                    $rows = $this->routeAssetRows($route, $itemtype, $cursor, $limit - $enqueued);
                }

                foreach ($rows as $row) {
                    if ($enqueued >= $limit || time() >= $deadline) {
                        break;
                    }

                    $itemsId = (int) ($row['id'] ?? 0);
                    if ($itemsId <= 0) {
                        continue;
                    }

                    $cursor = $itemsId;
                    if ($this->queueAssetIfNeeded($itemtype, $itemsId, $route['glpi_b_connection_id'])) {
                        $enqueued++;
                    }
                }

                $cursors[$cursorKey] = (string) $cursor;
                $this->saveScanCursors($cursors);
            }
        }

        return $enqueued;
    }

    public function queueAssetIfNeeded(string $itemtype, int $itemsId, string $connectionId): bool
    {
        $asset = $this->loadLocalAsset($itemtype, $itemsId);
        if ($asset === null) {
            return false;
        }

        $scope = $this->resolveScope($itemtype, $asset, $connectionId);
        if ($scope['status'] === 'conflict') {
            AssetSyncLink::saveStatus(
                $itemtype,
                $itemsId,
                $connectionId,
                '',
                AssetSyncLink::STATUS_BLOCKED_ROUTE_CONFLICT,
                $scope['message']
            );
            return false;
        }

        if ($scope['status'] !== 'matched' || $scope['route'] === null) {
            return false;
        }

        $route = $scope['route'];
        $mappings = FieldMapping::syncMappings($connectionId, $itemtype);
        $payloadHash = $this->payloadHash($asset, $route, $mappings);
        $payloadDate = $this->payloadDate($asset);
        $link = AssetSyncLink::find($itemtype, $itemsId, $connectionId);

        if ($link !== null && (string) ($link['route_id'] ?? '') === $route['id'] && (string) ($link['last_payload_hash'] ?? '') === $payloadHash) {
            $linkStatus = (string) ($link['status'] ?? '');
            if ($linkStatus === AssetSyncLink::STATUS_SYNCED && !$this->linkedAssetNeedsRemoteCheck($link, $mappings)) {
                return false;
            }

            if (str_starts_with($linkStatus, 'blocked_')) {
                return false;
            }
        }

        $remoteItemsId = $link !== null ? (int) ($link['remote_items_id'] ?? 0) : null;

        return AssetSyncQueue::enqueue(
            $itemtype,
            $itemsId,
            $connectionId,
            $route['id'],
            $payloadHash,
            $payloadDate,
            $remoteItemsId
        );
    }

    public function processQueue(int $limit = 10, ?int $deadline = null): int
    {
        $deadline = $deadline ?? (time() + 25);
        $processed = 0;

        while ($processed < $limit && time() < $deadline) {
            $jobs = AssetSyncQueue::claimDue(1);
            if ($jobs === []) {
                break;
            }

            $this->processJob($jobs[0]);
            $processed++;
        }

        return $processed;
    }

    /**
     * @param array<string,mixed> $job
     */
    public function processJob(array $job): void
    {
        $queueId = (int) ($job['id'] ?? 0);
        $itemtype = (string) ($job['itemtype'] ?? '');
        $itemsId = (int) ($job['items_id'] ?? 0);
        $connectionId = (string) ($job['glpi_b_connection_id'] ?? '');
        $attempts = (int) ($job['attempts'] ?? 1);

        if ($queueId <= 0 || $itemtype === '' || $itemsId <= 0 || $connectionId === '') {
            return;
        }

        $connection = $this->activeConnection($connectionId);
        if ($connection === null) {
            $this->blockJob($job, '', AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'The GLPI B connection is missing or inactive.');
            return;
        }

        $asset = $this->loadLocalAsset($itemtype, $itemsId);
        if ($asset === null) {
            AssetSyncLink::saveStatus(
                $itemtype,
                $itemsId,
                $connectionId,
                (string) ($job['route_id'] ?? ''),
                AssetSyncLink::STATUS_OUT_OF_SCOPE,
                'The local asset no longer exists or is deleted.'
            );
            AssetSyncQueue::finish($queueId, 'Local asset is no longer syncable.');
            return;
        }

        $scope = $this->resolveScope($itemtype, $asset, $connectionId);
        if ($scope['status'] === 'conflict') {
            $this->blockJob($job, '', AssetSyncLink::STATUS_BLOCKED_ROUTE_CONFLICT, $scope['message']);
            return;
        }

        if ($scope['status'] !== 'matched' || $scope['route'] === null) {
            AssetSyncLink::saveStatus(
                $itemtype,
                $itemsId,
                $connectionId,
                (string) ($job['route_id'] ?? ''),
                AssetSyncLink::STATUS_OUT_OF_SCOPE,
                'The asset is no longer in scope for this GLPI B connection.'
            );
            AssetSyncQueue::finish($queueId, 'Asset is out of scope.');
            return;
        }

        $route = $scope['route'];
        if ($route['glpi_b_target_entity_id'] === '') {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'The matched route has no GLPI B target entity.');
            return;
        }

        $serial = $this->value($asset['serial'] ?? '');
        if ($this->isBlank($serial)) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_IDENTITY, 'The local asset serial is blank.');
            return;
        }

        $mappings = FieldMapping::syncMappings($connectionId, $itemtype);
        $link = AssetSyncLink::find($itemtype, $itemsId, $connectionId);
        $remoteItemsId = $link !== null ? (int) ($link['remote_items_id'] ?? 0) : (int) ($job['remote_items_id'] ?? 0);
        $remoteItem = null;
        $createdRemote = false;

        if ($remoteItemsId > 0) {
            $remoteResult = $this->callRemote('getItem', [$connection, $itemtype, $remoteItemsId]);
            if (!$this->remoteSucceeded($remoteResult)) {
                if (!empty($remoteResult['missing'])) {
                    $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_MISSING_REMOTE, 'The linked GLPI B asset is missing.', $remoteItemsId);
                    return;
                }

                $this->handleRemoteFailure($job, $route['id'], $remoteResult, $attempts, $remoteItemsId);
                return;
            }

            $remoteItem = is_array($remoteResult['item'] ?? null) ? $remoteResult['item'] : [];
        } else {
            $searchResult = $this->callRemote('searchBySerial', [$connection, $itemtype, $serial]);
            if (!$this->remoteSucceeded($searchResult)) {
                $this->handleRemoteFailure($job, $route['id'], $searchResult, $attempts, null);
                return;
            }

            $totalCount = (int) ($searchResult['total_count'] ?? count($searchResult['items'] ?? []));
            $items = is_array($searchResult['items'] ?? null) ? $searchResult['items'] : [];

            if ($totalCount > 1) {
                $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_DUPLICATE, 'Multiple GLPI B assets match serial ' . $serial . '.');
                return;
            }

            if ($totalCount === 1) {
                $remoteItemsId = (int) ($items[0]['id'] ?? 0);
                if ($remoteItemsId <= 0) {
                    $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'GLPI B search did not return a usable asset id.');
                    return;
                }

                $remoteResult = $this->callRemote('getItem', [$connection, $itemtype, $remoteItemsId]);
                if (!$this->remoteSucceeded($remoteResult)) {
                    if (!empty($remoteResult['missing'])) {
                        $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_MISSING_REMOTE, 'The matched GLPI B asset is missing.', $remoteItemsId);
                        return;
                    }

                    $this->handleRemoteFailure($job, $route['id'], $remoteResult, $attempts, $remoteItemsId);
                    return;
                }

                $remoteItem = is_array($remoteResult['item'] ?? null) ? $remoteResult['item'] : [];
            } else {
                $createResult = $this->callRemote('createItem', [$connection, $itemtype, $this->createInput($asset, $route, $mappings)]);
                if (!$this->remoteSucceeded($createResult)) {
                    $this->handleRemoteFailure($job, $route['id'], $createResult, $attempts, null);
                    return;
                }

                $remoteItemsId = (int) ($createResult['id'] ?? 0);
                if ($remoteItemsId <= 0) {
                    $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'GLPI B create did not return a usable asset id.');
                    return;
                }

                $remoteItem = $this->createInput($asset, $route, $mappings);
                $remoteItem['id'] = $remoteItemsId;
                $createdRemote = true;
            }
        }

        if (!$createdRemote && (int) ($remoteItem['is_deleted'] ?? 0) === 1) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_MISSING_REMOTE, 'The linked GLPI B asset is deleted.', $remoteItemsId);
            return;
        }

        $changes = $createdRemote
            ? ['remote' => [], 'local' => [], 'conflicts' => []]
            : $this->existingChanges($asset, $remoteItem ?? [], $route, $mappings);

        if ($changes['conflicts'] !== []) {
            $this->blockJob(
                $job,
                $route['id'],
                AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT,
                'Both sides have different non-blank values for: ' . implode(', ', $changes['conflicts']) . '.',
                $remoteItemsId
            );
            return;
        }

        if ($changes['remote'] !== []) {
            $updateResult = $this->callRemote('updateItem', [$connection, $itemtype, $remoteItemsId, $changes['remote']]);
            if (!$this->remoteSucceeded($updateResult)) {
                $this->handleRemoteFailure($job, $route['id'], $updateResult, $attempts, $remoteItemsId);
                return;
            }
        }

        if ($changes['local'] !== [] && !$this->updateLocalAsset($itemtype, $itemsId, $changes['local'])) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_LOCAL_UPDATE, 'GLPI A rejected the local field update.', $remoteItemsId);
            return;
        }

        $finalAsset = array_merge($asset, $changes['local']);
        AssetSyncLink::save([
            'itemtype'             => $itemtype,
            'items_id'             => $itemsId,
            'glpi_b_connection_id' => $connectionId,
            'route_id'             => $route['id'],
            'remote_items_id'      => $remoteItemsId,
            'status'               => AssetSyncLink::STATUS_SYNCED,
            'last_payload_hash'    => $this->payloadHash($finalAsset, $route, $mappings),
            'last_payload_date'    => $this->payloadDate($finalAsset),
            'last_error'           => '',
        ]);
        AssetSyncQueue::finish($queueId, 'Asset synchronized.', $remoteItemsId);
    }

    /**
     * @param array<string,mixed> $asset
     * @param array{id:string,glpi_b_target_entity_id:string} $route
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     */
    public function payloadHash(array $asset, array $route, array $mappings): string
    {
        $payload = [
            'route_id' => $route['id'],
            'target_entities_id' => $route['glpi_b_target_entity_id'],
            'identity_serial' => $this->value($asset['serial'] ?? ''),
            'fields' => [],
        ];

        foreach ($mappings as $mapping) {
            $payload['fields'][] = [
                'a' => $mapping['glpi_a_field'],
                'b' => $mapping['glpi_b_field'],
                'source' => $mapping['source_of_truth'],
                'value' => $this->value($asset[$mapping['glpi_a_field']] ?? ''),
            ];
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function payloadDate(array $asset): string
    {
        $dateMod = trim((string) ($asset['date_mod'] ?? ''));

        return $dateMod !== '' ? $dateMod : gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $link
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     */
    private function linkedAssetNeedsRemoteCheck(array $link, array $mappings): bool
    {
        if (!$this->hasInboundMapping($mappings)) {
            return false;
        }

        $lastSyncAt = trim((string) ($link['last_sync_at'] ?? ''));
        if ($lastSyncAt === '') {
            return true;
        }

        $lastSyncTime = strtotime($lastSyncAt);
        if ($lastSyncTime === false) {
            return true;
        }

        return $lastSyncTime <= time() - self::INBOUND_RECHECK_SECONDS;
    }

    /**
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     */
    private function hasInboundMapping(array $mappings): bool
    {
        foreach ($mappings as $mapping) {
            if ($mapping['source_of_truth'] === 'glpi_b' || $mapping['source_of_truth'] === 'both') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}>
     */
    private function activeConnectionsById(): array
    {
        $connections = [];

        foreach (GlpiBConnection::loadAll() as $connection) {
            if ($connection['active']) {
                $connections[$connection['id']] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @return array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool}|null
     */
    private function activeConnection(string $connectionId): ?array
    {
        $connection = GlpiBConnection::find($connectionId);

        return $connection !== null && $connection['active'] ? $connection : null;
    }

    /**
     * @param array<string,mixed> $route
     * @return list<array<string,mixed>>
     */
    private function routeAssetRows(array $route, string $itemtype, int $afterId, int $limit): array
    {
        $db = $this->db();
        if ($db === null || !method_exists($db, 'request')) {
            return [];
        }

        $table = $this->assetTable($itemtype);
        if ($table === '' || (method_exists($db, 'tableExists') && !$db->tableExists($table))) {
            return [];
        }

        $entityIds = $this->routeEntityIds($route);
        if ($entityIds === []) {
            return [];
        }

        $rows = $db->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => $table,
            'WHERE'  => [
                'entities_id' => array_map('intval', $entityIds),
                'is_deleted'  => 0,
                'id'          => ['>', $afterId],
            ],
            'ORDER'  => 'id ASC',
            'LIMIT'  => max(1, $limit),
        ]);

        $assets = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $assets[] = $row;
            }
        }

        return $assets;
    }

    /**
     * @param array<string,mixed> $route
     * @return list<string>
     */
    private function routeEntityIds(array $route): array
    {
        $sourceEntityId = trim((string) ($route['glpi_a_source_entity_id'] ?? ''));
        if ($sourceEntityId === '') {
            return [];
        }

        $entityIds = [$sourceEntityId];
        if (empty($route['include_child_entities'])) {
            return $entityIds;
        }

        $db = $this->db();
        if ($db === null || !method_exists($db, 'request')) {
            return $entityIds;
        }

        $seen = [$sourceEntityId => true];
        $queue = [$sourceEntityId];

        while ($queue !== [] && count($entityIds) < self::MAX_ROUTE_ENTITY_IDS) {
            $parentId = array_shift($queue);
            $rows = $db->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['entities_id' => (int) $parentId],
                'ORDER'  => 'id ASC',
            ]);

            foreach ($rows as $row) {
                $childId = trim((string) ($row['id'] ?? ''));
                if ($childId === '' || isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $entityIds[] = $childId;
                $queue[] = $childId;

                if (count($entityIds) >= self::MAX_ROUTE_ENTITY_IDS) {
                    break;
                }
            }
        }

        return $entityIds;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadLocalAsset(string $itemtype, int $itemsId): ?array
    {
        if (!class_exists($itemtype)) {
            return null;
        }

        $item = new $itemtype();
        if (!method_exists($item, 'getFromDB') || !$item->getFromDB($itemsId)) {
            return null;
        }

        $fields = is_array($item->fields ?? null) ? $item->fields : [];
        if ((int) ($fields['is_deleted'] ?? 0) === 1) {
            return null;
        }

        $fields['id'] = (int) ($fields['id'] ?? $itemsId);

        return $fields;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function updateLocalAsset(string $itemtype, int $itemsId, array $fields): bool
    {
        if ($fields === [] || !class_exists($itemtype)) {
            return true;
        }

        $item = new $itemtype();
        if (!method_exists($item, 'update')) {
            return false;
        }

        $input = array_merge(['id' => $itemsId], $fields);

        return (bool) $item->update($input);
    }

    /**
     * @param array<string,mixed> $asset
     * @return array{status:string,message:string,route:array<string,mixed>|null}
     */
    private function resolveScope(string $itemtype, array $asset, string $connectionId): array
    {
        $match = EntitySyncRoute::matchAsset($itemtype, (string) ($asset['entities_id'] ?? ''), [], $connectionId);

        if ($match['conflicts'] !== []) {
            $routeIds = $match['conflicts'][0]['route_ids'] ?? [];

            return [
                'status' => 'conflict',
                'message' => 'Equal-depth active routes overlap for this asset: ' . implode(', ', $routeIds) . '.',
                'route' => null,
            ];
        }

        if ($match['matches'] === []) {
            return [
                'status' => 'out_of_scope',
                'message' => 'The asset is not in scope for this GLPI B connection.',
                'route' => null,
            ];
        }

        return [
            'status' => 'matched',
            'message' => '',
            'route' => $match['matches'][0]['route'],
        ];
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $route
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     * @return array<string,mixed>
     */
    private function createInput(array $asset, array $route, array $mappings): array
    {
        $input = [
            'entities_id' => (int) $route['glpi_b_target_entity_id'],
            'serial'      => $this->value($asset['serial'] ?? ''),
        ];

        foreach ($mappings as $mapping) {
            $source = $mapping['source_of_truth'];
            $localValue = $this->value($asset[$mapping['glpi_a_field']] ?? '');

            if ($source === 'glpi_a') {
                $input[$mapping['glpi_b_field']] = $localValue;
            }

            if ($source === 'both' && !$this->isBlank($localValue)) {
                $input[$mapping['glpi_b_field']] = $localValue;
            }
        }

        return $input;
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $remoteItem
     * @param array<string,mixed> $route
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     * @return array{remote:array<string,mixed>,local:array<string,mixed>,conflicts:list<string>}
     */
    private function existingChanges(array $asset, array $remoteItem, array $route, array $mappings): array
    {
        $remoteChanges = [];
        $localChanges = [];
        $conflicts = [];
        $targetEntityId = (string) $route['glpi_b_target_entity_id'];

        if ($targetEntityId !== '' && $this->value($remoteItem['entities_id'] ?? '') !== $targetEntityId) {
            $remoteChanges['entities_id'] = (int) $targetEntityId;
        }

        foreach ($mappings as $mapping) {
            $localValue = $this->value($asset[$mapping['glpi_a_field']] ?? '');
            $remoteValue = $this->value($remoteItem[$mapping['glpi_b_field']] ?? '');

            if ($mapping['source_of_truth'] === 'glpi_a' && $localValue !== $remoteValue) {
                $remoteChanges[$mapping['glpi_b_field']] = $localValue;
                continue;
            }

            if ($mapping['source_of_truth'] === 'glpi_b' && $localValue !== $remoteValue) {
                $localChanges[$mapping['glpi_a_field']] = $remoteValue;
                continue;
            }

            if ($mapping['source_of_truth'] !== 'both') {
                continue;
            }

            if ($this->isBlank($localValue) && !$this->isBlank($remoteValue)) {
                $localChanges[$mapping['glpi_a_field']] = $remoteValue;
                continue;
            }

            if (!$this->isBlank($localValue) && $this->isBlank($remoteValue)) {
                $remoteChanges[$mapping['glpi_b_field']] = $localValue;
                continue;
            }

            if (!$this->isBlank($localValue) && !$this->isBlank($remoteValue) && $localValue !== $remoteValue) {
                $conflicts[] = $mapping['glpi_a_field'];
            }
        }

        return [
            'remote' => $remoteChanges,
            'local' => $localChanges,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param array<string,mixed> $job
     */
    private function blockJob(array $job, string $routeId, string $linkStatus, string $message, ?int $remoteItemsId = null): void
    {
        $itemtype = (string) ($job['itemtype'] ?? '');
        $itemsId = (int) ($job['items_id'] ?? 0);
        $connectionId = (string) ($job['glpi_b_connection_id'] ?? '');

        AssetSyncLink::saveStatus(
            $itemtype,
            $itemsId,
            $connectionId,
            $routeId,
            $linkStatus,
            $message,
            $remoteItemsId,
            (string) ($job['payload_hash'] ?? ''),
            (string) ($job['payload_date'] ?? '')
        );
        AssetSyncQueue::block((int) ($job['id'] ?? 0), $message, $remoteItemsId);
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $remoteResult
     */
    private function handleRemoteFailure(array $job, string $routeId, array $remoteResult, int $attempts, ?int $remoteItemsId): void
    {
        $message = (string) ($remoteResult['message'] ?? 'GLPI B request failed.');

        if (!empty($remoteResult['transient'])) {
            AssetSyncQueue::retry((int) ($job['id'] ?? 0), $attempts, $message);
            return;
        }

        $this->blockJob($job, $routeId, AssetSyncLink::STATUS_BLOCKED_REMOTE_ERROR, $message, $remoteItemsId);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function remoteSucceeded(array $result): bool
    {
        return !empty($result['success']);
    }

    /**
     * @param list<mixed> $arguments
     * @return array<string,mixed>
     */
    private function callRemote(string $method, array $arguments): array
    {
        try {
            if (is_string($this->remoteClient) && method_exists($this->remoteClient, $method)) {
                $result = $this->remoteClient::$method(...$arguments);
            } elseif (is_object($this->remoteClient) && method_exists($this->remoteClient, $method)) {
                $result = $this->remoteClient->$method(...$arguments);
            } else {
                return [
                    'success' => false,
                    'message' => 'The GLPI B sync client is not available.',
                    'transient' => false,
                ];
            }
        } catch (\Throwable $error) {
            return [
                'success' => false,
                'message' => 'GLPI B request failed: ' . $error->getMessage(),
                'transient' => true,
            ];
        }

        return is_array($result) ? $result : [
            'success' => false,
            'message' => 'The GLPI B sync client returned an invalid response.',
            'transient' => false,
        ];
    }

    private function assetTable(string $itemtype): string
    {
        if (class_exists($itemtype) && method_exists($itemtype, 'getTable')) {
            return (string) $itemtype::getTable();
        }

        $tables = [
            'Computer'         => 'glpi_computers',
            'Monitor'          => 'glpi_monitors',
            'Peripheral'       => 'glpi_peripherals',
            'Printer'          => 'glpi_printers',
            'Phone'            => 'glpi_phones',
            'NetworkEquipment' => 'glpi_networkequipments',
        ];

        return $tables[$itemtype] ?? '';
    }

    /**
     * @return array<string,string>
     */
    private function loadScanCursors(): array
    {
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            return [];
        }

        $values = \Config::getConfigurationValues(self::SCAN_CONTEXT, [self::SCAN_CURSORS_KEY]);
        $json = (string) ($values[self::SCAN_CURSORS_KEY] ?? '');
        $decoded = $json !== '' ? json_decode($json, true) : [];

        if (!is_array($decoded)) {
            return [];
        }

        $cursors = [];
        foreach ($decoded as $key => $value) {
            if (is_scalar($key) && is_scalar($value)) {
                $cursors[(string) $key] = (string) $value;
            }
        }

        return $cursors;
    }

    /**
     * @param array<string,string> $cursors
     */
    private function saveScanCursors(array $cursors): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
            \Config::setConfigurationValues(self::SCAN_CONTEXT, [
                self::SCAN_CURSORS_KEY => json_encode($cursors, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * @return object|null
     */
    private function db(): ?object
    {
        return isset($GLOBALS['DB']) && is_object($GLOBALS['DB']) ? $GLOBALS['DB'] : null;
    }

    private function value($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function isBlank(string $value): bool
    {
        return trim($value) === '';
    }
}
