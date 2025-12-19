<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle return action - Only admin/staff can mark items as returned
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		// Only admin/staff can process returns
		if (!in_array(current_role(), ['admin', 'staff'])) {
			$errors[] = 'Only admin/staff can mark items as returned. Please contact admin to return your items.';
		} else {
			$borrow_id = req_int('borrow_id');
			$ret_date = req_str('return_date');
			if (!$borrow_id) $errors[] = 'Borrow record is required.';
			if (!$errors) {
				$rdt = $ret_date ? date('Y-m-d H:i:s', strtotime($ret_date)) : null;
				$stmt = $mysqli->prepare("CALL sp_return_item(?, ?)");
				$stmt->bind_param('is', $borrow_id, $rdt);
				$ok = $stmt->execute();
				if (!$ok) $errors[] = 'DB Error: ' . h($stmt->error);
				while ($stmt->more_results() && $stmt->next_result()) { /* no-op */ }
				$stmt->close();
				if (!$errors) {
					$_SESSION['flash'] = 'Item returned successfully.';
					header('Location: return.php');
					exit;
				}
			}
		}
	}
}

// List outstanding borrows (borrowed/overdue)
// Only admin/staff can access return page to mark items as returned
$outstanding = [];
if (!in_array(current_role(), ['admin', 'staff'])) {
	// Borrowers should be redirected - they cannot mark items as returned
	$_SESSION['flash'] = 'Please contact admin/staff to return your items. Only admin/staff can process returns.';
	header('Location: ../index.php');
	exit;
} else {
	// Admin/Staff can see all outstanding borrows
	$resO = $mysqli->query("SELECT * FROM v_current_borrows WHERE status IN ('borrowed','overdue') ORDER BY due_date");
	if ($resO) { $outstanding = $resO->fetch_all(MYSQLI_ASSOC); $resO->free(); }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Return Items</title>
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
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
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
		
		input[type="datetime-local"] {
			padding: 0.5rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			margin-right: 0.5rem;
		}
		
		.alert {
			border-radius: 10px;
			padding: 1rem;
			margin-bottom: 1.5rem;
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Return Items</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?>
			<div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
		<?php endif; ?>

		<div class="card">
			<h2>Outstanding Borrows</h2>
			<p style="color: #666; margin-bottom: 1rem;">Mark items as returned after verifying they are returned in good condition.</p>
			<table class="table">
				<thead>
					<tr>
						<th>#</th>
						<th>Borrower</th>
						<th>Item</th>
						<th>Borrowed</th>
						<th>Due</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($outstanding)): ?>
						<tr>
							<td colspan="7" style="text-align: center; padding: 2rem; color: #999;">
								No outstanding borrows at the moment.
							</td>
						</tr>
					<?php else: ?>
					<?php foreach ($outstanding as $o): ?>
						<tr>
							<td><?php echo h($o['borrow_id']); ?></td>
							<td><?php echo h($o['user_name']); ?></td>
							<td><?php echo h('['.($o['item_type']==='equipment'?'EQ':'SU').'] '.$o['item_name']); ?></td>
							<td><?php echo h($o['date_borrowed']); ?></td>
							<td><?php echo h($o['due_date']); ?></td>
							<td><?php echo h($o['status']); ?></td>
							<td>
								<form method="post" class="inline" onsubmit="return confirm('Mark this item as returned? Please verify the item condition first.');">
									<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
									<input type="hidden" name="borrow_id" value="<?php echo (int)$o['borrow_id']; ?>">
									<input type="datetime-local" name="return_date" title="Leave empty for current time" style="margin-right: 0.5rem;">
									<button class="btn primary" type="submit">Mark as Returned</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</body>
</html>


