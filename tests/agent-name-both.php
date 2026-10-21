<?php
declare(strict_types=1);

class CommonDBTM { public static function unsetUndisclosedFields(&$fields) {} }
require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../assetsync20agent/src/autoload.php';

use GlpiPlugin\Assetsync20\NameBoth as A;
use GlpiPlugin\Assetsync20agent\NameBoth as B;
use GlpiPlugin\Assetsync20agent\RestSettings;
use GlpiPlugin\Assetsync20agent\RestWork;
use GlpiPlugin\Assetsync20agent\RestPeer;
use GlpiPlugin\Assetsync20agent\TaskExecutor;

$checks = 0;
function bothCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $call, string $message): void {
    try { $call(); throw new LogicException('Accepted: ' . $message); }
    catch (RuntimeException | JsonException | TypeError) { bothCheck(true, $message); }
}
function e(mixed $value, ?array $bounds = [100, 100], string $event = 'a'): array {
    return ['value' => $value, 'hash' => hash('sha256', json_encode(['type' => 'text', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'meta' => str_repeat('f', 64), 'origin' => $bounds === null ? null : ['identity' => str_repeat($event, 32) . ':' . str_repeat('c', 32),
            'revision' => 2, 'kind' => 'user', 'from_epoch' => $bounds[0], 'to_epoch' => $bounds[1]],
        'guard' => ['epoch' => str_repeat('d', 32), 'generation' => 4, 'head' => str_repeat('e', 64),
            'history' => ['latest' => 1, 'anchor' => ['id' => 1, 'hash' => str_repeat('f', 64)]]]];
}

foreach ([A::class, B::class] as $class) {
    foreach ([[e('A', [101, 110]), e('B', [99, 100]), 'a'], [e('A', [99, 100]), e('B', [101, 110]), 'b'],
        [e('A'), e('B'), 'conflict'], [e('A', [100, 102]), e('B', [102, 104]), 'conflict'],
        [e('A', [100, 105]), e('B', [103, 104]), 'conflict'], [e('A', null), e('B'), 'conflict'],
        [e('', [120, 121]), e('B'), 'a'], [e('0'), e(''), 'conflict'], [e(null, null), e('', null), 'equal'],
        [e(' Name '), e('Name'), 'conflict'], [e('same', null), e('same'), 'equal']] as [$a, $b, $expected]) {
        bothCheck($class::decide($a, $b) === $expected, 'Strict disjoint intervals, normalized equality, blank, whitespace and zero.');
    }
    $a = e('Name'); $bookkeeping = $a; $bookkeeping['guard']['generation']++;
    $bookkeeping['guard']['head'] = str_repeat('1', 64);
    bothCheck($class::fingerprint(str_repeat('a', 64), ['serial' => 'S'], $a) === $class::fingerprint(str_repeat('a', 64), ['serial' => 'S'], $bookkeeping), 'Comments bookkeeping is not logical Name eligibility.');
    $aba = $a; $aba['origin']['identity'] = str_repeat('b', 32) . ':' . str_repeat('c', 32);
    bothCheck($class::fingerprint(str_repeat('a', 64), ['serial' => 'S'], $a) !== $class::fingerprint(str_repeat('a', 64), ['serial' => 'S'], $aba), 'Logged Name edit/revert changes the original event fingerprint.');
    foreach ([array_replace($a, ['value' => ['not text']]), array_replace($a, ['hash' => str_repeat('0', 64)]),
        array_replace($a, ['origin' => array_replace($a['origin'], ['from_epoch' => 101, 'to_epoch' => 100])]),
        array_replace($a, ['guard' => array_replace($a['guard'], ['generation' => '4'])]), $a + ['extra' => 1]] as $invalid) {
        rejects(static fn () => $class::validateEnvelope($invalid), 'Typed envelope rejects malformed/hash/backwards/unknown fields.');
    }
}

$source = e('A', [101, 102]);
$body = ['version' => 11, 'id' => str_repeat('a', 32), 'installation' => str_repeat('b', 32), 'registration' => str_repeat('c', 32),
    'generation' => str_repeat('d', 32), 'connection_id' => 'b', 'settings_id' => str_repeat('e', 32), 'settings_hash' => str_repeat('f', 64),
    'pair_id' => str_repeat('1', 32), 'itemtype' => 'Computer', 'items_id' => 201, 'entity_id' => 2, 'identity' => ['serial' => 'S'],
    'source' => $source, 'source_hash' => TaskExecutor::hash($source), 'source_fingerprint' => str_repeat('2', 64), 'expires' => 300,
    'batch_id' => str_repeat('3', 32)];
bothCheck(RestWork::decode(json_encode($body), TaskExecutor::hash($body)) === $body, 'Work11 roundtrip preserves exact external evidence.');
foreach ([array_replace($body, ['version' => 10]), array_replace($body, ['source_hash' => str_repeat('0', 64)]),
    array_replace($body, ['items_id' => '201']), array_replace($body, ['id' => []]), $body + ['name' => 'legacy field']] as $invalid) {
    rejects(static fn () => RestWork::decode(json_encode($invalid), TaskExecutor::hash($invalid)), 'No reserved10/schema reinterpretation.');
}
$before = e('B'); $after = e('A', [101, 102]);
$result = ['outcome' => 'verified', 'changed' => ['name'], 'winner' => 'a', 'mutation' => 'committed', 'source_hash' => $body['source_hash'],
    'before' => $before, 'after' => $after, 'started_at' => 110, 'model_ended_at' => 112, 'within_deadline' => true];
foreach ([A::class, B::class] as $class) {
    $class::validateResult($body, $result);
    foreach ([array_replace($result, ['winner' => 'b']), array_replace($result, ['mutation' => 'none']), array_replace($result, ['within_deadline' => false]),
        array_replace($result, ['model_ended_at' => 301]), array_replace($result, ['started_at' => -1]),
        array_replace($result, ['after' => e('A', [101, 103])]), array_replace($result, ['after' => e('WRONG', [101, 102])])] as $invalid) {
        rejects(static fn () => $class::validateResult($body, $invalid), 'No false winner/commit/inheritance/timing success.');
    }
}
$policy = ['version' => 4, 'id' => str_repeat('a', 32), 'installation' => $body['installation'], 'registration' => $body['registration'],
    'generation' => $body['generation'], 'connection_id' => 'b', 'route_id' => 'route', 'source_entity' => 1, 'target_entity' => 2,
    'itemtype' => 'Computer', 'mappings' => [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'both']],
    'revision' => 2, 'enabled' => true, 'include_child_entities' => false, 'asset_types' => ['Computer']];
bothCheck(RestSettings::decode(json_encode($policy), TaskExecutor::hash($policy)) === $policy, 'Settings4 is explicit, exact Computer Name Both only.');
foreach ([array_replace($policy, ['include_child_entities' => true]), array_replace($policy, ['asset_types' => ['Printer']]), array_replace($policy, ['version' => 3]),
    array_replace($policy, ['mappings' => [['glpi_a_field' => 'comment', 'glpi_b_field' => 'name', 'source_of_truth' => 'both']]])] as $invalid) {
    rejects(static fn () => RestSettings::decode(json_encode($invalid), TaskExecutor::hash($invalid)), 'Scope expansion/reserved3/other fields remain unsupported.');
}
$validate = new ReflectionMethod(RestPeer::class, 'validateBatch');
$input = static fn ($b) => ['work_id' => $b['id'], 'hash' => TaskExecutor::hash($b), 'body' => json_encode($b)];
$validate->invoke(null, [$input($body), $input(array_replace($body, ['id' => md5('second')]))]);
rejects(static fn () => $validate->invoke(null, [$input($body), $input(array_replace($body, ['id' => md5('second')])), $input(array_replace($body, ['id' => md5('third')]))]), 'New evidence batches are bounded to two, not ten worst-case responses.');
$size = new ReflectionMethod(RestWork::class, 'assertResponseSize');
foreach ([str_repeat('"', 255), str_repeat('\\', 255), str_repeat(chr(1), 255), str_repeat(json_decode('"\udbff\udfff"'), 255)] as $name) {
    $sample = array_replace($body, ['source' => e($name, [101, 102]), 'connection_id' => str_repeat('c', 255),
        'identity' => ['serial' => $name, 'otherserial' => $name]]);
    $sample['source_hash'] = TaskExecutor::hash($sample['source']);
    $r = array_replace($result, ['source_hash' => $sample['source_hash'], 'before' => e($name . '', [99, 100]), 'after' => $sample['source']]);
    $row = ['id' => PHP_INT_MAX, 'compiler' => 11, 'work_id' => $sample['id'], 'hash' => TaskExecutor::hash($sample), 'body' => json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'binding' => str_repeat('f', 64), 'batch_id' => $sample['batch_id'], 'state' => 'verified', 'result' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'expires' => 300,
        'connection_id' => $sample['connection_id'], 'registration' => $sample['registration'], 'generation' => $sample['generation'],
        'installation' => $sample['installation'], 'entities_id' => PHP_INT_MAX, 'accepted_at' => PHP_INT_MAX, 'task_body' => 'PRIVATE', 'task_hash' => 'PRIVATE', 'execution_settings' => 'PRIVATE'];
    $size->invoke(null, $row); RestWork::unsetUndisclosedFields($row);
    bothCheck(strlen(json_encode([$row, $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 65536, 'Two255-character worst-case Unicode/escaped Name evidence rows fit64KiB.');
    bothCheck(!isset($row['task_body'], $row['task_hash'], $row['execution_settings']), 'Only compiler internals omitted, external proof retained.');
}
echo "Name Both protocol: $checks checks PASS (host, not native qualification).\n";
