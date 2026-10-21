<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Worker
{
    public const WAKE_VERSION = 1;
    public function run(?int $deadline = null, ?\Closure $admit = null): int
    {
        global $DB;
        if (!Settings::transportReady(Settings::load())) { return 0; }
        // HTTP wakes and CLI workers share one database-scoped lock.
        $lock = 'assetsync20agent:' . substr(hash('sha256', (string) ($DB->dbdefault ?? 'glpi')), 0, 32);
        $quotedLock = $DB->quote($lock);
        if ((int) (Outbox::one("SELECT GET_LOCK({$quotedLock}, 0) AS acquired")['acquired'] ?? 0) !== 1) {
            return 0;
        }
        $sessionOwned = false;
        try {
            if (!Settings::transportReady(Settings::load())) { return 0; }
            $deadline = min($deadline ?? PHP_INT_MAX, hrtime(true) + 20_000_000_000);
            AgentSession::begin($deadline);
            $sessionOwned = true;
            if (hrtime(true) >= $deadline) { return 0; }
            if ($admit !== null) { $admit(); }
            $settings = Wake::settings();
            if (($settings['mode'] ?? 'rest') === 'agent') {
                TaskJournal::cycle($settings, $deadline);
            }
            try {
                $this->reconcile($deadline);
            } catch (\UnexpectedValueException $error) {
                throw $error;
            } catch (\Throwable) {
                \Config::setConfigurationValues(Settings::CONTEXT, ['reconcile_status' => 'degraded']);
            }
            $settings = Wake::settings();
            if (hrtime(true) < $deadline && Settings::transportReady($settings)
                && (time() - (int) ($settings['health_attempt'] ?? 0) >= 60
                    || ($settings['health_reported_status'] ?? '') !== ($settings['reconcile_status'] ?? 'degraded'))) {
                $health = ['kind' => 'health', 'registration' => $settings['registration'], 'generation' => $settings['generation'],
                    'reconcile_status' => $settings['reconcile_status'] ?? 'degraded'];
                $response = self::send($settings, $health, $deadline);
                $state = ['health_attempt' => (string) time()];
                if ($response['status'] === 200 && ($response['body']['kind'] ?? '') === 'health'
                    && ($response['body']['recorded'] ?? false) === true
                    && ($response['body']['registration'] ?? '') === $health['registration']
                    && ($response['body']['generation'] ?? '') === $health['generation']) {
                    $state['health_ack'] = (string) time();
                    $state['health_reported_status'] = $health['reconcile_status'];
                }
                \Config::setConfigurationValues(Settings::CONTEXT, $state);
            }
            $count = 0;
            while ($count < 20 && hrtime(true) + 500_000_000 < $deadline) {
                $settings = Wake::settings();
                if (!Settings::transportReady($settings) || empty($settings['generation'])) {
                    break;
                }
                Wake::assertCurrent();
                $sent = Outbox::claim($settings);
                if ($sent === null) {
                    break;
                }
                if (($settings['mode'] ?? 'rest') === 'agent') {
                    try {
                        $subscription = TargetScope::subscription($sent['itemtype'], (int) $sent['computer_id'], $settings);
                        if (!$subscription) { throw new \RuntimeException('Subscription unavailable.'); }
                        TargetScope::snapshot($subscription, $settings);
                    } catch (\Throwable) {
                        Outbox::retry($sent, 'blocked');
                        $count++;
                        continue;
                    }
                }
                $sent['computer_id'] = (int) $sent['computer_id'];
                $sent['revision'] = (int) $sent['revision'];
                $response = self::send($settings, $sent, $deadline);
                if ($response['status'] !== 200 || !Outbox::acknowledge($sent, $response['body'], Settings::load())) {
                    Outbox::retry($sent, match ($response['status']) {401, 403 => 'unauthorized', 409 => 'blocked', default => 'retrying'});
                }
                $count++;
            }
            \Config::setConfigurationValues(Settings::CONTEXT, ['worker_seen' => (string) time()]);
            return $count;
        } finally {
            if ($sessionOwned) { AgentSession::end(); }
            Outbox::sql("SELECT RELEASE_LOCK({$quotedLock})");
        }
    }

    /** Persistent separate cursors discover changes and tracked purges, at most 50 rows/2 seconds. */
    public function reconcile(?int $deadline = null): int
    {
        global $DB;
        if (!Settings::transportReady(Settings::load())) { return 0; }
        $deadline = min($deadline ?? PHP_INT_MAX, hrtime(true) + 2_000_000_000);
        if (hrtime(true) >= $deadline) { return 0; }
        $state = Wake::settings();
        if (($state['mode'] ?? 'rest') === 'agent') {
            $priorityDeadline = min($deadline, hrtime(true) + max(0, (int) (($deadline - hrtime(true)) / 2)));
            $cursor = max(0, (int) ($state['target_cursor'] ?? 0));
            $seen = 0;
            $degraded = false;
            $where = array_intersect_key($state, array_flip(['installation', 'registration', 'generation', 'connection_id']));
            $where += ['active' => 1, 'expires' => ['>', time()]];
            $process = static function (array $target) use ($DB, $state, &$degraded): void {
                Wake::assertCurrent();
                $id = (int) $target['items_id'];
                $type = $target['itemtype'];
                try {
                    $before = Outbox::find($id, $type);
                    $snapshot = TargetScope::snapshot($target, $state);
                    Outbox::observe($id, $snapshot, (int) $target['dirty'] === 0, $before, $type);
                    if (!$DB->update(TargetScope::TABLE, ['dirty' => 0], ['id' => (int) $target['id'], 'dirty' => (int) $target['dirty']])) {
                        throw new \RuntimeException('Subscription dirty clear failed.');
                    }
                } catch (\Throwable) { $degraded = true; }
            };
            $priorityIds = [];
            foreach ($DB->request(['FROM' => TargetScope::TABLE, 'WHERE' => $where + ['dirty' => ['>', 0]],
                'ORDER' => 'id ASC', 'LIMIT' => 5]) as $target) {
                if (hrtime(true) >= $priorityDeadline) { break; }
                $priorityIds[(int) $target['id']] = true;
                $seen++;
                $process($target);
            }
            if ($seen < 50 && hrtime(true) < $deadline) {
                $limit = 50 - $seen;
                $sweepSeen = 0;
                $interrupted = false;
                foreach ($DB->request(['FROM' => TargetScope::TABLE, 'WHERE' => $where + ['id' => ['>', $cursor]],
                    'ORDER' => 'id ASC', 'LIMIT' => $limit]) as $target) {
                    if ($seen >= 50 || hrtime(true) >= $deadline) { $interrupted = true; break; }
                    $seen++;
                    $sweepSeen++;
                    if (!isset($priorityIds[(int) $target['id']])) { $process($target); }
                    $cursor = (int) $target['id'];
                    \Config::setConfigurationValues(Settings::CONTEXT, ['target_cursor' => (string) $cursor]);
                }
                // Priority rows consume the shared budget but never move the ordinary sweep cursor.
                if (!$interrupted && $sweepSeen < $limit && hrtime(true) < $deadline) { $cursor = 0; }
            }
            \Config::setConfigurationValues(Settings::CONTEXT, ['target_cursor' => (string) $cursor, 'reconcile_status' => $degraded ? 'degraded' : 'ok']);
            return $seen;
        }
        return 0;
    }

    public static function send(array $settings, array $sent, int $deadline): array
    {
        Wake::assertCurrent();
        if (!Settings::transportReady($settings)) { return ['status' => 0, 'body' => []]; }
        $keys = ($sent['kind'] ?? '') === 'health'
            ? ['kind', 'registration', 'generation', 'reconcile_status']
            : ['registration', 'generation', 'computer_id', 'revision', 'lifecycle'];
        if (($settings['mode'] ?? 'rest') === 'agent' && ($sent['kind'] ?? '') !== 'health') { $keys[] = 'itemtype'; }
        $payload = array_intersect_key($sent, array_flip($keys));
        return AgentSession::send($settings, 'notify', $payload, $deadline);
    }
}
