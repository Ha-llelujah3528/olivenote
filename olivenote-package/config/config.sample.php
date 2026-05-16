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
// Google OAuth (Sign-In)
// ============================================================
define('GOOGLE_CLIENT_ID', '__GOOGLE_CLIENT_ID__');

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
// アップデート関連
// ============================================================
// マニフェスト（最新版情報）の取得URL
define('OLIVENOTE_UPDATE_MANIFEST_URL', 'https://raw.githubusercontent.com/Ha-llelujah3528/olivenote/main/manifest.json');

// このインストールを識別する任意のID（テレメトリ用、任意）
define('OLIVENOTE_INSTANCE_ID', '__INSTANCE_ID__');
