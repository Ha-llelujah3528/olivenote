# Changelog

このファイルは Olive Note の変更履歴です。Semantic Versioning ([https://semver.org/lang/ja/](https://semver.org/lang/ja/)) に従います。

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
