<?php
declare(strict_types=1);

ob_start(); require __DIR__ . '/agent-name-both-queue.php'; ob_end_clean();

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\FieldEvidence;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;

/** Extra failure/affected-row probes only; production task/queue/CAS SQL uses the existing SQLite fixture. */
final class SupersessionDB
{
    public AgentSqlite $sql;
    public string $dbdefault = 'settings-test';
    public bool $zeroRows = false;
    public string $commitFault = '';
    public ?Closure $beforeQuery = null;
    public function __construct(public NameQueueDB $base) { $this->sql = $base->sql; }
    public function tableExists(string $table): bool { return $this->base->tableExists($table); }
    public function fieldExists(string $table, string $field): bool { return $this->base->fieldExists($table,$field); }
    public function quote(string $value): string { return $this->base->quote($value); }
    public function fetchAssoc(PDOStatement $result): ?array { return $this->base->fetchAssoc($result); }
    public function request(array $query): ArrayIterator { return $this->base->request($query); }
    public function firstRow(string $table,array $where): ?array { return $this->base->firstRow($table,$where); }
    public function insert(string $table,array $fields): bool { return $this->base->insert($table,$fields); }
    public function update(string $table,array $fields,array $where): bool { return $this->base->update($table,$fields,$where); }
    public function affectedRows(): int { return $this->zeroRows ? 0 : $this->base->affectedRows(); }
    public function errno(): int { return $this->base->errno(); }
    public function doQuery(string $sql): mixed {
        if ($this->beforeQuery !== null) { ($this->beforeQuery)($sql); }
        if ($sql === 'COMMIT' && $this->commitFault !== ''
            && $this->sql->pdo->query("SELECT COUNT(*) FROM " . AgentTask::TABLE . " WHERE state='superseded'")->fetchColumn()) {
            $fault = $this->commitFault; $this->commitFault = '';
            if ($fault === 'after') { $this->base->doQuery($sql); }
            throw new RuntimeException('Injected supersession commit interruption');
        }
        return $this->base->doQuery($sql);
    }
}

