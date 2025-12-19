<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

// If not AJAX request, show back button
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo '<div style="padding: 20px; text-align: center;">';
    echo '<h2>Exporting CSV...</h2>';
    echo '<a href="monitoring.php" class="btn">← Back to Monitoring</a>';
    echo '</div>';
    echo '<script>setTimeout(function(){ window.location.href = "monitoring.php"; }, 2000);</script>';
    exit;
}

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

if ($where === '') {
	$res = $mysqli->query("SELECT * FROM v_current_borrows ORDER BY due_date");
} else {
	$sql = "SELECT * FROM v_current_borrows vc JOIN users u ON u.user_id = vc.user_id $where ORDER BY vc.due_date";
	$stmt = $mysqli->prepare($sql);
	$types = str_repeat('s', count($params));
	$stmt->bind_param($types, ...$params);
	$stmt->execute();
	$res = $stmt->get_result();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=monitoring.csv');
$out = fopen('php://output', 'w');
fputcsv($out, ['Borrow ID','Borrower','Item Type','Item','Borrowed','Due','Status','Overdue Days']);
while ($row = $res->fetch_assoc()) {
	fputcsv($out, [
		$row['borrow_id'],
		$row['user_name'],
		$row['item_type'],
		$row['item_name'],
		$row['date_borrowed'],
		$row['due_date'],
		$row['status'],
		$row['overdue_days']
	]);
}
fclose($out);
exit;


