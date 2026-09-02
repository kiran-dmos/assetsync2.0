<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\EntitySyncRoute;

function plugin_assetsync20_install(): bool
{
    GlpiBConnection::install();
    FieldMapping::install();
    EntitySyncRoute::install();

    return true;
}

function plugin_assetsync20_uninstall(): bool
{
    GlpiBConnection::uninstall();
    FieldMapping::uninstall();
    EntitySyncRoute::uninstall();

    return true;
}
