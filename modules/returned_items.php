<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle delete (soft delete - hide from current role's view)
$current_role = current_role();
$current_user_id = current_user()['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_returned'])) {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$borrow_id = req_int('borrow_id');
		if (!$borrow_id) {
			$errors[] = 'Invalid borrow ID.';
		} else {
			// Verify ownership and check if returned item can be deleted
			$can_delete = true;
			$delete_reason = '';
			
			if ($current_role === 'borrower') {
				$check_stmt = $mysqli->prepare("
					SELECT b.borrow_id, b.user_id, b.status
					FROM borrow_records b 
					WHERE b.borrow_id = ? AND b.user_id = ? AND b.status = 'returned'
				");
				$check_stmt->bind_param('ii', $borrow_id, $current_user_id);
				$check_stmt->execute();
				$check_result = $check_stmt->get_result();
				if ($check_result->num_rows === 0) {
					$errors[] = 'Returned item not found or access denied.';
					$can_delete = false;
				} else {
					// Allow deletion - soft delete only (hides from view, doesn't remove from database)
					// No restrictions for borrowers on their own returned items
				}
				$check_stmt->close();
			} else {
				// Admin/Staff: Check if returned item can be deleted
				$check_stmt = $mysqli->prepare("
					SELECT b.borrow_id, b.status
					FROM borrow_records b 
					WHERE b.borrow_id = ? AND b.status = 'returned'
				");
				$check_stmt->bind_param('i', $borrow_id);
				$check_stmt->execute();
				$check_result = $check_stmt->get_result();
				if ($check_result->num_rows === 0) {
					$errors[] = 'Returned item not found.';
					$can_delete = false;
				} else {
					// Allow deletion - soft delete only (hides from view, doesn't remove from database)
					// Admin/Staff can delete any returned items
				}
				$check_stmt->close();
			}
			
			if (!$errors && $can_delete) {
				// Soft delete: hide from current role's view
				if ($current_role === 'borrower') {
					$stmt = $mysqli->prepare("UPDATE borrow_records SET hidden_from_borrower = 1 WHERE borrow_id = ?");
				} else {
					// Admin/Staff hide from their view
					$stmt = $mysqli->prepare("UPDATE borrow_records SET hidden_from_admin = 1 WHERE borrow_id = ?");
				}
				$stmt->bind_param('i', $borrow_id);
				if ($stmt->execute()) {
					$_SESSION['flash'] = 'Returned item deleted from your view.';
					header('Location: returned_items.php');
					exit;
				} else {
					$errors[] = 'Failed to delete returned item.';
				}
				$stmt->close();
			}
		}
	}
}

// List returned items - show to all users
$returned = [];
if (current_role() === 'borrower') {
    // Borrowers can only see their own returned items
    $current_user_id = current_user()['user_id'] ?? null;
    if ($current_user_id) {
        $stmt = $mysqli->prepare("
            SELECT 
                b.borrow_id,
                b.user_id,
                CONCAT(u.Fname, ' ', u.Lname) AS user_name,
                b.item_type,
                b.item_id,
                CASE
                    WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
                    WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
                END AS item_name,
                b.date_borrowed,
                b.due_date,
                b.return_date,
                b.status,
                COALESCE(b.daily_rate, 
                    CASE 
                        WHEN b.item_type='equipment' THEN (SELECT e.daily_rate FROM equipment e WHERE e.equip_id=b.item_id)
                        WHEN b.item_type='supplies' THEN (SELECT s.daily_rate FROM supplies s WHERE s.supply_id=b.item_id)
                    END, 0.00
                ) AS daily_rate,
                COALESCE(b.total_amount_due,
                    CASE
                        WHEN b.return_date IS NOT NULL AND b.return_date > b.due_date THEN 
                            TIMESTAMPDIFF(DAY, b.due_date, b.return_date) * COALESCE(b.daily_rate, 
                                CASE 
                                    WHEN b.item_type='equipment' THEN (SELECT e.daily_rate FROM equipment e WHERE e.equip_id=b.item_id)
                                    WHEN b.item_type='supplies' THEN (SELECT s.daily_rate FROM supplies s WHERE s.supply_id=b.item_id)
                                END, 0.00
                            )
                        ELSE 0
                    END, 0.00
                ) AS total_amount_due
            FROM borrow_records b
            JOIN users u ON u.user_id = b.user_id
            WHERE b.user_id = ? AND b.status = 'returned' AND b.hidden_from_borrower = 0
            ORDER BY b.return_date DESC
        ");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $returned = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    // Admin/Staff can see all returned items
    $resR = $mysqli->query("
        SELECT 
            b.borrow_id,
            b.user_id,
            CONCAT(u.Fname, ' ', u.Lname) AS user_name,
            b.item_type,
            b.item_id,
            CASE
                WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
                WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
            END AS item_name,
            b.date_borrowed,
            b.due_date,
            b.return_date,
            b.status,
            COALESCE(b.daily_rate, 
                CASE 
                    WHEN b.item_type='equipment' THEN (SELECT e.daily_rate FROM equipment e WHERE e.equip_id=b.item_id)
                    WHEN b.item_type='supplies' THEN (SELECT s.daily_rate FROM supplies s WHERE s.supply_id=b.item_id)
                END, 0.00
            ) AS daily_rate,
            COALESCE(b.total_amount_due,
                CASE
                    WHEN b.return_date IS NOT NULL AND b.return_date > b.due_date THEN 
                        TIMESTAMPDIFF(DAY, b.due_date, b.return_date) * COALESCE(b.daily_rate, 
                            CASE 
                                WHEN b.item_type='equipment' THEN (SELECT e.daily_rate FROM equipment e WHERE e.equip_id=b.item_id)
                                WHEN b.item_type='supplies' THEN (SELECT s.daily_rate FROM supplies s WHERE s.supply_id=b.item_id)
                            END, 0.00
                        )
                    ELSE 0
                END, 0.00
            ) AS total_amount_due
        FROM borrow_records b
        JOIN users u ON u.user_id = b.user_id
        WHERE b.status = 'returned' AND b.hidden_from_admin = 0
        ORDER BY b.return_date DESC
    ");
    if ($resR) { $returned = $resR->fetch_all(MYSQLI_ASSOC); $resR->free(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo current_role() === 'borrower' ? 'My Returned Items' : 'Returned Items'; ?></title>
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
		
		.status-returned {
			display: inline-block;
			padding: 0.2rem 0.6rem;
			border-radius: 12px;
			font-size: 0.75rem;
			font-weight: 600;
			background: #d1ecf1;
			color: #0c5460;
		}
		
		.alert {
			border-radius: 8px;
			padding: 0.75rem;
			margin-bottom: 1rem;
			font-size: 0.9rem;
		}
		
		.alert.success {
			background: #d4edda;
			color: #155724;
			border: 1px solid #c3e6cb;
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
		
		.btn.small.danger {
			background-color: #f44336;
			color: white;
			border: none;
			padding: 0.4rem 0.8rem;
			border-radius: 6px;
			cursor: pointer;
			font-size: 0.8rem;
			transition: all 0.3s ease;
		}
		
		.btn.small.danger:hover {
			background-color: #d32f2f;
			transform: translateY(-1px);
		}
		
		.inline {
			display: inline-block;
			margin: 0;
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1><?php echo current_role() === 'borrower' ? 'My Returned Items' : 'Returned Items'; ?></h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?>
			<div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
		<?php endif; ?>

		<div class="card">
			<h2><?php echo current_role() === 'borrower' ? 'My Returned Items' : 'All Returned Items'; ?></h2>
			<?php if (empty($returned)): ?>
				<div class="no-results">
					<p>No returned items found.</p>
					<?php if (current_role() === 'borrower'): ?>
						<p>Items you return will appear here.</p>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<table class="table">
				<thead>
				<tr>
					<?php if (current_role() !== 'borrower'): ?>
						<th>Borrower</th>
					<?php endif; ?>
					<th>Item</th>
					<th>Daily Rate</th>
					<th>Borrowed</th>
					<th>Due</th>
					<th>Returned</th>
					<th>Status</th>
					<th>Amount Paid</th>
					<th>Action</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($returned as $r): ?>
					<tr>
						<?php if (current_role() !== 'borrower'): ?>
							<td><?php echo h($r['user_name']); ?></td>
						<?php endif; ?>
						<td><?php echo h('['.($r['item_type']==='equipment'?'EQ':'SU').'] '.$r['item_name']); ?></td>
						<td>₱<?php echo number_format($r['daily_rate'], 2); ?></td>
						<td><?php echo h($r['date_borrowed'] ?? 'N/A'); ?></td>
						<td><?php echo h($r['due_date']); ?></td>
						<td><?php echo h($r['return_date'] ?? 'N/A'); ?></td>
						<td>
							<span class="status-returned">
								Returned
							</span>
						</td>
						<td>₱<?php echo number_format($r['total_amount_due'], 2); ?></td>
						<td>
							<form method="post" class="inline" onsubmit="return confirm('Delete this returned item from your view? This will only hide it from your view, not from <?php echo $current_role === 'borrower' ? 'admin/staff' : 'the borrower'; ?>.');">
								<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
								<input type="hidden" name="borrow_id" value="<?php echo (int)$r['borrow_id']; ?>">
								<button type="submit" name="delete_returned" class="btn small danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
									🗑️ Delete
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
	</div>
</body>
</html>

