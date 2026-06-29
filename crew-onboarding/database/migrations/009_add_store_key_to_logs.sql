-- Project TWIN v1.0
-- Add store_key to session, message, event, and conversion logs.
-- Existing rows are backfilled as seika to preserve current production behavior.

SET @has_chat_sessions_store_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_sessions'
      AND COLUMN_NAME = 'store_key'
);

SET @sql_chat_sessions_store_key := IF(
    @has_chat_sessions_store_key = 0,
    'ALTER TABLE chat_sessions
        ADD COLUMN store_key VARCHAR(64) NOT NULL DEFAULT ''seika'' AFTER session_token,
        ADD KEY idx_chat_sessions_store_started (store_key, started_at)',
    'SELECT 1'
);

PREPARE stmt_chat_sessions_store_key FROM @sql_chat_sessions_store_key;
EXECUTE stmt_chat_sessions_store_key;
DEALLOCATE PREPARE stmt_chat_sessions_store_key;

SET @has_chat_messages_store_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_messages'
      AND COLUMN_NAME = 'store_key'
);

SET @sql_chat_messages_store_key := IF(
    @has_chat_messages_store_key = 0,
    'ALTER TABLE chat_messages
        ADD COLUMN store_key VARCHAR(64) NOT NULL DEFAULT ''seika'' AFTER session_id,
        ADD KEY idx_chat_messages_store_created (store_key, created_at)',
    'SELECT 1'
);

PREPARE stmt_chat_messages_store_key FROM @sql_chat_messages_store_key;
EXECUTE stmt_chat_messages_store_key;
DEALLOCATE PREPARE stmt_chat_messages_store_key;

SET @has_event_logs_store_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_logs'
      AND COLUMN_NAME = 'store_key'
);

SET @sql_event_logs_store_key := IF(
    @has_event_logs_store_key = 0,
    'ALTER TABLE event_logs
        ADD COLUMN store_key VARCHAR(64) NOT NULL DEFAULT ''seika'' AFTER session_id,
        ADD KEY idx_event_logs_store_created (store_key, created_at)',
    'SELECT 1'
);

PREPARE stmt_event_logs_store_key FROM @sql_event_logs_store_key;
EXECUTE stmt_event_logs_store_key;
DEALLOCATE PREPARE stmt_event_logs_store_key;

SET @has_conversion_events_store_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'conversion_events'
      AND COLUMN_NAME = 'store_key'
);

SET @sql_conversion_events_store_key := IF(
    @has_conversion_events_store_key = 0,
    'ALTER TABLE conversion_events
        ADD COLUMN store_key VARCHAR(64) NOT NULL DEFAULT ''seika'' AFTER session_id,
        ADD KEY idx_conversion_events_store_created (store_key, created_at)',
    'SELECT 1'
);

PREPARE stmt_conversion_events_store_key FROM @sql_conversion_events_store_key;
EXECUTE stmt_conversion_events_store_key;
DEALLOCATE PREPARE stmt_conversion_events_store_key;

UPDATE chat_messages m
INNER JOIN chat_sessions s ON s.id = m.session_id
SET m.store_key = s.store_key
WHERE m.store_key = 'seika'
  AND s.store_key <> 'seika';

UPDATE event_logs e
INNER JOIN chat_sessions s ON s.id = e.session_id
SET e.store_key = s.store_key
WHERE e.store_key = 'seika'
  AND s.store_key <> 'seika';

UPDATE conversion_events c
INNER JOIN chat_sessions s ON s.id = c.session_id
SET c.store_key = s.store_key
WHERE c.store_key = 'seika'
  AND s.store_key <> 'seika';
