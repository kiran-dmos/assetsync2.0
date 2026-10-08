<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncLockName
{
    public static function databaseHash(object $db): string
    {
        if (!method_exists($db, 'doQuery') || !method_exists($db, 'fetchAssoc')) {
            throw new \RuntimeException('Cannot resolve the database for the synchronization lock.');
        }
        // Use the connection's actual selected database, preserving the old SQL MD5 input exactly.
        $result = $db->doQuery('SELECT DATABASE() AS database_name');
        $row = $result === false ? null : $db->fetchAssoc($result);
        $database = is_array($row) ? ($row['database_name'] ?? null) : null;
        if (!is_string($database) || $database === '') {
            throw new \RuntimeException('Cannot resolve the database for the synchronization lock.');
        }
        return md5($database);
    }
}
