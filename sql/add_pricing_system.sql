-- Add pricing system to equipment and supplies
USE `cbr_agriculture`;

-- Add pricing fields to equipment table
ALTER TABLE `equipment`
ADD COLUMN `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `status`,
ADD COLUMN `max_rental_days` INT UNSIGNED NOT NULL DEFAULT 30 AFTER `daily_rate`,
ADD COLUMN `late_fee_per_day` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `max_rental_days`;

-- Add pricing fields to supplies table  
ALTER TABLE `supplies`
ADD COLUMN `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `status`,
ADD COLUMN `max_rental_days` INT UNSIGNED NOT NULL DEFAULT 30 AFTER `daily_rate`,
ADD COLUMN `late_fee_per_day` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `max_rental_days`;

-- Add calculated fields to borrow_records for tracking
ALTER TABLE `borrow_records`
ADD COLUMN `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `remarks`,
ADD COLUMN `total_rental_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `daily_rate`,
ADD COLUMN `late_fee_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_rental_amount`,
ADD COLUMN `total_amount_due` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `late_fee_amount`;

-- Add indexes for better performance
ALTER TABLE `equipment` ADD INDEX `ix_equipment_daily_rate` (`daily_rate`);
ALTER TABLE `supplies` ADD INDEX `ix_supplies_daily_rate` (`daily_rate`);
ALTER TABLE `borrow_records` ADD INDEX `ix_borrow_total_amount` (`total_amount_due`);

-- Create stored procedure to calculate rental amounts
DELIMITER $$

CREATE PROCEDURE `sp_calculate_rental_amount`(
    IN p_borrow_id INT UNSIGNED
)
BEGIN
    DECLARE v_daily_rate DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_due_date DATETIME;
    DECLARE v_return_date DATETIME;
    DECLARE v_rental_days INT DEFAULT 0;
    DECLARE v_late_days INT DEFAULT 0;
    DECLARE v_late_fee_per_day DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_total_rental DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_late_fee DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_total_due DECIMAL(10,2) DEFAULT 0.00;
    
    -- Get borrow record details
    SELECT 
        br.daily_rate,
        br.due_date,
        br.return_date,
        CASE 
            WHEN br.item_type = 'equipment' THEN e.late_fee_per_day
            WHEN br.item_type = 'supplies' THEN s.late_fee_per_day
        END as late_fee_rate
    INTO v_daily_rate, v_due_date, v_return_date, v_late_fee_per_day
    FROM borrow_records br
    LEFT JOIN equipment e ON br.item_type = 'equipment' AND br.item_id = e.equip_id
    LEFT JOIN supplies s ON br.item_type = 'supplies' AND br.item_id = s.supply_id
    WHERE br.borrow_id = p_borrow_id;
    
    -- Calculate rental days (from borrow date to return date or current date)
    IF v_return_date IS NOT NULL THEN
        SET v_rental_days = DATEDIFF(v_return_date, DATE(v_due_date)) + 1;
    ELSE
        SET v_rental_days = DATEDIFF(NOW(), DATE(v_due_date)) + 1;
    END IF;
    
    -- Calculate late days (if returned after due date)
    IF v_return_date IS NOT NULL AND v_return_date > v_due_date THEN
        SET v_late_days = DATEDIFF(v_return_date, v_due_date);
    ELSEIF v_return_date IS NULL AND NOW() > v_due_date THEN
        SET v_late_days = DATEDIFF(NOW(), v_due_date);
    END IF;
    
    -- Calculate amounts
    SET v_total_rental = v_daily_rate * v_rental_days;
    SET v_late_fee = v_late_fee_per_day * v_late_days;
    SET v_total_due = v_total_rental + v_late_fee;
    
    -- Update borrow record with calculated amounts
    UPDATE borrow_records 
    SET 
        total_rental_amount = v_total_rental,
        late_fee_amount = v_late_fee,
        total_amount_due = v_total_due
    WHERE borrow_id = p_borrow_id;
    
    -- Return calculated amounts
    SELECT 
        v_daily_rate as daily_rate,
        v_rental_days as rental_days,
        v_late_days as late_days,
        v_total_rental as total_rental_amount,
        v_late_fee as late_fee_amount,
        v_total_due as total_amount_due;
END$$

-- Create procedure to update rental amounts when borrowing
CREATE PROCEDURE `sp_update_borrow_pricing`(
    IN p_borrow_id INT UNSIGNED
)
BEGIN
    DECLARE v_daily_rate DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_late_fee_per_day DECIMAL(10,2) DEFAULT 0.00;
    
    -- Get pricing from item
    SELECT 
        CASE 
            WHEN br.item_type = 'equipment' THEN e.daily_rate
            WHEN br.item_type = 'supplies' THEN s.daily_rate
        END as rate,
        CASE 
            WHEN br.item_type = 'equipment' THEN e.late_fee_per_day
            WHEN br.item_type = 'supplies' THEN s.late_fee_per_day
        END as late_fee
    INTO v_daily_rate, v_late_fee_per_day
    FROM borrow_records br
    LEFT JOIN equipment e ON br.item_type = 'equipment' AND br.item_id = e.equip_id
    LEFT JOIN supplies s ON br.item_type = 'supplies' AND br.item_id = s.supply_id
    WHERE br.borrow_id = p_borrow_id;
    
    -- Update borrow record with item pricing
    UPDATE borrow_records 
    SET daily_rate = v_daily_rate
    WHERE borrow_id = p_borrow_id;
    
    -- Calculate current amounts
    CALL sp_calculate_rental_amount(p_borrow_id);
END$$

DELIMITER ;

-- Update existing borrow records with default pricing
UPDATE borrow_records br
LEFT JOIN equipment e ON br.item_type = 'equipment' AND br.item_id = e.equip_id
LEFT JOIN supplies s ON br.item_type = 'supplies' AND br.item_id = s.supply_id
SET br.daily_rate = CASE 
    WHEN br.item_type = 'equipment' THEN COALESCE(e.daily_rate, 0.00)
    WHEN br.item_type = 'supplies' THEN COALESCE(s.daily_rate, 0.00)
END;

-- Calculate amounts for existing records
SET @borrow_ids = (SELECT GROUP_CONCAT(borrow_id) FROM borrow_records);
SET @sql = CONCAT('CALL sp_calculate_rental_amount(', REPLACE(@borrow_ids, ',', '); CALL sp_calculate_rental_amount('), ');');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
