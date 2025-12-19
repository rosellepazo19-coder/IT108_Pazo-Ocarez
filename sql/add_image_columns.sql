-- Add image columns to equipment and supplies tables
-- Run this script to add image functionality to existing inventory

USE `cbr_agriculture`;

-- Add image column to equipment table
ALTER TABLE `equipment` 
ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `status`,
ADD COLUMN `image_alt` VARCHAR(150) NULL AFTER `image_path`;

-- Add image column to supplies table  
ALTER TABLE `supplies` 
ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `status`,
ADD COLUMN `image_alt` VARCHAR(150) NULL AFTER `image_path`;

-- Add indexes for better performance
ALTER TABLE `equipment` ADD INDEX `ix_equipment_image` (`image_path`);
ALTER TABLE `supplies` ADD INDEX `ix_supplies_image` (`image_path`);
