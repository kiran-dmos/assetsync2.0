<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-rest-batch-model.php';
ob_end_clean();

use GlpiPlugin\Assetsync20agent\RestPeer;
use GlpiPlugin\Assetsync20agent\RestSettings;
use GlpiPlugin\Assetsync20agent\RestWork;
use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;

final class SettingsModelDB
{
    public string $dbdefault = 'batch_test';
    public AgentSqlite $sql;
    public bool $releaseFails = false;
    public function __construct(public object $base) { $this->sql = $base->sql; }
    public function __call(string $name, array $args): mixed { return $this->base->$name(...$args); }
    public function doQuery(string $sql): mixed {
        if (str_starts_with($sql, 'SELECT COALESCE(IS_USED_LOCK(')) { return $this->sql->pdo->query('SELECT ' . (int) $this->sql->workerLockHeld . ' AS owned'); }
        if (str_starts_with($sql, 'SELECT RELEASE_LOCK(') && $this->releaseFails) { return $this->sql->pdo->query('SELECT NULL AS released'); }
        return $this->base->doQuery($sql);
    }
}

function policySetup(): array
{
    global $DB;
    [$settings, $work] = modelSetup();
    $DB = new SettingsModelDB($DB);
    Config::setConfigurationValues(Settings::CONTEXT, ['transport' => 'rest_records']);
    $old = $DB->firstRow(RestSettings::getTable(), ['settings_id' => $work['settings_id']]);
    $policy = json_decode($old['body'], true);
    $policy['version'] = 2; $policy['revision'] = 1; $policy['enabled'] = true; $policy['id'] = md5('policy-1');
    return [$policy, $work];
}

function policyPost(array $policy): int
{
    return (new RestSettings())->add(['settings_id' => $policy['id'], 'hash' => TaskExecutor::hash($policy), 'body' => json_encode($policy, JSON_THROW_ON_ERROR)]);
}

function policyReject(Closure $operation, string $message): void
{
    try { $operation(); throw new LogicException($message); }
    catch (RuntimeException) { modelCheck(true, $message); }
}

[$policy, $work] = policySetup();
$first = policyPost($policy);
modelCheck(policyPost($policy) === $first && RestSettings::active($policy), 'Equal exact revision is idempotent.');
$next = array_replace($policy, ['revision' => 3, 'id' => md5('policy-3')]);
policyPost($next);
policyReject(static fn () => policyPost(array_replace($policy, ['revision' => 2, 'id' => md5('policy-2')])), 'Out-of-order lower revision rejected.');
policyReject(static fn () => policyPost(array_replace($next, ['id' => md5('same-revision-different')])), 'Equal different revision rejected.');
modelCheck(RestSettings::active($next) && !RestSettings::active($policy), 'Highest committed revision alone is active.');
$tombstone = array_replace($next, ['revision' => 4, 'id' => md5('disabled'), 'enabled' => false]);
policyPost($tombstone);
modelCheck(!RestSettings::active($next) && !RestSettings::active($tombstone), 'Disable tombstone fences execution.');
policyReject(static fn () => policyPost($next), 'Replay cannot reactivate a disabled route.');
$head = (new ReflectionClass(RestSettings::class))->getConstant('HEAD');
$DB->doQuery('DELETE FROM `' . $head . '`');
policyReject(static fn () => policyPost($tombstone), 'Missing head with v2 history is not bootstrapped.');

[$policy, $work] = policySetup();
$before = iterator_to_array($DB->request(['FROM' => RestSettings::getTable()]));
$DB->sql->failOn = 'INSERT INTO `glpi_plugin_assetsync20agent_restsettingsheads`';
policyReject(static fn () => policyPost($policy), 'Head failure rolls back immutable settings acceptance.');
$DB->sql->failOn = null;
modelCheck(iterator_to_array($DB->request(['FROM' => RestSettings::getTable()])) === $before && !$DB->sql->workerLockHeld, 'Activation rollback and lock cleanup preserve previous records.');
RestPeer::$idle = false;
policyReject(static fn () => policyPost($policy), 'Caller transaction rejected before acquiring a lock.');
RestPeer::$idle = true;
$DB->sql->workerLockHeld = true;
policyReject(static fn () => policyPost($policy), 'Nested ownership rejected without releasing the outer worker lock.');
modelCheck($DB->sql->workerLockHeld, 'Outer lock remains owned.');
$DB->sql->workerLockHeld = false;
$id = policyPost($policy);
Config::setConfigurationValues(Settings::CONTEXT, ['transport' => 'adapter']);
$row = new RestSettings();
modelCheck($row->getFromDB($id), 'Historical v2 settings remain readable after transport rollback.');
policyReject(static fn () => policyPost($policy), 'Adapter transport cannot activate v2.');

