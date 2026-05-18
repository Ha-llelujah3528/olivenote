<?php
/**
 * Olive Note - Bootstrap
 *
 * すべての PHP エントリから最初に require される。
 * - config/config.php を読み込む
 * - PDO 接続 ($pdo) を確立
 * - 定数 OLIVENOTE_VERSION / OLIVENOTE_ROOT 等を定義
 */

if (defined('OLIVENOTE_BOOTSTRAPPED')) return;
define('OLIVENOTE_BOOTSTRAPPED', true);

// ---- パス定数 ----
define('OLIVENOTE_APP',     dirname(__DIR__));               // .../app
define('OLIVENOTE_ROOT',    dirname(OLIVENOTE_APP));         // .../ (project root)
define('OLIVENOTE_CONFIG',  OLIVENOTE_ROOT . '/config');
define('OLIVENOTE_DATA',    OLIVENOTE_ROOT . '/data');
define('OLIVENOTE_MIGRATIONS', OLIVENOTE_APP . '/migrations');

// ---- バージョン ----
$versionFile = OLIVENOTE_APP . '/VERSION';
define('OLIVENOTE_VERSION', is_file($versionFile) ? trim(file_get_contents($versionFile)) : '0.0.0');

// ---- 設定ファイル読み込み ----
$configFile = OLIVENOTE_CONFIG . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo '設定ファイル (config/config.php) が見つかりません。インストーラを実行してください。';
    exit;
}
require_once $configFile;

// ---- PDO 接続（グローバル $pdo を提供） ----
try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
        DB_USER, DB_PASS, $options
    );
    // bootstrap.php は関数スコープ内から require されることもある（installer の finalize 等）。
    // その場合ローカル変数 $pdo になってしまい、olivenote_db() の global $pdo が null を返すため
    // 明示的に $GLOBALS にも格納してスコープ非依存にする。
    $GLOBALS['pdo'] = $pdo;
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Database Connection Failed',
        'debug_message' => $e->getMessage()
    ]);
    exit;
}

// ---- 簡易ヘルパー ----
/**
 * DB 接続を取得する（lib 内で再利用するため）
 */
function olivenote_db(): PDO {
    global $pdo;
    return $pdo;
}

/**
 * 設定値を取得（settings テーブル）
 */
function olivenote_setting(string $key, $default = null) {
    $stmt = olivenote_db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false) ? $default : $v;
}

/**
 * 設定値をセットする（INSERT or UPDATE）
 */
function olivenote_set_setting(string $key, string $value): void {
    olivenote_db()
        ->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}
