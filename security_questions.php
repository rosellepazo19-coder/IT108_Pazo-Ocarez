<?php
// php/security_questions.php
session_start();

// ensure user arrives via forgot-password flow
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.html");
    exit;
}

// exact same mapping as registration.html option values
$questions = [
    "pet"      => "What is your favorite dessert?",
    "school"   => "What was the name of your school in Highschool?",
    "city"     => "In what city were you born?",
    "nickname" => "Who is your childhood bestfriend?",
    "food"     => "What is your favorite color?"
];

// get codes from session (may be null if something went wrong)
$q1_code = $_SESSION['secQ1'] ?? null;
$q2_code = $_SESSION['secQ2'] ?? null;
$q3_code = $_SESSION['secQ3'] ?? null;

// map codes to text safely (fallback to code if unknown)
$q1_text = isset($questions[$q1_code]) ? $questions[$q1_code] : ("Unknown question (" . htmlspecialchars((string)$q1_code) . ")");
$q2_text = isset($questions[$q2_code]) ? $questions[$q2_code] : ("Unknown question (" . htmlspecialchars((string)$q2_code) . ")");
$q3_text = isset($questions[$q3_code]) ? $questions[$q3_code] : ("Unknown question (" . htmlspecialchars((string)$q3_code) . ")");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Security Questions - CASELS</title>
  <link rel="stylesheet" href="styles/style.css">   
  <link rel="stylesheet" href="styles/public-shared.css">
  <link rel="stylesheet" href="styles/log-in.css">
  <style>
    .question-box {
        margin-bottom: 1.25rem;
        padding: 1rem;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 8px;
        border-left: 4px solid var(--primary);
    }
    .question-box label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--text-dark);
        font-size: 0.95rem;
    }
    .question-box input {
        width: 100%;
        padding: 0.875rem 1rem;
        font-size: 1rem;
        border: 1px solid #ddd;
        border-radius: 8px;
        background: #fff;
        outline: none;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }
    .question-box input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    }
  </style>
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
        <div class="login-form" style="max-width: 600px;">
            <h2>Answer Your Security Questions</h2>
            <p style="text-align: center; color: #555; margin-bottom: 1.5rem; font-size: 0.95rem;">
                Please answer the following security questions to verify your identity.
            </p>

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
                <div style="text-align: center; margin-top: 1rem;">
                    <a href="forgot_password.html" style="color: var(--primary); text-decoration: none; font-size: 0.9rem; font-weight: 500;">← Back to Forgot Password</a>
                </div>
            </form>
        </div>
    </div>

    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>
</body>
</html>
