<?php
/**
 * Script to import data from RootPanel SQL dump into the Namingo database.
 *
 * Usage: php import.php path_to_sql_file.sql
 */
 
ini_set('memory_limit', '512M');
set_time_limit(0);

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
$logFilePath = '/var/log/namingo/import.log';
$log = setupLogger($logFilePath, 'Registry_Import_RootPanel');
$log->info('job started.');

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

// Get the SQL file name from the command line argument
if ($argc < 2) {
    die("Usage: php import.php path_to_sql_file.sql\n");
}
$sqlFile = $argv[1];

// Check if file exists
if (!file_exists($sqlFile)) {
    die("File $sqlFile does not exist.\n");
}

// Read the SQL file
$columns = [];
$currentTable = '';
$parsingColumns = false;
$ordersDomainsData = [];
$usersProfileData = [];

$handle = fopen($sqlFile, 'r');
if (!$handle) {
    die("Unable to open file $sqlFile\n");
}

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    // Skip empty lines or comments
    if ($line === '' || strpos($line, '--') === 0 || strpos($line, '/*') === 0) {
        continue;
    }
    // Check for CREATE TABLE statements
    if (preg_match('/^CREATE TABLE `([^`]+)` \(/', $line, $matches)) {
        $currentTable = $matches[1];
        if ($currentTable === 'orders_domains' || $currentTable === 'users_profile') {
            $parsingColumns = true;
            $columns[$currentTable] = [];
        } else {
            $parsingColumns = false;
        }
        continue;
    }
    if ($parsingColumns) {
        if (strpos($line, ')') === 0 || strpos($line, 'PRIMARY KEY') !== false) {
            // End of columns
            $parsingColumns = false;
            continue;
        }
        // Extract the column name
        if (preg_match('/^`([^`]+)`/', $line, $colMatches)) {
            $colName = $colMatches[1];
            $columns[$currentTable][] = $colName;
        }
    }
    // Check for INSERT INTO statements
    if (preg_match('/^INSERT INTO `([^`]+)` VALUES/', $line, $matches)) {
        $tableName = $matches[1];
        if ($tableName === 'orders_domains' || $tableName === 'users_profile') {
            // Append the line to the appropriate data array
            if ($tableName === 'orders_domains') {
                $ordersDomainsData[] = $line;
            } else if ($tableName === 'users_profile') {
                $usersProfileData[] = $line;
            }
        }
    }
}
fclose($handle);

// Function to parse SQL values
function parseSqlValues($input) {
    $values = [];
    $length = strlen($input);
    $inQuote = false;
    $escape = false;
    $currentValue = '';
    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];
        if ($escape) {
            $currentValue .= $char;
            $escape = false;
        } else if ($char === '\\') {
            $currentValue .= $char;
            $escape = true;
        } else if ($char === "'") {
            $inQuote = !$inQuote;
            $currentValue .= $char;
        } else if ($char === ',' && !$inQuote) {
            // End of value
            $values[] = trimValue($currentValue);
            $currentValue = '';
        } else {
            $currentValue .= $char;
        }
    }
    // Add the last value
    if ($currentValue !== '') {
        $values[] = trimValue($currentValue);
    }
    return $values;
}

function trimValue($value) {
    $value = trim($value);
    if ($value === 'NULL') {
        return null;
    } else if (substr($value, 0, 1) === "'" && substr($value, -1) === "'") {
        // Remove the quotes and unescape
        $value = substr($value, 1, -1);
        $value = str_replace("\\'", "'", $value);
        $value = str_replace('\\\\', '\\', $value);
        return $value;
    } else {
        return $value;
    }
}

// Now we have $columns['orders_domains'] and $columns['users_profile']
// with the column names in order

// Next, parse the INSERT statements and map the values to columns

