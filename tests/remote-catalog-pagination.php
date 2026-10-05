<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function curl_init(string $url) { return (object) ['url' => $url, 'options' => [], 'status' => 0]; }
    function curl_setopt_array(object $curl, array $options): bool { $curl->options = $options; return true; }
    function curl_setopt(object $curl, int $option, $value): bool { $curl->options[$option] = $value; return true; }
    function curl_exec(object $curl)
    {
        $step = array_shift(\CatalogCurl::$steps);
        \CatalogCurl::$executions++;
        $path = substr((string) parse_url($curl->url, PHP_URL_PATH), strlen('/apirest.php/'));
        parse_str((string) parse_url($curl->url, PHP_URL_QUERY), $query);
        if ($step === null || $path !== $step['path'] || ($curl->options[CURLOPT_CUSTOMREQUEST] ?? '') !== ($step['method'] ?? 'GET')) {
            throw new \LogicException('Unexpected catalog HTTP request: ' . $path);
        }
        if (isset($step['range'])) {
            \catalogCheck(($query['range'] ?? '') === $step['range'] && ($query['sort'] ?? '') === 'id' && ($query['order'] ?? '') === 'ASC' && ($query['get_hateoas'] ?? '') === '0', 'Each catalog request must use contiguous range and deterministic order.');
        }
        if (isset($step['input'])) {
            \catalogCheck(json_decode($curl->options[CURLOPT_POSTFIELDS], true)['input'] === $step['input'], 'Only the expected mapped field may be written.');
        }
        \CatalogCurl::$methods[] = $curl->options[CURLOPT_CUSTOMREQUEST];
        $curl->status = $step['status'];
        ($curl->options[CURLOPT_HEADERFUNCTION])($curl, 'HTTP/1.1 ' . $step['status'] . "\r\n");
        foreach ($step['headers'] as $header) {
            \catalogCheck(($curl->options[CURLOPT_HEADERFUNCTION])($curl, $header . "\r\n") === strlen($header) + 2, 'Header callback must consume the full line.');
        }
        return $step['body'];
    }
    function curl_getinfo(object $curl, int $option): int { return $curl->status; }
    function curl_error(object $curl): string { return 'private-transport-token'; }
    function curl_errno(object $curl): int { return 0; }
    function curl_close(object $curl): void { \CatalogCurl::$closed++; }
}

namespace {
    $smokeBootstrapOnly = true;
    require __DIR__ . '/smoke.php';

    use GlpiPlugin\Assetsync20\AssetSyncLink;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20\AssetSyncService;
    use GlpiPlugin\Assetsync20\EntitySyncRoute;
    use GlpiPlugin\Assetsync20\FieldMapping;
    use GlpiPlugin\Assetsync20\GlpiBConnection;
    use GlpiPlugin\Assetsync20\RemoteRequestFailure;

    if (!function_exists('curl_init')) {
        echo "Remote catalog pagination tests skipped (cURL unavailable).\n";
        exit(0);
    }

    final class CatalogCurl
    {
        public static array $steps = [];
        public static array $methods = [];
        public static int $executions = 0;
        public static int $closed = 0;
    }
    $checks = 0;
    $connection = ['id' => 'catalog', 'base_url' => 'https://private.invalid', 'app_token' => 'private-app', 'user_token' => 'private-user'];

    function catalogCheck(bool $condition, string $message): void
    {
        global $checks;
        $checks++;
        if (!$condition) { throw new RuntimeException($message); }
    }

    function catalogStep(string $path, $body = [], int $status = 200, array $headers = []): array
    {
        return ['path' => $path, 'body' => is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body, 'status' => $status, 'headers' => $headers];
    }

    function catalogPage(int $start, int $length, int $total, string $path = 'Catalog', int $status = 206): array
    {
        $rows = [];
        for ($id = $start + 1; $id <= $start + $length; $id++) { $rows[] = ['id' => $id, 'name' => 'Label ' . $id]; }
        return catalogStep($path, $rows, $status, ['cOnTeNt-RaNgE: ' . $start . '-' . ($start + $length - 1) . '/' . $total]) + ['range' => $start . '-' . ($start + 999)];
    }

    function catalogStart(array $steps): void
    {
        CatalogCurl::$steps = $steps;
        CatalogCurl::$methods = [];
        CatalogCurl::$executions = CatalogCurl::$closed = 0;
        GlpiBConnection::beginHttpMetrics();
        GlpiBConnection::setHttpMetricsConnection('catalog');
    }

