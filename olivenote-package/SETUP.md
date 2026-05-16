# 🌿 Olive Note セットアップガイド

Olive Note を新しいサーバー（顧客ドメイン）にインストールする手順です。

> **想定読者**: 開発者 / 導入担当
> **所要時間**: 約30〜60分（Google Cloud 側の準備込み）

---

## 📋 全体の流れ

```
[1] Google Cloud 準備 (15〜30分)
    ├ プロジェクト作成
    ├ OAuth 同意画面 + Client ID 発行
    ├ Drive サービスアカウント発行
    └ (任意) Vertex AI 用 SA 発行

[2] MySQL データベース作成 (5分)
    └ Xサーバーパネル等で空のDBを作成

[3] Google Drive フォルダ準備 (5分)
    ├ ドキュメント保管フォルダ
    ├ 添付ファイル保管フォルダ
    ├ AI生成ドキュメント保管フォルダ
    └ いずれもサービスアカウントに編集権限を付与

[4] ZIPアップロード & インストーラ実行 (10分)
    └ ブラウザでウィザード式セットアップ
```

詳細手順は別ドキュメントを参照：

- [OAUTH_SETUP.md](../docs/OAUTH_SETUP.md) — Google OAuth Client ID の発行
- [DRIVE_SETUP.md](../docs/DRIVE_SETUP.md) — Drive サービスアカウントの発行とフォルダ準備
- [VERTEX_SETUP.md](../docs/VERTEX_SETUP.md) — (任意) Vertex AI 設定
- [INSTALL_GUIDE.md](../docs/INSTALL_GUIDE.md) — ZIP配置〜インストーラ実行
- [UPDATE_GUIDE.md](../docs/UPDATE_GUIDE.md) — 既存環境のアップデート
- [CLIENT_DEPLOYMENT.md](../docs/CLIENT_DEPLOYMENT.md) — 顧客への展開チェックリスト

---

## 🛠 必要なもの

- Xサーバー or 同等の PHP 8.0+ レンタルサーバー（独自ドメイン、HTTPS有効）
- 空の MySQL データベース（同サーバーでOK）
- Google アカウント（Google Cloud Platform を使えるもの）
- パッケージZIP（GitHub Release からダウンロード）

---

## 📁 ディレクトリ構造（インストール後）

```
olivenote/                        ← ドメインのサブディレクトリ or ルート
├── index.php                     ← エントリポイント
├── api.php                       ← API プロキシ
├── .htaccess                     ← HTTPS強制 + セキュリティヘッダー
├── app/                          ← アプリ本体（アップデートで上書きされる）
│   ├── api.php
│   ├── index.php
│   ├── *.html                    ← Reactコンポーネント
│   ├── lib/                      ← bootstrap / migrations / updater
│   ├── migrations/               ← DBマイグレーション
│   ├── admin/                    ← 管理者用画面
│   └── VERSION
├── config/                       ← 顧客固有（更新時保持）
│   └── config.php
├── data/                         ← 永続データ（更新時保持）
│   ├── backups/
│   └── cache/
└── installer.locked/             ← セットアップ完了後リネームされる
```

---

## ⚠️ よくある躓きポイント

| 症状 | 原因 / 対処 |
|---|---|
| `origin_mismatch` でログインできない | OAuth Client ID の承認済みオリジンに本ドメインを追加 |
| Google Sign-In ボタンが表示されない | HTTPS でアクセスしているか確認 |
| Drive の操作が `403 permission denied` | フォルダの「共有」にサービスアカウントのメールを追加（編集権限） |
| インストール完了後 500 エラー | `config/config.php` への書き込み権限・改行コード確認 |
| アップデーターが「manifest取得失敗」 | `config/config.php` の `OLIVENOTE_UPDATE_MANIFEST_URL` を確認 |
