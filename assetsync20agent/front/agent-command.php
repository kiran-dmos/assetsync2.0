<?php
declare(strict_types=1);
if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', dirname(__DIR__, 3)); }
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
\GlpiPlugin\Assetsync20agent\CommandReceiver::handle();
