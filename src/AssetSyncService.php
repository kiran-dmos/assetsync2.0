<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncService
{
    private const SCAN_CONTEXT = 'plugin:assetsync20';
    private const SCAN_CURSORS_KEY = 'asset_sync_scan_cursors';
    private const SCAN_LAST_VISITED_KEY = 'asset_sync_scan_last_visited';
    private const MAX_SCAN_CANDIDATES = 50;
    private const MAX_SCAN_VISITS = 10;
    private const MAX_VISIT_CANDIDATES = 5;
    private const SCAN_SECONDS = 2;
    private const MAX_ROUTE_ENTITY_IDS = 1000;
    private const INBOUND_RECHECK_SECONDS = 3600;
    private const DATE_MOD_TIMEZONE_KEY = 'date_mod_timezone';

    /** @var object|string */
    private $remoteClient;
    private ?\DateTimeImmutable $billingDate;
    private \Closure $scanClock;

    /**
     * @param object|string|null $remoteClient
     * @param callable():float|null $scanClock Monotonic seconds, injectable for scan tests.
     */
    public function __construct($remoteClient = null, ?\DateTimeImmutable $billingDate = null, ?callable $scanClock = null)
    {
        $this->remoteClient = $remoteClient ?? GlpiBConnection::class;
        $this->billingDate = $billingDate;
        $this->scanClock = \Closure::fromCallable($scanClock ?? static fn (): float => hrtime(true) / 1_000_000_000);
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::SCAN_CONTEXT, [self::SCAN_CURSORS_KEY, self::SCAN_LAST_VISITED_KEY]);
        }
    }

    public function run(int $batchSize = 10, int $timeLimitSeconds = 25, bool $forceInboundRecheck = false): int
    {
        $batchSize = max(1, $batchSize);
        $deadline = time() + max(1, $timeLimitSeconds);
        $processed = $this->processQueue($batchSize, $deadline);
        $enqueued = 0;
        if ($processed < $batchSize && time() < $deadline) {
            $remaining = $batchSize - $processed;
            $enqueued = $this->enqueueBackfill($remaining, $deadline, $forceInboundRecheck);
            $processed += $this->processQueue($remaining, $deadline);
        }

        return $enqueued + $processed;
    }

    public function enqueueBackfill(int $limit = 10, ?int $deadline = null, bool $forceInboundRecheck = false): int
    {
        $db = $this->db();
        if ($db === null || !method_exists($db, 'request')) {
            return 0;
        }

        $limit = max(1, $limit);
        $deadline = $deadline ?? (time() + 25);
        $scanDeadline = ($this->scanClock)() + min(self::SCAN_SECONDS, max(0, $deadline - time()));
        $connections = $this->activeConnectionsById();
        $cursors = $this->loadScanCursors();
        $pairs = [];
        foreach (EntitySyncRoute::loadAll() as $route) {
            if (!$route['active'] || !isset($connections[$route['glpi_b_connection_id']])) {
                continue;
            }

            foreach ($route['asset_types'] as $itemtype) {
                $tuple = json_encode([$route['glpi_b_connection_id'], $route['id'], $itemtype], JSON_THROW_ON_ERROR);
                $pairs[$tuple] = ['route' => $route, 'itemtype' => $itemtype];
            }
        }

        // Tuple keys give a stable order and keep duplicate tuples to one visit.
        ksort($pairs, SORT_STRING);
        $tuples = array_keys($pairs);
        $lastIndex = array_search($this->loadLastScanVisit(), $tuples, true);
        $startIndex = $lastIndex === false ? 0 : $lastIndex + 1;
        $enqueued = 0;
        $examined = 0;

        for ($visited = 0; $visited < min(self::MAX_SCAN_VISITS, count($tuples)); $visited++) {
            if ($enqueued >= $limit || $examined >= self::MAX_SCAN_CANDIDATES || !$this->scanHasTime($deadline, $scanDeadline)) {
                break;
            }

            $tuple = $tuples[($startIndex + $visited) % count($tuples)];
            $route = $pairs[$tuple]['route'];
            $itemtype = $pairs[$tuple]['itemtype'];
            $cursorKey = $route['id'] . ':' . $itemtype;
            $cursor = max(0, (int) ($cursors[$cursorKey] ?? 0));
            $queryLimit = min(self::MAX_VISIT_CANDIDATES, self::MAX_SCAN_CANDIDATES - $examined, $limit - $enqueued);
            $rows = $this->routeAssetRows($route, $itemtype, $cursor, $queryLimit, $deadline, $scanDeadline);

            if ($rows === [] && $cursor > 0 && $this->scanHasTime($deadline, $scanDeadline)) {
                $rows = $this->routeAssetRows($route, $itemtype, 0, $queryLimit, $deadline, $scanDeadline);
            }

            foreach ($rows as $row) {
                if ($enqueued >= $limit || $examined >= self::MAX_SCAN_CANDIDATES || !$this->scanHasTime($deadline, $scanDeadline)) {
                    break;
                }

                // Count started lookups even if time runs out before preparation.
                $examined++;
                $itemsId = (int) ($row['id'] ?? 0);
                if ($itemsId <= 0) {
                    continue;
                }

                $queued = $this->prepareAndQueueAsset($itemtype, $itemsId, $route['glpi_b_connection_id'], $forceInboundRecheck, $deadline, $scanDeadline);
                if ($queued === null) {
                    break;
                }

                $cursor = $itemsId;
                if ($queued) {
                    $enqueued++;
                }
            }

            $cursors[$cursorKey] = (string) $cursor;
            $this->saveScanProgress($cursors, $tuple);
        }

        return $enqueued;
    }

    public function queueAssetIfNeeded(string $itemtype, int $itemsId, string $connectionId, bool $forceInboundRecheck = false): bool
    {
        return $this->prepareAndQueueAsset($itemtype, $itemsId, $connectionId, $forceInboundRecheck) ?? false;
    }

    // A scan stopped before preparation returns null, leaving its asset cursor unchanged.
    private function prepareAndQueueAsset(string $itemtype, int $itemsId, string $connectionId, bool $forceInboundRecheck, ?int $deadline = null, ?float $scanDeadline = null): ?bool
    {
        $asset = $this->loadLocalAsset($itemtype, $itemsId);
        if ($asset === null) {
            return false;
        }

        if ($deadline !== null && $scanDeadline !== null && !$this->scanHasTime($deadline, $scanDeadline)) {
            return null;
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
        if ($deadline !== null && $scanDeadline !== null && !$this->scanHasTime($deadline, $scanDeadline)) {
            return null;
        }

        try {
            $mappings = FieldMapping::syncMappings($connectionId, $itemtype);
            $customTypes = FieldMapping::expectedCustomTypes($itemtype, $mappings);
            $prepared = $this->prepareAssetForSync($itemtype, $itemsId, $asset, $mappings, $customTypes);
            $asset = $prepared['asset'];
            $mappings = $prepared['mappings'];
        } catch (\Throwable $error) {
            AssetSyncLink::saveStatus($itemtype, $itemsId, $connectionId, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, $error->getMessage());
            return false;
        }
        $payloadHash = $this->payloadHash($asset, $route, $mappings);
        $payloadDate = $this->payloadDate($asset);
        $link = AssetSyncLink::find($itemtype, $itemsId, $connectionId);

        if ($link !== null && (string) ($link['route_id'] ?? '') === $route['id'] && (string) ($link['last_payload_hash'] ?? '') === $payloadHash) {
            $linkStatus = (string) ($link['status'] ?? '');
            if ($linkStatus === AssetSyncLink::STATUS_SYNCED) {
                if ($forceInboundRecheck) {
                    if (!$this->hasInboundMapping($mappings)) {
                        return false;
                    }
                } elseif (!$this->linkedAssetNeedsRemoteCheck($link, $mappings)) {
                    return false;
                }
            }

            if (str_starts_with($linkStatus, 'blocked_')) {
                // Retry only a known remote asset, through the normal validation path.
                if (!$forceInboundRecheck
                    || $linkStatus !== AssetSyncLink::STATUS_BLOCKED_LOCAL_UPDATE
                    || (int) ($link['remote_items_id'] ?? 0) <= 0) {
                    return false;
                }
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

        try {
            $mappings = FieldMapping::syncMappings($connectionId, $itemtype);
            $customTypes = FieldMapping::expectedCustomTypes($itemtype, $mappings);
            $prepared = $this->prepareAssetForSync($itemtype, $itemsId, $asset, $mappings, $customTypes);
            $asset = $prepared['asset'];
            $mappings = $prepared['mappings'];
            $customTypes = $prepared['custom_types'];
        } catch (\Throwable $error) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, $error->getMessage());
            return;
        }
        $customKeys = array_keys($customTypes);
        if ($customKeys !== []) {
            $validation = $this->callRemote('customTextValues', [$connection, $itemtype, 0, $customKeys, [], $customTypes]);
            if (!$this->remoteSucceeded($validation)) {
                $this->handleRemoteFailure($job, $route['id'], $validation, $attempts, null);
                return;
            }
        }
        $link = AssetSyncLink::find($itemtype, $itemsId, $connectionId);
        $remoteItemsId = $link !== null ? (int) ($link['remote_items_id'] ?? 0) : (int) ($job['remote_items_id'] ?? 0);
        $remoteItem = null;
        $remoteDateModTimezone = '';
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
            $remoteDateModTimezone = $this->dateModTimezone($remoteResult[self::DATE_MOD_TIMEZONE_KEY] ?? '');
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
                $remoteDateModTimezone = $this->dateModTimezone($remoteResult[self::DATE_MOD_TIMEZONE_KEY] ?? '');
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

        if ($customKeys !== []) {
            $customResult = $this->callRemote('customTextValues', [$connection, $itemtype, $remoteItemsId, $customKeys, [], $customTypes]);
            if (!$this->remoteSucceeded($customResult)) {
                $this->handleRemoteFailure($job, $route['id'], $customResult, $attempts, $remoteItemsId);
                return;
            }
            $remoteItem = array_merge($remoteItem ?? [], $customResult['item']);
        }
        // Native creation already applied its mappings; custom rows are written separately.
        $comparisonMappings = $createdRemote ? array_values(array_filter($mappings, static fn (array $mapping): bool => FieldsText::isCustom($mapping['glpi_b_field']) || FieldsText::isCustom($mapping['glpi_a_field']))) : $mappings;
        $localCustomDateMods = [];
        $remoteCustomDateMods = [];
        try {
            $customHistoryNeeds = $this->customHistoryNeeds($asset, $remoteItem ?? [], $comparisonMappings, $customTypes);
            if ($customHistoryNeeds['local'] !== []) {
                $localCustomDateMods = $this->localCustomHistoryDates($itemtype, $itemsId, $customHistoryNeeds['local']);
            }
            if ($customHistoryNeeds['remote'] !== []) {
                $remoteHistoryRefs = $this->historyRefs($customResult['history_refs'] ?? [], $customHistoryNeeds['remote']);
                $remoteHistory = $this->remoteCustomHistoryDates($connection, $itemtype, $remoteItemsId, $remoteHistoryRefs, $job, $route['id'], $attempts);
                if ($remoteHistory === null) {
                    return;
                }
                $remoteCustomDateMods = $remoteHistory['dates'];
                $remoteHistoryTimezone = $this->dateModTimezone($remoteHistory[self::DATE_MOD_TIMEZONE_KEY] ?? '');
                $remoteDateModTimezone = $remoteHistoryTimezone !== '' && ($remoteDateModTimezone === '' || $remoteDateModTimezone === $remoteHistoryTimezone)
                    ? $remoteHistoryTimezone
                    : '';
            }
            $changes = $this->existingChanges($asset, $remoteItem ?? [], $route, $comparisonMappings, $customTypes, $localCustomDateMods, $remoteCustomDateMods, $remoteDateModTimezone);
        } catch (\Throwable $error) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, $error->getMessage(), $remoteItemsId);
            return;
        }

        if ($changes['conflicts'] !== []) {
            $conflictMessage = $remoteDateModTimezone === ''
                ? 'Both sides have different values, but GLPI B did not expose a named timezone for timestamp comparison. Set a named timezone such as UTC on the GLPI B API user instead of "Use server configuration" for: '
                : 'Both sides have different values without a newer unambiguous asset or custom history timestamp for: ';
            $this->blockJob(
                $job,
                $route['id'],
                AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT,
                $conflictMessage . implode(', ', $changes['conflicts']) . '.',
                $remoteItemsId
            );
            return;
        }

        // A-owned derived outputs must use this run's inbound inputs before sending to B.
        try {
            $billingAsset = array_merge($asset, $changes['local']);
            $billingValues = array_merge(
                HardwareBilling::syncData($itemtype, $billingAsset, $this->billingDate ?? new \DateTimeImmutable('today'))['values'],
                SwsdBilling::syncData($itemtype, $billingAsset)['values']
            );
        } catch (\RuntimeException $error) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, $error->getMessage(), $remoteItemsId);
            return;
        }
        foreach ($comparisonMappings as $mapping) {
            $localKey = $mapping['glpi_a_field'];
            if ($mapping['source_of_truth'] === 'glpi_a' && array_key_exists($localKey, $billingValues)) {
                $remoteKey = $mapping['glpi_b_field'];
                unset($changes['remote'][$remoteKey]);
                if (($remoteItem[$remoteKey] ?? null) !== $billingValues[$localKey]) {
                    $changes['remote'][$remoteKey] = $billingValues[$localKey];
                }
            }
        }

        $nativeChanges = array_filter($changes['remote'], static fn (string $key): bool => !FieldsText::isCustom($key), ARRAY_FILTER_USE_KEY);
        $customChanges = array_diff_key($changes['remote'], $nativeChanges);
        if ($nativeChanges !== []) {
            $updateResult = $this->callRemote('updateItem', [$connection, $itemtype, $remoteItemsId, $nativeChanges]);
            if (!$this->remoteSucceeded($updateResult)) {
                $this->handleRemoteFailure($job, $route['id'], $updateResult, $attempts, $remoteItemsId);
                return;
            }
        }

        if ($customChanges !== []) {
            $updateResult = $this->callRemote('customTextValues', [$connection, $itemtype, $remoteItemsId, $customKeys, $customChanges, $customTypes, true]);
            if (!$this->remoteSucceeded($updateResult)) {
                $this->handleRemoteFailure($job, $route['id'], $updateResult, $attempts, $remoteItemsId);
                return;
            }
        }

        if ($changes['local'] !== []) {
            try {
                $updatedLocal = AssetChangeHook::withoutQueue(
                    fn (): bool => $this->updateLocalAsset($itemtype, $itemsId, $changes['local'])
                );
            } catch (\RuntimeException $error) {
                $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_LOCAL_UPDATE, $error->getMessage(), $remoteItemsId);
                return;
            }
            if (!$updatedLocal) {
                $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_LOCAL_UPDATE, 'GLPI A rejected the local field update.', $remoteItemsId);
                return;
            }
        }

        $finalAsset = array_merge($asset, $changes['local']);
        try {
            $finalAsset = $this->applyHardwareBilling($itemtype, $itemsId, $finalAsset);
            $finalAsset = $this->applySwsdBilling($itemtype, $itemsId, $finalAsset, $mappings);
        } catch (\RuntimeException $error) {
            $this->blockJob($job, $route['id'], AssetSyncLink::STATUS_BLOCKED_LOCAL_UPDATE, $error->getMessage(), $remoteItemsId);
            return;
        }
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
        if (is_int($asset['_date_mod_epoch'] ?? null)) {
            return gmdate('Y-m-d H:i:s', $asset['_date_mod_epoch']);
        }

        return '';
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

        $lastSyncTime = (int) ($link['last_sync_epoch'] ?? 0);
        if (!$lastSyncTime || $lastSyncTime > time() + 60) {
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
    private function routeAssetRows(array $route, string $itemtype, int $afterId, int $limit, int $deadline, float $scanDeadline): array
    {
        $db = $this->db();
        if ($db === null || !method_exists($db, 'request')) {
            return [];
        }

        $table = $this->assetTable($itemtype);
        if ($table === '' || (method_exists($db, 'tableExists') && !$db->tableExists($table))) {
            return [];
        }

        $entityIds = $this->routeEntityIds($route, $deadline, $scanDeadline);
        if ($entityIds === [] || !$this->scanHasTime($deadline, $scanDeadline)) {
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
    private function routeEntityIds(array $route, int $deadline, float $scanDeadline): array
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

        while ($queue !== [] && count($entityIds) < self::MAX_ROUTE_ENTITY_IDS && $this->scanHasTime($deadline, $scanDeadline)) {
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

        $db = $this->db();
        $table = $this->assetTable($itemtype);
        if ($db !== null && method_exists($db, 'request') && $table !== '') {
            $rows = $db->request([
                'SELECT' => [new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`date_mod`)', 'date_mod_epoch')],
                'FROM' => $table,
                'WHERE' => ['id' => $itemsId],
                'LIMIT' => 1,
            ]);
            foreach ($rows as $row) {
                if (is_array($row) && is_numeric($row['date_mod_epoch'] ?? null)) {
                    $fields['_date_mod_epoch'] = (int) $row['date_mod_epoch'];
                }
                break;
            }
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $asset
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     * @param array<string,string> $customTypes
     * @return array{
     *     asset:array<string,mixed>,
     *     mappings:list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}>,
     *     custom_types:array<string,string>
     * }
     */
    private function prepareAssetForSync(string $itemtype, int $itemsId, array $asset, array $mappings, array $customTypes): array
    {
        $localValueKeys = array_merge(
            array_column($mappings, 'glpi_a_field'),
            HardwareBilling::localInputKeys($itemtype),
            HardwareBilling::localOutputKeys($itemtype),
            SwsdBilling::localInputKeys($itemtype),
            SwsdBilling::localOutputKeys($itemtype)
        );
        $asset += FieldsText::localValues($itemtype, $itemsId, $localValueKeys);

        $asset = $this->applyHardwareBilling($itemtype, $itemsId, $asset);
        $asset = $this->applySwsdBilling($itemtype, $itemsId, $asset, $mappings);

        return [
            'asset' => $asset,
            'mappings' => $mappings,
            'custom_types' => $customTypes,
        ];
    }

    /**
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    private function applyHardwareBilling(string $itemtype, int $itemsId, array $asset): array
    {
        $billing = HardwareBilling::syncData($itemtype, $asset, $this->billingDate ?? new \DateTimeImmutable('today'));
        if ($billing['values'] !== []) {
            $billingChanges = [];
            foreach ($billing['values'] as $key => $value) {
                if (($asset[$key] ?? null) !== $value) {
                    $billingChanges[$key] = $value;
                }
            }

            if ($billingChanges !== []) {
                $updatedBilling = AssetChangeHook::withoutQueue(
                    fn (): bool => $this->updateLocalAsset($itemtype, $itemsId, $billingChanges)
                );
                if (!$updatedBilling) {
                    throw new \RuntimeException('GLPI A rejected the HW Billing field update.');
                }
            }

            $asset = array_merge($asset, $billing['values']);
        }

        return $asset;
    }

    /**
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    private function applySwsdBilling(string $itemtype, int $itemsId, array $asset, array $mappings): array
    {
        $billing = SwsdBilling::syncData($itemtype, $asset);
        foreach ($mappings as $mapping) {
            if ($mapping['source_of_truth'] !== 'glpi_a') {
                unset($billing['values'][$mapping['glpi_a_field']]);
            }
        }
        if ($billing['values'] !== []) {
            $billingChanges = [];
            foreach ($billing['values'] as $key => $value) {
                if (($asset[$key] ?? null) !== $value) {
                    $billingChanges[$key] = $value;
                }
            }

            if ($billingChanges !== []) {
                $updatedBilling = AssetChangeHook::withoutQueue(
                    fn (): bool => $this->updateLocalAsset($itemtype, $itemsId, $billingChanges)
                );
                if (!$updatedBilling) {
                    throw new \RuntimeException('GLPI A rejected the SW/SD Billing field update.');
                }
            }

            $asset = array_merge($asset, $billing['values']);
        }

        return $asset;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function updateLocalAsset(string $itemtype, int $itemsId, array $fields): bool
    {
        $customFields = array_filter($fields, static fn (string $key): bool => FieldsText::isCustom($key), ARRAY_FILTER_USE_KEY);
        try {
            if (!FieldsText::updateLocal($itemtype, $itemsId, $customFields, true)) {
                return false;
            }
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable) {
            return false;
        }
        $fields = array_diff_key($fields, $customFields);
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
            if (FieldsText::isCustom($mapping['glpi_b_field'])) {
                continue;
            }
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
     * @param array<string,string> $fieldTypes
     * @param array<string,string> $localCustomDateMods
     * @param array<string,string> $remoteCustomDateMods
     * @param string $remoteDateModTimezone Timezone used by GLPI B date_mod strings.
     * @return array{remote:array<string,mixed>,local:array<string,mixed>,conflicts:list<string>}
     */
    private function existingChanges(array $asset, array $remoteItem, array $route, array $mappings, array $fieldTypes = [], array $localCustomDateMods = [], array $remoteCustomDateMods = [], string $remoteDateModTimezone = ''): array
    {
        $remoteChanges = [];
        $localChanges = [];
        $conflicts = [];
        $targetEntityId = (string) $route['glpi_b_target_entity_id'];

        if ($targetEntityId !== '' && $this->value($remoteItem['entities_id'] ?? '') !== $targetEntityId) {
            $remoteChanges['entities_id'] = (int) $targetEntityId;
        }

        foreach ($mappings as $mapping) {
            $fieldType = $this->mappingFieldType($mapping, $fieldTypes);
            $localValue = $this->mappedValue($asset[$mapping['glpi_a_field']] ?? '', $fieldType);
            $remoteValue = $this->mappedValue($remoteItem[$mapping['glpi_b_field']] ?? '', $fieldType);

            if ($mapping['source_of_truth'] === 'glpi_a' && $localValue !== $remoteValue) {
                $remoteChanges[$mapping['glpi_b_field']] = $this->changeValue($localValue, $fieldType);
                continue;
            }

            if ($mapping['source_of_truth'] === 'glpi_b' && $localValue !== $remoteValue) {
                $localChanges[$mapping['glpi_a_field']] = $this->changeValue($remoteValue, $fieldType);
                continue;
            }

            if ($mapping['source_of_truth'] !== 'both') {
                continue;
            }

            if ($localValue === $remoteValue) {
                continue;
            }

            $newerDateModSource = $this->newerMappingDateModSource($asset, $remoteItem, $mapping, $localCustomDateMods, $remoteCustomDateMods, $remoteDateModTimezone);

            if ($newerDateModSource === 'glpi_a') {
                $remoteChanges[$mapping['glpi_b_field']] = $this->changeValue($localValue, $fieldType);
                continue;
            }

            if ($newerDateModSource === 'glpi_b') {
                $localChanges[$mapping['glpi_a_field']] = $this->changeValue($remoteValue, $fieldType);
                continue;
            }

            $conflicts[] = $mapping['glpi_a_field'];
        }

        return [
            'remote' => $remoteChanges,
            'local' => $localChanges,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $remoteItem
     * @param list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}> $mappings
     * @param array<string,string> $fieldTypes
     * @return array{local:list<string>,remote:list<string>}
     */
    private function customHistoryNeeds(array $asset, array $remoteItem, array $mappings, array $fieldTypes): array
    {
        $localKeys = [];
        $remoteKeys = [];

        foreach ($mappings as $mapping) {
            if ($mapping['source_of_truth'] !== 'both') {
                continue;
            }

            if (!FieldsText::isCustom($mapping['glpi_a_field']) && !FieldsText::isCustom($mapping['glpi_b_field'])) {
                continue;
            }

            $fieldType = $this->mappingFieldType($mapping, $fieldTypes);
            $localValue = $this->mappedValue($asset[$mapping['glpi_a_field']] ?? '', $fieldType);
            $remoteValue = $this->mappedValue($remoteItem[$mapping['glpi_b_field']] ?? '', $fieldType);
            if ($localValue === $remoteValue) {
                continue;
            }

            if (FieldsText::isCustom($mapping['glpi_a_field'])) {
                $localKeys[$mapping['glpi_a_field']] = true;
            }

            if (FieldsText::isCustom($mapping['glpi_b_field'])) {
                $remoteKeys[$mapping['glpi_b_field']] = true;
            }
        }

        return [
            'local' => array_keys($localKeys),
            'remote' => array_keys($remoteKeys),
        ];
    }

    /**
     * @param list<string> $keys
     * @return array<string,string>
     */
    private function localCustomHistoryDates(string $itemtype, int $itemsId, array $keys): array
    {
        $db = $this->db();
        if ($db === null || !method_exists($db, 'request') || $keys === []) {
            return [];
        }

        $historyRefs = FieldsText::customHistoryRefs($itemtype, $keys);
        if ($historyRefs === []) {
            return [];
        }

        $rows = $db->request([
            'SELECT' => [
                'id', 'date_mod', 'id_search_option', 'itemtype_link',
                new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`date_mod`)', 'date_mod_epoch'),
            ],
            'FROM' => 'glpi_logs',
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => max(20, count($historyRefs) * 20),
        ]);

        $datedRows = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['date_mod_epoch'] ?? null)) {
                $row['date_mod'] = gmdate('Y-m-d H:i:s', (int) $row['date_mod_epoch']);
            }
            $datedRows[] = $row;
        }

        return $this->latestHistoryDatesByRef($datedRows, $historyRefs);
    }

    /**
     * @param array{id:string,name:string,base_url:string,app_token:string,user_token:string,active:bool} $connection
     * @param array<string,array{option_id:string,itemtype_link:string}> $historyRefs
     * @param array<string,mixed> $job
     * @return array{dates:array<string,string>,date_mod_timezone:string}|null
     */
    private function remoteCustomHistoryDates(array $connection, string $itemtype, int $itemsId, array $historyRefs, array $job, string $routeId, int $attempts): ?array
    {
        if ($historyRefs === []) {
            return ['dates' => [], self::DATE_MOD_TIMEZONE_KEY => ''];
        }

        $historyResult = $this->callRemote('customHistoryDates', [$connection, $itemtype, $itemsId, $historyRefs]);
        if (!$this->remoteSucceeded($historyResult)) {
            $this->handleRemoteFailure($job, $routeId, $historyResult, $attempts, $itemsId);
            return null;
        }

        return [
            'dates' => $this->stringMap($historyResult['dates'] ?? []),
            self::DATE_MOD_TIMEZONE_KEY => $this->dateModTimezone($historyResult[self::DATE_MOD_TIMEZONE_KEY] ?? ''),
        ];
    }

    /**
     * @param mixed $value
     * @param list<string> $keys
     * @return array<string,array{option_id:string,itemtype_link:string}>
     */
    private function historyRefs($value, array $keys): array
    {
        if (!is_array($value)) {
            return [];
        }

        $refs = [];
        foreach ($keys as $key) {
            $ref = is_array($value[$key] ?? null) ? $value[$key] : [];
            $optionId = $this->cleanHistoryOptionId($ref['option_id'] ?? '');
            $itemtypeLink = is_scalar($ref['itemtype_link'] ?? null) ? trim((string) $ref['itemtype_link']) : '';
            if ($optionId === '' && $itemtypeLink === '') {
                continue;
            }

            $refs[$key] = [
                'option_id' => $optionId,
                'itemtype_link' => $itemtypeLink,
            ];
        }

        return $refs;
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @param array<string,array{option_id:string,itemtype_link:string}> $historyRefs
     * @return array<string,string>
     */
    private function latestHistoryDatesByRef(iterable $rows, array $historyRefs): array
    {
        $latestRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $logId = (int) ($row['id'] ?? 0);
            if ($logId <= 0) {
                continue;
            }

            $optionId = $this->cleanHistoryOptionId($row['id_search_option'] ?? 0);
            $itemtypeLink = is_scalar($row['itemtype_link'] ?? null) ? trim((string) $row['itemtype_link']) : '';
            if ($optionId === '' && $itemtypeLink === '') {
                continue;
            }

            foreach ($historyRefs as $key => $ref) {
                $matchesOption = ($ref['option_id'] ?? '') !== '' && $optionId === $ref['option_id'];
                $matchesItemtypeLink = ($ref['itemtype_link'] ?? '') !== '' && $itemtypeLink === $ref['itemtype_link'];
                if (!$matchesOption && !$matchesItemtypeLink) {
                    continue;
                }

                if (!isset($latestRows[$key]) || $logId > $latestRows[$key]['id']) {
                    $latestRows[$key] = [
                        'id' => $logId,
                        'date_mod' => is_scalar($row['date_mod'] ?? null) ? trim((string) $row['date_mod']) : '',
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

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $remoteItem
     * @param array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string} $mapping
     * @param array<string,string> $localCustomDateMods
     * @param array<string,string> $remoteCustomDateMods
     */
    private function newerMappingDateModSource(array $asset, array $remoteItem, array $mapping, array $localCustomDateMods, array $remoteCustomDateMods, string $remoteDateModTimezone = ''): string
    {
        $localKey = $mapping['glpi_a_field'];
        $remoteKey = $mapping['glpi_b_field'];
        $localEpoch = $asset['_date_mod_epoch'] ?? null;
        $localTimezone = is_int($localEpoch) ? 'UTC' : $this->dateModTimezone($this->localDateModTimezone());
        $remoteTimezone = $this->dateModTimezone($remoteDateModTimezone);
        if ($localTimezone === '' || $remoteTimezone === '') {
            return '';
        }

        $localParentDateMod = is_int($localEpoch)
            ? gmdate('Y-m-d H:i:s', $localEpoch)
            : trim((string) ($asset['date_mod'] ?? ''));
        $localDateMod = FieldsText::isCustom($localKey)
            ? $this->effectiveCustomDateMod($localParentDateMod, (string) ($localCustomDateMods[$localKey] ?? ''), $localTimezone)
            : $localParentDateMod;
        $remoteDateMod = FieldsText::isCustom($remoteKey)
            ? $this->effectiveCustomDateMod((string) ($remoteItem['date_mod'] ?? ''), (string) ($remoteCustomDateMods[$remoteKey] ?? ''), $remoteTimezone)
            : trim((string) ($remoteItem['date_mod'] ?? ''));

        return $this->newerDateModValueSource($localDateMod, $remoteDateMod, $localTimezone, $remoteTimezone);
    }

    private function effectiveCustomDateMod(string $parentDateMod, string $customHistoryDateMod, string $timezone): string
    {
        $customHistoryTimestamp = $this->validDateModTimestamp(trim($customHistoryDateMod), $timezone);
        if ($customHistoryTimestamp === null) {
            return '';
        }

        $parentDateMod = trim($parentDateMod);
        $parentTimestamp = $this->validDateModTimestamp($parentDateMod, $timezone);
        if ($parentTimestamp !== null && $parentTimestamp > $customHistoryTimestamp) {
            return $parentDateMod;
        }

        return trim($customHistoryDateMod);
    }

    private function newerDateModValueSource(string $localDateMod, string $remoteDateMod, string $localTimezone, string $remoteTimezone): string
    {
        if ($localDateMod === '' || $remoteDateMod === '') {
            return '';
        }

        $localTimestamp = $this->validDateModTimestamp($localDateMod, $localTimezone);
        $remoteTimestamp = $this->validDateModTimestamp($remoteDateMod, $remoteTimezone);

        if ($localTimestamp === null || $remoteTimestamp === null) {
            return '';
        }

        if ($localTimestamp === $remoteTimestamp) {
            return '';
        }

        return $localTimestamp > $remoteTimestamp ? 'glpi_a' : 'glpi_b';
    }

    private function validDateModTimestamp(string $dateMod, string $timezone = ''): ?int
    {
        if ($dateMod === '') {
            return null;
        }

        try {
            $dateTimezone = new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
        } catch (\Throwable) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateMod, $dateTimezone);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0);

        if ($date === false || $hasErrors || $date->format('Y-m-d H:i:s') !== $dateMod) {
            return null;
        }

        $wallTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateMod, new \DateTimeZone('UTC'));
        if ($wallTime === false) {
            return null;
        }

        $offsets = [$date->getOffset()];
        foreach ($dateTimezone->getTransitions($date->getTimestamp() - 172800, $date->getTimestamp() + 172800) ?: [] as $transition) {
            $offsets[] = (int) $transition['offset'];
        }

        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $timestamp = $wallTime->getTimestamp() - $offset;
            $inTimezone = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($dateTimezone);
            if ($inTimezone->format('Y-m-d H:i:s') === $dateMod) {
                $matches[$timestamp] = true;
            }
        }

        return count($matches) === 1 ? (int) array_key_first($matches) : null;
    }

    private function dateModTimezone($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $timezone = trim((string) $value);
        if ($timezone === '') {
            return '';
        }

        return in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : '';
    }

    private function localDateModTimezone(): string
    {
        $db = $this->db();
        if (is_object($db) && method_exists($db, 'guessTimezone')) {
            try {
                $timezone = $db->guessTimezone();
                if (is_scalar($timezone)) {
                    return (string) $timezone;
                }
            } catch (\Throwable) {
                // Fall back to PHP's configured timezone outside a full GLPI DB session.
            }
        }

        return date_default_timezone_get();
    }

    private function cleanHistoryOptionId($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) && (int) $value > 0 ? $value : '';
    }

    /**
     * @return array<string,string>
     */
    private function stringMap($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $key => $text) {
            if (is_scalar($key) && is_scalar($text)) {
                $strings[(string) $key] = (string) $text;
            }
        }

        return $strings;
    }

    /**
     * @param array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string} $mapping
     * @param array<string,string> $fieldTypes
     */
    private function mappingFieldType(array $mapping, array $fieldTypes): string
    {
        foreach (['glpi_b_field', 'glpi_a_field'] as $fieldName) {
            $key = $mapping[$fieldName] ?? '';
            if (isset($fieldTypes[$key])) {
                return $fieldTypes[$key];
            }
        }

        return 'text';
    }

    private function mappedValue($value, string $fieldType): string
    {
        if ($fieldType === 'text' || $fieldType === 'textarea') {
            return $this->value($value);
        }

        if ($value === null || $value === '') {
            return '';
        }

        return (string) FieldsText::normalizeValue($fieldType, $value);
    }

    private function changeValue(string $value, string $fieldType)
    {
        if ($fieldType === 'text') {
            return $value;
        }

        return FieldsText::normalizeValue($fieldType, $value);
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
            (int) ($job['payload_epoch'] ?? 0) > 0 ? gmdate('Y-m-d H:i:s', (int) $job['payload_epoch']) : null
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
                'transient' => !($error instanceof \RuntimeException),
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
    private function saveScanProgress(array $cursors, string $tuple): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
            \Config::setConfigurationValues(self::SCAN_CONTEXT, [
                self::SCAN_CURSORS_KEY => json_encode($cursors, JSON_THROW_ON_ERROR),
                self::SCAN_LAST_VISITED_KEY => $tuple,
            ]);
        }
    }

    private function loadLastScanVisit(): ?string
    {
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            return null;
        }

        $values = \Config::getConfigurationValues(self::SCAN_CONTEXT, [self::SCAN_LAST_VISITED_KEY]);
        $json = $values[self::SCAN_LAST_VISITED_KEY] ?? null;
        $tuple = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($tuple) || !array_is_list($tuple) || count($tuple) !== 3) {
            return null;
        }
        foreach ($tuple as $value) {
            if (!is_string($value) || $value === '') {
                return null;
            }
        }

        return json_encode($tuple, JSON_THROW_ON_ERROR);
    }

    private function scanHasTime(int $deadline, float $scanDeadline): bool
    {
        return time() < $deadline && ($this->scanClock)() < $scanDeadline;
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
