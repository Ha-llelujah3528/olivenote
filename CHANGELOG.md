# Changelog

このファイルは Olive Note の変更履歴です。Semantic Versioning ([https://semver.org/lang/ja/](https://semver.org/lang/ja/)) に従います。

## [1.0.6] - 2026-05-18

### 機能追加
- **AI 課題自動生成機能を新設**
  - ヘッダ右上の「🪄 AI生成」ボタンから起動
  - 画像 / PDF / Excel (.xlsx) / CSV / TSV / プレーンテキスト / 手入力メモを **複数まとめて** 入力可能（NotebookLM 風）
  - 「追加の指示」テキストで自然言語で生成方針を補足（例: 「担当は山田さん固定」「サブタスクは作らないで」）
  - **gemini-2.5-pro** が現在のカテゴリ・種別・メンバー・既存タスクを context として参照し、タスクのドラフトを一括生成
  - 親子関係（プロジェクト > サブタスク）、担当者、優先度、カテゴリ、種別、期限を AI が自動推測
  - **プレビュー画面** で各ドラフトを編集 / 除外可能。確認後に「選択した課題を保存」で一括登録
- **重複検出** を多層で実装:
  - AI が既存タスクと意味的に類似と判定したドラフトに `duplicateOfTaskId` フラグ + 根拠を付与
  - サーバ側でも件名完全一致（4文字以上）のハードルールで保険検証
  - 重複疑いドラフトは UI 上で琥珀色のバッジ + デフォルト OFF（ユーザー opt-in 必須）にしてうっかり登録を防止
- **生成品質の制御**:
  - description は必ず Markdown 形式（見出し / チェックリスト / 表 等）で生成
  - 「コンテキスト厳守ルール（ハルシネーション禁止）」をプロンプト先頭で明示。資料に書かれていないタスクや一般論補足を AI に追加させない
  - generationConfig を strict 寄り（temperature 0.1, topP 0.8）に設定

### 内部改善
- リリース sync スクリプトに `TaskAutoGenerateModal.html` を COPY_AS_IS 対象として登録
- lucide-react の Image アイコンを `ImageIcon` に rename（標準 Image コンストラクタとの混同回避）
- 新規 import: `UploadCloud`, `Check`, `Edit3`, `Table`
- SheetJS (xlsx 0.18.5) を CDN から追加（Excel クライアントサイドパース用）

## [1.0.5] - 2026-05-18

### バグ修正（重要）
- **インストーラ完了直後の `install.php?step=done` が 404 になる問題を修正**
  - 原因①: `handle_step5_finalize()` が `installer/` → `installer.locked/` にリネームしてから redirect していたため、リダイレクト先 `installer/install.php?step=done` が存在せず 404
  - 原因②: 仮にリネームしなかったとしても、`config/config.php` 存在チェックの「セットアップ済み」ガード（409）が `?step=done` を弾いて render_done に到達できなかった
  - 修正:
    1. installer のリネームを `handle_step5_finalize()` から外し、`render_done()` で HTML 出力後に `fastcgi_finish_request()` でレスポンスを確定してからリネームするように変更
    2. 「セットアップ済み」ガードに `?step=done` の例外を追加して、セットアップ完了直後の表示を許可
  - 影響範囲: v1.0.4 で migration まで完走できるようになった全環境（最終ステップが見えなかった）

## [1.0.4] - 2026-05-18

### バグ修正（重要）
- **Migration 実行時に `001_init: There is no active transaction` エラーで止まる問題を修正**
  - 原因: MySQL は `CREATE TABLE` 等の DDL を実行すると **暗黙コミット** を行うため、`beginTransaction()` で開いたトランザクションが DDL 一発目で消える。その状態で `commit()` を呼ぶと "There is no active transaction" になる
  - 修正: `commit()` を `inTransaction()` ガードで保護し、暗黙コミット済の場合は明示 commit をスキップ。`schema_migrations` への記録は DDL 後に行うように順序入れ替え
  - 影響範囲: 新規インストール時の最終ステップ（v1.0.3 で `olivenote_db()` の問題を直したことで初めて表面化）

## [1.0.3] - 2026-05-18

### バグ修正（重要）
- **インストーラ最終ステップで `TypeError: olivenote_db() must return PDO, null returned` が発生して完了できない問題を修正**
  - 原因: `install.php` の `handle_step5_finalize()` という**関数の内側**で `bootstrap.php` を `require_once` していたため、bootstrap.php 内で作られる `$pdo` 変数が関数のローカルスコープに閉じ込められ、グローバル参照する `olivenote_db()` から見えなかった
  - 修正: `bootstrap.php` で PDO 作成後に `$GLOBALS['pdo'] = $pdo;` を明示し、どのスコープから require されてもグローバル参照が必ず成立するようにした
  - 影響範囲: 新規インストールの最終ステップを実行した全ユーザー。既にセットアップ完了している環境は無関係

## [1.0.2] - 2026-05-18

### バグ修正
- **インストーラの「OAuthセットアップ手順書」「Drive準備手順書」リンクが404になっていた問題を修正**
  - 配布ZIPに `docs/` フォルダが同梱されていなかったのが原因
  - ビルドスクリプト (`scripts/build-release.sh` / `.ps1`) で `dist/docs/` を ZIP に同梱するように変更
  - markdown ファイルを綺麗にレンダリングする `docs/view.php` ビューアを追加（marked.js 使用）
  - インストーラのリンクを `view.php?doc=OAUTH_SETUP.md` 経由に変更
- アップデート時 (`app/admin/updater_ui.php`) も最新版の `docs/` を反映するよう updater を拡張

### 内部改善
- `scripts/build-release.ps1` の CHANGELOG パス解決バグを修正（`$RootDir/CHANGELOG.md` を参照するように）

## [1.0.1] - 2026-05-18

### 機能追加
- **Olive コンシェルジュに「表示中の課題」分析モード追加**
  - チャットウィンドウ上部のトグルで「💬 使い方」と「📊 表示中の課題」を切り替え可能
  - 「表示中の課題」モードはフィルター適用後の課題リスト全体を `gemini-2.5-pro` で分析
  - 件数集計・期限超過の検出・担当者ごとの負荷比較などの定量的な質問に対応
  - 「使い方」モードは従来通り `gemini-2.5-flash`（速度優先）
  - フィルター条件と現在の表示件数バッジを UI 上で可視化

## [1.0.0] - 2026-05-16

### 初回リリース
- パッケージ化対応版
- 既存のGAS版から PHP + MySQL 環境への移行が完了
- ファイル構造を `app/` `config/` `data/` `installer/` に分離
- インストーラ (`installer/install.php`) で対話的セットアップ
- アップデーター（管理者画面のボタンから自動更新）
- マイグレーション機構を導入し DB スキーマを自動適用
- スマホレスポンシブ対応（全7ビュー）

### 機能一覧
- ボード / ガントチャート / カレンダー / ドキュメント / 設定 の5ビュー
- Markdown でリッチな課題詳細
- 親子課題（2階層）
- 添付ファイル (Google Drive 連携)
- AI コンシェルジュ / AI タスクアドバイザー (Vertex AI)
- AI でドキュメント自動生成
- 通知 / コメント / メンション
- リリースノート自動生成（AI）
