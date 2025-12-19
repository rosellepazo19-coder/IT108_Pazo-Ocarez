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

if (isset($_GET['mail'])) {
    $email = trim($_GET['mail']);

    $stmt = $connect->prepare("SELECT 1 FROM users WHERE mail = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    echo json_encode(["exists" => $stmt->num_rows > 0]);

    $stmt->close();
} else {
    echo json_encode(["error" => "No email provided"]);
}

$connect->close();
?>
