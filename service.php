<?php
session_start();
require_once 'includes/db_connect.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? $_GET['category'] : 'all';

$items = [];
$types = $category === 'all' ? ['equipment', 'supplies'] : [$category];

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
        $term = "%$search%";
        $stmt->bind_param('ss', $term, $term);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}

$displayItems = array_slice($items, 0, 9);
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - CASELS</title>
    <link rel="stylesheet" href="styles/style.css">
    <link rel="stylesheet" href="styles/public-shared.css">
    <link rel="stylesheet" href="styles/service.css">
</head>
<body class="service-page">
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
                <a href="service.php" class="nav-link active">Service</a>
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

    <main class="service-main">
        <div class="service-container">
            <section id="service-details" class="service-card">
                <div class="section-header">
                    <h2>Our Services</h2>
                    <p>CASELS brings city resources closer to farmers and partner cooperatives.</p>
                </div>
                <div class="services-grid">
                    <div class="service-info-card">
                        <h3>Equipment Lending</h3>
                        <p>Reserve tractors, cultivators, sprayers, and other farm machinery needed for planting and harvesting seasons.</p>
                        <ul>
                            <li>Daily or weekly lending schedules</li>
                            <li>Priority slots for accredited groups</li>
                            <li>Automated reminders for due dates</li>
                        </ul>
                    </div>
                    <div class="service-info-card">
                        <h3>Supply Distribution</h3>
                        <p>Access seeds, fertilizers, and other critical inputs from the City Agriculture Office without long queues.</p>
                        <ul>
                            <li>Real-time stock visibility</li>
                            <li>Request pickup or delivery coordination</li>
                            <li>Transparent documentation</li>
                        </ul>
                    </div>
                    <div class="service-info-card">
                        <h3>Field Support</h3>
                        <p>Coordinate with agricultural technicians for on-site assessments, pest monitoring, and training sessions.</p>
                        <ul>
                            <li>Digital ticketing for assistance</li>
                            <li>Progress tracking per request</li>
                            <li>Knowledge base for best practices</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section id="inventory" class="inventory-panel">
                <div class="inventory-header">
                    <h3>Available Items</h3>
                    <span style="color:#666;"><?php echo count($items); ?> items found</span>
                </div>

                <?php if (empty($displayItems)): ?>
                    <div class="no-results">
                        <h4>No items found</h4>
                        <p>Try adjusting your search or category filters.</p>
                    </div>
                <?php else: ?>
                    <div class="service-grid">
                        <?php foreach ($displayItems as $item): ?>
                            <div class="service-product" onclick="handleProductClick('<?php echo htmlspecialchars($item['item_type']); ?>', <?php echo (int)$item['item_id']; ?>)">
                                <?php if ($item['image_path']): ?>
                                    <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['image_alt'] ?? $item['name']); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="placeholder" style="display:none;">No Image Available</div>
                                <?php else: ?>
                                    <div class="placeholder">No Image Available</div>
                                <?php endif; ?>
                                <div class="content">
                                    <div class="category"><?php echo $item['item_type'] === 'equipment' ? 'Equipment' : 'Supplies'; ?></div>
                                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <p><?php echo htmlspecialchars($item['description']); ?></p>
                                    <div class="details">
                                        <span class="price">₱<?php echo number_format($item['daily_rate'], 2); ?> / day</span>
                                        <span class="stock"><?php echo (int)$item['quantity']; ?> available</span>
                                    </div>
                                    <button type="button">Click to view details - <?php echo $isLoggedIn ? 'view now' : 'login required'; ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>

    <script>
        function handleProductClick(itemType, itemId) {
            <?php if ($isLoggedIn): ?>
                window.location.href = 'modules/borrow.php?item_type=' + encodeURIComponent(itemType) + '&item_id=' + itemId;
            <?php else: ?>
                const returnUrl = 'modules/borrow.php?item_type=' + encodeURIComponent(itemType) + '&item_id=' + itemId;
                window.location.href = 'login.php?return=' + encodeURIComponent(returnUrl);
            <?php endif; ?>
        }
    </script>
</body>
</html>

