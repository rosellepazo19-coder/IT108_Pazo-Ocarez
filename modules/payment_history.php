<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All authenticated users can access payment history (borrowers see only their own, admin/staff see all)
$current_role = current_role();
if (!in_array($current_role, ['admin', 'staff', 'borrower'])) {
	header('Location: ../index.php');
	exit;
}

$checked_payment_batch_column = false;
function ensure_payment_batch_column(mysqli $mysqli, bool &$checked): void {
	if ($checked) return;
	$checked = true;
	$res = $mysqli->query("SHOW COLUMNS FROM payments LIKE 'payment_batch_id'");
	if ($res && $res->num_rows === 0) {
		$mysqli->query("ALTER TABLE payments ADD COLUMN payment_batch_id VARCHAR(64) NULL AFTER borrow_id");
		$idx = $mysqli->query("SHOW INDEX FROM payments WHERE Key_name = 'idx_payment_batch_id'");
		if ($idx && $idx->num_rows === 0) {
			$mysqli->query("CREATE INDEX idx_payment_batch_id ON payments(payment_batch_id)");
		}
		if ($idx) { $idx->free(); }
	}
	if ($res) { $res->free(); }
}
ensure_payment_batch_column($mysqli, $checked_payment_batch_column);

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Get current user ID
$current_user_id = current_user()['user_id'] ?? null;

function aggregate_borrow_payment_batches(array $rows): array {
	$grouped = [];
	foreach ($rows as $row) {
		$batch = $row['payment_batch_id'] ?? null;
		if (!$batch) {
			$batch = 'single_' . $row['payment_id'];
		}
		$entryAmount = (float)($row['amount'] ?? 0);
		if (!isset($grouped[$batch])) {
			$newRow = $row;
			$newRow['amount'] = 0.0;
			$newRow['quantity_paid'] = 0;
			$newRow['primary_borrow_id'] = $row['borrow_id'];
			$newRow['aggregated_payment_ids'] = [];
			$newRow['status_counter'] = [
				'pending' => 0,
				'confirmed' => 0,
				'rejected' => 0
			];
			$grouped[$batch] = $newRow;
		}
		$grouped[$batch]['amount'] += $entryAmount;
		$grouped[$batch]['quantity_paid'] += 1;
		$grouped[$batch]['aggregated_payment_ids'][] = (int)$row['payment_id'];
		if (!isset($grouped[$batch]['date_paid']) || strtotime($row['date_paid']) > strtotime($grouped[$batch]['date_paid'])) {
			$grouped[$batch]['date_paid'] = $row['date_paid'];
		}
		$status = $row['payment_status'] ?? 'pending';
		if (!isset($grouped[$batch]['status_counter'][$status])) {
			$grouped[$batch]['status_counter'][$status] = 0;
		}
		$grouped[$batch]['status_counter'][$status]++;
	}
	foreach ($grouped as &$row) {
		$row['payment_id'] = $row['aggregated_payment_ids'][0] ?? $row['payment_id'];
		$row['borrow_id'] = $row['primary_borrow_id'];
		$pending = $row['status_counter']['pending'] ?? 0;
		$rejected = $row['status_counter']['rejected'] ?? 0;
		$confirmed = $row['status_counter']['confirmed'] ?? 0;
		if ($pending > 0) {
			$row['payment_status'] = 'pending';
		} elseif ($rejected > 0 && $confirmed === 0) {
			$row['payment_status'] = 'rejected';
		} else {
			$row['payment_status'] = 'confirmed';
		}
	}
	unset($row);
	return array_values($grouped);
}

// Load payments - borrowers see only their own, admin/staff see all
$borrow_payments = [];
$purchase_payments = [];
$all_payments = [];
$filter_borrow_id = req_int('borrow_id'); // Optional filter by borrow_id

