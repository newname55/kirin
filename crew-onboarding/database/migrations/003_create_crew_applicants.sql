-- crew_applicants: 応募前ヒアリング結果 + 内部評価の構造化保存
-- 応募者には一切見せない（管理画面専用）

CREATE TABLE IF NOT EXISTS crew_applicants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    session_id      INT          NOT NULL,
    store_key       VARCHAR(32)  NOT NULL DEFAULT 'kirin',

    -- ヒアリング結果（問診フローの回答をそのまま保存）
    experience      VARCHAR(8)   DEFAULT NULL,  -- none / some / yes
    genre           VARCHAR(16)  DEFAULT NULL,  -- cabaret / lounge / snack / girls_bar / other
    prev_hourly     SMALLINT     DEFAULT NULL,  -- 以前の時給（円）
    referrals       VARCHAR(8)   DEFAULT NULL,  -- 週指名組数: 0 / 1_2 / 3_5 / 6_plus
    bring_now       VARCHAR(8)   DEFAULT NULL,  -- 今でも呼べる: yes / some / no
    bring_trial     VARCHAR(8)   DEFAULT NULL,  -- 体験日に呼べる: yes / maybe / no
    days_per_week   VARCHAR(8)   DEFAULT NULL,  -- 週出勤日数: 1_2 / 3_4 / 5_plus
    alcohol         VARCHAR(8)   DEFAULT NULL,  -- yes / some / no
    estimated_wage  VARCHAR(64)  DEFAULT NULL,  -- "4,500〜5,000円前後" など
    completed_at    DATETIME     DEFAULT NULL,  -- 診断結果＋内部評価＋LINE CTA が揃った時刻

    -- AI内部評価（管理画面のみ・応募者には非表示）
    candidate_score TINYINT UNSIGNED DEFAULT NULL,  -- 0〜100
    priority_grade  CHAR(1)          DEFAULT NULL,  -- A / B / C / D
    score_detail    TEXT             DEFAULT NULL,  -- JSON: 各項目の内訳

    -- 採用フロー記録（将来の管理画面用）
    line_applied_at   DATETIME DEFAULT NULL,  -- LINEタップ時に自動記録
    interview_at      DATETIME DEFAULT NULL,
    hired_at          DATETIME DEFAULT NULL,
    rejected_at       DATETIME DEFAULT NULL,
    hired_employee_id INT      DEFAULT NULL,  -- WBSS の employee_id（採用後に手動で紐付け）
    memo              TEXT     DEFAULT NULL,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_session         (session_id),
    INDEX idx_store           (store_key),
    INDEX idx_grade           (priority_grade),
    INDEX idx_completed       (completed_at),
    INDEX idx_hired_employee  (hired_employee_id),
    INDEX idx_created         (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
