<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\BillingFieldConfig;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\Plugin;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';

if (class_exists('Session') && method_exists('Session', 'checkRight')) {
    Session::checkRight('config', defined('READ') ? READ : 1);
}

$message = null;
$messageClass = 'info';
$invalidConfig = false;
try {
    $config = BillingFieldConfig::load();
} catch (RuntimeException $error) {
    $config = [];
    $invalidConfig = true;
    $message = $error->getMessage();
    $messageClass = 'warning';
}
$legacyMode = $config === null;
$legacyWarnings = [];
if ($legacyMode) {
    $draft = BillingFieldConfig::legacyDraft();
    $config = $draft['config'];
    $legacyWarnings = $draft['warnings'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (class_exists('Session') && method_exists('Session', 'checkRight')) {
        Session::checkRight('config', defined('UPDATE') ? UPDATE : 2);
    }

    $posted = $_POST['billing_fields'] ?? [];
    try {
        BillingFieldConfig::save(is_array($posted) ? $posted : [], !empty($_POST['confirm_disable_legacy']));
        $config = BillingFieldConfig::load();
        $legacyMode = false;
        $invalidConfig = false;
        $message = 'Billing fields saved.';
        $messageClass = 'success';
    } catch (RuntimeException $error) {
        $message = $error->getMessage();
        $messageClass = 'warning';
        $config = is_array($posted) ? $posted : [];
    }
}

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

$html = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

echo '<div class="center">';
echo '<h2>Billing Field Configuration</h2>';
echo '<p><a href="' . $html(Menu::configUrl()) . '">Back to AssetSync2.0 dashboard</a></p>';
if ($message !== null) {
    echo '<div class="' . $html($messageClass) . '">' . $html($message) . '</div>';
}
if ($legacyMode) {
    echo '<div class="warning"><strong>Legacy billing mode is in effect. These detected selections are a draft; billing changes only when you save.</strong>';
    foreach ($legacyWarnings as $warning) {
        echo '<p>' . $html($warning) . '</p>';
    }
    if (empty($config['hardware']['enabled']) && empty($config['swsd']['enabled'])) {
        echo '<p><strong>No active workflow could be detected. Saving both disabled will stop legacy billing.</strong></p>';
    }
    echo '</div>';
}
echo '<p>Native Computer Status and Model can be used as inputs. No native Computer column is a safe billing output; choose Fields-plugin fields for outputs.</p>';

echo '<form method="post" action="' . $html(Menu::billingFieldsUrl()) . '">';
foreach (BillingFieldConfig::roles() as $workflow => $roles) {
    $title = $workflow === 'hardware' ? 'Hardware Billing' : 'SW/SD Billing';
    $saved = is_array($config[$workflow] ?? null) ? $config[$workflow] : [];
    echo '<table class="tab_cadre_fixe">';
    echo '<tr><th colspan="2">' . $html($title) . '</th></tr>';
    echo '<tr><td>Enabled</td><td><label><input type="checkbox" name="billing_fields[' . $html($workflow) . '][enabled]" value="1"'
        . (!empty($saved['enabled']) ? ' checked' : '') . '> Calculate billing fields</label></td></tr>';
    foreach ($roles as $role => [$label, $types, $isOutput]) {
        $selected = is_string($saved[$role] ?? null) ? $saved[$role] : '';
        $options = BillingFieldConfig::options($workflow, $role);
        echo '<tr><td><label for="' . $html($workflow . '_' . $role) . '">' . $html($label)
            . ($isOutput ? ' (output)' : '') . '</label></td><td>';
        echo '<select id="' . $html($workflow . '_' . $role) . '" name="billing_fields[' . $html($workflow) . '][' . $html($role) . ']">';
        echo '<option value="">Select a field</option>';
        foreach (['native' => 'Native GLPI', 'custom' => 'Fields plugin'] as $group => $groupLabel) {
            echo '<optgroup label="' . $html($groupLabel) . '">';
            foreach ($options[$group] as $key => $optionLabel) {
                echo '<option value="' . $html($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . $html($optionLabel) . '</option>';
            }
            echo '</optgroup>';
        }
        if ($selected !== '' && !array_key_exists($selected, $options['native']) && !array_key_exists($selected, $options['custom'])) {
            echo '<option value="' . $html($selected) . '" selected>Unavailable: ' . $html($selected) . '</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</table>';
}
if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">';
}
if ($legacyMode || $invalidConfig) {
    echo '<p><label><input type="checkbox" name="confirm_disable_legacy" value="1"> I understand that saving with both workflows disabled stops billing.</label></p>';
}
echo '<p><button type="submit" name="save" value="1" class="submit">Save billing fields</button></p>';
echo '</form></div>';

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
