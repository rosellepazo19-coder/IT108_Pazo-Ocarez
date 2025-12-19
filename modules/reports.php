<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

// Reports data
$mostBorrowed = [];
$activeBorrowers = [];
$activity = [];

$resMB = $mysqli->query("SELECT * FROM v_most_borrowed_items ORDER BY times_borrowed DESC, item_name ASC");
if ($resMB) { $mostBorrowed = $resMB->fetch_all(MYSQLI_ASSOC); $resMB->free(); }
$resAB = $mysqli->query("
	SELECT 
		u.user_id,
		CONCAT(u.Fname, ' ', u.Lname, ' (', u.idnum, ')') AS name,
		COUNT(*) AS active_transactions
	FROM borrow_records b
	JOIN users u ON u.user_id = b.user_id
	WHERE b.status IN ('reserved','borrowed','overdue')
	  AND u.role = 'borrower'
	GROUP BY u.user_id, u.Fname, u.Lname, u.idnum
	ORDER BY active_transactions DESC, name ASC
");
if ($resAB) { $activeBorrowers = $resAB->fetch_all(MYSQLI_ASSOC); $resAB->free(); }
$resAL = $mysqli->query("SELECT * FROM activity_log ORDER BY date_time DESC LIMIT 100");
if ($resAL) { $activity = $resAL->fetch_all(MYSQLI_ASSOC); $resAL->free(); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Reports</title>
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
		
		.chart-container {
			position: relative;
			height: 400px;
			margin-bottom: 2rem;
			background: white;
			padding: 1.5rem;
			border-radius: 10px;
		}
		
		.charts-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 1.5rem;
			margin-bottom: 1.5rem;
		}
		
		@media (max-width: 1024px) {
			.charts-grid {
				grid-template-columns: 1fr;
			}
		}
		
		.chart-wrapper {
			background: white;
			padding: 1.5rem;
			border-radius: 10px;
		}
	</style>
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Reports</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<div class="charts-grid">
			<div class="chart-wrapper">
				<h3 style="color: var(--primary); margin-top: 0; margin-bottom: 1rem;">Most Borrowed Items</h3>
				<div class="chart-container">
					<canvas id="mostBorrowedChart"></canvas>
				</div>
			</div>
			
			<div class="chart-wrapper">
				<h3 style="color: var(--primary); margin-top: 0; margin-bottom: 1rem;">Active Borrowers</h3>
				<div class="chart-container">
					<canvas id="activeBorrowersChart"></canvas>
				</div>
			</div>
		</div>
		
		<div class="card">
			<h2>Most Borrowed Items - Detailed Table</h2>
			<table class="table">
				<thead>
					<tr>
						<th>Type</th>
						<th>Item</th>
						<th>Times Borrowed</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($mostBorrowed as $m): ?>
						<tr>
							<td><?php echo h($m['item_type']); ?></td>
							<td><?php echo h($m['item_name']); ?></td>
							<td><?php echo h($m['times_borrowed']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="card">
			<h2>Active Borrowers - Detailed Table</h2>
			<table class="table">
				<thead>
					<tr>
						<th>User</th>
						<th>Active Transactions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($activeBorrowers as $a): ?>
						<tr>
							<td><?php echo h($a['name']); ?></td>
							<td><?php echo h($a['active_transactions']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="card">
			<h2>Recent Activity</h2>
			<table class="table">
				<thead>
					<tr>
						<th>#</th>
						<th>User ID</th>
						<th>Action</th>
						<th>Date/Time</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($activity as $log): ?>
						<tr>
							<td><?php echo h($log['log_id']); ?></td>
							<td><?php echo h($log['user_id']); ?></td>
							<td><?php echo h($log['action']); ?></td>
							<td><?php echo h($log['date_time']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	
	<script>
		// Chart colors matching the green theme
		const chartColors = {
			primary: '#2e7d32',
			secondary: '#85bba8',
			accent: '#9ceba0',
			light: '#c8e6c9',
			gradient: ['#2e7d32', '#4caf50', '#66bb6a', '#81c784', '#a5d6a7']
		};
		
		// Most Borrowed Items Chart
		<?php
		$topItems = array_slice($mostBorrowed, 0, 10); // Top 10 items
		if (empty($topItems)) {
			$itemLabels = json_encode(['No Data']);
			$itemData = json_encode([0]);
		} else {
			$itemLabels = json_encode(array_column($topItems, 'item_name'));
			$itemData = json_encode(array_map(function($m) { return (int)$m['times_borrowed']; }, $topItems));
		}
		?>
		const mostBorrowedCtx = document.getElementById('mostBorrowedChart').getContext('2d');
		new Chart(mostBorrowedCtx, {
			type: 'bar',
			data: {
				labels: <?php echo $itemLabels; ?>,
				datasets: [{
					label: 'Times Borrowed',
					data: <?php echo $itemData; ?>,
					backgroundColor: chartColors.gradient.slice(0, <?php echo max(1, count($topItems)); ?>),
					borderColor: chartColors.primary,
					borderWidth: 2,
					borderRadius: 8
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: false
					},
					title: {
						display: false
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							stepSize: 1
						},
						grid: {
							color: '#f0f0f0'
						}
					},
					x: {
						ticks: {
							maxRotation: 45,
							minRotation: 45
						},
						grid: {
							display: false
						}
					}
				}
			}
		});
		
		// Active Borrowers Chart
		<?php
		$topBorrowers = array_slice($activeBorrowers, 0, 10); // Top 10 borrowers
		if (empty($topBorrowers)) {
			$borrowerLabels = json_encode(['No Data']);
			$borrowerData = json_encode([0]);
		} else {
			$borrowerLabels = json_encode(array_column($topBorrowers, 'name'));
			$borrowerData = json_encode(array_map(function($a) { return (int)$a['active_transactions']; }, $topBorrowers));
		}
		?>
		const activeBorrowersCtx = document.getElementById('activeBorrowersChart').getContext('2d');
		new Chart(activeBorrowersCtx, {
			type: 'bar',
			data: {
				labels: <?php echo $borrowerLabels; ?>,
				datasets: [{
					label: 'Active Transactions',
					data: <?php echo $borrowerData; ?>,
					backgroundColor: chartColors.gradient.slice(0, <?php echo max(1, count($topBorrowers)); ?>),
					borderColor: chartColors.primary,
					borderWidth: 2,
					borderRadius: 8
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: false
					},
					title: {
						display: false
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							stepSize: 1
						},
						grid: {
							color: '#f0f0f0'
						}
					},
					x: {
						ticks: {
							maxRotation: 45,
							minRotation: 45
						},
						grid: {
							display: false
						}
					}
				}
			}
		});
	</script>
</body>
</html>