$recoveryChecks = 0;
function recoveryCheck(bool $ok,string $message): void { global $recoveryChecks; $recoveryChecks++; if (!$ok) { throw new RuntimeException($message); } }
function neverReserved(array $task): bool { return (new ReflectionMethod(RestWork::class,'neverReserved'))->invoke(null,$task); }
function saveDispatch(array &$task,array $context,?array $result=null): bool {
    $args = [&$task,$context,$result];
    return (new ReflectionMethod(RestWork::class,'saveReservation'))->invokeArgs(null,$args);
}
function currentTask(string $id): array { global $DB; return $DB->firstRow(AgentTask::TABLE,['id'=>$id]) ?? []; }
function immutableOld(array $old,array $now): void {
    foreach (['id','body','hash','result','job','created_at','expires'] as $key) { recoveryCheck($old[$key] === $now[$key],'Old immutable field retained: '.$key); }
}
function stalePreparation(string $edit='name'): array {
    global $DB;
    $pair = nameQueueSetup(); $old = nameQueueTask($pair);
    $DB = new SupersessionDB($DB);
    $item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,$edit === 'comments' ? 'comment' : 'name'=>'New genuine edit']); FieldEvidence::finishWork();
    if ($edit === 'aba') { $item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>json_decode($old['body'],true)['source']['value']]); FieldEvidence::finishWork(); }
    $before = queue(); RestDispatchWire::reset();
    RestWork::sendPending(hrtime(true)+20_000_000_000);
    $after = queue();
    recoveryCheck(RestDispatchWire::$calls === [],'Stale preflight/wake has zero HTTP/auth.');
    recoveryCheck($before['status'] === 'waiting_agent' && $after['status'] === 'pending','Exact eligible parked queue is woken for an ORDINARY claim.');
    unset($before['status'],$after['status']); recoveryCheck($before === $after,'Wake changes no claim, counters, timestamps or receipt revisions.');
    $saved = currentTask($old['id']); immutableOld($old,$saved);
    recoveryCheck(neverReserved($saved),'Deferred context still proves never reserved.');
    return [$pair,$saved];
}
function ordinaryClaim(): array {
    $jobs = AssetSyncQueue::claimDue(1); recoveryCheck(count($jobs) === 1,'Ordinary claimDue reaches deferred preparation.'); return $jobs[0];
}
function replaceDirect(array $old,array $job,array $pair): void {
    (new ReflectionMethod(RestWork::class,'replaceUnsent'))->invoke(null,$old,$job,$pair,nameQueueContext($pair));
}
function recoveryBatch(): array {
    global $DB;
    $pair=nameQueueSetup();
    $asset=$DB->firstRow('glpi_computers',['id'=>101]);
    $DB->insert('glpi_computers',array_replace($asset,['id'=>102,'name'=>'Initial sibling']));
    $sibling=array_replace($pair,['id'=>md5('recovery-sibling'),'local_id'=>102,'computer_id'=>202]);
    $snapshot=json_decode($sibling['snapshot'],true); $snapshot['items_id']=202; $sibling['snapshot']=json_encode($snapshot);
    $DB->insert(AgentTask::PAIRS,$sibling);
    AssetSyncLink::save(array_replace(AssetSyncLink::find('Computer',101,'b'),['items_id'=>102,'remote_items_id'=>202,'confirmed_remote_id'=>202]));
    GlpiBConnection::save(array_replace(GlpiBConnection::find('b'),['agent_rest_pairs'=>[$pair['id'],$sibling['id']]]));
    Computer::$afterModelUpdate=static function ($item): void {
        global $DB;
        foreach ($item->updates as $column) {
            if (!in_array($column,['name','comment'],true)) { continue; }
            $DB->insert('glpi_logs',['items_id'=>$item->fields['id'],'itemtype'=>'Computer','id_search_option'=>$column === 'name' ? 1 : 16,
                'old_value'=>$item->oldvalues[$column],'new_value'=>$item->input[$column],'itemtype_link'=>'','linked_action'=>0,'user_name'=>'Service (7)','date_mod'=>'2001-01-01']);
        }
        \GlpiPlugin\Assetsync20\AssetChangeHook::onUpdate($item);
    };
    $item=new Computer(); $item->getFromDB(102); $item->update(['id'=>102,'name'=>'Sibling browser edit']); FieldEvidence::finishWork();
    RestWork::beginRun();
    AssetSyncQueue::notify('Computer',101,'b'); AssetSyncQueue::notify('Computer',102,'b');
    foreach (AssetSyncQueue::claimDue(2) as $job) { RestWork::process($job); }
    $old=AgentTask::outstanding('b',$pair); $healthy=AgentTask::outstanding('b',$sibling);
    recoveryCheck($old && $healthy && json_decode($old['body'],true)['batch_id'] === json_decode($healthy['body'],true)['batch_id'],'Two independently scoped pairs share the original immutable batch.');
    $DB=new SupersessionDB($DB); RestDispatchWire::reset();
    return [$pair,$sibling,$old,$healthy];
}

foreach (['name','aba','comments'] as $edit) {
    [$pair,$old] = stalePreparation($edit); $beforePair = AgentTask::byId('b',$pair['id']); $link = AssetSyncLink::find('Computer',101,'b');
    $source = nameQueueContext($pair); $job = ordinaryClaim(); $queueBefore = queue();
    RestWork::process($job); $retired = currentTask($old['id']); $new = AgentTask::outstanding('b',$pair);
    recoveryCheck($retired['state'] === 'superseded' && $retired['slot'] === null && $new !== null && $new['id'] !== $old['id'],'Ordinary processing atomically transfers only the never-reserved slot.');
    immutableOld($old,$retired);
    $body = json_decode($new['body'],true); $oldBody = json_decode($old['body'],true);
    recoveryCheck(json_decode($retired['context'],true)['superseded_by'] === $new['id'],'Retained context identifies exact successor.');
    recoveryCheck($body['batch_id'] !== $oldBody['batch_id'] && $new['slot'] === $old['slot'],'Successor uses a FRESH batch ID, never rewrites shared old membership.');
    recoveryCheck($body['source'] === $source['evidence'] && $body['source_hash'] === $source['source_hash'] && $body['source_fingerprint'] === $source['fingerprint'],'Fresh full envelope and original event, not guessed timestamps.');
    recoveryCheck(AgentTask::hash($body) === $new['hash'] && $body['expires'] >= time()+299 && neverReserved($new),'Successor has fresh immutable body/hash/expiry and initialized never-reserved context.');
    recoveryCheck(AgentTask::byId('b',$pair['id']) === $beforePair && AssetSyncLink::find('Computer',101,'b') === $link,'Supersession does not advance fingerprint/probe/link success.');
    $parked = queue(); recoveryCheck($parked['status'] === 'waiting_agent' && $parked['agent_completed'] === $queueBefore['agent_completed'] && $parked['agent_requested'] === $queueBefore['agent_requested'],'No false completion or receipt-revision consumption.');
    recoveryCheck(RestDispatchWire::$calls === [],'Reprepare itself performs no remote operation.');
}

