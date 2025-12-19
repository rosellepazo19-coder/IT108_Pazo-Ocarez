<?php
// Database connection using MySQLi with error handling
// Update the credentials below to match your local XAMPP/MySQL setup

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'cabadbaran_agriculture';
$DB_PORT = 3306;

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);

if ($mysqli->connect_errno) {
	http_response_code(500);
	die('Database connection failed: ' . htmlspecialchars($mysqli->connect_error));
}

// Set charset to utf8mb4 for full Unicode support
if (!$mysqli->set_charset('utf8mb4')) {
	// Not fatal but good to report
	error_log('Failed to set charset: ' . $mysqli->error);
}
?>


