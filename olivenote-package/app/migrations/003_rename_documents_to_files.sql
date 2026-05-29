-- ============================================================
-- 003_rename_documents_to_files.sql
--   既存「ドキュメント」を「ファイル」に概念リネーム。
--   - documents テーブル → files テーブル
--   - 既存 INDEX もテーブルと一緒に移動する（MySQL 仕様）
--   - 外部キー参照は無いため後続テーブルの修正は不要
--
--   冪等性: 通常は schema_migrations が適用済 version をスキップするので
--   この migration が再実行されることは無いはずだが、部分適用や手動 retry
--   に備えて「documents が存在し files が存在しない場合のみ RENAME」とする。
--
--   NOTE: RENAME TABLE は DDL → 実行時に暗黙コミットが走る。
--         migrations.php 側はそれを inTransaction() ガードで吸収済。
-- ============================================================

-- 1) 状態判定（同一接続のセッション変数として後段で参照）
SET @has_documents := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'documents'
);
SET @has_files := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'files'
);

-- 2) documents だけ存在するときに限り RENAME を実行。
--    両方ある or 既に files だけのときは何もしない（再実行 safe）。
SET @sql := IF(
  @has_documents = 1 AND @has_files = 0,
  'RENAME TABLE documents TO files',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
