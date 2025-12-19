USE `cbr_agriculture`;

-- Update existing borrow records with pricing from items
UPDATE borrow_records br
LEFT JOIN equipment e ON br.item_type = 'equipment' AND br.item_id = e.equip_id
LEFT JOIN supplies s ON br.item_type = 'supplies' AND br.item_id = s.supply_id
SET br.daily_rate = CASE 
    WHEN br.item_type = 'equipment' THEN COALESCE(e.daily_rate, 0.00)
    WHEN br.item_type = 'supplies' THEN COALESCE(s.daily_rate, 0.00)
END;

-- Calculate rental amounts for existing records
UPDATE borrow_records 
SET 
    total_rental_amount = CASE 
        WHEN status = 'returned' AND return_date IS NOT NULL THEN 
            daily_rate * (DATEDIFF(return_date, date_borrowed) + 1)
        WHEN status IN ('borrowed', 'overdue') THEN 
            daily_rate * (DATEDIFF(NOW(), date_borrowed) + 1)
        ELSE 0.00
    END,
    late_fee_amount = CASE 
        WHEN status = 'returned' AND return_date > due_date THEN 
            (SELECT late_fee_per_day FROM equipment WHERE equip_id = item_id AND item_type = 'equipment') * DATEDIFF(return_date, due_date)
        WHEN status IN ('borrowed', 'overdue') AND NOW() > due_date THEN 
            (SELECT late_fee_per_day FROM equipment WHERE equip_id = item_id AND item_type = 'equipment') * DATEDIFF(NOW(), due_date)
        ELSE 0.00
    END;

-- Update late fees for supplies
UPDATE borrow_records 
SET late_fee_amount = CASE 
    WHEN status = 'returned' AND return_date > due_date AND item_type = 'supplies' THEN 
        (SELECT late_fee_per_day FROM supplies WHERE supply_id = item_id) * DATEDIFF(return_date, due_date)
    WHEN status IN ('borrowed', 'overdue') AND NOW() > due_date AND item_type = 'supplies' THEN 
        (SELECT late_fee_per_day FROM supplies WHERE supply_id = item_id) * DATEDIFF(NOW(), due_date)
    ELSE late_fee_amount
END
WHERE item_type = 'supplies';

-- Calculate total amount due
UPDATE borrow_records 
SET total_amount_due = total_rental_amount + late_fee_amount;
