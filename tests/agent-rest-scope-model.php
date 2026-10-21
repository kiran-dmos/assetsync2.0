<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-name-both-model.php';
ob_end_clean();

use GlpiPlugin\Assetsync20agent\RestSettings;
use GlpiPlugin\Assetsync20agent\RestWork;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;

function scopeModelSetup(): array
{
    Computer::$beforeModelUpdate = Computer::$afterModelUpdate = null;
    [$policy, $work] = policySetup();
    $policy['version'] = 3; $policy['id'] = md5('scope-policy');
    $policy['include_child_entities'] = true; $policy['asset_types'] = ['Computer', 'Printer'];
    $work['version'] = 10; $work['source_asset_entity'] = 12;
    $work['settings_id'] = $policy['id']; $work['settings_hash'] = TaskExecutor::hash($policy);
    return [$policy, $work];
}

[$policy, $work] = scopeModelSetup();
policyPost($policy);
$id = modelPost($work); $row = modelRow($work);
$result = json_decode($row['result'], true);
modelCheck($row['compiler'] === 10 && $result['outcome'] === 'verified'
    && $result['timing']['within_deadline'] && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === $work['name'], 'Work10 reuses real Name model/journal execution with stored timing.');
$journal = $DB->firstRow(TaskJournal::TABLE, ['id' => $work['id']]);
modelCheck($journal['report_status'] === 'rest' && $journal['result'] === $row['result'], 'Work10 projection and journal commit the same result outside adapter reporting.');
$history = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
modelCheck(modelPost($work) === $id && modelRow($work) === $row && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $history, 'Work10 immutable replay does not repeat the write or History.');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['get_hateoas' => '0'];
$read = new RestWork(); modelCheck($read->getFromDB($id) && $read->fields === $row, 'Work10 GET reads persisted result without execution.');
Config::setConfigurationValues(\GlpiPlugin\Assetsync20agent\Settings::CONTEXT,['transport'=>'adapter']);
$read=new RestWork(); modelCheck($read->getFromDB($id) && $read->fields === $row,'Historical Work10 body/hash/result remain readable after transport rollback; no active-policy reinterpretation.');

[$old, $work] = policySetup();
policyPost($old);
$policy = $old; $policy['version'] = 3; $policy['id'] = md5('upgrade-v3'); $policy['revision'] = 2;
$policy['include_child_entities'] = false; $policy['asset_types'] = ['Computer'];
policyPost($policy);
modelCheck(!RestSettings::active($old) && RestSettings::active($policy), 'V2 and v3 share the same head/revision sequence.');
policyReject(static fn () => policyPost($old), 'Retained v2 cannot reactivate after v3.');
policyReject(static fn () => policyPost(array_replace($old, ['id' => md5('downgrade'), 'revision' => 99])), 'Higher revision cannot downgrade the active schema.');
$tombstone = array_replace($policy, ['id' => md5('scope-disable'), 'revision' => 3, 'enabled' => false]);
policyPost($tombstone);
policyReject(static fn () => policyPost($policy), 'Old enabled v3 cannot reactivate a tombstone.');
modelCheck(!RestSettings::active($policy), 'Tombstone remains authoritative.');
$legacyRow = $DB->firstRow(RestSettings::getTable(), ['settings_id' => $old['id']]);
$read = new RestSettings();
modelCheck($read->getFromDB($legacyRow['id']) && $read->fields['body'] === $legacyRow['body'], 'Historical v2 GET remains exact after v3/tombstone.');

