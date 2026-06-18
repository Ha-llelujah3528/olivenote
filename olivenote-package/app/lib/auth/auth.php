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

/**
 * OliveNote 専用のセッション保存ディレクトリを用意して save_path に設定する。
 *
 * 背景（最重要）: Xserver 等の共有ホスティングでは、同一アカウントに同居する別アプリが
 * セッション保存先（既定の save_path）を共有している。隣のアプリが既定の
 * gc_maxlifetime（多くは 1440 秒 = 24 分）で GC を走らせると、OliveNote が .user.ini で
 * gc_maxlifetime=30日 に伸ばしていても、その GC が OliveNote のセッションファイルごと
 * 削除してしまう。.user.ini は「自分の GC」しか制御できず、隣のアプリの GC は止められない。
 * 結果として「ログインから一定時間後に保存すると 401 → ログイン画面に戻る」事故が起きる。
 *
 * 対策: OliveNote だけの専用サブディレクトリを save_path に指定する。PHP のセッション GC は
 * 設定された save_path 直下の sess_* ファイルのみを対象に走る（サブディレクトリへは降りない）
 * ため、親ディレクトリを save_path にしている隣のアプリの GC からは不可視になり、
 * OliveNote のセッションは自分の gc_maxlifetime（30日）だけで管理されるようになる。
 *
 * 安全側設計: 書き込み可能な専用ディレクトリを用意できたときだけ save_path を変更する。
 * 用意できなければ何もしない（＝従来どおり既定の save_path）。改善はしても壊さない。
 * いずれの候補も web 非公開域（既定 tmp / config.php 同階層 / システム tmp）なので
 * セッションファイルが web 経由で読まれることはない。
 *
 * 注意: 本変更の初回反映時のみ、保存先が変わるため既存ログインは一度だけ無効化され、
 * 利用者は再ログインが必要になる（以降は 30 日間安定して維持される）。
 */
function auth_private_session_dir(): ?string {
    static $resolved = false;
    static $cached = null;
    if ($resolved) return $cached;
    $resolved = true;

    $subdir = 'olivenote_sessions';
    $candidates = [];

    // 1) 既定 save_path 直下の専用サブディレクトリ（Xserver が想定する保存領域のまま隔離できる）
    $default = session_save_path();
    if (is_string($default) && $default !== '') {
        // "N;/path" 形式（ハッシュ階層指定）にも対応してパス部分だけ取り出す
        $defPath = (strpos($default, ';') !== false) ? substr($default, strrpos($default, ';') + 1) : $default;
        if ($defPath !== '' && is_dir($defPath) && is_writable($defPath)) {
            $candidates[] = rtrim($defPath, '/\\') . DIRECTORY_SEPARATOR . $subdir;
        }
    }
    // 2) config.php と同階層（web 非公開域）。auth.php = <app>/lib/auth なので 5 階層上。
    //    ※ ディレクトリ階層が想定と異なる環境でホーム/ルート等を誤採用しないよう、
    //      実際に config.php が存在するディレクトリであることを確認してから候補にする。
    $cfgDir = dirname(__DIR__, 5);
    if (is_string($cfgDir) && $cfgDir !== '' && is_dir($cfgDir) && is_writable($cfgDir)
        && is_file($cfgDir . DIRECTORY_SEPARATOR . 'config.php')) {
        $candidates[] = rtrim($cfgDir, '/\\') . DIRECTORY_SEPARATOR . $subdir;
    }
    // 3) システム一時ディレクトリ配下の専用サブディレクトリ（最終フォールバック）
    $sys = sys_get_temp_dir();
    if (is_string($sys) && $sys !== '') {
        $candidates[] = rtrim($sys, '/\\') . DIRECTORY_SEPARATOR . $subdir;
    }

    foreach ($candidates as $dir) {
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            if (is_dir($dir) && is_writable($dir)) {
                $cached = $dir;
                return $cached;
            }
        }
    }
    return null; // 用意できなければ save_path は変更しない（従来挙動）
}

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // サーバー側セッションファイルの寿命も Cookie と揃える（共有ホスティングの GC による早期回収を防ぐ）。
    // 恒久設定は .user.ini 側にもあり、ここはフォールバック。
    @ini_set('session.gc_maxlifetime', (string) OLIVENOTE_SESSION_LIFETIME);
    // OliveNote 専用のセッション保存先に隔離し、同居アプリの GC による早期削除を防ぐ。
    // （save_path は session_start() より前に設定する必要がある）
    $privateSessionDir = auth_private_session_dir();
    if ($privateSessionDir !== null) {
        @ini_set('session.save_path', $privateSessionDir);
    }
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
