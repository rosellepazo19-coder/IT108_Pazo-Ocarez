<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// Only borrowers can access payments
if (current_role() !== 'borrower') {
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

// Select borrow record - borrowers can only access their own records
$borrow_id = req_int('borrow_id');
$borrow = null;
$group_records = [];
$group_summary = null;
$current_user_id = current_user()['user_id'] ?? null;

if ($borrow_id && $current_user_id) {
	$stmt = $mysqli->prepare("SELECT b.*, CONCAT(u.Fname, ' ', u.Lname) AS user_name, 
		CASE WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
		WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id) END AS item_name,
		COALESCE(b.daily_rate, 
			CASE 
				WHEN b.item_type='equipment' THEN (SELECT e.daily_rate FROM equipment e WHERE e.equip_id=b.item_id)
				WHEN b.item_type='supplies' THEN (SELECT s.daily_rate FROM supplies s WHERE s.supply_id=b.item_id)
			END, 0.00
		) AS daily_rate,
		COALESCE(b.total_rental_amount, 0.00) AS total_rental_amount,
		COALESCE(b.late_fee_amount, 0.00) AS late_fee_amount,
		COALESCE(b.total_amount_due, 0.00) AS total_amount_due,
		CASE 
			WHEN b.item_type='equipment' THEN (SELECT e.late_fee_per_day FROM equipment e WHERE e.equip_id=b.item_id)
			WHEN b.item_type='supplies' THEN (SELECT s.late_fee_per_day FROM supplies s WHERE s.supply_id=b.item_id)
		END AS late_fee_per_day
	FROM borrow_records b 
	JOIN users u ON u.user_id=b.user_id 
	WHERE b.borrow_id=? AND b.user_id=? AND b.status IN ('borrowed', 'overdue', 'returned')");
	$stmt->bind_param('ii', $borrow_id, $current_user_id);
	$stmt->execute();
	$borrow = $stmt->get_result()->fetch_assoc();
	$stmt->close();
	
	// If borrow not found or doesn't belong to user, redirect
	if (!$borrow) {
		$_SESSION['flash'] = 'Borrow record not found or access denied.';
		header('Location: borrow.php');
		exit;
	}
	
	// Recalculate late fees for overdue items (if not returned yet)
	if (in_array($borrow['status'], ['borrowed', 'overdue']) && strtotime($borrow['due_date']) < time()) {
		$late_fee_per_day = (float)($borrow['late_fee_per_day'] ?? 0.00);
		if ($late_fee_per_day > 0) {
			$due_date = strtotime($borrow['due_date']);
			$current_date = time();
			$late_days = max(0, floor(($current_date - $due_date) / 86400)); // Days overdue
			$calculated_late_fee = round($late_fee_per_day * $late_days, 2);
			
			// Update late fee and total amount due if it changed
			if (abs($calculated_late_fee - (float)$borrow['late_fee_amount']) > 0.01) {
				$new_total_due = (float)$borrow['total_rental_amount'] + $calculated_late_fee;
				
				$update_stmt = $mysqli->prepare("UPDATE borrow_records SET late_fee_amount = ?, total_amount_due = ? WHERE borrow_id = ?");
				$update_stmt->bind_param('ddi', $calculated_late_fee, $new_total_due, $borrow_id);
				$update_stmt->execute();
				$update_stmt->close();
				
				// Update local borrow array
				$borrow['late_fee_amount'] = $calculated_late_fee;
				$borrow['total_amount_due'] = $new_total_due;
			}
		}
	}
	
	if ($borrow) {
		$group_stmt = $mysqli->prepare("
			SELECT 
				br.borrow_id,
				br.total_amount_due,
				br.total_rental_amount,
				br.late_fee_amount,
				br.status,
				COALESCE(SUM(CASE WHEN COALESCE(p.payment_status, 'confirmed') = 'confirmed' THEN p.amount ELSE 0 END), 0) AS confirmed_paid,
				COALESCE(SUM(CASE WHEN COALESCE(p.payment_status, 'confirmed') = 'pending' THEN p.amount ELSE 0 END), 0) AS pending_paid
			FROM borrow_records br
			LEFT JOIN payments p ON p.borrow_id = br.borrow_id
				AND (p.hidden_from_borrower = 0 OR p.hidden_from_borrower IS NULL)
			WHERE br.user_id = ?
			  AND br.item_id = ?
			  AND br.item_type = ?
			  AND br.due_date = ?
			  AND br.status IN ('borrowed','overdue','returned')
			GROUP BY br.borrow_id, br.total_amount_due, br.total_rental_amount, br.late_fee_amount, br.status
			ORDER BY br.borrow_id ASC
		");
		$group_stmt->bind_param('iiss', $borrow['user_id'], $borrow['item_id'], $borrow['item_type'], $borrow['due_date']);
		$group_stmt->execute();
		$group_result = $group_stmt->get_result();
		$group_records = $group_result->fetch_all(MYSQLI_ASSOC);
		$group_stmt->close();

		if (empty($group_records)) {
			$group_records = [[
				'borrow_id' => $borrow['borrow_id'],
				'total_amount_due' => $borrow['total_amount_due'],
				'total_rental_amount' => $borrow['total_rental_amount'],
				'late_fee_amount' => $borrow['late_fee_amount'],
				'status' => $borrow['status'],
				'confirmed_paid' => 0,
				'pending_paid' => 0,
			]];
		}

		$group_summary = [
			'quantity' => count($group_records),
			'total_amount_due' => 0.0,
			'total_rental_amount' => 0.0,
			'total_late_fees' => 0.0,
			'confirmed_paid' => 0.0,
			'pending_paid' => 0.0,
			'remaining_amount' => 0.0,
		];

		foreach ($group_records as &$gr) {
			$gr['total_amount_due'] = (float)($gr['total_amount_due'] ?? 0);
			$gr['total_rental_amount'] = (float)($gr['total_rental_amount'] ?? 0);
			$gr['late_fee_amount'] = (float)($gr['late_fee_amount'] ?? 0);
			$gr['confirmed_paid'] = (float)($gr['confirmed_paid'] ?? 0);
			$gr['pending_paid'] = (float)($gr['pending_paid'] ?? 0);
			$gr['remaining_due'] = max($gr['total_amount_due'] - $gr['confirmed_paid'], 0);

			$group_summary['total_amount_due'] += $gr['total_amount_due'];
			$group_summary['total_rental_amount'] += $gr['total_rental_amount'];
			$group_summary['total_late_fees'] += $gr['late_fee_amount'];
			$group_summary['confirmed_paid'] += $gr['confirmed_paid'];
			$group_summary['pending_paid'] += $gr['pending_paid'];
		}
		unset($gr);
		$group_summary['remaining_amount'] = max($group_summary['total_amount_due'] - $group_summary['confirmed_paid'], 0);
	}
}
$group_remaining_amount = $group_summary ? (float)$group_summary['remaining_amount'] : null;
$group_has_pending_payments = $group_summary ? ($group_summary['pending_paid'] > 0.0001) : false;
$is_overdue = false;
if ($borrow && in_array($borrow['status'], ['borrowed','overdue']) && !empty($borrow['due_date'])) {
	$is_overdue = (strtotime($borrow['due_date']) < time());
}

// Handle new payment - only for borrowers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		// Verify the borrow record belongs to the current borrower
		if ($borrow_id && $current_user_id) {
			$check_stmt = $mysqli->prepare("SELECT borrow_id FROM borrow_records WHERE borrow_id=? AND user_id=? AND status IN ('borrowed', 'overdue', 'returned')");
			$check_stmt->bind_param('ii', $borrow_id, $current_user_id);
			$check_stmt->execute();
			$check_result = $check_stmt->get_result();
			if ($check_result->num_rows === 0) {
				$errors[] = 'Access denied. You can only pay for your own approved borrows.';
			}
			$check_stmt->close();
		}
		$amount = (float)str_replace(',', '', req_str('amount'));
		$payment_method = req_str('payment_method');
		$payment_reference = trim(req_str('payment_reference'));
		
		if (!$borrow_id) $errors[] = 'Missing borrow ID.';
		if (!$borrow) $errors[] = 'Borrow record not found.';
		if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
		
		// Recalculate late fees before checking amount (for overdue items)
		if ($borrow && in_array($borrow['status'], ['borrowed', 'overdue']) && strtotime($borrow['due_date']) < time()) {
			$late_fee_per_day = 0.00;
			if ($borrow['item_type'] === 'equipment') {
				$fee_stmt = $mysqli->prepare("SELECT late_fee_per_day FROM equipment WHERE equip_id = ?");
				$fee_stmt->bind_param('i', $borrow['item_id']);
				$fee_stmt->execute();
				$fee_result = $fee_stmt->get_result();
				if ($fee_row = $fee_result->fetch_assoc()) {
					$late_fee_per_day = (float)$fee_row['late_fee_per_day'];
				}
				$fee_stmt->close();
			} else if ($borrow['item_type'] === 'supplies') {
				$fee_stmt = $mysqli->prepare("SELECT late_fee_per_day FROM supplies WHERE supply_id = ?");
				$fee_stmt->bind_param('i', $borrow['item_id']);
				$fee_stmt->execute();
				$fee_result = $fee_stmt->get_result();
				if ($fee_row = $fee_result->fetch_assoc()) {
					$late_fee_per_day = (float)$fee_row['late_fee_per_day'];
				}
				$fee_stmt->close();
			}
			
			if ($late_fee_per_day > 0) {
				$due_date = strtotime($borrow['due_date']);
				$current_date = time();
				$late_days = max(0, floor(($current_date - $due_date) / 86400));
				$calculated_late_fee = round($late_fee_per_day * $late_days, 2);
				$new_total_due = (float)$borrow['total_rental_amount'] + $calculated_late_fee;
				
				// Update borrow record with latest late fee
				$update_stmt = $mysqli->prepare("UPDATE borrow_records SET late_fee_amount = ?, total_amount_due = ? WHERE borrow_id = ?");
				$update_stmt->bind_param('ddi', $calculated_late_fee, $new_total_due, $borrow_id);
				$update_stmt->execute();
				$update_stmt->close();
				
				// Update local borrow array
				$borrow['late_fee_amount'] = $calculated_late_fee;
				$borrow['total_amount_due'] = $new_total_due;
			}
		}
		
		$max_payable_amount = $group_summary ? (float)$group_summary['remaining_amount'] : ($borrow['total_amount_due'] ?? 0);
		$max_payable_amount = max($max_payable_amount, 0);
		if ($max_payable_amount <= 0 && !$is_overdue) {
			$errors[] = 'This reservation is already fully paid.';
		} elseif ($amount > $max_payable_amount + 0.01) {
			$errors[] = 'Amount cannot exceed the total remaining balance (₱' . number_format($max_payable_amount, 2) . ').';
		}
		
		// Handle image upload for online payment proof
		$proof_image = null;
		$is_online_payment = in_array($payment_method, ['gcash', 'paymaya', 'bank_transfer', 'others']);
		
		if ($is_online_payment) {
			if (empty($payment_reference)) {
				$errors[] = 'Reference/Transaction number is required for online payment.';
			}
			
			if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
				$errors[] = 'Proof of payment image is required for online payment.';
			}
		}
		
		if ($is_online_payment && isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
			$upload_dir = '../uploads/payments/';
			$allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
			$max_size = 5 * 1024 * 1024; // 5MB
			
			$file_info = $_FILES['proof_image'];
			
			// Validate file type
			if (!in_array($file_info['type'], $allowed_types)) {
				$errors[] = 'Invalid file type. Only JPEG, PNG, and GIF images are allowed.';
			}
			// Validate file size
			elseif ($file_info['size'] > $max_size) {
				$errors[] = 'File too large. Maximum size is 5MB.';
			}
			// Validate file
			elseif (!getimagesize($file_info['tmp_name'])) {
				$errors[] = 'Invalid image file.';
			}
			else {
				// Create uploads/payments directory if it doesn't exist
				if (!is_dir($upload_dir)) {
					if (!mkdir($upload_dir, 0755, true)) {
						$errors[] = 'Failed to create upload directory.';
					}
				}
				
				if (empty($errors)) {
					// Generate unique filename
					$extension = pathinfo($file_info['name'], PATHINFO_EXTENSION);
					$filename = uniqid() . '_' . time() . '.' . $extension;
					$upload_path = $upload_dir . $filename;
					
					if (move_uploaded_file($file_info['tmp_name'], $upload_path)) {
						$proof_image = 'uploads/payments/' . $filename;
					} else {
						$errors[] = 'Failed to upload proof image. Please try again.';
					}
				}
			}
		}
		
		$payment_batch_id = uniqid('pb_');
		
		if (!$errors) {
			$distribution_records = !empty($group_records) ? $group_records : [[
				'borrow_id' => $borrow['borrow_id'],
				'remaining_due' => (float)$borrow['total_amount_due'],
				'confirmed_paid' => 0,
				'pending_paid' => 0,
			]];
			$remaining_payment = $amount;
			$created_payment_ids = [];
			
			foreach ($distribution_records as $record) {
				if ($remaining_payment <= 0) {
					break;
				}
				$record_remaining = max((float)($record['remaining_due'] ?? 0), 0);
				if ($record_remaining <= 0) {
					continue;
				}
				
				$allocation = min($record_remaining, $remaining_payment);
				if ($allocation <= 0) {
					continue;
				}
				
				$stmt = $mysqli->prepare("CALL sp_record_payment(?, ?)");
				$stmt->bind_param('id', $record['borrow_id'], $allocation);
				$ok = $stmt->execute();
				if (!$ok) {
					$errors[] = 'DB Error: ' . h($stmt->error);
					$stmt->close();
					break;
				}
				while ($stmt->more_results() && $stmt->next_result()) { }
				$stmt->close();
				
				$id_stmt = $mysqli->prepare("SELECT payment_id FROM payments WHERE borrow_id = ? ORDER BY payment_id DESC LIMIT 1");
				$id_stmt->bind_param('i', $record['borrow_id']);
				$id_stmt->execute();
				$id_row = $id_stmt->get_result()->fetch_assoc();
				$id_stmt->close();
				
				if (!$id_row) {
					$errors[] = 'Failed to record payment details. Please contact support.';
					break;
				}
				
				$created_payment_ids[] = (int)$id_row['payment_id'];
				$remaining_payment -= $allocation;
			}
			
			if ($remaining_payment > 0.01 && !$errors) {
				$errors[] = 'Unable to allocate the full amount across your borrowed items. Please try a smaller amount.';
			}
			
			if (!$errors && !empty($created_payment_ids)) {
				$payment_status = 'pending';
				foreach ($created_payment_ids as $pid) {
					$update_stmt = $mysqli->prepare("UPDATE payments SET payment_method = ?, payment_reference = ?, proof_image = ?, payment_status = ?, payment_batch_id = ? WHERE payment_id = ?");
					$update_stmt->bind_param('sssssi', $payment_method, $payment_reference, $proof_image, $payment_status, $payment_batch_id, $pid);
					$update_stmt->execute();
					$update_stmt->close();
				}
				
				if ($payment_method === 'cash') {
					$_SESSION['flash'] = 'Cash payment recorded. Please proceed to the office to complete your payment. Each borrowed item will be updated after admin confirmation.';
				} else {
					$_SESSION['flash'] = 'Online payment submitted successfully! Each borrowed item has been tagged with your proof. Please wait for admin confirmation.';
				}
				
				header('Location: payments.php?borrow_id=' . (int)$borrow_id);
				exit;
			}
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

// Load payments list
$payments = [];
$total_confirmed_paid = 0.00;
$has_pending_payments = false;
if ($borrow_id) {
	$stmt = $mysqli->prepare("SELECT * FROM payments WHERE borrow_id=? ORDER BY date_paid DESC");
	$stmt->bind_param('i', $borrow_id);
	$stmt->execute();
	$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
	$stmt->close();
	
	// Calculate total confirmed payments and check for pending payments
	foreach ($payments as $p) {
		$status = $p['payment_status'] ?? 'confirmed';
		if ($status === 'confirmed') {
			$total_confirmed_paid += (float)$p['amount'];
		} elseif ($status === 'pending') {
			$has_pending_payments = true;
		}
	}
}

// Recalculate late fees again after loading payments (in case item became overdue while viewing)
if ($borrow && in_array($borrow['status'], ['borrowed', 'overdue']) && strtotime($borrow['due_date']) < time()) {
	$late_fee_per_day = 0.00;
	if ($borrow['item_type'] === 'equipment') {
		$fee_stmt = $mysqli->prepare("SELECT late_fee_per_day FROM equipment WHERE equip_id = ?");
		$fee_stmt->bind_param('i', $borrow['item_id']);
		$fee_stmt->execute();
		$fee_result = $fee_stmt->get_result();
		if ($fee_row = $fee_result->fetch_assoc()) {
			$late_fee_per_day = (float)$fee_row['late_fee_per_day'];
		}
		$fee_stmt->close();
	} else if ($borrow['item_type'] === 'supplies') {
		$fee_stmt = $mysqli->prepare("SELECT late_fee_per_day FROM supplies WHERE supply_id = ?");
		$fee_stmt->bind_param('i', $borrow['item_id']);
		$fee_stmt->execute();
		$fee_result = $fee_stmt->get_result();
		if ($fee_row = $fee_result->fetch_assoc()) {
			$late_fee_per_day = (float)$fee_row['late_fee_per_day'];
		}
		$fee_stmt->close();
	}
	
	if ($late_fee_per_day > 0) {
		$due_date = strtotime($borrow['due_date']);
		$current_date = time();
		$late_days = max(0, floor(($current_date - $due_date) / 86400));
		$calculated_late_fee = round($late_fee_per_day * $late_days, 2);
		$new_total_due = (float)$borrow['total_rental_amount'] + $calculated_late_fee;
		
		// Update if late fee changed
		if (abs($calculated_late_fee - (float)$borrow['late_fee_amount']) > 0.01) {
			$update_stmt = $mysqli->prepare("UPDATE borrow_records SET late_fee_amount = ?, total_amount_due = ? WHERE borrow_id = ?");
			$update_stmt->bind_param('ddi', $calculated_late_fee, $new_total_due, $borrow_id);
			$update_stmt->execute();
			$update_stmt->close();
			
			// Update local borrow array
			$borrow['late_fee_amount'] = $calculated_late_fee;
			$borrow['total_amount_due'] = $new_total_due;
		}
	}
}

// Check if payment is fully paid and confirmed
$is_fully_paid = false;
if ($group_summary) {
	$is_fully_paid = ($group_summary['remaining_amount'] <= 0.01);
} elseif ($borrow && $borrow['total_amount_due'] > 0) {
	$is_fully_paid = ($total_confirmed_paid >= (float)$borrow['total_amount_due']);
}
// If overdue, allow payments even if previously fully paid (due to late fees)
if ($is_overdue) {
	$is_fully_paid = false;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Payments - CBR Agricultural System</title>
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
		
		.grid {
			display: grid;
			grid-template-columns: 1fr;
			gap: 1rem;
		}
		
		label {
			display: block;
			margin-top: 0.75rem;
			margin-bottom: 0.35rem;
			font-weight: 600;
			color: var(--text-dark);
			font-size: 0.9rem;
		}
		
		.form-actions {
			display: flex;
			gap: 0.5rem;
			margin-top: 1rem;
			flex-wrap: wrap;
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
		}
		
		.payment-method-select {
			padding: 0.75rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			font-size: 14px;
			background: #f9f9f9;
			transition: border-color 0.3s ease;
		}
		
		.payment-method-select:focus {
			outline: none;
			border-color: var(--primary);
			background: #fff;
		}
		
		.payment-info {
			background-color: #e8f1fd;
			padding: 15px;
			border-radius: 10px;
			margin-bottom: 20px;
			border-left: 4px solid var(--primary);
		}
		.payment-info p {
			margin: 5px 0;
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
			background-color: #4caf50;
			color: white;
		}
		.status-pending {
			background-color: #ff9800;
			color: white;
		}
		.status-rejected {
			background-color: #f44336;
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
		.proof-image {
			width: 50px;
			height: 50px;
			object-fit: cover;
			border-radius: 4px;
			cursor: pointer;
			border: 1px solid #ddd;
		}
		
		.btn.primary {
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			padding: 0.75rem 1.5rem;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.3s ease;
			font-weight: 500;
		}
		
		.btn.primary:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
		}
		
		input[type="text"], input[type="number"], input[type="file"], textarea {
			padding: 0.75rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			background: #f9f9f9;
			transition: border-color 0.3s ease;
		}
		
		input[type="text"]:focus, input[type="number"]:focus, textarea:focus {
			outline: none;
			border-color: var(--primary);
			background: #fff;
		}
		
		.table {
			background: white;
			border-radius: 10px;
			overflow: hidden;
		}
		
		.table th {
			background: var(--primary);
			color: var(--text-light);
			padding: 1rem;
			font-weight: 600;
		}
		
		.table td {
			padding: 1rem;
			border-bottom: 1px solid #f0f0f0;
		}
		
		.table tr:hover {
			background: #f9f9f9;
		}
		
		.alert {
			border-radius: 10px;
			padding: 1rem;
			margin-bottom: 1.5rem;
		}
		.proof-image:hover {
			opacity: 0.8;
		}
		input[type="file"] {
			padding: 5px;
		}
	</style>
</head>
<body>
		<div class="page-header">
		<div class="container">
			<h1>My Payments</h1>
			<a href="borrow.php" class="btn">← Back to My Borrows</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div><?php endif; ?>

		<div class="card">
			<h2>Borrow Details</h2>
			<?php if ($borrow): ?>
				<p><strong>#:</strong> <?php echo h($borrow['borrow_id']); ?></p>
				<p><strong>Borrower:</strong> <?php echo h($borrow['user_name']); ?></p>
				<p><strong>Item:</strong> <?php echo h('['.($borrow['item_type']==='equipment'?'EQ':'SU').'] '.$borrow['item_name']); ?></p>
				<p><strong>Daily Rate:</strong> ₱<?php echo number_format($borrow['daily_rate'], 2); ?></p>
				<p><strong>Status:</strong> <?php echo h($borrow['status']); ?></p>
				<p><strong>Total Amount Due:</strong> ₱<?php echo number_format($borrow['total_amount_due'], 2); ?></p>
				<?php if ($borrow['late_fee_amount'] > 0): ?>
					<p><strong>Late Fee:</strong> ₱<?php echo number_format($borrow['late_fee_amount'], 2); ?></p>
				<?php endif; ?>
				<?php if ($group_summary): ?>
					<div style="background: #e8f5e9; padding: 1rem; border-radius: 8px; border-left: 4px solid var(--primary); margin-top: 1rem;">
						<p style="margin: 0 0 0.35rem 0; font-weight: 600;">Reservation Summary</p>
						<p style="margin: 0; font-size: 0.9rem;">
							<strong>Items Borrowed:</strong> <?php echo $group_summary['quantity']; ?><br>
							<strong>Total Rental Amount:</strong> ₱<?php echo number_format($group_summary['total_rental_amount'], 2); ?><br>
							<?php if ($group_summary['total_late_fees'] > 0): ?>
								<strong>Late Fees (current):</strong> ₱<?php echo number_format($group_summary['total_late_fees'], 2); ?><br>
							<?php endif; ?>
							<strong>Total Amount Due (All Items):</strong> ₱<?php echo number_format($group_summary['total_amount_due'], 2); ?><br>
							<strong>Confirmed Payments:</strong> ₱<?php echo number_format($group_summary['confirmed_paid'], 2); ?><br>
							<?php if ($group_summary['pending_paid'] > 0): ?>
								<strong>Pending Payments:</strong> ₱<?php echo number_format($group_summary['pending_paid'], 2); ?><br>
							<?php endif; ?>
							<strong>Remaining Balance:</strong> ₱<?php echo number_format($group_summary['remaining_amount'], 2); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<p>Select a borrow record from Reports or Monitoring to manage payments.</p>
			<?php endif; ?>
		</div>

		<?php if ($borrow && in_array($borrow['status'], ['borrowed', 'overdue', 'returned']) && !$is_fully_paid): ?>
		<?php 
		// Allow payment if overdue, otherwise disable if there are pending payments across the group
		$can_make_payment = !$group_has_pending_payments || $is_overdue;
		?>
		<div class="card">
			<h2>Add Payment</h2>
			<?php if ($group_has_pending_payments && !$is_overdue): ?>
				<div style="background: #fff3cd; color: #856404; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #ff9800; text-align: center;">
					<div style="font-size: 2.5rem; margin-bottom: 0.5rem;">⏳</div>
					<h3 style="color: #856404; margin: 0 0 0.5rem 0;">Payment Pending</h3>
					<p style="margin: 0; color: #856404; font-size: 0.95rem;">
						You have a pending payment awaiting admin confirmation for this reservation. Please wait for confirmation before making additional payments.
					</p>
					<?php if ($has_pending_payments):
						$pending_payment = null;
						foreach ($payments as $p) {
							$status = $p['payment_status'] ?? 'confirmed';
							if ($status === 'pending') {
								$pending_payment = $p;
								break;
							}
						}
						if ($pending_payment):
					?>
							<div style="background: white; padding: 0.75rem; border-radius: 6px; margin-top: 1rem; text-align: left; display: inline-block;">
								<p style="margin: 0 0 0.5rem 0; font-weight: 600; color: #856404;">Pending Payment Details:</p>
								<p style="margin: 0; font-size: 0.9rem;">
									<strong>Amount:</strong> ₱<?php echo number_format((float)$pending_payment['amount'], 2); ?><br>
									<strong>Method:</strong> <?php 
										$method = $pending_payment['payment_method'] ?? 'cash';
										$methodNames = ['cash' => 'Cash', 'gcash' => 'GCash', 'paymaya' => 'PayMaya', 'bank_transfer' => 'Bank Transfer', 'others' => 'Other'];
										echo h($methodNames[$method] ?? ucfirst($method));
									?><br>
									<strong>Date Submitted:</strong> <?php echo date('M d, Y h:i A', strtotime($pending_payment['date_paid'])); ?>
								</p>
							</div>
					<?php 
						endif;
					endif; ?>
				</div>
			<?php elseif ($group_has_pending_payments && $is_overdue): ?>
				<div style="background: #f8d7da; color: #721c24; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #dc3545;">
					<strong>⚠️ Overdue Item:</strong> Your item is overdue. You can make additional payments even though you have pending payment(s).
				</div>
			<?php endif; ?>
			<form method="post" id="paymentForm" enctype="multipart/form-data" <?php if (!$can_make_payment): ?>onsubmit="return false;"<?php endif; ?>>
				<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
				<input type="hidden" name="borrow_id" value="<?php echo (int)$borrow_id; ?>">
				
				<label>Payment Method</label>
				<select name="payment_method" id="payment_method" class="payment-method-select" required <?php if (!$can_make_payment): ?>disabled<?php endif; ?>>
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
				
				<!-- Cash Payment Fields -->
				<div id="cash_payment_fields" style="display: none;">
					<div class="payment-instructions" style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #ff9800;">
						<strong style="color: #856404;">💵 Cash Payment Instructions:</strong>
						<ol style="margin: 0.5rem 0; padding-left: 1.5rem; color: #856404; font-size: 0.9rem;">
							<li>Proceed to the office/site to complete your cash payment</li>
							<li>Bring the exact amount: <strong>₱<span id="cash_amount_display">0.00</span></strong></li>
							<li>Your payment will be confirmed once the admin receives your payment</li>
							<li>You will be notified once your payment is confirmed</li>
						</ol>
					</div>
				</div>
				
				<!-- Online Payment Fields -->
				<div id="online_payment_fields" style="display: none;">
					<div class="payment-instructions" id="payment_instructions" style="background: #e8f5e9; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid var(--primary);">
						<h4 style="margin: 0 0 0.5rem 0; color: var(--primary); font-size: 0.95rem;">Payment Instructions:</h4>
						<div id="instruction_content" style="font-size: 0.85rem; color: #555; line-height: 1.6;"></div>
					</div>
					
					<label>Reference/Transaction Number <span style="color: #e74c3c;">*</span></label>
					<input type="text" name="payment_reference" id="payment_reference" placeholder="Enter transaction/reference number" style="width: 100%;" <?php if (!$can_make_payment): ?>disabled<?php endif; ?>>
					<small style="color: #666; display: block; margin-top: 5px; font-size: 0.85rem;">Enter the transaction number from your payment receipt</small>
					
					<label>Upload Proof of Payment (Screenshot/Photo) <span style="color: #e74c3c;">*</span></label>
					<input type="file" name="proof_image" id="proof_image" accept="image/*" style="width: 100%; padding: 0.5rem;" <?php if (!$can_make_payment): ?>disabled<?php endif; ?>>
					<small style="color: #666; display: block; margin-top: 5px; font-size: 0.85rem;">
						Upload a clear screenshot or photo of your payment transaction. Maximum file size: 5MB. Accepted formats: JPG, PNG, GIF
					</small>
					<div id="image_preview" style="margin-top: 1rem; display: none;">
						<img id="preview_img" src="" alt="Proof Preview" style="max-width: 300px; max-height: 200px; border: 2px solid #ddd; border-radius: 8px; padding: 0.5rem;">
					</div>
				</div>
				
				<label>Amount to Pay <span style="color: #e74c3c;">*</span></label>
				<?php 
				$remaining_amount = $group_summary ? (float)$group_summary['remaining_amount'] : ($borrow ? ((float)$borrow['total_amount_due'] - $total_confirmed_paid) : 0);
				$max_amount = max(0, $remaining_amount);
				?>
				<input type="number" name="amount" id="payment_amount" step="0.01" min="0" max="<?php echo number_format($max_amount, 2, '.', ''); ?>" value="<?php echo number_format($max_amount, 2, '.', ''); ?>" required style="width: 100%; font-size: 1.1rem; font-weight: 600;" <?php if (!$can_make_payment): ?>disabled<?php endif; ?>>
				<small style="color: #666; display: block; margin-top: 5px; font-size: 0.85rem;">
					<?php if ($borrow): ?>
						<div style="background: <?php echo $is_overdue ? '#fff3cd' : '#e8f5e9'; ?>; padding: 1rem; border-radius: 8px; border-left: 4px solid <?php echo $is_overdue ? '#ff9800' : '#4caf50'; ?>; margin-top: 0.5rem;">
							<div style="font-size: 0.9rem;">
								<?php if ($is_overdue): ?>
									<p style="margin: 0 0 0.5rem 0; font-weight: 600; color: #856404;">
										⚠️ Overdue - settle the outstanding balance for all borrowed units.
									</p>
								<?php endif; ?>
								<?php if ($group_summary): ?>
									<div style="margin-bottom: 0.25rem;">
										<span style="color: #666;">Rental Amount (All Items):</span> 
										<strong>₱<?php echo number_format($group_summary['total_rental_amount'], 2); ?></strong>
									</div>
									<?php if ($group_summary['total_late_fees'] > 0): ?>
										<div style="margin-bottom: 0.25rem;">
											<span style="color: #666;">Late Fees (All Items):</span> 
											<strong style="color: #dc3545;">₱<?php echo number_format($group_summary['total_late_fees'], 2); ?></strong>
										</div>
									<?php endif; ?>
									<div style="border-top: 1px solid #ddd; margin-top: 0.5rem; padding-top: 0.5rem; font-weight: 600;">
										<span style="color: #333;">Total Amount Due (All Items):</span> 
										<strong style="font-size: 1.1rem; color: #333;">₱<?php echo number_format($group_summary['total_amount_due'], 2); ?></strong>
									</div>
									<div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #ddd;">
										<span style="color: #666;">Confirmed Payments:</span> 
										<strong style="color: #4caf50;">₱<?php echo number_format($group_summary['confirmed_paid'], 2); ?></strong><br>
										<?php if ($group_summary['pending_paid'] > 0): ?>
											<span style="color: #666;">Pending Confirmation:</span> 
											<strong style="color: #ff9800;">₱<?php echo number_format($group_summary['pending_paid'], 2); ?></strong><br>
										<?php endif; ?>
										<span style="color: #666;">Remaining to Pay:</span> 
										<strong style="color: <?php echo $remaining_amount > 0 ? '#f44336' : '#4caf50'; ?>; font-size: 1.05rem;">₱<?php echo number_format($remaining_amount, 2); ?></strong>
									</div>
								<?php else: ?>
									<?php if ($borrow['total_rental_amount'] > 0): ?>
										<div style="margin-bottom: 0.25rem;">
											<span style="color: #666;">Rental Amount:</span> 
											<strong>₱<?php echo number_format($borrow['total_rental_amount'], 2); ?></strong>
										</div>
									<?php endif; ?>
									<?php if ($borrow['late_fee_amount'] > 0): ?>
										<div style="margin-bottom: 0.25rem;">
											<span style="color: #666;">Late Fee:</span> 
											<strong style="color: #dc3545;">₱<?php echo number_format($borrow['late_fee_amount'], 2); ?></strong>
										</div>
									<?php endif; ?>
									<div style="border-top: 1px solid #ddd; margin-top: 0.5rem; padding-top: 0.5rem; font-weight: 600;">
										<span style="color: #333;">Total Amount Due:</span> 
										<strong style="font-size: 1.1rem; color: #333;">₱<?php echo number_format($borrow['total_amount_due'], 2); ?></strong>
									</div>
									<div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #ddd;">
										<span style="color: #666;">Confirmed Payments:</span> 
										<strong style="color: #4caf50;">₱<?php echo number_format($total_confirmed_paid, 2); ?></strong><br>
										<span style="color: #666;">Remaining to Pay:</span> 
										<strong style="color: <?php echo $remaining_amount > 0 ? '#f44336' : '#4caf50'; ?>; font-size: 1.05rem;">₱<?php echo number_format($remaining_amount, 2); ?></strong>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
				</small>
				
				<div class="form-actions">
					<?php if ($can_make_payment): ?>
						<button class="btn primary" type="submit" name="record_payment">Record Payment</button>
					<?php else: ?>
						<button class="btn primary" type="button" disabled style="opacity: 0.6; cursor: not-allowed;">Record Payment (Disabled)</button>
					<?php endif; ?>
					<a class="btn" href="borrow.php">Back to My Borrows</a>
				</div>
			</form>
		</div>
		<?php elseif ($borrow && $is_fully_paid): ?>
		<div class="card">
			<div style="background: #d4edda; color: #155724; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #28a745; text-align: center;">
				<h3 style="margin: 0 0 0.5rem 0; color: #155724;">✅ Payment Fully Confirmed</h3>
				<p style="margin: 0; font-size: 1rem;">
					Total Amount Due: <strong>₱<?php echo number_format($borrow['total_amount_due'], 2); ?></strong><br>
					Total Confirmed Payments: <strong>₱<?php echo number_format($total_confirmed_paid, 2); ?></strong>
				</p>
				<p style="margin: 0.75rem 0 0 0; font-size: 0.9rem; color: #666;">
					All payments have been confirmed. No additional payment is needed at this time.
				</p>
			</div>
		</div>
		<?php else: ?>
		<div class="card">
			<p>Please select a borrow record to make a payment. Go to <a href="borrow.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">My Borrows</a> and click "Pay Now" on an approved item.</p>
		</div>
<?php endif; ?>
	</div>
	<?php 
		$instruction_amount_display = $group_summary ? number_format($group_summary['remaining_amount'], 2) : ($borrow ? number_format($borrow['total_amount_due'], 2) : '0.00');
	?>
	<script>
		// Payment method instructions
		const paymentInstructions = {
			'gcash': {
				title: 'GCash Payment Instructions',
				steps: [
					'Open your GCash app',
					'Go to "Send Money" or "Pay Bills"',
					'Enter the amount: ₱<?php echo $instruction_amount_display; ?>',
					'Enter recipient: [Admin Contact Number - to be configured]',
					'Complete the transaction',
					'Take a screenshot of the transaction confirmation',
					'Enter the Reference Number and upload the screenshot below'
				],
				note: 'Please ensure you enter the correct amount and recipient. Double-check before confirming the transaction.'
			},
			'paymaya': {
				title: 'PayMaya Payment Instructions',
				steps: [
					'Open your PayMaya app',
					'Go to "Send Money" or "Pay Bills"',
					'Enter the amount: ₱<?php echo $instruction_amount_display; ?>',
					'Enter recipient: [Admin Contact Number - to be configured]',
					'Complete the transaction',
					'Take a screenshot of the transaction confirmation',
					'Enter the Reference Number and upload the screenshot below'
				],
				note: 'Please ensure you enter the correct amount and recipient. Double-check before confirming the transaction.'
			},
			'bank_transfer': {
				title: 'Bank Transfer Instructions',
				steps: [
					'Log in to your bank\'s mobile app or online banking',
					'Go to "Transfer" or "Send Money"',
					'Enter recipient bank account: [Bank Details - to be configured]',
					'Enter the amount: ₱<?php echo $instruction_amount_display; ?>',
					'Complete the transfer',
					'Take a screenshot of the transaction confirmation',
					'Enter the Reference Number and upload the screenshot below'
				],
				note: 'Please include your name in the transaction remarks for easier tracking.'
			},
			'others': {
				title: 'Other Online Payment Instructions',
				steps: [
					'Complete your payment using your preferred online payment method',
					'Take a screenshot of the transaction confirmation',
					'Enter the Reference/Transaction Number below',
					'Upload the screenshot as proof of payment'
				],
				note: 'Please ensure the screenshot clearly shows the transaction details, amount, and reference number.'
			}
		};
		
		// Toggle online payment fields and show instructions
		document.getElementById('payment_method').addEventListener('change', function() {
			const onlineFields = document.getElementById('online_payment_fields');
			const cashFields = document.getElementById('cash_payment_fields');
			const instructionContent = document.getElementById('instruction_content');
			const referenceInput = document.getElementById('payment_reference');
			const proofInput = document.getElementById('proof_image');
			const imagePreview = document.getElementById('image_preview');
			const cashAmountDisplay = document.getElementById('cash_amount_display');
			const paymentAmountInput = document.getElementById('payment_amount');
			const selectedOption = this.options[this.selectedIndex];
			const paymentMethod = this.value;
			const isOnline = selectedOption.getAttribute('data-is-online') === '1';
			const accountNumber = selectedOption.getAttribute('data-account-number') || '';
			const accountName = selectedOption.getAttribute('data-account-name') || '';
			const customInstructions = selectedOption.getAttribute('data-instructions') || '';
			
			// Hide both fields first
			onlineFields.style.display = 'none';
			cashFields.style.display = 'none';
			referenceInput.removeAttribute('required');
			proofInput.removeAttribute('required');
			
			if (paymentMethod === 'cash') {
				// Show cash payment instructions
				cashFields.style.display = 'block';
				// Update cash amount display
				if (cashAmountDisplay && paymentAmountInput) {
					cashAmountDisplay.textContent = parseFloat(paymentAmountInput.value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
				}
			} else if (isOnline) {
				onlineFields.style.display = 'block';
				referenceInput.setAttribute('required', 'required');
				proofInput.setAttribute('required', 'required');
				
				// Build instructions HTML
				let html = '<strong>Payment Instructions:</strong><ol style="margin: 0.5rem 0; padding-left: 1.5rem;">';
				
				// Add account details if available
				if (accountNumber) {
					html += '<li style="margin: 0.25rem 0; font-weight: 600; color: var(--primary);">Send payment to: <strong>' + accountNumber;
					if (accountName) {
						html += ' (' + accountName + ')';
					}
					html += '</strong></li>';
				}
				
				// Use custom instructions if available, otherwise use default
				if (customInstructions) {
					// Split custom instructions by newlines and add as steps
					const customSteps = customInstructions.split('\n').filter(step => step.trim() !== '');
					customSteps.forEach(step => {
						html += '<li style="margin: 0.25rem 0;">' + step.trim() + '</li>';
					});
				} else {
					// Use default instructions
					if (paymentInstructions[paymentMethod]) {
						const instructions = paymentInstructions[paymentMethod];
						instructions.steps.forEach(step => {
							html += '<li style="margin: 0.25rem 0;">' + step + '</li>';
						});
					} else {
						html += '<li>Complete your payment using the selected method</li>';
						html += '<li>Take a screenshot of the transaction confirmation</li>';
						html += '<li>Enter the Reference/Transaction Number below</li>';
						html += '<li>Upload the screenshot as proof of payment</li>';
					}
				}
				
				html += '</ol>';
				
				// Add note if available
				if (paymentInstructions[paymentMethod] && paymentInstructions[paymentMethod].note) {
					html += '<p style="margin: 0.5rem 0 0 0; color: #d32f2f; font-weight: 600;"><strong>Note:</strong> ' + paymentInstructions[paymentMethod].note + '</p>';
				}
				
				instructionContent.innerHTML = html;
			}
			
			// Update cash amount when payment amount changes
			if (paymentMethod === 'cash' && paymentAmountInput && cashAmountDisplay) {
				const updateCashAmount = function() {
					cashAmountDisplay.textContent = parseFloat(paymentAmountInput.value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
				};
				updateCashAmount();
				paymentAmountInput.addEventListener('input', updateCashAmount);
			}
		});
		
		// Image preview
		document.getElementById('proof_image').addEventListener('change', function(e) {
			const file = e.target.files[0];
			const preview = document.getElementById('image_preview');
			const previewImg = document.getElementById('preview_img');
			
			if (file) {
				const reader = new FileReader();
				reader.onload = function(e) {
					previewImg.src = e.target.result;
					preview.style.display = 'block';
				};
				reader.readAsDataURL(file);
			} else {
				preview.style.display = 'none';
			}
		});
		
		// Validate amount
		document.getElementById('payment_amount').addEventListener('input', function() {
			const maxAmount = parseFloat(this.getAttribute('max'));
			const currentAmount = parseFloat(this.value);
			
			if (currentAmount > maxAmount) {
				this.setCustomValidity('Amount cannot exceed ₱' + maxAmount.toFixed(2));
			} else {
				this.setCustomValidity('');
			}
		});
	</script>
</body>
</html>


