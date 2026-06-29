-- Project TWIN v0.6.5
-- Safe migration for existing databases.

SET @has_app_settings := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_settings'
);

SET @sql_app_settings := IF(
    @has_app_settings = 0,
    'CREATE TABLE app_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_app_settings_setting_key (setting_key),
        KEY idx_app_settings_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1'
);

PREPARE stmt_app_settings FROM @sql_app_settings;
EXECUTE stmt_app_settings;
DEALLOCATE PREPARE stmt_app_settings;

INSERT INTO app_settings (setting_key, setting_value, updated_at)
SELECT 'response_mode', 'rule', NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM app_settings
    WHERE setting_key = 'response_mode'
);
