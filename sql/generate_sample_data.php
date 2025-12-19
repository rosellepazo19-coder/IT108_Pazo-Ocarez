<?php
/**
 * Generate aligned sample data for the cabadbaran_agriculture schema in BATCHED FILES.
 * - 10,000 users (split into 5 files of 2000 each)
 * - Seed equipment and supplies (1 file)
 * - ~6,000 borrow_records (split into 6 files of ~1000 each)
 * - 2,000 payments (split into 2 files of 1000 each)
 * - 1,500 login_attempts (split into 2 files)
 * - 3,000 activity_log entries (split into 3 files of 1000 each)
 *
 * Run: php sql/generate_sample_data.php
 * Output: Multiple batched SQL files in sql/batches/ directory
 */

ini_set('memory_limit', '512M');

// Configuration
$batchSize = 2000; // Users per file
$borrowBatchSize = 1000; // Borrows per file
$paymentBatchSize = 1000; // Payments per file
$loginBatchSize = 750; // Login attempts per file
$activityBatchSize = 1000; // Activities per file

// Reference data
$firstNames = ['Maria','Juan','Jose','Ana','Carlos','Rosa','Pedro','Carmen','Miguel','Elena','Antonio','Luz','Francisco','Teresa','Manuel','Dolores','Ramon','Concepcion','Fernando','Mercedes','Ricardo','Esperanza','Roberto','Rosario','Alberto','Isabel','Eduardo','Gloria','Alfredo','Patricia','Mario','Rebecca','Victor','Sofia','Enrique','Andrea','Daniel','Monica','Jorge','Laura','Luis','Beatriz','Sergio','Gabriela','Raul','Valeria','Oscar','Natalia','Pablo','Adriana','Felipe','Claudia'];
$lastNames  = ['Santos','Reyes','Cruz','Bautista','Ocampo','Garcia','Lopez','Mendoza','Torres','Gonzalez','Ramos','Villanueva','Fernandez','Romero','Del Rosario','Rivera','Aquino','Morales','Castillo','Dela Cruz','Martinez','Rodriguez','Perez','Sanchez','Gomez','Diaz','Flores','Castro','Ortega','Vargas','Herrera','Jimenez','Moreno','Alvarez','Medina','Silva','Vega','Navarro','Guerrero','Molina','Ortiz','Cortes','Pacheco','Campos','Vasquez','Rojas','Fuentes','Leon','Marquez'];
$barangays  = ['Poblacion 1','Poblacion 2','Poblacion 3','Poblacion 4','Poblacion 5','Poblacion 6','Poblacion 7','Poblacion 8','Poblacion 9','Calamba','Taguibo','Sumilihon','La Union','Mahayahay','Del Pilar','Sanghan','Antipolo','Puting Bato','Cabinet','Cayagong','Comagascas','Concepcion','Del Carmen','Kauswagan','Santo Niño','Talacogon','Tubay'];

$passwordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // password123
$dbName = 'cabadbaran_agriculture';

// Create batches directory
$batchesDir = __DIR__ . '/batches';
if (!is_dir($batchesDir)) {
    mkdir($batchesDir, 0755, true);
}

// Helper to create a new batch file
function createBatchFile($batchesDir, $filename, $description, $dbName) {
    $file = $batchesDir . '/' . $filename;
    $fp = fopen($file, 'w');
    if (!$fp) {
        die("Cannot create file: $file\n");
    }
    fwrite($fp, "-- Batch file: $filename\n");
    fwrite($fp, "-- $description\n");
    fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($fp, "USE `$dbName`;\n\n");
    fwrite($fp, "START TRANSACTION;\n\n");
    return $fp;
}

function closeBatchFile($fp) {
    fwrite($fp, "COMMIT;\n");
    fclose($fp);
}

// Helpers
function randDateTime(): string {
    $year = 2023 + rand(0, 2);
    $month = str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT);
    $day = str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
    $hour = str_pad(rand(8, 20), 2, '0', STR_PAD_LEFT);
    $min = str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT);
    return "$year-$month-$day $hour:$min:00";
}

