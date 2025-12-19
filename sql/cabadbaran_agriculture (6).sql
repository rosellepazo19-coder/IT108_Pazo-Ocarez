-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 25, 2025 at 03:03 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cabadbaran_agriculture`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_borrow_item` (IN `p_user_id` INT UNSIGNED, IN `p_item_type` ENUM('equipment','supplies'), IN `p_item_id` INT UNSIGNED, IN `p_due_date` DATETIME, IN `p_status` ENUM('reserved','borrowed'), IN `p_remarks` VARCHAR(255))   BEGIN
	IF p_status NOT IN ('reserved','borrowed') THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status must be reserved or borrowed.';
	END IF;

	INSERT INTO `borrow_records` (`user_id`, `item_id`, `item_type`, `date_borrowed`, `due_date`, `status`, `remarks`)
	VALUES (p_user_id, p_item_id, p_item_type, CASE WHEN p_status='borrowed' THEN NOW() ELSE NULL END, p_due_date, p_status, p_remarks);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_borrow_item_with_pricing` (IN `p_user_id` INT UNSIGNED, IN `p_item_type` ENUM('equipment','supplies'), IN `p_item_id` INT UNSIGNED, IN `p_due_date` DATETIME, IN `p_status` ENUM('reserved','borrowed'), IN `p_remarks` VARCHAR(255))   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_mark_overdue` ()   BEGIN
	UPDATE `borrow_records`
	SET `status` = 'overdue'
	WHERE `status` IN ('reserved','borrowed')
		AND `due_date` < NOW();
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_record_payment` (IN `p_borrow_id` INT UNSIGNED, IN `p_amount` DECIMAL(10,2))   BEGIN
	IF p_amount <= 0 THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amount must be positive.';
	END IF;

	INSERT INTO `payments` (`borrow_id`, `amount`)
	VALUES (p_borrow_id, p_amount);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_return_item` (IN `p_borrow_id` INT UNSIGNED, IN `p_return_date` DATETIME)   BEGIN
	UPDATE `borrow_records`
	SET `status` = 'returned',
		`return_date` = COALESCE(p_return_date, NOW())
	WHERE `borrow_id` = p_borrow_id;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `date_time` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `date_time`) VALUES
