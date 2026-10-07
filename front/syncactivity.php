<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\SyncActivity;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';

try {
    SyncActivity::checkAccess();
} catch (Throwable) {
    http_response_code(403);
    echo 'Access denied.';
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo 'This page accepts local reads only.';
    return;
}
try {
    $filters = SyncActivity::filters($_GET);
} catch (InvalidArgumentException) {
    http_response_code(400);
    echo 'Invalid activity filters.';
    return;
}
$unavailable = false;
$detail = null;
$activity = null;
try {
    $activity = new SyncActivity($GLOBALS['DB']);
    $listing = $activity->listing($filters);
    if ($filters['id'] !== '') {
        $detail = $activity->detail((int) $filters['id']);
    }
} catch (Throwable) {
    // Database errors and configuration contents may contain credentials.
    $unavailable = true;
}
SyncActivity::header((string) ($_SERVER['PHP_SELF'] ?? ''));

$html = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$stylesheet = dirname(Menu::syncActivityUrl(), 2) . '/css/assetsync20.css?v=' . hash_file('sha256', __DIR__ . '/../public/css/assetsync20.css');
echo '<link rel="stylesheet" href="' . $html($stylesheet) . '">';
$url = static function (array $changes = []) use ($filters): string {
    $query = array_merge($filters, $changes);
    return Menu::syncActivityUrl() . '?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== ''));
};
$time = static function (mixed $value) use ($html): string {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts)
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
        return 'Not recorded';
    }
    return $html($value);
};
$assets = static function (array $row) use ($activity, $html): string {
    $path = SyncActivity::assetPath($row['itemtype'], (int) $row['items_id']);
    $local = Html::getPrefixedUrl($path);
    $label = $row['asset_name'] !== '' ? $row['asset_name'] : $row['itemtype'] . ' #' . $row['items_id'];
    $result = '<span class="assetsync-activity-asset">A: <a href="' . $html($local) . '">' . $html($label) . '</a></span>';
    $result .= '<small>' . $html($row['itemtype']) . ' #' . (int) $row['items_id'] . '</small>';
    $remote = $activity->remoteUrl($row);
    $result .= '<span class="assetsync-activity-asset">B: ' . ($remote !== null
        ? '<a href="' . $html($remote) . '" target="_blank" rel="noopener noreferrer">Open asset #' . (int) $row['remote_items_id'] . '</a>'
        : 'Asset link unavailable') . '</span>';
    return $result;
};

