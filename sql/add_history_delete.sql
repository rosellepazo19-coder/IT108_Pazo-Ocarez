-- Add columns for role-based soft delete functionality
-- This allows borrowers to hide their own history from their view
-- and admin/staff to hide borrower history from their view
-- without actually deleting the records

-- Add columns to payments table
ALTER TABLE `payments` 
ADD COLUMN `hidden_from_borrower` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_status`,
ADD COLUMN `hidden_from_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hidden_from_borrower`;

-- Add indexes for better query performance
ALTER TABLE `payments`
ADD INDEX `ix_hidden_borrower` (`hidden_from_borrower`),
ADD INDEX `ix_hidden_admin` (`hidden_from_admin`);

-- Add columns to borrow_records table for returned items
ALTER TABLE `borrow_records`
ADD COLUMN `hidden_from_borrower` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_amount_due`,
ADD COLUMN `hidden_from_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hidden_from_borrower`;

-- Add indexes for better query performance
ALTER TABLE `borrow_records`
ADD INDEX `ix_hidden_borrower_br` (`hidden_from_borrower`),
ADD INDEX `ix_hidden_admin_br` (`hidden_from_admin`);

