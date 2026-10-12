<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\AgentTask;

if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', dirname(__DIR__, 3)); }
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
    http_response_code(403); echo '{"error":"HTTPS required"}'; return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo '{"error":"POST required"}'; return;
}
try {
    if (!empty($_SERVER['QUERY_STRING']) || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536
        || strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
        throw new InvalidArgumentException();
    }
    $body = (string) file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($body) > 65536) { throw new InvalidArgumentException(); }
    $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request)) { throw new InvalidArgumentException(); }
    $keys = ($request['kind'] ?? '') === 'pull' ? ['kind', 'registration', 'generation', 'installation', 'connection_id']
        : ['kind', 'registration', 'generation', 'installation', 'connection_id', 'id', 'hash', 'result'];
    if (($request['kind'] ?? '') === 'pull' && array_key_exists('subscriptions_only', $request)) {
        if (!is_bool($request['subscriptions_only'])) { throw new InvalidArgumentException(); }
        $keys[] = 'subscriptions_only';
    }
    if (($request['kind'] ?? '') === 'pull' && array_key_exists('task_versions', $request)) {
        if (!is_array($request['task_versions']) || !array_is_list($request['task_versions'])
            || count($request['task_versions']) > 5 || array_diff($request['task_versions'], [1, 2, 3, 4, 5]) !== []
            || count(array_filter($request['task_versions'], 'is_int')) !== count($request['task_versions'])) { throw new InvalidArgumentException(); }
        $keys[] = 'task_versions';
    }
    if (array_diff(array_keys($request), $keys) !== [] || array_diff($keys, array_keys($request)) !== []) { throw new InvalidArgumentException(); }
    foreach (['registration', 'generation', 'installation'] as $key) {
        if (!is_string($request[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $request[$key])) { throw new InvalidArgumentException(); }
    }
    if (!is_string($request['connection_id']) || strlen($request['connection_id']) > 255) { throw new InvalidArgumentException(); }
    $token = preg_match('/^Bearer ([a-f0-9]{64})$/D', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $match) ? $match[1] : '';
    $result = AgentTask::exchange($request, $token);
    http_response_code($result['status']); unset($result['status']);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException) {
    http_response_code(400); echo '{"error":"Invalid task request"}';
} catch (UnexpectedValueException) {
    http_response_code(409); echo '{"error":"Task binding, scope or evidence conflict"}';
} catch (Throwable) {
    http_response_code(503); echo '{"error":"Task storage unavailable"}';
}
