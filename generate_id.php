<?php
header('Content-Type: application/json');

require_once 'includes/db_connect.php';

// Use the same database connection from db_connect.php
$connect = $mysqli;

if ($connect->connect_error) {
    echo json_encode(["error" => "Database connection failed: " . $connect->connect_error]);
    exit;
}

// Get current year
$currentYear = date('Y');

// Get the last ID number for the current year
// Format: YYYY-#### (e.g., 2025-0001, 2025-0002)
$stmt = $connect->prepare("SELECT idnum FROM users WHERE idnum LIKE ? ORDER BY idnum DESC LIMIT 1");
$yearPattern = $currentYear . '-%';
$stmt->bind_param("s", $yearPattern);
$stmt->execute();
$result = $stmt->get_result();

$nextSequence = 1; // Default to 1 if no records found for this year

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $lastId = $row['idnum'];
    
    // Extract sequence number from last ID (e.g., "2025-0001" -> 1)
    if (preg_match('/^' . $currentYear . '-(\d+)$/', $lastId, $matches)) {
        $lastSequence = (int)$matches[1];
        $nextSequence = $lastSequence + 1;
    }
}

$stmt->close();

// Generate new ID in format YYYY-####
$idnum = $currentYear . '-' . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);

// Double check if ID already exists (safety check)
$checkStmt = $connect->prepare("SELECT 1 FROM users WHERE idnum = ? LIMIT 1");
$checkStmt->bind_param("s", $idnum);
$checkStmt->execute();
$checkStmt->store_result();
$exists = $checkStmt->num_rows > 0;
$checkStmt->close();

if ($exists) {
    // If somehow it exists, try next number
    $nextSequence++;
    $idnum = $currentYear . '-' . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
}

echo json_encode(["idnum" => $idnum]);
?>

