-- Add read tracking for conversation messages
-- This allows tracking when users have read admin messages and when admin has read user messages

-- Add last_viewed_by_user column to user_reports table
ALTER TABLE user_reports 
ADD COLUMN IF NOT EXISTS last_viewed_by_user TIMESTAMP NULL AFTER last_message_at;

-- Add last_viewed_by_admin column to user_reports table
ALTER TABLE user_reports 
ADD COLUMN IF NOT EXISTS last_viewed_by_admin TIMESTAMP NULL AFTER last_viewed_by_user;

-- Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_last_viewed_user ON user_reports(last_viewed_by_user);
CREATE INDEX IF NOT EXISTS idx_last_viewed_admin ON user_reports(last_viewed_by_admin);

