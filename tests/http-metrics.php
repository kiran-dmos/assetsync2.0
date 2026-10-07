<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function hrtime(bool $asNumber = false): int
    {
        return \HttpMetricsCurl::$nanoseconds;
    }

    function curl_init(string $url)
    {
        if (\HttpMetricsCurl::$failInit) {
            return false;
        }
        return (object) ['url' => $url, 'status' => 0, 'options' => []];
    }

    function curl_setopt_array(object $curl, array $options): bool
    {
        $curl->options = $options;
        \HttpMetricsCurl::$nanoseconds += \HttpMetricsCurl::$setupNs;
        \HttpMetricsCurl::$setupNs = 0;
        return true;
    }

    function curl_setopt(object $curl, int $option, $value): bool
    {
        $curl->options[$option] = $value;
        if ($option === CURLOPT_CONNECTTIMEOUT_MS) {
            \HttpMetricsCurl::$nanoseconds += \HttpMetricsCurl::$finalSetupNs;
            \HttpMetricsCurl::$finalSetupNs = 0;
        }
        return true;
    }

    function curl_exec(object $curl)
    {
        $step = array_shift(\HttpMetricsCurl::$steps);
        \HttpMetricsCurl::$executions++;
        \HttpMetricsCurl::$trace[] = ['path' => basename((string) parse_url($curl->url, PHP_URL_PATH)), 'options' => $curl->options];
        if ($step === null || !str_ends_with((string) parse_url($curl->url, PHP_URL_PATH), '/' . $step['path'])) {
            throw new \LogicException('Unexpected HTTP execution in scripted transport.');
        }
        \HttpMetricsCurl::$nanoseconds += $step['ms'] * 1_000_000;
        $curl->status = $step['status'];
        $curl->errno = (int) ($step['errno'] ?? 0);
        foreach ($step['headers'] ?? [] as $header) {
            ($curl->options[CURLOPT_HEADERFUNCTION])($curl, $header . "\r\n");
        }
        if (!empty($step['throw'])) {
            throw new \RuntimeException('private-curl-exception');
        }
        return $step['body'];
    }

    function curl_getinfo(object $curl, int $option): int
    {
        return $curl->status;
    }

    function curl_error(object $curl): string
    {
        return 'private-curl-error-with-token';
    }

    function curl_errno(object $curl): int
    {
        return $curl->errno ?? 0;
    }

    function curl_close(object $curl): void
    {
        \HttpMetricsCurl::$closed++;
    }
}

namespace {
    require_once __DIR__ . '/../src/autoload.php';

    use GlpiPlugin\Assetsync20\AssetSyncService;
    use GlpiPlugin\Assetsync20\GlpiBConnection;

    if (!function_exists('curl_init')) {
        echo "HTTP metrics test skipped (cURL unavailable).\n";
        exit(0);
    }

    final class HttpMetricsCurl
    {
        public static array $steps = [];
        public static int $nanoseconds = 0;
        public static int $executions = 0;
        public static int $closed = 0;
        public static bool $failInit = false;
        public static array $trace = [];
        public static int $setupNs = 0;
        public static int $finalSetupNs = 0;
    }

    function httpMetricsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function httpStep(string $path, $body = [], int $status = 200, int $milliseconds = 5, bool $throw = false): array
    {
        return ['path' => $path, 'body' => is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body, 'status' => $status, 'ms' => $milliseconds, 'throw' => $throw];
    }

    function httpCall(string $id, string $method, array $arguments = []): array
    {
        $connection = [
            'id' => $id, 'base_url' => 'https://private.invalid',
            'app_token' => 'private-app-token', 'user_token' => 'private-user-token',
        ];
        $call = new ReflectionMethod(AssetSyncService::class, 'callRemote');
        return $call->invoke(new AssetSyncService(), $method, [$connection, ...$arguments]);
    }

    function httpStart(array $steps): void
    {
        HttpMetricsCurl::$steps = $steps;
        HttpMetricsCurl::$nanoseconds = 0;
        HttpMetricsCurl::$executions = 0;
        HttpMetricsCurl::$closed = 0;
        HttpMetricsCurl::$failInit = false;
        HttpMetricsCurl::$trace = [];
        HttpMetricsCurl::$setupNs = HttpMetricsCurl::$finalSetupNs = 0;
        GlpiBConnection::beginHttpMetrics();
    }