(1, NULL, 'Created equipment: 1 - Hand Tractor', '2025-10-15 13:15:36'),
(2, NULL, 'Created equipment: 2 - Water Pump', '2025-10-15 13:15:36'),
(3, NULL, 'Created equipment: 3 - Sprayer', '2025-10-15 13:15:36'),
(4, NULL, 'Created supply: 1 - Fertilizer 14-14-14', '2025-10-15 13:15:36'),
(5, NULL, 'Created supply: 2 - Seeds - Rice', '2025-10-15 13:15:36'),
(6, NULL, 'Created supply: 3 - Pesticide', '2025-10-15 13:15:36'),
(7, NULL, 'Created equipment: 4 - BARA', '2025-10-15 14:33:02'),
(8, NULL, 'Created borrow record #1 (equipment:4) status=reserved', '2025-10-15 14:40:42'),
(9, NULL, 'Updated equipment: 4 - BARA', '2025-10-15 14:41:11'),
(10, NULL, 'Updated borrow record #1 status reserved -> borrowed', '2025-10-15 14:41:11'),
(11, NULL, 'Updated equipment: 4 - BARA', '2025-10-15 14:43:34'),
(12, NULL, 'Updated borrow record #1 status borrowed -> returned', '2025-10-15 14:43:34'),
(13, NULL, 'Deleted borrow record #1', '2025-10-22 14:30:51'),
(14, NULL, 'Created equipment: 5 - EXAMPLE', '2025-10-22 14:36:51'),
(15, NULL, 'Updated equipment: 4 - BARA', '2025-10-22 16:07:42'),
(16, NULL, 'Deleted equipment: 4 - BARA', '2025-10-22 16:11:14'),
(17, NULL, 'Deleted equipment: 5 - EXAMPLE', '2025-10-22 16:11:21'),
(18, NULL, 'Deleted supply: 1 - Fertilizer 14-14-14', '2025-10-22 16:35:30'),
(19, NULL, 'Deleted equipment: 1 - Hand Tractor', '2025-10-23 15:04:35'),
(20, 4, 'Created borrow record #2 (equipment:3) status=reserved', '2025-10-23 15:07:28'),
(21, NULL, 'Updated equipment: 3 - Sprayer', '2025-10-23 15:08:26'),
(22, 4, 'Updated borrow record #2 status reserved -> borrowed', '2025-10-23 15:08:26'),
(23, 4, 'Updated borrow record #2 status borrowed -> borrowed', '2025-10-23 16:48:49'),
(24, 4, 'Updated borrow record #2 status borrowed -> borrowed', '2025-10-24 11:27:06'),
(25, 4, 'Updated borrow record #2 status borrowed -> borrowed', '2025-10-24 11:27:06'),
(26, 4, 'Updated borrow record #2 status borrowed -> borrowed', '2025-10-24 11:27:06'),
(27, NULL, 'Created equipment: 6 - EXAMPLE', '2025-10-24 13:12:05'),
(28, NULL, 'Updated equipment: 3 - Sprayer', '2025-10-24 13:18:13'),
(29, 4, 'Updated borrow record #2 status borrowed -> returned', '2025-10-24 13:18:13'),
(30, NULL, 'Created equipment: 7 - EXAMPLE 2', '2025-10-26 14:05:50'),
(31, 4, 'Created borrow record #3 (equipment:6) status=reserved', '2025-10-26 14:06:35'),
(32, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-10-26 14:06:40'),
(33, 4, 'Updated borrow record #3 status reserved -> borrowed', '2025-10-26 14:06:40'),
(34, 4, 'Updated borrow record #3 status borrowed -> returned', '2025-11-07 14:10:50'),
(35, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-07 14:10:50'),
(36, NULL, 'Created supply: 4 - Car', '2025-11-07 14:15:38'),
(37, 4, 'Created borrow record #4 (equipment:6) status=reserved', '2025-11-07 14:25:52'),
(38, NULL, 'Deleted equipment: 3 - Sprayer', '2025-11-08 13:54:50'),
(39, NULL, 'Deleted equipment: 2 - Water Pump', '2025-11-08 13:54:52'),
(40, NULL, 'Deleted equipment: 7 - EXAMPLE 2', '2025-11-08 13:54:55'),
(41, 4, 'Created borrow record #5 (supplies:4) status=reserved', '2025-11-08 14:19:12'),
(42, 4, 'Updated borrow record #5 status reserved -> borrowed', '2025-11-08 14:20:44'),
(43, NULL, 'Updated supply: 4 - Car', '2025-11-08 14:20:44'),
(44, 4, 'Updated borrow record #4 status reserved -> cancelled', '2025-11-08 14:20:47'),
(45, 4, 'Updated borrow record #5 status borrowed -> returned', '2025-11-08 14:23:00'),
(46, NULL, 'Updated supply: 4 - Car', '2025-11-08 14:23:00'),
(47, NULL, 'Created equipment: 8 - EXAMPLE 2', '2025-11-08 14:24:11'),
(48, 4, 'Created borrow record #6 (equipment:8) status=reserved', '2025-11-08 14:34:37'),
(49, 4, 'Updated borrow record #6 status reserved -> borrowed', '2025-11-08 14:34:57'),
(50, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-08 14:34:57'),
(53, 4, 'Updated borrow record #2 status returned -> returned', '2025-11-08 21:33:29'),
(54, 4, 'Updated borrow record #3 status returned -> returned', '2025-11-08 21:33:29'),
(55, 4, 'Updated borrow record #5 status returned -> returned', '2025-11-08 21:33:29'),
(56, 4, 'Updated borrow record #6 status borrowed -> borrowed', '2025-11-08 21:33:29'),
(57, 4, 'Created borrow record #7 (equipment:6) status=reserved', '2025-11-08 21:44:04'),
(58, 4, 'Updated borrow record #7 status reserved -> borrowed', '2025-11-08 21:45:05'),
(59, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-08 21:45:05'),
(60, 4, 'Updated borrow record #7 status borrowed -> returned', '2025-11-08 22:23:01'),
(61, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-08 22:23:01'),
(62, 4, 'Updated borrow record #6 status borrowed -> returned', '2025-11-08 22:23:12'),
(63, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-08 22:23:12'),
(64, NULL, 'Created equipment: 9 - Goods 1', '2025-11-08 22:24:37'),
(65, 4, 'Created borrow record #8 (equipment:9) status=reserved', '2025-11-08 23:35:44'),
(66, 4, 'Updated borrow record #8 status reserved -> borrowed', '2025-11-08 23:36:04'),
(67, NULL, 'Updated equipment: 9 - Goods 1', '2025-11-08 23:36:04'),
(68, 4, 'Updated borrow record #8 status borrowed -> borrowed', '2025-11-08 23:36:04'),
(69, 4, 'Updated borrow record #2 status returned -> returned', '2025-11-08 23:37:49'),
(70, 4, 'Updated borrow record #3 status returned -> returned', '2025-11-08 23:37:49'),
(71, 4, 'Updated borrow record #5 status returned -> returned', '2025-11-08 23:37:49'),
(72, 4, 'Updated borrow record #6 status returned -> returned', '2025-11-08 23:37:49'),
(73, 4, 'Updated borrow record #7 status returned -> returned', '2025-11-08 23:37:49'),
(74, 4, 'Updated borrow record #8 status borrowed -> borrowed', '2025-11-08 23:37:49'),
(75, 4, 'Created borrow record #9 (equipment:6) status=reserved', '2025-11-09 00:02:23'),
(76, 4, 'Updated borrow record #8 status borrowed -> returned', '2025-11-09 00:03:12'),
(77, NULL, 'Updated equipment: 9 - Goods 1', '2025-11-09 00:03:12'),
(78, 4, 'Updated borrow record #9 status reserved -> borrowed', '2025-11-09 00:03:30'),
(79, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-09 00:03:30'),
(80, 4, 'Created borrow record #10 (equipment:8) status=reserved', '2025-11-09 00:29:10'),
(81, 4, 'Updated borrow record #10 status reserved -> borrowed', '2025-11-09 00:29:30'),
(82, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-09 00:29:30'),
(83, 4, 'Updated borrow record #9 status borrowed -> returned', '2025-11-09 00:36:39'),
(84, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-09 00:36:39'),
(85, 4, 'Updated borrow record #10 status borrowed -> returned', '2025-11-09 00:36:43'),
(86, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-09 00:36:43'),
(87, 4, 'Created borrow record #11 (equipment:9) status=reserved', '2025-11-09 03:01:32'),
(88, 4, 'Updated borrow record #11 status reserved -> borrowed', '2025-11-09 03:03:46'),
(89, NULL, 'Updated equipment: 9 - Goods 1', '2025-11-09 03:03:46'),
(90, 4, 'Updated borrow record #8 status returned -> returned', '2025-11-09 04:30:48'),
(91, 4, 'Updated borrow record #7 status returned -> returned', '2025-11-09 04:30:56'),
(92, 4, 'Deleted borrow record #2', '2025-11-09 04:31:42'),
(93, 4, 'Deleted borrow record #3', '2025-11-09 04:31:44'),
(94, 4, 'Deleted borrow record #4', '2025-11-09 04:31:54'),
(95, 4, 'Deleted borrow record #5', '2025-11-09 04:31:54'),
(96, 4, 'Deleted borrow record #6', '2025-11-09 04:31:54'),
(97, 4, 'Deleted borrow record #7', '2025-11-09 04:31:54'),
(98, 4, 'Deleted borrow record #8', '2025-11-09 04:31:54'),
(99, 4, 'Deleted borrow record #9', '2025-11-09 04:31:54'),
(100, 4, 'Deleted borrow record #10', '2025-11-09 04:31:54'),
(101, 4, 'Deleted borrow record #11', '2025-11-09 04:31:54'),
(102, 4, 'Created borrow record #12 (equipment:9) status=reserved', '2025-11-09 04:33:34'),
(103, 4, 'Updated borrow record #12 status reserved -> borrowed', '2025-11-09 04:34:37'),
(104, NULL, 'Updated equipment: 9 - Goods 1', '2025-11-09 04:34:37'),
(105, 4, 'Created borrow record #13 (equipment:8) status=reserved', '2025-11-11 13:49:09'),
(106, 4, 'Updated borrow record #13 status reserved -> borrowed', '2025-11-11 13:49:45'),
(107, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-11 13:49:45'),
(108, 4, 'Created borrow record #14 (equipment:6) status=reserved', '2025-11-11 13:53:01'),
(109, 4, 'Updated borrow record #14 status reserved -> borrowed', '2025-11-11 13:53:19'),
(110, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-11 13:53:19'),
(111, NULL, 'Created equipment: 10 - BARA', '2025-11-11 13:55:00'),
(112, NULL, 'Updated equipment: 10 - BARA', '2025-11-11 13:56:03'),
(113, NULL, 'Updated equipment: 10 - BARA', '2025-11-11 13:56:13'),
(114, 4, 'Updated borrow record #12 status borrowed -> returned', '2025-11-11 13:57:35'),
(115, NULL, 'Updated equipment: 9 - Goods 1', '2025-11-11 13:57:35'),
(116, 4, 'Updated borrow record #13 status borrowed -> returned', '2025-11-11 13:57:41'),
(117, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-11 13:57:41'),
(118, 4, 'Updated borrow record #14 status borrowed -> returned', '2025-11-11 13:57:45'),
(119, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-11 13:57:45'),
(120, 4, 'Created borrow record #15 (equipment:10) status=reserved', '2025-11-11 13:59:20'),
(121, 4, 'Updated borrow record #15 status reserved -> borrowed', '2025-11-11 13:59:55'),
(122, NULL, 'Updated equipment: 10 - BARA', '2025-11-11 13:59:55'),
(123, 4, 'Created borrow record #16 (equipment:6) status=reserved', '2025-11-11 14:13:58'),
(124, 4, 'Updated borrow record #16 status reserved -> borrowed', '2025-11-11 14:14:20'),
(125, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-11 14:14:20'),
(126, 4, 'Updated borrow record #14 status returned -> returned', '2025-11-22 05:19:15'),
(127, 4, 'Updated borrow record #14 status returned -> returned', '2025-11-22 05:22:04'),
(128, 4, 'Updated borrow record #12 status returned -> returned', '2025-11-22 05:38:08'),
(129, 4, 'Updated borrow record #13 status returned -> returned', '2025-11-22 05:38:10'),
(130, 4, 'Updated borrow record #12 status returned -> returned', '2025-11-22 06:15:42'),
(131, 4, 'Updated borrow record #13 status returned -> returned', '2025-11-22 06:15:44'),
(132, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:16:15'),
(133, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:01'),
(134, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:01'),
(135, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:09'),
(136, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:09'),
(137, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:25'),
(138, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:25'),
(139, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:36'),
(140, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:36'),
(141, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:41'),
(142, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:41'),
(143, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:42'),
(144, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:42'),
(145, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:42'),
(146, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:42'),
(147, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:43'),
(148, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:43'),
(149, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:43'),
(150, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:43'),
(151, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:44'),
(152, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:44'),
(153, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:44'),
(154, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:44'),
(155, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:45'),
(156, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:45'),
(157, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:45'),
(158, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:45'),
(159, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:18:57'),
(160, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:18:57'),
(161, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:19:06'),
(162, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:19:06'),
(163, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:24:12'),
(164, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:24:12'),
(165, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:24:13'),
(166, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:24:13'),
(167, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:24:13'),
(168, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:24:13'),
(169, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:24:14'),
(170, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:24:14'),
(171, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 06:24:14'),
(172, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 06:24:14'),
(173, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:24:24'),
(174, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:24:24'),
(175, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:25:04'),
(176, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:25:04'),
(177, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:25:06'),
(178, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:25:06'),
(179, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:25:21'),
(180, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:25:21'),
(181, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:26:21'),
(182, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:26:21'),
(183, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:26:23'),
(184, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:26:23'),
(185, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:26:25'),
(186, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:26:25'),
(187, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:27:01'),
(188, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:27:01'),
(189, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:32:28'),
(190, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:32:28'),
(191, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:32:29'),
(192, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:32:29'),
(193, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:33:44'),
(194, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:33:44'),
(195, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:33:46'),
(196, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:33:46'),
(197, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:39:25'),
(198, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:39:25'),
(199, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:39:36'),
(200, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:39:36'),
(201, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:47:12'),
(202, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:47:12'),
(203, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 20:57:02'),
(204, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 20:57:02'),
(205, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 21:05:41'),
(206, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 21:05:41'),
(207, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 21:12:08'),
(208, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 21:12:08'),
(209, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:15:36'),
(210, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:15:36'),
(211, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:15:39'),
(212, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:15:39'),
(213, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:20:24'),
(214, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:20:24'),
(215, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:20:44'),
(216, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:20:52'),
(217, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:20:52'),
(218, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:21:14'),
(219, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:21:22'),
(220, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:21:22'),
(221, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:28:31'),
(222, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:28:31'),
(223, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:28:33'),
(224, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:28:33'),
(225, 4, 'Updated borrow record #16 status borrowed -> borrowed', '2025-11-22 23:28:39'),
(226, 4, 'Updated borrow record #15 status borrowed -> borrowed', '2025-11-22 23:28:39'),
(227, 4, 'Updated borrow record #16 status borrowed -> returned', '2025-11-22 23:29:23'),
(228, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-22 23:29:23'),
(229, 4, 'Updated borrow record #15 status borrowed -> returned', '2025-11-22 23:29:25'),
(230, NULL, 'Updated equipment: 10 - BARA', '2025-11-22 23:29:25'),
(231, NULL, 'Deleted supply: 4 - Car', '2025-11-22 23:33:09'),
(232, NULL, 'Deleted supply: 3 - Pesticide', '2025-11-22 23:33:10'),
(233, NULL, 'Deleted supply: 2 - Seeds - Rice', '2025-11-22 23:33:13'),
(234, NULL, 'Created supply: 5 - CORN', '2025-11-22 23:38:40'),
(235, NULL, 'Updated supply: 5 - CORN', '2025-11-23 00:34:08'),
(236, NULL, 'Updated supply: 5 - CORN', '2025-11-23 00:50:35'),
(237, NULL, 'Updated supply: 5 - CORN', '2025-11-23 01:00:07'),
(238, 4, 'Created borrow record #17 (equipment:10) status=reserved', '2025-11-23 01:00:42'),
(239, 4, 'Updated borrow record #17 status reserved -> borrowed', '2025-11-23 01:02:23'),
(240, NULL, 'Updated equipment: 10 - BARA', '2025-11-23 01:02:23'),
(241, NULL, 'Updated supply: 5 - CORN', '2025-11-23 01:02:39'),
(242, 4, 'Created borrow record #18 (equipment:6) status=reserved', '2025-11-23 01:10:42'),
(243, NULL, 'Updated supply: 5 - CORN', '2025-11-23 01:11:54'),
(244, 4, 'Updated borrow record #18 status reserved -> borrowed', '2025-11-23 01:12:01'),
(245, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-23 01:12:01'),
(246, 4, 'Updated borrow record #17 status borrowed -> returned', '2025-11-23 01:22:25'),
(247, NULL, 'Updated equipment: 10 - BARA', '2025-11-23 01:22:25'),
(248, 4, 'Updated borrow record #18 status borrowed -> returned', '2025-11-23 01:22:29'),
(249, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-23 01:22:29'),
(250, 4, 'Created borrow record #19 (equipment:10) status=reserved', '2025-11-23 01:22:47'),
(251, 4, 'Updated borrow record #19 status reserved -> borrowed', '2025-11-23 01:23:15'),
(252, NULL, 'Updated equipment: 10 - BARA', '2025-11-23 01:23:15'),
(253, NULL, 'Updated supply: 5 - CORN', '2025-11-23 01:23:25'),
(254, 4, 'Updated borrow record #19 status borrowed -> returned', '2025-11-23 01:42:12'),
(255, NULL, 'Updated equipment: 10 - BARA', '2025-11-23 01:42:12'),
(256, 4, 'Created borrow record #20 (equipment:8) status=reserved', '2025-11-23 01:43:09'),
(257, 4, 'Updated borrow record #20 status reserved -> borrowed', '2025-11-23 01:43:26'),
(258, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-23 01:43:26'),
(259, 4, 'Updated borrow record #20 status borrowed -> returned', '2025-11-23 02:14:34'),
(260, NULL, 'Updated equipment: 8 - EXAMPLE 2', '2025-11-23 02:14:34'),
(261, 4, 'Created borrow record #21 (equipment:6) status=reserved', '2025-11-23 02:15:15'),
(262, 4, 'Updated borrow record #21 status reserved -> borrowed', '2025-11-23 02:15:26'),
(263, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-23 02:15:26'),
(264, NULL, 'Created equipment: 11 - Tractor', '2025-11-25 08:29:55'),
(265, NULL, 'Updated equipment: 11 - Tractor', '2025-11-25 08:30:10'),
(266, NULL, 'Deleted equipment: 10 - BARA', '2025-11-25 08:30:16'),
(267, NULL, 'Deleted equipment: 8 - EXAMPLE 2', '2025-11-25 08:30:18'),
(268, NULL, 'Deleted equipment: 9 - Goods 1', '2025-11-25 08:30:20'),
(269, NULL, 'Created supply: 6 - Banana', '2025-11-25 08:34:12'),
(270, NULL, 'Created supply: 7 - Fertilizer', '2025-11-25 08:42:46'),
(271, 4, 'Updated borrow record #21 status borrowed -> borrowed', '2025-11-25 08:55:03'),
(272, 4, 'Updated borrow record #21 status borrowed -> borrowed', '2025-11-25 08:55:28'),
(273, 4, 'Updated borrow record #21 status borrowed -> borrowed', '2025-11-25 08:55:30'),
(274, NULL, 'Updated supply: 5 - CORN', '2025-11-25 08:56:56'),
(275, 4, 'Updated borrow record #21 status borrowed -> returned', '2025-11-25 09:01:52'),
(276, NULL, 'Updated equipment: 6 - EXAMPLE', '2025-11-25 09:01:52'),
(277, NULL, 'Deleted equipment: 6 - EXAMPLE', '2025-11-25 09:12:33'),
(278, NULL, 'Created equipment: 12 - Wheel Barrow', '2025-11-25 09:15:53'),
(279, NULL, 'Updated equipment: 12 - Wheel Barrow', '2025-11-25 09:15:59'),
(280, NULL, 'Created equipment: 13 - Tiller', '2025-11-25 09:18:01');

-- --------------------------------------------------------

--
-- Table structure for table `borrow_records`
--

CREATE TABLE `borrow_records` (
  `borrow_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `item_id` int(10) UNSIGNED NOT NULL,
  `item_type` enum('equipment','supplies') NOT NULL,
  `date_borrowed` datetime DEFAULT NULL,
  `due_date` datetime NOT NULL,
  `return_date` datetime DEFAULT NULL,
  `status` enum('reserved','borrowed','returned','overdue','cancelled') NOT NULL DEFAULT 'reserved',
  `remarks` varchar(255) DEFAULT NULL,
  `daily_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_rental_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `late_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hidden_from_borrower` tinyint(1) NOT NULL DEFAULT 0,
  `hidden_from_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `borrow_records`
--

INSERT INTO `borrow_records` (`borrow_id`, `user_id`, `item_id`, `item_type`, `date_borrowed`, `due_date`, `return_date`, `status`, `remarks`, `daily_rate`, `total_rental_amount`, `late_fee_amount`, `total_amount_due`, `hidden_from_borrower`, `hidden_from_admin`, `created_at`) VALUES
(12, 4, 9, 'equipment', '2025-11-09 04:34:37', '2025-11-11 21:33:34', '2025-11-11 13:57:00', 'returned', 'HIRAM KO ANA', 20.00, 60.00, 0.00, 60.00, 1, 1, '2025-11-08 20:33:34'),
(13, 4, 8, 'equipment', '2025-11-11 13:49:45', '2025-11-13 06:49:09', '2025-11-11 13:57:00', 'returned', 'HIRAM KO', 50.00, 100.00, 0.00, 100.00, 1, 1, '2025-11-11 05:49:09'),
(14, 4, 6, 'equipment', '2025-11-11 13:53:19', '2025-11-18 06:53:01', '2025-11-11 13:57:00', 'returned', 'asdasdasd', 10.00, 70.00, 0.00, 70.00, 1, 1, '2025-11-11 05:53:01'),
(15, 4, 10, 'equipment', '2025-11-11 13:59:55', '2025-11-16 06:59:20', '2025-11-22 23:29:25', 'returned', 'GWAPO', 1000.00, 5000.00, 300.00, 5300.00, 0, 0, '2025-11-11 05:59:20'),
(16, 4, 6, 'equipment', '2025-11-11 14:14:20', '2025-11-13 07:13:58', '2025-11-22 23:29:23', 'returned', 'qweqweasd', 10.00, 20.00, 180.00, 200.00, 0, 0, '2025-11-11 06:13:58'),
(17, 4, 10, 'equipment', '2025-11-23 01:02:23', '2025-11-24 18:00:42', '2025-11-23 01:22:00', 'returned', 'GUSTO KO ANA BAYAD RAKO LATER', 1000.00, 2000.00, 0.00, 2000.00, 0, 0, '2025-11-22 17:00:42'),
(18, 4, 6, 'equipment', '2025-11-23 01:12:01', '2025-11-27 18:10:42', '2025-11-23 01:22:00', 'returned', 'GANAHAN KO ANA', 10.00, 50.00, 0.00, 50.00, 0, 0, '2025-11-22 17:10:42'),
(19, 4, 10, 'equipment', '2025-11-23 01:23:15', '2025-11-25 18:22:47', '2025-11-23 01:42:00', 'returned', 'GUSTO KO ANI', 1000.00, 3000.00, 0.00, 3000.00, 0, 0, '2025-11-22 17:22:47'),
(20, 4, 8, 'equipment', '2025-11-23 01:43:26', '2025-11-25 18:43:09', '2025-11-23 02:14:34', 'returned', 'GUSTO KO ANI', 50.00, 150.00, 0.00, 150.00, 0, 0, '2025-11-22 17:43:09'),
(21, 4, 6, 'equipment', '2025-11-23 02:15:26', '2025-11-24 19:15:15', '2025-11-25 09:01:00', 'returned', 'GGGG', 10.00, 20.00, 0.00, 20.00, 0, 0, '2025-11-22 18:15:15');

--
-- Triggers `borrow_records`
--
DELIMITER $$
CREATE TRIGGER `trg_borrow_after_del_log` AFTER DELETE ON `borrow_records` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (OLD.`user_id`, CONCAT('Deleted borrow record #', OLD.`borrow_id`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_borrow_after_ins_log` AFTER INSERT ON `borrow_records` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NEW.`user_id`, CONCAT('Created borrow record #', NEW.`borrow_id`, ' (', NEW.`item_type`, ':', NEW.`item_id`, ') status=', NEW.`status`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_borrow_after_insert` AFTER INSERT ON `borrow_records` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_borrow_after_upd_log` AFTER UPDATE ON `borrow_records` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NEW.`user_id`, CONCAT('Updated borrow record #', NEW.`borrow_id`, ' status ', OLD.`status`, ' -> ', NEW.`status`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_borrow_after_update` AFTER UPDATE ON `borrow_records` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_borrow_before_insert` BEFORE INSERT ON `borrow_records` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_borrow_before_update` BEFORE UPDATE ON `borrow_records` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `conversation_messages`
--

CREATE TABLE `conversation_messages` (
  `message_id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversation_messages`
--

INSERT INTO `conversation_messages` (`message_id`, `report_id`, `user_id`, `message`, `is_admin`, `created_at`) VALUES
(1, 1, 4, 'NAGUBA RAG KALIT UY', 0, '2025-10-26 06:08:40'),
(2, 1, 1, 'Ngano pod?', 1, '2025-10-26 06:09:05'),
(3, 2, 4, 'Way klaro uy ang items', 0, '2025-11-08 19:01:19'),
(4, 2, 1, 'Sige ayohon nato na', 1, '2025-11-08 19:03:35'),
(5, 3, 4, 'NAGUBA', 0, '2025-11-11 05:46:42'),
(6, 3, 1, 'AKOANG AyOHON', 1, '2025-11-11 05:47:31'),
(7, 4, 4, 'ASDASCZXC', 0, '2025-11-11 06:15:45'),
(8, 4, 1, 'Ayooo', 1, '2025-11-22 15:03:17'),
(9, 4, 4, 'Sige unya na', 0, '2025-11-22 15:11:40'),
(10, 4, 1, 'Sige2', 1, '2025-11-22 15:15:55');

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `equip_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('available','unavailable','maintenance','reserved') NOT NULL DEFAULT 'available',
  `daily_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_rental_days` int(10) UNSIGNED NOT NULL DEFAULT 30,
  `late_fee_per_day` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image_path` varchar(255) DEFAULT NULL,
  `image_alt` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `equipment`
--

INSERT INTO `equipment` (`equip_id`, `name`, `description`, `quantity`, `status`, `daily_rate`, `max_rental_days`, `late_fee_per_day`, `image_path`, `image_alt`, `created_at`) VALUES
(11, 'Tractor', 'Good condition', 2, 'available', 500.00, 30, 100.00, 'uploads/inventory/6924f8925f7b3_1764030610.jpg', '', '2025-11-25 00:29:55'),
(12, 'Wheel Barrow', 'Good condition', 3, 'available', 100.00, 30, 10.00, 'uploads/inventory/6925034f9e05a_1764033359.jpg', '', '2025-11-25 01:15:53'),
(13, 'Tiller', 'Good condition', 2, 'available', 120.00, 30, 15.00, 'uploads/inventory/692503c9bb0a2_1764033481.jpg', '', '2025-11-25 01:18:01');

--
-- Triggers `equipment`
--
DELIMITER $$
CREATE TRIGGER `trg_equipment_after_delete` AFTER DELETE ON `equipment` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Deleted equipment: ', OLD.`equip_id`, ' - ', OLD.`name`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_equipment_after_insert` AFTER INSERT ON `equipment` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Created equipment: ', NEW.`equip_id`, ' - ', NEW.`name`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_equipment_after_update` AFTER UPDATE ON `equipment` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Updated equipment: ', NEW.`equip_id`, ' - ', NEW.`name`));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(100) NOT NULL,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `last_attempt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `email`, `attempts`, `last_attempt`) VALUES
(27, 'admin@cbr.com', 1, '2025-11-25 00:49:22');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `borrow_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'cash',
  `payment_reference` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `payment_status` enum('pending','confirmed','cancelled') DEFAULT 'confirmed',
  `hidden_from_borrower` tinyint(1) NOT NULL DEFAULT 0,
  `hidden_from_admin` tinyint(1) NOT NULL DEFAULT 0,
  `date_paid` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `borrow_id`, `amount`, `payment_method`, `payment_reference`, `proof_image`, `payment_status`, `hidden_from_borrower`, `hidden_from_admin`, `date_paid`) VALUES
(9, 12, 60.00, 'gcash', '123123', 'uploads/payments/690fa9883e986_1762634120.png', 'confirmed', 1, 1, '2025-11-09 04:35:20'),
(10, 13, 100.00, 'gcash', '786545', 'uploads/payments/6912cedc0f2d1_1762840284.png', 'confirmed', 1, 1, '2025-11-11 13:51:24'),
(11, 15, 5000.00, 'gcash', '0930414', 'uploads/payments/6912d4771ae3d_1762841719.jpg', 'confirmed', 1, 1, '2025-11-11 14:15:19'),
(12, 16, 200.00, 'gcash', '456456456', 'uploads/payments/6921d4ccb0492_1763824844.jpg', 'confirmed', 1, 0, '2025-11-22 23:20:44'),
(13, 15, 300.00, 'gcash', '890789789678', 'uploads/payments/6921d4ea095c7_1763824874.jpg', 'confirmed', 1, 0, '2025-11-22 23:21:14'),
(14, 17, 2000.00, 'cash', '', NULL, 'confirmed', 0, 0, '2025-11-23 01:09:25'),
(15, 18, 50.00, 'cash', '', NULL, 'confirmed', 0, 0, '2025-11-23 01:12:38'),
(16, 19, 3000.00, 'cash', '', NULL, 'confirmed', 0, 0, '2025-11-23 01:23:42'),
(17, 20, 150.00, 'cash', '', NULL, '', 0, 1, '2025-11-23 01:43:40'),
(18, 20, 150.00, 'cash', '', NULL, 'confirmed', 1, 1, '2025-11-23 02:07:04'),
(19, 21, 20.00, 'gcash', '1244567567', 'uploads/payments/6921fddb0cae8_1763835355.jpg', 'confirmed', 0, 0, '2025-11-23 02:15:55');

-- --------------------------------------------------------

--
-- Table structure for table `payment_method_settings`
--

CREATE TABLE `payment_method_settings` (
  `setting_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `method_name` varchar(100) NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `account_number` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `icon` varchar(10) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_method_settings`
--

INSERT INTO `payment_method_settings` (`setting_id`, `payment_method`, `method_name`, `is_available`, `account_number`, `account_name`, `instructions`, `icon`, `display_order`, `created_at`, `updated_at`) VALUES
(1, 'cash', 'Cash (On-site Payment)', 1, '', '', '', '💵', 1, '2025-11-22 13:31:24', '2025-11-22 15:19:22'),
(2, 'gcash', 'GCash (Online Payment)', 1, '09356313342', 'LE****L C.', '', '📱', 2, '2025-11-22 13:31:24', '2025-11-22 15:19:22'),
(3, 'paymaya', 'PayMaya (Online Payment)', 0, '', '', '', '💳', 3, '2025-11-22 13:31:24', '2025-11-22 15:19:22'),
(4, 'bank_transfer', 'Bank Transfer (Online Payment)', 0, '', '', '', '🏦', 4, '2025-11-22 13:31:24', '2025-11-22 15:19:22'),
(5, 'others', 'Other Online Payment', 0, '', '', '', '📲', 5, '2025-11-22 13:31:24', '2025-11-22 15:19:22');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_records`
--

CREATE TABLE `purchase_records` (
  `purchase_id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `supply_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_records`
--

INSERT INTO `purchase_records` (`purchase_id`, `user_id`, `supply_id`, `quantity`, `unit_price`, `total_amount`, `payment_status`, `payment_method`, `payment_reference`, `proof_image`, `status`, `remarks`, `created_at`, `confirmed_at`) VALUES
(4, 4, 5, 1, 120.00, 120.00, 'confirmed', 'cash', '', NULL, 'confirmed', '', '2025-11-22 16:57:07', '2025-11-22 17:00:07'),
(5, 4, 5, 3, 120.00, 360.00, 'confirmed', 'gcash', '56456', 'uploads/payments/6921ec684f28e_1763830888.webp', 'confirmed', '', '2025-11-22 17:01:17', '2025-11-22 17:02:39'),
(6, 4, 5, 10, 120.00, 1200.00, 'confirmed', 'cash', '', NULL, 'confirmed', '', '2025-11-22 17:10:15', '2025-11-22 17:11:54'),
(7, 4, 5, 10, 120.00, 1200.00, 'confirmed', 'cash', '', NULL, 'confirmed', '', '2025-11-22 17:22:56', '2025-11-22 17:23:25'),
(9, 4, 5, 5, 120.00, 600.00, 'confirmed', 'gcash', '0930414', 'uploads/payments/6924feb9a6e03_1764032185.jpg', 'confirmed', '', '2025-11-25 00:56:11', '2025-11-25 00:56:56'),
(10, 11, 6, 5, 50.00, 250.00, 'rejected', 'gcash', '1244567567', 'uploads/payments/6924ff8e152d3_1764032398.jpg', 'cancelled', '', '2025-11-25 00:59:47', '2025-11-25 01:00:45');

-- --------------------------------------------------------

--
-- Table structure for table `supplies`
--

CREATE TABLE `supplies` (
  `supply_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('available','unavailable','maintenance','reserved') NOT NULL DEFAULT 'available',
  `daily_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_rental_days` int(10) UNSIGNED NOT NULL DEFAULT 30,
  `late_fee_per_day` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image_path` varchar(255) DEFAULT NULL,
  `image_alt` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `supplies`
--

INSERT INTO `supplies` (`supply_id`, `name`, `description`, `quantity`, `status`, `daily_rate`, `unit_price`, `max_rental_days`, `late_fee_per_day`, `image_path`, `image_alt`, `created_at`) VALUES
(5, 'CORN', 'MAIS SEEDS', 15, 'available', 0.00, 120.00, 30, 0.00, 'uploads/inventory/6921d90018bd3_1763825920.jpg', '', '2025-11-22 15:38:40'),
(6, 'Banana', 'Banana seeds', 100, 'available', 0.00, 50.00, 30, 0.00, 'uploads/inventory/6924f9840a84a_1764030852.webp', '', '2025-11-25 00:34:12'),
(7, 'Fertilizer', 'Pure Organic', 50, 'available', 0.00, 30.00, 30, 0.00, 'uploads/inventory/6924fb86b46d7_1764031366.jpg', '', '2025-11-25 00:42:46');

--
-- Triggers `supplies`
--
DELIMITER $$
CREATE TRIGGER `trg_supplies_after_delete` AFTER DELETE ON `supplies` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Deleted supply: ', OLD.`supply_id`, ' - ', OLD.`name`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_supplies_after_insert` AFTER INSERT ON `supplies` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Created supply: ', NEW.`supply_id`, ' - ', NEW.`name`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_supplies_after_update` AFTER UPDATE ON `supplies` FOR EACH ROW BEGIN
	INSERT INTO `activity_log` (`user_id`, `action`)
	VALUES (NULL, CONCAT('Updated supply: ', NEW.`supply_id`, ' - ', NEW.`name`));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `idnum` varchar(20) NOT NULL,
  `Fname` varchar(50) NOT NULL,
  `Mname` varchar(50) DEFAULT NULL,
  `Lname` varchar(50) NOT NULL,
  `Suffix` varchar(10) DEFAULT NULL,
  `mail` varchar(100) NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `Birthday` date NOT NULL,
  `Age` int(10) UNSIGNED NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `Street` varchar(100) NOT NULL,
  `Barangay` varchar(50) NOT NULL,
  `City` varchar(50) NOT NULL,
  `Province` varchar(50) NOT NULL,
  `Country` varchar(50) NOT NULL,
  `ZipCode` varchar(10) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff','borrower') NOT NULL DEFAULT 'borrower',
  `secQ1` varchar(255) NOT NULL,
  `secA1` varchar(255) NOT NULL,
  `secQ2` varchar(255) NOT NULL,
  `secA2` varchar(255) NOT NULL,
  `secQ3` varchar(255) NOT NULL,
  `secA3` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `idnum`, `Fname`, `Mname`, `Lname`, `Suffix`, `mail`, `sex`, `Birthday`, `Age`, `mobile`, `Street`, `Barangay`, `City`, `Province`, `Country`, `ZipCode`, `password`, `role`, `secQ1`, `secA1`, `secQ2`, `secA2`, `secQ3`, `secA3`, `created_at`) VALUES
(1, '2024-0001', 'Admin', 'System', 'User', '', 'admin@cbr.com', 'Male', '1990-01-01', 34, '09123456789', 'Main Street', 'Poblacion', 'Cabadbaran', 'Agusan del Norte', 'Philippines', '8600', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'What is your favorite dessert?', 'cake', 'What was the name of your school in Highchool?', 'CSUCC', 'In what city were you born?', 'Cabadbaran', '2025-10-15 05:15:36'),
(3, '0000-0001', 'Leonard', 'Sumalindong', 'Consistente', '', 'leonard@gmail.com', 'Male', '2005-04-08', 20, '09356313343', 'Villanueva-st', 'TOLOSA', 'Cabadbaran', 'Agusan del Norte', 'PH', '8016', '$2y$10$pf4eK3FglPXpZeZVKNvvhezWcAwAqizp8gDCIV1pxI6oQ.cGed7Ya', 'staff', 'pet', 'salad', 'city', 'esperanza', 'food', 'blue', '2025-10-22 08:31:24'),
(4, '2025-0001', 'Loy', 'Loy', 'Gwapo', '', 'loy@gmail.com', 'Male', '2005-04-08', 20, '09356313342', 'Villanueva-st', 'TOLOSA', 'Cabadbaran', 'Agusan del Norte', 'PH', '8016', '$2y$10$T1VqXCAYpCJ0tadokSQqHOMnS0YA1tzSg3acwkCwcmoe31fQp94I6', 'borrower', 'pet', 'salad', 'city', 'esperanza', 'food', 'blue', '2025-10-23 07:06:04'),
(11, '2025-0002', 'Onard', 'Sumalindong', 'Consistente', '', 'leoleo123@gmail.com', 'Male', '2005-04-08', 20, '09356313342', 'Basketballan', 'Tolosa', 'Cabadbaran', 'Agusan del Norte', 'Philippine', '8513', '$2y$10$X3sA9um72fwWiY18baNZwOR.Mf1lfBsftdKxgFxEfbR6jamKHM6Ze', 'borrower', 'pet', 'salad', 'school', 'esperanza', 'food', 'blue', '2025-11-25 00:59:06');

-- --------------------------------------------------------

--
-- Table structure for table `user_reports`
--

CREATE TABLE `user_reports` (
  `report_id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `last_viewed_by_user` timestamp NULL DEFAULT NULL,
  `last_viewed_by_admin` timestamp NULL DEFAULT NULL,
  `message_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_reports`
--

INSERT INTO `user_reports` (`report_id`, `user_id`, `subject`, `message`, `status`, `created_at`, `resolved_at`, `last_message_at`, `last_viewed_by_user`, `last_viewed_by_admin`, `message_count`) VALUES
(1, 4, 'PROBLEM ITEMS', 'NAGUBA RAG KALIT UY', 'resolved', '2025-10-26 06:08:40', '2025-10-26 06:09:10', '2025-10-26 06:09:05', NULL, NULL, 2),
(2, 4, 'PROBLEM ITEMS', 'Way klaro uy ang items', 'resolved', '2025-11-08 19:01:19', '2025-11-11 05:47:48', '2025-11-08 19:03:35', NULL, NULL, 2),
(3, 4, 'Expired nga Seeds', 'NAGUBA', 'resolved', '2025-11-11 05:46:42', '2025-11-11 05:47:47', '2025-11-11 05:47:31', '2025-11-25 01:06:05', NULL, 2),
(4, 4, 'RELAX RA', 'ASDASCZXC', 'open', '2025-11-11 06:15:45', NULL, '2025-11-22 15:15:55', '2025-11-25 01:06:08', '2025-11-25 01:09:51', 4);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_active_borrowers`
-- (See below for the actual view)
--
CREATE TABLE `v_active_borrowers` (
`user_id` int(10) unsigned
,`name` varchar(101)
,`active_transactions` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_current_borrows`
-- (See below for the actual view)
--
CREATE TABLE `v_current_borrows` (
`borrow_id` int(10) unsigned
,`user_id` int(10) unsigned
,`user_name` varchar(101)
,`item_type` enum('equipment','supplies')
,`item_id` int(10) unsigned
,`item_name` varchar(150)
,`date_borrowed` datetime
,`due_date` datetime
,`status` enum('reserved','borrowed','returned','overdue','cancelled')
,`remarks` varchar(255)
,`daily_rate` decimal(10,2)
,`total_rental_amount` decimal(10,2)
,`late_fee_amount` decimal(10,2)
,`total_amount_due` decimal(10,2)
,`overdue_days` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_most_borrowed_items`
-- (See below for the actual view)
--
CREATE TABLE `v_most_borrowed_items` (
`item_type` enum('equipment','supplies')
,`item_id` int(10) unsigned
,`item_name` varchar(150)
,`times_borrowed` decimal(23,0)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_overdue_items`
-- (See below for the actual view)
--
CREATE TABLE `v_overdue_items` (
`borrow_id` int(10) unsigned
,`user_id` int(10) unsigned
,`user_name` varchar(101)
,`item_type` enum('equipment','supplies')
,`item_id` int(10) unsigned
,`item_name` varchar(150)
,`date_borrowed` datetime
,`due_date` datetime
,`status` enum('reserved','borrowed','returned','overdue','cancelled')
,`overdue_days` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_report_conversations`
-- (See below for the actual view)
--
CREATE TABLE `v_report_conversations` (
`report_id` int(11)
,`user_id` int(10) unsigned
,`subject` varchar(255)
,`status` enum('open','resolved')
,`created_at` timestamp
,`resolved_at` timestamp
,`last_message_at` timestamp
,`message_count` int(11)
,`Fname` varchar(50)
,`Lname` varchar(50)
,`user_name` varchar(101)
,`conversation_status` varchar(8)
);

-- --------------------------------------------------------

--
-- Structure for view `v_active_borrowers`
--
DROP TABLE IF EXISTS `v_active_borrowers`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_active_borrowers`  AS SELECT `u`.`user_id` AS `user_id`, concat(`u`.`Fname`,' ',`u`.`Lname`) AS `name`, count(0) AS `active_transactions` FROM (`borrow_records` `b` join `users` `u` on(`u`.`user_id` = `b`.`user_id`)) WHERE `b`.`status` in ('reserved','borrowed','overdue') GROUP BY `u`.`user_id`, `u`.`Fname`, `u`.`Lname` ;

-- --------------------------------------------------------

--
-- Structure for view `v_current_borrows`
--
DROP TABLE IF EXISTS `v_current_borrows`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_current_borrows`  AS SELECT `b`.`borrow_id` AS `borrow_id`, `b`.`user_id` AS `user_id`, concat(`u`.`Fname`,' ',`u`.`Lname`) AS `user_name`, `b`.`item_type` AS `item_type`, `b`.`item_id` AS `item_id`, CASE WHEN `b`.`item_type` = 'equipment' THEN (select `e`.`name` from `equipment` `e` where `e`.`equip_id` = `b`.`item_id`) WHEN `b`.`item_type` = 'supplies' THEN (select `s`.`name` from `supplies` `s` where `s`.`supply_id` = `b`.`item_id`) END AS `item_name`, `b`.`date_borrowed` AS `date_borrowed`, `b`.`due_date` AS `due_date`, `b`.`status` AS `status`, `b`.`remarks` AS `remarks`, `b`.`daily_rate` AS `daily_rate`, `b`.`total_rental_amount` AS `total_rental_amount`, `b`.`late_fee_amount` AS `late_fee_amount`, `b`.`total_amount_due` AS `total_amount_due`, CASE WHEN `b`.`status` in ('borrowed','overdue') AND `b`.`due_date` < current_timestamp() THEN timestampdiff(DAY,`b`.`due_date`,current_timestamp()) WHEN `b`.`status` = 'returned' AND `b`.`return_date` is not null AND `b`.`return_date` > `b`.`due_date` THEN timestampdiff(DAY,`b`.`due_date`,`b`.`return_date`) ELSE 0 END AS `overdue_days` FROM (`borrow_records` `b` join `users` `u` on(`u`.`user_id` = `b`.`user_id`)) WHERE `b`.`status` in ('reserved','borrowed','overdue') ;

-- --------------------------------------------------------

--
-- Structure for view `v_most_borrowed_items`
--
DROP TABLE IF EXISTS `v_most_borrowed_items`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_most_borrowed_items`  AS SELECT `b`.`item_type` AS `item_type`, `b`.`item_id` AS `item_id`, CASE WHEN `b`.`item_type` = 'equipment' THEN (select `e`.`name` from `equipment` `e` where `e`.`equip_id` = `b`.`item_id`) WHEN `b`.`item_type` = 'supplies' THEN (select `s`.`name` from `supplies` `s` where `s`.`supply_id` = `b`.`item_id`) END AS `item_name`, sum(`b`.`status` in ('borrowed','returned','overdue')) AS `times_borrowed` FROM `borrow_records` AS `b` GROUP BY `b`.`item_type`, `b`.`item_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_overdue_items`
--
DROP TABLE IF EXISTS `v_overdue_items`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_overdue_items`  AS SELECT `cb`.`borrow_id` AS `borrow_id`, `cb`.`user_id` AS `user_id`, `cb`.`user_name` AS `user_name`, `cb`.`item_type` AS `item_type`, `cb`.`item_id` AS `item_id`, `cb`.`item_name` AS `item_name`, `cb`.`date_borrowed` AS `date_borrowed`, `cb`.`due_date` AS `due_date`, `cb`.`status` AS `status`, `cb`.`overdue_days` AS `overdue_days` FROM `v_current_borrows` AS `cb` WHERE `cb`.`status` in ('borrowed','overdue') AND `cb`.`due_date` < current_timestamp() ;

-- --------------------------------------------------------

--
-- Structure for view `v_report_conversations`
--
DROP TABLE IF EXISTS `v_report_conversations`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_report_conversations`  AS SELECT `r`.`report_id` AS `report_id`, `r`.`user_id` AS `user_id`, `r`.`subject` AS `subject`, `r`.`status` AS `status`, `r`.`created_at` AS `created_at`, `r`.`resolved_at` AS `resolved_at`, `r`.`last_message_at` AS `last_message_at`, `r`.`message_count` AS `message_count`, `u`.`Fname` AS `Fname`, `u`.`Lname` AS `Lname`, concat(`u`.`Fname`,' ',`u`.`Lname`) AS `user_name`, CASE WHEN `r`.`status` = 'resolved' THEN 'resolved' WHEN `r`.`last_message_at` is null THEN 'new' ELSE 'active' END AS `conversation_status` FROM (`user_reports` `r` join `users` `u` on(`r`.`user_id` = `u`.`user_id`)) ORDER BY `r`.`last_message_at` DESC, `r`.`created_at` DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `ix_log_user` (`user_id`),
  ADD KEY `ix_log_datetime` (`date_time`);

--
-- Indexes for table `borrow_records`
--
ALTER TABLE `borrow_records`
  ADD PRIMARY KEY (`borrow_id`),
  ADD KEY `ix_borrow_user` (`user_id`),
  ADD KEY `ix_borrow_due_date` (`due_date`),
  ADD KEY `ix_borrow_status` (`status`),
  ADD KEY `ix_borrow_item` (`item_type`,`item_id`),
  ADD KEY `ix_hidden_borrower_br` (`hidden_from_borrower`),
  ADD KEY `ix_hidden_admin_br` (`hidden_from_admin`);

--
-- Indexes for table `conversation_messages`
--
ALTER TABLE `conversation_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `fk_conversation_user` (`user_id`),
  ADD KEY `idx_conversation_report` (`report_id`),
  ADD KEY `idx_conversation_created` (`created_at`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`equip_id`),
  ADD KEY `ix_equipment_name` (`name`),
  ADD KEY `ix_equipment_status` (`status`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_login_attempts_email` (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `ix_payment_borrow` (`borrow_id`),
  ADD KEY `ix_payment_date` (`date_paid`),
  ADD KEY `ix_hidden_borrower` (`hidden_from_borrower`),
  ADD KEY `ix_hidden_admin` (`hidden_from_admin`),
  ADD KEY `ix_payment_method` (`payment_method`),
  ADD KEY `ix_payment_status` (`payment_status`);

--
-- Indexes for table `payment_method_settings`
--
ALTER TABLE `payment_method_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `payment_method` (`payment_method`),
  ADD KEY `idx_payment_method_available` (`is_available`,`display_order`);

--
-- Indexes for table `purchase_records`
--
ALTER TABLE `purchase_records`
  ADD PRIMARY KEY (`purchase_id`),
  ADD KEY `idx_purchase_user` (`user_id`),
  ADD KEY `idx_purchase_supply` (`supply_id`);

--
-- Indexes for table `supplies`
--
ALTER TABLE `supplies`
  ADD PRIMARY KEY (`supply_id`),
  ADD KEY `ix_supplies_name` (`name`),
  ADD KEY `ix_supplies_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `ux_users_idnum` (`idnum`),
  ADD UNIQUE KEY `ux_users_mail` (`mail`);

--
-- Indexes for table `user_reports`
--
ALTER TABLE `user_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `fk_user_reports_user` (`user_id`),
  ADD KEY `idx_last_viewed_user` (`last_viewed_by_user`),
  ADD KEY `idx_last_viewed_admin` (`last_viewed_by_admin`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=281;

--
-- AUTO_INCREMENT for table `borrow_records`
--
ALTER TABLE `borrow_records`
  MODIFY `borrow_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `conversation_messages`
--
ALTER TABLE `conversation_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `equip_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `payment_method_settings`
--
ALTER TABLE `payment_method_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `purchase_records`
--
ALTER TABLE `purchase_records`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `supplies`
--
ALTER TABLE `supplies`
  MODIFY `supply_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_reports`
--
ALTER TABLE `user_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `borrow_records`
--
ALTER TABLE `borrow_records`
  ADD CONSTRAINT `fk_borrow_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `conversation_messages`
--
ALTER TABLE `conversation_messages`
  ADD CONSTRAINT `fk_conversation_report` FOREIGN KEY (`report_id`) REFERENCES `user_reports` (`report_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_conversation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payment_borrow` FOREIGN KEY (`borrow_id`) REFERENCES `borrow_records` (`borrow_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchase_records`
--
ALTER TABLE `purchase_records`
  ADD CONSTRAINT `fk_purchase_supply` FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`supply_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `user_reports`
--
ALTER TABLE `user_reports`
  ADD CONSTRAINT `fk_user_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
