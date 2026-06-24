-- ============================================================
-- 009_comment_reply.sql
--   コメントの返信（引用）機能のためのスキーマ追加。
--
--   - comments.parent_comment_id : 返信元コメントの id。通常コメントは NULL。
--     Teams 風に「引用を埋め込んで返信 → クリックで該当コメントへスクロール」する。
--     外部キー（FK）は張らない。コメントは物理削除されるため、親が消えても
--     返信側は残し、フロントで「削除されたコメント」として表示する方針。
-- ============================================================

-- 注: ADD COLUMN に IF NOT EXISTS は付けない。
--   本番 Xサーバーは MySQL 8.0 で、ADD COLUMN IF NOT EXISTS は MariaDB 専用構文＝
--   MySQL 8 では構文エラーになるため。migration runner が各 migration を一度だけ
--   適用する仕組み（008 までと同じ前提）に依存して冪等性を担保する。
ALTER TABLE comments
  ADD COLUMN parent_comment_id VARCHAR(40) NULL DEFAULT NULL AFTER read_by,
  ADD INDEX idx_parent_comment (parent_comment_id);
