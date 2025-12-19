<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Require authentication
// All users can access

/**
 * Ensure borrowing triggers are updated to support multi-quantity reservations
 * and to prevent unsigned quantity underflow when admin/staff approve items.
 */
function ensure_borrow_triggers(mysqli $mysqli): void {
	static $checked = false;
	if ($checked) {
		return;
	}
	$checked = true;

	$triggers = [
		'trg_borrow_before_insert' => "
			CREATE TRIGGER `trg_borrow_before_insert`
			BEFORE INSERT ON `borrow_records`
			FOR EACH ROW
			BEGIN
				IF NEW.`status` = 'borrowed' AND NEW.`date_borrowed` IS NULL THEN
					SET NEW.`date_borrowed` = NOW();
				END IF;
			END
		",
		'trg_borrow_after_insert' => "
			CREATE TRIGGER `trg_borrow_after_insert`
			AFTER INSERT ON `borrow_records`
			FOR EACH ROW
			BEGIN
				IF NEW.`status` = 'borrowed' THEN
					IF NEW.`item_type` = 'equipment' THEN
						UPDATE `equipment`
						SET 
							`quantity` = CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END,
							`status` = CASE WHEN (CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END) <= 0 THEN 'unavailable' ELSE `status` END
						WHERE `equip_id` = NEW.`item_id`;
					ELSEIF NEW.`item_type` = 'supplies' THEN
						UPDATE `supplies`
						SET 
							`quantity` = CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END,
							`status` = CASE WHEN (CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END) <= 0 THEN 'unavailable' ELSE `status` END
						WHERE `supply_id` = NEW.`item_id`;
					END IF;
				END IF;
			END
		",
		'trg_borrow_after_update' => "
			CREATE TRIGGER `trg_borrow_after_update`
			AFTER UPDATE ON `borrow_records`
			FOR EACH ROW
			BEGIN
				IF NEW.`status` = 'borrowed' AND OLD.`status` = 'reserved' THEN
					IF NEW.`item_type` = 'equipment' THEN
						UPDATE `equipment`
						SET 
							`quantity` = CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END,
							`status` = CASE WHEN (CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END) <= 0 THEN 'unavailable' ELSE `status` END
						WHERE `equip_id` = NEW.`item_id`;
					ELSEIF NEW.`item_type` = 'supplies' THEN
						UPDATE `supplies`
						SET 
							`quantity` = CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END,
							`status` = CASE WHEN (CASE WHEN `quantity` > 0 THEN `quantity` - 1 ELSE 0 END) <= 0 THEN 'unavailable' ELSE `status` END
						WHERE `supply_id` = NEW.`item_id`;
					END IF;
				END IF;

				IF NEW.`status` = 'returned' AND OLD.`status` <> 'returned' THEN
					IF NEW.`item_type` = 'equipment' THEN
						UPDATE `equipment`
						SET 
							`quantity` = `quantity` + 1,
							`status` = 'available'
						WHERE `equip_id` = NEW.`item_id`;
					ELSEIF NEW.`item_type` = 'supplies' THEN
						UPDATE `supplies`
						SET 
							`quantity` = `quantity` + 1,
							`status` = 'available'
						WHERE `supply_id` = NEW.`item_id`;
					END IF;
				END IF;
			END
		",
	];

	foreach ($triggers as $name => $createSql) {
		if (!$mysqli->query("DROP TRIGGER IF EXISTS `$name`")) {
			error_log("Failed to drop trigger {$name}: " . $mysqli->error);
			continue;
		}
		if (!$mysqli->query($createSql)) {
			error_log("Failed to create trigger {$name}: " . $mysqli->error);
		}
	}
}

ensure_borrow_triggers($mysqli);

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Load users for selection (simple list; in production, filter by role if needed)
$users = [];
$resU = $mysqli->query("SELECT user_id, CONCAT(Fname, ' ', Lname) AS name FROM users ORDER BY Fname, Lname");
if ($resU) { $users = $resU->fetch_all(MYSQLI_ASSOC); $resU->free(); }

