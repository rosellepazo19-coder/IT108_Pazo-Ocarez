<?php
// php/reset_password.php
session_start();

// ensure user is verified
if (!isset($_SESSION['reset_email']) || !isset($_SESSION['verified_reset']) || $_SESSION['verified_reset'] !== true) {
    header("Location: forgot_password.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reset Password - CASELS</title>
  <link rel="stylesheet" href="styles/style.css">   
  <link rel="stylesheet" href="styles/public-shared.css">
  <link rel="stylesheet" href="styles/log-in.css">
</head>
<body>
    <header class="public-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
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
                <a href="about.php" class="nav-link">About Us</a>
                <a href="contact.php" class="nav-link">Contact</a>
                <a href="login.php" class="nav-link">Log In</a>
                <a href="register.php" class="nav-link primary">Sign Up</a>
            </nav>
        </div>
    </header>

    <div class="login-container">
        <div class="login-form">
            <h2>Reset Your Password</h2>
            <p style="text-align: center; color: #555; margin-bottom: 1.5rem; font-size: 0.95rem;">
                Please enter your new password below.
            </p>
            
            <form action="update_password.php" method="post">
                <div class="input-box">
                    <label for="newPassword">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="newPassword" id="newPassword" required minlength="6" placeholder="Enter new password">
                        <span class="password-toggle" id="passwordToggle1">
                            <svg id="eyeIcon1" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="eyeOffIcon1" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </span>
                    </div>
                </div>
                
                <div class="input-box">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="confirmPassword" id="confirmPassword" required minlength="6" placeholder="Confirm new password">
                        <span class="password-toggle" id="passwordToggle2">
                            <svg id="eyeIcon2" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="eyeOffIcon2" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </span>
                    </div>
                </div>
                
                <button type="submit" name="reset" class="btn">Reset Password</button>
                <div style="text-align: center; margin-top: 1rem;">
                    <a href="login.php" style="color: var(--primary); text-decoration: none; font-size: 0.9rem; font-weight: 500;">← Back to Login</a>
                </div>
            </form>
        </div>
    </div>

    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>

    <script>
        // Show/Hide password toggle for New Password
        const passwordToggle1 = document.getElementById('passwordToggle1');
        const passwordField1 = document.getElementById('newPassword');
        const eyeIcon1 = document.getElementById('eyeIcon1');
        const eyeOffIcon1 = document.getElementById('eyeOffIcon1');

        passwordToggle1.addEventListener('click', function() {
            if (passwordField1.type === 'password') {
                passwordField1.type = 'text';
                eyeIcon1.style.display = 'none';
                eyeOffIcon1.style.display = 'block';
            } else {
                passwordField1.type = 'password';
                eyeIcon1.style.display = 'block';
                eyeOffIcon1.style.display = 'none';
            }
        });

        // Show/Hide password toggle for Confirm Password
        const passwordToggle2 = document.getElementById('passwordToggle2');
        const passwordField2 = document.getElementById('confirmPassword');
        const eyeIcon2 = document.getElementById('eyeIcon2');
        const eyeOffIcon2 = document.getElementById('eyeOffIcon2');

        passwordToggle2.addEventListener('click', function() {
            if (passwordField2.type === 'password') {
                passwordField2.type = 'text';
                eyeIcon2.style.display = 'none';
                eyeOffIcon2.style.display = 'block';
            } else {
                passwordField2.type = 'password';
                eyeIcon2.style.display = 'block';
                eyeOffIcon2.style.display = 'none';
            }
        });
    </script>
</body>
</html>
