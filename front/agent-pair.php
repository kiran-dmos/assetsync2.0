<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\EntitySyncRoute;

if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', dirname(__DIR__, 3)); }
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
Session::checkCentralAccess();
Session::checkRight('config', UPDATE);
header('Cache-Control: no-store');
global $DB, $CFG_GLPI;
$connectionId = (string) ($_REQUEST['connection'] ?? '');
$type = (string) ($_REQUEST['itemtype'] ?? 'Computer');
$localEntity = (int) ($_REQUEST['local_entity'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$localSearch = (string) ($_REQUEST['local_search'] ?? '');
$localCursor = max(0, (int) ($_REQUEST['local_cursor'] ?? 0));
$viewContext = ['itemtype' => $type, 'local_entity' => $localEntity, 'local_search' => $localSearch,
    'local_cursor' => $localCursor, 'cart_page' => max(0, (int) ($_REQUEST['cart_page'] ?? 0)),
    'pair_cursor' => max(0, (int) ($_REQUEST['pair_cursor'] ?? 0))];
$message = '';
$credentials = null;
$results = [];
if (!isset($_SESSION['assetsync_agent_selection'][$connectionId][$type])) { $_SESSION['assetsync_agent_selection'][$connectionId][$type] = []; }
$cart = &$_SESSION['assetsync_agent_selection'][$connectionId][$type];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $pairId = (string) ($_POST['pair_id'] ?? '');
        if ($pairId !== '') {
            $pair = AgentTask::byId($connectionId, $pairId);
            if (!$pair || !AgentTask::model($pair['itemtype'])->can((int) $pair['local_id'], READ)) { throw new RuntimeException('Pair unavailable.'); }
        }
        switch ($_POST['action'] ?? '') {
            case 'register':
                $credentials = AgentTask::register($connectionId, (string) ($_POST['installation'] ?? ''));
                $message = 'Installation registered. Credentials are shown once.';
                break;
            case 'rotate': $credentials = AgentTask::rotate($connectionId); $message = 'Token rotated. Update B with the token shown once.'; break;
            case 'discover':
                AgentTask::discover($connectionId, $type, (int) ($_POST['remote_entity'] ?? -1),
                    (string) ($_POST['remote_search'] ?? ''), (int) ($_POST['remote_cursor'] ?? 0));
                $message = 'Discovery pending. Waiting for B.';
                break;
            case 'select_page':
                $cart = AgentTask::selectPage($cart, $type, $localEntity, $localSearch, $localCursor, (array) ($_POST['selected'] ?? []));
                $message = count($cart) . ' A assets selected.';
                break;
            case 'clear_selection': $cart = []; $message = 'A selection cleared.'; break;
            case 'candidates':
                $discovery = AgentTask::discovery($connectionId);
                if ($discovery['state'] !== 'ready' || $discovery['task']['id'] !== ($_POST['discovery_id'] ?? '')
                    || $discovery['task']['itemtype'] !== $type) { throw new RuntimeException('B observation is stale or changed. Request discovery again.'); }
                $choices = (array) ($_POST['remote_choice'] ?? []);
                if (count($choices) > 50) { throw new RuntimeException('At most 50 candidate pairs per request.'); }
                $remotes = array_column($discovery['page']['rows'], null, 'items_id');
                $binding = AgentTask::binding($connectionId);
                foreach ($choices as $id => $choice) {
                    if ($choice === '') { continue; }
                    $label = 'Selected A row';
                    try {
                        if (!isset($cart[$id])) { throw new RuntimeException('Asset is not in the A selection.'); }
                        $local = AgentTask::selectedAsset($type, (int) $id, (int) $cart[$id]);
                        $label = $local['name'];
                        $remote = $remotes[(int) $choice] ?? null;
                        if (!$remote || $remote['reason'] !== '' || $remote['itemtype'] !== $type) { throw new RuntimeException('Choose an available B asset from the fresh displayed page.'); }
                        if ($local['serial'] === '' || $remote['serial'] === '' || $local['serial'] !== $remote['serial']) { throw new RuntimeException('Both assets must have the same nonblank serial.'); }
                        $result = AgentTask::declare($connectionId, $binding['installation'], $local['items_id'], $remote['items_id'], $remote['entity_id'], $type);
                        $results[] = $label . ': ' . ($result['inspection_error'] ?? 'Candidate saved; independent inspection pending.');
                        unset($cart[$id]);
                    } catch (Throwable $error) { $results[] = $label . ': ' . $error->getMessage(); }
                }
                break;
            case 'confirm_selected':
                $ids = (array) ($_POST['confirm_pair'] ?? []);
                if (count($ids) > 50) { throw new RuntimeException('At most 50 confirmations per request.'); }
                foreach ($ids as $id) {
                    $label = 'Selected pair';
                    try {
                        $candidate = AgentTask::byId($connectionId, (string) $id);
                        if (!$candidate) { throw new RuntimeException('Pair unavailable.'); }
                        $local = AgentTask::selectedAsset($candidate['itemtype'], (int) $candidate['local_id'], (int) $candidate['local_entity']);
                        $label = $candidate['itemtype'] . ': ' . $local['name'];
                        AgentTask::confirm($connectionId, (string) ($_POST['routes'][$id] ?? ''), (string) $id);
                        $results[] = $label . ': Confirmed.';
                    } catch (Throwable $error) { $results[] = $label . ': ' . $error->getMessage(); }
                }
                break;
            case 'inspect': AgentTask::inspect($connectionId, $pairId); $message = 'Inspection pending.'; break;
            case 'retry': AgentTask::retryStale($connectionId, $pairId); $message = 'No-write task retained. Refresh observation to inspect again.'; break;
            case 'confirm': AgentTask::confirm($connectionId, (string) ($_POST['route'] ?? ''), $pairId); $message = 'Pair confirmed.'; break;
            case 'unconfirm': AgentTask::unconfirm($connectionId, $pairId); $message = 'Pair unconfirmed. Notification revocation will reach B on its next subscription page.'; break;
        }
    } catch (Throwable $error) { $message = $error->getMessage(); }
}
\GlpiPlugin\Assetsync20\SyncActivity::header($_SERVER['PHP_SELF']);
$html = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$hidden = static function (array $values) use ($html): void {
    foreach ($values as $key => $value) { echo '<input type="hidden" name="' . $html($key) . '" value="' . $html($value) . '">'; }
};
$post = static function (array $values = []) use ($connectionId, $hidden, $viewContext): void {
    echo '<form method="post">';
    $hidden(array_merge($viewContext, ['connection' => $connectionId, '_glpi_csrf_token' => Session::getNewCSRFToken()], $values));
};
echo '<div class="assetsync-page"><h2>Agent Pair</h2><p>' . $html($message) . '</p>';
foreach ($results as $result) { echo '<p>' . $html($result) . '</p>'; }
if ($credentials !== null) {
    echo '<dl>';
    foreach ($credentials as $key => $value) { echo '<dt>' . $html($key) . '</dt><dd><code>' . $html($value) . '</code></dd>'; }
    echo '</dl>';
}
echo '<form method="get"><label>Connection <select name="connection"><option value="">Select connection</option>';
foreach (GlpiBConnection::loadAll() as $connection) {
    if (!GlpiBConnection::isAgent($connection)) { continue; }
    echo '<option value="' . $html($connection['id']) . '"' . ($connection['id'] === $connectionId ? ' selected' : '') . '>' . $html($connection['name']) . '</option>';
}
echo '</select></label> <button class="btn btn-secondary btn-sm">Open</button></form>';
$connection = GlpiBConnection::find($connectionId);
if (!$connection || !GlpiBConnection::isAgent($connection)) { echo '</div>'; Html::footer(); return; }
$binding = AgentTask::binding($connectionId);
if (!$binding) {
    $post();
    echo '<label>B installation identity <input name="installation" required pattern="[a-f0-9]{32}"></label> '
        . '<button class="btn btn-primary btn-sm" name="action" value="register">Register installation</button></form>';
    echo '</div>'; Html::footer(); return;
}
echo '<p>Connection binding: <code>' . $html($connectionId) . '</code></p>';
$post();
echo '<button class="btn btn-secondary btn-sm" name="action" value="rotate">Rotate token</button></form>';
$discovery = AgentTask::discovery($connectionId);
echo '<h3>Asset selection</h3><p>B discovery: ' . $html(str_replace('_', ' ', $discovery['state'])) . '</p>';
echo '<p><a href="?' . $html(http_build_query($viewContext + ['connection' => $connectionId])) . '">Refresh status</a></p>';
if (isset($discovery['page']['observed_at'])) { echo '<p>Observed ' . $html(gmdate('Y-m-d H:i:s', $discovery['page']['observed_at'])) . ' UTC</p>'; }
echo '<form method="get">';
$hidden(['connection' => $connectionId]);
echo '<label>Type <select name="itemtype">';
foreach ($CFG_GLPI['inventory_types'] ?? [] as $availableType) {
    $supported = in_array($availableType, ['Computer', 'Printer'], true);
    $reason = !$supported ? 'unsupported in this version'
        : (!isset($discovery['capabilities']) ? 'B capability pending discovery'
            : (($discovery['capabilities'][$availableType] ?? 'Unavailable on B')));
    $disabled = !$supported || (isset($discovery['capabilities']) && $reason !== '');
    echo '<option value="' . $html($availableType) . '"' . ($type === $availableType ? ' selected' : '') . ($disabled ? ' disabled' : '') . '>'
        . $html($availableType . ($reason === '' ? '' : ': ' . $reason)) . '</option>';
}
echo '</select></label> <label>A entity ';
Dropdown::show('Entity', ['name' => 'local_entity', 'value' => $localEntity]);
echo '</label> <label>A name or serial <input name="local_search" maxlength="100" value="' . $html($localSearch) . '"></label> <button class="btn btn-secondary btn-sm">Search A</button></form>';
$filters = ['itemtype' => $type, 'local_entity' => $localEntity, 'local_search' => $localSearch, 'local_cursor' => $localCursor];
$post($filters);
echo '<label>B entity <select name="remote_entity"><option value="-1">Discover permitted entities</option>';
foreach ($discovery['page']['entities'] ?? [] as $id => $name) {
    echo '<option value="' . (int) $id . '"' . ((int) ($discovery['task']['entity_id'] ?? -1) === (int) $id ? ' selected' : '') . '>' . $html($name) . '</option>';
}
echo '</select></label> <label>B name or serial <input name="remote_search" maxlength="100" value="' . $html($discovery['task']['filter']['search'] ?? '')
    . '"></label> <button class="btn btn-secondary btn-sm" name="action" value="discover">Request B discovery</button></form>';
