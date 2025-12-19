-- Convert supplies from rental system to purchase system
-- Supplies should have a price per unit instead of daily_rate

-- Add unit_price column to supplies table
ALTER TABLE supplies 
ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER daily_rate;

-- Migrate existing daily_rate to unit_price (if daily_rate was used as price)
-- Update unit_price = daily_rate for existing supplies
UPDATE supplies SET unit_price = daily_rate WHERE unit_price = 0 AND daily_rate > 0;

