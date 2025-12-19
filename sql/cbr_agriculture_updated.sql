-- ======================================================================
-- Cabadbaran Agricultural Supply and Equipment Lending Web System
-- Database: cbr_agriculture
-- Engine/Charset: InnoDB, utf8mb4
-- phpMyAdmin-compatible script
-- Updated with comprehensive user management
-- ======================================================================

-- ----------------------------------------------------------------------
-- Safety: drop and create database
-- ----------------------------------------------------------------------
DROP DATABASE IF EXISTS `cbr_agriculture`;
CREATE DATABASE `cbr_agriculture`
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;
USE `cbr_agriculture`;

-- ----------------------------------------------------------------------
-- SQL modes to encourage strict behavior
-- ----------------------------------------------------------------------
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ----------------------------------------------------------------------
-- users: comprehensive user management system
-- ----------------------------------------------------------------------
CREATE TABLE `users` (
	`user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`idnum` VARCHAR(20) NOT NULL,
	`Fname` VARCHAR(50) NOT NULL,
	`Mname` VARCHAR(50) NULL,
	`Lname` VARCHAR(50) NOT NULL,
	`Suffix` VARCHAR(10) NULL,
	`mail` VARCHAR(100) NOT NULL,
	`sex` ENUM('Male','Female') NOT NULL,
	`Birthday` DATE NOT NULL,
	`Age` INT UNSIGNED NOT NULL,
	`mobile` VARCHAR(15) NOT NULL,
	`Street` VARCHAR(100) NOT NULL,
	`Barangay` VARCHAR(50) NOT NULL,
	`City` VARCHAR(50) NOT NULL,
	`Province` VARCHAR(50) NOT NULL,
	`Country` VARCHAR(50) NOT NULL,
	`ZipCode` VARCHAR(10) NOT NULL,
	`password` VARCHAR(255) NOT NULL,
	`role` ENUM('admin','staff','borrower') NOT NULL DEFAULT 'borrower',
	`secQ1` VARCHAR(255) NOT NULL,
	`secA1` VARCHAR(255) NOT NULL,
	`secQ2` VARCHAR(255) NOT NULL,
	`secA2` VARCHAR(255) NOT NULL,
	`secQ3` VARCHAR(255) NOT NULL,
	`secA3` VARCHAR(255) NOT NULL,
	`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`user_id`),
	UNIQUE KEY `ux_users_idnum` (`idnum`),
	UNIQUE KEY `ux_users_mail` (`mail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- login_attempts: track failed login attempts for security
-- ----------------------------------------------------------------------
CREATE TABLE `login_attempts` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`email` VARCHAR(100) NOT NULL,
	`attempts` INT UNSIGNED NOT NULL DEFAULT 1,
	`last_attempt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `ux_login_attempts_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- equipment: lendable equipment inventory
-- ----------------------------------------------------------------------
CREATE TABLE `equipment` (
	`equip_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(150) NOT NULL,
	`description` TEXT NULL,
	`quantity` INT UNSIGNED NOT NULL DEFAULT 0,
	`status` ENUM('available','unavailable','maintenance','reserved') NOT NULL DEFAULT 'available',
	`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`equip_id`),
	KEY `ix_equipment_name` (`name`),
	KEY `ix_equipment_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- supplies: consumable supplies inventory
-- ----------------------------------------------------------------------
CREATE TABLE `supplies` (
	`supply_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(150) NOT NULL,
	`description` TEXT NULL,
	`quantity` INT UNSIGNED NOT NULL DEFAULT 0,
	`status` ENUM('available','unavailable','maintenance','reserved') NOT NULL DEFAULT 'available',
	`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`supply_id`),
	KEY `ix_supplies_name` (`name`),
	KEY `ix_supplies_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- borrow_records: borrowing/reservation transactions
-- ----------------------------------------------------------------------
CREATE TABLE `borrow_records` (
	`borrow_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NOT NULL,
	`item_id` INT UNSIGNED NOT NULL,
	`item_type` ENUM('equipment','supplies') NOT NULL,
	`date_borrowed` DATETIME NULL,
	`due_date` DATETIME NOT NULL,
	`return_date` DATETIME NULL,
	`status` ENUM('reserved','borrowed','returned','overdue','cancelled') NOT NULL DEFAULT 'reserved',
	`remarks` VARCHAR(255) NULL,
	`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`borrow_id`),
	CONSTRAINT `fk_borrow_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
	KEY `ix_borrow_user` (`user_id`),
	KEY `ix_borrow_due_date` (`due_date`),
	KEY `ix_borrow_status` (`status`),
	KEY `ix_borrow_item` (`item_type`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- payments: records payments attached to a borrow record
-- ----------------------------------------------------------------------
CREATE TABLE `payments` (
	`payment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`borrow_id` INT UNSIGNED NOT NULL,
	`amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`date_paid` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`payment_id`),
	CONSTRAINT `fk_payment_borrow` FOREIGN KEY (`borrow_id`) REFERENCES `borrow_records` (`borrow_id`) ON DELETE CASCADE ON UPDATE CASCADE,
	KEY `ix_payment_borrow` (`borrow_id`),
	KEY `ix_payment_date` (`date_paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- activity_log: audit trail for key actions
-- ----------------------------------------------------------------------
CREATE TABLE `activity_log` (
	`log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NULL,
	`action` VARCHAR(255) NOT NULL,
	`date_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`log_id`),
	KEY `ix_log_user` (`user_id`),
	KEY `ix_log_datetime` (`date_time`),
	CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- DATA INTEGRITY TRIGGERS
-- ======================================================================
DELIMITER $$

-- ----------------------------------------------------------------------
-- Validate borrow insert: user exists, item exists, no duplicate active borrow
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_borrow_before_insert`
BEFORE INSERT ON `borrow_records`
FOR EACH ROW
BEGIN
	IF (SELECT COUNT(*) FROM `users` u WHERE u.`user_id` = NEW.`user_id`) = 0 THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User does not exist.';
	END IF;

	IF NEW.`item_type` = 'equipment' THEN
		IF (SELECT COUNT(*) FROM `equipment` e WHERE e.`equip_id` = NEW.`item_id`) = 0 THEN
			SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Equipment does not exist.';
		END IF;
	ELSEIF NEW.`item_type` = 'supplies' THEN
		IF (SELECT COUNT(*) FROM `supplies` s WHERE s.`supply_id` = NEW.`item_id`) = 0 THEN
			SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supply does not exist.';
		END IF;
	END IF;

	IF (SELECT COUNT(*) FROM `borrow_records` b
		WHERE b.`user_id` = NEW.`user_id`
			AND b.`item_type` = NEW.`item_type`
			AND b.`item_id` = NEW.`item_id`
			AND b.`status` IN ('reserved','borrowed')) > 0 THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate active reservation/borrow exists for this user and item.';
	END IF;

	IF NEW.`status` = 'borrowed' AND NEW.`date_borrowed` IS NULL THEN
		SET NEW.`date_borrowed` = NOW();
	END IF;
END$$

-- ----------------------------------------------------------------------
-- After insert on borrow: adjust inventory if status is borrowed
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_borrow_after_insert`
AFTER INSERT ON `borrow_records`
FOR EACH ROW
BEGIN
	IF NEW.`status` = 'borrowed' THEN
		IF NEW.`item_type` = 'equipment' THEN
			IF (SELECT `quantity` FROM `equipment` WHERE `equip_id` = NEW.`item_id`) = 0 THEN
				SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No stock available for equipment.';
			END IF;

			UPDATE `equipment`
			SET `quantity` = `quantity` - 1,
				`status` = CASE WHEN `quantity` - 1 <= 0 THEN 'unavailable' ELSE `status` END
			WHERE `equip_id` = NEW.`item_id`;
		ELSEIF NEW.`item_type` = 'supplies' THEN
			IF (SELECT `quantity` FROM `supplies` WHERE `supply_id` = NEW.`item_id`) = 0 THEN
				SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No stock available for supply.';
			END IF;

			UPDATE `supplies`
			SET `quantity` = `quantity` - 1,
				`status` = CASE WHEN `quantity` - 1 <= 0 THEN 'unavailable' ELSE `status` END
			WHERE `supply_id` = NEW.`item_id`;
		END IF;
	END IF;
END$$

-- ----------------------------------------------------------------------
-- Before update on borrow: validate transitions and set dates
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_borrow_before_update`
BEFORE UPDATE ON `borrow_records`
FOR EACH ROW
BEGIN
	IF NEW.`status` = 'borrowed' AND OLD.`status` = 'reserved' THEN
		IF NEW.`date_borrowed` IS NULL THEN
			SET NEW.`date_borrowed` = NOW();
		END IF;

		IF NEW.`item_type` = 'equipment' THEN
			IF (SELECT `quantity` FROM `equipment` WHERE `equip_id` = NEW.`item_id`) = 0 THEN
				SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No stock available for equipment.';
			END IF;
		ELSEIF NEW.`item_type` = 'supplies' THEN
			IF (SELECT `quantity` FROM `supplies` WHERE `supply_id` = NEW.`item_id`) = 0 THEN
				SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No stock available for supply.';
			END IF;
		END IF;
	END IF;

	IF NEW.`status` = 'returned' AND OLD.`status` <> 'returned' THEN
		IF NEW.`return_date` IS NULL THEN
			SET NEW.`return_date` = NOW();
		END IF;
	END IF;
END$$

-- ----------------------------------------------------------------------
-- After update on borrow: adjust inventory for status transitions
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_borrow_after_update`
AFTER UPDATE ON `borrow_records`
FOR EACH ROW
BEGIN
	IF NEW.`status` = 'borrowed' AND OLD.`status` = 'reserved' THEN
		IF NEW.`item_type` = 'equipment' THEN
			UPDATE `equipment`
			SET `quantity` = `quantity` - 1,
				`status` = CASE WHEN `quantity` - 1 <= 0 THEN 'unavailable' ELSE `status` END
			WHERE `equip_id` = NEW.`item_id`;
		ELSEIF NEW.`item_type` = 'supplies' THEN
			UPDATE `supplies`
			SET `quantity` = `quantity` - 1,
				`status` = CASE WHEN `quantity` - 1 <= 0 THEN 'unavailable' ELSE `status` END
			WHERE `supply_id` = NEW.`item_id`;
		END IF;
	END IF;

	IF NEW.`status` = 'returned' AND OLD.`status` <> 'returned' THEN
		IF NEW.`item_type` = 'equipment' THEN
			UPDATE `equipment`
			SET `quantity` = `quantity` + 1,
				`status` = 'available'
			WHERE `equip_id` = NEW.`item_id`;
		ELSEIF NEW.`item_type` = 'supplies' THEN
			UPDATE `supplies`
			SET `quantity` = `quantity` + 1,
				`status` = 'available'
			WHERE `supply_id` = NEW.`item_id`;
		END IF;
	END IF;
END$$

-- ----------------------------------------------------------------------
-- Activity log: equipment changes
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_equipment_after_insert`
AFTER INSERT ON `equipment`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Created equipment: ', NEW.`equip_id`, ' - ', NEW.`name`));
END$$

CREATE TRIGGER `trg_equipment_after_update`
AFTER UPDATE ON `equipment`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Updated equipment: ', NEW.`equip_id`, ' - ', NEW.`name`));
END$$

CREATE TRIGGER `trg_equipment_after_delete`
AFTER DELETE ON `equipment`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Deleted equipment: ', OLD.`equip_id`, ' - ', OLD.`name`));
END$$

-- ----------------------------------------------------------------------
-- Activity log: supplies changes
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_supplies_after_insert`
AFTER INSERT ON `supplies`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Created supply: ', NEW.`supply_id`, ' - ', NEW.`name`));
END$$

CREATE TRIGGER `trg_supplies_after_update`
AFTER UPDATE ON `supplies`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Updated supply: ', NEW.`supply_id`, ' - ', NEW.`name`));
END$$

CREATE TRIGGER `trg_supplies_after_delete`
AFTER DELETE ON `supplies`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Deleted supply: ', OLD.`supply_id`, ' - ', OLD.`name`));
END$$

-- ----------------------------------------------------------------------
-- Activity log: borrow_records changes
-- ----------------------------------------------------------------------
CREATE TRIGGER `trg_borrow_after_ins_log`
AFTER INSERT ON `borrow_records`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NEW.`user_id`, CONCAT('Created borrow record #', NEW.`borrow_id`, ' (', NEW.`item_type`, ':', NEW.`item_id`, ') status=', NEW.`status`));
END$$

CREATE TRIGGER `trg_borrow_after_upd_log`
AFTER UPDATE ON `borrow_records`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NEW.`user_id`, CONCAT('Updated borrow record #', NEW.`borrow_id`, ' status ', OLD.`status`, ' -> ', NEW.`status`));
END$$

CREATE TRIGGER `trg_borrow_after_del_log`
AFTER DELETE ON `borrow_records`
FOR EACH ROW
BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (OLD.`user_id`, CONCAT('Deleted borrow record #', OLD.`borrow_id`));
END$$

DELIMITER ;

-- ======================================================================
-- STORED PROCEDURES
-- ======================================================================
DELIMITER $$

CREATE PROCEDURE `sp_borrow_item`(
	IN p_user_id INT UNSIGNED,
	IN p_item_type ENUM('equipment','supplies'),
	IN p_item_id INT UNSIGNED,
	IN p_due_date DATETIME,
	IN p_status ENUM('reserved','borrowed'),
	IN p_remarks VARCHAR(255)
)
BEGIN
	IF p_status NOT IN ('reserved','borrowed') THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status must be reserved or borrowed.';
	END IF;

	INSERT INTO `borrow_records` (`user_id`, `item_id`, `item_type`, `date_borrowed`, `due_date`, `status`, `remarks`)
	VALUES (p_user_id, p_item_id, p_item_type, CASE WHEN p_status='borrowed' THEN NOW() ELSE NULL END, p_due_date, p_status, p_remarks);
END$$

CREATE PROCEDURE `sp_return_item`(
	IN p_borrow_id INT UNSIGNED,
	IN p_return_date DATETIME
)
BEGIN
	UPDATE `borrow_records`
	SET `status` = 'returned',
		`return_date` = COALESCE(p_return_date, NOW())
	WHERE `borrow_id` = p_borrow_id;
END$$

CREATE PROCEDURE `sp_mark_overdue`()
BEGIN
	UPDATE `borrow_records`
	SET `status` = 'overdue'
	WHERE `status` IN ('reserved','borrowed')
		AND `due_date` < NOW();
END$$

CREATE PROCEDURE `sp_record_payment`(
	IN p_borrow_id INT UNSIGNED,
	IN p_amount DECIMAL(10,2)
)
BEGIN
	IF p_amount <= 0 THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amount must be positive.';
	END IF;

	INSERT INTO `payments` (`borrow_id`, `amount`)
	VALUES (p_borrow_id, p_amount);
END$$

DELIMITER ;

-- ======================================================================
-- VIEWS FOR MONITORING AND REPORTS
-- ======================================================================

CREATE OR REPLACE VIEW `v_current_borrows` AS
SELECT
	b.`borrow_id`,
	b.`user_id`,
	CONCAT(u.`Fname`, ' ', u.`Lname`) AS `user_name`,
	b.`item_type`,
	b.`item_id`,
	CASE
		WHEN b.`item_type`='equipment' THEN (SELECT e.`name` FROM `equipment` e WHERE e.`equip_id`=b.`item_id`)
		WHEN b.`item_type`='supplies' THEN (SELECT s.`name` FROM `supplies` s WHERE s.`supply_id`=b.`item_id`)
	END AS `item_name`,
	b.`date_borrowed`,
	b.`due_date`,
	b.`status`,
	CASE
		WHEN b.`status` IN ('borrowed','overdue') AND b.`due_date` < NOW() THEN TIMESTAMPDIFF(DAY, b.`due_date`, NOW())
		WHEN b.`status`='returned' AND b.`return_date` IS NOT NULL AND b.`return_date` > b.`due_date` THEN TIMESTAMPDIFF(DAY, b.`due_date`, b.`return_date`)
		ELSE 0
	END AS `overdue_days`
FROM `borrow_records` b
JOIN `users` u ON u.`user_id` = b.`user_id`
WHERE b.`status` IN ('reserved','borrowed','overdue');

CREATE OR REPLACE VIEW `v_overdue_items` AS
SELECT
	cb.*
FROM `v_current_borrows` cb
WHERE cb.`status` IN ('borrowed','overdue')
	AND cb.`due_date` < NOW();

CREATE OR REPLACE VIEW `v_most_borrowed_items` AS
SELECT
	b.`item_type`,
	b.`item_id`,
	CASE
		WHEN b.`item_type`='equipment' THEN (SELECT e.`name` FROM `equipment` e WHERE e.`equip_id`=b.`item_id`)
		WHEN b.`item_type`='supplies' THEN (SELECT s.`name` FROM `supplies` s WHERE s.`supply_id`=b.`item_id`)
	END AS `item_name`,
	SUM(b.`status` IN ('borrowed','returned','overdue')) AS `times_borrowed`
FROM `borrow_records` b
GROUP BY b.`item_type`, b.`item_id`;

CREATE OR REPLACE VIEW `v_active_borrowers` AS
SELECT
	u.`user_id`,
	CONCAT(u.`Fname`, ' ', u.`Lname`) AS `name`,
	COUNT(*) AS `active_transactions`
FROM `borrow_records` b
JOIN `users` u ON u.`user_id` = b.`user_id`
WHERE b.`status` IN ('reserved','borrowed','overdue')
GROUP BY u.`user_id`, u.`Fname`, u.`Lname`;

-- ======================================================================
-- SAMPLE DATA
-- ======================================================================

-- Insert sample admin user
INSERT INTO `users` (
	`idnum`, `Fname`, `Mname`, `Lname`, `Suffix`, `mail`, `sex`, `Birthday`, `Age`,
	`mobile`, `Street`, `Barangay`, `City`, `Province`, `Country`, `ZipCode`,
	`password`, `role`, `secQ1`, `secA1`, `secQ2`, `secA2`, `secQ3`, `secA3`
) VALUES (
	'2024-0001', 'Admin', 'System', 'User', '', 'admin@cbr.com', 'Male', '1990-01-01', 34,
	'09123456789', 'Main Street', 'Poblacion', 'Cabadbaran', 'Agusan del Norte', 'Philippines', '8600',
	'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',
	'What is your favorite dessert?', 'cake', 'What was the name of your school in Highchool?', 'CSUCC',
	'In what city were you born?', 'Cabadbaran'
);

-- Insert sample equipment
INSERT INTO `equipment` (`name`, `description`, `quantity`, `status`) VALUES
('Hand Tractor', 'Used for land preparation', 5, 'available'),
('Water Pump', 'For irrigation purposes', 3, 'available'),
('Sprayer', 'For pesticide application', 8, 'available');

-- Insert sample supplies
INSERT INTO `supplies` (`name`, `description`, `quantity`, `status`) VALUES
('Fertilizer 14-14-14', 'Balanced fertilizer', 100, 'available'),
('Seeds - Rice', 'High-yield rice seeds', 50, 'available'),
('Pesticide', 'Organic pesticide', 25, 'available');
