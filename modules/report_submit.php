<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

// Only borrowers can submit reports, not admins or staff
if (current_role() === 'admin' || current_role() === 'staff') {
    $_SESSION['flash'] = 'You do not have permission to submit reports.';
    header('Location: ../index.php');
    exit;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle new report creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_report'])) {
    $token = req_str('csrf_token');
    if (!csrf_check($token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $subject = trim(req_str('subject'));
        $message = trim(req_str('message'));
        if ($subject === '' || $message === '') {
            $errors[] = 'Subject and message are required.';
        }
        if (!$errors) {
            $mysqli->begin_transaction();
            try {
                // Create report
                $stmt = $mysqli->prepare("INSERT INTO user_reports (user_id, subject, message, last_message_at, message_count) VALUES (?, ?, ?, NOW(), 1)");
                $uid = current_user()['user_id'];
                $stmt->bind_param('iss', $uid, $subject, $message);
                $stmt->execute();
                $report_id = $mysqli->insert_id;
                
                // Add initial message to conversation
                $stmt2 = $mysqli->prepare("INSERT INTO conversation_messages (report_id, user_id, message, is_admin) VALUES (?, ?, ?, FALSE)");
                $stmt2->bind_param('iis', $report_id, $uid, $message);
                $stmt2->execute();
                $stmt2->close();
                
                $mysqli->commit();
                $_SESSION['flash'] = 'Report submitted. Admin will review it.';
                header('Location: report_submit.php');
                exit;
            } catch (Exception $e) {
                $mysqli->rollback();
                $errors[] = 'Failed to submit report.';
            }
            $stmt->close();
        }
    }
}

// Handle new message in conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $token = req_str('csrf_token');
    if (!csrf_check($token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $report_id = req_int('report_id');
        $message = trim(req_str('message'));
        if (!$report_id || $message === '') {
            $errors[] = 'Report ID and message are required.';
        } else {
            $uid = current_user()['user_id'];
            $mysqli->begin_transaction();
            try {
                // Add message to conversation
                $stmt = $mysqli->prepare("INSERT INTO conversation_messages (report_id, user_id, message, is_admin) VALUES (?, ?, ?, FALSE)");
                $stmt->bind_param('iis', $report_id, $uid, $message);
                $stmt->execute();
                
                // Update report's last message time and count
                $stmt2 = $mysqli->prepare("UPDATE user_reports SET last_message_at = NOW(), message_count = message_count + 1 WHERE report_id = ?");
                $stmt2->bind_param('i', $report_id);
                $stmt2->execute();
                $stmt2->close();
                
                $mysqli->commit();
                $_SESSION['flash'] = 'Message sent.';
                header('Location: report_submit.php?view=' . $report_id);
                exit;
            } catch (Exception $e) {
                $mysqli->rollback();
                $errors[] = 'Failed to send message.';
            }
            $stmt->close();
        }
    }
}

// Check if last_viewed_by_user column exists, if not add it
$column_check = $mysqli->query("SHOW COLUMNS FROM user_reports LIKE 'last_viewed_by_user'");
if ($column_check->num_rows == 0) {
	$mysqli->query("ALTER TABLE user_reports ADD COLUMN last_viewed_by_user TIMESTAMP NULL AFTER last_message_at");
}
if ($column_check) $column_check->free();

// Load my reports with conversation status
$myReports = [];
$uid = current_user()['user_id'];
$res = $mysqli->prepare("SELECT report_id, subject, status, created_at, resolved_at, last_message_at, message_count, last_viewed_by_user FROM user_reports WHERE user_id = ? ORDER BY last_message_at DESC, created_at DESC");
$res->bind_param('i', $uid);
$res->execute();
$result = $res->get_result();
if ($result) { $myReports = $result->fetch_all(MYSQLI_ASSOC); }
$res->close();

// Load conversation for specific report
$conversation = [];
$viewReport = req_int('view');
if ($viewReport) {
	// Verify the report belongs to the current user
	$check_stmt = $mysqli->prepare("SELECT report_id FROM user_reports WHERE report_id = ? AND user_id = ?");
	$check_stmt->bind_param('ii', $viewReport, $uid);
	$check_stmt->execute();
	$check_result = $check_stmt->get_result();
	
	if ($check_result->num_rows > 0) {
		// Mark messages as read by updating last_viewed_by_user
		$update_stmt = $mysqli->prepare("UPDATE user_reports SET last_viewed_by_user = NOW() WHERE report_id = ? AND user_id = ?");
		$update_stmt->bind_param('ii', $viewReport, $uid);
		$update_stmt->execute();
		$update_stmt->close();
		
		// Load conversation messages
		$stmt = $mysqli->prepare("
			SELECT cm.*, u.Fname, u.Lname, u.role 
			FROM conversation_messages cm 
			JOIN users u ON cm.user_id = u.user_id 
			WHERE cm.report_id = ? 
			ORDER BY cm.created_at ASC
		");
		$stmt->bind_param('i', $viewReport);
		$stmt->execute();
		$conversation = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
		$stmt->close();
	}
	$check_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Report</title>
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
        
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, #1b5e20 100%);
            color: var(--text-light);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--shadow-hover);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            margin: 0;
            color: var(--text-light);
        }
        
        .card {
            background: var(--secondary);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow-hover);
            margin-bottom: 1.5rem;
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
            padding: 0.75rem;
            font-weight: 600;
        }
        
        .table td {
            padding: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table tr:hover {
            background: #f9f9f9;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn.primary {
            background: var(--primary);
            color: var(--text-light);
        }
        
        .btn.primary:hover {
            background: #1b5e20;
            transform: translateY(-2px);
        }
        
        .btn.small {
            background: var(--accent);
            color: var(--text-dark);
            border: 1px solid var(--primary);
        }
        
        .btn.small:hover {
            background: var(--primary);
            color: var(--text-light);
        }
        
        .alert {
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .alert.success {
            background: var(--accent);
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .alert.error {
            background: #fee;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        input[type="text"],
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 1rem;
            box-sizing: border-box;
        }
        
        input[type="text"]:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .chat-container {
            display: flex;
            gap: 20px;
            height: 600px;
        }
        
        .conversation-list {
            flex: 1;
            border: 2px solid var(--primary);
            border-radius: 8px;
            overflow-y: auto;
            background: white;
        }
        
        .conversation-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .conversation-item:hover {
            background-color: var(--accent);
        }
        
        .conversation-item.active {
            background-color: var(--accent);
            border-left: 4px solid var(--primary);
        }
        
        .conversation-header {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .conversation-meta {
            font-size: 12px;
            color: #666;
        }
        
        .chat-area {
            flex: 2;
            display: flex;
            flex-direction: column;
            border: 2px solid var(--primary);
            border-radius: 8px;
            background: white;
        }
        
        .chat-header {
            padding: 15px;
            background: linear-gradient(135deg, var(--primary) 0%, #1b5e20 100%);
            color: var(--text-light);
            border-bottom: 1px solid var(--primary);
            font-weight: bold;
        }
        
        .chat-messages {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            max-height: 400px;
            background: #f9f9f9;
        }
        
        .message {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }
        
        .message.user {
            justify-content: flex-end;
        }
        
        .message.admin {
            justify-content: flex-start;
        }
        
        .message-bubble {
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        
        .message.user .message-bubble {
            background-color: var(--primary);
            color: white;
        }
        
        .message.admin .message-bubble {
            background-color: var(--secondary);
            color: var(--text-dark);
        }
        
        .message-info {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
        }
        
        .chat-input {
            padding: 15px;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 10px;
            background: white;
        }
        
        .chat-input input {
            flex: 1;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 20px;
        }
        
        .chat-input input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .chat-input button {
            padding: 10px 20px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .chat-input button:hover {
            background-color: #1b5e20;
            transform: translateY(-2px);
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-open { 
            background-color: var(--accent); 
            color: var(--primary); 
        }
        
        .status-resolved { 
            background-color: var(--primary); 
            color: white; 
        }
        
        .no-conversation {
            text-align: center;
            color: #666;
            padding: 50px;
        }
        
        .form-actions {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Submit Report</h1>
            <div class="user-info">
                <a href="../index.php" class="btn small">← Back to Dashboard</a>
            </div>
        </div>

        <?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div><?php endif; ?>

        <?php if ($viewReport): ?>
            <!-- Chat Interface -->
            <div class="chat-container">
                <div class="conversation-list">
                    <div style="padding: 15px; background: linear-gradient(135deg, var(--primary) 0%, #1b5e20 100%); color: white; border-bottom: 1px solid var(--primary);">
                        <strong>My Reports</strong>
                    </div>
                    <?php foreach ($myReports as $r): ?>
                        <div class="conversation-item <?php echo $r['report_id'] == $viewReport ? 'active' : ''; ?>" 
                             onclick="location.href='?view=<?php echo $r['report_id']; ?>'">
                            <div class="conversation-header">
                                <?php echo h($r['subject']); ?>
                                <span class="status-badge status-<?php echo $r['status']; ?>">
                                    <?php echo ucfirst($r['status']); ?>
                                </span>
                            </div>
                            <div class="conversation-meta">
                                <?php echo h($r['last_message_at'] ?? $r['created_at']); ?>
                                <?php if ($r['message_count'] > 0): ?>
                                    • <?php echo $r['message_count']; ?> message<?php echo $r['message_count'] > 1 ? 's' : ''; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="chat-area">
                    <div class="chat-header">
                        <?php 
                        $currentReport = array_filter($myReports, function($r) use ($viewReport) { return $r['report_id'] == $viewReport; });
                        $currentReport = reset($currentReport);
                        if ($currentReport) {
                            echo h($currentReport['subject']);
                        }
                        ?>
                    </div>
                    
                    <div class="chat-messages">
                        <?php if ($conversation): ?>
                            <?php foreach ($conversation as $msg): ?>
                                <div class="message <?php echo $msg['is_admin'] ? 'admin' : 'user'; ?>">
                                    <div>
                                        <div class="message-bubble">
                                            <?php echo nl2br(h($msg['message'])); ?>
                                        </div>
                                        <div class="message-info">
                                            <?php echo h($msg['Fname'] . ' ' . $msg['Lname']); ?>
                                            <?php if ($msg['is_admin']): ?>
                                                <span style="color: var(--primary); font-weight: 600;">(Admin)</span>
                                            <?php endif; ?>
                                            • <?php echo h($msg['created_at']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-conversation">No messages yet.</div>
                        <?php endif; ?>
                    </div>

                    <?php if ($currentReport && $currentReport['status'] === 'open'): ?>
                        <div class="chat-input">
                            <form method="post" style="display: flex; width: 100%; gap: 10px;">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="report_id" value="<?php echo $viewReport; ?>">
                                <input type="text" name="message" placeholder="Type your message..." required>
                                <button type="submit" name="send_message">Send</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="padding: 15px; text-align: center; color: var(--text-dark); background-color: var(--accent);">
                            <?php if ($currentReport && $currentReport['status'] === 'resolved'): ?>
                                This report has been resolved.
                            <?php else: ?>
                                Select a report to view conversation.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Report List View -->
            <div class="card">
                <h2>Create a New Report</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <label>Subject</label>
                    <input type="text" name="subject" required>
                    <label>Message</label>
                    <textarea name="message" rows="4" required></textarea>
                    <div class="form-actions">
                        <button type="submit" name="create_report" class="btn primary">Submit Report</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>My Reports</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Messages</th>
                            <th>Last Activity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myReports as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['report_id']; ?></td>
                                <td><?php echo h($r['subject']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $r['status']; ?>">
                                        <?php echo ucfirst($r['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo (int)$r['message_count']; ?></td>
                                <td><?php echo h($r['last_message_at'] ?? $r['created_at']); ?></td>
                                <td>
                                    <a href="?view=<?php echo $r['report_id']; ?>" class="btn small">View Chat</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$myReports): ?>
                            <tr><td colspan="6">No reports yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>