[$pair,$old] = stalePreparation(); $job = ordinaryClaim(); $newContext = json_decode($old['context'],true); $newContext['rest_lease']=str_repeat('a',32); $newContext['rest_until']=time()+30;
$reserved = $old; recoveryCheck(saveDispatch($reserved,$newContext),'Reservation wins durable fence.');
RestWork::process($job);
recoveryCheck(AgentTask::outstanding('b',$pair)['id'] === $old['id'] && currentTask($old['id'])['state'] === 'accepted','Reservation winner prevents replacement, even sent=false.');

foreach (['reserved','expired','before_marker','after_marker'] as $case) {
    [$pair,$old] = stalePreparation(); $job = ordinaryClaim(); $ctx=json_decode($old['context'],true);
    $ctx['rest_lease']=str_repeat('b',32); $ctx['rest_until']=$case === 'reserved' ? time()+30 : 0;
    $ctx['rest_sent']=$case === 'after_marker'; $fields=['context'=>json_encode($ctx)];
    if ($case === 'expired') {
        $body=json_decode($old['body'],true); $body['expires']=time()-1;
        $fields+=['body'=>json_encode($body),'hash'=>AgentTask::hash($body),'expires'=>$body['expires']];
    }
    $DB->update(AgentTask::TABLE,$fields,['id'=>$old['id']]);
    $held=currentTask($old['id']); recoveryCheck(!neverReserved($held),'Sticky lease/attempt marker is never erased by expiry or process loss.');
    RestWork::process($job); recoveryCheck(AgentTask::outstanding('b',$pair)['id'] === $old['id'],'Ever-reserved preparation remains held: '.$case);
}

[$pair,$old] = stalePreparation(); $job=ordinaryClaim(); RestWork::process($job); $replacement=AgentTask::outstanding('b',$pair);
$paused=$old; $ctx=json_decode($paused['context'],true); $ctx['rest_lease']=str_repeat('c',32); $ctx['rest_until']=time()+30;
recoveryCheck(!saveDispatch($paused,$ctx),'Replacement wins: paused old initial reserve CAS fails.');
$ctx['rest_sent']=true; recoveryCheck(!saveDispatch($paused,$ctx),'Paused old attempt marker cannot authorize obsolete POST.');
$ctx['rest_until']=0; recoveryCheck(!saveDispatch($paused,$ctx,['outcome'=>'uncertain','changed'=>[]]),'Paused old observation/cleanup cannot change old evidence.');
recoveryCheck(AgentTask::outstanding('b',$pair)['id'] === $replacement['id'] && queue()['status'] === 'waiting_agent','Failed old fences do not wake replacement queue.');

$pair=nameQueueSetup(); $old=nameQueueTask($pair); $DB=new SupersessionDB($DB);
$l1=$old; $c1=json_decode($l1['context'],true); $c1['rest_lease']=str_repeat('1',32); $c1['rest_until']=time()-1;
recoveryCheck(saveDispatch($l1,$c1),'Expired L1 reservation remains durable dispatch evidence.');
$l2=$l1; $c2=$c1; $c2['rest_lease']=str_repeat('2',32); $c2['rest_until']=time()+30;
recoveryCheck(saveDispatch($l2,$c2),'Fresh holder L2 reserves the same task after L1 expiry.');
$before=currentTask($old['id']); $beforeQueue=queue(); $DB->zeroRows=true;
$c1['rest_sent']=true; recoveryCheck(!saveDispatch($l1,$c1),'Paused L1 cannot mark/authorize POST after L2 rotation.');
$c1['rest_until']=0;
recoveryCheck(!saveDispatch($l1,$c1,['outcome'=>'uncertain','changed'=>[]]),'Paused L1 cannot save an observation over L2.');
recoveryCheck(!saveDispatch($l1,$c1),'Paused L1 cleanup cannot clear L2 reservation even through zero-row fallback.');
$DB->zeroRows=false;
recoveryCheck(currentTask($old['id']) === $before && queue() === $beforeQueue,'Lease-rotation loser leaves task/queue byte-exact without supersession.');

