<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_roles(['admin', 'staff']);

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle payment status update (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$payment_id = req_int('payment_id');
		$new_status = req_str('new_status'); // 'confirmed' or 'rejected'
		$remarks = trim(req_str('remarks'));
		
		if (!$payment_id) {
			$errors[] = 'Invalid payment ID.';
		}
		if (!in_array($new_status, ['confirmed', 'rejected'], true)) {
			$errors[] = 'Invalid status.';
		}
		
		if (!$errors) {
			// Verify payment exists and is pending
			$stmt = $mysqli->prepare("SELECT payment_id, payment_status, borrow_id FROM payments WHERE payment_id = ?");
			$stmt->bind_param('i', $payment_id);
			$stmt->execute();
			$result = $stmt->get_result();
			$payment = $result->fetch_assoc();
			$stmt->close();
			
			if (!$payment) {
				$errors[] = 'Payment not found.';
			} elseif ($payment['payment_status'] !== 'pending') {
				$errors[] = 'Only pending payments can be updated.';
			} else {
				// Update payment status
				$update_stmt = $mysqli->prepare("UPDATE payments SET payment_status = ? WHERE payment_id = ?");
				$update_stmt->bind_param('si', $new_status, $payment_id);
				$ok = $update_stmt->execute();
				$update_stmt->close();
				
				if (!$ok) {
					$errors[] = 'Failed to update payment status.';
				} else {
					if ($new_status === 'confirmed') {
						$_SESSION['flash'] = 'Payment confirmed successfully.';
					} else {
						$_SESSION['flash'] = 'Payment rejected successfully.';
					}
					header('Location: pending_payments.php');
					exit;
				}
			}
		}
	}
}

