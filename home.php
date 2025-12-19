<?php
session_start();
require_once 'includes/db_connect.php';

// Get search and category filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? $_GET['category'] : 'all'; // 'all', 'equipment', 'supplies'

// Build query to fetch available items (show all items, not just available ones for public view)
$items = [];
$types = [];

if ($category === 'all') {
	$types = ['equipment', 'supplies'];
} else {
	$types = [$category];
}

foreach ($types as $type) {
	$table = $type === 'equipment' ? 'equipment' : 'supplies';
	$idField = $type === 'equipment' ? 'equip_id' : 'supply_id';
	
	$query = "SELECT 
		'$type' AS item_type,
		$idField AS item_id,
		name,
		description,
		quantity,
		status,
		daily_rate,
		max_rental_days,
		late_fee_per_day,
		image_path,
		image_alt
	FROM $table
	WHERE 1=1";
	
	if ($search) {
		$query .= " AND (name LIKE ? OR description LIKE ?)";
	}
	
	$query .= " ORDER BY name ASC";
	
	$stmt = $mysqli->prepare($query);
	if ($search) {
		$searchTerm = "%$search%";
		$stmt->bind_param('ss', $searchTerm, $searchTerm);
	}
	$stmt->execute();
	$result = $stmt->get_result();
	while ($row = $result->fetch_assoc()) {
		$items[] = $row;
	}
	$stmt->close();
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Home - Cabadbaran Agricultural Supply and Equipment Lending System</title>
	<link rel="stylesheet" href="assets/css/style.css">
	<link rel="stylesheet" href="styles/public-shared.css">
	<link rel="stylesheet" href="styles/home.css">
</head>
<body class="home-page">
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
                <a href="home.php#home" class="nav-link active">Home</a>
                <a href="service.php" class="nav-link">Service</a>
                <a href="about.php" class="nav-link">About Us</a>
                <a href="contact.php" class="nav-link">Contact</a>
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

	<main class="home-main">
		<section class="casels-hero" id="home">
			<div class="hero-content">
				<p class="hero-subtitle">CABADBARAN AGRICULTURAL SUPPLY AND EQUIPMENT LENDING SYSTEM</p>
				<h1 class="hero-title">CASELS</h1>
				<p class="hero-description">Cabadbaran Agricultural Supply and Equipment Lending System</p>
				<div class="hero-buttons">
					<a href="login.php" class="hero-btn primary">Log In</a>
					<a href="register.php" class="hero-btn outline">Register</a>
				</div>
			</div>
		</section>

    </main>

    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>
</body>
</html>

