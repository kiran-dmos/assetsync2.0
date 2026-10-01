<?php

declare(strict_types=1);

if (!extension_loaded('mysqli') || getenv('GLPI_DB_USER') === false || getenv('GLPI_DB_PASSWORD') === false) {
    echo "MySQL timestamp test skipped (GLPI database environment unavailable).\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    getenv('ASSETSYNC_TEST_DB_HOST') ?: 'db',
    getenv('GLPI_DB_USER'),
    getenv('GLPI_DB_PASSWORD'),
    getenv('GLPI_DB_NAME') ?: 'glpi'
);

$db->query("SET time_zone = '+08:00'");
$db->query('CREATE TEMPORARY TABLE assetsync_timestamp_test (kind VARCHAR(20), at_time TIMESTAMP NULL)');
$db->query("INSERT INTO assetsync_timestamp_test VALUES ('legacy_utc', UTC_TIMESTAMP())");
$db->query("SET SESSION time_zone = '+00:00'");
$db->query("INSERT INTO assetsync_timestamp_test VALUES ('now', NOW())");
$db->query("SET SESSION time_zone = '+08:00'");
$rows = $db->query('SELECT kind, UNIX_TIMESTAMP(at_time) - UNIX_TIMESTAMP() AS age FROM assetsync_timestamp_test')->fetch_all(MYSQLI_ASSOC);
$ages = array_column($rows, 'age', 'kind');
if (abs((int) $ages['now']) > 3 || abs((int) $ages['legacy_utc'] + 28800) > 3) {
    throw new RuntimeException('Database timezone mismatch did not preserve the current TIMESTAMP epoch.');
}

$db->query("SET time_zone = '+00:00'");
$rows = $db->query('SELECT kind, UNIX_TIMESTAMP(at_time) - UNIX_TIMESTAMP() AS age FROM assetsync_timestamp_test')->fetch_all(MYSQLI_ASSOC);
$ages = array_column($rows, 'age', 'kind');
if (abs((int) $ages['now']) > 3 || abs((int) $ages['legacy_utc'] + 28800) > 3) {
    throw new RuntimeException('Changing the database session timezone changed stored TIMESTAMP epochs.');
}

$db->query("SET time_zone = 'America/New_York'");
$firstFold = strtotime('2026-11-01 05:59:30 UTC');
$secondFold = $firstFold + 60;
$db->query('TRUNCATE TABLE assetsync_timestamp_test');
$db->query('SET timestamp = ' . $firstFold);
$db->query("SET SESSION time_zone = '+00:00'");
$db->query("INSERT INTO assetsync_timestamp_test VALUES ('retry', DATE_ADD(NOW(), INTERVAL 60 SECOND))");
$db->query("SET SESSION time_zone = 'America/New_York'");
$db->query('SET timestamp = ' . $secondFold);
$db->query("SET SESSION time_zone = '+00:00'");
$db->query("INSERT INTO assetsync_timestamp_test VALUES ('started', NOW())");
$db->query("SET SESSION time_zone = 'America/New_York'");
$rows = $db->query('SELECT kind, UNIX_TIMESTAMP(at_time) AS epoch FROM assetsync_timestamp_test')->fetch_all(MYSQLI_ASSOC);
$epochs = array_column($rows, 'epoch', 'kind');
if ((int) $epochs['retry'] !== $secondFold || (int) $epochs['started'] !== $secondFold) {
    throw new RuntimeException('Timestamp writes lost an hour at the DST fold.');
}

echo "MySQL timestamp test passed.\n";
