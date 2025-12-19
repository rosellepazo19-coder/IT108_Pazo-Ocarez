<?php
// php/verify_answers.php
session_start();
require_once 'includes/db_connect.php';

// must have started the flow
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.html");
    exit;
}

$email = $_SESSION['reset_email'];

// lockout settings
$maxAttempts = 3;
$lockSeconds = 5 * 60; // 5 minutes

$attempts = $_SESSION['sec_attempts'] ?? 0;
$lastAttempt = $_SESSION['sec_last_attempt'] ?? 0;
$now = time();

$message = '';
$messageType = '';

if ($attempts >= $maxAttempts && ($now - $lastAttempt) < $lockSeconds) {
    $wait = $lockSeconds - ($now - $lastAttempt);
    $message = "Too many attempts. Try again in {$wait} seconds.";
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    $secA1 = strtolower(trim($_POST['secA1'] ?? ''));
    $secA2 = strtolower(trim($_POST['secA2'] ?? ''));
    $secA3 = strtolower(trim($_POST['secA3'] ?? ''));

    // fetch stored answers from DB
    $stmt = $mysqli->prepare("SELECT secA1, secA2, secA3 FROM users WHERE mail = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $message = "User not found.";
        $messageType = 'error';
    } else {
    $stmt->bind_result($dbA1, $dbA2, $dbA3);
    $stmt->fetch();
    $stmt->close();

    // normalize stored answers
    $dbA1n = strtolower(trim($dbA1));
    $dbA2n = strtolower(trim($dbA2));
    $dbA3n = strtolower(trim($dbA3));

    if ($secA1 === $dbA1n && $secA2 === $dbA2n && $secA3 === $dbA3n) {
        // correct — reset attempt counters and mark verified
        unset($_SESSION['sec_attempts'], $_SESSION['sec_last_attempt']);
        $_SESSION['verified_reset'] = true;
        header("Location: reset_password.php");
        exit;
    } else {
        // incorrect -> increment attempts
        $attempts++;
        $_SESSION['sec_attempts'] = $attempts;
        $_SESSION['sec_last_attempt'] = $now;

        if ($attempts >= $maxAttempts) {
                $message = "Incorrect. You have used {$attempts} attempts. Locked for {$lockSeconds} seconds.";
                $messageType = 'error';
        } else {
            $remaining = $maxAttempts - $attempts;
                $message = "One or more answers are incorrect. You have {$remaining} attempt(s) left.";
                $messageType = 'error';
            }
        }
    }
}

// Get question codes from session
$q1_code = $_SESSION['secQ1'] ?? null;
$q2_code = $_SESSION['secQ2'] ?? null;
$q3_code = $_SESSION['secQ3'] ?? null;

// Map codes to text safely (fallback to code if unknown)
$questions = [
    "pet"      => "What is your favorite dessert?",
    "school"   => "What was the name of your school in Highschool?",
    "city"     => "In what city were you born?",
    "nickname" => "Who is your childhood bestfriend?",
    "food"     => "What is your favorite color?"
];

$q1_text = isset($questions[$q1_code]) ? $questions[$q1_code] : ("Unknown question (" . htmlspecialchars((string)$q1_code) . ")");
$q2_text = isset($questions[$q2_code]) ? $questions[$q2_code] : ("Unknown question (" . htmlspecialchars((string)$q2_code) . ")");
$q3_text = isset($questions[$q3_code]) ? $questions[$q3_code] : ("Unknown question (" . htmlspecialchars((string)$q3_code) . ")");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Questions - CBR Agricultural System</title>
    <link rel="stylesheet" href="styles/style.css">   
    <link rel="stylesheet" href="styles/log-in.css">
    <style>
        .security-questions-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .security-questions-form h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }
        .question-box {
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .question-box label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
            font-size: 16px;
        }
        .question-box input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-bottom: 15px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #007bff;
            text-decoration: none;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 16px;
        }
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <header class="header">
        <a href="index.php" class="logo">
            <span class="logo1">
                <img src="styles/SirG_images/csu_logo.png" alt="CSU logo">
            </span>
            <span class="logo2">CASELS</span>            
        </a>
        <nav class="navbar">
            <a href="login.php">Back to Login</a>
        </nav>
    </header>

    <div class="security-questions-container">
        <div class="security-questions-form">
            <h2>Answer Your Security Questions</h2>
            <p style="text-align: center; color: #666; margin-bottom: 30px;">
                Please answer the following security questions to verify your identity.
            </p>
            
            <?php if ($message): ?>
                <div class="alert <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="verify_answers.php" method="post">
                <div class="question-box">
                    <label><?php echo htmlspecialchars($q1_text); ?></label>
                    <input type="text" name="secA1" required>
                </div>
                
                <div class="question-box">
                    <label><?php echo htmlspecialchars($q2_text); ?></label>
                    <input type="text" name="secA2" required>
                </div>
                
                <div class="question-box">
                    <label><?php echo htmlspecialchars($q3_text); ?></label>
                    <input type="text" name="secA3" required>
                </div>
                
                <button type="submit" name="verify" class="btn">Submit Answers</button>
            </form>

            <div class="back-link">
                <a href="forgot_password.html">← Back to Forgot Password</a>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>
</body>
</html>
