-- Create purchase system for supplies
-- Supplies are for purchase, not rental

-- Create purchase_records table
CREATE TABLE IF NOT EXISTS purchase_records (
    purchase_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    supply_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(50) NULL,
    payment_reference VARCHAR(100) NULL,
    proof_image VARCHAR(255) NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    CONSTRAINT fk_purchase_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_purchase_supply FOREIGN KEY (supply_id) REFERENCES supplies(supply_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create purchase_payments table (similar to payments but for purchases)
CREATE TABLE IF NOT EXISTS purchase_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) NULL,
    payment_reference VARCHAR(100) NULL,
    proof_image VARCHAR(255) NULL,
    payment_status ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
    date_paid DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hidden_from_borrower TINYINT(1) DEFAULT 0,
    CONSTRAINT fk_purchase_payment FOREIGN KEY (purchase_id) REFERENCES purchase_records(purchase_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add indexes
CREATE INDEX idx_purchase_user ON purchase_records(user_id);
CREATE INDEX idx_purchase_supply ON purchase_records(supply_id);
CREATE INDEX idx_purchase_status ON purchase_records(status);
CREATE INDEX idx_purchase_payment_status ON purchase_records(payment_status);
CREATE INDEX idx_purchase_payment_purchase ON purchase_payments(purchase_id);