    function catalogFinish(int $requests, int $errors = 0): void
    {
        catalogCheck(CatalogCurl::$steps === [] && CatalogCurl::$executions === $requests && CatalogCurl::$closed === $requests, 'All and only expected curl_exec calls must occur and close.');
        $metrics = GlpiBConnection::finishHttpMetrics();
        catalogCheck($metrics['catalog']['requests'] === $requests && $metrics['catalog']['errors'] === $errors, 'Catalog execution/error counters must remain exact, including cleanup.');
        catalogCheck(!str_contains(json_encode($metrics), 'private') && array_keys($metrics['catalog']) === ['requests', 'errors', 'latency_ms'], 'Pagination proof must never enter HTTP metrics or expose secrets.');
    }

    function catalogRead(string $endpoint = 'Catalog'): array
    {
        global $connection;
        return (new ReflectionMethod(GlpiBConnection::class, 'remoteCollectionRows'))->invoke(null, $connection, [], $endpoint);
    }

    function catalogFails(array $steps, string $message, bool $transient = false, int $errors = 0): void
    {
        catalogStart($steps);
        try {
            catalogRead();
            throw new LogicException('Incomplete catalog was returned.');
        } catch (RuntimeException $error) {
            catalogCheck(str_contains($error->getMessage(), $message), 'Catalog failure must name the actual incompleteness.');
            catalogCheck($transient ? $error instanceof RemoteRequestFailure && $error->result['transient'] : !($error instanceof RemoteRequestFailure) || !$error->result['transient'], 'Catalog failure must preserve its retry classification.');
        }
        catalogFinish(count($steps), $errors);
    }

    catalogStart([catalogStep('Catalog')]);
    catalogCheck(catalogRead() === [], '200 [] without a range is proof of an empty catalog.');
    catalogFinish(1);
    foreach ([999, 1000, 1001, 10000] as $count) {
        $steps = [];
        for ($offset = 0; $offset < $count; $offset += 1000) { $steps[] = catalogPage($offset, min(1000, $count - $offset), $count); }
        catalogStart($steps);
        $rows = catalogRead();
        catalogCheck(count($rows) === $count && array_column($rows, 'id') === range(1, $count), 'A proven catalog must include every ID, including a final 206 page.');
        catalogFinish(count($steps));
    }
    catalogStart([catalogPage(0, 2, 5), catalogPage(2, 2, 5), catalogPage(4, 1, 5)]);
    catalogCheck(array_column(catalogRead(), 'id') === [1, 2, 3, 4, 5], 'Smaller server pages must resume after the actual returned end, not the requested end.');
    catalogFinish(3);

    $step = catalogPage(0, 1, 1, 'Catalog', 200);
    $step['headers'] = ['HTTP/1.1 100 Continue', 'Content-Range: 0-999/1001', 'HTTP/2 200', 'CONTENT-RANGE: 0-0/1', 'Accept-Range: Catalog 1000'];
    catalogStart([$step]);
    catalogCheck(count(catalogRead()) === 1, 'Only case-insensitive final response headers may prove completeness; Accept-Range is not a total.');
    catalogFinish(1);