foreach (['slot','state','result','body','hash','context','job','queue_id'] as $field) {
    [$pair,$old] = stalePreparation();
    $changed = match($field) { 'slot'=>null, 'state'=>'superseded', 'result'=>'{"outcome":"uncertain","changed":[]}', 'body'=>$old['body'].' ',
        'hash'=>str_repeat('f',64), 'context'=>$old['context'].' ', 'job'=>'{}', 'queue_id'=>999 };
    $DB->update(AgentTask::TABLE,[$field=>$changed],['id'=>$old['id']]); $DB->zeroRows=true;
    $paused=$old; $ctx=json_decode($old['context'],true);
    recoveryCheck(!saveDispatch($paused,$ctx),'Complete no-op fallback rejects changed '.$field.', even with identical requested context.');
}
[$pair,$old] = stalePreparation(); $DB->zeroRows=true; $copy=$old;
recoveryCheck(saveDispatch($copy,json_decode($copy['context'],true)),'Complete unchanged-row fallback accepts an actual MySQL-style no-op.');
$DB->zeroRows=false;

foreach ([['rest_sent'=>'false'],['rest_until'=>'0'],['rest_lease'=>null],['name_origin_pending'=>[]],['receipt'=>null],['mutation'=>[]],['rest_state'=>'accepted']] as $malformed) {
    [$pair,$old] = stalePreparation(); $ctx=array_replace(json_decode($old['context'],true),$malformed); $bad=array_replace($old,['context'=>json_encode($ctx)]);
    recoveryCheck(!neverReserved($bad),'Malformed/receipt/mutation context cannot be interpreted as absence.');
}

foreach (['insert','queue','commit_before','commit_after'] as $fault) {
    [$pair,$old] = stalePreparation(); $job=ordinaryClaim(); $beforeQueue=queue(); $beforePair=AgentTask::byId('b',$pair['id']);
    $allBefore=iterator_to_array($DB->request(['FROM'=>AgentTask::TABLE]));
    if ($fault === 'insert') { $DB->sql->failOn='INSERT INTO `'.AgentTask::TABLE.'`'; }
    if ($fault === 'queue') { $DB->sql->failOn='UPDATE `'.AssetSyncQueue::TABLE.'` SET `status`=\'waiting_agent\''; }
    if (str_starts_with($fault,'commit_')) { $DB->commitFault=$fault === 'commit_after' ? 'after' : 'before'; }
    $threw=false; try { replaceDirect($old,$job,$pair); } catch (RuntimeException) { $threw=true; }
    $DB->sql->failOn=null;
    if ($fault === 'commit_after') {
        recoveryCheck(!$threw && currentTask($old['id'])['state'] === 'superseded' && AgentTask::outstanding('b',$pair)['id'] !== $old['id'],'Lost COMMIT reply reconciles exact durable successor, never recreates it.');
    } else {
        recoveryCheck($threw && iterator_to_array($DB->request(['FROM'=>AgentTask::TABLE])) === $allBefore && queue() === $beforeQueue,'Insert/queue/precommit failure atomically rolls back slot, context, successor and queue: '.$fault);
    }
    recoveryCheck(AgentTask::byId('b',$pair['id']) === $beforePair && RestDispatchWire::$calls === [],'Commit/failure recovery never fabricates verification or remote mutation.');
}

[$pair,$old] = stalePreparation(); $job=ordinaryClaim(); $queueBefore=queue();
$badPair=$pair; $badPair['computer_id']=(int)$pair['computer_id']+1;
$threw=false; try { replaceDirect($old,$job,$badPair); } catch (RuntimeException) { $threw=true; }
recoveryCheck($threw && currentTask($old['id']) === $old && queue() === $queueBefore,'Changed confirmed target cannot reprepare/relink.');

// The other dispatcher commits its reservation after our idle read, before our policy transaction.
[$pair,$old] = stalePreparation(); $job=ordinaryClaim(); $taskCount=count(iterator_to_array($DB->request(['FROM'=>AgentTask::TABLE])));
$DB->beforeQuery=static function (string $sql) use ($old): void {
    global $DB;
    if (!str_starts_with($sql,'SELECT GET_LOCK(')) { return; }
    $DB->beforeQuery=null;
    recoveryCheck(!$DB->sql->pdo->inTransaction(),'Competing reservation is durable, not part of the replacement rollback.');
    $ctx=json_decode($old['context'],true); $ctx['rest_lease']=str_repeat('d',32); $ctx['rest_until']=time()+30;
    $actor=$old; recoveryCheck(saveDispatch($actor,$ctx),'Competing reservation wins between preparation and replacement transaction.');
};
$threw=false; try { replaceDirect($old,$job,$pair); } catch (RuntimeException) { $threw=true; }
recoveryCheck($threw && currentTask($old['id'])['state'] === 'accepted' && !neverReserved(currentTask($old['id'])),'Owned replacement transaction cannot supersede the reservation winner.');
recoveryCheck(count(iterator_to_array($DB->request(['FROM'=>AgentTask::TABLE]))) === $taskCount && RestDispatchWire::$calls === [],'Losing replacement inserts nothing and makes no HTTP request.');

