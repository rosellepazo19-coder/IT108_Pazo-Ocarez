<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';
require_login(); // Require authentication

// Get pending payments count for admin/staff
$pending_payments_count = 0;
$pending_purchase_payments_count = 0;
$pending_reservations_count = 0;
if (in_array(current_role(), ['admin', 'staff'])) {
	// Count pending payments (for borrows)
	$count_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_status = 'pending'");
	$count_stmt->execute();
	$count_result = $count_stmt->get_result();
	$count_row = $count_result->fetch_assoc();
	$pending_payments_count = (int)($count_row['count'] ?? 0);
	$count_stmt->close();
	
	// Count pending purchase payments
	$purchase_count_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM purchase_records WHERE payment_status = 'pending'");
	$purchase_count_stmt->execute();
	$purchase_count_result = $purchase_count_stmt->get_result();
	$purchase_count_row = $purchase_count_result->fetch_assoc();
	$pending_purchase_payments_count = (int)($purchase_count_row['count'] ?? 0);
	$purchase_count_stmt->close();
	
	// Count pending reservations (status = 'reserved')
	$reserve_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM borrow_records WHERE status = 'reserved'");
	$reserve_stmt->execute();
	$reserve_result = $reserve_stmt->get_result();
	$reserve_row = $reserve_result->fetch_assoc();
	$pending_reservations_count = (int)($reserve_row['count'] ?? 0);
	$reserve_stmt->close();
}

