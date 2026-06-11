-- ============================================================
-- 008_lineworks.sql
--   LINE WORKS 連携（送信通知・cron・受信bot）のためのスキーマ追加。
--
--   - members.lineworks_id : OliveNote メンバー → LINE WORKS ログインID の手動マッピング。
--     これが入っていないユーザーには LINE WORKS 通知を送らない（email フォールバックなし）。
--   - lineworks_user_prefs  : ユーザーごとの通知 ON/OFF 設定（種別ごと粒度）。
--     members は saveSettings で全件 DELETE+INSERT されるため、設定は別テーブルに退避する。
--   - lineworks_outbox      : 日付系／週次サマリの重複送信防止（同日・同週に二重送信しない）。
--   - lineworks_bot_sessions: 受信bot の会話状態（メモ→課題作成のヒアリング進行）。
--   - activity_log          : 週次サマリ「自身が更新した課題」を正確に出すための軽量アクティビティ。
--
--   JSON 値は settings テーブルと同様に LONGTEXT に json_encode して格納する
--   （MySQL8 / MariaDB 双方で安全側）。
-- ============================================================

-- 注: ADD COLUMN に IF NOT EXISTS は付けない。
--   本番 Xサーバーは MySQL 8.0 で、ADD COLUMN IF NOT EXISTS は MariaDB 専用構文＝
--   MySQL 8 では構文エラーになるため。migration runner が各 migration を一度だけ
--   適用する仕組み（007 と同じ前提）に依存して冪等性を担保する。
ALTER TABLE members
  ADD COLUMN lineworks_id VARCHAR(255) NULL AFTER default_category;

CREATE TABLE IF NOT EXISTS lineworks_user_prefs (
  email      VARCHAR(255) NOT NULL,
  prefs      LONGTEXT     NOT NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lineworks_outbox (
  email       VARCHAR(255) NOT NULL,
  task_id     VARCHAR(64)  NOT NULL DEFAULT '',
  notify_kind VARCHAR(40)  NOT NULL,
  sent_on     DATE         NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (email, task_id, notify_kind, sent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lineworks_bot_sessions (
  lw_user_id VARCHAR(255) NOT NULL,
  step       VARCHAR(40)  NOT NULL DEFAULT '',
  draft      LONGTEXT     NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (lw_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(255) NOT NULL,
  task_id    VARCHAR(64)  NOT NULL,
  action     VARCHAR(20)  NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_activity_email_created (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
