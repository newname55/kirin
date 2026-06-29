-- v0.9.1: AIキャラクター設定テーブル
-- 店舗ごとのAI名・役職・初回あいさつ・キャラクター画像・ロゴを管理する

CREATE TABLE IF NOT EXISTS ai_character_settings (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id             VARCHAR(64)  NOT NULL DEFAULT 'seika' COMMENT '店舗識別子（将来のマルチ店舗対応用）',
    ai_name              VARCHAR(100) NOT NULL DEFAULT 'TWIN SEIKA' COMMENT 'AI表示名',
    ai_title             VARCHAR(200) NOT NULL DEFAULT 'CLUB SEIKA DIGITAL HOSTESS' COMMENT 'AI肩書き',
    greeting_message     TEXT         NOT NULL COMMENT '初回あいさつ文',
    character_image_path VARCHAR(500) DEFAULT NULL COMMENT 'キャラクター画像パス（/twin/public/uploads/characters/ 以下の相対パス）',
    logo_image_path      VARCHAR(500) DEFAULT NULL COMMENT 'ロゴ画像パス',
    is_active            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=有効（1店舗につき1件のみ）',
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_active (store_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 星華のデフォルト設定を1件挿入（is_active=0: 有効にするには管理画面から操作）
INSERT IGNORE INTO ai_character_settings (store_id, ai_name, ai_title, greeting_message, is_active)
VALUES (
    'seika',
    'TWIN SEIKA',
    'CLUB SEIKA DIGITAL HOSTESS',
    'こんばんは。TWIN SEIKAです。初めての方も安心して楽しめるように、料金や雰囲気など何でも聞いてくださいね。',
    0
);