    foreach (['{malformed', '{}', 'null', '"text"', '{"data":[]}'] as $body) {
        catalogFails([catalogStep('Catalog', $body)], 'JSON row list');
    }
    catalogFails([catalogStep('Catalog', [['id' => 1]], 200, ['Accept-Range: Catalog 1000'])], 'Content-Range');
    foreach (['items 0-0/1', '0-0/*', '1-1/2', '0-2/3', '0-0/0', '0-1/1', '-1-0/1', '0-0/10001'] as $range) {
        catalogFails([catalogStep('Catalog', [['id' => 1]], 206, ['Content-Range: ' . $range])], $range === '0-0/10001' ? '10000-row limit' : (in_array($range, ['items 0-0/1', '0-0/*', '-1-0/1'], true) ? 'Content-Range' : 'range or row count'));
    }
    catalogFails([catalogStep('Catalog', [['id' => 1]], 206, ['Content-Range: 0-0/1', 'content-range: 0-0/1'])], 'Content-Range');
    catalogFails([catalogStep('Catalog', [], 206)], 'Content-Range');
    catalogFails([catalogStep('Catalog', [], 200, ['Content-Range: 0-0/1'])], 'range or row count');
    foreach ([[['id' => 0]], [['id' => 'bad']], [['name' => 'missing']], [1], [['id' => 2], ['id' => 1]], [['id' => 1], ['id' => 1]]] as $rows) {
        catalogFails([catalogStep('Catalog', $rows, 206, ['Content-Range: 0-' . (count($rows) - 1) . '/' . count($rows)])], 'unique and ascending');
    }
    $repeat = catalogPage(2, 1, 3);
    $repeat['body'] = '[{"id":2}]';
    catalogFails([catalogPage(0, 2, 3), $repeat], 'unique and ascending');
    $gap = catalogPage(1, 1, 3);
    $gap['range'] = '2-1001';
    catalogFails([catalogPage(0, 2, 3), $gap], 'range or row count');
    catalogFails([catalogPage(0, 2, 3), catalogPage(2, 1, 4)], 'changed during pagination', true);
    $emptyLater = catalogStep('Catalog');
    $emptyLater['range'] = '2-1001';
    catalogFails([catalogPage(0, 2, 3), $emptyLater], 'Content-Range');
    $ten = [];
    for ($offset = 0; $offset < 10; $offset++) { $ten[] = catalogPage($offset, 1, 11); }
    catalogFails($ten, '10-page limit');
    foreach ([429, 503, 403, 400] as $status) {
        catalogFails([catalogPage(0, 2, 3), catalogStep('Catalog', ['ERROR_UNSUPPORTED'], $status)], 'HTTP ' . $status, in_array($status, [429, 503], true), 1);
    }
    catalogFails([catalogPage(0, 2, 3), catalogStep('Catalog', ['ERROR_RANGE_EXCEED_TOTAL'], 400)], 'shrank during pagination', true, 1);
    catalogFails([catalogStep('Catalog', ['ERROR_RANGE_EXCEED_TOTAL'], 400)], 'HTTP 400', false, 1);
    catalogFails([catalogPage(0, 2, 3), catalogStep('Catalog', false, 0)], 'HTTP request failed', true, 1);

    $metadata = ['key' => 'dropdown', 'type' => 'dropdown', 'dropdown_class' => 'Catalog'];
    $writeValue = new ReflectionMethod(GlpiBConnection::class, 'customWriteValue');
    $readValue = new ReflectionMethod(GlpiBConnection::class, 'customReadValue');
    catalogStart([catalogPage(0, 1000, 1001), catalogPage(1000, 1, 1001)]);
    catalogCheck($writeValue->invoke(null, $connection, [], $metadata, 'Label 1001') === 1001, 'Destination label on page two must resolve successfully.');
    catalogFinish(2);
    catalogStart([catalogPage(0, 1000, 1001), catalogPage(1000, 1, 1001)]);
    catalogCheck($readValue->invoke(null, $connection, [], $metadata, 1001) === 'Label 1001', 'Reading an ID on page two must use the same complete catalog.');
    catalogFinish(2);
    $duplicate = catalogPage(1000, 1, 1001);
    $duplicate['body'] = '[{"id":1001,"name":"Label 1"}]';
    catalogStart([catalogPage(0, 1000, 1001), $duplicate]);
    try {
        $writeValue->invoke(null, $connection, [], $metadata, 'Label 1');
        throw new LogicException('Cross-page duplicate label authorized a write.');
    } catch (RuntimeException $error) { catalogCheck(str_contains($error->getMessage(), 'duplicated'), 'Duplicate label across pages must block, even when page one matched.'); }
    catalogFinish(2);