// For orders_domains
$ordersDomainsRows = [];
foreach ($ordersDomainsData as $insertStmt) {
    // Extract the values part
    $valuesPart = substr($insertStmt, strpos($insertStmt, 'VALUES') + 6);
    // The values could be multiple rows, separated by '),('
    $rows = explode('),(', trim($valuesPart, '();'));
    foreach ($rows as $row) {
        $row = trim($row, '()');
        // Parse the values
        $values = parseSqlValues($row);
        // Map the values to columns
        $rowAssoc = array_combine($columns['orders_domains'], $values);
        $ordersDomainsRows[] = $rowAssoc;
    }
}

// Similarly for users_profile
$usersProfileRows = [];
foreach ($usersProfileData as $insertStmt) {
    // Extract the values part
    $valuesPart = substr($insertStmt, strpos($insertStmt, 'VALUES') + 6);
    // The values could be multiple rows, separated by '),('
    $rows = explode('),(', trim($valuesPart, '();'));
    foreach ($rows as $row) {
        $row = trim($row, '()');
        // Parse the values
        $values = parseSqlValues($row);
        // Map the values to columns
        $rowAssoc = array_combine($columns['users_profile'], $values);
        $usersProfileRows[] = $rowAssoc;
    }
}

// Now, create a mapping from profileId to users_profile data
$usersProfileMap = [];
foreach ($usersProfileRows as $row) {
    // Assuming 'id' is the key in users_profile
    $profileId = $row['id'];
    $usersProfileMap[$profileId] = $row;
}

// Prepare caches and IDs
$hostCache = [];
$contactCache = [];

// Now, process each domain in orders_domains
foreach ($ordersDomainsRows as $domainRow) {
    $uid = $domainRow['uid'];
    $profileId = $domainRow['profileId'];
    // Check if both uid and profileId are 1000
    if ($uid == 1000 && $profileId == 1000) {
        $domainName = $domainRow['domain'];
        // Remove everything after the first dot to get the name
        $nameParts = explode('.', $domainName);
        $name = $nameParts[0]; // Take the part before the first dot

        // Check if name already exists in reserved_domain_names
        $stmt = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = :name");
        $stmt->execute([':name' => $name]);
        if ($stmt->fetch()) {
            echo "Reserved domain name already exists: $name\n";
        } else {
            // Insert into reserved_domain_names table
            $stmt = $pdo->prepare("INSERT INTO reserved_domain_names (name, type) VALUES (:name, :type)");
            $stmt->execute([
                ':name' => $name,
                ':type' => 'reserved',
            ]);
            echo "Inserted reserved domain name: $name\n";
        }
        continue;
    }

    // Determine clid and crid based on uid
    if ($uid == 1001) {
        $clid = $crid = 3;
    } else {
        $clid = $crid = 5;
    }

    // Get ns1 to ns4
    $ns1 = $domainRow['ns1'];
    $ns2 = $domainRow['ns2'];
    $ns3 = $domainRow['ns3'];
    $ns4 = $domainRow['ns4'];
    $nameservers = [];
    if (!empty($ns1)) $nameservers[] = $ns1;
    if (!empty($ns2)) $nameservers[] = $ns2;
    if (!empty($ns3)) $nameservers[] = $ns3;
    if (!empty($ns4)) $nameservers[] = $ns4;

    // Process the nameservers
    // For each nameserver, check if it exists in host table
    // If not, insert and get host_id
    $hostIds = [];
    foreach ($nameservers as $ns) {
        // Check if host exists
        $hostId = getHostId($ns, $pdo, $clid, $crid);
        $hostIds[] = $hostId;
    }

    // Get profileId from domainRow
    if (isset($usersProfileMap[$profileId])) {
        $profileData = $usersProfileMap[$profileId];
        // Process the profile data
        $contactId = processContact($profileData, $pdo);
    } else {
        // No profile data found, skip or use default contact
        $contactId = null;
        echo "No profile data found for profileId $profileId\n";
        continue;
    }

    // Insert domain into domain table
    $domainId = insertDomain($domainRow, $contactId, $pdo, $clid, $crid);

    // Insert into domain_host_map
    foreach ($hostIds as $hostId) {
        insertDomainHostMap($domainId, $hostId, $pdo);
    }

    echo "Imported domain: " . $domainRow['domain'] . "\n";
}

