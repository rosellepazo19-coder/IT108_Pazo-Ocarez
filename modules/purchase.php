<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

// Only borrowers can purchase supplies
if (current_role() !== 'borrower') {
	header('Location: ../index.php');
	exit;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$current_user_id = current_user()['user_id'] ?? null;

// Check if purchase_records table exists, if not create it
$table_check = $mysqli->query("SHOW TABLES LIKE 'purchase_records'");
if ($table_check->num_rows == 0) {
	// Create purchase_records table
	$mysqli->query("CREATE TABLE IF NOT EXISTS purchase_records (
		purchase_id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT UNSIGNED NOT NULL,
		supply_id INT UNSIGNED NOT NULL,
		quantity INT UNSIGNED NOT NULL DEFAULT 1,
		unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		payment_status ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
		payment_method VARCHAR(50) NULL,
		payment_reference VARCHAR(100) NULL,
		proof_image VARCHAR(255) NULL,
		status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
		remarks TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		confirmed_at TIMESTAMP NULL,
		CONSTRAINT fk_purchase_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
		CONSTRAINT fk_purchase_supply FOREIGN KEY (supply_id) REFERENCES supplies(supply_id) ON DELETE RESTRICT ON UPDATE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	
	$mysqli->query("CREATE INDEX IF NOT EXISTS idx_purchase_user ON purchase_records(user_id)");
	$mysqli->query("CREATE INDEX IF NOT EXISTS idx_purchase_supply ON purchase_records(supply_id)");
}
if ($table_check) $table_check->free();

// Check if unit_price column exists in supplies
$column_check = $mysqli->query("SHOW COLUMNS FROM supplies LIKE 'unit_price'");
if ($column_check->num_rows == 0) {
	$mysqli->query("ALTER TABLE supplies ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER daily_rate");
}
if ($column_check) $column_check->free();

// Handle purchase creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_purchase'])) {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$supply_id = req_int('supply_id');
		$quantity = max(1, (int)req_int('quantity', 1));
		$remarks = trim(req_str('remarks'));
		
		if (!$supply_id) {
			$errors[] = 'Supply item is required.';
		} else {
			// Get supply details
			$stmt = $mysqli->prepare("SELECT supply_id, name, quantity as stock, unit_price, status FROM supplies WHERE supply_id = ? AND status = 'available'");
			$stmt->bind_param('i', $supply_id);
			$stmt->execute();
			$supply = $stmt->get_result()->fetch_assoc();
			$stmt->close();
			
			if (!$supply) {
				$errors[] = 'Supply item not found or not available.';
			} elseif ($supply['stock'] < $quantity) {
				$errors[] = "Insufficient stock. Only {$supply['stock']} unit(s) available.";
			} elseif ($supply['unit_price'] <= 0) {
				$errors[] = 'This item has no price set. Please contact admin.';
			} else {
				$unit_price = (float)$supply['unit_price'];
				$total_amount = round($unit_price * $quantity, 2);
				
				// Create purchase record
				$stmt = $mysqli->prepare("
					INSERT INTO purchase_records (user_id, supply_id, quantity, unit_price, total_amount, remarks, status, payment_status)
					VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending')
				");
				$stmt->bind_param('iiidds', $current_user_id, $supply_id, $quantity, $unit_price, $total_amount, $remarks);
				
				if ($stmt->execute()) {
					$purchase_id = $mysqli->insert_id;
					$_SESSION['flash'] = 'Purchase request created. Please proceed to payment.';
					header('Location: purchase_payment.php?purchase_id=' . $purchase_id);
					exit;
				} else {
					$errors[] = 'Failed to create purchase: ' . h($stmt->error);
				}
				$stmt->close();
			}
		}
	}
}

// Get search filter
$search = req_str('search', '');

// Load available supplies
$supplies = [];
$query = "SELECT supply_id, name, description, quantity, unit_price, image_path, image_alt 
	FROM supplies 
	WHERE status = 'available' AND quantity > 0";
	
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
$supplies = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Purchase Supplies - Cabadbaran Agricultural Supply and Equipment Lending System</title>
	<link rel="stylesheet" href="../assets/css/style.css">
	<style>
		:root {
			--primary: #2e7d32;
			--secondary: #85bba8;
			--accent: #9ceba0;
			--text-dark: #333;
			--text-light: #fff;
			--shadow: 0 2px 5px rgba(0,0,0,0.1);
			--shadow-hover: 0 10px 30px rgba(0, 0, 0, 0.2);
		}
		
		body {
			background-color: #204E3C;
			color: var(--text-dark);
		}
		
		.page-header {
			background: linear-gradient(135deg, var(--primary) 0%, #1b5e20 100%);
			color: var(--text-light);
			padding: 1rem 0;
			margin-bottom: 1.5rem;
			box-shadow: var(--shadow-hover);
		}
		
		.page-header .container {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 1rem;
		}
		
		.page-header h1 {
			margin: 0;
			font-size: 1.5rem;
			font-weight: 700;
		}
		
		.page-header .btn {
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			padding: 0.5rem 1rem;
			border-radius: 8px;
			text-decoration: none;
			transition: all 0.3s ease;
			font-weight: 500;
		}
		
		.page-header .btn:hover {
			background: var(--primary);
			color: var(--text-light);
		}
		
		.container {
			max-width: 1400px;
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
		
		.purchase-form {
			display: flex;
			flex-direction: column;
			gap: 0.75rem;
		}
		
		.quantity-input {
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}
		
		.quantity-input input {
			width: 80px;
			padding: 0.5rem;
			border: 2px solid #ddd;
			border-radius: 6px;
			text-align: center;
			font-size: 1rem;
			font-weight: 600;
		}
		
		.quantity-input input:focus {
			outline: none;
			border-color: var(--primary);
		}
		
		.total-preview {
			background: #e8f5e9;
			padding: 0.75rem;
			border-radius: 8px;
			border-left: 4px solid var(--primary);
			font-weight: 600;
			color: var(--text-dark);
			font-size: 0.95rem;
		}
		
		.purchase-btn {
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
		}
		
		.purchase-btn:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
			box-shadow: var(--shadow);
		}
		
		.purchase-btn:disabled {
			background: #ccc;
			cursor: not-allowed;
		}
		
		.no-results {
			text-align: center;
			padding: 3rem;
			color: #999;
		}
		
		.alert {
			border-radius: 8px;
			padding: 1rem;
			margin-bottom: 1.5rem;
			font-size: 0.9rem;
		}
		
		.alert.success {
			background: #d4edda;
			color: #155724;
			border-left: 4px solid #28a745;
		}
		
		.alert.error {
			background: #f8d7da;
			color: #721c24;
			border-left: 4px solid #dc3545;
		}
		
		.modal {
			display: none;
			position: fixed;
			z-index: 1000;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0,0,0,0.5);
			overflow: auto;
		}
		
		.modal-content {
			background-color: var(--secondary);
			margin: 5% auto;
			padding: 2rem;
			border-radius: 15px;
			width: 90%;
			max-width: 500px;
			box-shadow: var(--shadow-hover);
		}
		
		.modal-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1.5rem;
		}
		
		.modal-header h2 {
			margin: 0;
			color: var(--primary);
		}
		
		.close {
			color: #aaa;
			font-size: 28px;
			font-weight: bold;
			cursor: pointer;
		}
		
		.close:hover {
			color: #000;
		}
		
		.form-group {
			margin-bottom: 1rem;
		}
		
		.form-group label {
			display: block;
			margin-bottom: 0.5rem;
			font-weight: 600;
			color: var(--text-dark);
		}
		
		.form-group input,
		.form-group textarea {
			width: 100%;
			padding: 0.75rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			font-size: 0.9rem;
			transition: border-color 0.3s ease;
		}
		
		.form-group input:focus,
		.form-group textarea:focus {
			outline: none;
			border-color: var(--primary);
		}
		
		@media (max-width: 768px) {
			.products-grid {
				grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
				gap: 1rem;
			}
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Purchase Supplies</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?>
			<div class="alert success"><?php echo h($flash); ?></div>
		<?php endif; ?>
		
		<?php if ($errors): ?>
			<div class="alert error">
				<ul style="margin: 0; padding-left: 1.5rem;">
					<?php foreach ($errors as $e): ?>
						<li><?php echo h($e); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		
		<div class="search-section">
			<form method="GET" action="purchase.php" class="search-bar">
				<input type="text" name="search" placeholder="Search supplies by name or description..." value="<?php echo h($search); ?>">
				<button type="submit">Search</button>
				<?php if ($search): ?>
					<a href="purchase.php" class="btn" style="padding: 0.75rem 1.5rem; text-decoration: none; display: inline-block;">Clear</a>
				<?php endif; ?>
			</form>
		</div>
		
		<?php if (empty($supplies)): ?>
			<div class="no-results">
				<h3>No supplies found</h3>
				<p>Try adjusting your search.</p>
			</div>
		<?php else: ?>
			<div class="products-grid">
				<?php foreach ($supplies as $supply): ?>
					<div class="product-card">
						<?php if ($supply['image_path']): ?>
							<img src="../<?php echo h($supply['image_path']); ?>" 
								alt="<?php echo h($supply['image_alt'] ?? $supply['name']); ?>" 
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
							<div class="product-category">Supply</div>
							
							<h3 class="product-name"><?php echo h($supply['name']); ?></h3>
							
							<p class="product-description"><?php echo h($supply['description']); ?></p>
							
							<div class="product-details">
								<div>
									<span class="product-price">₱<?php echo number_format((float)($supply['unit_price'] ?? 0), 2); ?></span>
									<span class="product-price-label">per unit</span>
								</div>
								<div class="product-stock <?php echo $supply['quantity'] > 0 ? 'in-stock' : ''; ?>">
									<?php echo (int)$supply['quantity']; ?> available
								</div>
							</div>
							
							<form method="post" class="purchase-form" onsubmit="return validatePurchase(<?php echo (int)$supply['quantity']; ?>, <?php echo (float)($supply['unit_price'] ?? 0); ?>);">
								<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
								<input type="hidden" name="supply_id" value="<?php echo (int)$supply['supply_id']; ?>">
								<input type="hidden" name="create_purchase" value="1">
								
								<div class="form-group">
									<label>Quantity</label>
									<div class="quantity-input">
										<button type="button" onclick="decreaseQuantity(this)" style="padding: 0.5rem 0.75rem; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">-</button>
										<input type="number" name="quantity" id="qty_<?php echo $supply['supply_id']; ?>" value="1" min="1" max="<?php echo (int)$supply['quantity']; ?>" required onchange="calculateTotal(<?php echo $supply['supply_id']; ?>, <?php echo (float)($supply['unit_price'] ?? 0); ?>)">
										<button type="button" onclick="increaseQuantity(this, <?php echo (int)$supply['quantity']; ?>)" style="padding: 0.5rem 0.75rem; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">+</button>
									</div>
								</div>
								
								<div class="total-preview" id="total_<?php echo $supply['supply_id']; ?>">
									Total: ₱<?php echo number_format((float)($supply['unit_price'] ?? 0), 2); ?>
								</div>
								
								<button type="submit" class="purchase-btn" <?php echo ($supply['quantity'] <= 0 || ($supply['unit_price'] ?? 0) <= 0) ? 'disabled' : ''; ?>>
									<?php echo ($supply['quantity'] <= 0) ? 'Out of Stock' : (($supply['unit_price'] ?? 0) <= 0 ? 'Price Not Set' : 'Purchase Now'); ?>
								</button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	
	<script>
		function decreaseQuantity(btn) {
			const input = btn.nextElementSibling;
			if (parseInt(input.value) > 1) {
				input.value = parseInt(input.value) - 1;
				const supplyId = input.id.replace('qty_', '');
				const unitPrice = parseFloat(input.closest('.product-card').querySelector('.product-price').textContent.replace('₱', '').replace(',', ''));
				calculateTotal(supplyId, unitPrice);
			}
		}
		
		function increaseQuantity(btn, max) {
			const input = btn.previousElementSibling;
			if (parseInt(input.value) < max) {
				input.value = parseInt(input.value) + 1;
				const supplyId = input.id.replace('qty_', '');
				const unitPrice = parseFloat(input.closest('.product-card').querySelector('.product-price').textContent.replace('₱', '').replace(',', ''));
				calculateTotal(supplyId, unitPrice);
			}
		}
		
		function calculateTotal(supplyId, unitPrice) {
			const qtyInput = document.getElementById('qty_' + supplyId);
			const totalDiv = document.getElementById('total_' + supplyId);
			const quantity = parseInt(qtyInput.value) || 1;
			const total = (unitPrice * quantity).toFixed(2);
			totalDiv.innerHTML = 'Total: ₱' + parseFloat(total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
		}
		
		function validatePurchase(stock, price) {
			const form = event.target;
			const quantity = parseInt(form.querySelector('input[name="quantity"]').value);
			
			if (quantity > stock) {
				alert('Quantity exceeds available stock!');
				return false;
			}
			
			if (price <= 0) {
				alert('This item has no price set. Please contact admin.');
				return false;
			}
			
			return confirm('Proceed with purchase of ' + quantity + ' unit(s)?');
		}
	</script>
</body>
</html>