// Load pending payments with borrower and borrow details
// Show quantity for each borrow transaction
$pending_payments = [];
$stmt = $mysqli->prepare("
	SELECT 
		p.payment_id,
		p.borrow_id,
		p.amount,
		p.payment_method,
		p.payment_reference,
		p.proof_image,
		p.payment_status,
		p.date_paid,
		b.user_id,
		CONCAT(u.Fname, ' ', u.Lname) AS borrower_name,
		u.mail AS borrower_email,
		b.item_type,
		b.item_id,
		b.remarks,
		(
			SELECT COUNT(*) 
			FROM borrow_records br 
			WHERE br.user_id = b.user_id 
			AND br.item_id = b.item_id 
			AND br.item_type = b.item_type 
			AND br.due_date = b.due_date 
			AND br.status = b.status
		) AS quantity,
		CASE 
			WHEN b.item_type = 'equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id = b.item_id)
			WHEN b.item_type = 'supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id = b.item_id)
		END AS item_name,
		CASE
			WHEN b.item_type = 'equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id = b.item_id)
			WHEN b.item_type = 'supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id = b.item_id)
		END AS item_image_path,
		CASE
			WHEN b.item_type = 'equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id = b.item_id)
			WHEN b.item_type = 'supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id = b.item_id)
		END AS item_image_alt,
		b.status AS borrow_status,
		b.due_date,
		(
			SELECT SUM(br.total_amount_due) 
			FROM borrow_records br 
			WHERE br.user_id = b.user_id 
			AND br.item_id = b.item_id 
			AND br.item_type = b.item_type 
			AND br.due_date = b.due_date 
			AND br.status = b.status
		) AS total_amount_due
	FROM payments p
	JOIN borrow_records b ON p.borrow_id = b.borrow_id
	JOIN users u ON b.user_id = u.user_id
	WHERE p.payment_status = 'pending'
	ORDER BY p.date_paid ASC
");
$stmt->execute();
$pending_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Pending Payments - CBR Agricultural System</title>
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
			padding: 0.75rem 0;
			margin-bottom: 1.5rem;
			box-shadow: var(--shadow-hover);
		}
		
		.page-header .container {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 0.75rem;
		}
		
		.page-header h1 {
			margin: 0;
			font-size: 1.25rem;
			font-weight: 600;
		}
		
		.page-header .btn {
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			padding: 0.5rem 0.75rem;
			border-radius: 8px;
			text-decoration: none;
			transition: all 0.3s ease;
			font-weight: 500;
			font-size: 0.9rem;
		}
		
		.page-header .btn:hover {
			background: var(--primary);
			color: var(--text-light);
		}
		
		.card {
			background: var(--secondary);
			border-radius: 15px;
			padding: 1.5rem;
			box-shadow: var(--shadow-hover);
			margin-bottom: 1.5rem;
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
		}
		
		.pending-badge {
			display: inline-block;
			background-color: #ff9800;
			color: white;
			padding: 0.5rem 1rem;
			border-radius: 20px;
			font-weight: 600;
			font-size: 0.9rem;
			margin-bottom: 1rem;
		}
		
		.payment-card {
			background: white;
			border-radius: 12px;
			padding: 1.5rem;
			margin-bottom: 1.5rem;
			box-shadow: var(--shadow);
			border-left: 4px solid #ff9800;
		}
		
		.payment-header {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			margin-bottom: 1rem;
			flex-wrap: wrap;
			gap: 1rem;
		}
		
		.payment-info {
			flex: 1;
		}
		
		.payment-info h3 {
			margin: 0 0 0.5rem 0;
			color: var(--primary);
			font-size: 1.1rem;
		}
		
		.payment-details {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 1rem;
			margin-top: 1rem;
		}
		
		.detail-item {
			background: #f8f9fa;
			padding: 0.75rem;
			border-radius: 8px;
		}
		
		.detail-item .label {
			font-size: 0.75rem;
			color: #666;
			margin-bottom: 0.25rem;
			text-transform: uppercase;
			font-weight: 600;
		}
		
		.detail-item .value {
			font-size: 1rem;
			color: var(--text-dark);
			font-weight: 600;
		}
		
		.proof-section {
			margin-top: 1rem;
			padding-top: 1rem;
			border-top: 1px solid #ddd;
		}
		
		.proof-image-container {
			position: relative;
			display: inline-block;
			margin-top: 0.5rem;
		}
		
		.proof-image {
			max-width: 300px;
			max-height: 300px;
			border: 2px solid #ddd;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.3s ease;
		}
		
		.proof-image:hover {
			border-color: var(--primary);
			transform: scale(1.05);
		}
		
		.method-badge {
			display: inline-block;
			padding: 4px 12px;
			border-radius: 12px;
			font-size: 0.75rem;
			font-weight: bold;
			text-transform: uppercase;
		}
		
		.method-cash {
			background-color: #4caf50;
			color: white;
		}
		
		.method-gcash {
			background-color: #0052cc;
			color: white;
		}
		
		.method-paymaya {
			background-color: #00a859;
			color: white;
		}
		
		.method-bank {
			background-color: #6c757d;
			color: white;
		}
		
		.method-others {
			background-color: #9c27b0;
			color: white;
		}
		
		.action-buttons {
			display: flex;
			gap: 0.75rem;
			margin-top: 1rem;
			flex-wrap: wrap;
		}
		
		.btn {
			padding: 0.75rem 1.5rem;
			border: none;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			transition: all 0.3s ease;
			text-decoration: none;
			display: inline-block;
		}
		
		.btn-success {
			background-color: #4caf50;
			color: white;
		}
		
		.btn-success:hover {
			background-color: #45a049;
			transform: translateY(-2px);
		}
		
		.btn-danger {
			background-color: #f44336;
			color: white;
		}
		
		.btn-danger:hover {
			background-color: #da190b;
			transform: translateY(-2px);
		}
		
		.modal {
			display: none;
			position: fixed;
			z-index: 1000;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0,0,0,0.8);
		}
		
		.modal-content {
			position: relative;
			margin: 5% auto;
			max-width: 90%;
			max-height: 90%;
		}
		
		.modal-image {
			width: 100%;
			height: auto;
			border-radius: 8px;
		}
		
		.close-modal {
			position: absolute;
			top: -40px;
			right: 0;
			color: white;
			font-size: 2rem;
			font-weight: bold;
			cursor: pointer;
		}
		
		.close-modal:hover {
			color: #ccc;
		}
		
		.no-pending {
			text-align: center;
			padding: 3rem;
			color: #666;
		}
		
		.no-pending-icon {
			font-size: 4rem;
			margin-bottom: 1rem;
		}
		
		.alert {
			border-radius: 10px;
			padding: 1rem;
			margin-bottom: 1.5rem;
		}
		
		.alert.success {
			background-color: #d4edda;
			color: #155724;
			border: 1px solid #c3e6cb;
		}
		
		.alert.error {
			background-color: #f8d7da;
			color: #721c24;
			border: 1px solid #f5c6cb;
		}
		
		.alert ul {
			margin: 0.5rem 0 0 0;
			padding-left: 1.5rem;
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Pending Payments Review</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div><?php endif; ?>
		
		<div class="card">
			<h2>
				Pending Payments
				<span class="pending-badge"><?php echo count($pending_payments); ?> Pending</span>
			</h2>
			
			<?php if (empty($pending_payments)): ?>
				<div class="no-pending">
					<div class="no-pending-icon">✅</div>
					<h3>No Pending Payments</h3>
					<p>All payments have been reviewed. Check back later for new payment submissions.</p>
				</div>
			<?php else: ?>
				<?php foreach ($pending_payments as $payment): ?>
					<div class="payment-card">
						<div class="payment-header">
							<div class="payment-info">
								<h3>Payment #<?php echo h($payment['payment_id']); ?></h3>
								<p style="margin: 0; color: #666; font-size: 0.9rem;">
									Submitted: <?php echo date('M d, Y h:i A', strtotime($payment['date_paid'])); ?>
								</p>
							</div>
							<div>
								<?php 
								$method = $payment['payment_method'] ?? 'cash';
								$methodNames = [
									'cash' => 'Cash',
									'gcash' => 'GCash',
									'paymaya' => 'PayMaya',
									'bank_transfer' => 'Bank Transfer',
									'others' => 'Other'
								];
								$badgeClasses = [
									'cash' => 'method-cash',
									'gcash' => 'method-gcash',
									'paymaya' => 'method-paymaya',
									'bank_transfer' => 'method-bank',
									'others' => 'method-others'
								];
								$badgeClass = $badgeClasses[$method] ?? 'method-cash';
								?>
								<span class="method-badge <?php echo $badgeClass; ?>">
									<?php echo h($methodNames[$method] ?? ucfirst($method)); ?>
								</span>
							</div>
						</div>
						
						<div class="payment-details">
							<div class="detail-item">
								<div class="label">Borrower</div>
								<div class="value"><?php echo h($payment['borrower_name']); ?></div>
								<small style="color: #666; font-size: 0.75rem;"><?php echo h($payment['borrower_email']); ?></small>
							</div>
							
							<div class="detail-item">
								<div class="label">Item</div>
								<div class="value"><?php echo h('['.($payment['item_type']==='equipment'?'EQ':'SU').'] '.$payment['item_name']); ?></div>
								<small style="color: #666; font-size: 0.75rem;">Borrow #<?php echo h($payment['borrow_id']); ?></small>
								<?php if ((int)($payment['quantity'] ?? 1) > 1): ?>
									<div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e9; border-radius: 6px; border-left: 3px solid var(--primary);">
										<strong style="color: var(--primary); font-size: 0.9rem;">📦 Quantity: <?php echo (int)($payment['quantity'] ?? 1); ?> item<?php echo (int)($payment['quantity'] ?? 1) > 1 ? 's' : ''; ?></strong>
									</div>
								<?php endif; ?>
								<?php if (!empty($payment['item_image_path'])): ?>
									<img src="../<?php echo h($payment['item_image_path']); ?>" 
										alt="<?php echo h($payment['item_image_alt'] ?? $payment['item_name']); ?>" 
										style="max-width: 120px; max-height: 120px; border: 2px solid #ddd; border-radius: 8px; object-fit: cover; margin-top: 0.5rem; cursor: pointer; display: block;"
										onclick="window.open('../<?php echo h($payment['item_image_path']); ?>', '_blank')"
										title="Click to view full size"
										onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
									<div style="display: none; width: 120px; height: 120px; background: #f8f9fa; border: 2px solid #ddd; border-radius: 8px; align-items: center; justify-content: center; color: #6c757d; font-size: 11px; text-align: center; margin-top: 0.5rem;">
										No Image
									</div>
								<?php else: ?>
									<div style="width: 120px; height: 120px; background: #f8f9fa; border: 2px solid #ddd; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 11px; text-align: center; margin-top: 0.5rem;">
										No Image
									</div>
								<?php endif; ?>
							</div>
							
							<div class="detail-item">
								<div class="label">Payment Amount</div>
								<div class="value" style="color: var(--primary); font-size: 1.25rem;">₱<?php echo number_format((float)$payment['amount'], 2); ?></div>
								<small style="color: #666; font-size: 0.75rem;">
									Total Due: ₱<?php echo number_format((float)$payment['total_amount_due'], 2); ?>
									<?php if ((int)($payment['quantity'] ?? 1) > 1): ?>
										<br>(for <?php echo (int)($payment['quantity'] ?? 1); ?> item<?php echo (int)($payment['quantity'] ?? 1) > 1 ? 's' : ''; ?>)
									<?php endif; ?>
								</small>
							</div>
							
							<?php if (!empty($payment['payment_reference'])): ?>
							<div class="detail-item">
								<div class="label">Reference Number</div>
								<div class="value">
									<code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; font-size: 0.9rem; font-weight: 600;">
										<?php echo h($payment['payment_reference']); ?>
									</code>
								</div>
							</div>
							<?php endif; ?>
							
							<div class="detail-item">
								<div class="label">Borrow Status</div>
								<div class="value"><?php echo h(ucfirst($payment['borrow_status'])); ?></div>
							</div>
						</div>
						
						<?php if (!empty($payment['remarks'])): ?>
						<div style="background: #e8f5e9; padding: 1rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid var(--primary);">
							<div class="label" style="margin-bottom: 0.5rem; color: var(--primary); font-weight: 600;">📝 Remarks from Borrower:</div>
							<div style="color: var(--text-dark); font-size: 0.95rem; line-height: 1.6; white-space: pre-wrap;"><?php echo h($payment['remarks']); ?></div>
						</div>
						<?php endif; ?>
						
						<?php if (!empty($payment['proof_image'])): ?>
						<div class="proof-section">
							<strong style="color: var(--primary);">Proof of Payment:</strong>
							<div class="proof-image-container">
								<a href="#" onclick="openImageModal('../<?php echo h($payment['proof_image']); ?>'); return false;" title="Click to view full image">
									<img src="../<?php echo h($payment['proof_image']); ?>" alt="Proof of Payment" class="proof-image">
								</a>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ($payment['payment_method'] === 'cash'): ?>
						<div style="background: #fff3cd; padding: 0.75rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid #ff9800;">
							<strong style="color: #856404;">💵 Cash Payment:</strong>
							<p style="margin: 0.5rem 0 0 0; color: #856404; font-size: 0.9rem;">
								The borrower has selected cash payment. Please confirm that you have received the cash payment of <strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong> before approving.
							</p>
						</div>
						<?php endif; ?>
						
						<div class="action-buttons">
							<form method="post" class="inline" onsubmit="return confirm('<?php echo $payment['payment_method'] === 'cash' ? 'Confirm that you have received the cash payment? This will mark it as confirmed.' : 'Confirm this payment? This will mark it as confirmed.'; ?>');">
								<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
								<input type="hidden" name="payment_id" value="<?php echo (int)$payment['payment_id']; ?>">
								<input type="hidden" name="new_status" value="confirmed">
								<button type="submit" name="update_payment_status" class="btn btn-success">
									✅ <?php echo $payment['payment_method'] === 'cash' ? 'Confirm Cash Received' : 'Approve Payment'; ?>
								</button>
							</form>
							
							<form method="post" class="inline" onsubmit="return confirm('Reject this payment? This will mark it as rejected. Please contact the borrower if needed.');">
								<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
								<input type="hidden" name="payment_id" value="<?php echo (int)$payment['payment_id']; ?>">
								<input type="hidden" name="new_status" value="rejected">
								<button type="submit" name="update_payment_status" class="btn btn-danger">
									❌ Reject Payment
								</button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
	
	<!-- Image Modal -->
	<div id="imageModal" class="modal" onclick="closeImageModal()">
		<span class="close-modal" onclick="closeImageModal()">&times;</span>
		<div class="modal-content">
			<img id="modalImage" class="modal-image" src="" alt="Payment Proof">
		</div>
	</div>
	
	<script>
		function openImageModal(imageSrc) {
			const modal = document.getElementById('imageModal');
			const modalImg = document.getElementById('modalImage');
			modal.style.display = 'block';
			modalImg.src = imageSrc;
		}
		
		function closeImageModal() {
			document.getElementById('imageModal').style.display = 'none';
		}
		
		// Close modal on ESC key
		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape') {
				closeImageModal();
			}
		});
		
		// Prevent modal from closing when clicking on image
		document.getElementById('modalImage').addEventListener('click', function(e) {
			e.stopPropagation();
		});
	</script>
</body>
</html>

