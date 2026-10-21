<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../assetsync20agent/src/autoload.php';

use GlpiPlugin\Assetsync20\TextBoth as ControllerText;
use GlpiPlugin\Assetsync20\AgentActivity;
use GlpiPlugin\Assetsync20agent\TextBoth as AgentText;

$plan = require __DIR__ . '/agent-text-both-acceptance-cases.php';
$checks = 0;
function textContractCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function textContractHash(mixed $value): string
{
    return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
function textContractReject(callable $operation, string $message): void
{
    try { $operation(); }
    catch (RuntimeException) { textContractCheck(true, $message); return; }
    throw new RuntimeException($message);
}

// Synthetic independent definitions only; no GLPI, API, database or user changes.
function textContractPin(array $endpoint, array $layout, string $side): array
{
    $native = $endpoint['family'] === 'N';
    $sameClass = $layout['classes'] === 'same';
    $sameColumn = $layout['columns'] === 'same';
    $class = $native ? 'Computer' : 'PluginFieldsComputer' . ($sameClass || $side === 'a' ? 'aligned' : 'remote');
    $column = $native ? $endpoint['native_key'] : ($sameColumn || $side === 'a' ? 'alignedfield' : 'remotefield');
    $definition = $native ? null : ($side === 'a' ? 11 : 211);
    $container = $native ? null : ($side === 'a' ? 7 : 67);
    $table = $native ? 'glpi_computers' : 'glpi_plugin_fields_computer' . ($sameClass || $side === 'a' ? 'aligneds' : 'remotes');
    $pin = ['kind' => $native ? 'native' : 'fields',
        'key' => $native ? $column : 'Computer.' . $class . '.' . $column,
        'capture_key' => $native ? 'native:' . $column : 'fields:' . $definition,
        'type' => $endpoint['type'], 'search_id' => $side === 'a' ? 150 : 450,
        'definition_id' => $definition, 'container_id' => $container,
        'class' => $class, 'table' => $table, 'column' => $column,
        'definition_hash' => '',
        'limits' => $endpoint['type'] === 'text' ? ['characters' => 255, 'bytes' => 1020]
            : ['characters' => 65535, 'bytes' => 65535]];
    $preimage = ['itemtype' => 'Computer'];
    foreach ($pin as $key => $value) {
        if (!in_array($key, ['definition_hash', 'limits'], true)) { $preimage[$key] = $value; }
    }
    $preimage['limits'] = $pin['limits'];
    $preimage['field'] = $native ? null : ['active' => true, 'readonly' => false, 'mandatory' => false, 'multiple' => false];
    $preimage['container'] = $native ? null : ['active' => true, 'type' => 'tab',
        'entity_id' => $side === 'a' ? 5 : 7, 'recursive' => false, 'itemtypes' => ['Computer']];
    $pin['definition_hash'] = textContractHash($preimage);
    return $pin;
}
function textContractEnvelope(array $observation, array $pin, string $side): array
{
    $origin = $observation['origin'];
    return ['value' => $observation['value'],
        'hash' => textContractHash(['type' => 'text', 'value' => $observation['value']]),
        'meta' => textContractHash(['capture' => $pin['capture_key'], 'definition' => $pin['definition_hash']]),
        'origin' => $origin === null ? null : ['identity' => md5($side) . ':' . md5($origin['event']),
            'revision' => 1, 'kind' => 'user', 'from_epoch' => $origin['bounds'][0], 'to_epoch' => $origin['bounds'][1]],
        'guard' => ['epoch' => md5('epoch-' . $side), 'generation' => 1, 'head' => textContractHash($side),
            'history' => ['latest' => 1, 'anchor' => ['id' => 1, 'hash' => textContractHash('history-' . $side)]]]];
}

textContractCheck(count($plan['mappings']) === 28, 'Reuse all prepared endpoint layouts.');
$classes = [ControllerText::class, AgentText::class];
$decisions = 0;
foreach ($plan['mappings'] as $layout) {
    $aPin = textContractPin($plan['endpoints'][$layout['a']], $layout, 'a');
    $bPin = textContractPin($plan['endpoints'][$layout['b']], $layout, 'b');
    $mapping = ['mapping_id' => substr(textContractHash(['text-row', $aPin, $bPin]), 0, 32),
        'source_of_truth' => 'both', 'a' => $aPin, 'b' => $bPin];
    foreach ($classes as $class) {
        $class::validatePin($mapping['a']); $class::validatePin($mapping['b']);
    }
    if ($mapping['a']['kind'] === 'fields' && $mapping['b']['kind'] === 'fields') {
        textContractCheck($mapping['a']['definition_id'] !== $mapping['b']['definition_id'], 'Each installation resolves its own definition.');
        textContractCheck(($mapping['a']['key'] === $mapping['b']['key']) === ($layout['keys'] === 'same'), 'Test technical identities match the declared layout.');
    }
    foreach ($plan['scenarios'] as $scenario) {
        if (!isset($scenario['a'], $scenario['b'])) { continue; }
        $a = textContractEnvelope($scenario['a'], $mapping['a'], 'a');
        $b = textContractEnvelope($scenario['b'], $mapping['b'], 'b');
        $expected = strtolower($scenario['expected']['winner']);
        foreach ($classes as $class) {
            $class::validateEnvelope($a, $mapping['a']);
            $class::validateEnvelope($b, $mapping['b']);
            textContractCheck($class::decide($a, $b, $mapping) === $expected, $class . ': ' . $layout['id'] . '/' . $scenario['id']);
        }
        $decisions++;
    }
}

$pin = textContractPin($plan['endpoints']['name'], $plan['mappings'][0], 'a');
$textarea = textContractPin($plan['endpoints']['fields-textarea'], ['classes' => 'different', 'columns' => 'different'], 'b');
foreach ($classes as $class) {
    foreach ([null, '', '0', '  ', "one\r\ntwo", "quotes \" and \\ and <b>literal</b>", "\u{00e9}\u{1f642}"] as $value) {
        textContractCheck($class::normalize($value, $pin) === ($value ?? ''), 'Only null normalization is permitted.');
    }
    foreach ([false, true, 0, 1, [], new stdClass(), "\xff"] as $value) {
        textContractReject(static fn () => $class::normalize($value, $pin), 'Reject nontext or invalid UTF8 instead of coercion.');
    }
    textContractCheck($class::normalize(str_repeat("\u{1f642}", 255), $pin) === str_repeat("\u{1f642}", 255), 'Separate character and byte limits.');
    textContractReject(static fn () => $class::normalize(str_repeat('x', 256), $pin), 'Native Name overflow blocks.');
    textContractCheck($class::normalize(str_repeat('x', 1000), $textarea) === str_repeat('x', 1000), 'Textarea is not silently given the Name limit.');
    foreach ([['type' => 'yesno'], ['definition_id' => '11'], ['limits' => ['characters' => 255, 'bytes' => 0]]] as $changes) {
        $bad = array_replace($pin, $changes);
        textContractReject(static fn () => $class::validatePin($bad), 'Malformed or unsupported pins block.');
    }
    $rawNull = textContractEnvelope(['value' => null, 'origin' => null], $pin, 'a');
    $rawEmpty = textContractEnvelope(['value' => '', 'origin' => null], $pin, 'a');
    textContractCheck($rawNull['hash'] !== $rawEmpty['hash'], 'Raw NULL and empty guards must stay distinct.');
    $bad = $rawNull; $bad['hash'] = $rawEmpty['hash'];
    textContractReject(static fn () => $class::validateEnvelope($bad, $pin), 'Normalized equality never substitutes for raw execution guards.');
}

$activity = new ReflectionMethod(AgentActivity::class, 'restLabel');
textContractCheck(str_contains($activity->invoke(null, ['state' => 'accepted', 'result' => '{"fields":[{"outcome":"verified"},{"outcome":"offered"}]}'], ['version' => 12], []), 'A finalization pending'), 'B verification alone is not A completion.');
textContractCheck(str_contains($activity->invoke(null, ['state' => 'partial', 'result' => '{"fields":[{"outcome":"verified"},{"outcome":"conflict"}]}'], ['version' => 12], []), '1 conflicted'), 'Mixed field progress reports the independent conflict, not full success.');
textContractCheck(!str_contains($activity->invoke(null, ['state' => 'conflict', 'result' => '{"fields":[{"outcome":"verified"},{"outcome":"conflict"}]}'], ['version' => 12], []), 'no mutation'), 'A field conflict does not deny an independent committed change.');
textContractCheck(str_contains($activity->invoke(null, ['state' => 'origin_pending', 'result' => '{"fields":[{"outcome":"offered"}]}'], ['version' => 12], []), 'A model committed'), 'Committed A writes awaiting evidence qualification are not described as unexecuted offers.');
textContractCheck(str_contains($activity->invoke(null, ['state' => 'partial', 'result' => '{"fields":[{"outcome":"offered","group_id":"group1"},{"outcome":"conflict"}]}'], ['version' => 12],
    ['text_groups' => ['group1' => ['phase' => 'done']]]), '1 verified on A'), 'Activity combines durable local group progress with historical B offers, without claiming whole-task completion.');
textContractCheck(str_contains($activity->invoke(null, ['state' => 'conflict', 'result' => '{}'], ['version' => 12], []), 'no mutation'), 'Conflicted Text is not reported as changed.');
textContractCheck(!str_contains($activity->invoke(null, ['state' => 'retired', 'result' => '{"outcome":"verified"}'], ['version' => 12], []), 'no dispatch'), 'A historical verified result may have executed before retirement.');

echo "Text Both contract: $checks checks, $decisions decision vectors PASS (pure A/B parity and local labels only; no runtime qualification).\n";
