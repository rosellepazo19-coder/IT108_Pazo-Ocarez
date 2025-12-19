<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

// Only admin and staff can access
if (!in_array(current_role(), ['admin', 'staff'])) {
	header('Location: ../index.php');
	exit;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$action = req_str('action');
		
		if ($action === 'update') {
			$settings = $_POST['settings'] ?? [];
			
			if (empty($settings)) {
				$errors[] = 'No settings provided to update.';
			} else {
				foreach ($settings as $method => $data) {
					// Validate payment method name (alphanumeric and underscore only)
					if (!preg_match('/^[a-z0-9_]+$/', $method)) {
						$errors[] = 'Invalid payment method: ' . h($method);
						continue;
					}
					
					$is_available = isset($data['is_available']) ? 1 : 0;
					$account_number = trim($data['account_number'] ?? '');
					$account_name = trim($data['account_name'] ?? '');
					$instructions = trim($data['instructions'] ?? '');
					$display_order = (int)($data['display_order'] ?? 0);
					
					$stmt = $mysqli->prepare("
						UPDATE payment_method_settings 
						SET is_available = ?, 
							account_number = ?, 
							account_name = ?, 
							instructions = ?,
							display_order = ?
						WHERE payment_method = ?
					");
					$stmt->bind_param('isssis', $is_available, $account_number, $account_name, $instructions, $display_order, $method);
					
					if (!$stmt->execute()) {
						$errors[] = 'Failed to update ' . h($method) . ': ' . h($stmt->error);
					}
					$stmt->close();
				}
			}
			
			if (!$errors) {
				$_SESSION['flash'] = 'Payment method settings updated successfully.';
				header('Location: payment_settings.php');
				exit;
			}
		}
	}
}

// Check if table exists, if not create it
$table_check = $mysqli->query("SHOW TABLES LIKE 'payment_method_settings'");
if ($table_check->num_rows == 0) {
	// Table doesn't exist - create it
	$create_table_sql = "CREATE TABLE IF NOT EXISTS payment_method_settings (
		setting_id INT AUTO_INCREMENT PRIMARY KEY,
		payment_method VARCHAR(50) NOT NULL UNIQUE,
		method_name VARCHAR(100) NOT NULL,
		is_available BOOLEAN DEFAULT TRUE,
		account_number VARCHAR(255) NULL,
		account_name VARCHAR(255) NULL,
		instructions TEXT NULL,
		icon VARCHAR(10) NULL,
		display_order INT DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
	
	if ($mysqli->query($create_table_sql)) {
		// Insert default payment methods
		$insert_sql = "INSERT INTO payment_method_settings (payment_method, method_name, is_available, icon, display_order) VALUES
			('cash', 'Cash (On-site Payment)', TRUE, '💵', 1),
			('gcash', 'GCash (Online Payment)', TRUE, '📱', 2),
			('paymaya', 'PayMaya (Online Payment)', TRUE, '💳', 3),
			('bank_transfer', 'Bank Transfer (Online Payment)', TRUE, '🏦', 4),
			('others', 'Other Online Payment', TRUE, '📲', 5)
			ON DUPLICATE KEY UPDATE method_name = VALUES(method_name)";
		$mysqli->query($insert_sql);
		
		// Create index (check if exists first)
		$index_check = $mysqli->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
			WHERE table_schema = DATABASE() 
			AND table_name = 'payment_method_settings' 
			AND index_name = 'idx_payment_method_available'");
		if ($index_check) {
			$index_row = $index_check->fetch_assoc();
			if ($index_row['cnt'] == 0) {
				$mysqli->query("CREATE INDEX idx_payment_method_available ON payment_method_settings(is_available, display_order)");
			}
			$index_check->free();
		}
		
		$_SESSION['flash'] = 'Payment method settings table created successfully with default methods.';
	} else {
		$errors[] = 'Failed to create payment method settings table: ' . h($mysqli->error);
	}
	$table_check->free();
}

