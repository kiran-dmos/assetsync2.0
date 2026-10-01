<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\AssetSyncCron;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\BillingFieldConfig;

function plugin_assetsync20_install(): bool
{
    GlpiBConnection::install();
    FieldMapping::install();
    EntitySyncRoute::install();

    if (!AssetSyncLink::install() || !AssetSyncQueue::install()) {
        return false;
    }

    AssetSyncCron::register();

    return true;
}

function plugin_assetsync20_uninstall(): bool
{
    AssetSyncCron::unregister();
    GlpiBConnection::uninstall();
    FieldMapping::uninstall();
    BillingFieldConfig::uninstall();
    EntitySyncRoute::uninstall();
    AssetSyncService::uninstall();

    return AssetSyncQueue::uninstall() && AssetSyncLink::uninstall();
}
