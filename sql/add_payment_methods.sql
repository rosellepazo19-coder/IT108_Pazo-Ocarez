-- Add payment method columns to payments table for online payments
USE `cabadbaran_agriculture`;

-- Check and add payment_method column
SET @dbname = DATABASE();
SET @tablename = 'payments';
SET @columnname = 'payment_method';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(\'cash\',\'gcash\',\'paymaya\',\'bank_transfer\',\'others\') NOT NULL DEFAULT \'cash\' AFTER `amount`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add payment_reference column
SET @columnname = 'payment_reference';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(100) NULL AFTER `payment_method`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add proof_image column
SET @columnname = 'proof_image';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(255) NULL AFTER `payment_reference`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add payment_status column
SET @columnname = 'payment_status';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(\'pending\',\'confirmed\',\'rejected\') NOT NULL DEFAULT \'pending\' AFTER `proof_image`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add indexes if they don't exist (simple approach - will fail silently if index exists)
-- Note: MySQL doesn't have IF NOT EXISTS for indexes, so we'll just try to add them
SET @preparedStatement = 'ALTER TABLE payments ADD INDEX ix_payment_method (payment_method)';
SET @sql = @preparedStatement;
SET @ignore = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = @dbname 
  AND table_name = @tablename 
  AND index_name = 'ix_payment_method'
);
SET @preparedStatement = IF(@ignore > 0, 'SELECT 1', @preparedStatement);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = 'ALTER TABLE payments ADD INDEX ix_payment_status (payment_status)';
SET @sql = @preparedStatement;
SET @ignore = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = @dbname 
  AND table_name = @tablename 
  AND index_name = 'ix_payment_status'
);
SET @preparedStatement = IF(@ignore > 0, 'SELECT 1', @preparedStatement);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