    $profilePath = 'PluginFieldsContainer/1/PluginFieldsProfile';
    $profile = catalogPage(0, 1000, 1001, $profilePath);
    $profile['body'] = json_encode(array_map(static fn (int $id): array => ['id' => $id, 'profiles_id' => 5, 'plugin_fields_containers_id' => 1, 'right' => 4], range(1, 1000)));
    $grant = catalogPage(1000, 1, 1001, $profilePath);
    $grant['body'] = '[{"id":1001,"profiles_id":4,"plugin_fields_containers_id":1,"right":4}]';
    $responses = [
        'getActiveProfile' => ['active_profile' => ['id' => 4, 'computer' => 3]],
        'PluginFieldsContainer/1' => ['id' => 1, 'is_active' => 1, 'itemtypes' => '["Computer"]', 'entities_id' => 0, 'is_recursive' => 0],
        'Computer/2' => ['id' => 2, 'entities_id' => 0],
    ];
    $guard = new ReflectionMethod(GlpiBConnection::class, 'assertCustomContainerAccess');
    $read = static fn (string $path): array => $responses[$path];
    $readCollection = static fn (string $path): array => catalogRead($path);
    foreach ([true, false] as $writing) {
        catalogStart([$profile, $grant]);
        $guard->invoke(null, 'Computer', 2, 1, $writing, $read, $readCollection);
        catalogFinish(2);
    }
    $maxProfile = $profile;
    $rows = json_decode($maxProfile['body'], true);
    $rows[0]['profiles_id'] = 4;
    $rows[0]['right'] = 1;
    $maxProfile['body'] = json_encode($rows);
    catalogStart([$maxProfile, $grant]);
    $guard->invoke(null, 'Computer', 2, 1, true, $read, $readCollection);
    catalogFinish(2);
    foreach ([['profiles_id' => 4, 'plugin_fields_containers_id' => 1, 'right' => 1], ['profiles_id' => 5, 'plugin_fields_containers_id' => 1, 'right' => 4], ['profiles_id' => 4, 'plugin_fields_containers_id' => 2, 'right' => 4]] as $denial) {
        $last = $grant;
        $last['body'] = json_encode([['id' => 1001] + $denial]);
        catalogStart([$profile, $last]);
        try {
            $guard->invoke(null, 'Computer', 2, 1, true, $read, $readCollection);
            throw new LogicException('Denied profile authorized a write.');
        } catch (RuntimeException $error) { catalogCheck(str_contains($error->getMessage(), 'container permission denied'), 'Full catalog must preserve profile/container/max-right denials.'); }
        catalogFinish(2);
    }