// Build items list - only equipment (supplies are for purchase, not rental)
// We calculate available quantity by subtracting currently borrowed/reserved items
// Show items where available quantity > 0
$items = [];
$resEq = $mysqli->query("
	SELECT 
		'equipment' AS item_type, 
		e.equip_id AS item_id, 
		e.name, 
		(e.quantity + COALESCE(br_counts.active_borrowed, 0)) AS total_quantity,
		COALESCE(br_counts.active_borrowed, 0) AS borrowed_count,
		COALESCE(br_counts.reserved_count, 0) AS reserved_count,
		GREATEST(e.quantity - COALESCE(br_counts.reserved_count, 0), 0) AS quantity,
		e.daily_rate, 
		e.late_fee_per_day, 
		e.image_path, 
		e.image_alt 
	FROM equipment e
	LEFT JOIN (
		SELECT 
			item_id,
			SUM(CASE WHEN status IN ('borrowed','overdue') THEN 1 ELSE 0 END) AS active_borrowed,
			SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved_count
		FROM borrow_records
		WHERE item_type = 'equipment' AND status IN ('reserved','borrowed','overdue')
		GROUP BY item_id
	) br_counts ON br_counts.item_id = e.equip_id
	WHERE e.status = 'available' 
	AND GREATEST(e.quantity - COALESCE(br_counts.reserved_count, 0), 0) > 0
	ORDER BY e.name
");
if ($resEq) { while ($r=$resEq->fetch_assoc()) $items[]=$r; $resEq->free(); }
// Supplies removed - they are for purchase only, not rental

// Handle create reservation/borrow
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = req_str('csrf_token');
	if (!csrf_check($token)) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$op = req_str('op','create');
		if ($op === 'create') {
			// Policy: only borrowers can create borrow/reserve transactions
			if (current_role() !== 'borrower') {
				$errors[] = 'Only borrowers can create borrow/reservation.';
			} else {
            $user_id = req_int('user_id');
            $item_type = req_str('item_type');
            $item_id = req_int('item_id');
			$rental_days = req_int('rental_days'); // number of days to rent
			$quantity = req_int('quantity', 1); // quantity of items to borrow
			$status = req_str('status','reserved'); // reserved|borrowed
			$remarks = req_str('remarks');

            // Force borrower submissions to be reservations under their own account
            if (current_role() === 'borrower') {
                $status = 'reserved';
                $cu = current_user();
                if ($cu) { $user_id = (int)$cu['user_id']; }
            }

            if (!$user_id) $errors[] = 'User is required.';
            // Auto-detect item type if missing - only check equipment (supplies are for purchase)
            if (!$item_type || $item_type !== 'equipment') {
                if ($item_id) {
                    $chk = $mysqli->prepare("SELECT 'equipment' AS t FROM equipment WHERE equip_id=? LIMIT 1");
                    $chk->bind_param('i', $item_id);
                    $chk->execute();
                    $rs = $chk->get_result();
                    if ($rs && $row = $rs->fetch_row()) { 
                        $item_type = $row[0]; 
                    } else {
                        $errors[] = 'Item not found or is not available for rental. Only equipment can be borrowed.';
                    }
                    $chk->close();
                }
            }
            // Only equipment can be borrowed - supplies are for purchase
            if ($item_type !== 'equipment') {
                $errors[] = 'Only equipment can be borrowed. Supplies are available for purchase through admin/staff.';
            }
			if (!$item_id) $errors[] = 'Item is required.';
			if (!$rental_days || $rental_days < 1) $errors[] = 'Number of days is required and must be at least 1.';
			if (!$quantity || $quantity < 1) $errors[] = 'Quantity is required and must be at least 1.';
			if (!in_array($status, ['reserved','borrowed'], true)) $errors[] = 'Invalid status.';
			
			// Validate quantity doesn't exceed available stock (total - currently borrowed)
			if (!$errors && $item_type === 'equipment') {
				$stock_check = $mysqli->prepare("
					SELECT 
						(e.quantity + COALESCE(br_counts.active_borrowed, 0)) AS total_quantity,
						COALESCE(br_counts.active_borrowed, 0) AS borrowed_count,
						COALESCE(br_counts.reserved_count, 0) AS reserved_count,
						GREATEST(e.quantity - COALESCE(br_counts.reserved_count, 0), 0) AS available_quantity
					FROM equipment e
					LEFT JOIN (
						SELECT 
							item_id,
							SUM(CASE WHEN status IN ('borrowed','overdue') THEN 1 ELSE 0 END) AS active_borrowed,
							SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved_count
						FROM borrow_records
						WHERE item_type = 'equipment' AND status IN ('reserved','borrowed','overdue')
						GROUP BY item_id
					) br_counts ON br_counts.item_id = e.equip_id
					WHERE e.equip_id = ? AND e.status = 'available'
				");
				$stock_check->bind_param('i', $item_id);
				$stock_check->execute();
				$stock_result = $stock_check->get_result();
				if ($stock_row = $stock_result->fetch_assoc()) {
					$available_quantity = (int)$stock_row['available_quantity'];
					if ($quantity > $available_quantity) {
						$errors[] = "Requested quantity ({$quantity}) exceeds available stock ({$available_quantity}).";
					}
				} else {
					$errors[] = 'Item not found or not available.';
				}
				$stock_check->close();
			}

				if (!$errors) {
				// Calculate due date based on rental days from current time
				$due_dt = date('Y-m-d H:i:s', strtotime("+{$rental_days} days"));
				
				// Get daily rate from item BEFORE creating borrow record
				$daily_rate = 0.00;
				if ($item_type === 'equipment') {
					$stmt_rate = $mysqli->prepare("SELECT daily_rate FROM equipment WHERE equip_id = ?");
					$stmt_rate->bind_param('i', $item_id);
					$stmt_rate->execute();
					$result_rate = $stmt_rate->get_result();
					if ($row_rate = $result_rate->fetch_assoc()) {
						$daily_rate = (float)$row_rate['daily_rate'];
					}
					$stmt_rate->close();
				} else if ($item_type === 'supplies') {
					$stmt_rate = $mysqli->prepare("SELECT daily_rate FROM supplies WHERE supply_id = ?");
					$stmt_rate->bind_param('i', $item_id);
					$stmt_rate->execute();
					$result_rate = $stmt_rate->get_result();
					if ($row_rate = $result_rate->fetch_assoc()) {
						$daily_rate = (float)$row_rate['daily_rate'];
					}
					$stmt_rate->close();
				}
				
				// Validate that daily_rate was retrieved
				if ($daily_rate <= 0) {
					$errors[] = 'Invalid daily rate (' . $daily_rate . '). Please ensure the item has a valid daily rate set in inventory.';
				}
				
				// Validate rental_days
				if ($rental_days <= 0) {
					$errors[] = 'Invalid rental days. Please select a valid number of days.';
				}
				
				// Calculate total rental amount and amount due
				// Formula: daily_rate * rental_days * quantity = total_amount_due
				$total_rental_amount = round((float)$daily_rate * (int)$rental_days * (int)$quantity, 2);
				$total_amount_due = $total_rental_amount; // No late fee yet
				
				// Debug: Log calculation for troubleshooting
				error_log("Borrow Calculation - Item ID: $item_id, Type: $item_type, Daily Rate: $daily_rate, Rental Days: $rental_days, Quantity: $quantity, Total: $total_rental_amount");
				
				// Add error if calculation seems wrong
				if ($total_rental_amount <= 0) {
					$errors[] = 'Calculation error: Daily rate (' . $daily_rate . ') × Days (' . $rental_days . ') × Quantity (' . $quantity . ') = ' . $total_rental_amount . '. Please check item pricing.';
				}
				
				// Set date_borrowed based on status (NULL for reserved, current time for borrowed)
				$date_borrowed_val = ($status === 'borrowed') ? date('Y-m-d H:i:s') : NULL;
				
				// Create borrow records with pricing information (one record per quantity)
				if (!$errors) {
					$success_count = 0;
					// Calculate amount per item (not total)
					$amount_per_item = round((float)$daily_rate * (int)$rental_days, 2);
					
					for ($q = 0; $q < $quantity; $q++) {
						if ($date_borrowed_val === NULL) {
							// For reserved items, date_borrowed is NULL
							$stmt = $mysqli->prepare("
								INSERT INTO borrow_records (user_id, item_id, item_type, date_borrowed, due_date, status, remarks, daily_rate, total_rental_amount, total_amount_due)
								VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
							");
							$stmt->bind_param('iissssddd', $user_id, $item_id, $item_type, $due_dt, $status, $remarks, $daily_rate, $amount_per_item, $amount_per_item);
						} else {
							// For borrowed items, date_borrowed is set
							$stmt = $mysqli->prepare("
								INSERT INTO borrow_records (user_id, item_id, item_type, date_borrowed, due_date, status, remarks, daily_rate, total_rental_amount, total_amount_due)
								VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
							");
							$stmt->bind_param('iisssssddd', $user_id, $item_id, $item_type, $date_borrowed_val, $due_dt, $status, $remarks, $daily_rate, $amount_per_item, $amount_per_item);
						}
						
						$ok = $stmt->execute();
						if (!$ok) {
							// Check if it's a duplicate error from database constraint
							if (strpos($stmt->error, 'Duplicate') !== false || strpos($stmt->error, 'duplicate') !== false) {
								$errors[] = 'You already have an active reservation or borrow for this item. Please complete or cancel your existing reservation/borrow before creating a new one.';
								break; // Stop creating more records
							} else {
								$errors[] = 'DB Error: ' . h($stmt->error);
								break; // Stop creating more records
							}
							error_log("Borrow Insert Error: " . $stmt->error);
						} else {
							$success_count++;
							// Verify the inserted values
							$inserted_id = $mysqli->insert_id;
							// Verify the inserted values match what we calculated
							$verify_stmt = $mysqli->prepare("SELECT daily_rate, total_rental_amount, total_amount_due FROM borrow_records WHERE borrow_id = ?");
							$verify_stmt->bind_param('i', $inserted_id);
							$verify_stmt->execute();
							$verify_result = $verify_stmt->get_result();
							if ($verify_row = $verify_result->fetch_assoc()) {
								$inserted_daily_rate = (float)$verify_row['daily_rate'];
								$inserted_total_rental = (float)$verify_row['total_rental_amount'];
								$inserted_total_due = (float)$verify_row['total_amount_due'];
								
								error_log("Inserted Values - daily_rate: $inserted_daily_rate, total_rental_amount: $inserted_total_rental, total_amount_due: $inserted_total_due");
								
								// Check if values match
								if (abs($inserted_daily_rate - $daily_rate) > 0.01) {
									error_log("WARNING: Daily rate mismatch! Expected: $daily_rate, Got: $inserted_daily_rate");
								}
								if (abs($inserted_total_rental - $amount_per_item) > 0.01) {
									error_log("WARNING: Total rental amount mismatch! Expected: $amount_per_item, Got: $inserted_total_rental");
								}
								if (abs($inserted_total_due - $amount_per_item) > 0.01) {
									error_log("WARNING: Total amount due mismatch! Expected: $amount_per_item, Got: $inserted_total_due");
								}
							}
							$verify_stmt->close();
						}
						$stmt->close();
					}
					
					// If some records were created but not all, add a warning
					if ($success_count > 0 && $success_count < $quantity) {
						$errors[] = "Only {$success_count} out of {$quantity} items were successfully reserved. Please check your existing reservations.";
					}
				}
				
                if (!$errors) {
                    $_SESSION['flash'] = 'Transaction saved.';
                    header('Location: borrow.php');
                    exit;
                }
            }
			}
        } elseif ($op === 'update_status') {
            $borrow_id = req_int('borrow_id');
			$new_status = req_str('new_status'); // borrowed|cancelled
			if (!$borrow_id) $errors[] = 'Invalid borrow record.';
			if (!in_array($new_status, ['borrowed','cancelled'], true)) $errors[] = 'Invalid transition.';
            
            // Check permissions
            $current_user_id = current_user()['user_id'];
            $current_role = current_role();
            
            if (!$errors) {
                // Get the borrow record to find the group (user_id, item_id, item_type, due_date, status)
                $stmt = $mysqli->prepare("SELECT user_id, item_id, item_type, due_date, status FROM borrow_records WHERE borrow_id = ?");
                $stmt->bind_param('i', $borrow_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $borrow = $result->fetch_assoc();
                $stmt->close();
                
                if (!$borrow) {
                    $errors[] = 'Borrow record not found.';
                } else {
                    // Verify ownership if borrower is trying to cancel
                    if ($new_status === 'cancelled' && $current_role === 'borrower') {
                        if ($borrow['user_id'] != $current_user_id) {
                            $errors[] = 'You can only cancel your own reservations.';
                        } elseif ($borrow['status'] !== 'reserved') {
                            $errors[] = 'You can only cancel reservations that are still pending.';
                        }
                    } elseif ($new_status === 'borrowed' && !in_array($current_role, ['admin','staff'])) {
                        $errors[] = 'Only admin/staff can approve reservations.';
                    } elseif ($new_status === 'cancelled' && !in_array($current_role, ['admin','staff'])) {
                        // This case is already handled above for borrowers
                    }
                }
            }
            
            if (!$errors && isset($borrow)) {
				// Update all records in the same group (same user, item, due_date, and status)
				// This handles quantity > 1 cases where multiple records were created
				if ($new_status === 'borrowed') {
					$stmt = $mysqli->prepare("
						UPDATE borrow_records 
						SET status = ?, date_borrowed = CASE WHEN date_borrowed IS NULL THEN NOW() ELSE date_borrowed END
						WHERE user_id = ? AND item_id = ? AND item_type = ? AND due_date = ? AND status = 'reserved'
					");
					$stmt->bind_param('siiss', $new_status, $borrow['user_id'], $borrow['item_id'], $borrow['item_type'], $borrow['due_date']);
				} else {
					$stmt = $mysqli->prepare("
						UPDATE borrow_records 
						SET status = ? 
						WHERE user_id = ? AND item_id = ? AND item_type = ? AND due_date = ? AND status = 'reserved'
					");
					$stmt->bind_param('siiss', $new_status, $borrow['user_id'], $borrow['item_id'], $borrow['item_type'], $borrow['due_date']);
				}
				$ok = $stmt->execute();
				$affected_rows = $mysqli->affected_rows;
				$stmt->close();
				
				if (!$ok) {
					$errors[] = 'DB Error: ' . h($mysqli->error);
				} elseif ($affected_rows === 0) {
					$errors[] = 'No reservation found or status already changed. Please refresh the page.';
				} else {
					// When approving reservation, DON'T recalculate amounts
					// The amounts are already correctly calculated when the borrow was created: daily_rate * rental_days
					// We should preserve the original values that were set during creation
					// Only update daily_rate if it's missing (0 or NULL) - update all records in the group
					if ($new_status === 'borrowed' && isset($borrow)) {
						// Only fix daily_rate if it's 0 or missing, but preserve the amounts
						$fix_rate_stmt = $mysqli->prepare("
							UPDATE borrow_records br
							LEFT JOIN equipment e ON br.item_type = 'equipment' AND br.item_id = e.equip_id
							LEFT JOIN supplies s ON br.item_type = 'supplies' AND br.item_id = s.supply_id
							SET br.daily_rate = CASE 
								WHEN br.daily_rate = 0 OR br.daily_rate IS NULL THEN
									CASE 
										WHEN br.item_type = 'equipment' THEN COALESCE(e.daily_rate, br.daily_rate)
										WHEN br.item_type = 'supplies' THEN COALESCE(s.daily_rate, br.daily_rate)
										ELSE br.daily_rate
									END
								ELSE br.daily_rate
							END
							WHERE br.user_id = ? AND br.item_id = ? AND br.item_type = ? AND br.due_date = ? 
							AND br.status = ? AND (br.daily_rate = 0 OR br.daily_rate IS NULL)
						");
						$fix_rate_stmt->bind_param('iisss', $borrow['user_id'], $borrow['item_id'], $borrow['item_type'], $borrow['due_date'], $new_status);
						$fix_rate_stmt->execute();
						$fix_rate_stmt->close();
						
						// IMPORTANT: Do NOT recalculate total_rental_amount or total_amount_due
						// These are already set correctly when the borrow record was created
						// Recalculating would change the agreed-upon rental amount
					}
					
					// Set success message and redirect
					if ($new_status === 'cancelled' && $current_role === 'borrower') {
						$_SESSION['flash'] = 'Reservation cancelled successfully.';
					} elseif ($new_status === 'cancelled' && in_array($current_role, ['admin','staff'])) {
						$_SESSION['flash'] = 'Reservation declined successfully.';
					} elseif ($new_status === 'borrowed') {
						$_SESSION['flash'] = 'Reservation approved successfully.';
					}
					
					header('Location: borrow.php');
					exit;
				}
			}
		}
	}
}

// List current active/reserved borrows with pricing information
// Group by user, item, due_date, and status to show quantity
$current = [];
if (current_role() === 'borrower') {
	// Borrowers see only their own borrows
	$current_user_id = current_user()['user_id'] ?? null;
	if ($current_user_id) {
		$stmt = $mysqli->prepare("
			SELECT 
				MIN(b.borrow_id) AS borrow_id,
				b.user_id,
				CONCAT(u.Fname, ' ', u.Lname) AS user_name,
				b.item_type,
				b.item_id,
				MAX(b.remarks) AS remarks,
				COUNT(*) AS quantity,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_name,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_path,
				CASE
					WHEN b.item_type='equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id=b.item_id)
					WHEN b.item_type='supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id=b.item_id)
				END AS item_image_alt,
				MIN(b.date_borrowed) AS date_borrowed,
				b.due_date,
				b.status,
				COALESCE(MAX(b.daily_rate), 0.00) AS daily_rate,
				SUM(COALESCE(b.total_rental_amount, 0.00)) AS total_rental_amount,
				SUM(COALESCE(b.late_fee_amount, 0.00)) AS late_fee_amount,
				SUM(COALESCE(b.total_amount_due, 0.00)) AS total_amount_due,
				COALESCE((
					SELECT SUM(p.amount) 
					FROM payments p 
					INNER JOIN borrow_records br ON p.borrow_id = br.borrow_id
					WHERE br.user_id = b.user_id 
					AND br.item_id = b.item_id 
					AND br.item_type = b.item_type
					AND br.due_date = b.due_date
					AND br.status = b.status
					AND (p.payment_status = 'confirmed' OR p.payment_status IS NULL)
				), 0.00) AS total_confirmed_paid
			FROM borrow_records b
			JOIN users u ON u.user_id = b.user_id
			WHERE b.user_id = ? AND b.status IN ('reserved','borrowed','overdue')
			GROUP BY b.user_id, b.item_id, b.item_type, b.due_date, b.status
			ORDER BY b.due_date
		");
		$stmt->bind_param('i', $current_user_id);
		$stmt->execute();
		$result = $stmt->get_result();
		$current = $result->fetch_all(MYSQLI_ASSOC);
		$stmt->close();
		
		// Recalculate late fees for overdue items before displaying
		foreach ($current as &$item) {
			if (in_array($item['status'], ['borrowed', 'overdue']) && strtotime($item['due_date']) < time()) {
				// Get late_fee_per_day from item
				$late_fee_per_day = 0.00;
				if ($item['item_type'] === 'equipment') {
					$fee_stmt = $mysqli->prepare("SELECT late_fee_per_day FROM equipment WHERE equip_id = ?");
					$fee_stmt->bind_param('i', $item['item_id']);
					$fee_stmt->execute();
					$fee_result = $fee_stmt->get_result();
					if ($fee_row = $fee_result->fetch_assoc()) {
						$late_fee_per_day = (float)$fee_row['late_fee_per_day'];
					}
					$fee_stmt->close();
				} else if ($item['item_type'] === 'supplies') {
					$fee_stmt = $mysqli->prepare("SELECT late_fee_per_day FROM supplies WHERE supply_id = ?");
					$fee_stmt->bind_param('i', $item['item_id']);
					$fee_stmt->execute();
					$fee_result = $fee_stmt->get_result();
					if ($fee_row = $fee_result->fetch_assoc()) {
						$late_fee_per_day = (float)$fee_row['late_fee_per_day'];
					}
					$fee_stmt->close();
				}
				
				if ($late_fee_per_day > 0) {
					$due_date = strtotime($item['due_date']);
					$current_date = time();
					$late_days = max(0, floor(($current_date - $due_date) / 86400));
					$calculated_late_fee = round($late_fee_per_day * $late_days, 2);
					$new_total_due = (float)$item['total_rental_amount'] + $calculated_late_fee;
					
					// Update in database
					$update_stmt = $mysqli->prepare("UPDATE borrow_records SET late_fee_amount = ?, total_amount_due = ? WHERE borrow_id = ?");
					$update_stmt->bind_param('ddi', $calculated_late_fee, $new_total_due, $item['borrow_id']);
					$update_stmt->execute();
					$update_stmt->close();
					
					// Update local array
					$item['late_fee_amount'] = $calculated_late_fee;
					$item['total_amount_due'] = $new_total_due;
					
					// Mark as overdue if not already
					if ($item['status'] === 'borrowed') {
						$item['status'] = 'overdue';
					}
				}
			}
		}
		unset($item); // Break reference
	}
} else {
	// Admin/Staff see all borrows - grouped by user, item, due_date, and status to show quantity
	$resC = $mysqli->query("
		SELECT 
			MIN(b.borrow_id) AS borrow_id,
			b.user_id,
			CONCAT(u.Fname, ' ', u.Lname) AS user_name,
			b.item_type,
			b.item_id,
			MAX(b.remarks) AS remarks,
			COUNT(*) AS quantity,
			CASE
				WHEN b.item_type='equipment' THEN (SELECT e.name FROM equipment e WHERE e.equip_id=b.item_id)
				WHEN b.item_type='supplies' THEN (SELECT s.name FROM supplies s WHERE s.supply_id=b.item_id)
			END AS item_name,
			CASE
				WHEN b.item_type='equipment' THEN (SELECT e.image_path FROM equipment e WHERE e.equip_id=b.item_id)
				WHEN b.item_type='supplies' THEN (SELECT s.image_path FROM supplies s WHERE s.supply_id=b.item_id)
			END AS item_image_path,
			CASE
				WHEN b.item_type='equipment' THEN (SELECT e.image_alt FROM equipment e WHERE e.equip_id=b.item_id)
				WHEN b.item_type='supplies' THEN (SELECT s.image_alt FROM supplies s WHERE s.supply_id=b.item_id)
			END AS item_image_alt,
			MIN(b.date_borrowed) AS date_borrowed,
			b.due_date,
			b.status,
			COALESCE(MAX(b.daily_rate), 0.00) AS daily_rate,
			SUM(COALESCE(b.total_rental_amount, 0.00)) AS total_rental_amount,
			SUM(COALESCE(b.late_fee_amount, 0.00)) AS late_fee_amount,
			SUM(COALESCE(b.total_amount_due, 0.00)) AS total_amount_due,
			COALESCE((
				SELECT SUM(p.amount) 
				FROM payments p 
				INNER JOIN borrow_records br ON p.borrow_id = br.borrow_id
				WHERE br.user_id = b.user_id 
				AND br.item_id = b.item_id 
				AND br.item_type = b.item_type
				AND br.due_date = b.due_date
				AND br.status = b.status
				AND (p.payment_status = 'confirmed' OR p.payment_status IS NULL)
			), 0.00) AS total_confirmed_paid
		FROM borrow_records b
		JOIN users u ON u.user_id = b.user_id
		WHERE b.status IN ('reserved','borrowed','overdue')
		GROUP BY b.user_id, b.item_id, b.item_type, b.due_date, b.status
		ORDER BY b.due_date
	");
	if ($resC) { $current = $resC->fetch_all(MYSQLI_ASSOC); $resC->free(); }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo current_role() === 'borrower' ? 'My Borrows' : 'Borrow / Reserve Items'; ?></title>
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
			margin-bottom: 1rem;
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
		
		.container {
			max-width: 1400px;
		}
		
		.grid {
			display: flex;
			flex-direction: column;
			gap: 2rem;
		}
		
		.card {
			background: var(--secondary);
			border-radius: 12px;
			padding: 1.5rem;
			box-shadow: var(--shadow-hover);
			margin-bottom: 0;
		}
		
		.card:first-of-type {
			margin-bottom: 1rem;
		}
		
		.card h2 {
			color: var(--primary);
			margin-top: 0;
			margin-bottom: 1rem;
			font-size: 1.1rem;
		}
		
		label {
			display: block;
			margin-top: 0.75rem;
			margin-bottom: 0.35rem;
			font-weight: 600;
			color: var(--text-dark);
			font-size: 0.9rem;
		}
		
		input[type="text"], input[type="number"], select, textarea {
			width: 100%;
			padding: 0.5rem 0.75rem;
			border: 2px solid #ddd;
			border-radius: 6px;
			background: #f9f9f9;
			transition: border-color 0.3s ease;
			font-size: 0.9rem;
		}
		
		input[type="text"]:focus, input[type="number"]:focus, select:focus, textarea:focus {
			outline: none;
			border-color: var(--primary);
			background: #fff;
		}
		
		input[type="text"]:disabled {
			background: #e9ecef;
			cursor: not-allowed;
		}
		
		textarea {
			resize: vertical;
			min-height: 60px;
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
			font-size: 1rem;
		}
		
		.btn.primary:hover {
			background: var(--primary);
			color: var(--text-light);
			transform: translateY(-2px);
		}
		
		.btn.danger {
			background: #e74c3c;
			color: white;
			border: 1px solid #e74c3c;
		}
		
		.btn.danger:hover {
			background: #c0392b;
			transform: translateY(-2px);
		}
		
		.btn.small {
			padding: 0.5rem 1rem;
			font-size: 0.9rem;
		}
		
		.form-actions {
			display: flex;
			gap: 0.5rem;
			margin-top: 1rem;
			flex-wrap: wrap;
		}
		
		.form-actions .btn {
			padding: 0.5rem 1rem;
			font-size: 0.9rem;
		}
		
		.table {
			background: white;
			border-radius: 10px;
			overflow: hidden;
		}
		
		.table th {
			background: var(--primary);
			color: var(--text-light);
			padding: 0.6rem 0.75rem;
			font-weight: 600;
			text-align: left;
			font-size: 0.85rem;
		}
		
		.table td {
			padding: 0.6rem 0.75rem;
			border-bottom: 1px solid #f0f0f0;
			font-size: 0.85rem;
		}
		
		.table tr:hover {
			background: #f9f9f9;
		}
		
		.table tr:last-child td {
			border-bottom: none;
		}
		
		.status-badge {
			display: inline-block;
			padding: 0.2rem 0.6rem;
			border-radius: 12px;
			font-size: 0.75rem;
			font-weight: 600;
			text-transform: capitalize;
		}
		
		.status-reserved {
			background: #fff3cd;
			color: #856404;
		}
		
		.status-borrowed {
			background: #d4edda;
			color: #155724;
		}
		
		.status-overdue {
			background: #f8d7da;
			color: #721c24;
		}
		
		.status-returned {
			background: #d1ecf1;
			color: #0c5460;
		}
		
		.alert {
			border-radius: 8px;
			padding: 0.75rem;
			margin-bottom: 1rem;
			font-size: 0.9rem;
		}
		
		.due-date-preview {
			margin-top: 0.35rem;
			padding: 0.5rem 0.75rem;
			background: #e8f5e9;
			border-left: 4px solid var(--primary);
			border-radius: 6px;
			color: var(--text-dark);
			font-weight: 500;
			font-size: 0.85rem;
		}
		
		.inline {
			display: inline-block;
		}
		
		.badge {
			display: inline-block;
			padding: 0.25rem 0.5rem;
			border-radius: 4px;
			font-size: 0.85rem;
		}
		
		.badge-gray {
			background: #e9ecef;
			color: #6c757d;
		}
		
		.btn.small {
			padding: 0.4rem 0.75rem;
			font-size: 0.8rem;
		}
		
		.products-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
			gap: 1.5rem;
			margin-bottom: 2rem;
		}
		
		.product-card {
			background: white;
			border-radius: 12px;
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			transition: transform 0.3s ease, box-shadow 0.3s ease;
			display: flex;
			flex-direction: column;
			border: 2px solid transparent;
			cursor: pointer;
		}
		
		.product-card:hover {
			transform: translateY(-4px);
			box-shadow: 0 8px 20px rgba(0,0,0,0.15);
		}
		
		.product-card.selected {
			border-color: var(--primary);
			box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
		}
		
		.product-image {
			width: 100%;
			height: 180px;
			object-fit: cover;
			background: #f5f5f5;
		}
		
		.product-image-placeholder {
			width: 100%;
			height: 180px;
			background: linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%);
			display: flex;
			align-items: center;
			justify-content: center;
			color: #999;
			font-size: 0.85rem;
		}
		
		.product-info {
			padding: 1rem;
			flex: 1;
			display: flex;
			flex-direction: column;
		}
		
		.product-name {
			font-size: 1rem;
			font-weight: 600;
			margin: 0 0 0.5rem 0;
			color: var(--text-dark);
			line-height: 1.4;
		}
		
		.product-description {
			font-size: 0.85rem;
			color: #666;
			margin-bottom: 0.75rem;
			line-height: 1.4;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			overflow: hidden;
		}
		
		.product-details {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-top: auto;
			padding-top: 0.75rem;
			border-top: 1px solid #f0f0f0;
		}
		
		.product-price {
			font-size: 1.1rem;
			font-weight: 700;
			color: var(--primary);
		}
		
		.product-price-label {
			font-size: 0.7rem;
			color: #666;
			display: block;
		}
		
		.product-stock {
			font-size: 0.8rem;
			color: #666;
		}
		
		.product-stock.in-stock {
			color: #28a745;
			font-weight: 600;
		}
		
		.selected-badge {
			background: var(--primary);
			color: white;
			padding: 0.25rem 0.5rem;
			border-radius: 4px;
			font-size: 0.75rem;
			font-weight: 600;
			margin-top: 0.5rem;
			text-align: center;
		}
		
		@media (max-width: 768px) {
			.card {
				padding: 1rem;
			}
			.products-grid {
				grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
				gap: 1rem;
			}
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="container">
			<h1><?php echo current_role() === 'borrower' ? 'My Borrows' : 'Borrow / Reserve Items'; ?></h1>
			<a href="../index.php" class="btn">← Back to Dashboard</a>
		</div>
	</div>
	
	<div class="container">
		<?php if ($flash): ?><div class="alert success"><?php echo h($flash); ?></div><?php endif; ?>
		<?php if ($errors): ?>
			<div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
		<?php endif; ?>

		<?php 
		// Check if we should show the request form or the borrowed items table
		$action = req_str('action', '');
		$item_id_param = req_int('item_id');
		$item_type_param = req_str('item_type');
		
		// Show request form if:
		// 1. Borrower with action=request, OR
		// 2. Borrower with item_id and item_type in URL (clicked "Borrow Now")
		$show_request_form = (current_role() === 'borrower' && ($action === 'request' || ($item_id_param && $item_type_param)));
		$show_borrowed_items = (current_role() === 'borrower' && !$show_request_form);
		?>
		
		<?php if ($show_request_form): ?>
		<!-- Request to Borrow Section -->
		<div class="card" style="margin-bottom: 2rem;">
			<h2>Request to Borrow Item</h2>
			<form method="post">
				<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
				<input type="hidden" name="op" value="create">
				<label>Borrower</label>
				<input type="hidden" name="user_id" value="<?php echo (int)current_user()['user_id']; ?>">
				<input type="text" value="<?php echo h(current_user_name()); ?>" disabled>

				<label>Select Item to Borrow</label>
				<?php if (empty($items)): ?>
					<div style="padding: 1.5rem; text-align: center; background: #f8f9fa; border-radius: 8px; color: #666;">
						<p style="margin: 0;">No available equipment at the moment.</p>
					</div>
				<?php else: ?>
					<div class="products-grid">
						<?php foreach ($items as $it): ?>
							<div class="product-card" 
								 data-item-id="<?php echo (int)$it['item_id']; ?>"
								 data-item-type="<?php echo h($it['item_type']); ?>"
								 data-daily-rate="<?php echo number_format((float)($it['daily_rate'] ?? 0), 2, '.', ''); ?>"
								 data-late-fee="<?php echo number_format((float)($it['late_fee_per_day'] ?? 0), 2, '.', ''); ?>"
								 data-item-name="<?php echo h($it['name']); ?>"
								 data-item-quantity="<?php echo (int)$it['quantity']; ?>"
								 data-total-quantity="<?php echo (int)($it['total_quantity'] ?? $it['quantity']); ?>"
								 data-borrowed-count="<?php echo (int)($it['borrowed_count'] ?? 0); ?>"
								 data-reserved-count="<?php echo (int)($it['reserved_count'] ?? 0); ?>"
								 onclick="selectItem(this)">
								<?php if (!empty($it['image_path'])): ?>
									<img src="../<?php echo h($it['image_path']); ?>" 
										alt="<?php echo h($it['image_alt'] ?? $it['name']); ?>" 
										class="product-image"
										onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
									<div class="product-image-placeholder" style="display: none;">
										No Image Available
									</div>
								<?php else: ?>
									<div class="product-image-placeholder">
										No Image Available
									</div>
								<?php endif; ?>
								
								<div class="product-info">
									<h3 class="product-name"><?php echo h($it['name']); ?></h3>
									
									<div class="product-details">
										<div>
											<span class="product-price">₱<?php echo number_format((float)($it['daily_rate'] ?? 0), 2); ?></span>
											<span class="product-price-label">per day</span>
										</div>
										<div class="product-stock <?php echo $it['quantity'] > 0 ? 'in-stock' : ''; ?>">
											<?php 
												$available_qty = (int)$it['quantity'];
												$total_qty = (int)($it['total_quantity'] ?? $it['quantity']);
												$borrowed_qty = (int)($it['borrowed_count'] ?? 0);
												$reserved_qty = (int)($it['reserved_count'] ?? 0);
												if ($borrowed_qty > 0 || $reserved_qty > 0) {
													echo $available_qty . ' available (' . $total_qty . ' total';
													if ($borrowed_qty > 0) {
														echo ', ' . $borrowed_qty . ' borrowed';
													}
													if ($reserved_qty > 0) {
														echo ', ' . $reserved_qty . ' reserved';
													}
													echo ')';
												} else {
													echo $available_qty . ' available';
												}
											?>
										</div>
									</div>
									
									<div class="selected-badge" style="display: none;">✓ Selected</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				
				<!-- Hidden inputs for selected item -->
				<input type="hidden" name="item_id" id="item_id" required>
				<input type="hidden" name="item_type" id="item_type" value="">

				<!-- Selected Item Display -->
				<div id="selected_item_display" style="display: none; margin-top: 1rem; padding: 1rem; background: #e8f5e9; border-radius: 8px; border-left: 4px solid var(--primary);">
					<strong>Selected Item: </strong><span id="selected_item_name"></span>
					<br><small style="color: #555;">Daily Rate: <span id="selected_item_rate"></span></small>
					<br><small style="color: #555;">Available Stock: <span id="selected_item_stock"></span></small>
					<div id="late_fee_display" style="display: none; margin-top: 0.25rem;"><small style="color: #d32f2f; font-weight: 600;">⚠️ Overdue Fee: <span id="selected_item_late_fee"></span> per day</small></div>
				</div>

				<label>Quantity</label>
				<input type="number" name="quantity" id="quantity" min="1" value="1" required onchange="calculateAmount()" oninput="validateQuantity()">
				<small id="quantity_hint" style="color: #666; font-size: 0.85rem; display: block; margin-top: 0.25rem;"></small>

				<label>Number of Days</label>
				<select name="rental_days" id="rental_days" required onchange="showDueDate(); calculateAmount()">
					<option value="">-- Select Days --</option>
					<option value="1">1 Day</option>
					<option value="2">2 Days</option>
					<option value="3">3 Days</option>
					<option value="5">5 Days</option>
					<option value="7">1 Week</option>
					<option value="14">2 Weeks</option>
					<option value="30">1 Month</option>
					<option value="60">2 Months</option>
					<option value="90">3 Months</option>
				</select>
				<div id="due_date_preview" class="due-date-preview" style="display: none;"></div>
				
				<!-- Amount Calculation Preview -->
				<div id="amount_preview" class="due-date-preview" style="display: none; background: #e8f5e9; border-left-color: #2e7d32; margin-top: 0.5rem;">
					<strong>Estimated Amount Due: </strong><span id="calculated_amount">₱0.00</span>
					<br><small id="calculation_details" style="color: #555;"></small>
				</div>

				<input type="hidden" name="status" value="reserved">

				<label>Remarks</label>
				<textarea name="remarks" rows="2"></textarea>

				<div class="form-actions">
					<button type="submit" class="btn primary" onclick="return validateForm();">Submit</button>
				</div>
			</form>
		</div>
		<?php endif; ?>

		<?php if ($show_borrowed_items || current_role() !== 'borrower'): ?>
		<!-- My Borrowed Items Section -->
		<div class="card">
				<h2>
					<?php echo current_role() === 'borrower' ? 'My Borrowed Items' : 'Active / Reserved'; ?>
					<?php if (in_array(current_role(), ['admin', 'staff'])): ?>
						<?php
							// Count reserved items for notification
							$reserved_count = 0;
							foreach ($current as $c) {
								if ($c['status'] === 'reserved') {
									$reserved_count++;
								}
							}
						?>
						<?php if ($reserved_count > 0): ?>
							<span style="background: #ff9800; color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.85rem; margin-left: 10px; font-weight: 600;">
								<?php echo $reserved_count; ?> Pending Reservation<?php echo $reserved_count > 1 ? 's' : ''; ?>
							</span>
						<?php endif; ?>
					<?php endif; ?>
				</h2>
				<table class="table">
					<thead>
					<tr>
						<?php if (current_role() !== 'borrower'): ?>
							<th>Borrower</th>
						<?php endif; ?>
						<th>Image</th>
						<th>Item</th>
						<th>Quantity</th>
						<th>Daily Rate</th>
						<th>Borrowed</th>
						<th>Due</th>
						<th>Status</th>
						<th>Amount Due</th>
						<th>Actions</th>
					</tr>
					</thead>
					<tbody>
					<?php if (empty($current)): ?>
						<tr>
							<td colspan="<?php echo current_role() === 'borrower' ? '9' : '10'; ?>" style="text-align: center; padding: 1.5rem; color: #999;">
								<p style="font-size: 0.95rem; margin: 0;">No <?php echo current_role() === 'borrower' ? 'borrowed items' : 'active borrows'; ?> at the moment.</p>
								<?php if (current_role() === 'borrower'): ?>
									<p style="margin-top: 0.35rem; font-size: 0.85rem;">Browse items from the <a href="../index.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">dashboard</a> to borrow.</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php else: ?>
					<?php foreach ($current as $c): ?>
						<?php 
							$is_overdue = ($c['status'] === 'overdue' || (strtotime($c['due_date']) < time() && in_array($c['status'], ['borrowed', 'overdue'])));
							$overdue_days = 0;
							if ($is_overdue && strtotime($c['due_date']) < time()) {
								$overdue_days = max(0, floor((time() - strtotime($c['due_date'])) / 86400));
							}
						?>
						<tr <?php if ($c['status'] === 'reserved' && in_array(current_role(), ['admin', 'staff'])): ?>style="background-color: #fff3cd; border-left: 4px solid #ff9800;"<?php elseif ($is_overdue): ?>style="background-color: #f8d7da; border-left: 4px solid #dc3545;"<?php endif; ?>>
							<?php if (current_role() !== 'borrower'): ?>
								<td><?php echo h($c['user_name']); ?></td>
							<?php endif; ?>
							<td>
								<?php if (!empty($c['item_image_path'])): ?>
									<img src="../<?php echo h($c['item_image_path']); ?>" 
										alt="<?php echo h($c['item_image_alt'] ?? $c['item_name']); ?>" 
										style="width: 60px; height: 60px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd; cursor: pointer;"
										onclick="window.open('../<?php echo h($c['item_image_path']); ?>', '_blank')"
										title="Click to view full size">
								<?php else: ?>
									<div style="width: 60px; height: 60px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 11px; text-align: center;">
										No Image
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php echo h('['.($c['item_type']==='equipment'?'EQ':'SU').'] '.$c['item_name']); ?>
								<?php if (!empty($c['remarks']) && in_array(current_role(), ['admin', 'staff'])): ?>
									<br><small style="color: #666; font-size: 0.75rem; font-style: italic;">📝 <?php echo h($c['remarks']); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<strong style="font-size: 1.1rem; color: var(--primary);"><?php echo (int)($c['quantity'] ?? 1); ?></strong>
								<?php if ((int)($c['quantity'] ?? 1) > 1): ?>
									<br><small style="color: #666; font-size: 0.75rem;">item<?php echo (int)($c['quantity'] ?? 1) > 1 ? 's' : ''; ?></small>
								<?php endif; ?>
							</td>
							<td>₱<?php echo number_format((float)$c['daily_rate'], 2); ?></td>
							<td><?php echo h($c['date_borrowed'] ?? ''); ?></td>
							<td>
								<?php echo h($c['due_date']); ?>
								<?php if ($is_overdue && $overdue_days > 0): ?>
									<br><small style="color: #dc3545; font-weight: 600; font-size: 0.75rem;">
										⚠️ <?php echo $overdue_days; ?> day<?php echo $overdue_days > 1 ? 's' : ''; ?> overdue
									</small>
								<?php endif; ?>
							</td>
							<td>
								<span class="status-badge status-<?php echo h($c['status']); ?>">
									<?php echo h(ucfirst($c['status'])); ?>
								</span>
							</td>
							<td>
								<strong>₱<?php echo number_format((float)$c['total_amount_due'], 2); ?></strong>
								<?php if ((float)$c['total_rental_amount'] > 0): ?>
									<br><small style="color: #666; font-size: 0.75rem;">
										Rental: ₱<?php echo number_format((float)$c['total_rental_amount'], 2); ?>
									</small>
								<?php endif; ?>
								<?php if ((float)$c['late_fee_amount'] > 0): ?>
									<br><small style="color: #dc3545; font-weight: 600; font-size: 0.75rem;">
										⚠️ Late Fee: ₱<?php echo number_format((float)$c['late_fee_amount'], 2); ?>
									</small>
								<?php endif; ?>
								<?php 
									$total_confirmed_paid = (float)($c['total_confirmed_paid'] ?? 0.00);
									$remaining = (float)$c['total_amount_due'] - $total_confirmed_paid;
									if ($remaining > 0 && $total_confirmed_paid > 0):
								?>
									<br><small style="color: #f44336; font-weight: 600; font-size: 0.75rem;">
										Remaining: ₱<?php echo number_format($remaining, 2); ?>
									</small>
								<?php endif; ?>
							</td>
							<td>
					<?php if ($c['status'] === 'reserved' && in_array(current_role(), ['admin','staff'])): ?>
									<?php if (!empty($c['remarks'])): ?>
										<div style="background: #e8f5e9; padding: 0.5rem; border-radius: 6px; margin-bottom: 0.5rem; border-left: 3px solid var(--primary);">
											<small style="color: var(--primary); font-weight: 600; display: block; margin-bottom: 0.25rem;">📝 Remarks:</small>
											<small style="color: #555; font-size: 0.8rem; display: block;"><?php echo nl2br(h($c['remarks'])); ?></small>
										</div>
									<?php endif; ?>
									<form method="post" class="inline" onsubmit="return confirm('Approve this reservation?');">
										<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
										<input type="hidden" name="op" value="update_status">
										<input type="hidden" name="borrow_id" value="<?php echo (int)$c['borrow_id']; ?>">
										<input type="hidden" name="new_status" value="borrowed">
										<button class="btn small primary" type="submit">Approve</button>
									</form>
									<form method="post" class="inline" onsubmit="return confirm('Decline this reservation?');">
										<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
										<input type="hidden" name="op" value="update_status">
										<input type="hidden" name="borrow_id" value="<?php echo (int)$c['borrow_id']; ?>">
										<input type="hidden" name="new_status" value="cancelled">
										<button class="btn small danger" type="submit">Decline</button>
									</form>
								<?php elseif ($c['status'] === 'reserved' && current_role() === 'borrower' && $c['user_id'] == current_user()['user_id']): ?>
									<form method="post" class="inline" onsubmit="return confirm('Cancel this reservation? This action cannot be undone.');">
										<input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
										<input type="hidden" name="op" value="update_status">
										<input type="hidden" name="borrow_id" value="<?php echo (int)$c['borrow_id']; ?>">
										<input type="hidden" name="new_status" value="cancelled">
										<button class="btn small danger" type="submit">Cancel</button>
									</form>
								<?php elseif (current_role() === 'borrower' && in_array($c['status'], ['borrowed', 'overdue'])): ?>
									<?php
										$total_confirmed_paid = (float)($c['total_confirmed_paid'] ?? 0.00);
										$total_amount_due = (float)($c['total_amount_due'] ?? 0.00);
										$is_fully_paid = ($total_amount_due > 0 && $total_confirmed_paid >= $total_amount_due);
									?>
									<?php if ($is_fully_paid): ?>
										<div style="display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start;">
											<span class="status-badge status-confirmed" style="font-size: 0.8rem;">✓ Successful Payment</span>
											<a href="payment_history.php?borrow_id=<?php echo (int)$c['borrow_id']; ?>" class="btn small primary" style="background: #4caf50;">View Receipt</a>
										</div>
									<?php else: ?>
										<a href="payments.php?borrow_id=<?php echo (int)$c['borrow_id']; ?>" class="btn small primary">Pay Now</a>
									<?php endif; ?>
								<?php else: ?>
									<span class="badge badge-gray">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</body>
<script>
function showDueDate() {
    const days = document.getElementById('rental_days').value;
    const preview = document.getElementById('due_date_preview');
    
    if (days && days > 0) {
        const today = new Date();
        const dueDate = new Date(today.getTime() + (days * 24 * 60 * 60 * 1000));
        const formattedDate = dueDate.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        preview.innerHTML = `Estimated Due Date: ${formattedDate}`;
        preview.style.display = 'block';
    } else {
        preview.innerHTML = '';
        preview.style.display = 'none';
    }
}

// Validate form before submission
function validateForm() {
    const itemId = document.getElementById('item_id').value;
    if (!itemId) {
        alert('Please select an item to borrow.');
        return false;
    }
    
    const quantityInput = document.getElementById('quantity');
    if (quantityInput) {
        const quantity = parseInt(quantityInput.value) || 0;
        const maxQuantity = parseInt(quantityInput.getAttribute('max') || '0');
        
        if (quantity < 1) {
            alert('Please enter a valid quantity (at least 1).');
            quantityInput.focus();
            return false;
        }
        
        if (quantity > maxQuantity) {
            alert('Quantity cannot exceed available stock (' + maxQuantity + ').');
            quantityInput.focus();
            return false;
        }
    }
    
    return true;
}

// Select item from card
function selectItem(card) {
    // Remove selected class from all cards
    document.querySelectorAll('.product-card').forEach(c => {
        c.classList.remove('selected');
        const badge = c.querySelector('.selected-badge');
        if (badge) badge.style.display = 'none';
    });
    
    // Add selected class to clicked card
    card.classList.add('selected');
    const badge = card.querySelector('.selected-badge');
    if (badge) badge.style.display = 'block';
    
    // Set hidden input values
    const itemId = card.getAttribute('data-item-id');
    const itemType = card.getAttribute('data-item-type');
    const dailyRate = card.getAttribute('data-daily-rate');
    const lateFee = card.getAttribute('data-late-fee') || '0';
    const itemName = card.getAttribute('data-item-name');
    const itemQuantity = parseInt(card.getAttribute('data-item-quantity') || '0'); // Available quantity
    const totalQuantity = parseInt(card.getAttribute('data-total-quantity') || itemQuantity);
    const borrowedCount = parseInt(card.getAttribute('data-borrowed-count') || '0');
    const reservedCount = parseInt(card.getAttribute('data-reserved-count') || '0');
    
    document.getElementById('item_id').value = itemId;
    document.getElementById('item_type').value = itemType;
    
    // Update quantity field max value
    const quantityInput = document.getElementById('quantity');
    if (quantityInput) {
        quantityInput.setAttribute('max', itemQuantity);
        if (parseInt(quantityInput.value) > itemQuantity) {
            quantityInput.value = itemQuantity;
        }
        validateQuantity();
    }
    
    // Show selected item display
    const selectedDisplay = document.getElementById('selected_item_display');
    const selectedName = document.getElementById('selected_item_name');
    const selectedRate = document.getElementById('selected_item_rate');
    const selectedStock = document.getElementById('selected_item_stock');
    const selectedLateFee = document.getElementById('selected_item_late_fee');
    
    if (selectedDisplay && selectedName && selectedRate) {
        selectedName.textContent = itemName;
        selectedRate.textContent = '₱' + parseFloat(dailyRate).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (selectedStock) {
            if (borrowedCount > 0 || reservedCount > 0) {
                const details = [];
                if (borrowedCount > 0) details.push(borrowedCount + ' borrowed');
                if (reservedCount > 0) details.push(reservedCount + ' reserved');
                selectedStock.textContent = itemQuantity + ' available (' + totalQuantity + ' total, ' + details.join(', ') + ')';
            } else {
                selectedStock.textContent = itemQuantity + ' available';
            }
        }
        if (selectedLateFee) {
            const lateFeeValue = parseFloat(lateFee);
            const lateFeeDisplay = document.getElementById('late_fee_display');
            if (lateFeeValue > 0) {
                selectedLateFee.textContent = '₱' + lateFeeValue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                if (lateFeeDisplay) lateFeeDisplay.style.display = '';
            } else {
                if (lateFeeDisplay) lateFeeDisplay.style.display = 'none';
            }
        }
        selectedDisplay.style.display = 'block';
    }
    
    // Trigger calculation if days are already selected
    const daysSelect = document.getElementById('rental_days');
    if (daysSelect && daysSelect.value) {
        calculateAmount();
    }
}

// Validate quantity input
function validateQuantity() {
    const quantityInput = document.getElementById('quantity');
    const quantityHint = document.getElementById('quantity_hint');
    const selectedCard = document.querySelector('.product-card.selected');
    
    if (!quantityInput || !quantityHint || !selectedCard) return;
    
    const maxQuantity = parseInt(selectedCard.getAttribute('data-item-quantity') || '0');
    const enteredQuantity = parseInt(quantityInput.value) || 0;
    
    if (enteredQuantity > maxQuantity) {
        quantityHint.textContent = '⚠️ Maximum available: ' + maxQuantity;
        quantityHint.style.color = '#d32f2f';
        quantityInput.setCustomValidity('Quantity cannot exceed available stock');
    } else if (enteredQuantity < 1) {
        quantityHint.textContent = '⚠️ Quantity must be at least 1';
        quantityHint.style.color = '#d32f2f';
        quantityInput.setCustomValidity('Quantity must be at least 1');
    } else {
        quantityHint.textContent = 'Available: ' + maxQuantity;
        quantityHint.style.color = '#666';
        quantityInput.setCustomValidity('');
    }
}

// Calculate amount based on selected item, days, and quantity
function calculateAmount() {
    const itemIdInput = document.getElementById('item_id');
    const daysSelect = document.getElementById('rental_days');
    const quantityInput = document.getElementById('quantity');
    const amountPreview = document.getElementById('amount_preview');
    const calculatedAmount = document.getElementById('calculated_amount');
    const calculationDetails = document.getElementById('calculation_details');
    
    if (!itemIdInput || !daysSelect || !amountPreview) return;
    
    const itemId = itemIdInput.value;
    if (!itemId) {
        amountPreview.style.display = 'none';
        return;
    }
    
    // Get selected card
    const selectedCard = document.querySelector('.product-card.selected');
    if (!selectedCard) {
        amountPreview.style.display = 'none';
        return;
    }
    
    const dailyRate = parseFloat(selectedCard.getAttribute('data-daily-rate') || '0');
    const itemName = selectedCard.getAttribute('data-item-name') || '';
    const days = parseInt(daysSelect.value);
    const quantity = parseInt(quantityInput ? quantityInput.value : '1') || 1;
    
    if (!days || days <= 0) {
        amountPreview.style.display = 'none';
        return;
    }
    
    if (dailyRate <= 0) {
        amountPreview.style.display = 'block';
        calculatedAmount.textContent = '₱0.00';
        calculationDetails.textContent = 'Please select an item with a valid daily rate';
        return;
    }
    
    // Calculate: daily_rate * days * quantity
    const amountPerItem = (dailyRate * days);
    const totalAmount = (amountPerItem * quantity).toFixed(2);
    
    amountPreview.style.display = 'block';
    calculatedAmount.textContent = '₱' + parseFloat(totalAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    if (quantity > 1) {
        calculationDetails.textContent = '₱' + dailyRate.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (daily rate) × ' + days + ' days × ' + quantity + ' items = ₱' + parseFloat(totalAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        calculationDetails.textContent = '₱' + dailyRate.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (daily rate) × ' + days + ' days = ₱' + parseFloat(totalAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
}

// Auto-select item from URL parameters
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const itemId = urlParams.get('item_id');
    const itemType = urlParams.get('item_type');
    
    if (itemId) {
        // Find the card with matching item_id
        const card = document.querySelector(`.product-card[data-item-id="${itemId}"]`);
        if (card) {
            selectItem(card);
            
            // Scroll to the selected card
            setTimeout(() => {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
        }
    }
    
    // Add event listener to days select to recalculate when days change
    const daysSelect = document.getElementById('rental_days');
    if (daysSelect) {
        daysSelect.addEventListener('change', function() {
            calculateAmount();
        });
    }
    
    // Add event listener to quantity input to recalculate when quantity changes
    const quantityInput = document.getElementById('quantity');
    if (quantityInput) {
        quantityInput.addEventListener('input', function() {
            validateQuantity();
            calculateAmount();
        });
    }
});
</script>
</html>


