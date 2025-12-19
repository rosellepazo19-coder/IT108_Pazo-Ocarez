<?php
// php/forgot_password.php
session_start();
require_once 'includes/db_connect.php';

// Set content type to JSON for AJAX requests
header('Content-Type: application/json');

// Match the exact option values used in registration.html
$questions = [
    "pet"      => "What is your favorite dessert?",
    "school"   => "What was the name of your school in Highschool?",
    "city"     => "In what city were you born?",
    "nickname" => "Who is your childhood bestfriend?",
    "food"     => "What is your favorite color?"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkEmail'])) {
    $mail = trim($_POST['mail']);

    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT secQ1, secQ2, secQ3 FROM users WHERE mail = ? LIMIT 1");
    $stmt->bind_param("s", $mail);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($secQ1, $secQ2, $secQ3);
        $stmt->fetch();

        // Save only the codes (we will map them when showing)
        $_SESSION['reset_email'] = $mail;
        $_SESSION['secQ1'] = $secQ1;
        $_SESSION['secQ2'] = $secQ2;
        $_SESSION['secQ3'] = $secQ3;

        // reset any previous attempt counters
        unset($_SESSION['sec_attempts'], $_SESSION['sec_last_attempt'], $_SESSION['verified_reset']);

        $stmt->close();
        
        // Return success response
        echo json_encode(['success' => true, 'message' => 'Email found. Redirecting...', 'redirect' => 'security_questions.php']);
        exit;
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Email not found!']);
        exit;
    }
}

// If not POST request or missing checkEmail, return error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => false, 'message' => 'Email not found!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
exit;
?>
