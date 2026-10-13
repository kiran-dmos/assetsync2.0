<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-scalars.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20agent\TaskExecutor;

foreach (['inbound', 'equal', 'mixed', 'permission', 'billing', 'finalizing', 'locked_source', 'billing_missing'] as $case) {
    [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_b',
        $case === 'equal' ? 'B value' : 'A value', 'B value');
    if ($case === 'mixed') {
        FieldMapping::save('b', 'Computer', [
            ['glpi_a_field_key' => scalarKey('textfield'), 'glpi_b_field_key' => scalarKey('textfield'), 'source_of_truth' => 'glpi_b'],
            ['glpi_a_field_key' => scalarKey('yesfield'), 'glpi_b_field_key' => scalarKey('yesfield'), 'source_of_truth' => 'glpi_a'],
        ], AgentTask::catalog('b', 'Computer')['fields']);
        $DB->update(PluginFieldsComputerscalar::getTable(), ['yesfield' => 0], ['items_id' => 101]);
        $DB->update(PluginFieldsComputerscalar::getTable(), ['yesfield' => 1], ['items_id' => 201]);
    }
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    $inspect = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    $result = TaskExecutor::execute($inspect, $settings);
    $bBefore = scalarRow(201);
    scalarReceipt($request, $token, $inspect, $result);
    if ($case === 'permission') { Computer::$allowUpdate = false; }
    if ($case === 'finalizing') { $DB->update(AgentTask::TABLE, ['state' => 'finalizing'], ['id' => $inspect['id']]); }
    if (in_array($case, ['locked_source', 'billing_missing'], true)) {
        $DB->sql->beforeQuery = static function (string $sql) use ($DB, $case): void {
            if (!str_starts_with($sql, 'SELECT `id` FROM `glpi_computers`') || !str_ends_with($sql, 'FOR UPDATE')) { return; }
            $DB->sql->beforeQuery = null;
            if ($case === 'locked_source') {
                $DB->update(PluginFieldsComputerscalar::getTable(), ['textfield' => 'Concurrent A value'], ['items_id' => 101]);
            } else {
                $DB->doQuery('DELETE FROM glpi_plugin_fields_computerscalars WHERE items_id = 101');
            }
        };
    }
    $calls = 0;
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], static function (array $changes) use (&$calls, $case): bool {
        $calls++;
        scalarCheck(AgentTask::outstanding('b')['state'] === 'finalizing', 'Durable marker exists before every local/billing callback.');
        (new ReflectionMethod(\GlpiPlugin\Assetsync20\AssetSyncService::class, 'assertLocalNativeAccess'))
            ->invoke(new \GlpiPlugin\Assetsync20\AssetSyncService(), 'Computer', 101, 100);
        scalarApplyLocal($changes);
        return $case === 'billing';
    });
    scalarCheck(scalarRow(201) === $bBefore, 'Inspect finalization never performs a B write: ' . $case);
    if ($case === 'mixed') {
        $apply = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarCheck($apply['kind'] === 'apply' && $calls === 0 && scalarRow(101)['textfield'] === 'A value',
            'Any real B mutation preserves the apply phase before inbound finalization.');
        scalarReceipt($request, $token, $apply, TaskExecutor::execute($apply, $settings));
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
        scalarCheck(scalarRow(101)['textfield'] === 'B value' && scalarRow(201)['yesfield'] === 0,
            'Mixed authority converges through the ordinary guarded apply.');
    } elseif (in_array($case, ['permission', 'finalizing', 'locked_source', 'billing_missing'], true)) {
        scalarCheck(AgentTask::outstanding('b')['state'] === 'uncertain' && scalarRow(101)['textfield'] === 'A value',
            'Permission denial or interrupted local finalization remains held without replay: ' . $case);
        if ($case !== 'permission') { scalarCheck($calls === 0, 'Row/expected-value recheck rejects before local or billing writes: ' . $case); }
    } else {
        $queue = $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101]);
        scalarCheck(AgentTask::outstanding('b') === null && $queue['status'] === 'done' && $calls === 1
            && scalarRow(101)['textfield'] === 'B value', 'Fresh inspect finishes only through normal A finalization: ' . $case);
        scalarCheck((bool) $queue['needs_recheck'] === ($case === 'billing'), 'Billing changes preserve the follow-up recheck.');
    }
}
ob_end_clean();
echo "Agent inspect finalization tests passed ($checks checks including scalar permission/date/metadata/readback prerequisites).\n";
