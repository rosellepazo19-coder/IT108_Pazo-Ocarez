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
		$purchase_id = req_int('purchase_id');
		$new_status = req_str('new_status'); // 'confirmed' or 'rejected'
		
		if (!$purchase_id) {
			$errors[] = 'Invalid purchase ID.';
		}
		if (!in_array($new_status, ['confirmed', 'rejected'], true)) {
			$errors[] = 'Invalid status.';
		}
		
		if (!$errors) {
			// Verify purchase exists and payment is pending
			$stmt = $mysqli->prepare("SELECT purchase_id, payment_status, status FROM purchase_records WHERE purchase_id = ?");
			$stmt->bind_param('i', $purchase_id);
			$stmt->execute();
			$result = $stmt->get_result();
			$purchase = $result->fetch_assoc();
			$stmt->close();
			
			if (!$purchase) {
				$errors[] = 'Purchase not found.';
			} elseif ($purchase['payment_status'] !== 'pending') {
				$errors[] = 'Only pending payments can be updated.';
			} else {
				// Update payment status and purchase status
				$update_stmt = $mysqli->prepare("
					UPDATE purchase_records 
					SET payment_status = ?, status = ?, confirmed_at = NOW()
					WHERE purchase_id = ?
				");
				$new_purchase_status = ($new_status === 'confirmed') ? 'confirmed' : 'cancelled';
				$update_stmt->bind_param('ssi', $new_status, $new_purchase_status, $purchase_id);
				$ok = $update_stmt->execute();
				
				if ($ok && $new_status === 'confirmed') {
					// Update supply quantity - deduct purchased quantity
					$qty_stmt = $mysqli->prepare("
						UPDATE supplies s
						INNER JOIN purchase_records pr ON s.supply_id = pr.supply_id
						SET s.quantity = s.quantity - pr.quantity
						WHERE pr.purchase_id = ? AND pr.payment_status = 'confirmed'
					");
					$qty_stmt->bind_param('i', $purchase_id);
					$qty_stmt->execute();
					$qty_stmt->close();
				}
				
				$update_stmt->close();
				
				if (!$ok) {
					$errors[] = 'Failed to update payment status.';
				} else {
					if ($new_status === 'confirmed') {
						$_SESSION['flash'] = 'Purchase payment confirmed successfully. Inventory updated.';
					} else {
						$_SESSION['flash'] = 'Purchase payment rejected successfully.';
					}
					header('Location: pending_purchase_payments.php');
					exit;
				}
			}
		}
	}
}

