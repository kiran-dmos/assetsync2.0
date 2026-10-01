<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncDbTime
{
    public static function write(object $db, callable $write): bool
    {
        if (!method_exists($db, 'doQuery') || !method_exists($db, 'fetchAssoc') || !method_exists($db, 'quote')) {
            return false;
        }

        $result = $db->doQuery('SELECT @@SESSION.time_zone AS session_timezone');
        if ($result === false) {
            return false;
        }
        $row = $db->fetchAssoc($result);
        if (!is_array($row) || !is_string($row['session_timezone'] ?? null)) {
            return false;
        }

        $timezone = $row['session_timezone'];
        if ($timezone === '+00:00') {
            return (bool) $write();
        }

        // NOW() stays unambiguous in UTC even during a repeated local DST hour.
        if ($db->doQuery("SET SESSION time_zone = '+00:00'") !== true) {
            return false;
        }
        try {
            return (bool) $write();
        } finally {
            if ($db->doQuery('SET SESSION time_zone = ' . $db->quote($timezone)) !== true) {
                throw new \RuntimeException('Could not restore the database session timezone.');
            }
        }
    }
}