if ($current_role === 'borrower' && $current_user_id) {
	// Borrowers can only see their own payments
	// If borrow_id is provided, filter by it (but still verify ownership)
	if ($filter_borrow_id) {
		$stmt = $mysqli->prepare("
			SELECT 
				p.payment_id,
				p.borrow_id,
				p.payment_batch_id,
				p.amount,
				p.payment_method,
				p.payment_reference,
				p.proof_image,
				p.date_paid,
				p.payment_status,
				b.item_type,
				b.item_id,
				b.user_id,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_path,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_alt,
				b.date_borrowed,
				b.due_date,
				b.return_date,
				b.status AS borrow_status
			FROM payments p
			JOIN borrow_records b ON p.borrow_id = b.borrow_id
			JOIN users u ON u.user_id = b.user_id
			WHERE b.user_id = ? 
			  AND p.hidden_from_borrower = 0
			  AND (
					p.borrow_id = ?
					OR (
						p.payment_batch_id IS NOT NULL
						AND p.payment_batch_id IN (
							SELECT payment_batch_id 
							FROM payments 
							WHERE borrow_id = ?
							  AND payment_batch_id IS NOT NULL
						)
					)
			  )
			ORDER BY p.date_paid DESC
		");
		$stmt->bind_param('iii', $current_user_id, $filter_borrow_id, $filter_borrow_id);
	} else {
		$stmt = $mysqli->prepare("
			SELECT 
				p.payment_id,
				p.borrow_id,
				p.payment_batch_id,
				p.amount,
				p.payment_method,
				p.payment_reference,
				p.proof_image,
				p.date_paid,
				p.payment_status,
				b.item_type,
				b.item_id,
				b.user_id,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_path,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_alt,
				b.date_borrowed,
				b.due_date,
				b.return_date,
				b.status AS borrow_status
			FROM payments p
			JOIN borrow_records b ON p.borrow_id = b.borrow_id
			JOIN users u ON u.user_id = b.user_id
			WHERE b.user_id = ? AND p.hidden_from_borrower = 0
			ORDER BY p.date_paid DESC
		");
		$stmt->bind_param('i', $current_user_id);
	}
	$stmt->execute();
	$result = $stmt->get_result();
	$borrow_payments = $result->fetch_all(MYSQLI_ASSOC);
	$stmt->close();
} else {
	// Admin/Staff see all payments
	if ($filter_borrow_id) {
		$stmt = $mysqli->prepare("
			SELECT 
				p.payment_id,
				p.borrow_id,
				p.payment_batch_id,
				p.amount,
				p.payment_method,
				p.payment_reference,
				p.proof_image,
				p.date_paid,
				p.payment_status,
				b.item_type,
				b.item_id,
				b.user_id,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_path,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_alt,
				b.date_borrowed,
				b.due_date,
				b.return_date,
				b.status AS borrow_status
			FROM payments p
			JOIN borrow_records b ON p.borrow_id = b.borrow_id
			JOIN users u ON u.user_id = b.user_id
			WHERE p.hidden_from_admin = 0
			  AND (
				p.borrow_id = ?
				OR (
					p.payment_batch_id IS NOT NULL
					AND p.payment_batch_id IN (
						SELECT payment_batch_id 
						FROM payments 
						WHERE borrow_id = ?
						  AND payment_batch_id IS NOT NULL
					)
				)
			  )
			ORDER BY p.date_paid DESC
		");
		$stmt->bind_param('ii', $filter_borrow_id, $filter_borrow_id);
		$stmt->execute();
		$resP = $stmt->get_result();
		$stmt->close();
	} else {
		$resP = $mysqli->query("
			SELECT 
				p.payment_id,
				p.borrow_id,
				p.amount,
				p.payment_method,
				p.payment_reference,
				p.proof_image,
				p.date_paid,
				p.payment_status,
				b.item_type,
				b.item_id,
				b.user_id,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_path,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_alt,
				b.date_borrowed,
				b.due_date,
				b.return_date,
				b.status AS borrow_status
			FROM payments p
			JOIN borrow_records b ON p.borrow_id = b.borrow_id
			JOIN users u ON u.user_id = b.user_id
			WHERE p.hidden_from_admin = 0
			ORDER BY p.date_paid DESC
		");
	}
	if ($resP) { 
		$borrow_payments = $resP->fetch_all(MYSQLI_ASSOC); 
		$resP->free(); 
	}
}

// Tag borrow payments for unified display
if (!empty($borrow_payments)) {
	foreach ($borrow_payments as &$bp) {
		$bp['transaction_type'] = 'borrow';
		$bp['record_id'] = $bp['borrow_id'] ?? null;
	}
	unset($bp);
	$borrow_payments = aggregate_borrow_payment_batches($borrow_payments);
}

// ------------------------------------------------------------------
// Load purchase payments (supplies)
// ------------------------------------------------------------------
$purchase_filter_id = req_int('purchase_id');
if ($current_role === 'borrower' && $current_user_id) {
	if ($purchase_filter_id) {
		$stmt = $mysqli->prepare("
			SELECT 
				pr.*,
				s.name AS item_name,
				s.image_path AS item_image_path,
				s.image_alt AS item_image_alt,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name
			FROM purchase_records pr
			JOIN supplies s ON pr.supply_id = s.supply_id
			JOIN users u ON pr.user_id = u.user_id
			WHERE pr.user_id = ? AND pr.purchase_id = ?
			ORDER BY pr.created_at DESC
		");
		$stmt->bind_param('ii', $current_user_id, $purchase_filter_id);
	} else {
		$stmt = $mysqli->prepare("
			SELECT 
				pr.*,
				s.name AS item_name,
				s.image_path AS item_image_path,
				s.image_alt AS item_image_alt,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name
			FROM purchase_records pr
			JOIN supplies s ON pr.supply_id = s.supply_id
			JOIN users u ON pr.user_id = u.user_id
			WHERE pr.user_id = ?
			ORDER BY pr.created_at DESC
		");
		$stmt->bind_param('i', $current_user_id);
	}
	$stmt->execute();
	$purchase_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
	$stmt->close();
} else {
	$query = "
		SELECT 
			pr.*,
			s.name AS item_name,
			s.image_path AS item_image_path,
			s.image_alt AS item_image_alt,
			CONCAT(u.Fname, ' ', u.Lname) AS user_name
		FROM purchase_records pr
		JOIN supplies s ON pr.supply_id = s.supply_id
		JOIN users u ON pr.user_id = u.user_id
	";
	if ($purchase_filter_id) {
		$query .= " WHERE pr.purchase_id = " . (int)$purchase_filter_id;
	}
	$query .= " ORDER BY pr.created_at DESC";
	$resPurch = $mysqli->query($query);
	if ($resPurch) {
		$purchase_payments = $resPurch->fetch_all(MYSQLI_ASSOC);
		$resPurch->free();
	}
}

if (!empty($purchase_payments)) {
	foreach ($purchase_payments as &$pp) {
		$pp['transaction_type'] = 'purchase';
		$pp['record_id'] = $pp['purchase_id'] ?? null;
		$pp['payment_id'] = 'PUR-' . ($pp['purchase_id'] ?? '0');
		$pp['borrow_id'] = null;
		$pp['item_type'] = 'supplies';
		$pp['amount'] = (float)($pp['total_amount'] ?? 0);
		$pp['date_paid'] = $pp['confirmed_at'] ?? $pp['created_at'] ?? date('Y-m-d H:i:s');
		$pp['proof_image'] = $pp['proof_image'] ?? null;
		$pp['payment_status'] = $pp['payment_status'] ?? 'pending';
		$pp['borrow_status'] = $pp['status'] ?? 'pending';
		$pp['quantity'] = (int)($pp['quantity'] ?? 0);
		$pp['unit_price'] = (float)($pp['unit_price'] ?? 0);
	}
	unset($pp);
}

// Merge and sort all records by payment date (newest first)
$all_payments = array_merge($borrow_payments, $purchase_payments);
usort($all_payments, function($a, $b) {
	return strcmp($b['date_paid'] ?? '', $a['date_paid'] ?? '');
});

$visible_payments = array_values(array_filter($all_payments, function($payment) {
	$status = $payment['payment_status'] ?? 'confirmed';
	return $status === 'confirmed';
}));

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $current_role === 'borrower' ? 'My Payment History' : 'Payment History'; ?> - CBR Agricultural System</title>
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
			margin-bottom: 1rem;
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
		
		.container {
			max-width: 1400px;
		}
		
		.card {
			background: var(--secondary);
			border-radius: 12px;
			padding: 1rem;
			box-shadow: var(--shadow-hover);
			margin-bottom: 0;
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
			margin-bottom: 1rem;
			font-size: 1.1rem;
		}
		
		.table {
			background: white;
			border-radius: 10px;
			overflow: hidden;
		}
		
		.table th {
			background: var(--primary);
			color: var(--text-light);
			padding: 0.6rem 0.75rem;
			font-weight: 600;
			text-align: left;
			font-size: 0.85rem;
		}
		
		.table td {
			padding: 0.6rem 0.75rem;
			border-bottom: 1px solid #f0f0f0;
			font-size: 0.85rem;
		}
		
		.table tr:hover {
			background: #f9f9f9;
		}
		
		.table tr:last-child td {
			border-bottom: none;
		}
		
		.method-badge {
			display: inline-block;
			padding: 4px 10px;
			border-radius: 12px;
			font-size: 12px;
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
		
		.status-badge {
			padding: 3px 8px;
			border-radius: 10px;
			font-size: 11px;
			font-weight: bold;
		}
		
		.status-confirmed {
			background-color: var(--primary);
			color: white;
		}
		
		.status-pending {
			background-color: var(--accent);
			color: var(--primary);
		}
		
		.status-rejected {
			background-color: #f44336;
			color: white;
		}
		
		.status-returned {
			background-color: var(--secondary);
			color: var(--primary);
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
		
		.proof-image {
			width: 60px;
			height: 60px;
			object-fit: cover;
			border-radius: 6px;
			cursor: pointer;
			border: 2px solid #ddd;
			transition: all 0.3s ease;
		}
		
		.proof-image:hover {
			opacity: 0.8;
			border-color: var(--primary);
			transform: scale(1.1);
		}
		
		.summary-card {
			background: linear-gradient(135deg, var(--primary) 0%, #1b5e20 100%);
			color: var(--text-light);
			border-radius: 12px;
			padding: 1.5rem;
			margin-bottom: 1.5rem;
			box-shadow: var(--shadow-hover);
		}
		
		.summary-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 1.5rem;
			margin-top: 1rem;
		}
		
		.summary-item {
			background: rgba(255, 255, 255, 0.15);
			padding: 1rem;
			border-radius: 8px;
			text-align: center;
			border: 1px solid rgba(255, 255, 255, 0.2);
		}
		
		.summary-item .label {
			font-size: 0.85rem;
			opacity: 0.9;
			margin-bottom: 0.5rem;
		}
		
		.summary-item .value {
			font-size: 1.5rem;
			font-weight: 700;
		}
		
		.payment-details {
			background: #f8f9fa;
			padding: 0.75rem;
			border-radius: 6px;
			margin-top: 0.5rem;
			font-size: 0.85rem;
		}
		
		.borrow-link {
			color: var(--primary);
			text-decoration: none;
			font-weight: 600;
			font-size: 0.9rem;
		}
		
		.borrow-link:hover {
			text-decoration: underline;
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
		
		.alert {
			border-radius: 8px;
			padding: 0.75rem;
			margin-bottom: 1rem;
			font-size: 0.9rem;
		}
		
		.alert.success {
			background: var(--accent);
			color: var(--primary);
			border: 1px solid var(--primary);
		}
		
		.alert.error {
			background: #f8d7da;
			color: #721c24;
			border: 1px solid #f5c6cb;
		}
		
		.no-results {
			text-align: center;
			padding: 2rem;
			color: #999;
		}
		
		.no-results p {
			margin: 0.5rem 0;
			font-size: 0.95rem;
		}
	</style>
</head>
<body>
		<div class="page-header">
		<div class="container">
			<h1><?php echo $current_role === 'borrower' ? 'My Payment History' : 'Payment History'; ?></h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?>
			<div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
		<?php endif; ?>

		<?php if ($current_role === 'borrower' && !empty($all_payments)): ?>
		<?php
			// Calculate summary statistics for borrower
			$total_paid = 0;
			$total_confirmed = 0;
			$total_pending = 0;
			$total_rejected = 0;
			$payment_count = count($visible_payments);
			
			foreach ($visible_payments as $p) {
				$amount = (float)$p['amount'];
				$total_paid += $amount;
				$total_confirmed += $amount;
			}
			foreach ($all_payments as $p) {
				$status = $p['payment_status'] ?? 'confirmed';
				$amount = (float)$p['amount'];
				if ($status === 'pending') {
					$total_pending += $amount;
				} elseif ($status === 'rejected') {
					$total_rejected += $amount;
				}
			}
		?>
		<div class="summary-card">
			<h2 style="margin: 0 0 1rem 0; color: var(--text-light);">Payment Summary</h2>
			<div class="summary-grid">
				<div class="summary-item">
					<div class="label">Total Payments</div>
					<div class="value"><?php echo $payment_count; ?></div>
				</div>
				<div class="summary-item">
					<div class="label">Total Amount Paid</div>
					<div class="value">₱<?php echo number_format($total_paid, 2); ?></div>
				</div>
				<div class="summary-item">
					<div class="label">Confirmed</div>
					<div class="value">₱<?php echo number_format($total_confirmed, 2); ?></div>
				</div>
				<div class="summary-item">
					<div class="label">Pending</div>
					<div class="value">₱<?php echo number_format($total_pending, 2); ?></div>
				</div>
				<?php if ($total_rejected > 0): ?>
				<div class="summary-item">
					<div class="label">Rejected</div>
					<div class="value">₱<?php echo number_format($total_rejected, 2); ?></div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<div class="card">
			<h2><?php echo $current_role === 'borrower' ? 'My Payment Records' : 'All Payment Records'; ?></h2>
			<?php 
			// Check if there are any confirmed payments
			$has_confirmed_payments = !empty($visible_payments);
			?>
			
			<?php if (!$has_confirmed_payments): ?>
				<div class="no-results" style="text-align: center; padding: 3rem;">
					<div style="font-size: 3rem; margin-bottom: 1rem;">⏳</div>
					<h3 style="color: var(--primary); margin-bottom: 0.5rem;">Pending Payment</h3>
					<?php if ($current_role === 'borrower'): ?>
						<p style="color: #666; font-size: 0.95rem;">
							Your payment is pending admin confirmation. Once confirmed, it will appear here.
						</p>
						<?php if (!empty($all_payments)): ?>
							<?php 
							$pending_payment = $all_payments[0];
							$pending_amount = (float)($pending_payment['amount'] ?? 0);
							?>
							<div style="background: var(--accent); padding: 1rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid var(--primary); display: inline-block; text-align: left;">
								<p style="margin: 0; font-weight: 600; color: var(--primary);">Pending Amount: <strong>₱<?php echo number_format($pending_amount, 2); ?></strong></p>
								<p style="margin: 0.5rem 0 0 0; font-size: 0.9rem; color: var(--primary);">
									Status: <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">Pending</span>
								</p>
							</div>
						<?php else: ?>
							<p style="color: #666; font-size: 0.95rem; margin-top: 0.5rem;">
								No payment records found. Make a payment to see your payment history.
							</p>
						<?php endif; ?>
					<?php else: ?>
						<p style="color: #666; font-size: 0.95rem;">
							No confirmed payments found. Pending payments will appear here once confirmed.
						</p>
					<?php endif; ?>
				</div>
			<?php elseif (empty($all_payments)): ?>
				<div class="no-results">
					<p>No payment records found.</p>
					<?php if ($current_role === 'borrower'): ?>
						<p>Your payment history will appear here after you make payments.</p>
					<?php else: ?>
						<p>Payments will appear here after borrowers make payments.</p>
					<?php endif; ?>
			<?php else: ?>
			<table class="table">
				<thead>
				<tr>
					<th>Payment #</th>
					<th>Type</th>
					<?php if ($current_role !== 'borrower'): ?>
						<th>Borrower</th>
					<?php endif; ?>
					<th>Image</th>
					<th>Item</th>
					<th>Qty</th>
					<th>Reference</th>
					<th>Amount</th>
					<th>Method</th>
					<th>Reference #</th>
					<th>Proof</th>
					<th>Date Paid</th>
					<th>Status</th>
					<th>Transaction Status</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($visible_payments as $p): ?>
					<tr>
						<td><strong>#<?php echo h($p['payment_id']); ?></strong></td>
						<td><?php echo $p['transaction_type'] === 'purchase' ? 'Supply Purchase' : 'Borrow Payment'; ?></td>
						<?php if ($current_role !== 'borrower'): ?>
							<td><?php echo h($p['user_name']); ?></td>
						<?php endif; ?>
						<td>
							<?php if (!empty($p['item_image_path'])): ?>
								<img src="../<?php echo h($p['item_image_path']); ?>" 
									alt="<?php echo h($p['item_image_alt'] ?? $p['item_name']); ?>" 
									style="width: 60px; height: 60px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd; cursor: pointer;"
									onclick="window.open('../<?php echo h($p['item_image_path']); ?>', '_blank')"
									title="Click to view full size">
							<?php else: ?>
								<div style="width: 60px; height: 60px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 11px; text-align: center;">
									No Image
								</div>
							<?php endif; ?>
						</td>
						<td>
							<?php echo h('['.($p['item_type']==='equipment'?'EQ':'SU').'] '.$p['item_name']); ?>
							<?php if ($p['transaction_type'] === 'borrow' && $current_role === 'borrower'): ?>
								<div class="payment-details">
									<small>Borrowed: <?php echo $p['date_borrowed'] ? date('M d, Y', strtotime($p['date_borrowed'])) : 'N/A'; ?></small>
									<?php if ($p['due_date']): ?>
										<br><small>Due: <?php echo date('M d, Y', strtotime($p['due_date'])); ?></small>
									<?php endif; ?>
								</div>
							<?php elseif ($p['transaction_type'] === 'purchase'): ?>
								<div class="payment-details">
									<small>Quantity: <?php echo (int)($p['quantity'] ?? 0); ?> @ ₱<?php echo number_format((float)($p['unit_price'] ?? 0), 2); ?></small>
									<br><small>Ordered: <?php echo isset($p['created_at']) ? date('M d, Y', strtotime($p['created_at'])) : date('M d, Y', strtotime($p['date_paid'])); ?></small>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($p['transaction_type'] === 'purchase'): ?>
								<?php echo (int)($p['quantity'] ?? 1); ?>
							<?php else: ?>
								<?php echo (int)($p['quantity_paid'] ?? 1); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($p['transaction_type'] === 'borrow'): ?>
								<a href="borrow.php" class="borrow-link" title="View Borrow Details">
									#<?php echo h($p['primary_borrow_id'] ?? $p['borrow_id']); ?>
								</a>
							<?php else: ?>
								<a href="purchase_history.php" class="borrow-link" title="View Purchase Details">
									Purchase #<?php echo h($p['record_id']); ?>
								</a>
							<?php endif; ?>
						</td>
						<td><strong>₱<?php echo number_format((float)$p['amount'], 2); ?></strong></td>
						<td>
							<?php 
							$method = $p['payment_method'] ?? 'cash';
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
						</td>
						<td>
							<?php if (!empty($p['payment_reference'])): ?>
								<code style="background-color: #f5f5f5; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
									<?php echo h($p['payment_reference']); ?>
								</code>
							<?php else: ?>
								<span style="color: #999; font-size: 0.85rem;">-</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if (!empty($p['proof_image'])): ?>
								<a href="#" onclick="openImageModal('../<?php echo h($p['proof_image']); ?>'); return false;" title="Click to view full image">
									<img src="../<?php echo h($p['proof_image']); ?>" alt="Proof" class="proof-image">
								</a>
							<?php else: ?>
								<span style="color: #999; font-size: 0.85rem;">No proof</span>
							<?php endif; ?>
						</td>
						<td>
							<?php 
							$date_paid = $p['date_paid'];
							echo date('M d, Y', strtotime($date_paid));
							?>
							<br><small style="color: #666; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($date_paid)); ?></small>
						</td>
						<td>
							<?php 
							$status = $p['payment_status'] ?? 'confirmed';
							$statusClasses = [
								'confirmed' => 'status-confirmed',
								'pending' => 'status-pending',
								'rejected' => 'status-rejected'
							];
							$statusClass = $statusClasses[$status] ?? 'status-pending';
							?>
							<?php if ($status === 'confirmed'): ?>
								<span class="status-badge <?php echo $statusClass; ?>" style="font-weight: 700; font-size: 12px; padding: 5px 12px;">
									✓ SUCCESSFUL PAYMENT
								</span>
							<?php else: ?>
								<span class="status-badge <?php echo $statusClass; ?>">
									<?php echo ucfirst(h($status)); ?>
								</span>
								<?php if ($status === 'pending' && $current_role === 'borrower'): ?>
									<br><small style="color: #666; font-size: 0.75rem;">Awaiting confirmation</small>
								<?php elseif ($status === 'rejected' && $current_role === 'borrower'): ?>
									<br><small style="color: #d32f2f; font-size: 0.75rem;">Please contact admin</small>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php 
							$transaction_status = $p['borrow_status'] ?? 'unknown';
							$transaction_status_classes = [
								'reserved' => 'status-pending',
								'borrowed' => 'status-confirmed',
								'returned' => 'status-returned',
								'overdue' => 'status-rejected',
								'cancelled' => 'status-rejected',
								'pending' => 'status-pending',
								'confirmed' => 'status-confirmed',
								'completed' => 'status-returned'
							];
							$transaction_status_class = $transaction_status_classes[$transaction_status] ?? '';
							?>
							<span class="status-badge <?php echo $transaction_status_class; ?>" style="font-size: 0.75rem;">
								<?php echo h(ucfirst($transaction_status)); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
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