function batchWrite($fp, $rows, $table, $columns, $isLastBatch = true) {
    if (empty($rows)) {
        return;
    }
    // Always terminate each INSERT with a semicolon; do not carry commas across batches
    $sql = "INSERT INTO `$table` ($columns) VALUES\n" . implode(",\n", $rows) . ";\n\n";
    fwrite($fp, $sql);
    fflush($fp);
}

// Seed equipment and supplies with ample quantity to satisfy borrow triggers
$equipSeed = [
    "('Hand Tractor','Used for land preparation',10000,'available')",
    "('Water Pump','For irrigation purposes',10000,'available')",
    "('Sprayer','For pesticide application',10000,'available')",
    "('Power Tiller','Bulk equipment',10000,'available')",
    "('Seeder','Row seeder',10000,'available')"
];
$supplySeed = [
    "('Fertilizer 14-14-14','Balanced fertilizer',20000,'available')",
    "('Seeds - Rice','High-yield rice seeds',20000,'available')",
    "('Pesticide','Organic pesticide',20000,'available')",
    "('Urea Bulk','Nitrogen fertilizer',20000,'available')",
    "('Corn Seeds','Hybrid corn seeds',20000,'available')"
];

// Create base file with equipment and supplies
$baseFile = createBatchFile($batchesDir, '01_base_inventory.sql', 'Equipment and Supplies inventory', $dbName);
fwrite($baseFile, "INSERT INTO `equipment` (`name`,`description`,`quantity`,`status`) VALUES\n" . implode(",\n", $equipSeed) . ";\n\n");
fwrite($baseFile, "INSERT INTO `supplies` (`name`,`description`,`quantity`,`status`) VALUES\n" . implode(",\n", $supplySeed) . ";\n\n");
closeBatchFile($baseFile);
echo "✓ Created base inventory file\n";

// Generate users in batches
$totalUsers = 10000;
$userFileNum = 1;
$currentBatchFile = null;
$userRows = [];
$usersInCurrentFile = 0;

echo "Generating $totalUsers users in batches...\n";

for ($i = 1; $i <= $totalUsers; $i++) {
    // Start new file if needed
    if ($usersInCurrentFile === 0) {
        if ($currentBatchFile) {
            closeBatchFile($currentBatchFile);
        }
        $filename = sprintf('02_users_batch_%02d.sql', $userFileNum);
        $currentBatchFile = createBatchFile($batchesDir, $filename, "Users batch $userFileNum (users " . (($userFileNum - 1) * $batchSize + 1) . " to " . min($userFileNum * $batchSize, $totalUsers) . ")", $dbName);
        echo "  Creating batch $userFileNum...\n";
    }

    $fn = $firstNames[array_rand($firstNames)];
    $ln = $lastNames[array_rand($lastNames)];
    $sex = ($i % 2 === 0) ? 'Male' : 'Female';
    $barangay = $barangays[array_rand($barangays)];
    $dob = (new DateTime('1975-01-01'))->add(new DateInterval('P' . rand(0, 18000) . 'D'))->format('Y-m-d');
    $age = max(18, min(70, (int)((new DateTime())->diff(new DateTime($dob)))->y));
    $email = strtolower(str_replace(' ', '', $fn . $ln . $i)) . '@example.com';
    $role = ($i % 50 === 0) ? 'staff' : 'borrower';
    $mobile = '09' . str_pad((string)rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);

    $userRows[] = "('2024-" . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . "', '$fn', 'M', '$ln', '', '$email', '$sex', '$dob', $age, '$mobile', 'Street $i', '$barangay', 'Cabadbaran', 'Agusan del Norte', 'Philippines', '8600', '$passwordHash', '$role', 'Q1','A1','Q2','A2','Q3','A3')";
    $usersInCurrentFile++;

    // Write batch when full or at end
    if ($usersInCurrentFile >= $batchSize || $i === $totalUsers) {
        $isLast = ($i === $totalUsers);
        batchWrite(
            $currentBatchFile,
            $userRows,
            'users',
            '`idnum`,`Fname`,`Mname`,`Lname`,`Suffix`,`mail`,`sex`,`Birthday`,`Age`,`mobile`,`Street`,`Barangay`,`City`,`Province`,`Country`,`ZipCode`,`password`,`role`,`secQ1`,`secA1`,`secQ2`,`secA2`,`secQ3`,`secA3`',
            $isLast
        );
        $userRows = [];
        if ($usersInCurrentFile >= $batchSize) {
            closeBatchFile($currentBatchFile);
            $currentBatchFile = null;
            $usersInCurrentFile = 0;
            $userFileNum++;
        }
    }
}
if ($currentBatchFile) {
    closeBatchFile($currentBatchFile);
}
echo "✓ Created " . ($userFileNum) . " user batch files\n";

