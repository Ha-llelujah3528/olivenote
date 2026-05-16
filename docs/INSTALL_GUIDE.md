# インストール手順（顧客サーバーへの初回設置）

> 前提: [OAUTH_SETUP.md](./OAUTH_SETUP.md) と [DRIVE_SETUP.md](./DRIVE_SETUP.md) が完了していること。

---

## 1. 必要な情報をまとめておく

インストーラで聞かれる情報を**先に手元に揃えて**おくとスムーズです。

```
✏️ 事前準備チェックリスト

□ ドメイン: ___________________________  (例: example.com)
□ SSL証明書: 設定済み (HTTPSアクセスができる)

□ MySQL
   ホスト:  ___________________________
   DB名:    ___________________________
   ユーザー: ___________________________
   パスワード: ___________________________

□ Google OAuth Client ID:
   ____________________________________________________

□ Drive Service Account
   メール:    ____________________________________________
   秘密鍵: (PEM JSONを保存)
   DOC_FOLDER_ID:        _______________________________
   ATTACHMENT_FOLDER_ID: _______________________________
   AI_DOC_FOLDER_ID:     _______________________________

□ (任意) Vertex AI
   Project ID:  __________________________________
   Location:    us-central1
   メール:       ___________________________________________
   秘密鍵: (PEM)

□ 初期管理者
   Googleアカウント: _________________________________
   表示名:          _________________________________
```

---

## 2. データベースを作成

### Xサーバーの場合

1. サーバーパネル → 「MySQL設定」
2. 「MySQL追加」タブで空のDBを作成
   - 例: `totie_olivenote_clientA`
3. 「MySQLユーザー」で接続用ユーザーを作成 or 既存ユーザーを使用
4. 「MySQL追加」タブに戻り、ユーザーに DB 権限を付与

DB接続情報をメモ：
- ホスト: 例 `mysql8093.xserver.jp`
- DB名: 例 `totie_olivenote_clientA`
- ユーザー: 例 `totie_swadmin`
- パスワード: メモ済みのもの

---

## 3. パッケージZIPをアップロード

1. [GitHub Releases](https://github.com/Ha-llelujah3528/olivenote/releases) から最新版 `olivenote-X.Y.Z.zip` をダウンロード
2. 顧客ドメインの公開ディレクトリ（例: `/home/{user}/example.com/public_html/olivenote/`）にアップロード
3. サーバー上で解凍。Xサーバーの場合は **ファイルマネージャ → 解凍** で可能
4. 解凍後の構造：
   ```
   public_html/olivenote/
   ├── index.php
   ├── api.php
   ├── .htaccess
   ├── app/
   ├── config/        ← config.sample.php のみ
   ├── data/
   ├── installer/
   ├── SETUP.md
   └── CHANGELOG.md
   ```

---

## 4. パーミッション確認

以下のディレクトリに**書き込み権限**（PHP実行ユーザーに対して）が必要：

- `config/`   （インストーラが config.php を書き出す）
- `data/`     （バックアップ / キャッシュ）
- `app/`      （アップデート時にファイル差し替え）

通常の Xサーバーはアップロードした PHP プロセスが書き込み可能なので変更不要。

---

## 5. ブラウザでインストーラ実行

`https://example.com/olivenote/` にアクセス。

セットアップ未完了なら自動で `installer/install.php` にリダイレクトされます。

### ① 環境チェック

PHP / 拡張 / 書き込み権限 / HTTPS が全部 ✅ になっているか確認。
❌ がある場合はサーバー設定を見直してから「再読み込み」。

### ② データベース接続

DB情報を入力 → 「接続テスト→次へ」
接続失敗時はエラーメッセージを確認して再入力。

### ③ Google設定

OAuth Client ID と Drive SA 情報を入力。
- 秘密鍵は JSONファイル内の `private_key` を**そのまま**コピー＆ペースト（改行込みでOK）

### ④ 初期管理者

最初の管理者となる Google アカウント情報を入力。
このメールアドレスでないと **誰もログインできなくなる** ので慎重に。

### ⑤ 確認と実行

サマリ表示を確認 → 「セットアップを実行」

裏で以下が実行されます：
1. `config/config.php` を生成
2. DB migrations を全件適用
3. 初期管理者を `members` テーブルに登録
4. `installer/` → `installer.locked/` にリネーム

---

## 6. ログインして動作確認

完了画面の「アプリを開く →」ボタン or `https://example.com/olivenote/` に再アクセス。

- ✅ Googleログイン画面が表示
- ✅ 初期管理者のアカウントでログイン
- ✅ ボード / ガント / カレンダー / ドキュメント / 設定 すべて表示できる
- ✅ 設定 → メンバー管理 で他メンバーを追加できる

---

## 7. 仕上げ作業（任意）

### 7.1 ドキュメントタブの初回同期

設定画面の管理者メニュー外、ドキュメント画面の右上「**Driveと同期**」ボタンを押すと、Drive上の既存ファイルが取り込まれます。

### 7.2 マニフェストURL の確認

`config/config.php` の `OLIVENOTE_UPDATE_MANIFEST_URL` が、アップデート配信用のmanifest.jsonを指しているか確認：

```php
define('OLIVENOTE_UPDATE_MANIFEST_URL', 'https://raw.githubusercontent.com/{あなたのGitHub}/olivenote/main/manifest.json');
```

GitHub以外の場所でホスティングする場合は適宜URLを変更。

### 7.3 顧客への引き渡し

顧客に伝えること：
- アクセスURL
- 初期管理者として登録されている Google アカウント
- メンバー追加・カテゴリ設定の方法（→ アプリ内のAIコンシェルジュに質問してもらえばOK）

---

## 🚨 インストールに失敗した場合のリカバリー

セットアップ途中で詰まったら、以下のいずれかでやり直し：

### A. 完全にやり直す
```
config/config.php を削除
installer.locked/ → installer/ にリネーム
DB を空にする
```
→ ブラウザで再アクセスで installer/install.php に飛ぶ

### B. 設定だけ手動編集
`config/config.php` を直接エディタで開いて値を書き換える。

### C. DBだけリセット
```sql
DROP DATABASE totie_olivenote_xxx;
CREATE DATABASE totie_olivenote_xxx DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
→ ブラウザで `installer/install.php?force=1` にアクセスすれば再実行可能（force=1で既存config無視）
