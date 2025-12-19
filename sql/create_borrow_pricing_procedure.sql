USE `cbr_agriculture`;

DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_borrow_item_with_pricing`$$

CREATE PROCEDURE `sp_borrow_item_with_pricing`(
	IN p_user_id INT UNSIGNED,
	IN p_item_type ENUM('equipment','supplies'),
	IN p_item_id INT UNSIGNED,
	IN p_due_date DATETIME,
	IN p_status ENUM('reserved','borrowed'),
	IN p_remarks VARCHAR(255)
)
BEGIN
	DECLARE v_daily_rate DECIMAL(10,2) DEFAULT 0.00;
	
	IF p_status NOT IN ('reserved','borrowed') THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status must be reserved or borrowed.';
	END IF;

	-- Get daily rate from item
	SELECT 
		CASE 
			WHEN p_item_type = 'equipment' THEN e.daily_rate
			WHEN p_item_type = 'supplies' THEN s.daily_rate
		END INTO v_daily_rate
	FROM (SELECT 1 as dummy) d
	LEFT JOIN equipment e ON p_item_type = 'equipment' AND p_item_id = e.equip_id
	LEFT JOIN supplies s ON p_item_type = 'supplies' AND p_item_id = s.supply_id;

	-- Insert borrow record with pricing
	INSERT INTO `borrow_records` (`user_id`, `item_id`, `item_type`, `date_borrowed`, `due_date`, `status`, `remarks`, `daily_rate`)
	VALUES (p_user_id, p_item_id, p_item_type, CASE WHEN p_status='borrowed' THEN NOW() ELSE NULL END, p_due_date, p_status, p_remarks, v_daily_rate);
END$$

DELIMITER ;
