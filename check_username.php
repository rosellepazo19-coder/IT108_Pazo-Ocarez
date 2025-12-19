<?php
header('Content-Type: application/json');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'cabadbaran_agriculture';

$connect = new mysqli($host, $user, $pass, $db);

if ($connect->connect_error) {
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

if (isset($_GET['idnum'])) {
    $idnum = trim($_GET['idnum']);

    $stmt = $connect->prepare("SELECT 1 FROM users WHERE idnum = ? LIMIT 1");
    $stmt->bind_param("s", $idnum);
    $stmt->execute();
    $stmt->store_result();

    echo json_encode(["exists" => $stmt->num_rows > 0]);

    $stmt->close();
} else {
    echo json_encode(["error" => "No ID number provided"]);
}

$connect->close();
?>
