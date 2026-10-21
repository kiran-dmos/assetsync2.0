<?php

declare(strict_types=1);

// Reuse the SQLite capture/model doubles and run their original regression checks.
ob_start();
require __DIR__ . '/field-evidence.php';
ob_end_clean();
$checks = 0;

function groupModelWrite(string $class, bool $custom, array $values): void
{
    global $DB;
    $item = $custom ? new PluginFieldsComputerExtra() : new Computer();
    $item->input = $values;
    $class::before($item);
    $item->oldvalues = [];
    $item->updates = array_keys($values);
    foreach ($values as $column => $value) {
        $old = $item->fields[$column];
        $item->oldvalues[$column] = $old;
        $DB->doQuery('UPDATE `' . $item::getTable() . '` SET `' . $column . '`=' . $DB->quote($value) . ' WHERE id=1');
        $item->fields[$column] = $value;
    }
    $class::after($item);
    // The official Fields wrapper constructs parent History after the child callback.
    foreach ($values as $column => $value) {
        history(['name' => 1, 'comment' => 16, 'customtext' => 76666, 'notes' => 76667][$column], $item->oldvalues[$column], $value);
    }
}

foreach ([GlpiPlugin\Assetsync20\FieldEvidence::class, GlpiPlugin\Assetsync20agent\FieldEvidence::class] as $class) {
    foreach ([false, true] as $custom) {
        fixture($class);
        CaptureContext::$cli = false;
        $columns = $custom ? ['customtext' => 'fields:1', 'notes' => 'fields:2'] : ['name' => 'native:name', 'comment' => 'native:comment'];
        $changes = [];
        foreach ($columns as $column => $key) {
            save($class, $column, $column . ' human edit', $custom);
            $envelope = $class::envelope(1, $key);
            check($envelope['origin'] !== null, 'Each source field has its own qualified fixture edit.');
            $changes[] = ['key' => $key, 'before_hash' => $envelope['hash'],
                'after_hash' => internal($class, 'valueHash', $column . ' copied'), 'origin' => $envelope['origin']];
        }
        check($changes[0]['origin']['identity'] !== $changes[1]['origin']['identity'], 'Group origins are distinct, not one asset timestamp.');
        CaptureContext::$cli = true;
        $beforeHistory = (int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn();
        $DB->sql->pdo->beginTransaction();
        $class::withoutOrigin(static function () use ($class, $changes, $columns, $custom): void {
            $class::withOrigins(1, $changes, static function () use ($class, $columns, $custom): void {
                groupModelWrite($class, $custom, array_combine(array_keys($columns), array_map(static fn ($column) => $column . ' copied', array_keys($columns))));
            });
        });
        $receipts = [];
        foreach ($changes as $change) { $receipts[] = $class::inheritedReceipt(1, $change['key'], $change['origin']); }
        check($DB->sql->pdo->inTransaction() && count($receipts) === 2, 'All group receipts are collected inside the caller-owned transaction.');
        $DB->sql->pdo->commit();
        foreach ($receipts as $index => $receipt) {
            $envelope = $class::settleInherited($receipt);
            check($envelope['origin'] === $changes[$index]['origin'] && $envelope['hash'] === $changes[$index]['after_hash'], 'Each changed field retains its own exact original event after commit.');
            check($class::settleInherited($receipt) === $envelope, 'Committed receipt replay is an idempotent read/settlement, not another model write.');
        }
        $class::finishWork();
        check((int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn() === $beforeHistory + 2, 'One grouped model update produces one fixture History row per actual changed field, with no replay duplicates.');
        check((new ReflectionProperty($class, 'origins'))->getValue() === [], 'The group and service suppression contexts are both restored.');

        // A group rollback must restore values, History and every evidence head together.
        $beforeHeads = [];
        foreach ($changes as $change) { $beforeHeads[$change['key']] = internal($class, 'head', 1, $change['key']); }
        $DB->sql->pdo->beginTransaction();
        $rollbackChanges = array_map(static fn ($change) => array_replace($change, ['before_hash' => $change['after_hash'],
            'after_hash' => internal($class, 'valueHash', 'rolled back')]), $changes);
        $class::withOrigins(1, $rollbackChanges, static fn () => groupModelWrite($class, $custom, array_fill_keys(array_keys($columns), 'rolled back')));
        $DB->sql->pdo->rollBack();
        $class::finishWork();
        foreach ($changes as $change) { check(internal($class, 'head', 1, $change['key']) === $beforeHeads[$change['key']], 'Rollback preserves each exact prior evidence head.'); }
        check((int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn() === $beforeHistory + 2, 'Rolled-back group does not leave change History.');
    }

    CaptureContext::$cli = false;
    fixture($class);
    save($class, 'name', 'Source');
    $origin = $class::read(1, 'native:name');
    $change = ['key' => 'native:name', 'before_hash' => internal($class, 'valueHash', 'Source'),
        'after_hash' => internal($class, 'valueHash', 'Target'), 'origin' => $origin];
    $called = false;
    $invalid = [[], [$change, $change], ['named' => $change], [array_replace($change, ['key' => 'native:uuid'])],
        [array_replace($change, ['before_hash' => 'invalid'])], [array_replace($change, ['origin' => []])], [$change + ['extra' => true]]];
    $tooMany = [];
    for ($i = 1; $i <= 9; $i++) { $tooMany[] = array_replace($change, ['key' => 'fields:' . $i]); }
    $invalid[] = $tooMany;
    foreach ($invalid as $members) {
        try {
            $class::withOrigins(1, $members, static function () use (&$called): void { $called = true; });
            throw new LogicException('Invalid group accepted.');
        } catch (InvalidArgumentException) {}
        check(!$called && (new ReflectionProperty($class, 'origins'))->getValue() === [], 'Invalid/duplicate/oversized group is rejected before callback or context installation.');
    }
    check($class::withOrigins(1, array_slice($tooMany, 0, 8), static fn () => 'eight') === 'eight', 'The documented eight-member origin limit is inclusive.');
    try { $class::withOrigins(1, [$change], static fn () => throw new RuntimeException('model failed')); }
    catch (RuntimeException $error) { check($error->getMessage() === 'model failed', 'The original callback failure is preserved.'); }
    check((new ReflectionProperty($class, 'origins'))->getValue() === [], 'Exceptions always remove the origin map.');

    // The top group is an inheritance barrier: no fallback to an outer single scope.
    $class::withOrigin(1, 'native:comment', $change['before_hash'], $change['after_hash'], $origin,
        static function () use ($class, $change): void {
            $class::withOrigins(1, [$change], static function () use ($class, $change): void {
                $scope = array_slice((new ReflectionProperty($class, 'origins'))->getValue(), -1)[0];
                $entry = ['scope' => $scope, 'id' => 1, 'descriptor' => ['key' => 'native:name'], 'old_hash' => $change['before_hash']];
                check(internal($class, 'origin', $entry, $change['after_hash'], 1, 1) === $change['origin'], 'Listed field receives its exact mapped origin.');
                $entry['descriptor']['key'] = 'native:comment';
                check(internal($class, 'origin', $entry, $change['after_hash'], 1, 1) === null, 'Unlisted sibling cannot inherit an outer scope or invent human provenance.');
                $entry['descriptor']['key'] = 'native:name'; $entry['id'] = 2;
                check(internal($class, 'origin', $entry, $change['after_hash'], 1, 1) === null, 'The map cannot qualify another asset.');
                $entry['id'] = 1;
                check(internal($class, 'origin', $entry, str_repeat('0', 64), 1, 1) === null, 'The map cannot qualify an unexpected applied value.');
            });
            check(count((new ReflectionProperty($class, 'origins'))->getValue()) === 1, 'Nested group restores the previous singleton scope.');
        });
    CaptureContext::$cli = false;
}
echo "Field evidence groups: $checks checks PASS (SQLite/model doubles; native qualification is separate)\n";
