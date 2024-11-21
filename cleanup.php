<?php
/**
 * Script to clean up specific tables and reset AUTO_INCREMENT counters.
 *
 * Usage: php cleanup.php
 */

require __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '512M');
set_time_limit(0);

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/cleanup.log';
$log = setupLogger($logFilePath, 'Registry_Cleanup_RootPanel');
$log->info('job started.');

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Begin transaction
    $pdo->beginTransaction();

    // Disable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Order of cleanup because of constraints
    $tables = [
        'host',
        // Contact related tables
        'contact_authInfo',
        'contact_status',
        'contact_postalInfo',
        'contact',
        // Domain related tables
        'domain_host_map',
        'domain_authInfo',
        'domain_status',
        'domain',
    ];

    foreach ($tables as $table) {
        // Delete all records
        $stmt = $pdo->prepare("DELETE FROM `$table`");
        $stmt->execute();

        // Reset AUTO_INCREMENT counter
        $stmt = $pdo->prepare("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        $stmt->execute();

        echo "Cleaned table: $table\n";
    }

    // Re-enable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Commit transaction
    $pdo->commit();

    echo "Cleanup completed successfully.\n";

} catch (PDOException $e) {
    // Roll back transaction in case of error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $log->error('DB Connection failed: ' . $e->getMessage());
}