echo '<div class="assetsync-page assetsync-activity"><h2>Sync Activity</h2>';
echo '<div class="assetsync-activity-actions"><a href="' . $html(Menu::configUrl()) . '">Back to dashboard</a><a class="submit" href="' . $html($url(['id' => ''])) . '">Refresh activity</a></div>';
echo '<h3>Current Work</h3><p class="assetsync-activity-note">Latest queue state only. Finished jobs do not prove field changes; retrying or blocked jobs may follow partial updates.</p>';
if ($unavailable) {
    echo '<p class="warning">Activity data is unavailable. Ask an administrator to check the local queue and connection configuration.</p></div>';
    Html::footer();
    return;
}
echo '<form method="get" action="' . $html(Menu::syncActivityUrl()) . '" class="assetsync-activity-filters">';
foreach (['connection' => ['Connection', 'All connections'], 'entity' => ['GLPI A entity', 'All entities'],
    'type' => ['Asset type', 'All asset types'], 'state' => ['Queue state', 'All statuses']] as $key => [$label, $allLabel]) {
    echo '<div><label for="activity-' . $key . '">' . $html($label) . '</label><select id="activity-' . $key . '" name="' . $key . '"><option value="">' . $html($allLabel) . '</option>';
    foreach ($listing['options'][$key] as $value => $text) {
        echo '<option value="' . $html($value) . '"' . ((string) $value === $filters[$key] ? ' selected' : '') . '>' . $html($text) . '</option>';
    }
    // Preserve an unavailable filter without exposing any out-of-scope label.
    if ($filters[$key] !== '' && !array_key_exists($filters[$key], $listing['options'][$key])) {
        echo '<option value="' . $html($filters[$key]) . '" selected>Selection unavailable</option>';
    }
    echo '</select></div>';
}
echo '<div><label for="activity-search">Asset name / A or queued B ID</label><input id="activity-search" type="search" name="search" maxlength="120" value="' . $html($filters['search']) . '"></div>';
echo '<div class="assetsync-activity-filter-actions"><button class="submit" type="submit">Apply</button><a href="' . $html(Menu::syncActivityUrl()) . '">Clear</a></div></form>';
echo '<dl class="assetsync-activity-summary"><div><dt>Filtered asset-connection pairs</dt><dd>' . $listing['total'] . ' of ' . $listing['all'] . '</dd></div>';
foreach (SyncActivity::STATES as $state => $label) {
    echo '<div><dt>' . $html($label) . '</dt><dd>' . $listing['counts'][$state] . '</dd></div>';
}
echo '<div><dt>Needs another check</dt><dd>' . ($listing['recheck'] === null ? 'Not recorded' : $listing['recheck']) . '</dd></div></dl>';
if ($listing['recheck'] === null) {
    echo '<p class="assetsync-activity-note">Installed queue records do not contain a recheck flag. Stored queue states are shown without inferring another check.</p>';
}
if ($listing['rows'] === []) {
    echo '<p class="assetsync-activity-empty">' . ($listing['total'] === 0 ? 'No work matches these filters.' : 'No rows on this page. Choose Latest.') . '</p>';
} else {
    echo '<div class="assetsync-table-scroll" tabindex="0" role="region" aria-label="Current work, horizontally scrollable"><table class="tab_cadre_fixe assetsync-activity-table"><caption class="assetsync-activity-note">' . count($listing['rows']) . ' of ' . $listing['total'] . ' filtered pairs. Up to 50 rows per page, newest queue IDs first.</caption><thead><tr>';
    foreach (['Assets', 'Connection / entity', 'Queue state', 'Attempts (current row)', 'Started / finished (stored)', 'Stored last sync time', 'Stored retry time', 'What happened / What to do', 'Details'] as $heading) {
        echo '<th scope="col">' . $html($heading) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
        $state = SyncActivity::currentState($row);
        $stateLabel = $state === 'pending' && $row['status'] !== 'pending' ? 'Waiting: recheck requested' : (SyncActivity::STATES[$state] ?? 'Unknown');
        echo '<tr><td>' . $assets($row) . '</td><td>' . $html($activity->connectionName($row['glpi_b_connection_id'])) . '<small>' . $html($row['completename'] ?: 'Entity #' . $row['entities_id']) . '</small></td>';
        echo '<td>' . $html($stateLabel) . ((int) $row['needs_recheck'] === 1 ? '<small>Needs another check</small>' : '') . '</td><td>' . max(0, (int) $row['attempts']) . '</td>';
        echo '<td>' . $time($row['started_at']) . '<small>' . $time($row['finished_at']) . '</small></td><td>' . $time($row['link_last_sync_at']) . '</td><td>' . $time($row['available_at']) . '</td>';
        echo '<td>' . $html(SyncActivity::reason($row)) . '</td><td><a href="' . $html($url(['id' => (string) $row['id']])) . '#activity-details" aria-label="Details for ' . $html($row['asset_name']) . '">Details</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '<nav class="assetsync-activity-pagination" aria-label="Activity pages">';
if ($listing['newer']) {
    echo '<a href="' . $html($url(['before' => '', 'after' => (string) $listing['rows'][0]['id'], 'id' => ''])) . '">Newer</a>';
} else {
    echo '<span>Newer</span>';
}
echo '<a href="' . $html($url(['before' => '', 'after' => '', 'id' => ''])) . '">Latest</a>';
if ($listing['older']) {
    echo '<a href="' . $html($url(['before' => (string) $listing['rows'][count($listing['rows']) - 1]['id'], 'after' => '', 'id' => ''])) . '">Older</a>';
} else {
    echo '<span>Older</span>';
}
echo '</nav><p class="assetsync-activity-note">Times are stored database-session values; their timezone is not recorded here. A stored retry time is not a guarantee of when the next run will happen.</p>';
if ($filters['id'] !== '') {
    echo '<section id="activity-details" aria-labelledby="activity-detail-title"><h3 id="activity-detail-title">Current work details</h3>';
    if ($detail === null) {
        echo '<p>This queue row is unavailable or outside the assets you can view.</p>';
    } else {
        echo '<p>' . $html(SyncActivity::reason($detail)) . '</p><table class="tab_cadre_fixe assetsync-activity-detail"><tbody>';
        $state = SyncActivity::currentState($detail);
        $details = [
            'Queue ID' => (string) $detail['id'],
            'Current work state' => $state === 'pending' && $detail['status'] !== 'pending' ? 'Waiting: recheck requested' : (SyncActivity::STATES[$state] ?? 'Unknown'),
            'Stored queue state' => $detail['status'],
            'Needs another check' => $detail['needs_recheck'] === null ? 'Not recorded' : ((int) $detail['needs_recheck'] === 1 ? 'Yes' : 'No'),
            'Connection' => $activity->connectionName($detail['glpi_b_connection_id']),
            'Stored route ID' => SyncActivity::validKey($detail['route_id']) ? $detail['route_id'] : 'Not recorded',
            'Attempts (current queue row only)' => (string) max(0, (int) $detail['attempts']),
            'Queued GLPI B ID' => (int) $detail['remote_items_id'] > 0 ? (string) (int) $detail['remote_items_id'] : 'Not recorded',
            'Linked GLPI B ID' => (int) $detail['link_remote_items_id'] > 0 ? (string) (int) $detail['link_remote_items_id'] : 'Not recorded',
            'Last verified field success' => 'Not recorded',
            'Field updates / old values / new values / field outcomes' => 'Not recorded',
            'Executing identity / origin / operation ID' => 'Not recorded',
            'Audit evidence' => 'Not recorded',
        ];
        foreach ($details as $label => $value) {
            echo '<tr><th scope="row">' . $html($label) . '</th><td>' . $html($value) . '</td></tr>';
        }
        echo '<tr><th scope="row">Assets</th><td>' . $assets($detail) . '</td></tr>';
        foreach (['started_at' => 'Stored start time', 'finished_at' => 'Stored finish time', 'date_mod' => 'Stored queue update time', 'available_at' => 'Stored retry time', 'link_last_sync_at' => 'Stored last sync time'] as $key => $label) {
            echo '<tr><th scope="row">' . $html($label) . '</th><td>' . $time($detail[$key]) . '</td></tr>';
        }
        echo '</tbody></table><p class="assetsync-activity-note">Current queue and link records do not contain per-field results. No previous values or changes are reconstructed from current assets or mappings.</p>';
        echo '<h4>Native UUID reconciliation</h4><ul>';
        foreach ($detail['uuid_outcomes'] ?? [] as $code) {
            $label = \GlpiPlugin\Assetsync20\AssetUuidService::OUTCOMES[$code] ?? null;
            if ($label !== null) {
                echo '<li>' . $html($label) . '</li>';
            }
        }
        if (empty($detail['uuid_outcomes'])) {
            echo '<li>No UUID operation recorded.</li>';
        }
        echo '</ul>';
    }
    echo '</section>';
}
echo '</div>';
Html::footer();
