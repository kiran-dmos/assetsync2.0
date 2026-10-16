<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20agent\AgentApi;

if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', dirname(__DIR__, 3)); }
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';

(new AgentApi())->call();

