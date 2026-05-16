# 🌿 Olive Note - 配布パッケージ

Olive Note を **他のドメインに展開したり、画面上ボタンでバージョンアップしたりできる** ように
パッケージ化した一式です。

> ⚠️ このフォルダは **本番(prd) / 検証(stg)環境とは独立** しています。
> 既存環境を壊すことなく、こちらで「配布版」をビルド・配信します。

---

## 📁 ディレクトリ

```
dist/
├── olivenote-package/      ← 配布物の中身（このまま ZIP に固める）
│   ├── index.php
│   ├── api.php
│   ├── app/                ← アプリ本体（アップデートで上書き）
│   ├── config/             ← 顧客固有設定（保持）
│   ├── data/               ← 永続データ（保持）
│   ├── installer/          ← 初回セットアップウィザード
│   ├── SETUP.md
│   └── CHANGELOG.md
├── docs/                   ← 顧客展開ガイド類
│   ├── OAUTH_SETUP.md
│   ├── DRIVE_SETUP.md
│   ├── VERTEX_SETUP.md
│   ├── INSTALL_GUIDE.md
│   ├── UPDATE_GUIDE.md
│   └── CLIENT_DEPLOYMENT.md
├── manifest/
│   └── manifest.json       ← サンプル（実際はビルドで生成）
├── scripts/
│   ├── build-release.sh    ← Linux/Mac 用ビルダー
│   └── build-release.ps1   ← Windows 用ビルダー
├── .github/workflows/
│   └── release.yml         ← git tag push で自動ビルド & 公開
├── CHANGELOG.md
└── README.md (this file)
```

---

## 🚀 はじめてのリリース

### 1. GitHubリポジトリを準備

このパッケージを GitHub の自分のアカウントにリポジトリ化：

```bash
cd dist
git init
git add .
git commit -m "feat: initial release v1.0.0"
git branch -M main
git remote add origin https://github.com/Ha-llelujah3528/olivenote.git
git push -u origin main
```

> 既に GitHub ユーザー名 `Ha-llelujah3528` で各ファイルに埋め込み済みです。
> リポジトリ名を `olivenote` 以外にしたい場合は、各ファイル内の `Ha-llelujah3528/olivenote` を一括置換してください。

### 2. タグを切ってリリース

```bash
git tag v1.0.0
git push origin v1.0.0
```

→ GitHub Actions が動いて：
- `olivenote-1.0.0.zip` を Release にアップロード
- `manifest.json` を main ブランチに push
- 顧客サーバーのアップデーターが自動検知

---

## 📦 ローカルでビルドだけしたいとき

```bash
# Linux/Mac
bash scripts/build-release.sh

# Windows (PowerShell)
powershell -ExecutionPolicy Bypass -File scripts/build-release.ps1
```

→ `build/olivenote-X.Y.Z.zip` と `build/manifest.json` ができる。
GitHub を使わず手動でホスティングする場合はこれを使う。

---

## 🏢 顧客に展開する

[docs/CLIENT_DEPLOYMENT.md](docs/CLIENT_DEPLOYMENT.md) のチェックリストに沿って作業。

おおまかな流れ：

```
1. 顧客の Google Cloud で OAuth Client ID & Drive SA を発行
2. 顧客サーバーに ZIP を展開
3. ブラウザで installer/install.php にアクセス
4. ウィザードに従って5ステップ入力
5. 引き渡し完了
```

---

## 🔄 バージョンアップを配信する

[docs/UPDATE_GUIDE.md](docs/UPDATE_GUIDE.md) 参照。

1. `olivenote-package/app/VERSION` を更新（例: `1.1.0`）
2. `CHANGELOG.md` に変更内容を追記
3. DB変更があれば `olivenote-package/app/migrations/00X_*.sql` を追加
4. `git tag v1.1.0 && git push origin v1.1.0`
5. 顧客サーバーの管理者が「設定 → システムアップデート」でワンクリック更新

---

## 🆘 トラブル時

- インストーラが詰まった → [docs/INSTALL_GUIDE.md](docs/INSTALL_GUIDE.md) の「リカバリー」
- アップデートが失敗 → `data/backups/` から手動復旧 ([docs/UPDATE_GUIDE.md](docs/UPDATE_GUIDE.md))
- OAuth/Drive が動かない → [docs/OAUTH_SETUP.md](docs/OAUTH_SETUP.md) [docs/DRIVE_SETUP.md](docs/DRIVE_SETUP.md) のトラブルシュート

---

## 🧭 設計判断のメモ

| 判断 | 理由 |
|---|---|
| `config/` を公開ディレクトリ内に置く（.htaccessで保護） | パッケージ化と展開のシンプルさ優先。.htaccess deny で実用上十分 |
| マイグレーションは番号順SQL（自作ランナー） | Composer 不要、Xサーバーで即動く |
| アップデートは同期処理 | 単純で堅実。長くても3分程度なので非同期化は不要 |
| バックアップは DB ダンプ + app/ZIP | 復旧手順がシンプル、5世代/3世代ローテで容量も抑制 |
| OAuth Client ID は顧客ごとに発行 | Googleの方針通り。ドメイン共有による境界曖昧化を防ぐ |
| Drive SA も顧客ごとに発行を推奨 | 万一の流出時に他顧客に波及しない |
| 顧客識別は instance_id (UUID風) で内部生成 | 将来テレメトリやライセンス検証を入れる余地を確保 |
