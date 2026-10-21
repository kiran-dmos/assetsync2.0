<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Outbox
{
    public const TABLE = 'glpi_plugin_assetsync20agent_outbox';

    public static function install(): bool
    {
        global $DB;
        $ok = (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `computer_id` INT UNSIGNED NOT NULL, `itemtype` VARCHAR(100) NOT NULL DEFAULT \'Computer\',
            `revision` BIGINT NOT NULL DEFAULT 0, `acked_revision` BIGINT NOT NULL DEFAULT 0,
            `fingerprint` CHAR(64) NOT NULL, `lifecycle` VARCHAR(12) NOT NULL DEFAULT \'present\',
            `generation` CHAR(32) NOT NULL DEFAULT \'\', `claim_token` CHAR(32) NOT NULL DEFAULT \'\',
            `lease_until` BIGINT NOT NULL DEFAULT 0, `next_attempt` BIGINT NOT NULL DEFAULT 0,
            `attempts` INT NOT NULL DEFAULT 0, `last_outcome` VARCHAR(20) NOT NULL DEFAULT \'baseline\',
            `last_ack` BIGINT NOT NULL DEFAULT 0, `changed_at` BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (`itemtype`, `computer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$ok) { return false; }
        if (!$DB->fieldExists(self::TABLE, 'itemtype') && !$DB->doQuery('ALTER TABLE `' . self::TABLE . '` ADD `itemtype` VARCHAR(100) NOT NULL DEFAULT \'Computer\'')) { return false; }
        $index = self::one('SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = \'PRIMARY\' AND Seq_in_index = 1');
        return ($index['Column_name'] ?? '') === 'itemtype'
            || (bool) $DB->doQuery('ALTER TABLE `' . self::TABLE . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`itemtype`, `computer_id`)');
    }

    public static function find(int $id, string $type = 'Computer'): ?array
    {
        global $DB;
        return self::one('SELECT * FROM `' . self::TABLE . '` WHERE `computer_id` = ' . $id . ' AND `itemtype` = ' . $DB->quote($type));
    }

    /** baseline=true never clears a hook revision, even if the hook wins the initial insert race. */
    public static function observe(int $id, array $snapshot, bool $baseline, ?array $before = null, string $type = 'Computer'): void
    {
        global $DB;
        if ($id <= 0) {
            return;
        }
        $fingerprint = $DB->quote($snapshot['fingerprint']);
        $lifecycle = $DB->quote($snapshot['lifecycle']);
        $now = time();
        $quotedType = $DB->quote($type);
        if ($baseline && $before === null) {
            self::sql('INSERT IGNORE INTO `' . self::TABLE . "` (`itemtype`,`computer_id`,`fingerprint`,`lifecycle`,`changed_at`) VALUES ({$quotedType},{$id},{$fingerprint},{$lifecycle},{$now})");
            return;
        }
        if ($baseline) {
            if ($before['fingerprint'] === $snapshot['fingerprint'] && $before['lifecycle'] === $snapshot['lifecycle']) {
                return;
            }
            // A concurrent hook owns its newer fingerprint and cannot be overwritten by an older scan.
            self::sql('UPDATE `' . self::TABLE . "` SET `revision` = `revision` + 1, `fingerprint` = {$fingerprint}, `lifecycle` = {$lifecycle}, `changed_at` = {$now}"
                . ' WHERE `computer_id` = ' . $id . ' AND `itemtype` = ' . $quotedType . ' AND `revision` = ' . (int) $before['revision']
                . ' AND `fingerprint` = ' . $DB->quote($before['fingerprint']));
            return;
        }
        self::sql('INSERT INTO `' . self::TABLE . "` (`itemtype`,`computer_id`,`revision`,`fingerprint`,`lifecycle`,`changed_at`,`last_outcome`) VALUES ({$quotedType},{$id},1,{$fingerprint},{$lifecycle},{$now},'pending')"
            . ' ON DUPLICATE KEY UPDATE `revision` = `revision` + IF(`fingerprint` <> VALUES(`fingerprint`) OR `lifecycle` <> VALUES(`lifecycle`), 1, 0),'
            . ' `fingerprint` = VALUES(`fingerprint`), `lifecycle` = VALUES(`lifecycle`), `changed_at` = VALUES(`changed_at`)');
    }

    public static function claim(array $settings): ?array
    {
        $current = Settings::load();
        if (!Settings::transportReady($settings) || !Settings::transportReady($current)
            || AgentSession::fingerprint($settings) !== AgentSession::fingerprint($current)) { return null; }
        global $DB;
        $generation = $DB->quote($settings['generation']);
        $now = time();
        $agent = ($settings['mode'] ?? 'rest') === 'agent';
        $scope = " AND `itemtype` = 'Computer'";
        if ($agent) {
            $scope = ' AND EXISTS (SELECT 1 FROM `' . TargetScope::TABLE . '` s WHERE s.`items_id` = `'
                . self::TABLE . '`.`computer_id` AND s.`itemtype` = `' . self::TABLE . '`.`itemtype` AND s.`active` = 1 AND s.`expires` > ' . $now;
            foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
                $scope .= ' AND s.`' . $key . '` = ' . $DB->quote($settings[$key]);
            }
            $scope .= ')';
        }
        $rows = self::sql('SELECT * FROM `' . self::TABLE . "` WHERE (`revision` > `acked_revision` OR (`revision` > 0 AND `generation` <> {$generation}))"
            . " AND `lease_until` <= {$now} AND (`next_attempt` <= {$now} OR `generation` <> {$generation})" . $scope . ' ORDER BY `next_attempt`, `computer_id`, `itemtype` LIMIT 50');
        $row = null;
        while ($candidate = $DB->fetchAssoc($rows)) {
            if ($agent) {
                try {
                    $subscription = TargetScope::subscription($candidate['itemtype'], (int) $candidate['computer_id'], $settings);
                    if (!$subscription) { continue; }
                    TargetScope::snapshot($subscription, $settings);
                } catch (\Throwable) {
                    // Permission failure never resets an existing revision, fingerprint, generation or ACK.
                    $DB->update(self::TABLE, ['next_attempt' => $now + 60, 'last_outcome' => 'blocked'],
                        ['itemtype' => $candidate['itemtype'], 'computer_id' => (int) $candidate['computer_id'], 'claim_token' => $candidate['claim_token']]);
                    continue;
                }
            }
            $row = $candidate;
            break;
        }
        if (!$row) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $reset = $row['generation'] !== $settings['generation'];
        $fields = ['claim_token' => $token, 'generation' => $settings['generation'], 'lease_until' => $now + 60,
            'attempts' => $reset ? 1 : (int) $row['attempts'] + 1, 'last_outcome' => 'sending'];
        if ($reset) {
            $fields['acked_revision'] = 0;
        }
        if (!$DB->update(self::TABLE, $fields, ['itemtype' => $row['itemtype'], 'computer_id' => (int) $row['computer_id'],
            'revision' => (int) $row['revision'], 'claim_token' => $row['claim_token'], 'lease_until' => (int) $row['lease_until']])
            || $DB->affectedRows() !== 1) {
            return null;
        }
        return array_merge($row, $fields, ['registration' => $settings['registration']]);
    }

    public static function acknowledge(array $sent, array $ack, array $currentSettings): bool
    {
        global $DB;
        if (($ack['accepted'] ?? null) !== true) {
            return false;
        }
        foreach (['generation', 'registration', 'computer_id', 'revision'] as $key) {
            if (($ack[$key] ?? null) !== $sent[$key]) {
                return false;
            }
        }
        if (isset($sent['itemtype']) && ($ack['itemtype'] ?? 'Computer') !== $sent['itemtype']) { return false; }
        if (($currentSettings['generation'] ?? '') !== $sent['generation'] || ($currentSettings['registration'] ?? '') !== $sent['registration']) {
            return false;
        }
        // Do not assign revision or fingerprint here: a save during HTTP must remain outstanding.
        return $DB->update(self::TABLE, ['acked_revision' => $sent['revision'], 'lease_until' => 0,
            'claim_token' => '', 'attempts' => 0, 'next_attempt' => 0, 'last_outcome' => 'accepted', 'last_ack' => time()],
            self::owner($sent)) && $DB->affectedRows() === 1;
    }

    public static function retry(array $sent, string $outcome): bool
    {
        global $DB;
        $backoff = min(3600, 15 * (2 ** min(8, max(0, (int) $sent['attempts'] - 1))));
        return $DB->update(self::TABLE, ['lease_until' => 0, 'claim_token' => '', 'next_attempt' => time() + $backoff,
            'last_outcome' => in_array($outcome, ['blocked', 'unauthorized'], true) ? $outcome : 'retrying'], self::owner($sent))
            && $DB->affectedRows() === 1;
    }

    private static function owner(array $sent): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $sent['claim_token'] ?? '')) {
            throw new \RuntimeException('Missing outbox claim.');
        }
        return ['itemtype' => $sent['itemtype'] ?? 'Computer', 'computer_id' => (int) $sent['computer_id'], 'generation' => $sent['generation'], 'claim_token' => $sent['claim_token']];
    }

    public static function one(string $sql): ?array
    {
        global $DB;
        $row = $DB->fetchAssoc(self::sql($sql));
        return is_array($row) ? $row : null;
    }

    public static function sql(string $sql): mixed
    {
        global $DB;
        $result = $DB->doQuery($sql);
        if ($result === false) {
            throw new \RuntimeException('Agent outbox storage unavailable.');
        }
        return $result;
    }
}
