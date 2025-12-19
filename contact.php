<?php
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - CASELS</title>
    <link rel="stylesheet" href="styles/style.css">
    <link rel="stylesheet" href="styles/public-shared.css">
    <link rel="stylesheet" href="styles/contact.css">
</head>
<body class="contact-page">
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
                <a href="contact.php" class="nav-link active">Contact</a>
                <?php if ($isLoggedIn): ?>
                    <a href="index.php" class="nav-link primary">Dashboard</a>
                    <a href="logout.php" class="nav-link">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="nav-link">Log In</a>
                    <a href="register.php" class="nav-link primary">Sign Up</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="contact-main">
        <div class="contact-card">
            <h2>Need Assistance?</h2>
            <p class="contact-lead">
                Reach out to the City Agriculture Office or proceed to your dashboard to manage reservations.
            </p>
            <div class="contact-details">
                <div>
                    <h4>Office</h4>
                    <p>City Agriculture Office, Cabadbaran City Hall Compound</p>
                    <p>8:00 AM – 5:00 PM, Monday to Friday</p>
                </div>
                <div>
                    <h4>Hotline</h4>
                    <p>(+63) 912-345-6789</p>
                    <p>agri.office@cabadbaran.gov.ph</p>
                </div>
            </div>
            <div class="contact-actions">
                <a href="login.php" class="hero-btn primary">Log In</a>
                <a href="register.php" class="hero-btn outline">Create Account</a>
            </div>
        </div>
    </main>

    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>
</body>
</html>