// Generate borrow_records in batches
$borrowRows = [];
$borrowCount = 0;
$borrowFileNum = 1;
$currentBorrowFile = null;
$borrowsInCurrentFile = 0;

echo "Generating borrow_records in batches...\n";

for ($userId = 1; $userId <= $totalUsers; $userId++) {
    if (rand(1, 100) > 60) {
        continue; // ~40% skip borrows
    }

    // Start new file if needed
    if ($borrowsInCurrentFile === 0 && $currentBorrowFile === null) {
        $filename = sprintf('03_borrows_batch_%02d.sql', $borrowFileNum);
        $currentBorrowFile = createBatchFile($batchesDir, $filename, "Borrow records batch $borrowFileNum", $dbName);
    }

    $itemType = (rand(0, 1) === 0) ? 'equipment' : 'supplies';
    $itemId = rand(1, 5);
    $statusRoll = rand(1, 100);

    if ($statusRoll <= 55) {
        $status = 'borrowed';
        $dateBorrowed = randDateTime();
        $dueDate = date('Y-m-d H:i:s', strtotime($dateBorrowed . ' + ' . rand(5, 20) . ' days'));
        $returnDate = 'NULL';
    } elseif ($statusRoll <= 75) {
        $status = 'reserved';
        $dateBorrowed = 'NULL';
        $dueDate = randDateTime();
        $returnDate = 'NULL';
    } elseif ($statusRoll <= 90) {
        $status = 'returned';
        $dateBorrowed = randDateTime();
        $returnDateDT = date('Y-m-d H:i:s', strtotime($dateBorrowed . ' + ' . rand(1, 10) . ' days'));
        $dueDate = date('Y-m-d H:i:s', strtotime($dateBorrowed . ' + ' . rand(3, 15) . ' days'));
        $returnDate = "'$returnDateDT'";
    } else {
        $status = 'overdue';
        $dateBorrowed = randDateTime();
        $dueDate = date('Y-m-d H:i:s', strtotime($dateBorrowed . ' - ' . rand(1, 5) . ' days'));
        $returnDate = 'NULL';
    }

    $borrowRows[] = "($userId, $itemId, '$itemType', " . ($dateBorrowed === 'NULL' ? 'NULL' : "'$dateBorrowed'") . ", '$dueDate', $returnDate, '$status', 'auto-gen')";
    $borrowCount++;
    $borrowsInCurrentFile++;

    // Write batch when full
    if ($borrowsInCurrentFile >= $borrowBatchSize) {
        batchWrite(
            $currentBorrowFile,
            $borrowRows,
            'borrow_records',
            '`user_id`,`item_id`,`item_type`,`date_borrowed`,`due_date`,`return_date`,`status`,`remarks`',
            true
        );
        closeBatchFile($currentBorrowFile);
        $borrowRows = [];
        $borrowsInCurrentFile = 0;
        $currentBorrowFile = null;
        $borrowFileNum++;
        echo "  Created borrow batch " . ($borrowFileNum - 1) . " ($borrowCount total)...\n";
    }
}

// Write remaining borrows
if (!empty($borrowRows) && $currentBorrowFile) {
    batchWrite(
        $currentBorrowFile,
        $borrowRows,
        'borrow_records',
        '`user_id`,`item_id`,`item_type`,`date_borrowed`,`due_date`,`return_date`,`status`,`remarks`',
        true
    );
    closeBatchFile($currentBorrowFile);
}
echo "✓ Created " . ($borrowFileNum - 1 + (!empty($borrowRows) ? 1 : 0)) . " borrow batch files ($borrowCount total records)\n";

// Generate payments in batches
$paymentRows = [];
$totalPayments = min(2000, $borrowCount);
$paymentFileNum = 1;
$currentPaymentFile = null;
$paymentsInCurrentFile = 0;

echo "Generating $totalPayments payments in batches...\n";

