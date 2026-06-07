-- ============================================================
-- 006_add_wiki_ydoc.sql
--   Wiki(TipTap) 同時編集（Yjs + Pusher 中継）用。
--   設計: docs/wiki-collab-design.md
--
--   wiki_pages に Yjs の収束状態（CRDT）を保存する 2 カラムを追加する。
--   - ydoc_state      : Y.encodeStateAsUpdate を base64 化した文字列（LONGTEXT で素通し格納）。
--                       NULL = まだ一度も同時編集されていない → 初回オープン時に body_md から seed。
--   - ydoc_updated_at : ydoc_state の最終更新時刻。
--
--   body_md は廃止しない（revision / 差分 / PDF / 検索 / 非collabビューは引き続き markdown を使う）。
--   ロールバックは 2 カラムを DROP するだけ（body_md が正本なのでデータ損失なし）。
-- ============================================================

ALTER TABLE wiki_pages
  ADD COLUMN ydoc_state      LONGTEXT  NULL  AFTER body_md,
  ADD COLUMN ydoc_updated_at TIMESTAMP NULL  AFTER ydoc_state;
