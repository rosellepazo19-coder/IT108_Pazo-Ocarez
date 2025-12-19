<?php
// CLI/Task script to mark overdue records. Configure in Task Scheduler or cron.
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: text/plain');

$ok = $mysqli->query("CALL sp_mark_overdue()");
if (!$ok) {
	echo 'Error: ' . $mysqli->error . "\n";
	exit(1);
}
// Consume any results
while ($mysqli->more_results() && $mysqli->next_result()) { }

echo 'Overdue marking completed at ' . date('Y-m-d H:i:s') . "\n";
exit(0);


