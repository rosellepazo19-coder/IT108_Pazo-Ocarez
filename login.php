<?php
session_start();
require_once 'includes/db_connect.php';

$errors = [];
$success = '';

// Check if redirected from registration
if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $success = "Registration successful! You can now login with your credentials.";
}

// Handle login request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['login'])) {
    $email = trim($_POST['mail']);
    $password = $_POST['password'];

    // Validate user credentials
    $stmt = $mysqli->prepare("SELECT user_id, Fname, Lname, password, role FROM users WHERE mail = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($user_id, $fname, $lname, $hashedPassword, $role);
        $stmt->fetch();

        if (password_verify($password, $hashedPassword)) {
            $_SESSION['user'] = [
                'user_id' => $user_id,
                'Fname' => $fname,
                'Lname' => $lname,
                'role' => $role,
                'mail' => $email
            ];
            $_SESSION['loggedin'] = true;

            // Redirect to return URL if provided, otherwise to index.php
            $returnUrl = isset($_GET['return']) ? $_GET['return'] : (isset($_POST['return']) ? $_POST['return'] : 'index.php');
            // Sanitize return URL to prevent open redirects
            if (strpos($returnUrl, 'http') === 0 || strpos($returnUrl, '//') === 0) {
                $returnUrl = 'index.php';
            }
            header('Location: ' . $returnUrl);
            exit;
        } else {
            $errors[] = "Invalid email or password!";
        }
    } else {
        $errors[] = "Invalid email or password!";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in - CBR Agricultural System</title>
    <link rel="stylesheet" href="styles/style.css">   
    <link rel="stylesheet" href="styles/public-shared.css">
    <link rel="stylesheet" href="styles/log-in.css">
</head>
<body>
    <!-- Header -->
    <header class="public-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Farm Building/Barn Icon -->
                        <path d="M3 21V8L12 3L21 8V21H3Z" fill="#FFD700" stroke="#FFD700" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M3 8L12 3L21 8" stroke="#FFD700" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M12 3V21" stroke="#FFD700" stroke-width="1.5"/>
                        <rect x="6" y="12" width="4" height="6" fill="#1b5e20"/>
                        <rect x="14" y="12" width="4" height="6" fill="#1b5e20"/>
                        <circle cx="9" cy="15" r="0.5" fill="#FFD700"/>
                        <circle cx="16" cy="15" r="0.5" fill="#FFD700"/>
                    </svg>
                </div>
                <h1>CABADBARAN AGRICULTURAL SUPPLY AND EQUIPMENT LENDING SYSTEM</h1>
            </div>
            <nav class="header-nav">
                <a href="home.php" class="nav-link">Home</a>
                <a href="service.php" class="nav-link">Service</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="contact.php" class="nav-link">Contact</a>
                <a href="register.php" class="nav-link primary">Sign Up</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <div class="login-container">
        <div class="login-form">
            <h2>Log In</h2>
            
            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php if (isset($_GET['return'])): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars($_GET['return']); ?>">
                <?php endif; ?>
                <div class="input-box">
                    <label for="mail">Email</label>
                    <input type="email" name="mail" id="mail" placeholder="Enter your email" required>
                </div>
                <div class="input-box">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" placeholder="Enter your password" required>
                        <span class="password-toggle" id="passwordToggle">
                            <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="eyeOffIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </span>
                    </div>
                </div>
                <button type="submit" name="login" id="loginBtn" class="btn">Log In</button>
                <div style="text-align: center; margin: 15px 0;">
                    <a href="forgot_password.html" id="forgotPassword">Forgot Password? Reset here.</a>
                </div>
                <p class="auto-switch">Don't have an account? 
                    <a href="register.php" id="signUpLink" class="sign-btn">Sign Up</a>
                </p>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>

    <script>
        // Show/Hide password toggle
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordField = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        const eyeOffIcon = document.getElementById('eyeOffIcon');

        passwordToggle.addEventListener('click', function() {
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeIcon.style.display = 'none';
                eyeOffIcon.style.display = 'block';
            } else {
                passwordField.type = 'password';
                eyeIcon.style.display = 'block';
                eyeOffIcon.style.display = 'none';
            }
        });
    </script>
</body>
</html>
