-- migration: 010_add_outcome_columns
-- 採用後の実績追跡カラムを crew_applicants に追加
-- 目的: AI評価 → 店長判断 → 実結果 のループを閉じ、将来の学習データ源とする
-- 対象DB: twin_crew_dev (Raspi4開発) / twin (Raspi5本番)
-- 実行: mysql -u root <DB名> < 010_add_outcome_columns.sql

ALTER TABLE crew_applicants
    ADD COLUMN IF NOT EXISTS outcome_status     VARCHAR(16)  DEFAULT NULL COMMENT '採用結果: hired/declined/rejected/pending',
    ADD COLUMN IF NOT EXISTS outcome_sales_rank VARCHAR(4)   DEFAULT NULL COMMENT '3か月後売上ランク: S/A/B/C/D',
    ADD COLUMN IF NOT EXISTS outcome_retention  VARCHAR(16)  DEFAULT NULL COMMENT '定着状況: active/left/unknown',
    ADD COLUMN IF NOT EXISTS outcome_bring      VARCHAR(16)  DEFAULT NULL COMMENT '呼客実績: high/normal/low/unknown',
    ADD COLUMN IF NOT EXISTS outcome_checked_at DATETIME     DEFAULT NULL COMMENT '実結果確認日（いつ時点の評価か）',
    ADD COLUMN IF NOT EXISTS outcome_note       TEXT         DEFAULT NULL COMMENT '店長の結果メモ（例外理由・特記事項）';
