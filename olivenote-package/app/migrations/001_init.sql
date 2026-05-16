-- ============================================================
-- Olive Note - 初期スキーマ (v1.0.0)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- ============================================================
-- members : メンバー
-- ============================================================
CREATE TABLE IF NOT EXISTS members (
  email             VARCHAR(255)  NOT NULL PRIMARY KEY,
  name              VARCHAR(120)  NOT NULL,
  avatar            VARCHAR(16)   NOT NULL DEFAULT '👤',
  is_admin          TINYINT(1)    NOT NULL DEFAULT 0,
  default_category  VARCHAR(120)  NOT NULL DEFAULT '',
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- tasks : 課題
-- ============================================================
CREATE TABLE IF NOT EXISTS tasks (
  id                    VARCHAR(40)   NOT NULL PRIMARY KEY,
  title                 TEXT          NOT NULL,
  description           LONGTEXT      NULL,
  status                VARCHAR(40)   NOT NULL DEFAULT 'todo',
  priority              VARCHAR(20)   NOT NULL DEFAULT 'medium',
  type                  VARCHAR(120)  NOT NULL DEFAULT '',
  category              VARCHAR(120)  NOT NULL DEFAULT '',
  parent_id             VARCHAR(40)   NULL,
  start_date            DATE          NULL,
  due_date              DATE          NULL,
  implementation_date   DATE          NULL,
  implementation_days   INT           NOT NULL DEFAULT 1,
  assignee_email        VARCHAR(255)  NOT NULL DEFAULT '',
  assignee_name         VARCHAR(120)  NOT NULL DEFAULT '',
  sub_assignees         JSON          NULL,
  likes                 JSON          NULL,
  attachments           JSON          NULL,
  sort_order            DOUBLE        NOT NULL DEFAULT 0,
  created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP     NULL,
  INDEX idx_status (status),
  INDEX idx_due_date (due_date),
  INDEX idx_parent (parent_id),
  INDEX idx_deleted (deleted_at),
  INDEX idx_assignee (assignee_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- comments : コメント
-- ============================================================
CREATE TABLE IF NOT EXISTS comments (
  id            VARCHAR(40)   NOT NULL PRIMARY KEY,
  task_id       VARCHAR(40)   NOT NULL,
  author_email  VARCHAR(255)  NOT NULL DEFAULT '',
  author_name   VARCHAR(120)  NOT NULL DEFAULT '',
  text          LONGTEXT      NULL,
  likes         JSON          NULL,
  read_by       JSON          NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NULL,
  INDEX idx_task (task_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- notifications : 通知
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
  id            VARCHAR(40)   NOT NULL PRIMARY KEY,
  target_email  VARCHAR(255)  NOT NULL,
  sender_name   VARCHAR(120)  NOT NULL DEFAULT '',
  task_id       VARCHAR(40)   NULL,
  task_title    TEXT          NULL,
  message       TEXT          NULL,
  is_read       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_target (target_email, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- documents : Google Drive ファイル/フォルダ
-- ============================================================
CREATE TABLE IF NOT EXISTS documents (
  id            VARCHAR(80)   NOT NULL PRIMARY KEY,
  name          VARCHAR(255)  NOT NULL,
  url           TEXT          NOT NULL,
  parent_id     VARCHAR(80)   NULL,
  mime_type     VARCHAR(100)  NOT NULL DEFAULT '',
  last_updated  DATETIME      NULL,
  deleted_at    TIMESTAMP     NULL,
  INDEX idx_parent (parent_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- settings : key-value 設定（JSON もここに入る）
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(120)  NOT NULL PRIMARY KEY,
  setting_value LONGTEXT      NULL,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期 settings 値（lastTaskId 等）
INSERT INTO settings (setting_key, setting_value) VALUES
  ('lastTaskId', '0'),
  ('categories', '[]'),
  ('taskTypes', '[]'),
  ('taskTemplates', '{}'),
  ('docTags', '[]'),
  ('docFileTags', '{}'),
  ('docTemplates', '[]'),
  ('releaseDocUrl', ''),
  ('systemSpecForAI', '')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ============================================================
-- schema_migrations : マイグレーション履歴
-- ============================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(10)   NOT NULL PRIMARY KEY,
  name        VARCHAR(200)  NOT NULL,
  applied_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
