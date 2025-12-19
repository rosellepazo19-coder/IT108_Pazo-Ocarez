<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

// Only borrowers can access
if (current_role() !== 'borrower') {
	header('Location: ../index.php');
	exit;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$current_user_id = current_user()['user_id'] ?? null;
$purchase_id = req_int('purchase_id');
$purchase = null;

// Load purchase record
if ($purchase_id && $current_user_id) {
	$stmt = $mysqli->prepare("
		SELECT p.*, s.name as supply_name, s.description as supply_description, s.image_path, s.image_alt
		FROM purchase_records p
		JOIN supplies s ON p.supply_id = s.supply_id
		WHERE p.purchase_id = ? AND p.user_id = ? AND p.status = 'pending'
	");
	$stmt->bind_param('ii', $purchase_id, $current_user_id);
	$stmt->execute();
	$purchase = $stmt->get_result()->fetch_assoc();
	$stmt->close();
	
	if (!$purchase) {
		$_SESSION['flash'] = 'Purchase record not found or already processed.';
		header('Location: purchase.php');
		exit;
	}
}

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$payment_method = req_str('payment_method');
		$payment_reference = trim(req_str('payment_reference'));
		$proof_image = null;
		
		// Handle proof image upload for online payments
		if (in_array($payment_method, ['gcash', 'paymaya', 'bank_transfer', 'others'])) {
			if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
				$upload_dir = '../uploads/payments/';
				$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
				$max_size = 5 * 1024 * 1024; // 5MB
				
				$file_info = $_FILES['proof_image'];
				
				if (!in_array($file_info['type'], $allowed_types)) {
					$errors[] = 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.';
				} elseif ($file_info['size'] > $max_size) {
					$errors[] = 'File too large. Maximum size is 5MB.';
				} elseif (!getimagesize($file_info['tmp_name'])) {
					$errors[] = 'Invalid image file.';
				} else {
					$extension = pathinfo($file_info['name'], PATHINFO_EXTENSION);
					$filename = uniqid() . '_' . time() . '.' . $extension;
					$upload_path = $upload_dir . $filename;
					
					if (move_uploaded_file($file_info['tmp_name'], $upload_path)) {
						$proof_image = 'uploads/payments/' . $filename;
					} else {
						$errors[] = 'Failed to upload proof image.';
					}
				}
			} else {
				$errors[] = 'Proof of payment is required for online payments.';
			}
		}
		
		if (!$errors) {
			// All payments (including cash) start as 'pending' - admin needs to confirm receipt
			// For cash: user will pay on-site, admin confirms when cash is received
			// For online: user submits proof, admin verifies and confirms
			$payment_status = 'pending';
			
			$stmt = $mysqli->prepare("
				UPDATE purchase_records 
				SET payment_method = ?, payment_reference = ?, proof_image = ?, payment_status = ?
				WHERE purchase_id = ? AND user_id = ?
			");
			$stmt->bind_param('ssssii', $payment_method, $payment_reference, $proof_image, $payment_status, $purchase_id, $current_user_id);
			
			if ($stmt->execute()) {
				if ($payment_method === 'cash') {
					$_SESSION['flash'] = 'Cash payment method selected. Please proceed to the office to complete your payment. Your purchase will be confirmed once payment is received.';
				} else {
					$_SESSION['flash'] = 'Payment submitted successfully! Your payment is pending admin confirmation.';
				}
				header('Location: purchase_history.php');
				exit;
			} else {
				$errors[] = 'Failed to process payment: ' . h($stmt->error);
			}
			$stmt->close();
		}
	}
}

// Load payment method settings
$payment_method_settings = [];
$settings_stmt = $mysqli->query("SELECT * FROM payment_method_settings WHERE is_available = 1 ORDER BY display_order ASC, method_name ASC");
if ($settings_stmt) {
	$payment_method_settings = $settings_stmt->fetch_all(MYSQLI_ASSOC);
	$settings_stmt->free();
}

