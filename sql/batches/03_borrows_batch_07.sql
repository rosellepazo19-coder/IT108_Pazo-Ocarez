-- Batch file: 03_borrows_batch_07.sql
-- Borrow records batch 7
-- Generated: 2025-12-10 09:46:00

USE `cabadbaran_agriculture`;

START TRANSACTION;

INSERT INTO `borrow_records` (`user_id`,`item_id`,`item_type`,`date_borrowed`,`due_date`,`return_date`,`status`,`remarks`) VALUES
(9956, 4, 'equipment', '2023-02-09 13:18:00', '2023-02-05 13:18:00', NULL, 'overdue', 'auto-gen'),
(9960, 4, 'supplies', '2024-09-04 16:27:00', '2024-09-17 16:27:00', '2024-09-14 16:27:00', 'returned', 'auto-gen'),
(9962, 2, 'supplies', '2023-10-19 13:29:00', '2023-10-30 13:29:00', NULL, 'borrowed', 'auto-gen'),
(9964, 3, 'equipment', NULL, '2025-03-06 14:43:00', NULL, 'reserved', 'auto-gen'),
(9967, 5, 'supplies', NULL, '2025-02-06 12:58:00', NULL, 'reserved', 'auto-gen'),
(9968, 1, 'supplies', '2023-04-26 13:30:00', '2023-05-15 13:30:00', NULL, 'borrowed', 'auto-gen'),
(9969, 3, 'equipment', '2023-10-17 12:45:00', '2023-10-22 12:45:00', NULL, 'borrowed', 'auto-gen'),
(9970, 3, 'equipment', '2023-08-01 08:05:00', '2023-08-15 08:05:00', NULL, 'borrowed', 'auto-gen'),
(9971, 5, 'supplies', '2024-09-21 12:59:00', '2024-10-10 12:59:00', NULL, 'borrowed', 'auto-gen'),
(9972, 3, 'supplies', '2023-08-17 14:14:00', '2023-08-24 14:14:00', NULL, 'borrowed', 'auto-gen'),
(9973, 4, 'supplies', '2023-11-07 20:06:00', '2023-11-14 20:06:00', NULL, 'borrowed', 'auto-gen'),
(9974, 3, 'equipment', '2024-04-10 15:42:00', '2024-04-21 15:42:00', NULL, 'borrowed', 'auto-gen'),
(9975, 5, 'equipment', '2024-03-23 10:16:00', '2024-04-01 10:16:00', NULL, 'borrowed', 'auto-gen'),
(9976, 5, 'equipment', '2025-12-27 12:27:00', '2026-01-04 12:27:00', '2025-12-29 12:27:00', 'returned', 'auto-gen'),
(9977, 1, 'supplies', NULL, '2024-02-04 16:07:00', NULL, 'reserved', 'auto-gen'),
(9979, 1, 'supplies', '2023-04-02 11:26:00', '2023-04-16 11:26:00', NULL, 'borrowed', 'auto-gen'),
(9982, 2, 'supplies', NULL, '2023-12-06 12:46:00', NULL, 'reserved', 'auto-gen'),
(9985, 2, 'supplies', '2025-03-22 16:32:00', '2025-04-01 16:32:00', NULL, 'borrowed', 'auto-gen'),
(9986, 3, 'supplies', NULL, '2024-02-02 12:41:00', NULL, 'reserved', 'auto-gen'),
(9987, 1, 'supplies', '2024-11-07 13:40:00', '2024-11-19 13:40:00', NULL, 'borrowed', 'auto-gen'),
(9988, 2, 'supplies', NULL, '2025-10-16 09:10:00', NULL, 'reserved', 'auto-gen'),
(9989, 2, 'supplies', '2024-10-19 18:16:00', '2024-10-30 18:16:00', NULL, 'borrowed', 'auto-gen'),
(9992, 5, 'equipment', '2024-06-21 16:46:00', '2024-07-04 16:46:00', NULL, 'borrowed', 'auto-gen'),
(9997, 5, 'equipment', '2023-09-07 10:16:00', '2023-09-13 10:16:00', '2023-09-09 10:16:00', 'returned', 'auto-gen'),
(9999, 2, 'supplies', '2024-12-05 12:25:00', '2024-12-12 12:25:00', NULL, 'borrowed', 'auto-gen');

COMMIT;
