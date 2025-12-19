<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_roles(['admin', 'staff']);

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Resolve report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve'])) {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$rid = req_int('report_id');
		if ($rid) {
			// Mark as resolved and also mark as read
			$stmt = $mysqli->prepare("UPDATE user_reports SET status='resolved', resolved_at = NOW(), last_viewed_by_admin = NOW() WHERE report_id = ?");
			$stmt->bind_param('i', $rid);
			if ($stmt->execute()) {
				$_SESSION['flash'] = 'Report marked as resolved.';
				header('Location: report_inbox.php');
				exit;
			} else {
				$errors[] = 'Failed to update report.';
			}
			$stmt->close();
		}
	}
}

// Handle admin reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_reply'])) {
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
                // Add admin/staff message to conversation
                // Verify the user is admin or staff
                $current_role = current_role();
                if (!in_array($current_role, ['admin', 'staff'])) {
                    throw new Exception('Only admin/staff can reply to reports.');
                }
                $stmt = $mysqli->prepare("INSERT INTO conversation_messages (report_id, user_id, message, is_admin) VALUES (?, ?, ?, TRUE)");
                $stmt->bind_param('iis', $report_id, $uid, $message);
                $stmt->execute();
                
				// Update report's last message time and count
				// Also mark as read when admin replies (since they're viewing it)
				$stmt2 = $mysqli->prepare("UPDATE user_reports SET last_message_at = NOW(), message_count = message_count + 1, last_viewed_by_admin = NOW() WHERE report_id = ?");
				$stmt2->bind_param('i', $report_id);
				$stmt2->execute();
				$stmt2->close();
				
				$mysqli->commit();
				$_SESSION['flash'] = 'Reply sent.';
				header('Location: report_inbox.php?view=' . $report_id);
				exit;
            } catch (Exception $e) {
                $mysqli->rollback();
                $errors[] = 'Failed to send reply.';
            }
            $stmt->close();
        }
    }
}

