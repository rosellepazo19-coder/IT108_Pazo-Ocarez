-- Batch file: 01_base_inventory.sql
-- Equipment and Supplies inventory
-- Generated: 2025-12-10 09:46:00

USE `cabadbaran_agriculture`;

START TRANSACTION;

INSERT INTO `equipment` (`name`,`description`,`quantity`,`status`) VALUES
('Hand Tractor','Used for land preparation',10000,'available'),
('Water Pump','For irrigation purposes',10000,'available'),
('Sprayer','For pesticide application',10000,'available'),
('Power Tiller','Bulk equipment',10000,'available'),
('Seeder','Row seeder',10000,'available');

INSERT INTO `supplies` (`name`,`description`,`quantity`,`status`) VALUES
('Fertilizer 14-14-14','Balanced fertilizer',20000,'available'),
('Seeds - Rice','High-yield rice seeds',20000,'available'),
('Pesticide','Organic pesticide',20000,'available'),
('Urea Bulk','Nitrogen fertilizer',20000,'available'),
('Corn Seeds','Hybrid corn seeds',20000,'available');

COMMIT;