[$policy, $work] = policySetup();
// Accept old v8 before activation, without obtaining the worker lock to compile it.
$DB->sql->beforeQuery = static function (string $sql) use ($DB): void {
    if (str_starts_with($sql, 'SELECT GET_LOCK(')) { $DB->sql->failOn = 'SELECT GET_LOCK('; }
};
try { modelPost($work); } catch (RuntimeException) {}
$DB->sql->beforeQuery = null; $DB->sql->failOn = null;
policyPost($policy);
modelPost($work);
$saved = $DB->firstRow(RestWork::getTable(), ['work_id' => $work['id']]);
modelCheck(json_decode($saved['result'], true)['reason'] === 'settings_superseded' && $saved['task_body'] === '{}'
    && !$DB->firstRow(TaskJournal::TABLE, ['id' => $work['id']]), 'Pre-activation accepted work becomes stale before compile or intent.');
modelCheck($DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original', 'Superseded work has no model effect.');

[$policy, $work] = policySetup();
policyPost($policy);
$work['settings_id'] = $policy['id']; $work['settings_hash'] = TaskExecutor::hash($policy);
$id = modelPost($work);
$terminal = $DB->firstRow(RestWork::getTable(), ['id' => $id]);
Config::setConfigurationValues(Settings::CONTEXT, ['transport' => 'adapter']);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['get_hateoas' => '0'];
$read = new RestWork();
modelCheck($read->getFromDB($id) && $read->fields['result'] === $terminal['result'], 'Historical v8/v2 terminal result remains exact after transport rollback.');

[$policy, $work] = policySetup();
policyPost($policy);
$work['settings_id'] = $policy['id']; $work['settings_hash'] = TaskExecutor::hash($policy);
$DB->base->crashAfterIntent = true;
policyReject(static fn () => modelPost($work), 'Injected lost process after durable intent propagates.');
$intent = $DB->firstRow(RestWork::getTable(), ['work_id' => $work['id']]);
modelCheck($intent['state'] === 'executing' && $intent['result'] === '{}', 'Executing intent is retained before head changes.');
policyPost(array_replace($policy, ['id' => md5('disabled-after-intent'), 'revision' => 2, 'enabled' => false]));
modelPost($work);
$uncertain = $DB->firstRow(RestWork::getTable(), ['work_id' => $work['id']]);
modelCheck(json_decode($uncertain['result'], true)['outcome'] === 'uncertain' && $uncertain['body'] === $intent['body']
    && $uncertain['hash'] === $intent['hash'] && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original',
    'New head cannot turn existing intent into no-mutation stale or replay its asset write.');

[$policy, $work] = policySetup();
$beforeHead = iterator_to_array($DB->request(['FROM' => $head]));
Profile::$rights = READ; Computer::$allowUpdate = false;
$request = ['version' => 9, 'id' => md5('definition-only'), 'kind' => 'metadata', 'installation' => $work['installation'],
    'registration' => $work['registration'], 'generation' => $work['generation'], 'connection_id' => 'b', 'itemtype' => 'Computer',
    'entity_id' => 100, 'items_id' => 0, 'pair_id' => '', 'expires' => time() + 300];
$DB->doQuery('DELETE FROM glpi_computers'); $DB->doQuery('DROP TABLE glpi_plugin_fields_computerscalars');
$id = modelPost($request);
$saved = $DB->firstRow(RestWork::getTable(), ['id' => $id]);
$result = json_decode($saved['result'], true);
modelCheck($result['outcome'] === 'observed' && $result['fields'][0]['key'] === 'name' && !isset($result['snapshot']), 'READ-only metadata needs no asset or Fields row.');
modelCheck(iterator_to_array($DB->request(['FROM' => $head])) === $beforeHead && count(iterator_to_array($DB->request(['FROM' => TaskJournal::TABLE]))) === 0,
    'Metadata never activates a head or creates mutation intent.');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['get_hateoas' => '0'];
$read = new RestWork(); $read->getFromDB($id);
modelCheck($DB->firstRow(RestWork::getTable(), ['id' => $id]) === $saved, 'GET reads persisted metadata without executing.');
modelPost($request);
modelCheck($DB->firstRow(RestWork::getTable(), ['id' => $id]) === $saved, 'Identical metadata POST preserves its original observation.');
Profile::$rights = 0;
policyReject(static fn () => (new RestWork())->getFromDB($id), 'Stored metadata GET still needs fresh service READ.');
Profile::$rights = READ;

[$policy, $work] = policySetup();
$request = array_replace($request, ['id' => md5('typed-inspection'), 'kind' => 'inspect', 'items_id' => 201, 'pair_id' => md5('candidate')]);
Profile::$rights = READ; Computer::$allowUpdate = false;
$id = modelPost($request);
$saved = $DB->firstRow(RestWork::getTable(), ['id' => $id]);
modelCheck(json_decode($saved['result'], true)['snapshot']['serial'] === 'SCALAR-1', 'Typed inspection uses real service READ without UPDATE or confirmed pair.');
Computer::$allowRead = false;
policyReject(static fn () => (new RestWork())->getFromDB($id), 'Fresh READ revocation hides retained inspection.');
Computer::$allowRead = true;
$DB->releaseFails = true;
policyReject(static fn () => Settings::withWorkerLock(static fn () => true), 'Release SQL success without released=1 is not cleanup proof.');
$DB->releaseFails = false; $DB->sql->workerLockHeld = false;
echo "REST settings model passed: ordered activation/rollback, stale fencing, retained reads, pair-free READ metadata and private head isolation (SQLite/native doubles).\n";
