<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\Plugin;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';

if (class_exists('Session') && method_exists('Session', 'checkRight')) {
    Session::checkRight('config', defined('READ') ? READ : 1);
}

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

echo '<div class="center">';
echo '<h2>' . htmlspecialchars(Plugin::NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
echo '<p>Barebone GLPI plugin scaffold is installed and ready for implementation.</p>';
echo '</div>';

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
