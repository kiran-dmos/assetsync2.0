<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    /** Authenticated native request double for component tests, never loaded by the plugin. */
    final class AgentApi
    {
        public function __construct(private string $connection = 'b', private bool $authenticated = true) {}
        public static function validatePeer(array $peer): void
        {
            if ((int) ($peer['agent_peer_user'] ?? 0) <= 0 || (int) ($peer['agent_peer_api_client'] ?? 0) <= 0
                || (int) ($peer['agent_peer_profile'] ?? 0) <= 0 || !ctype_digit((string) ($peer['agent_peer_entity'] ?? ''))) {
                throw new \UnexpectedValueException('Explicit native peer scope required.');
            }
        }
        public function assertPeer(array $peer): void
        {
            if (!$this->authenticated || ($peer['id'] ?? $peer['connection_id'] ?? '') !== $this->connection
                || (array_key_exists('active', $peer) && !$peer['active'])) {
                throw new \UnexpectedValueException('Native peer rejected.');
            }
        }
        public function activateReadAsset(string $type, int $id): bool { return true; }
        public function restoreReadScope(): void {}
    }
}
namespace GlpiPlugin\Assetsync20agent {
    final class AgentApi
    {
        public function __construct(private string $connection = 'b', private bool $authenticated = true) {}
        public static function validatePeer(array $peer): void
        {
            if ((int) ($peer['agent_peer_user'] ?? 0) <= 0 || (int) ($peer['agent_peer_api_client'] ?? 0) <= 0
                || (int) ($peer['agent_peer_profile'] ?? 0) <= 0 || !ctype_digit((string) ($peer['agent_peer_entity'] ?? ''))) {
                throw new \UnexpectedValueException('Explicit native peer scope required.');
            }
        }
        public function assertPeer(array $peer): void
        {
            if (!$this->authenticated || ($peer['connection_id'] ?? '') !== $this->connection) {
                throw new \UnexpectedValueException('Native peer rejected.');
            }
        }
    }
}
