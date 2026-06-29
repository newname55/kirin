-- Project TWIN v0.4
-- Safe migration for existing databases.

SET @has_message_count := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_sessions'
      AND COLUMN_NAME = 'message_count'
);

SET @sql_message_count := IF(
    @has_message_count = 0,
    'ALTER TABLE chat_sessions ADD COLUMN message_count INT UNSIGNED NULL AFTER ended_at, ADD COLUMN session_duration_seconds INT UNSIGNED NULL AFTER message_count',
    'SELECT 1'
);

PREPARE stmt_message_count FROM @sql_message_count;
EXECUTE stmt_message_count;
DEALLOCATE PREPARE stmt_message_count;

SET @has_conversion_events := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'conversion_events'
);

SET @sql_conversion_events := IF(
    @has_conversion_events = 0,
    'CREATE TABLE conversion_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        conversion_type VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_conversion_events_session_created (session_id, created_at),
        KEY idx_conversion_events_conversion_type (conversion_type),
        CONSTRAINT fk_conversion_events_session
            FOREIGN KEY (session_id) REFERENCES chat_sessions (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1'
);

PREPARE stmt_conversion_events FROM @sql_conversion_events;
EXECUTE stmt_conversion_events;
DEALLOCATE PREPARE stmt_conversion_events;

UPDATE chat_sessions cs
LEFT JOIN (
    SELECT session_id, COUNT(*) AS cnt
    FROM chat_messages
    WHERE sender = 'user'
    GROUP BY session_id
) msg ON msg.session_id = cs.id
SET cs.message_count = COALESCE(msg.cnt, 0),
    cs.session_duration_seconds = CASE
        WHEN cs.ended_at IS NULL THEN NULL
        ELSE TIMESTAMPDIFF(SECOND, cs.started_at, cs.ended_at)
    END;