    function httpFinish(string $id, int $requests, int $errors, float $milliseconds, ?int $handles = null): array
    {
        httpMetricsCheck(HttpMetricsCurl::$steps === [], 'Every expected HTTP execution must have occurred.');
        httpMetricsCheck(HttpMetricsCurl::$executions === $requests && HttpMetricsCurl::$closed === ($handles ?? $requests), 'Request counts must match actual curl_exec calls and all initialized handles must close.');
        $metrics = GlpiBConnection::finishHttpMetrics();
        $expected = $requests === 0 ? [] : [$id => ['requests' => $requests, 'errors' => $errors, 'latency_ms' => $milliseconds]];
        httpMetricsCheck($metrics === $expected, 'Exact transport counts, errors and latency must match the script.');
        httpMetricsCheck(GlpiBConnection::finishHttpMetrics() === [], 'Metrics must reset after collection.');
        $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);
        foreach (['private', 'https://', 'App-Token', 'Session-Token', 'Authorization', 'payload'] as $secret) {
            httpMetricsCheck(!str_contains($encoded, $secret), 'HTTP metrics must not contain URL, tokens, headers, errors or payload.');
        }
        return $metrics;
    }

    if (!empty($httpMetricsBootstrapOnly)) {
        return;
    }

    $init = httpStep('initSession', ['session_token' => 'private-session-token']);
    $kill = httpStep('killSession');
    $timezone = httpStep('getFullSession', ['glpitimezone' => 'UTC']);
    $options = httpStep('listSearchOptions/Computer', [2 => ['field' => 'id'], 5 => ['field' => 'serial']]);

    httpStart([$init, $options, httpStep('search/Computer', ['totalcount' => 0, 'data' => []]), $kill,
        $init, $timezone, httpStep('Computer/12', ['id' => 12, 'name' => 'private-payload']), $kill,
        $init, httpStep('Computer', ['id' => 13], 201), httpStep('Computer/13', ['id' => 13, 'name' => 'private-payload']), $kill]);
    httpMetricsCheck(httpCall('a', 'searchBySerial', ['Computer', 'private-serial'])['success'], 'Serial search must succeed.');
    httpMetricsCheck(httpCall('b', 'getItem', ['Computer', 12])['success'], 'Item loading must succeed.');
    httpMetricsCheck(httpCall('a', 'createItem', ['Computer', ['name' => 'private-payload']])['success'], 'Item creation must succeed.');
    httpMetricsCheck(httpCall('a', 'updateItem', ['Computer', 13, []])['success'] && httpCall('b', 'customTextValues', ['Computer', 12, []])['success'], 'No-op operations must remain successful without HTTP.');
    $metrics = GlpiBConnection::finishHttpMetrics();
    httpMetricsCheck($metrics === ['a' => ['requests' => 8, 'errors' => 0, 'latency_ms' => 40.0], 'b' => ['requests' => 4, 'errors' => 0, 'latency_ms' => 20.0]], 'Operations must include init, optional timezone, work, readback and kill calls under the correct connection.');
    httpMetricsCheck(HttpMetricsCurl::$executions === 12 && HttpMetricsCurl::$closed === 12 && HttpMetricsCurl::$steps === [], 'Per-connection totals must reconcile with actual executions.');

    $oneWayItem = ['id' => 12, 'name' => 'One-way item', 'date_mod' => '2026-10-05 12:00:00'];
    httpStart([$init, httpStep('Computer/12', $oneWayItem), $kill]);
    $oneWay = httpCall('a', 'getItem', ['Computer', 12, false]);
    httpMetricsCheck($oneWay['success'] && $oneWay['item'] === $oneWayItem && $oneWay['date_mod_timezone'] === '', 'One-way reads must preserve item/date data without requesting unnecessary timezone permission.');
    httpFinish('a', 3, 0, 15.0);

    httpStart([$init, httpStep('getFullSession', ['glpitimezone' => '0']), httpStep('Computer/12', $oneWayItem), $kill]);
    $unknownTimezone = httpCall('a', 'getItem', ['Computer', 12, true]);
    httpMetricsCheck($unknownTimezone['success'] && $unknownTimezone['item'] === $oneWayItem && $unknownTimezone['date_mod_timezone'] === '', 'A successful unnamed timezone must remain unknown for existing Both conflict handling, not become a transport failure.');
    httpFinish('a', 4, 0, 20.0);

    foreach ([
        [httpStep('initSession', [], 503), 1],
        [httpStep('initSession', false, 503), 1],
        [httpStep('initSession', []), 0],
    ] as [$step, $errors]) {
        httpStart([$step]);
        httpMetricsCheck(!httpCall('a', 'getItem', ['Computer', 12])['success'], 'Initialization failures must retain operation failure.');
        httpFinish('a', 1, $errors, 5.0);
    }

    httpStart([$init, httpStep('getFullSession', [], 503), httpStep('killSession', [], 500)]);
    $failedTimezone = httpCall('a', 'getItem', ['Computer', 12]);
    httpMetricsCheck(!$failedTimezone['success'] && $failedTimezone['transient'] && $failedTimezone['status_code'] === 503, 'Timezone failure must preserve retry/status classification and still execute cleanup, without reading the item.');
    httpFinish('a', 3, 2, 15.0);

    foreach ([429, 503, 403, 0] as $status) {
        foreach (['getItem', 'customHistoryDates'] as $method) {
            httpStart([$init, httpStep('getFullSession', $status === 0 ? false : [], $status), $kill]);
            $arguments = $method === 'getItem'
                ? ['Computer', 12]
                : ['Computer', 12, ['custom' => ['option_id' => '10', 'itemtype_link' => 'PluginFieldsComputerdmosasset']]];
            $result = httpCall('a', $method, $arguments);
            httpMetricsCheck(!$result['success'] && $result['transient'] === ($status !== 403) && $result['status_code'] === $status, 'Timezone HTTP failures must preserve exact status/classification for asset/history reads, with no work request or mutation.');
            httpFinish('a', 3, 1, 15.0);
        }
    }
    httpStart([$init, httpStep('getFullSession', [], 200, 7, true), $kill]);
    httpMetricsCheck(!httpCall('a', 'getItem', ['Computer', 12])['success'], 'A thrown timezone lookup must still execute session cleanup.');
    httpFinish('a', 3, 1, 17.0);

    httpStart([$init, $timezone, httpStep('Computer/12', [], 404), httpStep('killSession', false, 500)]);
    $missing = httpCall('a', 'getItem', ['Computer', 12]);
    httpMetricsCheck(!$missing['success'] && $missing['missing'], 'A missing item must retain its existing classification.');
    httpFinish('a', 4, 2, 20.0);

    httpStart([$init, httpStep('listSearchOptions/Computer'), $kill]);
    httpMetricsCheck(!httpCall('a', 'customTextValues', ['Computer', 12, ['Computer.PluginFieldsComputerdmosasset.missingfield']])['success'], 'Missing custom metadata must still fail.');
    // A semantic failure after 2xx calls is not an HTTP error; cleanup still executes.
    httpFinish('a', 3, 0, 15.0);

    httpStart([$init, $kill]);
    httpMetricsCheck(!httpCall('a', 'createItem', ['Computer', ['name' => "\xB1"]])['success'], 'Payload encoding failure must preserve the operation failure.');
    httpFinish('a', 2, 0, 10.0, 3);

    httpStart([$init, $timezone, httpStep('Computer/12', '{malformed'), $kill]);
    httpMetricsCheck(httpCall('a', 'getItem', ['Computer', 12])['success'], 'Malformed 2xx decoding must retain the existing success semantics.');
    httpFinish('a', 4, 0, 20.0);

    httpStart([]);
    HttpMetricsCurl::$failInit = true;
    httpMetricsCheck(!httpCall('a', 'getItem', ['Computer', 12])['success'], 'Handle initialization failure must retain operation failure.');
    httpFinish('a', 0, 0, 0.0);

    httpStart([]);
    httpMetricsCheck(!httpCall('a', 'getItem', ['Computer', 0])['success'], 'Local input validation must still fail.');
    httpMetricsCheck(!httpCall('a', 'unknownMethod')['success'], 'Missing clients must still fail.');
    httpFinish('a', 0, 0, 0.0);

    httpStart([httpStep('initSession', [], 200, 7, true)]);
    httpMetricsCheck(!httpCall('a', 'getItem', ['Computer', 12])['success'], 'Thrown execution failures must remain operation failures.');
    httpFinish('a', 1, 1, 7.0);

    httpStart([$init, $timezone, httpStep('Computer/12', ['id' => 12]), httpStep('killSession', [], 200, 9, true)]);
    httpMetricsCheck(httpCall('a', 'getItem', ['Computer', 12])['success'], 'Thrown cleanup failures must preserve the successful primary result.');
    httpFinish('a', 4, 1, 24.0);

    // Restore an enclosing connection context even when callRemote catches an exception.
    httpStart([httpStep('initSession', [], 200, 5, true), httpStep('outside')]);
    GlpiBConnection::setHttpMetricsConnection('outer');
    httpCall('inner', 'getItem', ['Computer', 12]);
    $request = new ReflectionMethod(GlpiBConnection::class, 'request');
    $request->invoke(null, 'GET', 'https://private.invalid/outside', []);
    $metrics = GlpiBConnection::finishHttpMetrics();
    httpMetricsCheck($metrics === ['inner' => ['requests' => 1, 'errors' => 1, 'latency_ms' => 5.0], 'outer' => ['requests' => 1, 'errors' => 0, 'latency_ms' => 5.0]], 'Connection attribution must be restored after exceptions.');

    HttpMetricsCurl::$steps = [httpStep('outside')];
    $request->invoke(null, 'GET', 'https://private.invalid/outside', []);
    httpMetricsCheck(GlpiBConnection::finishHttpMetrics() === [], 'Calls outside a run must not leave metrics for the next run.');

    echo "HTTP metrics tests passed.\n";
}
