CREATE TABLE IF NOT EXISTS chat_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token VARCHAR(64) NOT NULL,
    store_key VARCHAR(64) NOT NULL DEFAULT 'seika',
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    message_count INT UNSIGNED NULL,
    session_duration_seconds INT UNSIGNED NULL,
    user_agent VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_sessions_session_token (session_token),
    KEY idx_chat_sessions_store_started (store_key, started_at),
    KEY idx_chat_sessions_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    store_key VARCHAR(64) NOT NULL DEFAULT 'seika',
    sender ENUM('user','twin') NOT NULL,
    message TEXT NOT NULL,
    intent VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_messages_session_created (session_id, created_at),
    KEY idx_chat_messages_store_created (store_key, created_at),
    KEY idx_chat_messages_intent (intent),
    CONSTRAINT fk_chat_messages_session
        FOREIGN KEY (session_id) REFERENCES chat_sessions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    store_key VARCHAR(64) NOT NULL DEFAULT 'seika',
    event_name VARCHAR(64) NOT NULL,
    event_value VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_event_logs_session_created (session_id, created_at),
    KEY idx_event_logs_store_created (store_key, created_at),
    KEY idx_event_logs_event_name (event_name),
    CONSTRAINT fk_event_logs_session
        FOREIGN KEY (session_id) REFERENCES chat_sessions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_settings_setting_key (setting_key),
    KEY idx_app_settings_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversion_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    store_key VARCHAR(64) NOT NULL DEFAULT 'seika',
    conversion_type VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_conversion_events_session_created (session_id, created_at),
    KEY idx_conversion_events_store_created (store_key, created_at),
    KEY idx_conversion_events_conversion_type (conversion_type),
    CONSTRAINT fk_conversion_events_session
        FOREIGN KEY (session_id) REFERENCES chat_sessions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    model VARCHAR(100) NOT NULL DEFAULT '',
    prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost_usd DECIMAL(10,8) NOT NULL DEFAULT 0.00000000,
    estimated_cost_jpy DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_usage_logs_session_id (session_id),
    KEY idx_ai_usage_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_character_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id VARCHAR(64) NOT NULL DEFAULT 'seika' COMMENT '店舗識別子',
    ai_name VARCHAR(100) NOT NULL DEFAULT 'TWIN SEIKA' COMMENT 'AI表示名',
    ai_title VARCHAR(200) NOT NULL DEFAULT 'CLUB SEIKA DIGITAL HOSTESS' COMMENT 'AI肩書き',
    greeting_message TEXT NOT NULL COMMENT '初回あいさつ文',
    character_image_path VARCHAR(500) DEFAULT NULL COMMENT 'キャラクター画像パス',
    logo_image_path VARCHAR(500) DEFAULT NULL COMMENT 'ロゴ画像パス',
    is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=有効',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_character_store_active (store_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, updated_at)
SELECT 'response_mode', 'rule', NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM app_settings WHERE setting_key = 'response_mode'
);

INSERT INTO ai_character_settings (store_id, ai_name, ai_title, greeting_message, is_active)
SELECT
    'seika',
    'TWIN SEIKA',
    'CLUB SEIKA DIGITAL HOSTESS',
    'こんばんは。TWIN SEIKAです。初めての方も安心して楽しめるように、料金や雰囲気など何でも聞いてくださいね。',
    0
WHERE NOT EXISTS (
    SELECT 1 FROM ai_character_settings WHERE store_id = 'seika'
);
