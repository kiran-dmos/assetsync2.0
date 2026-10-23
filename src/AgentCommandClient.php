<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Sends an A-authorised write to Agent B using the configured GLPI B API identity. */
final class AgentCommandClient
{
    /** Native fields that the first authorised-representative release may mutate. */
    private const COMPUTER_FIELDS = ['entities_id', 'serial', 'name', 'otherserial', 'comment', 'computertypes_id', 'computermodels_id'];

    public static function enabled(array $connection): bool
    {
        return !empty($connection['agent_endpoint'])
            && !empty($connection['app_token'])
            && !empty($connection['user_token']);
    }

    public static function update(array $connection, string $itemtype, int $remoteId, array $input, array $before): array
    {
        if (!self::enabled($connection)) {
            // Preserve the legacy direct-write behaviour only for connections that
            // have not opted into Agent B at all. Once an endpoint is configured,
            // missing GLPI API credentials must be visible to the operator;
            // silently falling back would bypass the authorised-representative
            // audit trail.
            if (!empty($connection['agent_endpoint'])) {
                return [
                    'success' => false,
                    'message' => 'Agent B requires the GLPI B App Token and User Token of the dedicated service account. Direct GLPI B writes are disabled for this connection.',
                    'transient' => false,
                ];
            }

            return GlpiBConnection::updateItem($connection, $itemtype, $remoteId, $input);
        }
        $allowed = array_diff(self::COMPUTER_FIELDS, ['entities_id', 'serial']);
        if ($itemtype !== 'Computer' || $input === [] || array_diff(array_keys($input), $allowed) !== []) {
            return ['success' => false, 'message' => 'Agent B execution supports only approved Computer fields (Name, Inventory Number, Type, Model, and Comments). Serial is match-only.', 'transient' => false];
        }
        $expectedBefore = [];
        foreach (array_keys($input) as $field) {
            $expectedBefore[$field] = $before[$field] ?? null;
        }
        $command = ['command_id' => bin2hex(random_bytes(16)), 'action' => 'update', 'itemtype' => $itemtype, 'remote_id' => $remoteId,
            'input' => $input, 'expected_before' => $expectedBefore];
        return self::send($connection, $command, $remoteId, false);
    }

    /** Creates a new Computer through Agent B's GLPI item API, never through the B REST write API. */
    public static function create(array $connection, string $itemtype, array $input): array
    {
        if (!self::enabled($connection)) {
            $missing = [];
            if (empty($connection['agent_endpoint'])) {
                $missing[] = 'command URL';
            }
            if (empty($connection['app_token']) || empty($connection['user_token'])) {
                $missing[] = 'GLPI B App Token and User Token';
            }

            return [
                'success' => false,
                'message' => 'Agent B creation is unavailable in this worker because the ' . implode(' and ', $missing)
                    . ' could not be loaded. Direct GLPI B REST creation is disabled.',
                'transient' => false,
                'id' => 0,
            ];
        }
        if ($itemtype !== 'Computer' || !isset($input['entities_id'], $input['serial']) || trim((string) $input['serial']) === ''
            || array_diff(array_keys($input), self::COMPUTER_FIELDS) !== []) {
            return ['success' => false, 'message' => 'Agent B creation supports only a Computer with its entity, immutable serial, Name, Inventory Number, Type, Model, and Comment.', 'transient' => false, 'id' => 0];
        }
        $command = ['command_id' => bin2hex(random_bytes(16)), 'action' => 'create', 'itemtype' => 'Computer', 'input' => $input];
        return self::send($connection, $command, 0, true);
    }

    /**
     * Obtains a normal GLPI REST session using the configured App/User Token and
     * sends the resulting Session-Token to Agent B. Agent B validates that GLPI
     * session, then performs the local item write through its internal API.
     */
    private static function send(array $connection, array $command, int $remoteId, bool $creation): array
    {
        $url = (string) ($connection['agent_endpoint'] ?? '');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && !($scheme === 'http' && !empty($connection['agent_allow_lab_http']))) {
            return ['success' => false, 'message' => 'Agent B command endpoint must use HTTPS (or explicit local-lab HTTP).', 'transient' => false, 'id' => 0];
        }
        try {
            $result = GlpiBConnection::executeAgentCommand($connection, $url, $command);
        } catch (\Throwable $error) {
            return ['success' => false, 'message' => 'Unable to contact Agent B: ' . $error->getMessage(), 'transient' => true, 'id' => 0];
        }
        $body = $result['body'] ?? [];
        if (!is_array($body) || empty($result['success']) || empty($body['success'])) {
            return ['success' => false, 'message' => is_array($body) ? (string) ($body['message'] ?? 'Agent B rejected the command.')
                : (string) ($result['message'] ?? 'Agent B did not return a receipt.'),
                'transient' => !empty($result['transient']), 'id' => 0];
        }
        if (!$creation) {
            return ['success' => true, 'message' => 'GLPI B Agent executed and verified the update.',
                'item' => ['id' => $remoteId] + (is_array($body['after'] ?? null) ? $body['after'] : []),
                'receipt' => $body, 'transient' => false];
        }
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0 || !is_array($body['after'] ?? null)) {
            return ['success' => false, 'message' => 'Agent B creation receipt is incomplete.', 'transient' => false, 'id' => $id];
        }
        return ['success' => true, 'message' => 'GLPI B Agent created and verified the Computer.', 'id' => $id,
            'item' => ['id' => $id] + $body['after'], 'receipt' => $body, 'transient' => false];
    }
}
