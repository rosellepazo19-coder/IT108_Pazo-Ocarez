-- Add conversation system for reports
-- This creates a chat-like conversation system for user reports

-- Add conversation_messages table
CREATE TABLE IF NOT EXISTS conversation_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversation_report FOREIGN KEY (report_id) REFERENCES user_reports(report_id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add index for better performance
CREATE INDEX idx_conversation_report ON conversation_messages(report_id);
CREATE INDEX idx_conversation_created ON conversation_messages(created_at);

-- Update user_reports table to support conversations
ALTER TABLE user_reports 
ADD COLUMN IF NOT EXISTS last_message_at TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS message_count INT DEFAULT 0;

-- Create view for conversation summary
CREATE OR REPLACE VIEW v_report_conversations AS
SELECT 
    r.report_id,
    r.user_id,
    r.subject,
    r.status,
    r.created_at,
    r.resolved_at,
    r.last_message_at,
    r.message_count,
    u.Fname,
    u.Lname,
    CONCAT(u.Fname, ' ', u.Lname) AS user_name,
    CASE 
        WHEN r.status = 'resolved' THEN 'resolved'
        WHEN r.last_message_at IS NULL THEN 'new'
        ELSE 'active'
    END AS conversation_status
FROM user_reports r
JOIN users u ON r.user_id = u.user_id
ORDER BY r.last_message_at DESC, r.created_at DESC;