    // Exercise the actual public callback chain, a successful write/readback, and transient failures.
    $key = 'Computer.PluginFieldsComputerdmosasset.namefield';
    $init = catalogStep('initSession', ['session_token' => 'private-session']);
    $kill = catalogStep('killSession');
    $options = catalogStep('listSearchOptions/Computer', [10 => ['table' => 'glpi_plugin_fields_computerdmosassets', 'field' => 'namefield', 'pfields_type' => 'text', 'pfields_fields_id' => 1]]);
    $definition = catalogStep('PluginFieldsField/1', ['id' => 1, 'name' => 'namefield', 'type' => 'text', 'is_active' => 1, 'plugin_fields_containers_id' => 1]);
    $active = catalogStep('getActiveProfile', $responses['getActiveProfile']);
    $container = catalogStep('PluginFieldsContainer/1', $responses['PluginFieldsContainer/1']);
    $asset = catalogStep('Computer/2', $responses['Computer/2']);
    $child = ['id' => 10, 'items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1, 'namefield' => 'old'];
    $row = catalogStep('Computer/2/PluginFieldsComputerdmosasset', [$child]);
    $put = catalogStep('PluginFieldsComputerdmosasset/10') + ['method' => 'PUT', 'input' => ['namefield' => 'new']];
    $child['namefield'] = 'new';
    $verified = catalogStep('PluginFieldsComputerdmosasset/10', $child);
    $call = new ReflectionMethod(AssetSyncService::class, 'callRemote');
    catalogStart([$init, $options, $definition, $active, $container, $maxProfile, $grant, $asset, $row, $put, $verified, $kill]);
    $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key], [$key => 'new']]);
    catalogCheck($result['success'] && $result['item'][$key] === 'new' && count(array_filter(CatalogCurl::$methods, static fn (string $method): bool => $method === 'PUT')) === 1, 'A page-two grant must permit exactly one verified mapped write.');
    catalogFinish(12);

    $dropdownKey = 'Computer.PluginFieldsComputerdmosasset.plugin_fields_departmentfielddropdowns_id';
    $dropdownOptions = catalogStep('listSearchOptions/Computer', [10 => [
        'table' => 'glpi_plugin_fields_departmentfielddropdowns', 'field' => 'completename',
        'linkfield' => 'plugin_fields_departmentfielddropdowns_id', 'pfields_type' => 'dropdown', 'pfields_fields_id' => 8,
        'joinparams' => ['beforejoin' => ['table' => 'glpi_plugin_fields_computerdmosassets']],
    ]]);
    $dropdownDefinition = catalogStep('PluginFieldsField/8', ['id' => 8, 'name' => 'departmentfield', 'type' => 'dropdown', 'is_active' => 1, 'plugin_fields_containers_id' => 1]);
    $dropdownChild = ['id' => 10, 'items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1, 'plugin_fields_departmentfielddropdowns_id' => 0];
    $dropdownRow = catalogStep('Computer/2/PluginFieldsComputerdmosasset', [$dropdownChild]);
    $dropdownPut = catalogStep('PluginFieldsComputerdmosasset/10') + ['method' => 'PUT', 'input' => ['plugin_fields_departmentfielddropdowns_id' => 1001]];
    $dropdownChild['plugin_fields_departmentfielddropdowns_id'] = 1001;
    $dropdownVerified = catalogStep('PluginFieldsComputerdmosasset/10', $dropdownChild);
    $dropdownFirst = catalogPage(0, 1000, 1001, 'PluginFieldsDepartmentfieldDropdown');
    $dropdownLast = catalogPage(1000, 1, 1001, 'PluginFieldsDepartmentfieldDropdown');
    $prefix = [$init, $dropdownOptions, $dropdownDefinition, $active, $container, $profile, $grant, $asset, $dropdownRow];
    catalogStart([...$prefix, $dropdownFirst, $dropdownLast, $dropdownPut, $dropdownVerified, $dropdownFirst, $dropdownLast, $kill]);
    $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$dropdownKey], [$dropdownKey => 'Label 1001'], [$dropdownKey => 'dropdown']]);
    catalogCheck($result['success'] && $result['item'][$dropdownKey] === 'Label 1001', 'Page-two dropdown destination must be written and read back through fresh complete catalogs.');
    catalogFinish(16);
    $duplicate['path'] = 'PluginFieldsDepartmentfieldDropdown';
    catalogStart([...$prefix, $dropdownFirst, $duplicate, $kill]);
    $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$dropdownKey], [$dropdownKey => 'Label 1'], [$dropdownKey => 'dropdown']]);
    catalogCheck(!$result['success'] && !$result['transient'] && str_contains($result['message'], 'duplicated') && !in_array('PUT', CatalogCurl::$methods, true), 'A cross-page duplicate must block before the actual dropdown write.');
    catalogFinish(12);
    catalogStart([...$prefix, $dropdownFirst, catalogStep('PluginFieldsDepartmentfieldDropdown', [], 503), $kill]);
    $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$dropdownKey], [$dropdownKey => 'Label 1'], [$dropdownKey => 'dropdown']]);
    catalogCheck(!$result['success'] && $result['transient'] && $result['status_code'] === 503 && !in_array('PUT', CatalogCurl::$methods, true), 'A matching first-page label must not authorize a write when a later dropdown page fails; transient status must survive.');
    catalogFinish(12, 1);

    foreach (['transport', '429', '503', '403', 'moving', 'shrunk', 'malformed', 'cap'] as $failure) {
        $failed = match ($failure) {
            'transport' => catalogStep($profilePath, false, 0),
            '429', '503', '403' => catalogStep($profilePath, [], (int) $failure),
            'moving' => catalogPage(1000, 1, 1002, $profilePath),
            'shrunk' => catalogStep($profilePath, ['ERROR_RANGE_EXCEED_TOTAL'], 400),
            'malformed' => catalogStep($profilePath, '{bad', 206, ['Content-Range: 1000-1000/1001']),
            'cap' => catalogPage(1000, 1, 10001, $profilePath),
        };
        $steps = [$init, $options, $definition, $active, $container, $profile, $failed, $kill];
        if ($failure === 'cap') {
            $overCap = $profile;
            $overCap['headers'] = ['Content-Range: 0-999/10001'];
            $steps = [$init, $options, $definition, $active, $container, $overCap, $kill];
        }
        catalogStart($steps);
        $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key], [$key => 'new']]);
        $transient = in_array($failure, ['transport', '429', '503', 'moving', 'shrunk'], true);
        catalogCheck(!$result['success'] && $result['transient'] === $transient && !in_array('PUT', CatalogCurl::$methods, true) && !in_array('POST', CatalogCurl::$methods, true), 'Incomplete permission catalogs must never authorize mutations; retry classification must survive callbacks: ' . $failure);
        if (in_array($failure, ['transport', '429', '503', '403'], true)) {
            catalogCheck($result['status_code'] === ($failure === 'transport' ? 0 : (int) $failure), 'Original failed HTTP status must survive callRemote.');
        }
        if ($failure === 'shrunk' || $failure === 'moving') {
            catalogCheck($result['cause'] === 'catalog_changed' && $result['status_code'] === ($failure === 'shrunk' ? 400 : 206), 'Moving catalog cause and actual status must survive callback propagation.');
        }
        catalogFinish(count($steps), in_array($failure, ['transport', '429', '503', '403', 'shrunk'], true) ? 1 : 0);
        $GLOBALS['DB'] = new FakeDB();
        AssetSyncQueue::install();
        AssetSyncLink::install();
        AssetSyncQueue::enqueue('Computer', 2, 'catalog', 'route', 'hash', null);
        $job = AssetSyncQueue::claimDue(1)[0];
        (new ReflectionMethod(AssetSyncService::class, 'handleRemoteFailure'))->invoke(new AssetSyncService(), $job, 'route', $result, 1, null);
        catalogCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] === ($transient ? 'retry' : 'blocked'), 'Catalog failures must produce actual retry/block queue transitions, without replaying writes.');
    }

    // Single-item permission reads also retain transport/status failures across their throwing callback.
    catalogStart([$init, $options, $definition, catalogStep('getActiveProfile', [], 503), $kill]);
    $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key]]);
    catalogCheck(!$result['success'] && $result['transient'] && $result['status_code'] === 503, 'Permission callback must retain a transient HTTP failure, not turn it into a permanent RuntimeException.');
    catalogFinish(5, 1);

    $singleGrant = catalogPage(0, 1, 1, $profilePath);
    $singleGrant['body'] = '[{"id":1,"profiles_id":4,"plugin_fields_containers_id":1,"right":4}]';
    foreach (['getActiveProfile', 'PluginFieldsContainer/1', 'Computer/2', 'Entity/7'] as $endpoint) {
        foreach ([429, 503, 403, 0] as $status) {
            $permissionSteps = [$init, $options, $definition, $active, $container, $singleGrant, $asset];
            if ($endpoint === 'Entity/7') {
                $recursive = $responses['PluginFieldsContainer/1'];
                $recursive['is_recursive'] = 1;
                $permissionSteps[4] = catalogStep('PluginFieldsContainer/1', $recursive);
                $permissionSteps[6] = catalogStep('Computer/2', ['id' => 2, 'entities_id' => 7]);
                $permissionSteps[] = catalogStep('Entity/7', ['id' => 7, 'entities_id' => 0]);
            }
            $steps = [];
            foreach ($permissionSteps as $step) {
                if ($step['path'] === $endpoint) {
                    $steps[] = catalogStep($endpoint, $status === 0 ? false : [], $status);
                    break;
                }
                $steps[] = $step;
            }
            $steps[] = $kill;
            catalogStart($steps);
            $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key], [$key => 'new']]);
            catalogCheck(!$result['success'] && $result['transient'] === ($status !== 403) && $result['status_code'] === $status && $result['cause'] === ($status === 0 ? 'transport' : 'http') && $result['executed'], 'Every noncollection permission reader must preserve executed transport/429/503/403 classification: ' . $endpoint);
            catalogCheck(!in_array('PUT', CatalogCurl::$methods, true) && !in_array('POST', CatalogCurl::$methods, true), 'Failed permission reads must prevent all mutations.');
            catalogFinish(count($steps), 1);
        }
    }
    foreach ([429, 503, 403, 0] as $status) {
        catalogStart([...$prefix, $dropdownFirst, catalogStep('PluginFieldsDepartmentfieldDropdown', $status === 0 ? false : [], $status), $kill]);
        $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$dropdownKey], [$dropdownKey => 'Label 1'], [$dropdownKey => 'dropdown']]);
        catalogCheck(!$result['success'] && $result['transient'] === ($status !== 403) && $result['status_code'] === $status && $result['cause'] === ($status === 0 ? 'transport' : 'http') && $result['executed'] && !in_array('PUT', CatalogCurl::$methods, true), 'Later dropdown failures must retain status/cause/execution and prevent writes even after a first-page match.');
        catalogFinish(12, 1);
    }

    // Same-container changes share a write; missing child rows still use one POST/readback.
    $secondKey = 'Computer.PluginFieldsComputerdmosasset.notesfield';
    $sameOptions = json_decode($options['body'], true);
    $sameOptions[11] = ['table' => 'glpi_plugin_fields_computerdmosassets', 'field' => 'notesfield', 'pfields_type' => 'text', 'pfields_fields_id' => 2];
    $secondDefinition = catalogStep('PluginFieldsField/2', ['id' => 2, 'name' => 'notesfield', 'type' => 'text', 'is_active' => 1, 'plugin_fields_containers_id' => 1]);
    $changes = [$key => 'new', $secondKey => 'new notes'];
    $written = ['namefield' => 'new', 'notesfield' => 'new notes'];
    foreach ([false, true] as $missing) {
        $before = ['id' => 10, 'items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1, 'namefield' => 'old', 'notesfield' => 'old notes'];
        $after = array_replace($before, $written);
        $mutation = $missing
            ? catalogStep('PluginFieldsComputerdmosasset', ['id' => 10], 201) + ['method' => 'POST', 'input' => $written + ['items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1]]
            : catalogStep('PluginFieldsComputerdmosasset/10') + ['method' => 'PUT', 'input' => $written];
        catalogStart([$init, catalogStep('listSearchOptions/Computer', $sameOptions), $definition, $secondDefinition,
            $active, $container, $singleGrant, $asset, catalogStep('Computer/2/PluginFieldsComputerdmosasset', $missing ? [] : [$before]),
            $mutation, catalogStep('PluginFieldsComputerdmosasset/10', $after), $kill]);
        $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, array_keys($changes), $changes, array_fill_keys(array_keys($changes), 'text'), true]);
        catalogCheck($result['success'] && $result['item'] === $changes && count(array_filter(CatalogCurl::$methods, static fn (string $method): bool => in_array($method, ['POST', 'PUT'], true))) === 1, 'Multiple changed fields in one container must share exactly one verified mutation.');
        catalogFinish(12);
    }

    // A second generated container repeats fresh permission reads and has its own verified write.
    FakeItemTypes::$classResult = static fn (string $table): string => $table === 'glpi_plugin_fields_computerotherassets' ? 'PluginFieldsComputerotherasset' : 'PluginFieldsComputerdmosasset';
    FakeItemTypes::$tableResult = static fn (string $class): string => $class === 'PluginFieldsComputerotherasset' ? 'glpi_plugin_fields_computerotherassets' : 'glpi_plugin_fields_computerdmosassets';
    $otherKey = 'Computer.PluginFieldsComputerotherasset.notesfield';
    $otherOptions = $sameOptions;
    $otherOptions[11]['table'] = 'glpi_plugin_fields_computerotherassets';
    $otherDefinition = catalogStep('PluginFieldsField/2', ['id' => 2, 'name' => 'notesfield', 'type' => 'text', 'is_active' => 1, 'plugin_fields_containers_id' => 2]);
    $otherContainer = $responses['PluginFieldsContainer/1'];
    $otherContainer['id'] = 2;
    $otherGrant = catalogPage(0, 1, 1, 'PluginFieldsContainer/2/PluginFieldsProfile');
    $otherGrant['body'] = '[{"id":2,"profiles_id":4,"plugin_fields_containers_id":2,"right":4}]';
    $otherRow = ['id' => 20, 'items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 2, 'notesfield' => 'old notes'];
    $otherAfter = array_replace($otherRow, ['notesfield' => 'new notes']);
    catalogStart([$init, catalogStep('listSearchOptions/Computer', $otherOptions), $definition, $otherDefinition,
        $active, $container, $singleGrant, $asset, $row, $put, $verified,
        $active, catalogStep('PluginFieldsContainer/2', $otherContainer), $otherGrant, $asset,
        catalogStep('Computer/2/PluginFieldsComputerotherasset', [$otherRow]),
        catalogStep('PluginFieldsComputerotherasset/20') + ['method' => 'PUT', 'input' => ['notesfield' => 'new notes']],
        catalogStep('PluginFieldsComputerotherasset/20', $otherAfter), $kill]);
    $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key, $otherKey], [$key => 'new', $otherKey => 'new notes'], [$key => 'text', $otherKey => 'text'], true]);
    catalogCheck($result['success'] && $result['item'] === [$key => 'new', $otherKey => 'new notes'] && count(array_filter(CatalogCurl::$methods, static fn (string $method): bool => $method === 'PUT')) === 2, 'Multiple changed containers must retain independent permission checks and readback per write.');
    catalogFinish(19);
    FakeItemTypes::$classResult = FakeItemTypes::$tableResult = null;

    // Field definitions and persisted values remain fresh on every operation, without option caching.
    foreach (['type', 'inactive', 'readonly', 'persist'] as $failure) {
        catalogStart([$init, $options, $definition, $active, $container, $singleGrant, $asset, $row, $kill]);
        $initial = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key]]);
        catalogCheck($initial['success'], 'Initial mapped-field read must succeed before changing destination state.');
        catalogFinish(9);
        $changedDefinition = json_decode($definition['body'], true);
        if ($failure === 'type') { $changedDefinition['type'] = 'number'; }
        if ($failure === 'inactive') { $changedDefinition['is_active'] = 0; }
        if ($failure === 'readonly') { $changedDefinition['is_readonly'] = 1; }
        $steps = [$init, $options, catalogStep('PluginFieldsField/1', $changedDefinition)];
        if ($failure === 'persist') {
            $steps = [...$steps, $active, $container, $singleGrant, $asset, $row, $put,
                catalogStep('PluginFieldsComputerdmosasset/10', array_replace($child, ['namefield' => 'old']))];
        }
        $steps[] = $kill;
        catalogStart($steps);
        $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key], [$key => 'new']]);
        catalogCheck(!$result['success'] && !$result['transient'], 'Changed destination type/active/readonly or failed persistence must still block: ' . $failure);
        catalogCheck(count(array_filter(CatalogCurl::$methods, static fn (string $method): bool => $method === 'PUT')) === ($failure === 'persist' ? 1 : 0), 'Validation failures must not write, and failed persistence must not replay a PUT.');
        catalogFinish(count($steps));
        if ($failure === 'readonly') {
            catalogStart([$init, $options, catalogStep('PluginFieldsField/1', $changedDefinition),
                $active, $container, $singleGrant, $asset, $row, $put, $verified, $kill]);
            $result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$key], [$key => 'new'], [$key => 'text'], true]);
            catalogCheck($result['success'] && $result['item'][$key] === 'new', 'Explicit allowReadonly true must still permit A-owned derived writes with fresh type/active/permission/persistence checks.');
            catalogFinish(11);
        }
    }

    // One-way jobs cannot depend on getFullSession permission; Both failures still retry after cleanup.
    foreach ([429, 503, null] as $status) {
        $GLOBALS['DB'] = new FakeDB();
        Config::$values = [];
        AssetSyncQueue::install();
        AssetSyncLink::install();
        GlpiBConnection::save($connection + ['active' => true]);
        EntitySyncRoute::save(['id' => 'both-timezone', 'glpi_b_connection_id' => 'catalog',
            'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
        FieldMapping::save('catalog', 'Computer', [1 => ['glpi_b_field_key' => 'name', 'source_of_truth' => $status === null ? 'glpi_a' : 'both']]);
        $GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
        $GLOBALS['DB']->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0,
            'serial' => 'BOTH-TIMEZONE', 'name' => 'Local Both value', 'date_mod' => '2026-10-01 00:00:00']);
        AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'catalog',
            'route_id' => 'both-timezone', 'remote_items_id' => 12, 'status' => AssetSyncLink::STATUS_SYNCED]);
        $service = new AssetSyncService();
        catalogCheck($service->queueAssetIfNeeded('Computer', 101, 'catalog'), 'Timezone fixture must enqueue.');
        if ($status === null) {
            catalogStart([$init, catalogStep('Computer/12', ['id' => 12, 'name' => 'Local Both value',
                'serial' => 'BOTH-TIMEZONE', 'entities_id' => 100, 'date_mod' => '2026-10-01 00:00:00']), $kill]);
            catalogCheck($service->processQueue(1) === 1 && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] === 'done',
                'A one-way job must complete using item data alone, even when getFullSession would return403; any unexpected timezone GET fails this strict transport.');
            catalogFinish(3);
        } else {
            catalogStart([$init, catalogStep('getFullSession', [], $status), $kill]);
            catalogCheck($service->processQueue(1) === 1 && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] === 'retry'
                && !in_array('PUT', CatalogCurl::$methods, true), 'A Both timezone 429/503 must retry without mutation and still kill the session.');
            catalogFinish(3, 1);
        }
    }

    echo "Remote catalog pagination tests passed ($checks checks).\n";
}