// Load all reports with conversation data
$reports = [];
$res = $mysqli->query("SELECT r.report_id, r.subject, r.message, r.status, r.created_at, r.resolved_at, r.last_message_at, r.message_count, u.Fname, u.Lname
                       FROM user_reports r JOIN users u ON r.user_id = u.user_id
                       ORDER BY r.last_message_at DESC, r.created_at DESC");
if ($res) { $reports = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }

// Check if last_viewed_by_admin column exists, if not add it
$column_check = $mysqli->query("SHOW COLUMNS FROM user_reports LIKE 'last_viewed_by_admin'");
if ($column_check->num_rows == 0) {
	$mysqli->query("ALTER TABLE user_reports ADD COLUMN last_viewed_by_admin TIMESTAMP NULL AFTER last_viewed_by_user");
}
if ($column_check) $column_check->free();

// Load conversation for specific report
$conversation = [];
$viewReport = req_int('view');
if ($viewReport) {
	// Mark messages as read by updating last_viewed_by_admin when admin views the conversation
	$update_stmt = $mysqli->prepare("UPDATE user_reports SET last_viewed_by_admin = NOW() WHERE report_id = ?");
	$update_stmt->bind_param('i', $viewReport);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Inbox</title>
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
        
        .chat-container {
            display: flex;
            gap: 20px;
            height: 600px;
            background: var(--secondary);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow-hover);
        }
        .conversation-list {
            flex: 1;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            overflow-y: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .conversation-list-header {
            padding: 15px;
            background-color: var(--primary);
            color: white;
            border-bottom: 2px solid #1b5e20;
            font-weight: bold;
            font-size: 1rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .conversation-item {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }
        .conversation-item:hover {
            background-color: #f0f7f0;
        }
        .conversation-item.active {
            background-color: var(--accent);
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .conversation-header {
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text-dark);
            font-size: 0.95rem;
        }
        .conversation-meta {
            font-size: 12px;
            color: #555;
            line-height: 1.5;
        }
        .chat-area {
            flex: 2;
            display: flex;
            flex-direction: column;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .chat-header {
            padding: 15px;
            background-color: var(--primary);
            color: white;
            border-bottom: 2px solid #1b5e20;
            font-weight: bold;
            font-size: 1rem;
        }
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            max-height: 400px;
            background: #fafafa;
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
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .message.user .message-bubble {
            background-color: var(--primary);
            color: white;
            border: 1px solid #1b5e20;
        }
        .message.admin .message-bubble {
            background-color: white;
            color: var(--text-dark);
            border: 1px solid #e0e0e0;
        }
        .message-info {
            font-size: 11px;
            color: #555;
            margin-top: 5px;
            font-weight: 500;
        }
        .chat-input {
            padding: 15px;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 10px;
        }
        .chat-input {
            background: white;
        }
        .chat-input input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 20px;
            background: #f9f9f9;
            transition: border-color 0.3s ease;
        }
        .chat-input input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }
        .chat-input button {
            padding: 10px 20px;
            background: var(--accent);
            color: var(--text-dark);
            border: 1px solid var(--primary);
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .chat-input button:hover {
            background: var(--primary);
            color: var(--text-light);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 8px;
        }
        .status-open { 
            background-color: #ffeb3b; 
            color: #333; 
            border: 1px solid #fbc02d;
        }
        .status-resolved { 
            background-color: #4caf50; 
            color: white; 
            border: 1px solid #388e3c;
        }
        .no-conversation {
            text-align: center;
            color: #666;
            padding: 50px;
            background: white;
        }
        .resolved-bar {
            padding: 15px;
            text-align: center;
            background-color: #e8f5e9;
            border-top: 2px solid var(--primary);
            color: #2e7d32;
            font-weight: 600;
            border-radius: 0 0 8px 8px;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="container">
            <h1>Reports Inbox</h1>
            <a href="../index.php" class="btn">← Back to Dashboard</a>
        </div>
    </div>
    
    <div class="container">

        <?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div><?php endif; ?>

        <?php if ($viewReport): ?>
            <!-- Chat Interface -->
            <div class="chat-container">
                <div class="conversation-list">
                    <div class="conversation-list-header">
                        All Reports
                    </div>
                    <?php foreach ($reports as $r): ?>
                        <div class="conversation-item <?php echo $r['report_id'] == $viewReport ? 'active' : ''; ?>" 
                             onclick="location.href='?view=<?php echo $r['report_id']; ?>'">
                            <div class="conversation-header">
                                <?php echo h($r['subject']); ?>
                                <span class="status-badge status-<?php echo $r['status']; ?>">
                                    <?php echo ucfirst($r['status']); ?>
                                </span>
                            </div>
                            <div class="conversation-meta">
                                From: <?php echo h($r['Fname'] . ' ' . $r['Lname']); ?><br>
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
                        $currentReport = array_filter($reports, function($r) use ($viewReport) { return $r['report_id'] == $viewReport; });
                        $currentReport = reset($currentReport);
                        if ($currentReport) {
                            echo h($currentReport['subject']) . ' - From: ' . h($currentReport['Fname'] . ' ' . $currentReport['Lname']);
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
                                                <span style="color: var(--primary); font-weight: bold;">
                                                    (<?php echo in_array($msg['role'], ['admin', 'staff']) ? ucfirst($msg['role']) : 'Admin'; ?>)
                                                </span>
                                            <?php endif; ?>
                                            • <?php echo h($msg['created_at']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($currentReport && $currentReport['status'] === 'resolved'): ?>
                                <div class="resolved-bar" style="margin-top: 20px;">
                                    ✓ This report has been resolved.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-conversation">No messages yet.</div>
                        <?php endif; ?>
                    </div>

                    <?php if ($currentReport && $currentReport['status'] === 'open'): ?>
                        <div class="chat-input">
                            <form method="post" style="display: flex; width: 100%; gap: 10px;">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="report_id" value="<?php echo $viewReport; ?>">
                                <input type="text" name="message" placeholder="Type your reply..." required>
                                <button type="submit" name="admin_reply">Send</button>
                            </form>
                        </div>
                        <div style="padding: 10px; text-align: center; border-top: 1px solid #ddd;">
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="report_id" value="<?php echo $viewReport; ?>">
                                <button type="submit" name="resolve" class="btn small danger" onclick="return confirm('Mark this report as resolved?')">Mark Resolved</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="no-conversation">
                            <?php if ($currentReport && $currentReport['status'] === 'resolved' && !$conversation): ?>
                                <div class="resolved-bar" style="margin: 0;">
                                    ✓ This report has been resolved.
                                </div>
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
                <h2>All Reports</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Messages</th>
                            <th>Last Activity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['report_id']; ?></td>
                                <td><?php echo h($r['Fname'].' '.$r['Lname']); ?></td>
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
                                    <?php if ($r['status'] === 'open'): ?>
                                        <form method="post" class="inline" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="report_id" value="<?php echo (int)$r['report_id']; ?>">
                                            <button type="submit" name="resolve" class="btn small danger" onclick="return confirm('Mark as resolved?')">Resolve</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$reports): ?>
                            <tr><td colspan="7">No reports yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>