[$pair,$old]=stalePreparation(); $job=ordinaryClaim(); $fresh=nameQueueContext($pair);
$DB->beforeQuery=static function (string $sql): void {
    global $DB;
    if (!str_starts_with($sql,'SELECT GET_LOCK(')) { return; }
    $DB->beforeQuery=null;
    $item=new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Edit after idle envelope']); FieldEvidence::finishWork();
};
$threw=false;
try { (new ReflectionMethod(RestWork::class,'replaceUnsent'))->invoke(null,$old,$job,$pair,$fresh); } catch (RuntimeException) { $threw=true; }
recoveryCheck($threw && currentTask($old['id']) === $old,'In-transaction full field guard rejects a change after idle preparation.');
recoveryCheck($DB->firstRow('glpi_computers',['id'=>101])['name'] === 'Edit after idle envelope' && RestDispatchWire::$calls === [],'Failed replacement preserves the newer genuine edit and makes zero HTTP.');

[$pair,$old]=stalePreparation(); $job=ordinaryClaim();
$DB->update(AgentTask::BINDINGS,['installation'=>str_repeat('f',32)],['connection_id'=>'b']);
$threw=false; try { replaceDirect($old,$job,$pair); } catch (RuntimeException) { $threw=true; }
recoveryCheck($threw && currentTask($old['id']) === $old && RestDispatchWire::$calls === [],'Fresh locked binding mismatch prevents replacement, even for an older manually confirmed pair.');

$pair=nameQueueSetup(); $old=nameQueueTask($pair); $DB=new SupersessionDB($DB);
$item=new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Changed while running']); FieldEvidence::finishWork();
$DB->update(AssetSyncQueue::TABLE,['status'=>'pending'],['id'=>$old['queue_id']]); $job=ordinaryClaim(); $running=queue();
RestDispatchWire::reset(); RestWork::sendPending(hrtime(true)+20_000_000_000);
recoveryCheck(queue() === $running && $running['status'] === 'running','Stale dispatch never wakes or changes a running claim/counters.');
recoveryCheck(RestDispatchWire::$calls === [],'Running-claim stale preflight still has zero HTTP.');

foreach ([['serial'=>[]],['serial'=>null],['serial'=>' not canonical ']] as $identity) {
    [$pair,$old]=stalePreparation(); $body=json_decode($old['body'],true); $body['identity']=$identity;
    $bad=array_replace($old,['body'=>json_encode($body),'hash'=>AgentTask::hash($body)]);
    recoveryCheck(!neverReserved($bad),'Malformed/noncanonical immutable identity is held, never silently repaired.');
}

// A paused marker must fail before POST even if it reached native session initialization.
$pair=nameQueueSetup(); $old=nameQueueTask($pair); $DB=new SupersessionDB($DB);
RestDispatchWire::$login=['status'=>200,'body'=>['session_token'=>'PRIVATE-SESSION']];
$DB->sql->beforeQuery=static function (string $sql) use ($old): void {
    global $DB;
    if (!str_starts_with($sql,'UPDATE `'.AgentTask::TABLE.'`') || !str_contains($sql,'"rest_sent":true')) { return; }
    $DB->sql->beforeQuery=null;
    $DB->update(AgentTask::TABLE,['slot'=>null,'state'=>'superseded'],['id'=>$old['id']]);
};
$beforeQueue=queue(); RestWork::sendPending(hrtime(true)+20_000_000_000);
recoveryCheck(!array_filter(RestDispatchWire::$calls,static fn ($call)=>$call['method'] === 'POST'),'Failed durable pre-POST fence makes no Work POST.');
recoveryCheck(currentTask($old['id'])['result'] === '{}' && queue() === $beforeQueue,'Failed marker/cleanup fence cannot write obsolete receipts or wake the queue.');

