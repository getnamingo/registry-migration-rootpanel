<?php
/**
 * Script to update disclosure fields in contact and contact_postalInfo tables.
 *
 * Usage: php update_disclosure_fields.php
 */

require __DIR__ . '/vendor/autoload.php';

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
$log = setupLogger($logFilePath, 'Registry_Addon_RootPanel');
$log->info('job started.');

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);

    // Begin transaction
    $pdo->beginTransaction();

    // Update disclosure fields in the contact table
    $contactUpdateQuery = "
        UPDATE contact
        SET
            disclose_voice = '0',
            disclose_fax = '0',
            disclose_email = '0'
    ";
    $pdo->exec($contactUpdateQuery);
    echo "Updated disclosure fields in contact table.\n";

    // Update disclosure fields in the contact_postalInfo table
    $contactPostalInfoUpdateQuery = "
        UPDATE contact_postalInfo
        SET
            disclose_name_int = '0',
            disclose_name_loc = '0',
            disclose_org_int = '0',
            disclose_org_loc = '0',
            disclose_addr_int = '0',
            disclose_addr_loc = '0'
    ";
    $pdo->exec($contactPostalInfoUpdateQuery);
    echo "Updated disclosure fields in contact_postalInfo table.\n";

    // Commit transaction
    $pdo->commit();

    echo "Disclosure fields updated successfully.\n";

} catch (PDOException $e) {
    // Roll back transaction in case of error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Database error: " . $e->getMessage() . "\n");
}