// Load pending purchase payments
$pending_purchases = [];
$stmt = $mysqli->prepare("
	SELECT 
		p.purchase_id,
		p.user_id,
		p.supply_id,
		p.quantity,
		p.unit_price,
		p.total_amount,
		p.payment_method,
		p.payment_reference,
		p.proof_image,
		p.payment_status,
		p.status,
		p.remarks,
		p.created_at,
		s.name as supply_name,
		s.description as supply_description,
		s.image_path,
		s.image_alt,
		CONCAT(u.Fname, ' ', u.Lname) AS buyer_name,
		u.mail AS buyer_email
	FROM purchase_records p
	JOIN supplies s ON p.supply_id = s.supply_id
	JOIN users u ON p.user_id = u.user_id
	WHERE p.payment_status = 'pending'
	ORDER BY p.created_at ASC
");
$stmt->execute();
$pending_purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Pending Purchase Payments - CBR Agricultural System</title>
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
		
		.purchase-card {
			background: white;
			border-radius: 12px;
			padding: 1.5rem;
			margin-bottom: 1.5rem;
			box-shadow: var(--shadow);
			border-left: 4px solid #ff9800;
		}
		
		.purchase-header {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			margin-bottom: 1rem;
			flex-wrap: wrap;
			gap: 1rem;
		}
		
		.purchase-info {
			flex: 1;
		}
		
		.purchase-info h3 {
			margin: 0 0 0.5rem 0;
			color: var(--primary);
			font-size: 1.1rem;
		}
		
		.purchase-details {
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
		
		.supply-image {
			max-width: 100px;
			max-height: 100px;
			border: 2px solid #ddd;
			border-radius: 8px;
			object-fit: cover;
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
			<h1>Pending Purchase Payments Review</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div><?php endif; ?>
		
		<div class="card">
			<h2>
				Pending Purchase Payments
				<span class="pending-badge"><?php echo count($pending_purchases); ?> Pending</span>
			</h2>
			
			<?php if (empty($pending_purchases)): ?>
				<div class="no-pending">
					<div class="no-pending-icon">✅</div>
					<h3>No Pending Purchase Payments</h3>
					<p>All purchase payments have been reviewed. Check back later for new payment submissions.</p>
				</div>
			<?php else: ?>
				<?php foreach ($pending_purchases as $purchase): ?>
					<div class="purchase-card">
						<div class="purchase-header">
							<div class="purchase-info">
								<h3>Purchase #<?php echo h($purchase['purchase_id']); ?></h3>
								<p style="margin: 0; color: #666; font-size: 0.9rem;">
									Submitted: <?php echo date('M d, Y h:i A', strtotime($purchase['created_at'])); ?>
								</p>
							</div>
							<div>
								<?php 
								$method = $purchase['payment_method'] ?? 'cash';
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
						
						<div class="purchase-details">
							<div class="detail-item">
								<div class="label">Buyer</div>
								<div class="value"><?php echo h($purchase['buyer_name']); ?></div>
								<small style="color: #666; font-size: 0.75rem;"><?php echo h($purchase['buyer_email']); ?></small>
							</div>
							
							<div class="detail-item">
								<div class="label">Supply Item</div>
								<div class="value"><?php echo h($purchase['supply_name']); ?></div>
								<?php if (!empty($purchase['image_path'])): ?>
									<img src="../<?php echo h($purchase['image_path']); ?>" 
										alt="<?php echo h($purchase['image_alt'] ?? $purchase['supply_name']); ?>" 
										class="supply-image"
										style="margin-top: 0.5rem;">
								<?php endif; ?>
							</div>
							
							<div class="detail-item">
								<div class="label">Quantity</div>
								<div class="value"><?php echo (int)$purchase['quantity']; ?> unit(s)</div>
								<small style="color: #666; font-size: 0.75rem;">Unit Price: ₱<?php echo number_format((float)$purchase['unit_price'], 2); ?></small>
							</div>
							
							<div class="detail-item">
								<div class="label">Total Amount</div>
								<div class="value" style="color: var(--primary); font-size: 1.25rem;">₱<?php echo number_format((float)$purchase['total_amount'], 2); ?></div>
							</div>
							
							<?php if (!empty($purchase['payment_reference'])): ?>
							<div class="detail-item">
								<div class="label">Reference Number</div>
								<div class="value">
									<code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; font-size: 0.9rem; font-weight: 600;">
										<?php echo h($purchase['payment_reference']); ?>
									</code>
								</div>
							</div>
							<?php endif; ?>
							
						</div>
						
						<?php if (!empty($purchase['remarks'])): ?>
						<div style="background: #e8f5e9; padding: 1rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid var(--primary);">
							<div class="label" style="margin-bottom: 0.5rem; color: var(--primary); font-weight: 600;">📝 Remarks from Buyer:</div>
							<div style="color: var(--text-dark); font-size: 0.95rem; line-height: 1.6; white-space: pre-wrap;"><?php echo h($purchase['remarks']); ?></div>
						</div>
						<?php endif; ?>
						
						<?php if (!empty($purchase['proof_image'])): ?>
						<div class="proof-section">
							<strong style="color: var(--primary);">Proof of Payment:</strong>
							<div class="proof-image-container">
								<a href="#" onclick="openImageModal('../<?php echo h($purchase['proof_image']); ?>'); return false;" title="Click to view full image">
									<img src="../<?php echo h($purchase['proof_image']); ?>" alt="Proof of Payment" class="proof-image">
								</a>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ($purchase['payment_method'] === 'cash'): ?>
						<div style="background: #fff3cd; padding: 0.75rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid #ff9800;">
							<strong style="color: #856404;">💵 Cash Payment:</strong>
							<p style="margin: 0.5rem 0 0 0; color: #856404; font-size: 0.9rem;">
								The buyer has selected cash payment. Please confirm that you have received the cash payment of <strong>₱<?php echo number_format((float)$purchase['total_amount'], 2); ?></strong> before approving.
							</p>
						</div>
						<?php endif; ?>
						
						<div class="action-buttons">
							<form method="post" class="inline" onsubmit="return confirm('<?php echo $purchase['payment_method'] === 'cash' ? 'Confirm that you have received the cash payment? This will update the inventory and mark the purchase as confirmed.' : 'Confirm this purchase payment? This will update the inventory and mark the purchase as confirmed.'; ?>');">
								<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
								<input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['purchase_id']; ?>">
								<input type="hidden" name="new_status" value="confirmed">
								<button type="submit" name="update_payment_status" class="btn btn-success">
									✅ <?php echo $purchase['payment_method'] === 'cash' ? 'Confirm Cash Received' : 'Approve Payment'; ?>
								</button>
							</form>
							
							<form method="post" class="inline" onsubmit="return confirm('Reject this purchase payment? This will cancel the purchase. Please contact the buyer if needed.');">
								<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
								<input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['purchase_id']; ?>">
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

