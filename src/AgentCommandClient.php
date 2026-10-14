<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Sends an A-authorised write to Agent B. No GLPI B user credential is sent. */
final class AgentCommandClient
{
    public static function enabled(array $connection): bool
    {
        return !empty($connection['agent_endpoint']) && !empty($connection['agent_token']);
    }

    public static function update(array $connection, string $itemtype, int $remoteId, array $input, array $before): array
    {
        if (!self::enabled($connection)) {
            // Preserve the legacy direct-write behaviour only for connections that
            // have not opted into Agent B at all. Once an endpoint is configured,
            // a missing command token must be visible to the operator; silently
            // falling back would bypass the authorised-representative audit trail.
            if (!empty($connection['agent_endpoint'])) {
                return [
                    'success' => false,
                    'message' => 'Agent B command token is missing. Save the Agent B command token on this GLPI B connection; direct GLPI B writes are disabled for this connection.',
                    'transient' => false,
                ];
            }

            return GlpiBConnection::updateItem($connection, $itemtype, $remoteId, $input);
        }
        $allowed = ['name', 'otherserial', 'comment', 'computertypes_id', 'computermodels_id'];
        if ($itemtype !== 'Computer' || $input === [] || array_diff(array_keys($input), $allowed) !== []) {
            return ['success' => false, 'message' => 'Agent B execution supports only approved Computer fields (Name, Inventory Number, Type, Model, and Comments). Serial is match-only.', 'transient' => false];
        }
        $url = (string) $connection['agent_endpoint'];
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && !($scheme === 'http' && !empty($connection['agent_allow_lab_http']))) {
            return ['success' => false, 'message' => 'Agent B command endpoint must use HTTPS (or explicit local-lab HTTP).', 'transient' => false];
        }
        $curl = curl_init($url);
        if ($curl === false) { return ['success' => false, 'message' => 'Unable to start Agent B request.', 'transient' => true]; }
        $expectedBefore = [];
        foreach (array_keys($input) as $field) {
            $expectedBefore[$field] = $before[$field] ?? null;
        }
        $command = ['command_id' => bin2hex(random_bytes(16)), 'itemtype' => $itemtype, 'remote_id' => $remoteId,
            'input' => $input, 'expected_before' => $expectedBefore];
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($command, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $connection['agent_token']], CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5]);
            $raw = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $body = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($body) || empty($body['success'])) { return ['success' => false, 'message' => is_array($body) ? (string) ($body['message'] ?? 'Agent B rejected the command.') : 'Agent B did not return a receipt.', 'transient' => $code >= 500]; }
            return ['success' => true, 'message' => 'GLPI B Agent executed and verified the update.',
                'item' => ['id' => $remoteId] + (is_array($body['after'] ?? null) ? $body['after'] : []), 'receipt' => $body, 'transient' => false];
        } finally { curl_close($curl); }
    }
}