// A received result changes our in-memory fence; cleanup then preserves that exact durable result.
$pair=nameQueueSetup(); $old=nameQueueTask($pair); $DB=new SupersessionDB($DB);
$result=nameQueueResult($old,'equal'); $record=settingsWorkRecord($old,$result);
settingsDispatch([['status'=>201,'body'=>[['id'=>77]]],['status'=>200,'body'=>[$record]]]);
$saved=currentTask($old['id']); $ctx=json_decode($saved['context'],true);
recoveryCheck($saved['result'] === json_encode($result) && queue()['status'] === 'pending','Full observation fence saves exact result and wakes its parked queue.');
recoveryCheck($ctx['rest_until'] === 0 && $ctx['rest_sent'] === true && $ctx['rest_lease'] !== '' && $ctx['rest_state'] === 'unchanged','Cleanup uses the updated receipt fence and never clears sticky dispatch evidence.');

$pair=nameQueueSetup(); $old=nameQueueTask($pair); $DB=new SupersessionDB($DB); $beforeQueue=queue();
$result=nameQueueResult($old,'equal');
settingsDispatch([['status'=>201,'body'=>[['id'=>77]]],static function () use ($old,$result): array {
    global $DB;
    $record=settingsWorkRecord($old,$result);
    $DB->update(AgentTask::TABLE,['slot'=>null,'state'=>'superseded'],['id'=>$old['id']]);
    return ['status'=>200,'body'=>[$record]];
}]);
recoveryCheck(currentTask($old['id'])['result'] === '{}' && queue() === $beforeQueue,'Changed task during GET fails observation fence: no obsolete result/wake/cleanup.');

foreach (['stale','marker_fence'] as $case) {
    [$pair,$sibling,$old,$healthy]=recoveryBatch();
    if ($case === 'stale') {
        $item=new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Latest original pair edit']); FieldEvidence::finishWork();
    } else {
        $DB->sql->beforeQuery=static function (string $sql) use ($old): void {
            global $DB;
            if (!str_starts_with($sql,'UPDATE `'.AgentTask::TABLE.'`') || !str_contains($sql,'"rest_sent":true') || !str_contains($sql,$old['id'])) { return; }
            $DB->sql->beforeQuery=null;
            $ctx=json_decode(currentTask($old['id'])['context'],true); $ctx['rest_lease']=str_repeat('e',32);
            $DB->update(AgentTask::TABLE,['context'=>json_encode($ctx)],['id'=>$old['id']]);
        };
    }
    $result=nameQueueResult($healthy,'equal');
    settingsDispatch([static function ($method,$path,$options) use ($healthy): array {
        $input=json_decode($options[CURLOPT_POSTFIELDS],true)['input'];
        recoveryCheck(count($input) === 1 && $input[0]['work_id'] === $healthy['id'] && $input[0]['body'] === $healthy['body'],'Healthy sibling alone advances past stale/fenced batch member.');
        return ['status'=>201,'body'=>[['id'=>78]]];
    },['status'=>200,'body'=>[settingsWorkRecord($healthy,$result)]]]);
    recoveryCheck(currentTask($healthy['id'])['result'] === json_encode($result) && currentTask($old['id'])['result'] === '{}','Missing old batch member is not inferred completed or failed.');
    immutableOld($old,currentTask($old['id']));
    foreach (AssetSyncQueue::claimDue(2) as $job) { RestWork::process($job); }
    recoveryCheck(currentTask($healthy['id'])['state'] === 'unchanged' && currentTask($healthy['id'])['slot'] === null
        && $DB->firstRow(AssetSyncQueue::TABLE,['id'=>$healthy['queue_id']])['status'] === 'done','Healthy equal sibling finalizes independently without a fabricated mutation.');
    if ($case === 'stale') {
        $new=AgentTask::outstanding('b',$pair);
        recoveryCheck(currentTask($old['id'])['state'] === 'superseded' && $new && json_decode($new['body'],true)['batch_id'] !== json_decode($healthy['body'],true)['batch_id'],'Ordinary claim replaces stale member with a fresh batch, leaving original sibling membership exact.');
        recoveryCheck(str_contains(restLabel(currentTask($old['id'])),'no dispatch or completion'),'Activity describes local supersession without false acceptance/completion.');
    } else {
        recoveryCheck(AgentTask::outstanding('b',$pair)['id'] === $old['id'] && !neverReserved(currentTask($old['id'])),'Competing sticky lease remains held while sibling succeeds.');
    }
    recoveryCheck(currentTask($healthy['id'])['body'] === $healthy['body'] && currentTask($healthy['id'])['hash'] === $healthy['hash'],'Sibling immutable body/hash are never rewritten.');
}

echo "Work11 never-reserved supersession: $recoveryChecks checks PASS (host SQL/model doubles; native race/worker proof separate).\n";