// The floor is schema-wide on the SAME head; revision99 cannot restore A authority after Both.
[$v2,$work]=policySetup(); policyPost($v2);
$v3=$v2; $v3['version']=3; $v3['id']=md5('floor-three'); $v3['revision']=2;
$v3['include_child_entities']=false; $v3['asset_types']=['Computer']; policyPost($v3);
$v4=$v3; $v4['version']=4; $v4['id']=md5('floor-four'); $v4['revision']=3;
$v4['mappings'][0]['source_of_truth']='both'; policyPost($v4);
$records=iterator_to_array($DB->request(['FROM'=>RestSettings::getTable()]));
foreach ([$v2,$v3] as $lower) {
    $lower['revision']=99; $lower['id']=md5('floor-denied-'.$lower['version']);
    policyReject(static fn () => policyPost($lower),'A higher revision cannot lower an active Both schema.');
}
modelCheck(RestSettings::active($v4) && iterator_to_array($DB->request(['FROM'=>RestSettings::getTable()])) === $records,'Rejected downgrade changes neither immutable records nor the current head.');
$off=$v4; $off['id']=md5('floor-four-off'); $off['revision']=4; $off['enabled']=false; $off['mappings']=[];
policyPost($off);
policyReject(static fn () => policyPost(array_replace($v3,['id'=>md5('floor-off-denied'),'revision'=>100])),'A version3 cannot override the version4 disable tombstone.');
policyReject(static fn () => policyPost($v4),'An old enabled Both record cannot reactivate after disable.');
$read=new RestSettings(); $old=$DB->firstRow(RestSettings::getTable(),['settings_id'=>$v2['id']]);
modelCheck($read->getFromDB($old['id']) && $read->fields['body'] === $old['body'],'Version floor never reinterprets historical lower-version GET evidence.');

[$policy,$work]=policySetup();
$policy['version']=4; $policy['id']=md5('no-legacy-authority-on-both');
$policy['include_child_entities']=false; $policy['asset_types']=['Computer']; $policy['mappings'][0]['source_of_truth']='both'; policyPost($policy);
$work['settings_id']=$policy['id']; $work['settings_hash']=TaskExecutor::hash($policy);
policyReject(static fn () => modelPost($work),'Work8 A-authority body cannot borrow an active Settings4 Both policy.');
modelCheck(modelRow($work) === [] && $DB->firstRow('glpi_computers',['id'=>201])['name'] === 'Original','Mismatched legacy compiler is rejected before acceptance or model mutation.');

[$policy,$work]=policySetup(); policyPost($policy);
$work['settings_id']=$policy['id']; $work['settings_hash']=TaskExecutor::hash($policy); $legacyId=modelPost($work); $legacyWork=modelRow($work);
$next=$policy; $next['version']=3; $next['id']=md5('historical-three'); $next['revision']=2; $next['include_child_entities']=false; $next['asset_types']=['Computer']; policyPost($next);
$next['version']=4; $next['id']=md5('historical-four'); $next['revision']=3; $next['mappings'][0]['source_of_truth']='both'; policyPost($next);
$history=iterator_to_array($DB->request(['FROM'=>'glpi_logs']));
$_SERVER['REQUEST_METHOD']='GET'; $_GET=['get_hateoas'=>'0']; $read=new RestWork();
modelCheck($read->getFromDB($legacyId) && $read->fields === $legacyWork,'Terminal immutable Work8/Settings2 stays byte-exact on GET after the same head reaches4.');
modelCheck(modelPost($work) === $legacyId && modelRow($work) === $legacyWork && iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === $history,'Retained old terminal POST reads its original journal/result, never reinterprets the current4 policy or repeats mutation.');

[$policy, $work] = scopeModelSetup();
$policy['include_child_entities'] = false;
policyPost($policy); $work['settings_hash'] = TaskExecutor::hash($policy);
policyReject(static fn () => modelPost($work), 'Exact source policy rejects a different source asset entity.');
modelCheck(modelRow($work) === [], 'Invalid new source scope writes no Work row.');
$work['source_asset_entity'] = $policy['source_entity'];
modelPost($work);
modelCheck(modelRow($work)['state'] === 'verified', 'Exact source entity remains supported.');

[$policy, $work] = scopeModelSetup();
policyPost($policy);
$v8 = $work; $v8['version'] = 8; unset($v8['source_asset_entity']);
policyReject(static fn () => modelPost($v8), 'Retained v8 schema cannot be reinterpreted under a v3 policy.');
foreach ([array_replace($work, ['source_asset_entity' => '12']), array_replace($work, ['itemtype' => 'Printer']), $work + ['new_key' => 1]] as $invalid) {
    policyReject(static fn () => RestWork::decode(json_encode($invalid), TaskExecutor::hash($invalid)), 'Unsupported/malformed Work10 input rejects explicitly.');
}
foreach ([array_replace($policy, ['asset_types' => ['Printer', 'Computer']]), array_replace($policy, ['asset_types' => ['Computer', 'Computer']]),
    array_replace($policy, ['asset_types' => ['Printer']]), array_replace($policy, ['include_child_entities' => 1])] as $invalid) {
    policyReject(static fn () => RestSettings::decode(json_encode($invalid), TaskExecutor::hash($invalid)), 'Noncanonical or unsupported scope rejects.');
}
$legacy = $policy; $legacy['version'] = 2;
policyReject(static fn () => RestSettings::decode(json_encode($legacy), TaskExecutor::hash($legacy)), 'New scope keys do not silently extend v2.');

