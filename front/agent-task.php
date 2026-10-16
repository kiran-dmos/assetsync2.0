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
    $request = AgentTask::decode((string) file_get_contents('php://input', false, null, 0, 65537));
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
