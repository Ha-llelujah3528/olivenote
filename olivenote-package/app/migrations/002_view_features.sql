-- ============================================================
-- 002_view_features.sql
--   ビュー強化 6大改修向けスキーマ追加
--     - tasks.card_color : カードカラー（要件1）
--     - user_preferences : ユーザー別表示設定（要件2/3/4/6）
--     - filter_presets   : フィルタープリセット（要件5）
-- ============================================================

-- 1) tasks にカード色カラム追加（要件1）
--    プリセット色名（'red','blue','green','yellow','purple','gray'）または NULL
--    冪等性は schema_migrations テーブルによる適用済みチェックで担保される。
--    （MySQL は ADD COLUMN IF NOT EXISTS 非対応のため、生 ADD COLUMN を使う）
ALTER TABLE tasks
  ADD COLUMN card_color VARCHAR(20) NULL AFTER category;

-- 2) ユーザー別の汎用設定（要件2,3,4,6 の表示状態）
CREATE TABLE IF NOT EXISTS user_preferences (
  user_email   VARCHAR(255) NOT NULL,
  pref_key     VARCHAR(64)  NOT NULL,
  pref_value   JSON         NOT NULL,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_email, pref_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) フィルタープリセット（要件5）
CREATE TABLE IF NOT EXISTS filter_presets (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_email   VARCHAR(255) NOT NULL,
  name         VARCHAR(120) NOT NULL,
  filters      JSON         NOT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
