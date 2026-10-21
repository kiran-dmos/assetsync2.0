<?php
declare(strict_types=1);

ob_start(); require __DIR__ . '/field-evidence.php'; ob_end_clean();
require_once __DIR__ . '/../src/NameBoth.php';

if (($argv[1] ?? '') === 'inherited-recovery') {
    $DB = new CaptureDB($argv[2]); $_SERVER['REQUEST_METHOD'] = 'POST';
    $class = $argv[3];
    $receipt = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    echo json_encode($class::settleInherited($receipt), JSON_THROW_ON_ERROR), "\n";
    exit;
}

foreach ([GlpiPlugin\Assetsync20\FieldEvidence::class, GlpiPlugin\Assetsync20agent\FieldEvidence::class] as $class) {
    $file = tempnam(sys_get_temp_dir(), 'name-origin-');
    try {
        fixture($class, $file);
        $unknown = $class::envelope(1, 'native:name');
        check($unknown['origin'] === null && $unknown['value'] === 'Original' && $unknown['guard']['generation'] === null, 'Missing head returns unknown, never backfills.');
        save($class, 'name', 'Name'); $captured = $class::envelope(1, 'native:name');
        $fp = GlpiPlugin\Assetsync20\NameBoth::fingerprint(str_repeat('a', 64), [], $captured);
        save($class, 'comment', 'Only comments'); $unchanged = $class::envelope(1, 'native:name');
        check($captured !== $unchanged && $captured['origin'] === $unchanged['origin']
            && $fp === GlpiPlugin\Assetsync20\NameBoth::fingerprint(str_repeat('a', 64), [], $unchanged), 'Unmapped edit changes full guard, not original-event eligibility.');
        try { $class::assertGuard(1, 'native:name', $captured); throw new LogicException('Stale guard accepted'); } catch (RuntimeException) {}
        $class::assertGuard(1, 'native:name', $unchanged);
        $origin = $unchanged['origin'];
        // A different installation's winning event is inherited INSIDE the enclosing unknown-sync scope.
        $origin['identity'] = str_repeat('b', 32) . ':' . str_repeat('c', 32); $origin['from_epoch'] = 100; $origin['to_epoch'] = 109;
        $DB->doQuery('START TRANSACTION');
        $class::assertGuard(1, 'native:name', $unchanged);
        check($class::read(1, 'native:name') === null, 'Idle origin API cannot mint/qualify inside model TX.');
        $receipt = $class::withoutOrigin(static fn () => $class::withOrigin(1, 'native:name', $unchanged['hash'], internal($class, 'valueHash', 'Inherited'), $origin,
            static function () use ($class, $origin): array {
                $item = new Computer(); $item->input = ['name' => 'Inherited']; $class::before($item);
                modelWrite($class, $item, 'name', 'Inherited');
                return $class::inheritedReceipt(1, 'native:name', $origin);
            }));
        $DB->doQuery('COMMIT');
        $before = internal($class, 'head', 1, 'native:name');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try { $class::settleInherited($receipt); throw new LogicException('GET settled inherited candidate'); } catch (RuntimeException) {}
        check(internal($class, 'head', 1, 'native:name') === $before && $class::read(1, 'native:name') === null, 'Committed candidate is not terminal/qualified on GET.');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // No clean callback/handoff: committed exact receipt alone recovers after process loss, never pre-only tokens.
        resetCapture($class);
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'inherited-recovery', $file, $class], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        fwrite($pipes[0], json_encode($receipt)); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Fresh process inherited recovery: ' . $error);
        $recovered = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        check($recovered['origin'] === $origin && $recovered['value'] === 'Inherited' && $recovered['guard']['history']['anchor'] === $receipt['anchor'], 'Recovery keeps exact original bounds and own parent History.');
        check($class::settleInherited($receipt) === $recovered, 'Already settled committed receipt is idempotent.');
        save($class, 'comment', 'Later comments');
        check($class::settleInherited($receipt)['origin'] === $origin, 'Unrelated save may refresh bookkeeping without changing inherited origin.');
        $fresh = $class::envelope(1, 'native:name');
        $DB->doQuery('START TRANSACTION');
        $item = new Computer(); $class::withoutOrigin(static fn () => $class::before($item)); modelWrite($class, $item, 'name', 'Rolled back');
        $DB->doQuery('ROLLBACK'); $class::finishWork();
        check($class::envelope(1, 'native:name') === $fresh, 'Rollback leaves qualified value/origin/guard exact.');
        save($class, 'name', 'Other'); save($class, 'name', 'Inherited');
        check($class::envelope(1, 'native:name')['origin'] !== $origin, 'Browser ABA preserves value but changes event.');
        try { $class::settleInherited($receipt); throw new LogicException('ABA receipt accepted'); } catch (RuntimeException) {}
    } finally { unlink($file); }
}
echo "Name Both evidence: $checks checks PASS (capture parity plus owned-TX/committed receipt gates).\n";