for ($i = 1; $i <= $totalPayments; $i++) {
    // Start new file if needed
    if ($paymentsInCurrentFile === 0) {
        if ($currentPaymentFile) {
            closeBatchFile($currentPaymentFile);
        }
        $filename = sprintf('04_payments_batch_%02d.sql', $paymentFileNum);
        $currentPaymentFile = createBatchFile($batchesDir, $filename, "Payments batch $paymentFileNum", $dbName);
    }

    $borrowId = rand(1, $borrowCount);
    $amount = number_format(rand(500, 15000) / 100, 2, '.', '');
    $paymentRows[] = "($borrowId, $amount, '" . randDateTime() . "')";
    $paymentsInCurrentFile++;

    // Write batch when full or at end
    if ($paymentsInCurrentFile >= $paymentBatchSize || $i === $totalPayments) {
        $isLast = ($i === $totalPayments);
        batchWrite(
            $currentPaymentFile,
            $paymentRows,
            'payments',
            '`borrow_id`,`amount`,`date_paid`',
            $isLast
        );
        $paymentRows = [];
        if ($paymentsInCurrentFile >= $paymentBatchSize) {
            closeBatchFile($currentPaymentFile);
            $currentPaymentFile = null;
            $paymentsInCurrentFile = 0;
            $paymentFileNum++;
        }
    }
}
if ($currentPaymentFile) {
    closeBatchFile($currentPaymentFile);
}
echo "✓ Created " . ($paymentFileNum) . " payment batch files\n";

// Generate login_attempts in batches
$loginAttemptRows = [];
$totalLoginAttempts = 1500;
$loginFileNum = 1;
$currentLoginFile = null;
$loginsInCurrentFile = 0;

echo "Generating $totalLoginAttempts login_attempts in batches...\n";

for ($i = 1; $i <= $totalLoginAttempts; $i++) {
    // Start new file if needed
    if ($loginsInCurrentFile === 0) {
        if ($currentLoginFile) {
            closeBatchFile($currentLoginFile);
        }
        $filename = sprintf('05_login_attempts_batch_%02d.sql', $loginFileNum);
        $currentLoginFile = createBatchFile($batchesDir, $filename, "Login attempts batch $loginFileNum", $dbName);
    }

    // Generate email using same pattern as users (80% match existing users, 20% random)
    if (rand(1, 100) <= 80) {
        // Match existing user email pattern
        $userId = rand(1, $totalUsers);
        $fn = $firstNames[array_rand($firstNames)];
        $ln = $lastNames[array_rand($lastNames)];
        $email = strtolower(str_replace(' ', '', $fn . $ln . $userId)) . '@example.com';
    } else {
        // Some invalid/random emails
        $fn = $firstNames[array_rand($firstNames)];
        $ln = $lastNames[array_rand($lastNames)];
        $email = strtolower(str_replace(' ', '', $fn . $ln . rand(10000, 99999))) . '@example.com';
    }
    
    $attempts = rand(1, 5);
    $lastAttempt = randDateTime();
    
    $loginAttemptRows[] = "('" . addslashes($email) . "', $attempts, '$lastAttempt')";
    $loginsInCurrentFile++;

    // Write batch when full or at end
    if ($loginsInCurrentFile >= $loginBatchSize || $i === $totalLoginAttempts) {
        $isLast = ($i === $totalLoginAttempts);
        batchWrite(
            $currentLoginFile,
            $loginAttemptRows,
            'login_attempts',
            '`email`,`attempts`,`last_attempt`',
            $isLast
        );
        $loginAttemptRows = [];
        if ($loginsInCurrentFile >= $loginBatchSize) {
            closeBatchFile($currentLoginFile);
            $currentLoginFile = null;
            $loginsInCurrentFile = 0;
            $loginFileNum++;
        }
    }
}
if ($currentLoginFile) {
    closeBatchFile($currentLoginFile);
}
echo "✓ Created " . ($loginFileNum) . " login_attempts batch files\n";

// Generate activity_log entries in batches
$activityLogRows = [];
$totalActivities = 3000;
$activityFileNum = 1;
$currentActivityFile = null;
$activitiesInCurrentFile = 0;

echo "Generating $totalActivities activity_log entries in batches...\n";

