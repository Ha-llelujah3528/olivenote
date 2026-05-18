# Changelog

このファイルは Olive Note の変更履歴です。Semantic Versioning ([https://semver.org/lang/ja/](https://semver.org/lang/ja/)) に従います。

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
