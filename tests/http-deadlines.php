<?php

declare(strict_types=1);

$httpMetricsBootstrapOnly = true;
require __DIR__ . '/http-metrics.php';

use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RemoteRequestFailure;

$checks = 0;
$connection = ['id' => 'a', 'base_url' => 'https://private.invalid', 'app_token' => 'private-app', 'user_token' => 'private-user'];
function deadlineCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function deadlineCall(string $method, array $arguments, ?int $deadlineNs): array
{
    global $connection;
    $service = new AssetSyncService();
    (new ReflectionProperty(AssetSyncService::class, 'phaseDeadlineNs'))->setValue($service, $deadlineNs);
    return (new ReflectionMethod(AssetSyncService::class, 'callRemote'))->invoke($service, $method, [$connection, ...$arguments]);
}
function deadlineFinish(int $requests, int $errors, float $latency, array $timeouts): void
{
    httpFinish('a', $requests, $errors, $latency);
    deadlineCheck(array_column(array_column(HttpMetricsCurl::$trace, 'options'), CURLOPT_TIMEOUT_MS) === $timeouts, 'Every execution must receive the exact shrinking millisecond timeout.');
    foreach (HttpMetricsCurl::$trace as $entry) {
        deadlineCheck($entry['options'][CURLOPT_TIMEOUT_MS] > 0 && $entry['options'][CURLOPT_CONNECTTIMEOUT_MS] === $entry['options'][CURLOPT_TIMEOUT_MS], 'Both timeouts must be positive and bounded, never zero/unlimited.');
        deadlineCheck(!isset($entry['options'][CURLOPT_TIMEOUT]), 'Only millisecond timeouts may control requests.');
    }
}

$init = httpStep('initSession', ['session_token' => 'private-session']);
$kill = httpStep('killSession');
$timezone = httpStep('getFullSession', ['glpitimezone' => 'UTC']);
$options = httpStep('listSearchOptions/Computer', [2 => ['field' => 'id'], 5 => ['field' => 'serial']]);

httpStart([$init, httpStep('Computer/12', ['id' => 12]), $kill]);
deadlineCheck(deadlineCall('getItem', ['Computer', 12, false], null)['success'], 'Standalone APIs must retain their existing success semantics.');
deadlineFinish(3, 0, 15.0, [15000, 15000, 15000]);

httpStart([httpStep('initSession', ['session_token' => 's'], 200, 500), httpStep('Computer/12', ['id' => 12], 200, 250), $kill]);
deadlineCheck(deadlineCall('getItem', ['Computer', 12, false], 8_000_000_000)['success'], 'Ordinary requests must share one absolute work cutoff.');
deadlineFinish(3, 0, 755.0, [7000, 6500, 1000]);

// A new session cannot restore the time consumed by an earlier operation.
httpStart([httpStep('initSession', ['session_token' => 's'], 200, 1000), httpStep('Computer/12', ['id' => 12], 200, 1000),
    httpStep('killSession', [], 200, 1000), $init, httpStep('Computer/12', ['id' => 12]), $kill]);
deadlineCheck(deadlineCall('getItem', ['Computer', 12, false], 6_000_000_000)['success'], 'First shared-budget session must succeed.');
deadlineCheck(deadlineCall('getItem', ['Computer', 12, false], 6_000_000_000)['success'], 'Second session must use the same absolute deadline.');
deadlineFinish(6, 0, 3015.0, [5000, 4000, 1000, 2000, 1995, 1000]);

foreach ([0, 999_999, 1_000_000_000, 1_000_999_999] as $deadlineNs) {
    httpStart([]);
    $result = deadlineCall('updateItem', ['Computer', 12, ['name' => 'Changed']], $deadlineNs);
    deadlineCheck(!$result['success'] && $result['transient'] && $result['cause'] === 'budget_deadline' && $result['status_code'] === 0 && !$result['executed'], 'Exhausted reserve/submillisecond work must reject before executing HTTP.');
    deadlineFinish(0, 0, 0.0, []);
}

// Check again after setup and immediately before curl_exec; close unexecuted handles.
foreach (['setupNs', 'finalSetupNs'] as $property) {
    httpStart([]);
    HttpMetricsCurl::$$property = 2_000_000;
    GlpiBConnection::setHttpDeadline(1_002_000_000);
    $result = (new ReflectionMethod(GlpiBConnection::class, 'request'))->invoke(null, 'GET', 'https://private.invalid/ordinary', []);
    deadlineCheck($result['cause'] === 'budget_deadline' && !$result['executed'], 'Setup exhaustion must be checked before curl_exec.');
    httpFinish('a', 0, 0, 0.0, 1);
    GlpiBConnection::setHttpDeadline(null);
}