// Load all payment method settings
$payment_methods = [];
$stmt = $mysqli->query("SELECT * FROM payment_method_settings ORDER BY display_order ASC, method_name ASC");
if ($stmt) {
	$payment_methods = $stmt->fetch_all(MYSQLI_ASSOC);
	$stmt->free();
} else {
	$errors[] = 'Failed to load payment method settings: ' . h($mysqli->error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Payment Method Settings - Cabadbaran Agricultural Supply and Equipment Lending System</title>
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
			max-width: 1200px;
		}
		
		.card {
			background: var(--secondary);
			border-radius: 12px;
			padding: 2rem;
			box-shadow: var(--shadow-hover);
			margin-bottom: 2rem;
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
			margin-bottom: 1.5rem;
			font-size: 1.3rem;
		}
		
		.payment-method-item {
			background: white;
			border-radius: 10px;
			padding: 1.5rem;
			margin-bottom: 1.5rem;
			border: 2px solid #e0e0e0;
			transition: all 0.3s ease;
		}
		
		.payment-method-item:hover {
			border-color: var(--primary);
			box-shadow: 0 4px 12px rgba(0,0,0,0.1);
		}
		
		.method-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 1rem;
		}
		
		.method-title {
			display: flex;
			align-items: center;
			gap: 0.75rem;
			font-size: 1.1rem;
			font-weight: 600;
			color: var(--text-dark);
		}
		
		.method-icon {
			font-size: 1.5rem;
		}
		
		.toggle-switch {
			position: relative;
			display: inline-block;
			width: 60px;
			height: 30px;
		}
		
		.toggle-switch input {
			opacity: 0;
			width: 0;
			height: 0;
		}
		
		.toggle-slider {
			position: absolute;
			cursor: pointer;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background-color: #ccc;
			transition: .4s;
			border-radius: 30px;
		}
		
		.toggle-slider:before {
			position: absolute;
			content: "";
			height: 22px;
			width: 22px;
			left: 4px;
			bottom: 4px;
			background-color: white;
			transition: .4s;
			border-radius: 50%;
		}
		
		input:checked + .toggle-slider {
			background-color: var(--primary);
		}
		
		input:checked + .toggle-slider:before {
			transform: translateX(30px);
		}
		
		.form-group {
			margin-bottom: 1rem;
		}
		
		.form-group label {
			display: block;
			margin-bottom: 0.5rem;
			font-weight: 600;
			color: var(--text-dark);
			font-size: 0.9rem;
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
		
		.form-group textarea {
			resize: vertical;
			min-height: 80px;
		}
		
		.form-group small {
			display: block;
			margin-top: 0.25rem;
			color: #666;
			font-size: 0.85rem;
		}
		
		.form-row {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 1rem;
		}
		
		.btn-primary {
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			padding: 0.75rem 2rem;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			font-size: 1rem;
			transition: all 0.3s ease;
		}
		
		.btn-primary:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
			box-shadow: var(--shadow);
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
		
		@media (max-width: 768px) {
			.form-row {
				grid-template-columns: 1fr;
			}
			
			.card {
				padding: 1.5rem;
			}
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Payment Method Settings</h1>
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
		
		<div class="card">
			<h2>Configure Payment Methods</h2>
			<p style="color: #666; margin-bottom: 2rem;">Enable or disable payment methods and set account numbers/details for each method.</p>
			
			<form method="post">
				<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
				<input type="hidden" name="action" value="update">
				
				<?php foreach ($payment_methods as $method): ?>
					<div class="payment-method-item">
						<div class="method-header">
							<div class="method-title">
								<span class="method-icon"><?php echo h($method['icon'] ?? '💳'); ?></span>
								<span><?php echo h($method['method_name']); ?></span>
							</div>
							<label class="toggle-switch">
								<input type="checkbox" 
									name="settings[<?php echo h($method['payment_method']); ?>][is_available]" 
									value="1" 
									<?php echo ($method['is_available']) ? 'checked' : ''; ?>>
								<span class="toggle-slider"></span>
							</label>
						</div>
						
					<input type="hidden" name="settings[<?php echo h($method['payment_method']); ?>][display_order]" value="<?php echo (int)$method['display_order']; ?>">
					
					<?php if ($method['payment_method'] !== 'cash'): ?>
					<div class="form-row">
						<div class="form-group">
							<label>Account Number / Mobile Number</label>
							<input type="text" 
								name="settings[<?php echo h($method['payment_method']); ?>][account_number]" 
								value="<?php echo h($method['account_number'] ?? ''); ?>" 
								placeholder="e.g., 09123456789 or Account #123456">
							<small>Enter the account number or mobile number for this payment method</small>
						</div>
						
						<div class="form-group">
							<label>Account Name</label>
							<input type="text" 
								name="settings[<?php echo h($method['payment_method']); ?>][account_name]" 
								value="<?php echo h($method['account_name'] ?? ''); ?>" 
								placeholder="e.g., Juan Dela Cruz">
							<small>Enter the account holder name</small>
						</div>
					</div>
					
					<div class="form-group">
						<label>Payment Instructions</label>
						<textarea 
							name="settings[<?php echo h($method['payment_method']); ?>][instructions]" 
							placeholder="Enter specific instructions for this payment method..."><?php echo h($method['instructions'] ?? ''); ?></textarea>
						<small>Instructions that will be shown to borrowers when they select this payment method</small>
					</div>
					<?php else: ?>
					<div style="background: #e8f5e9; padding: 1rem; border-radius: 8px; border-left: 4px solid var(--primary); color: #555; font-size: 0.9rem;">
						<strong>Note:</strong> Cash payments are processed on-site. No account details or instructions needed.
					</div>
					<?php endif; ?>
					</div>
				<?php endforeach; ?>
				
				<div style="margin-top: 2rem; text-align: right;">
					<button type="submit" class="btn-primary">Save Settings</button>
				</div>
			</form>
		</div>
	</div>
</body>
</html>

