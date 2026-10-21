<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    function hrtime(bool $asNumber = false): int { return \AdmissionClock::$now; }
}
namespace {
    final class AdmissionClock { public static int $now = 1_000_000_000; }
    class CommonDBTM { public static function unsetUndisclosedFields(&$fields) {} }
    require __DIR__ . '/../assetsync20agent/src/RestPeer.php';
    require __DIR__ . '/../assetsync20agent/src/RestWork.php';
    require __DIR__ . '/../assetsync20agent/src/TaskExecutor.php';
    use GlpiPlugin\Assetsync20agent\RestPeer;
    use GlpiPlugin\Assetsync20agent\RestWork;
    use GlpiPlugin\Assetsync20agent\TaskExecutor;
    function admissionCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
    function admissionInput(array $body): array { return ['work_id' => $body['id'], 'hash' => TaskExecutor::hash($body), 'body' => json_encode($body, JSON_THROW_ON_ERROR)]; }
    $body = ['version' => 8, 'id' => str_repeat('a', 32), 'batch_id' => str_repeat('b', 32), 'installation' => str_repeat('c', 32),
        'registration' => str_repeat('d', 32), 'generation' => str_repeat('e', 32), 'connection_id' => 'b',
        'settings_id' => str_repeat('f', 32), 'settings_hash' => str_repeat('1', 64), 'pair_id' => str_repeat('2', 32),
        'itemtype' => 'Computer', 'items_id' => 1, 'entity_id' => 2, 'identity' => ['serial' => 'S'],
        'name' => 'Name', 'source_hash' => str_repeat('3', 64), 'expires' => time() + 300];
    $validate = new ReflectionMethod(RestPeer::class, 'validateBatch');
    $inputs = [];
    for ($index = 0; $index < 10; $index++) { $inputs[] = admissionInput(array_replace($body, ['id' => md5('row-' . $index), 'items_id' => $index + 1])); }
    $validate->invoke(null, $inputs);
    foreach ([[], [...$inputs, admissionInput($body)], [$inputs[0], $inputs[0]],
        [$inputs[0], admissionInput(array_replace($body, ['id' => ['invalid']]))],
        [$inputs[0], admissionInput(array_replace($body, ['entity_id' => 3]))],
        [$inputs[0], admissionInput(array_replace($body, ['version' => 7]))],
        [$inputs[0], array_replace(admissionInput($body), ['hash' => str_repeat('0', 64)])],
        [$inputs[0], admissionInput($body + ['unknown' => 'field'])]] as $invalid) {
        try { $validate->invoke(null, $invalid); throw new LogicException('Malformed batch admitted'); }
        catch (RuntimeException | JsonException) {}
    }
    admissionCheck(count($inputs) === 10, 'Up to ten complete rows pass whole-request structural validation.');
    $deadline = RestPeer::deadline(); AdmissionClock::$now += 4_000_000_000;
    new RestWork(); new RestWork();
    admissionCheck(RestPeer::deadline() === $deadline && $deadline === 6_000_000_000, 'Native per-row instances share one monotonic deadline.');

    $wire = static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assertSize = new ReflectionMethod(RestWork::class, 'assertResponseSize');
    foreach ([str_repeat('"', 255), str_repeat('\\', 255), str_repeat(json_decode('"\u0080"'), 127) . 'a',
        str_repeat(json_decode('"\udbff\udfff"'), 63) . 'aaa', str_repeat(chr(1), 255)] as $name) {
        $sample = array_replace($body, ['name' => $name, 'connection_id' => str_repeat('c', 255),
            'identity' => ['serial' => str_repeat(json_decode('"\u0080"'), 127) . 'a', 'otherserial' => str_repeat(json_decode('"\udbff\udfff"'), 63) . 'aaa']]);
        $modification = ['epoch' => null, 'untrusted_date_mod' => '2026-10-21 23:59:59', 'history' => ['id' => PHP_INT_MAX, 'epoch' => null, 'field' => '']];
        $snapshot = ['itemtype' => 'Computer', 'items_id' => PHP_INT_MAX, 'pair_id' => $sample['pair_id'], 'entity_id' => 2,
            'serial' => $sample['identity']['serial'], 'otherserial' => $sample['identity']['otherserial'], 'name' => $name, 'observed_at' => time(),
            'fields' => ['name' => ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'group' => 'Native', 'option' => 1, 'metadata' => str_repeat('f', 64), 'value' => $name]],
            'modification' => $modification, 'native_values' => ['name' => $name], 'origin_epoch' => null];
        $result = ['outcome' => 'verified', 'changed' => ['name' => PHP_INT_MAX], 'snapshot' => $snapshot,
            'mutation' => ['modification' => $modification, 'native_values' => ['name' => $name], 'origin_epoch' => null],
            'timing' => ['started_at' => time(), 'finished_at' => time(), 'within_deadline' => true]];
        $row = ['id' => PHP_INT_MAX, 'compiler' => '8', 'work_id' => $sample['id'], 'batch_id' => $sample['batch_id'], 'hash' => TaskExecutor::hash($sample),
            'body' => json_encode($sample), 'state' => 'verified', 'result' => json_encode($result), 'expires' => $sample['expires'],
            'binding' => str_repeat('f', 64), 'connection_id' => $sample['connection_id'], 'registration' => $sample['registration'],
            'generation' => $sample['generation'], 'installation' => $sample['installation'], 'entities_id' => 2, 'accepted_at' => time(),
            'task_body' => str_repeat('INTERNAL', 20000), 'task_hash' => str_repeat('f', 64), 'execution_settings' => 'INTERNAL'];
        $assertSize->invoke(null, $row);
        $external = $row; RestWork::unsetUndisclosedFields($external);
        admissionCheck(!isset($external['task_body'], $external['task_hash'], $external['execution_settings'])
            && $external['body'] === $row['body'] && $external['result'] === $row['result'] && $external['hash'] === $row['hash'], 'Only v8 compiler internals are omitted; immutable external proof is exact.');
        admissionCheck(strlen($wire(array_fill(0, 4, $external))) < 65536, 'Four worst-case escaped Name rows fit unchanged 64 KiB cap.');
    }
    $oversize = $row; $oversize['result'] = str_repeat('x', 16001);
    try { $assertSize->invoke(null, $oversize); throw new LogicException('Oversize response admitted'); } catch (RuntimeException) {}
    admissionCheck(strlen($wire(array_fill(0, 10, $external))) > 65536, 'Ten-row worst-case response is not falsely qualified.');
    echo "REST v8 admission passed: whole-list schemas/hashes, malformed IDs, shared deadline, compiler hiding and worst-case four-row 64 KiB bounds.\n";
}