$activityActions = [
    'User logged in',
    'User logged out',
    'User updated profile',
    'User changed password',
    'Admin viewed dashboard',
    'Staff processed borrow request',
    'Admin approved equipment addition',
    'Staff updated inventory',
    'System backup completed',
    'Report generated',
    'User registration completed',
    'Password reset completed',
    'Equipment maintenance scheduled',
    'Supply restocked',
    'Borrow request cancelled',
    'Payment processed',
    'Overdue notification sent',
    'User account deactivated',
    'User account activated',
    'System configuration updated'
];

for ($i = 1; $i <= $totalActivities; $i++) {
    // Start new file if needed
    if ($activitiesInCurrentFile === 0) {
        if ($currentActivityFile) {
            closeBatchFile($currentActivityFile);
        }
        $filename = sprintf('06_activity_log_batch_%02d.sql', $activityFileNum);
        $currentActivityFile = createBatchFile($batchesDir, $filename, "Activity log batch $activityFileNum", $dbName);
    }

    // Some entries have user_id, some are NULL (system actions)
    $userId = (rand(1, 100) <= 70) ? rand(1, $totalUsers) : 'NULL';
    $action = $activityActions[array_rand($activityActions)];
    $dateTime = randDateTime();
    
    $activityLogRows[] = "($userId, '" . addslashes($action) . "', '$dateTime')";
    $activitiesInCurrentFile++;

    // Write batch when full or at end
    if ($activitiesInCurrentFile >= $activityBatchSize || $i === $totalActivities) {
        $isLast = ($i === $totalActivities);
        batchWrite(
            $currentActivityFile,
            $activityLogRows,
            'activity_log',
            '`user_id`,`action`,`date_time`',
            $isLast
        );
        $activityLogRows = [];
        if ($activitiesInCurrentFile >= $activityBatchSize) {
            closeBatchFile($currentActivityFile);
            $currentActivityFile = null;
            $activitiesInCurrentFile = 0;
            $activityFileNum++;
        }
    }
}
if ($currentActivityFile) {
    closeBatchFile($currentActivityFile);
}
echo "✓ Created " . ($activityFileNum) . " activity_log batch files\n";

// Create import script
$importScript = $batchesDir . '/import_all.sh';
$importScriptWin = $batchesDir . '/import_all.bat';
$importScriptContent = "#!/bin/bash\n# Import all batch files in order\n";
$importScriptContent .= "cd \"$(dirname \"\$0\")\"\n\n";
$importScriptWinContent = "@echo off\nREM Import all batch files in order\n";
$importScriptWinContent .= "cd /d \"%~dp0\"\n\n";

$files = glob($batchesDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $basename = basename($file);
    $importScriptContent .= "echo Importing $basename...\n";
    $importScriptContent .= "mysql -u root -p $dbName < \"$basename\"\n\n";
    
    $importScriptWinContent .= "echo Importing $basename...\n";
    $importScriptWinContent .= "mysql -u root -p $dbName < \"$basename\"\n\n";
}

file_put_contents($importScript, $importScriptContent);
chmod($importScript, 0755);
file_put_contents($importScriptWin, $importScriptWinContent);

echo "\n✓ Done! All batch files created in: $batchesDir/\n";
echo "✓ Generated data for all tables:\n";
echo "  - users: $totalUsers (in " . ($userFileNum) . " files)\n";
echo "  - equipment: 5 items (in 01_base_inventory.sql)\n";
echo "  - supplies: 5 items (in 01_base_inventory.sql)\n";
echo "  - borrow_records: $borrowCount (in " . ($borrowFileNum - 1 + (!empty($borrowRows) ? 1 : 0)) . " files)\n";
echo "  - payments: $totalPayments (in " . ($paymentFileNum) . " files)\n";
echo "  - login_attempts: $totalLoginAttempts (in " . ($loginFileNum) . " files)\n";
echo "  - activity_log: $totalActivities (in " . ($activityFileNum) . " files)\n";
echo "\nTo import, run:\n";
echo "  Linux/Mac: bash $batchesDir/import_all.sh\n";
echo "  Windows: $batchesDir\\import_all.bat\n";
echo "  Or import files manually in numerical order (01, 02, 03...)\n";

