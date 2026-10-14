<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

use GlpiPlugin\Assetsync20\Config\LocalExecutionSettings;

/**
 * Runs an inbound GLPI B-to-A write as the dedicated AssetSync service account.
 * The caller still uses GLPI's item API, so normal History entries are retained.
 */
final class InternalGlpiExecutor
{
    public static function run(callable $operation): mixed
    {
        $userId = LocalExecutionSettings::getServiceUserId();
        if ($userId <= 0) {
            throw new \RuntimeException('Configure the GLPI A service user before synchronizing GLPI B-owned fields.');
        }

        $user = new \User();
        if (!$user->getFromDB($userId) || !empty($user->fields['is_deleted']) || empty($user->fields['is_active'])) {
            throw new \RuntimeException('The configured GLPI A service user is unavailable.');
        }

        $saved = [];
        foreach (['glpiID', 'glpiname', 'glpifriendlyname'] as $key) {
            $saved[$key] = $_SESSION[$key] ?? null;
        }
        $_SESSION['glpiID'] = (int) $user->fields['id'];
        $_SESSION['glpiname'] = (string) ($user->fields['name'] ?? 'assetsync-agent-a');
        $_SESSION['glpifriendlyname'] = (string) $user->getFriendlyName();

        try {
            return $operation();
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SESSION[$key]);
                } else {
                    $_SESSION[$key] = $value;
                }
            }
        }
    }
}
