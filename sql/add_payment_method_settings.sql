-- Create payment method settings table
-- This allows admin/staff to configure payment methods (enable/disable and set account numbers)

CREATE TABLE IF NOT EXISTS payment_method_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    payment_method VARCHAR(50) NOT NULL UNIQUE,
    method_name VARCHAR(100) NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    account_number VARCHAR(255) NULL,
    account_name VARCHAR(255) NULL,
    instructions TEXT NULL,
    icon VARCHAR(10) NULL,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default payment methods
INSERT INTO payment_method_settings (payment_method, method_name, is_available, icon, display_order) VALUES
('cash', 'Cash (On-site Payment)', TRUE, '💵', 1),
('gcash', 'GCash (Online Payment)', TRUE, '📱', 2),
('paymaya', 'PayMaya (Online Payment)', TRUE, '💳', 3),
('bank_transfer', 'Bank Transfer (Online Payment)', TRUE, '🏦', 4),
('others', 'Other Online Payment', TRUE, '📲', 5)
ON DUPLICATE KEY UPDATE method_name = VALUES(method_name);

-- Add index for faster lookups (only if it doesn't exist)
SET @dbname = DATABASE();
SET @tablename = 'payment_method_settings';
SET @indexname = 'idx_payment_method_available';
SET @preparedStatement = (
	SELECT IF(
		(
			SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
			WHERE table_schema = @dbname 
			AND table_name = @tablename 
			AND index_name = @indexname
		) > 0,
		'SELECT 1',
		CONCAT('CREATE INDEX ', @indexname, ' ON ', @tablename, '(is_available, display_order)')
	)
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