foreach ([[null, 15000, 'transport'], [30_000_000_000, 15000, 'transport'], [3_000_000_000, 2000, 'budget_deadline'], [3_000_000_000, 5, 'transport']] as [$deadlineNs, $elapsedMs, $cause]) {
    httpStart([httpStep('initSession', false, 0, $elapsedMs) + ['errno' => 28]]);
    $result = deadlineCall('getItem', ['Computer', 12, false], $deadlineNs);
    deadlineCheck(!$result['success'] && $result['transient'] && $result['status_code'] === 0 && $result['cause'] === $cause && $result['executed'], 'Only a budget-limited actual timeout at the shared cutoff is a budget failure.');
    deadlineFinish(1, 1, (float) $elapsedMs, [$deadlineNs === 3_000_000_000 ? 2000 : 15000]);
}
httpStart([httpStep('initSession', false, 0, 2000) + ['errno' => 7]]);
deadlineCheck(deadlineCall('getItem', ['Computer', 12, false], 3_000_000_000)['cause'] === 'transport', 'A refusal must stay transport even at the cutoff.');
deadlineFinish(1, 1, 2000.0, [2000]);

// Pagination shares the work cutoff and must never expose its partial catalog.
$firstPage = httpStep('Catalog', [['id' => 1]], 206, 2000) + ['headers' => ['Content-Range: 0-0/2']];
httpStart([$firstPage]);
GlpiBConnection::setHttpDeadline(3_000_000_000);
GlpiBConnection::setHttpMetricsConnection('a');
try {
    (new ReflectionMethod(GlpiBConnection::class, 'remoteCollectionRows'))->invoke(null, $connection, [], 'Catalog');
    throw new LogicException('Partial catalog escaped deadline validation.');
} catch (RemoteRequestFailure $failure) {
    deadlineCheck($failure->result['cause'] === 'budget_deadline' && !$failure->result['executed'], 'Later catalog pages must preserve a zero-HTTP budget failure.');
} finally {
    GlpiBConnection::setHttpDeadline(null);
}
deadlineFinish(1, 0, 2000.0, [2000]);

// Work consumes the ordinary budget; the reserved second still cleans up.
httpStart([httpStep('initSession', ['session_token' => 's'], 200, 2000), $kill]);
$result = deadlineCall('updateItem', ['Computer', 12, ['name' => 'Changed']], 3_000_000_000);
deadlineCheck($result['cause'] === 'budget_deadline' && !$result['executed'], 'No mutation may start after init consumes the work budget.');
deadlineFinish(2, 0, 2005.0, [2000, 1000]);

httpStart([httpStep('initSession', ['session_token' => 's'], 200, 4000)]);
$result = deadlineCall('updateItem', ['Computer', 12, ['name' => 'Changed']], 3_000_000_000);
deadlineCheck($result['cause'] === 'budget_deadline' && !$result['executed'], 'Gone total budget must skip cleanup too.');
deadlineFinish(1, 0, 4000.0, [2000]);

// Cleanup failures/exceptions do not replay or undo the primary mutation result.
foreach ([httpStep('killSession', [], 503), httpStep('killSession', false, 0) + ['errno' => 7], httpStep('killSession', [], 200, 5, true)] as $cleanup) {
    httpStart([$init, httpStep('Computer/12'), httpStep('Computer/12', ['id' => 12, 'name' => 'Changed']), $cleanup]);
    $result = deadlineCall('updateItem', ['Computer', 12, ['name' => 'Changed']], 5_000_000_000);
    deadlineCheck($result['success'] && !empty($result['cleanup_failure']['executed']), 'Successful PUT must remain successful, with observable cleanup failure.');
    deadlineCheck(count(array_filter(HttpMetricsCurl::$trace, static fn (array $entry): bool => $entry['options'][CURLOPT_CUSTOMREQUEST] === 'PUT')) === 1, 'Mutations must never be automatically replayed.');
    deadlineFinish(4, 1, 20.0, [4000, 3995, 3990, 1000]);
}