// Functions for processing contacts, hosts, domains, etc.

// Function to get or insert host and return host_id
function getHostId($hostName, $pdo, $clid, $crid) {
    global $hostCache;
    if (isset($hostCache[$hostName])) {
        return $hostCache[$hostName];
    }
    // Check if host exists
    $stmt = $pdo->prepare("SELECT id FROM host WHERE name = :name");
    $stmt->execute([':name' => $hostName]);
    $host = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($host) {
        $hostId = $host['id'];
    } else {
        // Insert host
        $stmt = $pdo->prepare("INSERT INTO host (name, clid, crid, crdate) VALUES (:name, :clid, :crid, NOW(3))");
        $stmt->execute([':name' => $hostName, ':clid' => $clid, ':crid' => $crid]);
        $hostId = $pdo->lastInsertId();
    }
    $hostCache[$hostName] = $hostId;
    return $hostId;
}

// Function to process contact data
function processContact($profileData, $pdo) {
    global $contactCache;
    $email = $profileData['email'];
    $voice = $profileData['phone'];
    $voice = preg_replace('/\s+/', '', $voice); // Remove spaces
    $voice = preg_replace('/(\+\d{3})(\d{2})(\d+)/', '$1.$2$3', $voice);
    $fax = $profileData['fax'];
    $nin = $profileData['idnum'];
    $nin_type = null;
    $orgValue = $profileData['org'];
    if ($orgValue === '1' || $orgValue === '2' || $orgValue === '3') {
        $nin_type = 'business';
    } else {
        $nin_type = 'personal';
    }

    // Determine clid and crid based on profileData['uid']
    $profileUid = $profileData['uid'];
    if ($profileUid == 1001) {
        $contactClid = $contactCrid = 3;
    } else {
        $contactClid = $contactCrid = 5;
    }

    $cacheKey = $email . '|' . $voice . '|' . $nin;
    if (isset($contactCache[$cacheKey])) {
        return $contactCache[$cacheKey];
    }

    // Generate identifier
    $identifier = generateIdentifier();

    // Insert into contact table
    $stmt = $pdo->prepare("INSERT INTO contact (identifier, email, voice, fax, nin, nin_type, clid, crid, crdate) VALUES (:identifier, :email, :voice, :fax, :nin, :nin_type, :clid, :crid, NOW(3))");
    $stmt->execute([
        ':identifier' => $identifier,
        ':email' => $email,
        ':voice' => $voice,
        ':fax' => $fax,
        ':nin' => $nin,
        ':nin_type' => $nin_type,
        ':clid' => $contactClid,
        ':crid' => $contactCrid,
    ]);
    $contactId = $pdo->lastInsertId();

    // Insert into contact_postalInfo
    // For type 'loc' and 'int'

    // Type 'loc'
    if (!empty($profileData['city'])) {
        $stmt = $pdo->prepare("INSERT INTO contact_postalInfo (contact_id, type, name, org, street1, city, sp, pc, cc) VALUES (:contact_id, :type, :name, :org, :street1, :city, :sp, :pc, :cc)");
        $stmt->execute([
            ':contact_id' => $contactId,
            ':type' => 'loc',
            ':name' => $profileData['name'] . ' ' . $profileData['surname'],
            ':org' => $profileData['firma'],
            ':street1' => $profileData['street'],
            ':city' => $profileData['city'],
            ':sp' => $profileData['oblast'],
            ':pc' => $profileData['post'],
            ':cc' => $profileData['country'],
        ]);
    }

    // Type 'int'
    if (!empty($profileData['cityeng'])) {
        $stmt = $pdo->prepare("INSERT INTO contact_postalInfo (contact_id, type, name, org, street1, city, sp, pc, cc) VALUES (:contact_id, :type, :name, :org, :street1, :city, :sp, :pc, :cc)");
        $stmt->execute([
            ':contact_id' => $contactId,
            ':type' => 'int',
            ':name' => $profileData['nameeng'] . ' ' . $profileData['surnameeng'],
            ':org' => $profileData['firmaeng'],
            ':street1' => $profileData['streeteng'],
            ':city' => $profileData['cityeng'],
            ':sp' => $profileData['oblast'],
            ':pc' => $profileData['post'],
            ':cc' => $profileData['country'],
        ]);
    }

    // Insert status 'ok' into contact_status
    $stmt = $pdo->prepare("INSERT INTO contact_status (contact_id, status) VALUES (:contact_id, :status)");
    $stmt->execute([':contact_id' => $contactId, ':status' => 'ok']);

    // Generate authinfo for contact
    $contactAuthinfo = generateAuthInfo();
    $stmt = $pdo->prepare("INSERT INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (:contact_id, :authtype, :authinfo)");
    $stmt->execute([':contact_id' => $contactId, ':authtype' => 'pw', ':authinfo' => $contactAuthinfo]);

    $contactCache[$cacheKey] = $contactId;
    return $contactId;
}

