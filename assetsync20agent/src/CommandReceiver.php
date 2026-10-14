<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Stateless, narrow command endpoint for approved Computer fields. */
final class CommandReceiver
{
    public static function handle(): void
    {
        header('Content-Type: application/json');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { self::reply(405, ['success' => false, 'message' => 'POST required.']); return; }
        $settings = ExecutionSettings::load();
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/\ABearer ([a-f0-9]{64})\z/i', $auth, $match)
            || !hash_equals((string) $settings['command_token'], strtolower($match[1]))) {
            self::reply(401, ['success' => false, 'message' => 'Unauthorised command.']); return;
        }
        try { $command = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { self::reply(400, ['success' => false, 'message' => 'Invalid JSON command.']); return; }
        $id = (int) ($command['remote_id'] ?? 0);
        $input = $command['input'] ?? null;
        $expectedBefore = $command['expected_before'] ?? null;
        // Backward-compatible with the first Comment-only command format.
        if (is_string($expectedBefore) && is_array($input) && array_keys($input) === ['comment']) {
            $expectedBefore = ['comment' => $expectedBefore];
        }
        if (($command['itemtype'] ?? '') !== 'Computer' || $id <= 0 || !is_array($input)
            || $input === [] || !is_array($expectedBefore) && $expectedBefore !== null) {
            self::reply(422, ['success' => false, 'message' => 'Invalid authorised Computer command.']); return;
        }
        try {
            $result = InternalGlpiExecutor::updateComputerFields(
                (int) ($settings['service_user_id'] ?? 0),
                $id,
                $input,
                $expectedBefore,
            );
            self::reply(200, ['success' => true, 'command_id' => (string) ($command['command_id'] ?? '')] + $result);
        } catch (StaleTargetException $error) {
            self::reply(409, ['success' => false, 'message' => $error->getMessage(), 'before' => $error->before]);
        } catch (\Throwable $error) {
            self::reply(502, ['success' => false, 'message' => $error->getMessage()]);
        }
    }

    private static function reply(int $status, array $body): void { http_response_code($status); echo json_encode($body, JSON_THROW_ON_ERROR); }
}
