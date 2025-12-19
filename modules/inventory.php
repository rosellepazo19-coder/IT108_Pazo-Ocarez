<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication

// Only admin and staff can access inventory management
if (!in_array(current_role(), ['admin','staff'], true)) {
	$_SESSION['flash'] = 'You do not have permission to access this page.';
	header('Location: ../index.php');
	exit;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$isStaffOrAdmin = in_array(current_role(), ['admin','staff'], true);

// Handle create/update/delete for equipment and supplies
$action = req_str('action');
$type = req_str('type', 'equipment'); // 'equipment' or 'supplies'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$isStaffOrAdmin) {
		$errors[] = 'Only staff/admin can modify inventory.';
	} else {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$name = req_str('name');
		$description = req_str('description');
		$quantity = max(0, (int)req_int('quantity', 0));
		$status = req_str('status', 'available');
		// For equipment: rental fields, for supplies: purchase fields
		$daily_rate = max(0, (float)req_str('daily_rate', 0));
		$max_rental_days = max(1, (int)req_int('max_rental_days', 30));
		$late_fee_per_day = max(0, (float)req_str('late_fee_per_day', 0));
		$unit_price = max(0, (float)req_str('unit_price', 0)); // For supplies
		$id = req_int('id');
		$image_alt = req_str('image_alt');
		
		// Handle image upload
		$image_path = null;
		if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
			$upload_dir = '../uploads/inventory/';
			$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
			$max_size = 5 * 1024 * 1024; // 5MB
			
			$file_info = $_FILES['image'];
			
			// Validate file type
			if (!in_array($file_info['type'], $allowed_types)) {
				$errors[] = 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.';
			}
			// Validate file size
			elseif ($file_info['size'] > $max_size) {
				$errors[] = 'File too large. Maximum size is 5MB.';
			}
			// Validate file
			elseif (!getimagesize($file_info['tmp_name'])) {
				$errors[] = 'Invalid image file.';
			}
			else {
				// Generate unique filename
				$extension = pathinfo($file_info['name'], PATHINFO_EXTENSION);
				$filename = uniqid() . '_' . time() . '.' . $extension;
				$upload_path = $upload_dir . $filename;
				
				if (move_uploaded_file($file_info['tmp_name'], $upload_path)) {
					$image_path = 'uploads/inventory/' . $filename;
				} else {
					$errors[] = 'Failed to upload image.';
				}
			}
		}

		// Only validate name/status for create or update actions
		if (in_array($action, ['create','update'], true)) {
			if ($name === '') $errors[] = 'Name is required.';
			if ($status === '') $status = 'available';
			$validStatuses = ['available','unavailable','maintenance','reserved'];
			if (!in_array($status, $validStatuses, true)) $errors[] = 'Invalid status value.';
		}

		if (!$errors) {
			if ($type === 'equipment') {
				if ($action === 'create') {
					$stmt = $mysqli->prepare("INSERT INTO equipment(name,description,quantity,status,daily_rate,max_rental_days,late_fee_per_day,image_path,image_alt) VALUES (?,?,?,?,?,?,?,?,?)");
					$stmt->bind_param('ssisiiiss', $name, $description, $quantity, $status, $daily_rate, $max_rental_days, $late_fee_per_day, $image_path, $image_alt);
					$ok = $stmt->execute();
					if (!$ok) $errors[] = 'DB Error: ' . h($stmt->error);
					$stmt->close();
				} elseif ($action === 'update' && $id) {
					// Handle image update - only update image if new one uploaded
					if ($image_path) {
						$stmt = $mysqli->prepare("UPDATE equipment SET name=?, description=?, quantity=?, status=?, daily_rate=?, max_rental_days=?, late_fee_per_day=?, image_path=?, image_alt=? WHERE equip_id=?");
						$stmt->bind_param('ssisiiissi', $name, $description, $quantity, $status, $daily_rate, $max_rental_days, $late_fee_per_day, $image_path, $image_alt, $id);
					} else {
						$stmt = $mysqli->prepare("UPDATE equipment SET name=?, description=?, quantity=?, status=?, daily_rate=?, max_rental_days=?, late_fee_per_day=?, image_alt=? WHERE equip_id=?");
						$stmt->bind_param('ssisiiisi', $name, $description, $quantity, $status, $daily_rate, $max_rental_days, $late_fee_per_day, $image_alt, $id);
					}
					$ok = $stmt->execute();
					if (!$ok) $errors[] = 'DB Error: ' . h($stmt->error);
					$stmt->close();
				} elseif ($action === 'delete' && $id) {
					// Check if equipment has active borrow records
					$stmt = $mysqli->prepare("SELECT COUNT(*) FROM borrow_records WHERE item_type='equipment' AND item_id=? AND status IN ('reserved','borrowed','overdue')");
					$stmt->bind_param('i', $id);
					$stmt->execute();
					$stmt->bind_result($active_borrows);
					$stmt->fetch();
					$stmt->close();
					
					if ($active_borrows > 0) {
						$errors[] = "Cannot delete equipment. It has {$active_borrows} active borrow record(s). Please return all items first.";
					} else {
						// Get image path before deletion to remove file
						$stmt = $mysqli->prepare("SELECT image_path FROM equipment WHERE equip_id=?");
						$stmt->bind_param('i', $id);
						$stmt->execute();
						$result = $stmt->get_result()->fetch_assoc();
						$stmt->close();
						
						// Delete the record
						$stmt = $mysqli->prepare("DELETE FROM equipment WHERE equip_id=?");
						$stmt->bind_param('i', $id);
						$ok = $stmt->execute();
						if (!$ok) $errors[] = 'DB Error: ' . h($stmt->error);
						$stmt->close();
						
						// Remove image file if exists
						if ($result && $result['image_path'] && file_exists('../' . $result['image_path'])) {
							unlink('../' . $result['image_path']);
						}
					}
				}
			} else { // supplies - purchase system
				// Check if unit_price column exists, if not add it
				$column_check = $mysqli->query("SHOW COLUMNS FROM supplies LIKE 'unit_price'");
				if ($column_check->num_rows == 0) {
					$mysqli->query("ALTER TABLE supplies ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER daily_rate");
				}
				if ($column_check) $column_check->free();
				
				if ($action === 'create') {
					$stmt = $mysqli->prepare("INSERT INTO supplies(name,description,quantity,status,unit_price,image_path,image_alt) VALUES (?,?,?,?,?,?,?)");
					$stmt->bind_param('ssisiss', $name, $description, $quantity, $status, $unit_price, $image_path, $image_alt);
					$ok = $stmt->execute();
					if (!$ok) $errors[] = 'DB Error: ' . h($stmt->error);
					$stmt->close();
				} elseif ($action === 'update' && $id) {
					// Handle image update - only update image if new one uploaded
					if ($image_path) {
						$stmt = $mysqli->prepare("UPDATE supplies SET name=?, description=?, quantity=?, status=?, unit_price=?, image_path=?, image_alt=? WHERE supply_id=?");
						$stmt->bind_param('ssisissi', $name, $description, $quantity, $status, $unit_price, $image_path, $image_alt, $id);
					} else {
						$stmt = $mysqli->prepare("UPDATE supplies SET name=?, description=?, quantity=?, status=?, unit_price=?, image_alt=? WHERE supply_id=?");
						$stmt->bind_param('ssisisi', $name, $description, $quantity, $status, $unit_price, $image_alt, $id);
					}
					$ok = $stmt->execute();
					if (!$ok) $errors[] = 'DB Error: ' . h($stmt->error);
					$stmt->close();
				} elseif ($action === 'delete' && $id) {
					$mysqli->begin_transaction();
					$delete_errors = [];

					// Delete purchase payments only if table exists
					$table_exists = $mysqli->query("SHOW TABLES LIKE 'purchase_payments'");
					$has_purchase_payments_table = $table_exists && $table_exists->num_rows > 0;
					if ($table_exists) { $table_exists->free(); }

					if ($has_purchase_payments_table) {
						$stmt = $mysqli->prepare("
							DELETE pp FROM purchase_payments pp
							INNER JOIN purchase_records pr ON pp.purchase_id = pr.purchase_id
							WHERE pr.supply_id = ?
						");
						$stmt->bind_param('i', $id);
						if (!$stmt->execute()) {
							$delete_errors[] = 'Failed to delete purchase payments: ' . h($stmt->error);
						}
						$stmt->close();
					}

					// Remove purchase records (table exists in normal install)
					$table_exists = $mysqli->query("SHOW TABLES LIKE 'purchase_records'");
					$has_purchase_records_table = $table_exists && $table_exists->num_rows > 0;
					if ($table_exists) { $table_exists->free(); }

					if ($has_purchase_records_table) {
						$stmt = $mysqli->prepare("DELETE FROM purchase_records WHERE supply_id = ?");
						$stmt->bind_param('i', $id);
						if (!$stmt->execute()) {
							$delete_errors[] = 'Failed to delete purchase records: ' . h($stmt->error);
						}
						$stmt->close();
					}

					// Get image path before deleting supply
					$stmt = $mysqli->prepare("SELECT image_path FROM supplies WHERE supply_id=?");
					$stmt->bind_param('i', $id);
					$stmt->execute();
					$result = $stmt->get_result()->fetch_assoc();
					$stmt->close();

					// Delete supply record
					if (!$delete_errors) {
						$stmt = $mysqli->prepare("DELETE FROM supplies WHERE supply_id=?");
						$stmt->bind_param('i', $id);
						if (!$stmt->execute()) {
							$delete_errors[] = 'DB Error: ' . h($stmt->error);
						}
						$stmt->close();
					}

					if ($delete_errors) {
						$mysqli->rollback();
						$errors = array_merge($errors, $delete_errors);
					} else {
						$mysqli->commit();
						if ($result && $result['image_path'] && file_exists('../' . $result['image_path'])) {
							unlink('../' . $result['image_path']);
						}
					}
				}
			}

			if (!$errors) {
				$_SESSION['flash'] = 'Saved successfully.';
				header('Location: inventory.php?type=' . urlencode($type));
				exit;
			}
		}
	}
	}
}