// Get notification counts for borrowers
$borrower_payment_notif_count = 0;
$borrower_report_notif_count = 0;
if (current_role() === 'borrower') {
	$current_user_id = current_user()['user_id'] ?? null;
	if ($current_user_id) {
		// Check if last_viewed_by_user column exists, if not add it
		$column_check = $mysqli->query("SHOW COLUMNS FROM user_reports LIKE 'last_viewed_by_user'");
		if ($column_check->num_rows == 0) {
			$mysqli->query("ALTER TABLE user_reports ADD COLUMN last_viewed_by_user TIMESTAMP NULL AFTER last_message_at");
		}
		if ($column_check) $column_check->free();
		// Count borrowed items that need payment (status = 'borrowed' or 'overdue' with amount due > paid)
		$payment_stmt = $mysqli->prepare("
			SELECT COUNT(*) as count 
			FROM borrow_records b
			LEFT JOIN (
				SELECT borrow_id, SUM(amount) as total_paid 
				FROM payments 
				WHERE payment_status = 'confirmed' OR payment_status IS NULL
				GROUP BY borrow_id
			) p ON b.borrow_id = p.borrow_id
			WHERE b.user_id = ? 
			AND b.status IN ('borrowed', 'overdue')
			AND COALESCE(b.total_amount_due, 0) > COALESCE(p.total_paid, 0)
		");
		$payment_stmt->bind_param('i', $current_user_id);
		$payment_stmt->execute();
		$payment_result = $payment_stmt->get_result();
		$payment_row = $payment_result->fetch_assoc();
		$borrower_payment_notif_count = (int)($payment_row['count'] ?? 0);
		$payment_stmt->close();
		
		// Count unread admin messages (admin messages created after user last viewed)
		// Show notification only for unread admin messages
		$report_stmt = $mysqli->prepare("
			SELECT COUNT(DISTINCT r.report_id) as count
			FROM user_reports r
			INNER JOIN conversation_messages cm ON r.report_id = cm.report_id
			WHERE r.user_id = ? 
			AND cm.is_admin = TRUE
			AND r.status != 'resolved'
			AND (r.last_viewed_by_user IS NULL OR cm.created_at > r.last_viewed_by_user)
		");
		$report_stmt->bind_param('i', $current_user_id);
		$report_stmt->execute();
		$report_result = $report_stmt->get_result();
		$report_row = $report_result->fetch_assoc();
		$borrower_report_notif_count = (int)($report_row['count'] ?? 0);
		$report_stmt->close();
	}
}

// Get search and category filters
// Note: Only equipment can be borrowed/rented. Supplies are for purchase only.
$search = req_str('search', '');
$category = req_str('category', 'all'); // 'all', 'equipment', or 'supplies'

// Build query to fetch items based on category
$items = [];

if ($category === 'equipment') {
	// Fetch equipment only
	$query = "SELECT 
		'equipment' AS item_type,
		equip_id AS item_id,
		name,
		description,
		quantity,
		status,
		daily_rate,
		max_rental_days,
		late_fee_per_day,
		image_path,
		image_alt,
		NULL AS unit_price
	FROM equipment
	WHERE status IN ('available','unavailable')";
	
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
} elseif ($category === 'supplies') {
	// Fetch supplies only
	$query = "SELECT 
		'supplies' AS item_type,
		supply_id AS item_id,
		name,
		description,
		quantity,
		status,
		NULL AS daily_rate,
		NULL AS max_rental_days,
		NULL AS late_fee_per_day,
		image_path,
		image_alt,
		unit_price
	FROM supplies
	WHERE status IN ('available','unavailable')";
	
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
} else {
	// Fetch both equipment and supplies (ALL)
	// Equipment
	$query = "SELECT 
		'equipment' AS item_type,
		equip_id AS item_id,
		name,
		description,
		quantity,
		status,
		daily_rate,
		max_rental_days,
		late_fee_per_day,
		image_path,
		image_alt,
		NULL AS unit_price
	FROM equipment
	WHERE status IN ('available','unavailable')";
	
	if ($search) {
		$query .= " AND (name LIKE ? OR description LIKE ?)";
	}
	
	$query .= " UNION ALL SELECT 
		'supplies' AS item_type,
		supply_id AS item_id,
		name,
		description,
		quantity,
		status,
		NULL AS daily_rate,
		NULL AS max_rental_days,
		NULL AS late_fee_per_day,
		image_path,
		image_alt,
		unit_price
	FROM supplies
	WHERE status IN ('available','unavailable')";
	
	if ($search) {
		$query .= " AND (name LIKE ? OR description LIKE ?)";
	}
	
	$query .= " ORDER BY name ASC";
	
	$stmt = $mysqli->prepare($query);
	if ($search) {
		$searchTerm = "%$search%";
		$stmt->bind_param('ssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm);
	}
	$stmt->execute();
	$result = $stmt->get_result();
	while ($row = $result->fetch_assoc()) {
		$items[] = $row;
	}
	$stmt->close();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Browse Items - Cabadbaran Agricultural Supply and Equipment Lending System</title>
	<link rel="stylesheet" href="assets/css/style.css">
	<style>
		:root {
			--primary: #2e7d32;
			--secondary: #85bba8;
			--accent: #9ceba0;
			--text-dark: #333;
			--text-light: #fff;
			--orange-accent: #ffc766;
			--orange-hover: #ffd699;
			--shadow: 0 2px 5px rgba(0,0,0,0.1);
			--shadow-hover: 0 10px 30px rgba(0, 0, 0, 0.2);
		}
		
		body {
			background-color: #204E3C;
			color: var(--text-dark);
			position: relative;
			min-height: 100vh;
		}
		
		body::before {
			content: '';
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background-image: url('uploads/BACKGORUNDDD.jpg');
			background-size: cover;
			background-position: center;
			background-repeat: no-repeat;
			filter: blur(5px);
			opacity: 0.3;
			z-index: -1;
		}
		
		.container {
			background-color: transparent;
			position: relative;
			z-index: 1;
		}
		
		.main-layout {
			display: grid;
			gap: 2rem;
			align-items: start;
		}
		
		.main-layout.with-sidebar {
			grid-template-columns: 280px 1fr;
		}
		
		.main-layout.without-sidebar {
			grid-template-columns: 1fr;
		}
		
		.sidebar {
			position: sticky;
			top: 2rem;
		}
		
		.main-content {
			min-width: 0;
		}
		
		.shop-header {
			background: linear-gradient(135deg, var(--primary) 0%, #1b5e20 100%);
			color: var(--text-light);
			padding: 1rem 0;
			margin-bottom: 1.5rem;
			box-shadow: var(--shadow-hover);
		}
		
		.shop-header .container {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 1rem;
		}
		
		.shop-header h1 {
			margin: 0;
			font-size: 1.5rem;
			font-weight: 700;
			letter-spacing: 0.5px;
		}
		
		.user-menu {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 0.75rem;
			width: 100%;
		}
		
		.user-menu span {
			font-size: 0.95rem;
			margin-right: 0.5rem;
			font-weight: 500;
			grid-column: 1 / -1;
		}
		
		.user-menu a {
			background: linear-gradient(135deg, #ffc766 0%, #ffb84d 100%);
			color: #1a1a1a;
			text-decoration: none;
			padding: 0.85rem 1rem;
			border-radius: 8px;
			transition: all 0.3s ease;
			font-weight: 700;
			font-size: 0.9rem;
			position: relative;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 0.5rem;
			box-shadow: 0 6px 15px rgba(255, 199, 102, 0.4), 0 2px 5px rgba(0,0,0,0.2);
			border: 2px solid rgba(255, 255, 255, 0.3);
			letter-spacing: 0.3px;
			overflow: hidden;
			width: 100%;
			height: 50px;
			white-space: nowrap;
			text-align: center;
		}
		
		.user-menu a::before {
			content: '';
			position: absolute;
			top: 0;
			left: -100%;
			width: 100%;
			height: 100%;
			background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
			transition: left 0.5s;
		}
		
		.user-menu a:hover::before {
			left: 100%;
		}
		
		.user-menu a:hover {
			background: linear-gradient(135deg, #ffd699 0%, #ffc766 100%);
			color: #000;
			transform: translateY(-4px) scale(1.05);
			box-shadow: 0 10px 25px rgba(255, 199, 102, 0.5), 0 4px 10px rgba(0,0,0,0.3);
			border-color: rgba(255, 255, 255, 0.5);
		}
		
		.user-menu a:active {
			transform: translateY(-2px) scale(1.02);
			box-shadow: 0 5px 15px rgba(255, 199, 102, 0.4), 0 2px 5px rgba(0,0,0,0.2);
		}
		
		.user-menu a[href="logout.php"] {
			background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
			color: white;
			box-shadow: 0 6px 15px rgba(231, 76, 60, 0.4), 0 2px 5px rgba(0,0,0,0.2);
		}
		
		.user-menu a[href="logout.php"]:hover {
			background: linear-gradient(135deg, #ec7063 0%, #e74c3c 100%);
			color: white;
			box-shadow: 0 10px 25px rgba(231, 76, 60, 0.5), 0 4px 10px rgba(0,0,0,0.3);
		}
		
		.notification-badge {
			background: #ff4444;
			color: white;
			border-radius: 50%;
			width: 20px;
			height: 20px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 0.75rem;
			font-weight: 700;
			line-height: 1;
			min-width: 20px;
			padding: 2px;
		}
		
		.search-section {
			background: var(--secondary);
			padding: 1.5rem;
			border-radius: 15px;
			box-shadow: var(--shadow-hover);
			margin-bottom: 2rem;
		}
		
		.search-bar {
			display: flex;
			gap: 1rem;
			margin-bottom: 1rem;
		}
		
		.search-bar input {
			flex: 1;
			padding: 0.75rem 1rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			font-size: 1rem;
			transition: border-color 0.3s ease;
			background: #f9f9f9;
		}
		
		.search-bar input:focus {
			outline: none;
			border-color: var(--primary);
			background: #fff;
		}
		
		.search-bar button {
			padding: 0.75rem 2rem;
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			border-radius: 8px;
			cursor: pointer;
			font-size: 1rem;
			font-weight: 500;
			transition: all 0.3s ease;
		}
		
		.search-bar button:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
		}
		
		.category-filters {
			display: flex;
			gap: 0.5rem;
			flex-wrap: wrap;
		}
		
		.category-btn {
			padding: 0.5rem 1.25rem;
			border: 2px solid var(--primary);
			background: var(--accent);
			border-radius: 20px;
			cursor: pointer;
			text-decoration: none;
			color: var(--text-dark);
			font-size: 0.9rem;
			transition: all 0.3s ease;
			font-weight: 500;
		}
		
		.category-btn:hover {
			border-color: var(--primary);
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
		}
		
		.category-btn.active {
			background: var(--primary);
			border-color: var(--primary);
			color: var(--text-light);
		}
		
		.products-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
			gap: 1.5rem;
			margin-bottom: 2rem;
		}
		
		.product-card {
			background: var(--secondary);
			border-radius: 15px;
			overflow: hidden;
			box-shadow: var(--shadow-hover);
			transition: transform 0.3s ease, box-shadow 0.3s ease;
			display: flex;
			flex-direction: column;
			border: 1px solid rgba(255,255,255,0.2);
		}
		
		.product-card:hover {
			transform: translateY(-4px);
			box-shadow: 0 15px 35px rgba(0,0,0,0.3);
		}
		
		.product-image {
			width: 100%;
			height: 220px;
			object-fit: cover;
			background: #f5f5f5;
		}
		
		.product-image-placeholder {
			width: 100%;
			height: 220px;
			background: linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%);
			display: flex;
			align-items: center;
			justify-content: center;
			color: #999;
			font-size: 0.9rem;
		}
		
		.product-info {
			padding: 1.25rem;
			flex: 1;
			display: flex;
			flex-direction: column;
		}
		
		.product-category {
			font-size: 0.75rem;
			color: var(--primary);
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			margin-bottom: 0.5rem;
		}
		
		.product-name {
			font-size: 1.1rem;
			font-weight: 600;
			margin: 0 0 0.5rem 0;
			color: var(--text-dark);
			line-height: 1.4;
		}
		
		.product-description {
			font-size: 0.9rem;
			color: var(--text-dark);
			margin-bottom: 1rem;
			line-height: 1.5;
			flex: 1;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			overflow: hidden;
			opacity: 0.8;
		}
		
		.product-details {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1rem;
			padding-top: 1rem;
			border-top: 1px solid #f0f0f0;
		}
		
		.product-price {
			font-size: 1.25rem;
			font-weight: 700;
			color: var(--primary);
		}
		
		.product-price-label {
			font-size: 0.75rem;
			color: black;
			display: block;
		}
		
		.product-stock {
			font-size: 0.85rem;
			color: #666;
		}
		
		.product-stock.in-stock {
			color: #28a745;
			font-weight: 600;
		}
		
		.borrow-btn {
			width: 100%;
			padding: 0.75rem;
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			border-radius: 8px;
			font-size: 1rem;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.3s ease;
			text-decoration: none;
			text-align: center;
			display: block;
		}
		
		.borrow-btn:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
			box-shadow: var(--shadow);
		}
		
		.borrow-btn:disabled {
			background: #ccc;
			cursor: not-allowed;
		}
		
		.no-results {
			text-align: center;
			padding: 3rem;
			color: #999;
		}
		
		.no-results h3 {
			margin: 0 0 0.5rem 0;
			color: #666;
		}
		
		.admin-links {
			background: var(--secondary);
			padding: 1.5rem;
			border-radius: 15px;
			box-shadow: var(--shadow-hover);
		}
		
		.admin-links h3 {
			margin: 0 0 1rem 0;
			font-size: 1.1rem;
			color: var(--text-dark);
		}
		
		.admin-links-grid {
			display: flex;
			flex-direction: column;
			gap: 0.75rem;
		}
		
		.admin-links a {
			padding: 0.75rem 1rem;
			background: var(--accent);
			border: 1px solid var(--primary);
			border-radius: 8px;
			text-decoration: none;
			color: var(--text-dark);
			text-align: center;
			transition: all 0.3s ease;
			font-weight: 500;
			display: block;
		}
		
		.admin-links a:hover {
			background: var(--primary);
			color: var(--text-light);
			border-color: var(--primary);
			transform: translateY(-2px);
		}
		
		@media (max-width: 1024px) {
			.main-layout {
				grid-template-columns: 1fr;
			}
			
			.sidebar {
				position: relative;
				top: 0;
			}
			
			.admin-links-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
			}
		}
		
		@media (max-width: 768px) {
			.products-grid {
				grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
				gap: 1rem;
			}
			
			.shop-header .container {
				flex-direction: column;
				align-items: flex-start;
			}
			
			.shop-header h1 {
				font-size: 1.25rem;
			}
			
			.user-menu {
				grid-template-columns: repeat(2, 1fr);
				gap: 0.5rem;
			}
			
			.user-menu a {
				padding: 0.75rem 0.8rem;
				font-size: 0.85rem;
				font-weight: 700;
				height: 50px;
			}
			
			.main-layout {
				gap: 1.5rem;
			}
		}
	</style>
</head>
<body>
	<div class="shop-header">
		<div class="container">
			<h1>Agricultural Equipment & Supplies</h1>
			<div class="user-menu">
				<span>Welcome, <?php 
					if (current_role() === 'staff') {
						echo 'Staff';
					} else {
						echo h(current_user_name());
					}
				?></span>
				<?php if (current_role() === 'borrower'): ?>
					<a href="modules/borrow.php?action=request">REQUEST BORROW</a>
					<a href="modules/purchase.php">Purchase Supplies</a>
					<a href="modules/borrow.php">
						My Borrows
						<?php if ($borrower_payment_notif_count > 0): ?>
							<span class="notification-badge"><?php echo $borrower_payment_notif_count; ?></span>
						<?php endif; ?>
					</a>
					<a href="modules/purchase_history.php">My Purchases</a>
					<a href="modules/returned_items.php">My Returned Items</a>
					<a href="modules/report_submit.php">
						Report to Admin
						<?php if ($borrower_report_notif_count > 0): ?>
							<span class="notification-badge"><?php echo $borrower_report_notif_count; ?></span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
				<a href="logout.php">Logout</a>
			</div>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?>
			<div class="alert success"><?php echo h($flash); ?></div>
		<?php endif; ?>
		
		<div class="main-layout <?php echo in_array(current_role(), ['admin', 'staff']) ? 'with-sidebar' : 'without-sidebar'; ?>">
			<?php if (in_array(current_role(), ['admin', 'staff'])): ?>
				<div class="sidebar">
					<div class="admin-links">
						<h3>Admin & Staff Tools</h3>
						<div class="admin-links-grid">
							<?php if (current_role() === 'admin'): ?>
								<a href="modules/inventory.php">Inventory Management</a>
								<a href="modules/users.php">User Management</a>
							<?php endif; ?>
							<?php if (current_role() === 'staff'): ?>
								<a href="modules/inventory.php">Manage Inventory</a>
							<?php endif; ?>
							<?php if (current_role() === 'admin'): ?>
								<a href="modules/borrow.php">
									Borrow/Reserve
									<?php if ($pending_reservations_count > 0): ?>
										<span style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.75rem; margin-left: 5px; font-weight: 600;">
											<?php echo $pending_reservations_count; ?>
										</span>
									<?php endif; ?>
								</a>
							<?php endif; ?>
							<?php if (current_role() === 'staff'): ?>
								<a href="modules/borrow.php">
									Review Reservations
									<?php if ($pending_reservations_count > 0): ?>
										<span style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.75rem; margin-left: 5px; font-weight: 600;">
											<?php echo $pending_reservations_count; ?>
										</span>
									<?php endif; ?>
								</a>
							<?php endif; ?>
							<a href="modules/return.php">Return Items</a>
							<a href="modules/returned_items.php">Returned Items</a>
							<a href="modules/payment_history.php">Payment History</a>
							<a href="modules/pending_payments.php">
								Pending Payments
								<?php if ($pending_payments_count > 0): ?>
									<span style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.75rem; margin-left: 5px; font-weight: 600;">
										<?php echo $pending_payments_count; ?>
									</span>
								<?php endif; ?>
							</a>
							<a href="modules/pending_purchase_payments.php">
								Pending Purchase Payments
								<?php if ($pending_purchase_payments_count > 0): ?>
									<span style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.75rem; margin-left: 5px; font-weight: 600;">
										<?php echo $pending_purchase_payments_count; ?>
									</span>
								<?php endif; ?>
							</a>
							<a href="modules/monitoring.php">Monitoring</a>
							<a href="modules/reports.php">Reports</a>
							<a href="modules/payment_settings.php">Payment Settings</a>
							<a href="modules/report_inbox.php">
								Reports Inbox
								<?php
								// Count unread borrower messages for notification
								$open_reports_count = 0;
								if (in_array(current_role(), ['admin', 'staff'])) {
									// Check if last_viewed_by_admin column exists
									$column_check = $mysqli->query("SHOW COLUMNS FROM user_reports LIKE 'last_viewed_by_admin'");
									if ($column_check->num_rows == 0) {
										$mysqli->query("ALTER TABLE user_reports ADD COLUMN last_viewed_by_admin TIMESTAMP NULL AFTER last_viewed_by_user");
									}
									if ($column_check) $column_check->free();
									
									// Count reports with unread borrower messages (messages from borrowers created after admin last viewed)
									$open_stmt = $mysqli->prepare("
										SELECT COUNT(DISTINCT r.report_id) as count
										FROM user_reports r
										INNER JOIN conversation_messages cm ON r.report_id = cm.report_id
										WHERE r.status != 'resolved'
										AND cm.is_admin = FALSE
										AND (r.last_viewed_by_admin IS NULL OR cm.created_at > r.last_viewed_by_admin)
									");
									$open_stmt->execute();
									$open_result = $open_stmt->get_result();
									$open_row = $open_result->fetch_assoc();
									$open_reports_count = (int)($open_row['count'] ?? 0);
									$open_stmt->close();
								}
								?>
								<?php if ($open_reports_count > 0): ?>
									<span style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.75rem; margin-left: 5px; font-weight: 600;">
										<?php echo $open_reports_count; ?>
									</span>
								<?php endif; ?>
							</a>
						</div>
					</div>
				</div>
			<?php endif; ?>
			
			<div class="main-content">
				<div class="search-section">
			<form method="GET" action="index.php" class="search-bar">
				<input type="hidden" name="category" value="<?php echo h($category); ?>">
				<input type="text" name="search" placeholder="Search <?php echo $category === 'all' ? 'items' : ($category === 'equipment' ? 'equipment' : 'supplies'); ?> by name or description..." value="<?php echo h($search); ?>">
				<button type="submit">Search</button>
				<?php if ($search): ?>
					<a href="index.php?category=<?php echo urlencode($category); ?>" class="btn" style="padding: 0.75rem 1.5rem; text-decoration: none; display: inline-block;">Clear</a>
				<?php endif; ?>
			</form>
			
			<div class="category-filters">
				<a href="index.php?category=all<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="category-btn <?php echo $category === 'all' ? 'active' : ''; ?>">All</a>
				<a href="index.php?category=equipment<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="category-btn <?php echo $category === 'equipment' ? 'active' : ''; ?>">Equipment</a>
				<a href="index.php?category=supplies<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="category-btn <?php echo $category === 'supplies' ? 'active' : ''; ?>">Supplies</a>
				<p style="color: #666; font-size: 0.9rem; margin: 0.5rem 0; padding: 0.75rem; background: #e8f5e9; border-left: 4px solid var(--primary); border-radius: 6px;">
					<strong>ℹ️ Note:</strong> 
					<?php 
					if ($category === 'equipment') {
						echo 'Equipment can be borrowed/rented.';
					} elseif ($category === 'supplies') {
						echo 'Supplies are available for purchase.';
					} else {
						echo 'Equipment can be borrowed/rented. Supplies are available for purchase.';
					}
					?>
				</p>
			</div>
		</div>
		
		<?php if (empty($items)): ?>
			<div class="no-results">
				<h3>No items found</h3>
				<p>Try adjusting your search or category filter.</p>
			</div>
		<?php else: ?>
			<div class="products-grid">
				<?php foreach ($items as $item): ?>
					<?php
						$is_equipment = $item['item_type'] === 'equipment';
						$is_unavailable = $item['status'] !== 'available';
						$is_out_of_stock = (int)$item['quantity'] <= 0;
					?>
					<div class="product-card">
						<?php if ($item['image_path']): ?>
							<img src="<?php echo h($item['image_path']); ?>" 
								alt="<?php echo h($item['image_alt'] ?? $item['name']); ?>" 
								class="product-image"
								onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
							<div class="product-image-placeholder" style="display: none;">
								No Image Available
							</div>
						<?php else: ?>
							<div class="product-image-placeholder">
								No Image Available
							</div>
						<?php endif; ?>
						
						<div class="product-info">
							<div class="product-category">
								<?php echo $item['item_type'] === 'equipment' ? 'Equipment' : 'Supplies'; ?>
							</div>
							
							<h3 class="product-name"><?php echo h($item['name']); ?></h3>
							
							<p class="product-description"><?php echo h($item['description']); ?></p>
							
							<div class="product-details">
								<div>
									<?php if ($item['item_type'] === 'equipment'): ?>
										<span class="product-price">₱<?php echo number_format((float)$item['daily_rate'], 2); ?></span>
										<span class="product-price-label">per day</span>
									<?php else: ?>
										<span class="product-price">₱<?php echo number_format((float)$item['unit_price'], 2); ?></span>
										<span class="product-price-label">per kilo</span>
									<?php endif; ?>
								</div>
								<div class="product-stock <?php echo $item['quantity'] > 0 ? 'in-stock' : ''; ?>">
									<?php echo (int)$item['quantity']; ?> available
								</div>
							</div>

							<?php if ($is_unavailable): ?>
								<small style="color: #c62828; font-weight: 600; display: block; margin-top: 0.35rem;">
									<?php echo $is_equipment ? 'Not available for borrowing' : 'Not available for purchase'; ?>
								</small>
							<?php elseif ($is_out_of_stock): ?>
								<small style="color: #c62828; font-weight: 600; display: block; margin-top: 0.35rem;">Out of stock</small>
							<?php endif; ?>
							
							<?php 
								$borrower_view = current_role() === 'borrower';
								$equipment_available = $is_equipment && $item['quantity'] > 0 && $item['status'] === 'available';
								$supply_available = (!$is_equipment) && $item['quantity'] > 0 && $item['status'] === 'available';
							?>
							<?php if ($borrower_view && $is_equipment): ?>
								<?php if ($equipment_available): ?>
									<a href="modules/borrow.php?item_type=<?php echo urlencode($item['item_type']); ?>&item_id=<?php echo (int)$item['item_id']; ?>" 
										class="borrow-btn">
										Borrow Now
									</a>
								<?php else: ?>
									<button class="borrow-btn" disabled>
										<?php echo $item['status'] === 'available' ? 'Out of Stock' : 'Not Available'; ?>
									</button>
								<?php endif; ?>
							<?php elseif ($borrower_view && !$is_equipment): ?>
								<?php if ($supply_available): ?>
									<a href="modules/purchase.php?supply_id=<?php echo (int)$item['item_id']; ?>" 
										class="borrow-btn">
										Purchase Now
									</a>
								<?php else: ?>
									<button class="borrow-btn" disabled>
										<?php echo $item['status'] === 'available' ? 'Out of Stock' : 'Not Available'; ?>
									</button>
								<?php endif; ?>
							<?php else: ?>
								<button class="borrow-btn" disabled style="opacity: 0.6;">View Only</button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			</div>
		</div>
	</div>
</body>
</html>


