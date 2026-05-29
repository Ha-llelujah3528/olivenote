-- ============================================================
-- 004_create_wiki_tables.sql
--   新「ドキュメント」タブ（社内 Wiki）用テーブル。
--   - wiki_pages       : Wiki ページ本体（階層 + ソフトデリート）
--   - wiki_revisions   : 編集履歴（保存毎に 1 行追加、復元・差分用）
--
--   permalink は wiki_pages.id (UUID) を使うため、移動・改題しても
--   URL (#/wiki/<uuid>) は不変。
-- ============================================================

CREATE TABLE IF NOT EXISTS wiki_pages (
  id           VARCHAR(36)   NOT NULL PRIMARY KEY,
  title        VARCHAR(255)  NOT NULL DEFAULT '',
  body_md      LONGTEXT      NULL,
  parent_id    VARCHAR(36)   NULL,
  sort_order   DOUBLE        NOT NULL DEFAULT 0,
  created_by   VARCHAR(255)  NOT NULL DEFAULT '',
  updated_by   VARCHAR(255)  NOT NULL DEFAULT '',
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   TIMESTAMP     NULL,
  INDEX idx_parent_sort (parent_id, deleted_at, sort_order),
  INDEX idx_deleted (deleted_at),
  INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wiki_revisions (
  id              BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_id         VARCHAR(36)   NOT NULL,
  revision_no     INT           NOT NULL,
  title           VARCHAR(255)  NOT NULL DEFAULT '',
  body_md         LONGTEXT      NULL,
  editor_email    VARCHAR(255)  NOT NULL DEFAULT '',
  editor_name     VARCHAR(120)  NOT NULL DEFAULT '',
  change_summary  VARCHAR(500)  NOT NULL DEFAULT '',
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE INDEX uniq_page_revision (page_id, revision_no),
  INDEX idx_page_created (page_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
