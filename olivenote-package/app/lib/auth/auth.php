<?php
/**
 * lib/auth/auth.php — 認証レイヤの共通エントリポイント
 *
 * Provider 切替は OLIVENOTE_AUTH_PROVIDER 定数で行う。
 *   - 'google'   : stg/prd の Google OAuth2 直実装（既定）
 *   - 'supabase' : dist の Supabase Auth
 *
 * Provider ファイルは下記の関数を必ず定義する:
 *   - auth_render_login_screen(string $appVersion): void   未ログイン時の画面を出力して exit
 *   - auth_verify_token(array $payload, PDO $pdo): void    トークン検証→セッション確立→JSON 出力
 *   - auth_public_actions(): array                         認証不要 action のリスト（logout は別管理）
 *   - auth_setup_headers(): void                           provider 固有の HTTP ヘッダー
 *
 * 既存仕様（互換維持のため変更しない）:
 *   - $_SESSION['user_email'] / $_SESSION['user_name'] の構造
 *   - フロントから送る action 名（'verifyGoogleToken' / 'verifySupabaseAuth'）
 */

if (!defined('OLIVENOTE_AUTH_PROVIDER')) {
    define('OLIVENOTE_AUTH_PROVIDER', 'google');
}

$__authProviderFile = __DIR__ . '/auth_' . OLIVENOTE_AUTH_PROVIDER . '.php';
if (!is_file($__authProviderFile)) {
    throw new RuntimeException('Unknown auth provider: ' . OLIVENOTE_AUTH_PROVIDER);
}
require_once $__authProviderFile;
unset($__authProviderFile);

// ログイン/セッションの有効期限（30日）。
// ブラウザ再起動・タブ復元・PHP 一時エラー後でも Cookie が生きていれば再ログインを求めない。
if (!defined('OLIVENOTE_SESSION_LIFETIME')) {
    define('OLIVENOTE_SESSION_LIFETIME', 30 * 24 * 60 * 60); // 2592000秒 = 30日
}

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // サーバー側セッションファイルの寿命も Cookie と揃える（共有ホスティングの GC による早期回収を防ぐ）。
    // 恒久設定は .user.ini 側にもあり、ここはフォールバック。
    @ini_set('session.gc_maxlifetime', (string) OLIVENOTE_SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => OLIVENOTE_SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // スライド更新：アクセスのたびに Cookie の有効期限を 30 日先へ延ばす（使い続ける限り切れない）。
    if (!headers_sent() && !empty($_SESSION['user_email'])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + OLIVENOTE_SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function auth_is_logged_in(): bool {
    return !empty($_SESSION['user_email']);
}

/**
 * 認証ガード。非公開 action でセッションが無ければ 401 で終了する。
 */
function auth_require_session(string $action): void {
    $publicActions = array_merge(['logout'], auth_public_actions());
    if (in_array($action, $publicActions, true)) return;
    if (empty($_SESSION['user_email'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required', 'authRequired' => true]);
        exit;
    }
}

/**
 * Auth 関連 action（logout / provider 固有のトークン検証）を処理する。
 * 処理した場合は true。呼び出し元は true なら以降の switch をスキップする。
 */
function auth_dispatch(string $action, array $payload, PDO $pdo): bool {
    if ($action === 'logout') {
        auth_logout();
        echo json_encode(['success' => true, 'data' => null]);
        return true;
    }
    if (in_array($action, auth_public_actions(), true)) {
        auth_verify_token($payload, $pdo);
        return true;
    }
    return false;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