// Each failure wrapper must preserve the executed request's status and cause.
$customKey = 'Computer.PluginFieldsComputerdmosasset.note';
foreach ([408, 429, 503, 403, 404] as $status) {
    $cases = [
        ['getItem', ['Computer', 12, false], [httpStep('initSession', [], $status)]],
        ['searchBySerial', ['Computer', 'SERIAL'], [$init, httpStep('listSearchOptions/Computer', [], $status), $kill]],
        ['searchBySerial', ['Computer', 'SERIAL'], [$init, $options, httpStep('search/Computer', [], $status), $kill]],
        ['getItem', ['Computer', 12, false], [$init, httpStep('Computer/12', [], $status), $kill]],
        ['createItem', ['Computer', ['name' => 'New']], [$init, httpStep('Computer', [], $status), $kill]],
        ['updateItem', ['Computer', 12, ['name' => 'Changed']], [$init, httpStep('Computer/12', [], $status), $kill]],
        ['customTextValues', ['Computer', 12, [$customKey]], [$init, httpStep('listSearchOptions/Computer', [], $status), $kill]],
        ['customHistoryDates', ['Computer', 12, [$customKey => ['option_id' => '5', 'itemtype_link' => 'PluginFieldsComputerdmosasset']]], [$init, $timezone, httpStep('Log', [], $status), $kill]],
        ['getItem', ['Computer', 12], [$init, httpStep('getFullSession', [], $status), $kill]],
        ['test', [], [$init, httpStep('getFullSession', [], $status), $kill]],
        ['fetchNativeFields', ['Computer'], [httpStep('initSession', [], $status)]],
        ['fetchNativeFields', ['Computer'], [$init, httpStep('listSearchOptions/Computer', [], $status), $options, $kill]],
        ['fetchNativeFields', ['Computer'], [$init, $options, httpStep('listSearchOptions/Computer', [], $status), $kill]],
    ];
    foreach ($cases as [$method, $arguments, $steps]) {
        httpStart($steps);
        $result = deadlineCall($method, $arguments, 5_000_000_000);
        deadlineCheck(!$result['success'] && $result['status_code'] === $status && $result['cause'] === 'http' && $result['executed'] && $result['transient'] === in_array($status, [408, 429, 503], true), 'All API wrappers must retain exact HTTP failure classification: ' . $method);
        if ($method === 'fetchNativeFields') {
            deadlineCheck($result['fields'] === [], 'Standalone search-option failures must retain the empty fields response key.');
        }
        httpFinish('a', count($steps), 1, (float) count($steps) * 5);
    }
}

httpStart([$init, httpStep('Computer/12', [], 429), httpStep('killSession', [], 503)]);
$result = deadlineCall('getItem', ['Computer', 12, false], 5_000_000_000);
deadlineCheck($result['status_code'] === 429 && $result['cause'] === 'http', 'Cleanup failure must not replace the primary status/cause.');
deadlineFinish(3, 2, 15.0, [4000, 3995, 1000]);

httpStart([$init, $timezone, httpStep('killSession', [], 200, 5, true)]);
deadlineCheck(deadlineCall('test', [], null)['success'], 'Standalone test helper must preserve its primary result after cleanup exceptions.');
deadlineFinish(3, 1, 15.0, [15000, 15000, 15000]);

httpStart([$init, httpStep('listSearchOptions/Computer'), httpStep('killSession', [], 503)]);
$result = deadlineCall('customTextValues', ['Computer', 12, [$customKey]], 5_000_000_000);
deadlineCheck(!$result['success'] && !$result['transient'] && $result['cause'] === '' && !$result['executed'] && str_contains($result['message'], 'unavailable'), 'Semantic callback failure must remain permanent even when cleanup fails.');
deadlineCheck($result['cleanup_failure'] === ['status_code' => 503, 'cause' => 'http', 'executed' => true], 'Cleanup outage metadata must survive a thrown primary semantic failure without tokens/body/message.');
deadlineFinish(3, 1, 15.0, [4000, 3995, 1000]);

httpStart([httpStep('initSession', [])]);
$result = deadlineCall('getItem', ['Computer', 12, false], 5_000_000_000);
deadlineCheck(!$result['success'] && !$result['transient'] && ($result['cause'] ?? '') !== 'transport', 'Missing session token is semantic, not transport.');
deadlineFinish(1, 0, 5.0, [4000]);

httpStart([]);
HttpMetricsCurl::$failInit = true;
$result = deadlineCall('getItem', ['Computer', 12, false], 5_000_000_000);
deadlineCheck(!$result['executed'] && $result['cause'] !== 'transport', 'Handle initialization is setup, not an executed transport outage.');
deadlineFinish(0, 0, 0.0, []);

httpStart([httpStep('initSession', [], 200, 5, true)]);
GlpiBConnection::setHttpDeadline(99_000_000_000);
$result = deadlineCall('getItem', ['Computer', 12, false], 5_000_000_000);
deadlineCheck($result['cause'] === 'transport' && $result['executed'], 'Thrown executed requests must preserve transport classification.');
deadlineCheck(GlpiBConnection::setHttpDeadline(null) === 99_000_000_000, 'callRemote must restore the caller deadline after errors.');
deadlineFinish(1, 1, 5.0, [4000]);

echo "HTTP deadline tests passed ($checks checks).\n";
