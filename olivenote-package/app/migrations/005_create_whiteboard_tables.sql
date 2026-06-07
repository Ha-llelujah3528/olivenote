-- ============================================================
-- 005_create_whiteboard_tables.sql
--   「ホワイトボード（フリーボード）」用テーブル。
--   - whiteboards : Excalidraw のシーン(elements + 抜粋 appState + files)を JSON で保存。
--                   階層(parent_id) + ソフトデリート(deleted_at) は wiki_pages と同方針。
--
--   永続データ(正本)は必ずこのテーブルに保存する。リアルタイム同時編集の中継(Pusher)を
--   流れるのは編集中の差分のみで、落ちても scene_json から復元できる。
--   permalink は whiteboards.id (UUID) を使うため、改題・移動しても URL は不変。
-- ============================================================

CREATE TABLE IF NOT EXISTS whiteboards (
  id            VARCHAR(36)   NOT NULL PRIMARY KEY,
  title         VARCHAR(255)  NOT NULL DEFAULT '',
  scene_json    LONGTEXT      NULL,
  parent_id     VARCHAR(36)   NULL,
  sort_order    DOUBLE        NOT NULL DEFAULT 0,
  created_by    VARCHAR(255)  NOT NULL DEFAULT '',
  updated_by    VARCHAR(255)  NOT NULL DEFAULT '',
  scene_version BIGINT        NOT NULL DEFAULT 0,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP     NULL,
  INDEX idx_wb_parent_sort (parent_id, deleted_at, sort_order),
  INDEX idx_wb_deleted (deleted_at),
  INDEX idx_wb_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
