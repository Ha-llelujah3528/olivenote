<?php
/**
 * Olive Note - 設定ファイル
 *
 * このファイルはインストーラが自動生成します。
 * 手動編集する場合は値だけ書き換えてください。
 */

// ============================================================
// データベース接続
// ============================================================
define('DB_HOST', '__DB_HOST__');
define('DB_NAME', '__DB_NAME__');
define('DB_USER', '__DB_USER__');
define('DB_PASS', '__DB_PASS__');

// ============================================================
// 認証プロバイダの選択
// ============================================================
// 'supabase' : 本番。Supabase Auth 経由で Google / Microsoft / Email を扱う（既定）
// 'demo'     : 営業デモ用。共通パスワードだけで入れる簡易ログイン（外部設定不要）。
//              ※ 本番運用では絶対に使わないこと。
// インストーラがセットアップモードに応じて 'supabase' / 'demo' を埋め込む。
// 手動編集する場合は 'supabase' か 'demo' を直接書く。
define('OLIVENOTE_AUTH_PROVIDER', '__AUTH_PROVIDER__');

// デモログイン用の共通パスワード（OLIVENOTE_AUTH_PROVIDER が 'demo' のときのみ使用）。
// 顧客には「members に登録済みのメールアドレス ＋ このパスワード」でログインしてもらう。
define('OLIVENOTE_DEMO_PASSWORD', '__DEMO_PASSWORD__');

// ============================================================
// Supabase Auth (認証基盤)
// ============================================================
// Olive Note は Supabase Auth を通じて Email Magic Link / Google / Microsoft
// などのログインを提供する。詳細セットアップは docs/SUPABASE_SETUP.md 参照。
//
// 取得元: Supabase ダッシュボード → Project Settings → API
//   - SUPABASE_URL      : Project URL (例: https://abcdef.supabase.co)
//   - SUPABASE_ANON_KEY : anon / public key (フロントエンドから安全に使える鍵)
//   - SUPABASE_JWT_SECRET : JWT Secret (★絶対秘密。サーバー側 JWT 検証用)
define('SUPABASE_URL',        '__SUPABASE_URL__');
define('SUPABASE_ANON_KEY',   '__SUPABASE_ANON_KEY__');
define('SUPABASE_JWT_SECRET', '__SUPABASE_JWT_SECRET__');

// ============================================================
// Supabase OAuth プロバイダの選択
// ============================================================
// OLIVENOTE_AUTH_PROVIDER が 'supabase' のとき、Supabase 側で有効化した
// 認証方法をここに列記する。
// 有効な値: 'google', 'microsoft', 'email'
// 例: ['google', 'email']
define('SUPABASE_PROVIDERS', __SUPABASE_PROVIDERS__);

// Google OAuth（SUPABASE_PROVIDERS に 'google' が含まれる場合）
// 取得元: Google Cloud Console → OAuth 2.0 クライアント
define('GOOGLE_CLIENT_ID',     '__GOOGLE_CLIENT_ID__');
define('GOOGLE_CLIENT_SECRET', '__GOOGLE_CLIENT_SECRET__');

// Microsoft Azure AD OAuth（SUPABASE_PROVIDERS に 'microsoft' が含まれる場合）
// 取得元: Microsoft Entra ID → App registrations
define('MICROSOFT_CLIENT_ID',     '__MICROSOFT_CLIENT_ID__');
define('MICROSOFT_CLIENT_SECRET', '__MICROSOFT_CLIENT_SECRET__');

// ============================================================
// Pusher（ホワイトボード／ドキュメントのリアルタイム同時編集／任意）
// ============================================================
// 同時編集を使う場合のみ設定。取得元: https://dashboard.pusher.com/
//   Channels → アプリ → 「App Keys」タブ（app_id / key / secret / cluster）
//   ※「App Settings」タブで「Enable client events」を ON にすること。
// 空欄のままなら同時編集は無効（単独編集＋DB保存で通常どおり動作）。
define('PUSHER_APP_ID',  '');
define('PUSHER_KEY',     '');
define('PUSHER_SECRET',  '');
define('PUSHER_CLUSTER', 'ap3');

// ============================================================
// Google Drive 用 サービスアカウント
// ============================================================
define('CLIENT_EMAIL', '__DRIVE_SA_EMAIL__');
define('PRIVATE_KEY',  '__DRIVE_SA_PRIVATE_KEY__');
define('DOC_FOLDER_ID',        '__DOC_FOLDER_ID__');
define('ATTACHMENT_FOLDER_ID', '__ATTACHMENT_FOLDER_ID__');
define('AI_DOC_FOLDER_ID',     '__AI_DOC_FOLDER_ID__');

// ============================================================
// Vertex AI（オプション、空欄でもアプリは起動可能）
// ============================================================
define('VERTEX_PROJECT_ID',   '__VERTEX_PROJECT_ID__');
define('VERTEX_LOCATION',     '__VERTEX_LOCATION__');
define('VERTEX_CLIENT_EMAIL', '__VERTEX_SA_EMAIL__');
define('VERTEX_PRIVATE_KEY',  '__VERTEX_SA_PRIVATE_KEY__');

// ============================================================
// LINE WORKS（ビジネスチャット連携／オプション、空欄でもアプリは起動可能）
// ============================================================
// LINE WORKS Developer Console でアプリ＋Bot を作成し、OAuth Scope に
//   bot / bot.message / user.read を付与。Service Account と Private Key を取得する。
// LINEWORKS_BOT_ID が空欄のままなら連携機能は丸ごと無効（通知・受信bot ともに動かない）。
//   - LINEWORKS_SERVICE_ACCOUNT : サービスアカウント（例: xxxxx.serviceaccount@domain）
//   - LINEWORKS_PRIVATE_KEY     : ★秘密。RS256 PEM
//   - LINEWORKS_BOT_SECRET      : ★秘密。Callback(webhook) の署名検証用
//   - LINEWORKS_CRON_TOKEN      : ★秘密。定期通知エンドポイント保護用の共有トークン
define('LINEWORKS_BOT_ID',          '');
define('LINEWORKS_CLIENT_ID',       '');
define('LINEWORKS_CLIENT_SECRET',   '');
define('LINEWORKS_SERVICE_ACCOUNT', '');
define('LINEWORKS_PRIVATE_KEY',     '');
define('LINEWORKS_BOT_SECRET',      '');
define('LINEWORKS_CRON_TOKEN',      '');

// ============================================================
// アップデート関連
// ============================================================
// マニフェスト（最新版情報）の取得URL
define('OLIVENOTE_UPDATE_MANIFEST_URL', 'https://raw.githubusercontent.com/Ha-llelujah3528/olivenote/main/manifest.json');

// このインストールを識別する任意のID（テレメトリ用、任意）
define('OLIVENOTE_INSTANCE_ID', '__INSTANCE_ID__');
