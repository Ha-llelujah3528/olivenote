# Google OAuth Client ID 発行ガイド

Olive Note は Google Sign-In を使ってログインします。顧客のドメインごとに **OAuth Client ID を1つ発行** する必要があります。

> 所要時間: 約10〜15分（Google Cloud にログイン済みの場合）

---

## ステップ 1. Google Cloud プロジェクトを作成

1. [Google Cloud Console](https://console.cloud.google.com/) を開く
2. 左上の「プロジェクトを選択」 → 「**新しいプロジェクト**」
3. プロジェクト名: 例 `olivenote-{客先名}`（あとから変更不可なので分かりやすく）
4. 「作成」

---

## ステップ 2. OAuth 同意画面の構成

左メニュー「**APIとサービス**」→「**OAuth 同意画面**」

1. **User Type**:
   - 同じ組織内で完結するなら「**内部**」（推奨、審査不要）
   - 外部の人も使うなら「**外部**」（テストユーザーまでは審査不要）
2. アプリ名: `Olive Note` など分かりやすい名前
3. ユーザーサポートメール: あなたのメールアドレス
4. デベロッパーの連絡先: あなたのメールアドレス
5. 「保存して次へ」
6. スコープ画面 → 何も追加せず「保存して次へ」（Sign-In は基本スコープで十分）
7. テストユーザー（外部の場合のみ） → 利用予定のメールアドレスを追加
8. 「ダッシュボードに戻る」

---

## ステップ 3. OAuth Client ID を作成

左メニュー「**APIとサービス**」→「**認証情報**」

1. 上部「**+ 認証情報を作成**」 → 「**OAuth クライアント ID**」
2. アプリケーションの種類: **ウェブ アプリケーション**
3. 名前: `Olive Note Web Client`（任意）
4. **承認済みの JavaScript 生成元** に **顧客のドメイン** を追加：
   ```
   https://example.com
   https://www.example.com    ← 必要なら
   ```
   - **localhost で開発する場合**: `http://localhost` も追加
5. **承認済みのリダイレクトURI** はGoogleSign-In (FedCM) では不要なので空でOK
6. 「作成」
7. ダイアログに表示される **クライアント ID** をコピー
   - 形式: `123456789-xxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com`

> このClient ID をインストーラの「OAuth Client ID」欄に貼り付けます。

---

## ステップ 4. 動作確認

セットアップ完了後、`https://{顧客ドメイン}/olivenote/` にアクセス：

- ✅ Google Sign-In ボタンが表示される
- ✅ ボタンを押すとGoogleアカウント選択画面が出る
- ✅ ログイン後にアプリが表示される

---

## 🚨 トラブルシュート

### `Error 400: origin_mismatch`

→ ステップ3の「承認済みの JavaScript 生成元」に、現在ブラウザでアクセスしている URL を**完全に同じ形** で追加してください。

- ❌ `https://example.com/` （末尾スラッシュNG）
- ✅ `https://example.com`
- ❌ `http://example.com` （HTTPS必須）

変更してから反映まで5〜10分かかることがあります。

### `NotAllowedError: identity-credentials-get`

→ アプリ側の `index.php` で `Permissions-Policy: identity-credentials-get=(self "https://accounts.google.com")` ヘッダが出ているか確認。本パッケージでは設定済みのはず。

### ボタンが出ない / Loading が止まる

→ ブラウザの開発者ツール（F12）→ Console を見て、エラーメッセージで判断。
   多くの場合は HTTPS が無効、または Client ID の入力ミス。
