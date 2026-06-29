CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `session_id`           BIGINT UNSIGNED NOT NULL,
  `model`                VARCHAR(100)    NOT NULL DEFAULT '',
  `prompt_tokens`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `completion_tokens`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `total_tokens`         INT UNSIGNED    NOT NULL DEFAULT 0,
  `estimated_cost_usd`   DECIMAL(10,8)   NOT NULL DEFAULT 0.00000000,
  `estimated_cost_jpy`   DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_session_id` (`session_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