// Fallback to default methods if table doesn't exist
if (empty($payment_method_settings)) {
	$payment_method_settings = [
		['payment_method' => 'cash', 'method_name' => 'Cash (On-site Payment)', 'icon' => '💵', 'is_available' => 1, 'display_order' => 1],
		['payment_method' => 'gcash', 'method_name' => 'GCash (Online Payment)', 'icon' => '📱', 'is_available' => 1, 'display_order' => 2],
		['payment_method' => 'paymaya', 'method_name' => 'PayMaya (Online Payment)', 'icon' => '💳', 'is_available' => 1, 'display_order' => 3],
		['payment_method' => 'bank_transfer', 'method_name' => 'Bank Transfer (Online Payment)', 'icon' => '🏦', 'is_available' => 1, 'display_order' => 4],
		['payment_method' => 'others', 'method_name' => 'Other Online Payment', 'icon' => '📲', 'is_available' => 1, 'display_order' => 5],
	];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Payment for Purchase - Cabadbaran Agricultural Supply and Equipment Lending System</title>
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
			max-width: 800px;
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
		
		.purchase-summary {
			background: #e8f5e9;
			padding: 1.5rem;
			border-radius: 10px;
			border-left: 4px solid var(--primary);
			margin-bottom: 1.5rem;
		}
		
		.purchase-summary h3 {
			margin: 0 0 1rem 0;
			color: var(--primary);
		}
		
		.purchase-summary p {
			margin: 0.5rem 0;
			font-size: 0.95rem;
		}
		
		.form-group {
			margin-bottom: 1.5rem;
		}
		
		.form-group label {
			display: block;
			margin-bottom: 0.5rem;
			font-weight: 600;
			color: var(--text-dark);
		}
		
		.form-group input,
		.form-group select,
		.form-group textarea {
			width: 100%;
			padding: 0.75rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			font-size: 0.9rem;
			transition: border-color 0.3s ease;
		}
		
		.form-group input:focus,
		.form-group select:focus,
		.form-group textarea:focus {
			outline: none;
			border-color: var(--primary);
		}
		
		.payment-instructions {
			background: #e8f5e9;
			padding: 1rem;
			border-radius: 8px;
			margin: 1rem 0;
			border-left: 4px solid var(--primary);
			font-size: 0.85rem;
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
		
		.alert.error {
			background: #f8d7da;
			color: #721c24;
			border-left: 4px solid #dc3545;
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Payment for Purchase</h1>
			<a href="purchase.php" class="btn">← Back to Supplies</a>
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
		
		<?php if ($purchase): ?>
		<div class="card">
			<div class="purchase-summary">
				<h3>Purchase Summary</h3>
				<p><strong>Item:</strong> <?php echo h($purchase['supply_name']); ?></p>
				<p><strong>Quantity:</strong> <?php echo (int)$purchase['quantity']; ?> unit(s)</p>
				<p><strong>Unit Price:</strong> ₱<?php echo number_format((float)$purchase['unit_price'], 2); ?></p>
				<p><strong style="font-size: 1.1rem; color: var(--primary);">Total Amount:</strong> <span style="font-size: 1.2rem; font-weight: 700;">₱<?php echo number_format((float)$purchase['total_amount'], 2); ?></span></p>
			</div>
			
			<h2>Payment Information</h2>
			<form method="post" enctype="multipart/form-data">
				<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
				<input type="hidden" name="purchase_id" value="<?php echo (int)$purchase_id; ?>">
				<input type="hidden" name="submit_payment" value="1">
				
				<div class="form-group">
					<label>Payment Method</label>
					<select name="payment_method" id="payment_method" required>
						<option value="">-- Select Payment Method --</option>
						<?php foreach ($payment_method_settings as $method): ?>
							<option value="<?php echo h($method['payment_method']); ?>" 
								data-account-number="<?php echo h($method['account_number'] ?? ''); ?>"
								data-account-name="<?php echo h($method['account_name'] ?? ''); ?>"
								data-instructions="<?php echo h($method['instructions'] ?? ''); ?>"
								data-is-online="<?php echo ($method['payment_method'] !== 'cash') ? '1' : '0'; ?>">
								<?php echo h(($method['icon'] ?? '💳') . ' ' . $method['method_name']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				
				<div id="cash_payment_fields" style="display: none;">
					<div class="payment-instructions" style="background: #fff3cd; border-left-color: #ff9800;">
						<strong>Cash Payment Instructions:</strong>
						<ol style="margin: 0.5rem 0; padding-left: 1.5rem;">
							<li>Proceed to the office/site to complete your cash payment</li>
							<li>Bring the exact amount: <strong>₱<?php echo number_format((float)$purchase['total_amount'], 2); ?></strong></li>
							<li>Your purchase will be confirmed once the admin receives your payment</li>
							<li>You will be notified once your payment is confirmed</li>
						</ol>
					</div>
				</div>
				
				<div id="online_payment_fields" style="display: none;">
					<div class="payment-instructions" id="payment_instructions"></div>
					
					<div class="form-group">
						<label>Reference/Transaction Number <span style="color: #e74c3c;">*</span></label>
						<input type="text" name="payment_reference" id="payment_reference" placeholder="Enter transaction/reference number">
					</div>
					
					<div class="form-group">
						<label>Upload Proof of Payment (Screenshot/Photo) <span style="color: #e74c3c;">*</span></label>
						<input type="file" name="proof_image" id="proof_image" accept="image/*">
						<small style="color: #666; display: block; margin-top: 5px;">Upload a clear screenshot or photo of your payment transaction. Maximum file size: 5MB.</small>
					</div>
				</div>
				
				<div class="form-group">
					<label>Remarks (Optional)</label>
					<textarea name="remarks" rows="3" placeholder="Any additional notes..."><?php echo h($purchase['remarks'] ?? ''); ?></textarea>
				</div>
				
				<div style="text-align: right; margin-top: 2rem;">
					<button type="submit" class="btn-primary">Submit Payment</button>
				</div>
			</form>
		</div>
		<?php else: ?>
		<div class="card">
			<p>Purchase record not found.</p>
			<a href="purchase.php" class="btn-primary">Back to Supplies</a>
		</div>
		<?php endif; ?>
	</div>
	
	<script>
		document.getElementById('payment_method').addEventListener('change', function() {
			const onlineFields = document.getElementById('online_payment_fields');
			const cashFields = document.getElementById('cash_payment_fields');
			const instructionContent = document.getElementById('payment_instructions');
			const selectedOption = this.options[this.selectedIndex];
			const paymentMethod = selectedOption.value;
			const isOnline = selectedOption.getAttribute('data-is-online') === '1';
			const accountNumber = selectedOption.getAttribute('data-account-number') || '';
			const accountName = selectedOption.getAttribute('data-account-name') || '';
			const customInstructions = selectedOption.getAttribute('data-instructions') || '';
			
			// Hide both fields first
			onlineFields.style.display = 'none';
			cashFields.style.display = 'none';
			document.getElementById('payment_reference').removeAttribute('required');
			document.getElementById('proof_image').removeAttribute('required');
			
			if (paymentMethod === 'cash') {
				// Show cash payment instructions
				cashFields.style.display = 'block';
			} else if (isOnline) {
				// Show online payment fields
				onlineFields.style.display = 'block';
				document.getElementById('payment_reference').setAttribute('required', 'required');
				document.getElementById('proof_image').setAttribute('required', 'required');
				
				let html = '<strong>Payment Instructions:</strong><ol style="margin: 0.5rem 0; padding-left: 1.5rem;">';
				
				if (accountNumber) {
					html += '<li style="margin: 0.25rem 0; font-weight: 600; color: var(--primary);">Send payment to: <strong>' + accountNumber;
					if (accountName) {
						html += ' (' + accountName + ')';
					}
					html += '</strong></li>';
				}
				
				if (customInstructions) {
					const customSteps = customInstructions.split('\n').filter(step => step.trim() !== '');
					customSteps.forEach(step => {
						html += '<li style="margin: 0.25rem 0;">' + step.trim() + '</li>';
					});
				} else {
					html += '<li>Complete your payment using the selected method</li>';
					html += '<li>Take a screenshot of the transaction confirmation</li>';
					html += '<li>Enter the Reference/Transaction Number</li>';
					html += '<li>Upload the screenshot as proof of payment</li>';
				}
				
				html += '</ol>';
				instructionContent.innerHTML = html;
			}
		});
	</script>
</body>
</html>