// Function to insert domain
function insertDomain($domainRow, $registrant, $pdo, $clid, $crid) {
    // Map domain data to domain table fields
    $name = $domainRow['domain'];
    // Other fields can be set to default or current timestamps
    $stmt = $pdo->prepare("INSERT INTO domain (name, tldid, registrant, clid, crid, crdate, exdate) VALUES (:name, :tldid, :registrant, :clid, :crid, :crdate, :exdate)");
    $crdate = $domainRow['startdate'] . ' 00:00:00'; // Assuming 'startdate' is in 'YYYY-MM-DD' format
    $exdate = $domainRow['todate'] . ' 00:00:00'; // Assuming 'todate' is in 'YYYY-MM-DD' format
    $stmt->execute([
        ':name' => $name,
        ':tldid' => 3,
        ':registrant' => $registrant,
        ':clid' => $clid,
        ':crid' => $crid,
        ':crdate' => $crdate,
        ':exdate' => $exdate,
    ]);
    $domainId = $pdo->lastInsertId();

    // Insert status 'ok' into domain_status
    $stmt = $pdo->prepare("INSERT INTO domain_status (domain_id, status) VALUES (:domain_id, :status)");
    $stmt->execute([':domain_id' => $domainId, ':status' => 'ok']);

    // Generate authinfo for domain
    $domainAuthinfo = generateAuthInfo();
    $stmt = $pdo->prepare("INSERT INTO domain_authInfo (domain_id, authtype, authinfo) VALUES (:domain_id, :authtype, :authinfo)");
    $stmt->execute([':domain_id' => $domainId, ':authtype' => 'pw', ':authinfo' => $domainAuthinfo]);

    return $domainId;
}

// Function to insert into domain_host_map
function insertDomainHostMap($domainId, $hostId, $pdo) {
    $stmt = $pdo->prepare("INSERT INTO domain_host_map (domain_id, host_id) VALUES (:domain_id, :host_id)");
    $stmt->execute([':domain_id' => $domainId, ':host_id' => $hostId]);
}

// Function to generate random authinfo ensuring at least two digits
function generateAuthInfo() {
    $length = 16;
    $charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $retVal = "";
    $digitCount = 0;

    // Generate initial random string
    for ($i = 0; $i < $length; $i++) {
        $randomIndex = random_int(0, strlen($charset) - 1);
        $char = $charset[$randomIndex];
        $retVal .= $char;
        if (ctype_digit($char)) {
            $digitCount++;
        }
    }

    // Ensure there are at least two digits in the string
    while ($digitCount < 2) {
        // Replace a non-digit character at a random position with a digit
        $replacePosition = random_int(0, $length - 1);
        if (!ctype_digit($retVal[$replacePosition])) {
            $randomDigit = strval(random_int(0, 9)); // Generate a digit from 0 to 9
            $retVal = substr($retVal, 0, $replacePosition) . $randomDigit . substr($retVal, $replacePosition + 1);
            $digitCount++;
        }
    }

    return $retVal;
}

// Function to generate unique identifier for contacts
function generateIdentifier() {
    // You can customize the prefix or format as needed
    return 'CID' . generateAuthInfo();
}

echo "Data import completed successfully.\n";