try {
    $localPage = AgentTask::localPage($type, $localEntity, $localSearch, $localCursor);
    $pagePairs = [];
    if ($localPage['rows'] !== []) {
        foreach ($DB->request(['SELECT' => ['local_id', 'confirmed'], 'FROM' => AgentTask::PAIRS,
            'WHERE' => ['connection_id' => $connectionId, 'itemtype' => $type,
                'local_id' => array_column($localPage['rows'], 'items_id')]]) as $pagePair) {
            $pagePairs[(int) $pagePair['local_id']] = $pagePair['confirmed'] ? 'Confirmed' : 'Candidate';
        }
    }
    $post($filters);
    echo '<h4>A assets</h4><table class="tab_cadre_fixe"><tr><th><label><input type="checkbox" id="select-a-page"> Select this page (max 50)</label></th><th>Name</th><th>Type</th><th>Serial</th><th>Entity</th><th>Pair status</th></tr>';
    foreach ($localPage['rows'] as $row) {
        echo '<tr><td><input class="a-selection" type="checkbox" name="selected[]" value="' . $row['items_id'] . '"'
            . (isset($cart[$row['items_id']]) ? ' checked' : '') . ($row['serial'] === '' || isset($pagePairs[$row['items_id']]) ? ' disabled' : '') . '></td><td>' . $html($row['name'])
            . '</td><td>' . $html($type) . '</td><td>' . $html($row['serial'] === '' ? 'Serial is missing.' : $row['serial'])
            . '</td><td>' . $html(Dropdown::getDropdownName('glpi_entities', $row['entity_id'])) . '</td><td>'
            . $html($pagePairs[$row['items_id']] ?? 'Unpaired') . '</td></tr>';
    }
    echo '</table><button class="btn btn-primary btn-sm" name="action" value="select_page">Save page selection</button> '
        . '<button class="btn btn-secondary btn-sm" name="action" value="clear_selection">Clear selection</button></form>';
    echo '<script>document.getElementById("select-a-page").addEventListener("change", function () { document.querySelectorAll(".a-selection:not(:disabled)").forEach(box => { box.checked = this.checked; }); });</script>';
    echo '<p>' . count($cart) . ' A assets selected (limit 200).</p>';
    $remoteRows = ($discovery['task']['itemtype'] ?? '') === $type && $discovery['state'] === 'ready' ? ($discovery['page']['rows'] ?? []) : [];
    $cartPage = max(0, (int) ($_REQUEST['cart_page'] ?? 0));
    $post($filters + ['discovery_id' => $discovery['task']['id'] ?? '', 'cart_page' => $cartPage]);
    echo '<h4>Candidate choices</h4><table class="tab_cadre_fixe"><tr><th>A name</th><th>Type</th><th>A serial</th><th>A entity</th><th>B asset (name / type / serial / entity)</th></tr>';
    foreach (array_slice($cart, $cartPage * 50, 50, true) as $id => $entity) {
        try { $local = AgentTask::selectedAsset($type, (int) $id, (int) $entity); }
        catch (Throwable $error) { echo '<tr><td colspan="5">' . $html($error->getMessage()) . '</td></tr>'; continue; }
        echo '<tr><td>' . $html($local['name']) . '</td><td>' . $html($type) . '</td><td>' . $html($local['serial'])
            . '</td><td>' . $html(Dropdown::getDropdownName('glpi_entities', $entity)) . '</td><td><select name="remote_choice[' . (int) $id . ']">'
            . '<option value="">Choose B asset independently</option>';
        foreach ($remoteRows as $remote) {
            $reason = $remote['reason'];
            if ($reason === '' && ($local['serial'] === '' || $local['serial'] !== $remote['serial'])) { $reason = 'Serial mismatch'; }
            $label = $remote['name'] . ' / ' . $remote['itemtype'] . ' / ' . $remote['serial'] . ' / ' . ($discovery['page']['entities'][$remote['entity_id']] ?? '');
            echo '<option value="' . $remote['items_id'] . '"' . ($reason !== '' ? ' disabled' : '') . '>' . $html($label . ($reason !== '' ? ' (' . $reason . ')' : '')) . '</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</table><button class="btn btn-primary btn-sm" name="action" value="candidates"' . ($remoteRows === [] ? ' disabled' : '') . '>Save chosen candidate pairs</button></form>';
    if ($cartPage > 0) { echo '<a href="?' . $html(http_build_query($filters + ['connection' => $connectionId, 'cart_page' => $cartPage - 1])) . '">Previous selections</a> '; }
    if (count($cart) > ($cartPage + 1) * 50) { echo '<a href="?' . $html(http_build_query($filters + ['connection' => $connectionId, 'cart_page' => $cartPage + 1])) . '">Next selections</a> '; }
    echo '<h4>B discovery page</h4><table class="tab_cadre_fixe"><tr><th>Name</th><th>Type</th><th>Serial</th><th>Entity</th><th>Availability</th></tr>';
    foreach (($discovery['task']['itemtype'] ?? '') === $type ? ($discovery['page']['rows'] ?? []) : [] as $row) {
        echo '<tr><td>' . $html($row['name']) . '</td><td>' . $html($row['itemtype']) . '</td><td>' . $html($row['serial'])
            . '</td><td>' . $html($discovery['page']['entities'][$row['entity_id']] ?? '') . '</td><td>'
            . $html($discovery['state'] === 'ready' ? $row['reason'] : 'Fresh discovery required.') . '</td></tr>';
    }
    echo '</table>';
    if ($localPage['next_cursor'] !== null) {
        echo '<a href="?' . $html(http_build_query(array_replace($filters, ['connection' => $connectionId, 'local_cursor' => $localPage['next_cursor']]))) . '">Next A page</a>';
    }
    if (($discovery['page']['next_cursor'] ?? null) !== null) {
        $post($filters + ['remote_entity' => $discovery['task']['entity_id'], 'remote_search' => $discovery['task']['filter']['search'], 'remote_cursor' => $discovery['page']['next_cursor']]);
        echo '<button class="btn btn-secondary btn-sm" name="action" value="discover">Request next B page</button></form>';
    }
} catch (Throwable $error) { echo '<p>' . $html($error->getMessage()) . '</p>'; }
echo '<h3>Candidate and confirmed pairs</h3>';
echo '<form method="post" id="confirm-pairs">';
$hidden($viewContext + ['connection' => $connectionId, '_glpi_csrf_token' => Session::getNewCSRFToken()]);
echo '<button class="btn btn-primary btn-sm" name="action" value="confirm_selected">Confirm selected pairs</button></form>';
$pairCursor = $viewContext['pair_cursor'];
$pairs = $DB->request(['FROM' => AgentTask::PAIRS, 'WHERE' => ['connection_id' => $connectionId], 'ORDER' => 'itemtype, local_id', 'LIMIT' => 50, 'START' => $pairCursor]);
$count = 0;
foreach ($pairs as $pair) {
    $count++;
    $item = AgentTask::model($pair['itemtype']);
    if (!$item->can((int) $pair['local_id'], READ) || !$item->getFromDB((int) $pair['local_id'])) { continue; }
    $post(['pair_id' => $pair['id']]);
    $url = $item::getFormURL() . '?id=' . (int) $pair['local_id'];
    $snapshot = json_decode($pair['snapshot'], true);
    $remoteUrl = rtrim($connection['base_url'], '/') . '/front/' . strtolower($pair['itemtype']) . '.form.php?id=' . (int) $pair['computer_id'];
    echo '<p><a href="' . $html($url) . '">' . $html($pair['itemtype'] . ': ' . $item->fields['name']) . '</a> / <a href="' . $html($remoteUrl) . '">B: '
        . $html($snapshot['name'] ?? $snapshot['fields']['name']['value'] ?? ('#' . $pair['computer_id'])) . '</a>'
        . ' / ' . ($pair['confirmed'] ? 'Confirmed' : 'Candidate') . '</p>';
    echo '<p>A serial: ' . $html($item->fields['serial']) . ' / A entity: '
        . $html(Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']))
        . ' / B observed serial: ' . $html($snapshot['serial'] ?? 'Pending') . ' / B entity: '
        . $html($discovery['page']['entities'][$pair['entity_id']] ?? ('#' . $pair['entity_id'])) . '</p>';
    echo '<p>Observed: ' . ($pair['observed_at'] ? $html(gmdate('Y-m-d H:i:s', (int) $pair['observed_at'])) . ' UTC' : 'Pending')
        . ((int) $pair['observed_at'] < time() - 300 ? ' / Fresh observation required before confirmation.' : '') . '</p>';
    echo '<label><input type="checkbox" form="confirm-pairs" name="confirm_pair[]" value="' . $html($pair['id']) . '"> Select pair</label> ';
    echo '<label>Exact route <select form="confirm-pairs" name="routes[' . $html($pair['id']) . ']"><option value="">Select route</option>';
    foreach (EntitySyncRoute::loadAll() as $route) {
        if ($route['glpi_b_connection_id'] === $connectionId && $route['asset_types'] === [$pair['itemtype']]
            && $route['active'] && !$route['include_child_entities']
            && (string) $route['glpi_a_source_entity_id'] === (string) $pair['local_entity']
            && (string) $route['glpi_b_target_entity_id'] === (string) $pair['entity_id']) {
            echo '<option value="' . $html($route['id']) . '">' . $html($route['name']) . '</option>';
        }
    }
    echo '</select></label> <button class="btn btn-secondary btn-sm" name="action" value="inspect">Refresh observation</button>';
    if ($pair['confirmed']) { echo ' <button class="btn btn-secondary btn-sm" name="action" value="unconfirm">Unconfirm pair</button>'; }
    $task = AgentTask::outstanding($connectionId, $pair);
    if ($task) {
        echo '<p>Task: ' . $html($task['state']) . '</p>';
        if (AgentTask::canRetry($task)) { echo '<button class="btn btn-secondary btn-sm" name="action" value="retry">Retry no-write stale task</button>'; }
    }
    echo '</form>';
}
if ($count === 50) { echo '<a href="?' . $html(http_build_query(array_replace($viewContext, ['connection' => $connectionId, 'pair_cursor' => $pairCursor + 50]))) . '">Next pairs</a>'; }
echo '<p><a href="entitysyncroutes.php?connection_id=' . rawurlencode($connectionId) . '">Entity routes</a> | <a href="fieldmapping.php?connection_id=' . rawurlencode($connectionId) . '&amp;itemtype=' . rawurlencode($type) . '">Field mappings</a></p>';
foreach (AgentTask::recent($connectionId) as $recent) { echo '<p>Task ' . $html($recent['id']) . ': ' . $html($recent['state']) . '</p>'; }
echo '</div>';
Html::footer();
