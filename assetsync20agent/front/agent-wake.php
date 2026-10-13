<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20agent\Wake;

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
    if (!empty($_SERVER['QUERY_STRING']) || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024
        || strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') { throw new InvalidArgumentException(); }
    $body = (string) file_get_contents('php://input', false, null, 0, 1025);
    if (strlen($body) > 1024) { throw new InvalidArgumentException(); }
    $request = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request)) { throw new InvalidArgumentException(); }
    $token = preg_match('/^Bearer ([a-f0-9]{64})$/D', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $match) ? $match[1] : '';
    $response = Wake::receive($request, $token);
    http_response_code($response['status']); unset($response['status']);
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException) {
    http_response_code(400); echo '{"error":"Invalid wake request"}';
} catch (UnexpectedValueException) {
    http_response_code(401); echo '{"error":"Wake unauthorized"}';
} catch (Throwable $error) {
    http_response_code($error->getCode() === 429 ? 429 : 503); echo '{"error":"Wake unavailable; durable work retained"}';
}
