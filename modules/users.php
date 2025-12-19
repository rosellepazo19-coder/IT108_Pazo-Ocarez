<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_roles(['admin']); // Only admin can manage users

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle user role update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $user_id = req_int('user_id');
    $new_role = req_str('role');
    
    if ($user_id && in_array($new_role, ['admin','staff','borrower'])) {
        $stmt = $mysqli->prepare("UPDATE users SET role = ? WHERE user_id = ?");
        $stmt->bind_param('si', $new_role, $user_id);
        if ($stmt->execute()) {
            $_SESSION['flash'] = 'User role updated successfully.';
        } else {
            $errors[] = 'Failed to update user role.';
        }
        $stmt->close();
    } else {
        $errors[] = 'Invalid user or role.';
    }
}

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = req_int('user_id');
    $current_user = current_user();
    $current_user_id = $current_user['user_id'] ?? null;
    
    if ($user_id && $user_id !== $current_user_id) {
        // Check if user exists
        $stmt = $mysqli->prepare("SELECT Fname, Lname FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($fname, $lname);
            $stmt->fetch();
            $stmt->close();
            
            // Check if user has active borrow records
            $stmt = $mysqli->prepare("SELECT COUNT(*) FROM borrow_records WHERE user_id = ? AND status IN ('reserved', 'borrowed', 'overdue')");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->bind_result($active_borrows);
            $stmt->fetch();
            $stmt->close();
            
            if ($active_borrows > 0) {
                $errors[] = "Cannot delete user {$fname} {$lname}. User has {$active_borrows} active borrow record(s). Please return all items first.";
            } else {
                // Start transaction for safe deletion
                $mysqli->begin_transaction();
                
                try {
                    // Delete related records first (in order of dependency)
                    // 1. Delete payments (cascade will handle this, but being explicit)
                    $stmt = $mysqli->prepare("DELETE FROM payments WHERE borrow_id IN (SELECT borrow_id FROM borrow_records WHERE user_id = ?)");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // 2. Delete borrow records
                    $stmt = $mysqli->prepare("DELETE FROM borrow_records WHERE user_id = ?");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // 3. Delete purchase records (to fix foreign key constraint)
                    $stmt = $mysqli->prepare("DELETE FROM purchase_records WHERE user_id = ?");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // 4. Delete user reports and conversation messages
                    $stmt = $mysqli->prepare("DELETE FROM conversation_messages WHERE report_id IN (SELECT report_id FROM user_reports WHERE user_id = ?)");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $stmt = $mysqli->prepare("DELETE FROM user_reports WHERE user_id = ?");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // 5. Delete login attempts
                    $stmt = $mysqli->prepare("DELETE FROM login_attempts WHERE email = (SELECT mail FROM users WHERE user_id = ?)");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // 6. Finally delete the user
                    $stmt = $mysqli->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Commit transaction
                    $mysqli->commit();
                    $_SESSION['flash'] = "User {$fname} {$lname} has been deleted successfully.";
                    
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $mysqli->rollback();
                    $errors[] = 'Failed to delete user: ' . $e->getMessage();
                }
            }
        } else {
            $errors[] = 'User not found.';
        }
    } else {
        $errors[] = 'Cannot delete your own account or invalid user.';
    }
}

// Load all users with active borrow count
$users = [];
$res = $mysqli->query("
    SELECT 
        u.user_id, u.idnum, u.Fname, u.Lname, u.mail, u.role, u.created_at,
        COALESCE(active_borrows.count, 0) as active_borrows
    FROM users u
    LEFT JOIN (
        SELECT user_id, COUNT(*) as count 
        FROM borrow_records 
        WHERE status IN ('reserved', 'borrowed', 'overdue')
        GROUP BY user_id
    ) active_borrows ON u.user_id = active_borrows.user_id
    ORDER BY u.created_at DESC
");
if ($res) { 
    $users = $res->fetch_all(MYSQLI_ASSOC); 
    $res->free(); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
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
        
        .btn-danger {
            background: #e74c3c !important;
            color: white !important;
            border: 1px solid #e74c3c !important;
            padding: 0.5rem 1rem !important;
            border-radius: 8px !important;
            cursor: pointer !important;
            font-size: 0.9rem !important;
            transition: all 0.3s ease !important;
        }
        .btn-danger:hover {
            background: #c0392b !important;
            transform: translateY(-2px);
        }
        .btn-danger:disabled {
            background: #6c757d !important;
            cursor: not-allowed !important;
            opacity: 0.6 !important;
        }
        .actions-container {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .current-user-text {
            color: #6c757d;
            font-size: 12px;
            font-style: italic;
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
            vertical-align: middle;
        }
        .table tr:hover {
            background: #f9f9f9;
        }
        select {
            padding: 0.5rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
            transition: border-color 0.3s ease;
        }
        select:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
        }
        .btn.primary {
            background: var(--accent);
            color: var(--text-dark);
            border: 1px solid var(--primary);
            padding: 0.5rem 1rem;
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
            <h1>User Management</h1>
            <a href="../index.php" class="btn">← Back to Dashboard</a>
            </div>
        </div>
    
    <div class="container">

        <?php if ($flash): ?>
            <div class="alert success"><?php echo h($flash); ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo h($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>All Users</h2>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #007bff;">
                <strong>Deletion Policy:</strong> Users with active borrow records (reserved, borrowed, or overdue items) cannot be deleted. 
                Please ensure all items are returned before deleting a user account.
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Active Borrows</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo h($user['idnum']); ?></td>
                            <td><?php echo h($user['Fname'] . ' ' . $user['Lname']); ?></td>
                            <td><?php echo h($user['mail']); ?></td>
                            <td>
                                <form method="post" class="inline">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
                                    <select name="role" onchange="this.form.submit()">
                                        <option value="borrower" <?php echo $user['role']==='borrower'?'selected':''; ?>>Borrower</option>
                                        <option value="staff" <?php echo $user['role']==='staff'?'selected':''; ?>>Staff</option>
                                        <option value="admin" <?php echo $user['role']==='admin'?'selected':''; ?>>Admin</option>
                                    </select>
                                    <input type="hidden" name="update_role" value="1">
                                </form>
                            </td>
                            <td>
                                <?php if ($user['active_borrows'] > 0): ?>
                                    <span class="badge badge-orange"><?php echo (int)$user['active_borrows']; ?> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-green">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($user['created_at']); ?></td>
                            <td>
                                <div class="actions-container">
                                    <span class="badge <?php 
                                        echo $user['role']==='admin'?'badge-red':
                                            ($user['role']==='staff'?'badge-orange':'badge-green'); 
                                    ?>">
                                        <?php echo h(ucfirst($user['role'])); ?>
                                    </span>
                                    <?php if ($user['user_id'] != (current_user()['user_id'] ?? null)): ?>
                                        <?php if ($user['active_borrows'] > 0): ?>
                                            <button type="button" class="btn-danger" disabled title="Cannot delete user with active borrows">
                                                Delete (Blocked)
                                            </button>
                                        <?php else: ?>
                                            <form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete <?php echo h($user['Fname'] . ' ' . $user['Lname']); ?>? This action cannot be undone.');">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
                                                <button type="submit" name="delete_user" class="btn-danger">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="current-user-text">(Current User)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
