<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

// If not AJAX request, show back button
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo '<div style="padding: 20px; text-align: center;">';
    echo '<h2>Exporting CSV...</h2>';
    echo '<a href="reports.php" class="btn">← Back to Reports</a>';
    echo '</div>';
    echo '<script>setTimeout(function(){ window.location.href = "reports.php"; }, 2000);</script>';
    exit;
}

$type = req_str('type');
header('Content-Type: text/csv; charset=utf-8');
if ($type === 'most_borrowed') {
	header('Content-Disposition: attachment; filename=most_borrowed.csv');
	$res = $mysqli->query("SELECT * FROM v_most_borrowed_items ORDER BY times_borrowed DESC, item_name ASC");
	$out = fopen('php://output', 'w');
	fputcsv($out, ['Item Type','Item','Times Borrowed']);
	while ($r = $res->fetch_assoc()) {
		fputcsv($out, [$r['item_type'], $r['item_name'], $r['times_borrowed']]);
	}
	fclose($out);
	exit;
} elseif ($type === 'active_borrowers') {
	header('Content-Disposition: attachment; filename=active_borrowers.csv');
	$res = $mysqli->query("SELECT * FROM v_active_borrowers ORDER BY active_transactions DESC, name ASC");
	$out = fopen('php://output', 'w');
	fputcsv($out, ['User','Active Transactions']);
	while ($r = $res->fetch_assoc()) {
		fputcsv($out, [$r['name'], $r['active_transactions']]);
	}
	fclose($out);
	exit;
}

http_response_code(400);
echo 'Invalid export type';
exit;


