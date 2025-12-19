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

// Load purchase history
$purchases = [];
if ($current_user_id) {
	$stmt = $mysqli->prepare("
		SELECT 
			p.*,
			s.name as supply_name,
			s.image_path,
			s.image_alt
		FROM purchase_records p
		JOIN supplies s ON p.supply_id = s.supply_id
		WHERE p.user_id = ?
		ORDER BY p.created_at DESC
	");
	$stmt->bind_param('i', $current_user_id);
	$stmt->execute();
	$purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
	$stmt->close();
}

// Calculate summary
$total_purchases = count($purchases);
$total_amount = 0;
$confirmed_amount = 0;
$pending_amount = 0;

foreach ($purchases as $p) {
	$total_amount += (float)$p['total_amount'];
	if ($p['payment_status'] === 'confirmed' && $p['status'] === 'confirmed') {
		$confirmed_amount += (float)$p['total_amount'];
	} elseif ($p['payment_status'] === 'pending') {
		$pending_amount += (float)$p['total_amount'];
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Purchase History - Cabadbaran Agricultural Supply and Equipment Lending System</title>
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
		
		.summary-cards {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 1.5rem;
			margin-bottom: 2rem;
		}
		
		.summary-card {
			background: var(--secondary);
			padding: 1.5rem;
			border-radius: 12px;
			box-shadow: var(--shadow-hover);
			text-align: center;
		}
		
		.summary-card h3 {
			margin: 0 0 0.5rem 0;
			font-size: 0.9rem;
			color: #666;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		
		.summary-card .value {
			font-size: 1.8rem;
			font-weight: 700;
			color: var(--primary);
			margin: 0;
		}
		
		.card {
			background: var(--secondary);
			border-radius: 12px;
			padding: 2rem;
			box-shadow: var(--shadow-hover);
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
			margin-bottom: 1.5rem;
			font-size: 1.3rem;
		}
		
		.table {
			background: white;
			border-radius: 10px;
			overflow: hidden;
		}
		
		.table th {
			background: var(--primary);
			color: var(--text-light);
			padding: 0.75rem;
			font-weight: 600;
			text-align: left;
			font-size: 0.9rem;
		}
		
		.table td {
			padding: 0.75rem;
			border-bottom: 1px solid #f0f0f0;
			font-size: 0.9rem;
		}
		
		.table tr:hover {
			background: #f9f9f9;
		}
		
		.status-badge {
			display: inline-block;
			padding: 0.25rem 0.75rem;
			border-radius: 12px;
			font-size: 0.8rem;
			font-weight: 600;
			text-transform: capitalize;
		}
		
		.status-pending {
			background: #fff3cd;
			color: #856404;
		}
		
		.status-confirmed {
			background: #d4edda;
			color: #155724;
		}
		
		.status-cancelled {
			background: #f8d7da;
			color: #721c24;
		}
		
		.status-completed {
			background: #d1ecf1;
			color: #0c5460;
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
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>My Purchase History</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?>
			<div class="alert success"><?php echo h($flash); ?></div>
		<?php endif; ?>
		
		
		<div class="card">
			<h2>My Purchase Records</h2>
			<table class="table">
				<thead>
					<tr>
						<th>Purchase #</th>
						<th>Item</th>
						<th>Quantity</th>
						<th>Unit Price</th>
						<th>Total Amount</th>
						<th>Payment Method</th>
						<th>Reference</th>
						<th>Date</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($purchases)): ?>
						<tr>
							<td colspan="9" style="text-align: center; padding: 2rem; color: #999;">
								No purchase records found.
							</td>
						</tr>
					<?php else: ?>
						<?php foreach ($purchases as $p): ?>
							<tr>
								<td>#<?php echo (int)$p['purchase_id']; ?></td>
								<td>
									<?php if ($p['image_path']): ?>
										<img src="../<?php echo h($p['image_path']); ?>" 
											alt="<?php echo h($p['image_alt'] ?? $p['supply_name']); ?>" 
											style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px; margin-right: 0.5rem; vertical-align: middle;">
									<?php endif; ?>
									<?php echo h($p['supply_name']); ?>
								</td>
								<td><?php echo (int)$p['quantity']; ?></td>
								<td>₱<?php echo number_format((float)$p['unit_price'], 2); ?></td>
								<td><strong>₱<?php echo number_format((float)$p['total_amount'], 2); ?></strong></td>
								<td><?php echo h(ucfirst($p['payment_method'] ?? 'N/A')); ?></td>
								<td><?php echo h($p['payment_reference'] ?? '—'); ?></td>
								<td><?php echo h($p['created_at']); ?></td>
								<td>
									<span class="status-badge status-<?php echo h($p['status']); ?>">
										<?php echo h(ucfirst($p['status'])); ?>
									</span>
									<?php if ($p['payment_status'] === 'pending'): ?>
										<br><small style="color: #856404;">Payment Pending</small>
									<?php elseif ($p['payment_status'] === 'confirmed'): ?>
										<br><small style="color: #155724;">Payment Confirmed</small>
									<?php endif; ?>
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

