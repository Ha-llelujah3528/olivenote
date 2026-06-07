-- ============================================================
-- 007_add_files_is_ai_generated.sql
--   AI 生成 Doc（AI_DOC_FOLDER_ID 配下）をメインのドキュメント一覧から
--   除外するためのフラグ列を files テーブルに追加する。
--
--   背景:
--   - タスクから生成する AI Doc は Drive 上は AI_DOC_FOLDER_ID に作られるが、
--     DB(files) には parent_id / mime_type を持たない行として登録されていた。
--   - そのためメイン一覧（getAllData の Documents）に混在し、さらに
--     syncDocumentsFromDrive の削除パス（parent_id NULL を同期対象とみなす）で
--     誤ってソフトデリートされる潜在不具合があった。
--
--   対応:
--   - is_ai_generated = 1 の行はメイン一覧から除外し、同期の削除対象からも外す。
--   - AI Doc 生成時の INSERT で is_ai_generated = 1 を立てる（api.php 側）。
--
--   既存データの back-fill:
--   - AI Doc は INSERT 時に mime_type / parent_id を一切セットしないため、
--     「parent_id IS NULL かつ mime_type が空/NULL」の行＝AI Doc と判定できる。
--     （通常の Doc・フォルダは sync / 作成時に必ず mime_type を持つ）。
-- ============================================================

ALTER TABLE files
  ADD COLUMN is_ai_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER mime_type;

-- 既存の AI 生成 Doc にフラグを立てる（新規インストール環境では対象行ゼロ＝no-op）。
UPDATE files
   SET is_ai_generated = 1
 WHERE parent_id IS NULL
   AND (mime_type IS NULL OR mime_type = '');
