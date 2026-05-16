# Google Drive サービスアカウント発行ガイド

Olive Note は **添付ファイル** と **ドキュメント管理機能** で Google Drive を使います。Drive API へのアクセスは **サービスアカウント (SA)** 経由で行います。

> 所要時間: 約15〜20分

---

## ステップ 1. Drive API を有効化

[OAUTH_SETUP.md](./OAUTH_SETUP.md) で作成した Google Cloud プロジェクトで作業します。

1. 左メニュー「**APIとサービス**」→「**ライブラリ**」
2. 検索バーで `Google Drive API` を検索
3. 「**有効にする**」をクリック

---

## ステップ 2. サービスアカウントを作成

1. 左メニュー「**IAM と管理**」→「**サービスアカウント**」
2. 上部「**+ サービスアカウントを作成**」
3. **サービスアカウント名**: 例 `olivenote-drive`
4. **サービスアカウントID**: 自動生成のままでOK（`olivenote-drive`）
5. 「**作成して続行**」
6. ロールの付与 → 何も追加せず「続行」（Driveはフォルダ単位で個別共有するので不要）
7. 「**完了**」

---

## ステップ 3. JSONキーを発行（一度きり）

1. 作成したサービスアカウントの行をクリック
2. 「**キー**」タブ → 「**鍵を追加**」→「**新しい鍵を作成**」
3. キーのタイプ: **JSON** → 「作成」
4. JSONファイルがダウンロードされる ⚠️ **このファイルは再発行できないので大切に保管**

ダウンロードした JSON の中身：
```json
{
  "type": "service_account",
  "project_id": "...",
  "client_email": "olivenote-drive@xxx.iam.gserviceaccount.com",
  "private_key": "-----BEGIN PRIVATE KEY-----\nMIIE...\n-----END PRIVATE KEY-----\n",
  ...
}
```

インストーラでは以下を使います：
- `client_email` → 「**SA メールアドレス**」欄
- `private_key`  → 「**SA 秘密鍵 (PEM)**」欄（**まるごと**コピペ）

---

## ステップ 4. Drive フォルダを準備

ブラウザで [Google Drive](https://drive.google.com/) を開きます。

### 4.1 3つのフォルダを作成

Drive 上で以下の3フォルダを作成（場所は任意。マイドライブ直下推奨）：

| フォルダ名 (任意) | 用途 |
|---|---|
| 📁 OliveNote/Documents       | ドキュメント機能で作成・管理するファイル |
| 📁 OliveNote/Attachments     | 課題への添付ファイル |
| 📁 OliveNote/AI-Generated    | AIが自動生成したドキュメント |

> 👆 全部を1つの親フォルダ `OliveNote/` の下に整理すると後で管理しやすいです。

### 4.2 各フォルダをサービスアカウントに共有

各フォルダごとに：

1. フォルダを右クリック → 「**共有**」
2. 「ユーザーやグループを追加」に **ステップ3でメモした client_email** を貼り付け
3. 権限: **編集者**
4. 「**通知を送信する**」のチェックは**外す**（SAにはメール届かないため）
5. 「共有」

### 4.3 フォルダIDを取得

各フォルダのURL末尾の文字列がフォルダID：

```
https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUv
                                       ^^^^^^^^^^^^^^^^^^^^^^^^^
                                       これがフォルダID
```

インストーラで以下に貼り付け：
- `DOC_FOLDER_ID`       → Documents フォルダ
- `ATTACHMENT_FOLDER_ID`→ Attachments フォルダ
- `AI_DOC_FOLDER_ID`    → AI-Generated フォルダ

---

## ステップ 5. 動作確認

インストール完了後：

1. アプリにログイン
2. **ドキュメントタブ** を開く → エラーが出なければ正常
3. 課題を新規作成 → **添付ファイル** をアップロード → Drive にファイルが上がる
4. 管理者なら「**Driveと同期**」ボタンが押せる

---

## 🚨 トラブルシュート

### `User does not have sufficient permissions` / `File not found: 1AbC...`

→ サービスアカウントが各フォルダに **編集権限** で共有されているか確認。フォルダIDが正しいかも併せて確認。

### 添付ファイル一覧に何も表示されない（同期もエラーなし）

→ Drive上は問題なくても、SAアカウントのドライブ容量が0なので、 **オーナーが共有元の人になっている** ことが原因の可能性。共有時に「権限の譲渡」が必要な場合あり。

### `invalid_grant: Invalid JWT Signature`

→ `private_key` が正しくコピーされていない可能性。改行コード（\n リテラルと実際の改行）に注意。インストーラは両方の形式を受け付けます。