[$policy, $work] = scopeModelSetup();
policyPost($policy);
$DB->base->crashAfterIntent = true;
policyReject(static fn () => modelPost($work), 'Lost Work10 execution process retains intent.');
$intent = modelRow($work);
policyPost(array_replace($policy, ['id' => md5('disabled-intent'), 'revision' => 2, 'enabled' => false]));
modelPost($work);
$uncertain = modelRow($work);
modelCheck($uncertain['state'] === 'uncertain' && $uncertain['body'] === $intent['body'] && $uncertain['hash'] === $intent['hash']
    && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original', 'New head never turns existing intent into no-mutation stale/replay.');

[$policy,$work]=scopeModelSetup(); policyPost($policy); Computer::$allowUpdate=false;
policyReject(static fn () => modelPost($work),'Work10 fresh service UPDATE denial cannot execute.');
$accepted=modelRow($work); $history=iterator_to_array($DB->request(['FROM'=>'glpi_logs']));
modelCheck($accepted['state'] === 'accepted' && $accepted['result'] === '{}' && $accepted['task_body'] === '{}','Denied Work10 remains durable acceptance, not mutation or completion.');
Computer::$allowUpdate=true; modelPost($work);
modelCheck(modelRow($work)['state'] === 'verified' && modelRow($work)['body'] === $accepted['body'] && modelRow($work)['hash'] === $accepted['hash'],'Restored service continues the same Work10 identity/body/hash.');
$terminal=modelRow($work); modelPost($work);
modelCheck(modelRow($work) === $terminal && count(iterator_to_array($DB->request(['FROM'=>'glpi_logs']))) === count($history)+1,'Repeated Work10 POST does not duplicate model/History.');

[$policy,$work]=scopeModelSetup(); policyPost($policy);
$DB->sql->beforeQuery=static function (string $sql): void { if (str_starts_with($sql,'INSERT INTO `glpi_logs`')) { BatchModelClock::$now+=6_000_000_000; } };
modelPost($work); $DB->sql->beforeQuery=null;
modelCheck(modelRow($work)['state'] === 'uncertain' && $DB->firstRow('glpi_computers',['id'=>201])['name'] === 'Original'
    && iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === [],'Work10 deadline overrun rolls back model/History and cannot expose false timing verification.');

[$policy,$work]=scopeModelSetup(); policyPost($policy);
$DB->sql->failOn='SELECT GET_LOCK('; policyReject(static fn () => modelPost($work),'Accepted Work10 can remain unstarted behind the worker lock.'); $DB->sql->failOn=null;
$accepted=modelRow($work); policyPost(array_replace($policy,['id'=>md5('ten-off-before-intent'),'revision'=>2,'enabled'=>false]));
modelPost($work); $stale=modelRow($work);
modelCheck(json_decode($stale['result'],true)['reason'] === 'settings_superseded' && $stale['body'] === $accepted['body'] && $stale['hash'] === $accepted['hash']
    && !$DB->firstRow(TaskJournal::TABLE,['id'=>$work['id']]),'Known unstarted Work10 becomes stale without creating an intent after policy disable.');
$restored=array_replace($policy,['id'=>md5('ten-restored'),'revision'=>3]); policyPost($restored);
$fresh=array_replace($work,['id'=>md5('ten-fresh'),'batch_id'=>md5('ten-fresh-batch'),'settings_id'=>$restored['id'],'settings_hash'=>TaskExecutor::hash($restored)]);
modelPost($fresh); $history=iterator_to_array($DB->request(['FROM'=>'glpi_logs'])); modelPost($work);
modelCheck(modelRow($fresh)['state'] === 'verified' && modelRow($work) === $stale && iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === $history,'A fresh Work10 can progress after re-enable; retained stale replay never repeats mutation.');
echo "REST scope B model passed: v3 shared head, schema isolation, Work10 Name execution/replay/timing and uncertain intent (SQLite/native doubles).\n";
