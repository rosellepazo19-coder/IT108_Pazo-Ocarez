# Summary of Locked History Records

## Overview
This document lists all the history records that are locked and cannot be deleted in the system, along with the reasons why they are locked.

---

## 1. Payment History (`payment_history.php`)

### Locked Conditions:
1. **Confirmed Payments** - Cannot be deleted
   - **Reason**: Confirmed payments are final and should be kept for audit trail
   - **Location**: Lines 45-48, 73-76, 775-778
   - **Code Check**: `if ($payment_data['payment_status'] === 'confirmed')`

2. **Payments from Returned Items** - Cannot be deleted
   - **Reason**: Payments linked to returned items are part of completed transactions
   - **Location**: Lines 50-54, 78-82, 780-784
   - **Code Check**: `if ($payment_data['borrow_status'] === 'returned')`

### What CAN be deleted:
- Pending payments (not yet confirmed)
- Rejected payments
- Payments from items that are NOT yet returned

---

## 2. Returned Items (`returned_items.php`)

### Locked Conditions:
1. **Returned Items with Confirmed Payments** - Cannot be deleted
   - **Reason**: Items with confirmed payments are part of completed financial transactions
   - **Location**: Lines 43-47, 66-70, 424-450
   - **Code Check**: `if ($item_data['confirmed_payments_count'] > 0)`

### What CAN be deleted:
- Returned items that have NO confirmed payments
- Returned items with only pending/rejected payments

---

## 3. Users (`users.php`)

### Locked Conditions:
1. **Users with Active Borrow Records** - Cannot be deleted
   - **Reason**: Users with active borrows (reserved, borrowed, overdue) must return items first
   - **Location**: Lines 48-57
   - **Code Check**: `SELECT COUNT(*) FROM borrow_records WHERE user_id = ? AND status IN ('reserved', 'borrowed', 'overdue')`
   - **Error Message**: "Cannot delete user. User has X active borrow record(s). Please return all items first."

### What CAN be deleted:
- Users with NO active borrow records
- Users with only returned/cancelled borrow records

---

## 4. Inventory Items (`inventory.php`)

### Locked Conditions:

#### Equipment:
1. **Equipment with Active Borrow Records** - Cannot be deleted
   - **Reason**: Equipment currently borrowed/reserved cannot be deleted
   - **Location**: Lines 105-114
   - **Code Check**: `SELECT COUNT(*) FROM borrow_records WHERE item_type='equipment' AND item_id=? AND status IN ('reserved','borrowed','overdue')`
   - **Error Message**: "Cannot delete equipment. It has X active borrow record(s). Please return all items first."

#### Supplies:
1. **Supplies with Active Borrow Records** - Cannot be deleted
   - **Reason**: Supplies currently borrowed/reserved cannot be deleted
   - **Location**: Lines 155-165
   - **Code Check**: `SELECT COUNT(*) FROM borrow_records WHERE item_type='supplies' AND item_id=? AND status IN ('reserved','borrowed','overdue')`
   - **Error Message**: "Cannot delete supply. It has X active borrow record(s). Please return all items first."

### What CAN be deleted:
- Items with NO active borrow records
- Items with only returned/cancelled borrow records

---

## Summary Table

| Record Type | Locked When | Reason | File Location |
|------------|-------------|--------|---------------|
| **Payment History** | Payment status = 'confirmed' | Audit trail requirement | `payment_history.php` |
| **Payment History** | Borrow status = 'returned' | Completed transaction | `payment_history.php` |
| **Returned Items** | Has confirmed payments | Financial record integrity | `returned_items.php` |
| **Users** | Has active borrows | Must return items first | `users.php` |
| **Equipment** | Has active borrows | Currently in use | `inventory.php` |
| **Supplies** | Has active borrows | Currently in use | `inventory.php` |

---

## Notes

1. **Soft Delete System**: The system uses soft delete (hiding records) rather than hard delete for history records. This means:
   - Records are marked as `hidden_from_borrower` or `hidden_from_admin`
   - Records are NOT actually deleted from the database
   - Different roles can hide records from their own view independently

2. **Security**: These locks are in place to:
   - Maintain audit trails
   - Prevent data loss
   - Ensure financial record integrity
   - Prevent deletion of records that are still in use

3. **To Unlock Records**:
   - **Payments**: Change status from 'confirmed' to 'pending' or 'rejected' (if allowed)
   - **Returned Items**: Remove confirmed payments (not recommended for audit purposes)
   - **Users**: Return all active borrows first
   - **Inventory**: Return all active borrows first

---

## Recommendations

If you need to modify these restrictions, consider:
1. Adding an admin override option (with proper logging)
2. Creating an archive system instead of deletion
3. Adding a "reason for deletion" field for audit purposes
4. Implementing a time-based lock (e.g., can't delete records older than X days)

