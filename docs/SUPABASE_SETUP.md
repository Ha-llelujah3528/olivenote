# Supabase Auth セットアップ手順

Olive Note は認証基盤に [Supabase Auth](https://supabase.com/auth) を使います。
インストーラで顧客が **使っている認証方式（Google Workspace / O365 / メール認証）を選択** できるため、セットアップが柔軟です。

**セットアップ時の特徴:**
- 顧客が「Google Workspace を使っている」「O365 を使っている」「その他」から選択
- 複数の認証方式を同時に有効化することも可能
- Email Magic Link は Supabase 標準 SMTP で自動送信（追加設定不要）
- 本番運用で自社ドメインから送りたい場合は、セットアップ後に SMTP 差し替え可能

> 想定: 顧客ごとに **独立した Supabase プロジェクト** を1つ作る。所要 15〜30 分。月50,000 認証アクセスまで無料。

---

## 0. 事前に準備するもの

- 任意のメールアドレス（Supabase アカウント用）
- 顧客の独自ドメイン（例: `https://example.com/olivenote/`）と HTTPS
- (任意) Google OAuth クライアントID & シークレット（[OAUTH_SETUP.md](OAUTH_SETUP.md) 参照、Google ログインを有効化するなら必要）
- (任意) Microsoft Azure AD のアプリ登録（O365 ログインを有効化するなら必要、後述）

---

## 1. Supabase プロジェクト作成（5分）

1. https://supabase.com/dashboard にアクセス → アカウント作成 or ログイン
2. **「New project」** をクリック
3. 入力:
   - Name: `olivenote-{客名}` （例: `olivenote-acme`）
   - Database Password: 自動生成された強いパスワード（控えなくてOK、再表示できる）
   - Region: **Northeast Asia (Tokyo)** を選択
   - Pricing Plan: Free
4. 「Create new project」→ 1〜2分待つとプロジェクト初期化完了

---

## 2. 認証プロバイダの有効化（10〜15分）

左サイドバー → **Authentication** → **Providers**

### 2.1 Email (Magic Link) を有効化

- 「Email」をクリック → **Enable Email provider: ON**
- 「Confirm email」: ON のまま
- 「Secure email change」: ON のまま
- 「Mailer Autoconfirm」: OFF のまま
- 設定保存

> ✅ Supabase が標準の SMTP（noreply@mail.app.supabase.io 等）でログインメールを送ってくれます。
> 自社ドメインから送りたい場合は後で「**Authentication → Email Templates**」と「**Project Settings → Auth**」で SMTP を差し替え可能。

### 2.2 Google ログインを有効化（任意）

事前に Google Cloud Console で OAuth Client ID を発行（[OAUTH_SETUP.md](OAUTH_SETUP.md) 参照）。

1. 「Google」を選択 → **Enable: ON**
2. **Client IDs** に Google の Client ID
3. **Client Secret** に Google のクライアントシークレット
4. Supabase が表示する **Authorized redirect URL** （例: `https://abcdef.supabase.co/auth/v1/callback`）を Google Cloud Console 側「**承認済みのリダイレクト URI**」に追加
   - ⚠️ **「承認済みの JavaScript 生成元」ではなく「承認済みのリダイレクト URI」の方** に入れてください。前者はパスを含む URL を受け付けないため "無効な URI" エラーになります
5. 保存

### 2.3 Microsoft (Azure AD) ログインを有効化（任意）

事前に Microsoft Entra ID（旧 Azure AD）でアプリを登録します。

1. https://portal.azure.com/ → **Microsoft Entra ID** → **App registrations** → **新規登録**
2. 入力:
   - 名前: `Olive Note ({客名})`
   - サポートされているアカウントの種類: 「**任意の組織ディレクトリ内のアカウント + 個人の Microsoft アカウント**」を推奨（O365 共通 + 個人 Microsoft も）
   - リダイレクト URI: 「Web」+ Supabase が示すコールバック URL（例: `https://abcdef.supabase.co/auth/v1/callback`）
3. 登録完了後、**アプリケーション (クライアント) ID** をコピー
4. **証明書とシークレット** → **新しいクライアントシークレット** → 説明と有効期間（24か月）を選び発行 → 「値」を **その場でコピー**（後で見れません）
5. **API のアクセス許可** → **Microsoft Graph** → `email`, `openid`, `profile`, `User.Read` を追加し管理者の同意付与
6. Supabase に戻る → 「Azure」プロバイダを Enable → Application ID + Secret を貼り付け → 「Azure tenant」は `common` のままで OK（複数テナント許可、O365 と個人MS両対応）

---

## 3. リダイレクト URL の登録

左サイドバー → **Authentication** → **URL Configuration**

- **Site URL**: Olive Note のトップ URL を1つだけ設定
  - 例: `https://example.com/olivenote/`（末尾 `/` を忘れずに）
- **Redirect URLs** (許可リスト): 同じ URL を追加
  - 例: `https://example.com/olivenote/**`
- 「Save」をクリック

> Supabase は登録外の URL へのリダイレクトを拒否します。本番ドメイン以外（テスト環境等）でも使うなら、ここに追加してください。

---

## 4. API 認証情報の取得 → config.php に反映

新 UI では旧「Project Settings → API」ページが **3か所** に分割されています。左サイドバー **Project Settings** から下記3ページを順に開いて値をコピーしてください。

| 取得元 (左サイドバー Project Settings 配下) | config.php の定数 | 内容 |
|---|---|---|
| **General** → Project ID | `SUPABASE_URL` | 表示された ID から `https://{Project ID}.supabase.co` 形式の URL を組み立てる<br>例: `https://abcdef.supabase.co` |
| **CONFIGURATION → API Keys** → `anon` (public) | `SUPABASE_ANON_KEY` | フロントエンドが使う公開可キー（`eyJhbG...` で始まる長い JWT） |
| **CONFIGURATION → JWT Keys** → JWT Secret | `SUPABASE_JWT_SECRET` | サーバー側 JWT 検証用。★絶対秘密 |

> ⚠️ 旧 UI（〜2025年中頃まで）では「Settings → API」1ページに3つが揃っていましたが、現在の UI ではそれぞれ別ページに分かれています。手順書通りに「API」サブメニューを探しても見つからない場合は「API Keys」「JWT Keys」を別々に確認してください。

`config.php` の該当箇所に貼り付け（インストーラから生成された場合は `__SUPABASE_URL__` 等のプレースホルダを書き換える）:

```php
define('SUPABASE_URL',        'https://abcdef.supabase.co');
define('SUPABASE_ANON_KEY',   'eyJhbGciOi....(長い)....');
define('SUPABASE_JWT_SECRET', 'super-secret-jwt-key-from-supabase');
```

---

## 5. メールテンプレートの日本語化（任意）

左サイドバー → **Authentication** → **Email Templates**

「Magic Link」テンプレートの件名と本文を日本語に書き換え可能。例:

- **Subject**: `Olive Note ログインリンク`
- **Body** (HTML): 既存のテンプレートを利用しつつ、ロゴや一言を日本語に

`{{ .ConfirmationURL }}` 部分は Supabase が動的に挿入するので残します。

---

## 6. メンバー登録

Supabase 側で「認証できる人」、Olive Note の `members` テーブルで「使える人」を制御します。**両方に登録が必要** なわけではなく:

- Supabase はメアド to verify するだけ（最初のログイン時に Supabase 側に勝手に作られる）
- Olive Note は `members.email` に登録されたアドレスでないとログインを通さない（PHP 側で確認）

つまり **管理者が Olive Note の「設定 → メンバー管理」で email を事前登録** すれば OK。Supabase 側で個別ユーザー作成は不要です。

---

## 7. 動作確認

1. `https://example.com/olivenote/` を開く（ログアウト状態で）
2. ログイン画面に「Google でログイン」「Microsoft でログイン」「📧 メールでログイン」が並んでいることを確認
3. メンバー登録済のメアドで **Magic Link** を試す → メール受信 → リンククリック → 自動ログイン
4. **Google ログイン** を試す → ポップアップで Google アカウント選択 → 自動ログイン
5. **Microsoft ログイン** を試す（O365 アカウントで）→ Microsoft 同意画面 → 自動ログイン

---

## 8. トラブルシュート

| 症状 | 原因 | 対処 |
|---|---|---|
| ログイン画面に「ログイン設定が未完了です」と出る | `config.php` の `SUPABASE_URL` / `SUPABASE_ANON_KEY` が未設定 or プレースホルダのまま | §4 を再確認 |
| ボタン押下後「Provider not enabled」 | Supabase 側でプロバイダ ON にしていない | §2 で各プロバイダを Enable |
| OAuth リダイレクトで「Invalid redirect」 | Site URL / Redirect URLs が未登録 | §3 を再確認 |
| ログイン直後に「メンバーとして登録されていません」 | Olive Note の members に該当 email が無い | 設定 → メンバー管理で追加 |
| Magic Link メールが届かない | Supabase 標準 SMTP がスパム判定 | 受信箱の迷惑メールフォルダを確認、本番運用なら自社 SMTP に切替 |
| Microsoft ログインで「AADSTS50011」 | Azure 側の Redirect URI に Supabase のコールバック URL を入れていない | §2.3 の手順4を再確認 |

---

## 9. 自社ドメイン SMTP の設定（任意・本番向け）

デフォルトでは `noreply@mail.app.supabase.io` から送信されます。自社ドメインを使いたい場合:

1. 左サイドバー → **Project Settings** → **Auth** → 「SMTP Settings」
2. Enable Custom SMTP: ON
3. SMTP Host / Port / User / Pass を入力（Resend, SendGrid, Xサーバー の SMTP 等）
4. Sender Email / Sender Name を設定
5. Save → 「Send test email」で疎通確認

これで `noreply@example.com` 等から送信されるようになります。
