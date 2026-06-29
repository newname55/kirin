-- Project TWIN v0.2
-- Safe migration for existing databases.

SET @intent_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_messages'
      AND COLUMN_NAME = 'intent'
);

SET @sql := IF(
    @intent_column_exists = 0,
    'ALTER TABLE chat_messages ADD COLUMN intent VARCHAR(50) NULL AFTER message, ADD KEY idx_chat_messages_intent (intent)',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