// Fetch counts for pagination
$table = $type === 'supplies' ? 'supplies' : 'equipment';
$total = 0;
$res = $mysqli->query("SELECT COUNT(*) AS c FROM `$table`");
if ($res) { $row = $res->fetch_assoc(); $total = (int)$row['c']; $res->free(); }
list($page, $pages, $offset, $perPage) = paginate($total, 10);

// Fetch current page items with active borrow count
if ($type === 'equipment') {
	$stmtList = $mysqli->prepare("
		SELECT 
			e.*,
			COALESCE(active_borrows.count, 0) as active_borrows,
			CASE WHEN e.quantity <= 0 THEN 'unavailable' ELSE e.status END AS display_status
		FROM equipment e
		LEFT JOIN (
			SELECT item_id, COUNT(*) as count 
			FROM borrow_records 
			WHERE item_type = 'equipment' AND status IN ('reserved', 'borrowed', 'overdue')
			GROUP BY item_id
		) active_borrows ON e.equip_id = active_borrows.item_id
		ORDER BY e.name ASC LIMIT ? OFFSET ?
	");
} else {
	// Supplies are for purchase, not rental - no need to check active borrows
	$stmtList = $mysqli->prepare("
		SELECT 
			s.*,
			CASE WHEN s.quantity <= 0 THEN 'unavailable' ELSE s.status END AS display_status,
			0 as active_borrows
		FROM supplies s
		ORDER BY s.name ASC LIMIT ? OFFSET ?
	");
}
$stmtList->bind_param('ii', $perPage, $offset);
$stmtList->execute();
$items = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtList->close();

$editing = null;
if ($action === 'edit') {
	$editId = req_int('id');
	if ($editId) {
		if ($type === 'supplies') {
			$stmt = $mysqli->prepare("SELECT * FROM supplies WHERE supply_id=?");
			$stmt->bind_param('i', $editId);
		} else {
			$stmt = $mysqli->prepare("SELECT * FROM equipment WHERE equip_id=?");
			$stmt->bind_param('i', $editId);
		}
		$stmt->execute();
		$editing = $stmt->get_result()->fetch_assoc();
		$stmt->close();
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Inventory Management</title>
	<link rel="stylesheet" href="../assets/css/style.css">
	<link rel="stylesheet" href="../assets/css/image-lightbox.css">
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
		
		.tabs {
			display: flex;
			gap: 0.5rem;
			margin-bottom: 2rem;
			background: var(--secondary);
			padding: 0.5rem;
			border-radius: 15px;
			box-shadow: var(--shadow-hover);
		}
		
		.tab {
			flex: 1;
			padding: 0.75rem 1.5rem;
			background: transparent;
			border: 2px solid transparent;
			border-radius: 10px;
			text-decoration: none;
			color: var(--text-dark);
			text-align: center;
			transition: all 0.3s ease;
			font-weight: 500;
		}
		
		.tab:hover {
			background: rgba(255,255,255,0.3);
		}
		
		.tab.active {
			background: var(--primary);
			color: var(--text-light);
			border-color: var(--primary);
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
			margin-bottom: 1rem;
		}
		
		.btn.danger:disabled {
			background: #6c757d !important;
			cursor: not-allowed !important;
			opacity: 0.6 !important;
		}
		
		.badge-orange {
			background: #fd7e14;
			color: white;
			padding: 2px 8px;
			border-radius: 3px;
			font-size: 12px;
		}
		
		.badge-green {
			background: #28a745;
			color: white;
			padding: 2px 8px;
			border-radius: 3px;
			font-size: 12px;
		}
		
		input[type="text"], input[type="number"], textarea, select {
			background: #f9f9f9;
			border: 2px solid #ddd;
			border-radius: 8px;
			padding: 0.75rem;
			transition: border-color 0.3s ease;
		}
		
		input[type="text"]:focus, input[type="number"]:focus, textarea:focus, select:focus {
			outline: none;
			border-color: var(--primary);
			background: #fff;
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
		
		.btn {
			display: inline-block;
			padding: 0.5rem 1rem;
			border-radius: 8px;
			text-decoration: none;
			border: 1px solid #cccccc;
			background-color: #ffffff;
			color: #333333;
			cursor: pointer;
			transition: all 0.3s ease;
			font-weight: 500;
			box-sizing: border-box;
		}
		
		.btn.danger {
			background: #e74c3c;
			color: white;
			border: 1px solid #e74c3c;
		}
		
		.btn.small {
			padding: 0.5rem 1rem;
			font-size: 0.9rem;
			min-width: 85px;
			text-align: center;
		}
		
		.btn.small:hover {
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
		
		.alert {
			border-radius: 10px;
			padding: 1rem;
			margin-bottom: 1.5rem;
		}
		
		.info-box {
			background: #e8f1fd;
			border-left: 4px solid var(--primary);
			padding: 1rem;
			border-radius: 8px;
			margin-bottom: 1.5rem;
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1>Inventory Management</h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<div class="tabs">
			<a class="tab <?php echo $type==='equipment'?'active':''; ?>" href="inventory.php?type=equipment">Equipment</a>
			<a class="tab <?php echo $type==='supplies'?'active':''; ?>" href="inventory.php?type=supplies">Supplies</a>
		</div>

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

		<div class="grid">
		<?php if ($isStaffOrAdmin): ?>
		<div class="card">
			<h2><?php echo $editing? 'Edit ' : 'Add '; echo $type==='supplies'? 'Supply' : 'Equipment'; ?></h2>
			<form method="post" action="inventory.php?type=<?php echo h($type); ?>" enctype="multipart/form-data">
					<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
					<input type="hidden" name="action" value="<?php echo $editing? 'update':'create'; ?>">
					<input type="hidden" name="type" value="<?php echo h($type); ?>">
					<?php if ($editing): ?>
						<input type="hidden" name="id" value="<?php echo h($type==='supplies'?$editing['supply_id']:$editing['equip_id']); ?>">
					<?php endif; ?>

					<label>Name</label>
					<input type="text" name="name" value="<?php echo h($editing['name'] ?? ''); ?>" required>

					<label>Description</label>
					<textarea name="description" rows="3"><?php echo h($editing['description'] ?? ''); ?></textarea>

					<label>Quantity</label>
					<input type="number" name="quantity" min="0" value="<?php echo h($editing['quantity'] ?? 0); ?>" required>

					<label>Status</label>
					<select name="status">
						<?php
							$opts = ['available','unavailable','maintenance','reserved'];
							$cur = $editing['status'] ?? 'available';
							foreach ($opts as $opt) {
								$sel = $opt===$cur? 'selected' : '';
								echo '<option value="'.h($opt).'" '.$sel.'>'.h(ucfirst($opt)).'</option>';
							}
						?>
					</select>

					<?php if ($type === 'equipment'): ?>
						<label>Daily Rate (₱)</label>
						<input type="number" name="daily_rate" step="0.01" min="0" value="<?php echo h($editing['daily_rate'] ?? 0); ?>" required>
						<small>Rental rate per day</small>

						<label>Max Rental Days</label>
						<input type="number" name="max_rental_days" min="1" value="<?php echo h($editing['max_rental_days'] ?? 30); ?>" required>

						<label>Late Fee per Day (₱)</label>
						<input type="number" name="late_fee_per_day" step="0.01" min="0" value="<?php echo h($editing['late_fee_per_day'] ?? 0); ?>" required>
					<?php else: ?>
						<label>Unit Price (₱)</label>
						<input type="number" name="unit_price" step="0.01" min="0" value="<?php echo h($editing['unit_price'] ?? $editing['daily_rate'] ?? 0); ?>" required>
						<small>Price per unit for purchase</small>
					<?php endif; ?>

					<label>Image</label>
					<?php if ($editing && $editing['image_path']): ?>
						<div style="margin-bottom: 10px;">
							<img src="../<?php echo h($editing['image_path']); ?>" 
								alt="<?php echo h($editing['image_alt'] ?? $editing['name']); ?>" 
								class="image-clickable"
								data-image-path="../<?php echo h($editing['image_path']); ?>"
								data-image-name="<?php echo h($editing['name']); ?>"
								style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 5px; cursor: pointer;">
							<p style="font-size: 12px; color: #666; margin: 5px 0;">Current image (click to enlarge)</p>
						</div>
					<?php endif; ?>
					<input type="file" name="image" accept="image/*" style="margin-bottom: 10px;">
					<p style="font-size: 12px; color: #666; margin: 5px 0;">Upload JPEG, PNG, GIF, or WebP image (max 5MB)</p>

					<label>Image Alt Text</label>
					<input type="text" name="image_alt" value="<?php echo h($editing['image_alt'] ?? ''); ?>" placeholder="Describe the image for accessibility">

					<div class="form-actions">
						<button type="submit" class="btn primary"><?php echo $editing? 'Update':'Add'; ?></button>
						<?php if ($editing): ?>
							<a class="btn" href="inventory.php?type=<?php echo h($type); ?>">Cancel</a>
						<?php endif; ?>
					</div>
			</form>
		</div>
		<?php endif; ?>

			<div class="card">
				<h2>List of <?php echo $type==='supplies'? 'Supplies':'Equipment'; ?></h2>
				<div class="info-box">
					<?php if ($type === 'equipment'): ?>
						<strong>Deletion Policy:</strong> Items with active borrow records (reserved, borrowed, or overdue) cannot be deleted. 
						Please ensure all items are returned before deleting inventory items.
					<?php else: ?>
						<strong>Note:</strong> Supplies are for purchase, not rental. Users can purchase supplies directly.
					<?php endif; ?>
				</div>
				<table class="table">
					<thead>
						<tr>
							<th>Image</th>
							<th>Name</th>
							<th>Description</th>
							<th>Quantity</th>
							<th>Status</th>
							<?php if ($type === 'equipment'): ?>
								<th>Daily Rate</th>
								<th>Max Days</th>
								<th>Late Fee/Day</th>
								<th>Active Borrows</th>
							<?php else: ?>
								<th>Unit Price</th>
							<?php endif; ?>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($items as $it): ?>
							<tr>
								<td>
									<?php if ($it['image_path']): ?>
										<img src="../<?php echo h($it['image_path']); ?>" 
											alt="<?php echo h($it['image_alt'] ?? $it['name']); ?>" 
											class="image-clickable"
											data-image-path="../<?php echo h($it['image_path']); ?>"
											data-image-name="<?php echo h($it['name']); ?>"
											style="width: 60px; height: 60px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd;">
									<?php else: ?>
										<div style="width: 60px; height: 60px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 12px;">
											No Image
										</div>
									<?php endif; ?>
								</td>
								<td><?php echo h($it['name']); ?></td>
								<td><?php echo h($it['description']); ?></td>
								<td><?php echo h($it['quantity']); ?></td>
								<?php $display_status = $it['display_status'] ?? $it['status']; ?>
								<td><?php echo status_badge($display_status); ?></td>
								<?php if ($type === 'equipment'): ?>
									<td>₱<?php echo number_format($it['daily_rate'], 2); ?></td>
									<td><?php echo h($it['max_rental_days']); ?> days</td>
									<td>₱<?php echo number_format($it['late_fee_per_day'], 2); ?></td>
									<td>
										<?php if ($it['active_borrows'] > 0): ?>
											<span class="badge badge-orange"><?php echo (int)$it['active_borrows']; ?> Active</span>
										<?php else: ?>
											<span class="badge badge-green">0</span>
										<?php endif; ?>
									</td>
								<?php else: ?>
									<td>₱<?php echo number_format($it['unit_price'] ?? $it['daily_rate'] ?? 0, 2); ?></td>
								<?php endif; ?>
								<td>
									<?php if ($isStaffOrAdmin): ?>
									<div style="display: flex; gap: 0.5rem; align-items: center;">
									<a class="btn small" href="inventory.php?type=<?php echo h($type); ?>&action=edit&id=<?php echo h($type==='supplies'?$it['supply_id']:$it['equip_id']); ?>">Edit</a>
									<?php if ($type === 'equipment' && $it['active_borrows'] > 0): ?>
										<button type="button" class="btn danger small" disabled title="Cannot delete item with active borrows">
												Delete
										</button>
									<?php else: ?>
											<form method="post" action="inventory.php?type=<?php echo h($type); ?>" class="inline" onsubmit="return confirm('Delete this item?');" style="margin: 0;">
											<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
											<input type="hidden" name="type" value="<?php echo h($type); ?>">
											<input type="hidden" name="action" value="delete">
											<input type="hidden" name="id" value="<?php echo h($type==='supplies'?$it['supply_id']:$it['equip_id']); ?>">
											<button type="submit" class="btn danger small">Delete</button>
										</form>
									<?php endif; ?>
									</div>
									<?php else: ?>
									<span class="badge badge-gray">View only</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="pagination">
					<?php if ($page > 1): ?>
						<a class="page" href="inventory.php?type=<?php echo h($type); ?>&page=<?php echo $page-1; ?>">Prev</a>
					<?php endif; ?>
					<span class="page current">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
					<?php if ($page < $pages): ?>
						<a class="page" href="inventory.php?type=<?php echo h($type); ?>&page=<?php echo $page+1; ?>">Next</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Image Lightbox Modal -->
	<div id="imageLightbox" class="image-lightbox">
		<div class="image-lightbox-content">
			<span class="image-lightbox-close" onclick="closeLightbox()">&times;</span>
			<img id="lightboxImage" src="" alt="">
			<div class="image-lightbox-title" id="lightboxTitle"></div>
		</div>
	</div>

	<script src="../javascript/image-lightbox.js"></script>
</body>
</html>


