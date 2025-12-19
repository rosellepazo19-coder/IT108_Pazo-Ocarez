<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

// Filters
$kw = trim(req_str('q'));
$status = req_str('status');
$params = [];
$sqlWhere = [];
if ($kw !== '') {
	$sqlWhere[] = "(u.name LIKE CONCAT('%', ?, '%') OR item_name LIKE CONCAT('%', ?, '%'))";
	$params[] = $kw; $params[] = $kw;
}
if ($status !== '' && in_array($status, ['reserved','borrowed','overdue'], true)) {
	$sqlWhere[] = "status = ?";
	$params[] = $status;
}
$where = $sqlWhere ? ('WHERE ' . implode(' AND ', $sqlWhere)) : '';

// Current and overdue lists via views with filters
$current = [];
$overdue = [];
if ($where === '') {
	$resC = $mysqli->query("SELECT * FROM v_current_borrows ORDER BY due_date");
} else {
	$sql = "SELECT * FROM v_current_borrows vc JOIN users u ON u.user_id = vc.user_id $where ORDER BY vc.due_date";
	$stmt = $mysqli->prepare($sql);
	$types = str_repeat('s', count($params));
	$stmt->bind_param($types, ...$params);
	$stmt->execute();
	$resC = $stmt->get_result();
}
if ($resC) { $current = $resC->fetch_all(MYSQLI_ASSOC); $resC->free(); }
if ($where === '') {
	$resO = $mysqli->query("SELECT * FROM v_overdue_items ORDER BY overdue_days DESC");
} else {
	$sql2 = "SELECT * FROM (SELECT * FROM v_overdue_items) t JOIN users u ON u.user_id = t.user_id $where ORDER BY t.overdue_days DESC";
	$stmt2 = $mysqli->prepare($sql2);
	$types2 = str_repeat('s', count($params));
	$stmt2->bind_param($types2, ...$params);
	$stmt2->execute();
	$resO = $stmt2->get_result();
}
if ($resO) { $overdue = $resO->fetch_all(MYSQLI_ASSOC); $resO->free(); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Monitoring</title>
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
		
		.filter-form {
			display: flex;
			gap: 0.75rem;
			flex-wrap: wrap;
			align-items: center;
		}
		
		.filter-form input, .filter-form select {
			padding: 0.75rem;
			border: 2px solid #ddd;
			border-radius: 8px;
			background: #f9f9f9;
			transition: border-color 0.3s ease;
		}
		
		.filter-form input:focus, .filter-form select:focus {
			outline: none;
			border-color: var(--primary);
			background: #fff;
		}
		
		.btn {
			background: var(--accent);
			color: var(--text-dark);
			border: 1px solid var(--primary);
			padding: 0.75rem 1.5rem;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.3s ease;
			font-weight: 500;
			text-decoration: none;
			display: inline-block;
		}
		
		.btn:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
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
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Monitoring</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
		<div class="grid">
			<div class="card">
				<h2>Active / Reserved Items</h2>
				<table class="table">
					<thead>
						<tr>
							<th>#</th>
							<th>Borrower</th>
							<th>Item</th>
							<th>Borrowed</th>
							<th>Due</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($current as $c): ?>
						<tr>
							<td><?php echo h($c['borrow_id']); ?></td>
							<td><?php echo h($c['user_name']); ?></td>
							<td><?php echo h('['.($c['item_type']==='equipment'?'EQ':'SU').'] '.$c['item_name']); ?></td>
							<td><?php echo h($c['date_borrowed']); ?></td>
							<td><?php echo h($c['due_date']); ?></td>
							<td><?php echo h($c['status']); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="card">
				<h2>Overdue Items</h2>
				<table class="table">
					<thead>
						<tr>
							<th>#</th>
							<th>Borrower</th>
							<th>Item</th>
							<th>Due</th>
							<th>Overdue (days)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($overdue as $o): ?>
						<tr>
							<td><?php echo h($o['borrow_id']); ?></td>
							<td><?php echo h($o['user_name']); ?></td>
							<td><?php echo h('['.($o['item_type']==='equipment'?'EQ':'SU').'] '.$o['item_name']); ?></td>
							<td><?php echo h($o['due_date']); ?></td>
							<td><?php echo h($o['overdue_days']); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</body>
</html>


