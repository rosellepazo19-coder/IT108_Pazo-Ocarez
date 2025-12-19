<?php
// php/update_password.php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['reset_email']) || !isset($_SESSION['verified_reset']) || $_SESSION['verified_reset'] !== true) {
    header("Location: forgot_password.html");
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    $new = $_POST['newPassword'] ?? '';
    $confirm = $_POST['confirmPassword'] ?? '';

    if ($new !== $confirm) {
        $message = "Passwords do not match!";
        $messageType = 'error';
    } elseif (strlen($new) < 6) {
        $message = "Password must be at least 6 characters.";
        $messageType = 'error';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $email = $_SESSION['reset_email'];

        $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE mail = ?");
        $stmt->bind_param("ss", $hashed, $email);

        if ($stmt->execute()) {
            // cleanup session variables used for reset
            unset($_SESSION['reset_email'], $_SESSION['secQ1'], $_SESSION['secQ2'], $_SESSION['secQ3'], $_SESSION['verified_reset'], $_SESSION['sec_attempts'], $_SESSION['sec_last_attempt']);
            $message = "Password reset successful! You can now log in with your new password.";
            $messageType = 'success';
        } else {
            $message = "Error updating password: " . htmlspecialchars($stmt->error);
            $messageType = 'error';
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Result - CBR Agricultural System</title>
    <link rel="stylesheet" href="styles/style.css">   
    <link rel="stylesheet" href="styles/log-in.css">
    <style>
        .result-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            text-align: center;
        }
        .result-container h2 {
            margin-bottom: 30px;
            color: #333;
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
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            margin: 10px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
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

    <div class="result-container">
        <h2>Password Reset Result</h2>
        
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($messageType === 'success'): ?>
            <a href="login.php" class="btn btn-success">Go to Login</a>
        <?php else: ?>
            <a href="reset_password.php" class="btn">Try Again</a>
            <a href="login.php" class="btn">Back to Login</a>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>
</body>
</html>
