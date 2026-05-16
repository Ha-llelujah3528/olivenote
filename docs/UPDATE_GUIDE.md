# アップデート手順

Olive Note は **設定画面の「システムアップデート」ボタン** から、ワンクリックで最新版に更新できます。

---

## 顧客側の操作（管理者）

### 1. アップデート画面を開く

「設定」タブ → 左メニュー最下部「**🔄 システムアップデート**」をクリック
（管理者のみ表示）

### 2. バージョンを確認

画面に以下が表示されます：

```
現在のバージョン:  v1.0.0
最新バージョン:    v1.1.0  🆕 UPDATE

📝 変更内容:
- スマホレスポンシブ対応強化
- 印刷機能のバグ修正
- ...
```

### 3. 「アップデートを実行」ボタン

確認ダイアログ → OK → 自動で以下が走ります：

```
[10:23:01] 🔒 メンテナンスモード ON
[10:23:02] 📦 v1.0.0 → v1.1.0 へ更新します
[10:23:03] 💾 DBバックアップ: db-20260516-102303.sql
[10:23:05] 💾 app/ バックアップ: app-1.0.0-20260516-102305.zip
[10:23:10] ⬇️  ZIPダウンロード完了
[10:23:11] 🔑 SHA256検証 OK
[10:23:13] 📂 ZIP展開完了
[10:23:14] 🔄 app/ を新バージョンに置き換え
[10:23:15] 📊 Migration: 2件適用
[10:23:16] 🧹 一時ファイル削除
[10:23:16] 🔓 メンテナンスモード OFF
[10:23:16] ✅ アップデート完了: v1.1.0
```

完了するとページが自動再読み込みされます。

> 所要時間: **1〜3分** 程度
> アップデート中は他ユーザーには 503 Service Unavailable が返ります（短時間メンテ）

---

## 開発者側の操作（新バージョン配信）

### 1. ローカルでバージョン更新 & テスト

`dist/olivenote-package/app/VERSION` を更新（例: `1.1.0`）。

`dist/CHANGELOG.md` に新バージョンのエントリを追加：

```markdown
## [1.1.0] - 2026-05-20

### 追加
- スマホレスポンシブ対応強化
- ...

### 修正
- 印刷機能のバグ
- ...
```

DBスキーマの変更がある場合は `dist/olivenote-package/app/migrations/` に新しい SQL を追加：

```
002_add_xxx_to_tasks.sql
003_create_audit_log.sql
```

### 2. パッケージをビルド

```bash
# Linux/Mac
bash dist/scripts/build-release.sh 1.1.0

# Windows
powershell -ExecutionPolicy Bypass -File dist/scripts/build-release.ps1 -Version 1.1.0
```

→ `dist/build/olivenote-1.1.0.zip` と `dist/build/manifest.json` が生成される。

### 3. リリース公開

#### A. GitHub を使う場合（推奨）

```bash
git tag v1.1.0
git push origin v1.1.0
```

GitHub Actions が起動し、自動で：
- Release を作成して ZIP をアップロード
- `main` ブランチに `manifest.json` をコミット
- 各顧客サーバーが自動的に最新版を検知

#### B. 手動で公開する場合

1. `olivenote-1.1.0.zip` をどこかの URL に置く
   (例: 自社サイトのリリース置き場 / S3 / Google Cloud Storage)
2. `manifest.json` の `download_url` を実URLに書き換え
3. `manifest.json` を `OLIVENOTE_UPDATE_MANIFEST_URL` で指定したURLに配置

---

## 🚨 トラブルシュート

### 「アップデートを実行」ボタンを押しても何も起きない

→ ブラウザの開発者ツール（F12）→ Console を確認。多くの場合は通信エラー。
   `data/` ディレクトリの書き込み権限を確認。

### アップデート中に「メンテナンスモード OFF」されないままになった

→ 何らかの理由で更新が中断された可能性。サーバーに SSH or FTP で入って手動で：
```
data/MAINTENANCE.lock を削除
```

### バックアップから復旧したい

`data/backups/` に以下が保存されています：
- `db-{日時}.sql`          : DB全体のダンプ（5世代）
- `app-{バージョン}-{日時}.zip` : app/ ディレクトリのスナップショット（3世代）

復旧手順：
```bash
# DB復元
mysql -h ... -u ... -p {DB名} < data/backups/db-20260516-102303.sql

# app/ 復元
cd /path/to/olivenote
rm -rf app
unzip data/backups/app-1.0.0-20260516-102305.zip
```

### アップデート後に何か壊れた

開発者側で原因調査 → 修正版 v1.1.1 をリリース → 顧客は再度ボタンを押すだけ。

緊急時は手動で：
1. `config/config.php` の `OLIVENOTE_UPDATE_MANIFEST_URL` を旧バージョンのmanifestに差し替え
2. アップデート画面でダウングレード（バージョン比較が逆になるのでボタンは出ないが、強制したい場合は手動でZIP展開）

---

## 配信戦略のヒント

### 段階的ロールアウト

複数顧客がいる場合、いきなり全員に配信せず：

1. **社内STG** に先に展開して動作確認（1日）
2. **1〜2社のパイロット顧客** に展開（3日様子見）
3. **全顧客に正式配信**（manifest を更新）

### バージョン番号

[Semantic Versioning](https://semver.org/lang/ja/) 推奨：
- `1.0.0 → 1.0.1` : バグ修正のみ
- `1.0.1 → 1.1.0` : 後方互換のある機能追加
- `1.1.0 → 2.0.0` : 破壊的変更（DBスキーマの非互換変更など）
