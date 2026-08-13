-- ============================================================
-- 010_task_actions.sql
--   ネクストアクション: 課題ごとの「次にやること」を順序付きの行として持つ。
--
--   表に出るのは常に sort_order 先頭の未完了 1 行だけ（ボード/一覧の nextAction）。
--   全行を見せるのは課題モーダルの下ペインの中だけ。
--
--   - task_id  : tasks.id（TASK-XXXX）を指す。将来ほかのチケット種別へ同じ仕組みを
--                載せられるよう、外部キー制約は張らず幅も 40 で揃えておく。
--                課題はソフトデリート（tasks.deleted_at）なので行の掃除は不要。
--   - sort_order : 並べ替えは値の振り直し（(i+1)*1000）。行間に挿入できるよう DOUBLE。
--   - done_by / done_by_name : 「誰が終わらせたか」の事実の記録（担当の割り当てではない）。
--
--   注: CREATE TABLE IF NOT EXISTS で冪等。migration runner が各ファイルを一度だけ
--   適用する仕組みにも依存している（001〜009 と同じ前提）。
-- ============================================================

CREATE TABLE IF NOT EXISTS task_actions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_id      VARCHAR(40)   NOT NULL,
  title        VARCHAR(500)  NOT NULL,
  due_date     DATE          NULL,
  sort_order   DOUBLE        NOT NULL DEFAULT 0,
  done_at      TIMESTAMP     NULL,
  done_by      VARCHAR(255)  NOT NULL DEFAULT '',
  done_by_name VARCHAR(120)  NOT NULL DEFAULT '',
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ta_task (task_id, sort_order),
  INDEX idx_ta_open (task_id, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
