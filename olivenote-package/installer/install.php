<?php
/**
 * Olive Note - インストーラ
 *
 * シングルファイルのウィザード。
 * セッションでステップ間の状態を保持する。
 *
 * 完了後は本ディレクトリを自動でリネーム (installer.locked) して
 * 再アクセス不可にする。
 */

session_start();
header('X-Robots-Tag: noindex, nofollow');

// ---- 既にインストール済みなら中断 ----
$rootDir   = dirname(__DIR__);
$configDir = $rootDir . '/config';
$configFile = $configDir . '/config.php';

if (file_exists($configFile) && empty($_GET['force'])) {
    http_response_code(409);
    echo render_layout('セットアップ済み', '
        <h1>すでにセットアップ済みです</h1>
        <p>このサーバーには Olive Note がインストール済みです。再セットアップしたい場合は、<code>config/config.php</code> を削除してから <a href="install.php">install.php</a> にアクセスしてください。</p>
        <p><a class="btn-primary" href="../">アプリを開く →</a></p>
    ');
    exit;
}

// ---- ステップ管理 ----
$steps = [
    1 => '環境チェック',
    2 => 'データベース接続',
    3 => 'Google設定',
    4 => '初期管理者',
    5 => '確定・実行',
];

$step = max(1, min(5, (int)($_GET['step'] ?? 1)));

// ---- POST 処理 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['_token']) || !hash_equals($_SESSION['_token'] ?? '', $_POST['_token'])) {
        die('CSRF token mismatch. ブラウザのページを再読み込みしてやり直してください。');
    }

    switch ($step) {
        case 2: handle_step2_db($_POST);     header('Location: install.php?step=3'); exit;
        case 3: handle_step3_google($_POST); header('Location: install.php?step=4'); exit;
        case 4: handle_step4_admin($_POST);  header('Location: install.php?step=5'); exit;
        case 5: handle_step5_finalize();      header('Location: install.php?step=done'); exit;
    }
}

// CSRF トークン
if (empty($_SESSION['_token'])) {
    $_SESSION['_token'] = bin2hex(random_bytes(16));
}

// ---- ステップ別レンダリング ----
if (($_GET['step'] ?? '') === 'done') {
    render_done();
    exit;
}

ob_start();
switch ($step) {
    case 1: render_step1(); break;
    case 2: render_step2(); break;
    case 3: render_step3(); break;
    case 4: render_step4(); break;
    case 5: render_step5(); break;
}
$content = ob_get_clean();

echo render_layout($steps[$step], render_progress($step, $steps) . $content);

// ============================================================
// 各ステップのレンダリング
// ============================================================

function render_step1(): void {
    $checks = run_env_checks();
    $allOk = !in_array(false, array_column($checks, 'ok'), true);
    ?>
    <h1>① 環境チェック</h1>
    <p>サーバー環境が Olive Note の動作要件を満たしているか確認します。</p>
    <table class="check-table">
        <?php foreach ($checks as $c): ?>
            <tr class="<?= $c['ok'] ? 'ok' : 'ng' ?>">
                <td class="status"><?= $c['ok'] ? '✅' : '❌' ?></td>
                <td class="name"><?= h($c['name']) ?></td>
                <td class="value"><?= h($c['value']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($allOk): ?>
        <div class="actions">
            <a class="btn-primary" href="install.php?step=2">次へ進む →</a>
        </div>
    <?php else: ?>
        <div class="alert error">
            <strong>❌ 必要な要件が満たされていません。</strong>
            上記の ❌ 項目を解消してから、画面を再読み込みしてください。
        </div>
    <?php endif;
}

function render_step2(): void {
    $prev = $_SESSION['install']['db'] ?? ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => ''];
    $error = $_SESSION['install']['errors']['db'] ?? '';
    unset($_SESSION['install']['errors']['db']);
    ?>
    <h1>② データベース接続</h1>
    <p>このアプリ専用に作成済みの MySQL データベースの接続情報を入力してください。</p>
    <?php if ($error): ?>
        <div class="alert error">接続に失敗しました: <?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="_token" value="<?= h($_SESSION['_token']) ?>">
        <label>DBホスト <span class="hint">例：mysql8093.xserver.jp</span>
            <input name="db_host" required value="<?= h($prev['host']) ?>">
        </label>
        <label>DB名
            <input name="db_name" required value="<?= h($prev['name']) ?>">
        </label>
        <label>DBユーザー
            <input name="db_user" required value="<?= h($prev['user']) ?>">
        </label>
        <label>DBパスワード
            <input name="db_pass" type="password" required value="<?= h($prev['pass']) ?>">
        </label>
        <div class="actions">
            <a class="btn-secondary" href="install.php?step=1">← 戻る</a>
            <button class="btn-primary" type="submit">接続テスト → 次へ</button>
        </div>
    </form>
    <?php
}

function render_step3(): void {
    $prev = $_SESSION['install']['google'] ?? [
        'client_id' => '',
        'sa_email'  => '', 'sa_pk' => '',
        'doc_folder'=> '', 'att_folder' => '', 'ai_folder' => '',
        'vertex_project' => '', 'vertex_location' => 'us-central1',
        'vertex_sa_email' => '', 'vertex_sa_pk' => ''
    ];
    ?>
    <h1>③ Google 連携設定</h1>
    <p>Google OAuth と Drive サービスアカウントの情報を登録します。<br>
       <strong>初めての場合は</strong> <a href="../docs/view.php?doc=OAUTH_SETUP.md" target="_blank">OAuthセットアップ手順書</a> と
       <a href="../docs/view.php?doc=DRIVE_SETUP.md" target="_blank">Drive準備手順書</a> を先にご確認ください。</p>

    <form method="post">
        <input type="hidden" name="_token" value="<?= h($_SESSION['_token']) ?>">

        <fieldset>
            <legend>Google Sign-In（必須）</legend>
            <label>OAuth Client ID
                <span class="hint">~.apps.googleusercontent.com で終わる文字列</span>
                <input name="client_id" required value="<?= h($prev['client_id']) ?>" placeholder="123456789-xxxxx.apps.googleusercontent.com">
            </label>
        </fieldset>

        <fieldset>
            <legend>Drive サービスアカウント（必須）</legend>
            <label>SA メールアドレス
                <input name="sa_email" required value="<?= h($prev['sa_email']) ?>" placeholder="xxx@xxx.iam.gserviceaccount.com">
            </label>
            <label>SA 秘密鍵 (PEM)
                <span class="hint">SAのJSONキー内 "private_key" の値。改行は \n のままでもOK</span>
                <textarea name="sa_pk" required rows="6" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?= h($prev['sa_pk']) ?></textarea>
            </label>
            <label>ドキュメント保管フォルダID（DOC_FOLDER_ID）
                <input name="doc_folder" required value="<?= h($prev['doc_folder']) ?>">
            </label>
            <label>添付ファイル保管フォルダID（ATTACHMENT_FOLDER_ID）
                <input name="att_folder" required value="<?= h($prev['att_folder']) ?>">
            </label>
            <label>AI生成ドキュメント保管フォルダID（AI_DOC_FOLDER_ID）
                <input name="ai_folder" required value="<?= h($prev['ai_folder']) ?>">
            </label>
        </fieldset>

        <fieldset>
            <legend>Vertex AI（オプション・空欄でも可）</legend>
            <label>Vertex Project ID
                <input name="vertex_project" value="<?= h($prev['vertex_project']) ?>">
            </label>
            <label>Vertex Location <span class="hint">通常は us-central1</span>
                <input name="vertex_location" value="<?= h($prev['vertex_location']) ?>">
            </label>
            <label>Vertex SA メールアドレス
                <input name="vertex_sa_email" value="<?= h($prev['vertex_sa_email']) ?>">
            </label>
            <label>Vertex SA 秘密鍵 (PEM)
                <textarea name="vertex_sa_pk" rows="6"><?= h($prev['vertex_sa_pk']) ?></textarea>
            </label>
        </fieldset>

        <div class="actions">
            <a class="btn-secondary" href="install.php?step=2">← 戻る</a>
            <button class="btn-primary" type="submit">次へ →</button>
        </div>
    </form>
    <?php
}

function render_step4(): void {
    $prev = $_SESSION['install']['admin'] ?? ['email' => '', 'name' => '', 'avatar' => '🦄'];
    ?>
    <h1>④ 初期管理者の登録</h1>
    <p>最初の管理者ユーザー（is_admin = 1）を登録します。<br>
       <strong>このGoogleアカウントでログインしないとシステムに入れません。</strong></p>

    <form method="post">
        <input type="hidden" name="_token" value="<?= h($_SESSION['_token']) ?>">
        <label>Googleアカウント（メールアドレス）
            <input type="email" name="admin_email" required value="<?= h($prev['email']) ?>">
        </label>
        <label>表示名
            <input name="admin_name" required value="<?= h($prev['name']) ?>">
        </label>
        <label>アバター絵文字
            <input name="admin_avatar" value="<?= h($prev['avatar']) ?>" maxlength="4">
        </label>

        <div class="actions">
            <a class="btn-secondary" href="install.php?step=3">← 戻る</a>
            <button class="btn-primary" type="submit">次へ →</button>
        </div>
    </form>
    <?php
}

function render_step5(): void {
    $db     = $_SESSION['install']['db']     ?? [];
    $google = $_SESSION['install']['google'] ?? [];
    $admin  = $_SESSION['install']['admin']  ?? [];
    ?>
    <h1>⑤ 確認と実行</h1>
    <p>以下の内容で <code>config/config.php</code> を生成し、データベースを初期化します。</p>

    <h3>データベース</h3>
    <ul class="summary">
        <li>ホスト: <code><?= h($db['host'] ?? '') ?></code></li>
        <li>DB名: <code><?= h($db['name'] ?? '') ?></code></li>
        <li>ユーザー: <code><?= h($db['user'] ?? '') ?></code></li>
    </ul>

    <h3>Google 設定</h3>
    <ul class="summary">
        <li>OAuth Client ID: <code><?= h($google['client_id'] ?? '') ?></code></li>
        <li>Drive SA: <code><?= h($google['sa_email'] ?? '') ?></code></li>
        <li>DOC_FOLDER_ID: <code><?= h($google['doc_folder'] ?? '') ?></code></li>
        <li>ATTACHMENT_FOLDER_ID: <code><?= h($google['att_folder'] ?? '') ?></code></li>
        <li>AI_DOC_FOLDER_ID: <code><?= h($google['ai_folder'] ?? '') ?></code></li>
        <li>Vertex Project: <code><?= h($google['vertex_project'] ?? '(未設定)') ?></code></li>
    </ul>

    <h3>初期管理者</h3>
    <ul class="summary">
        <li>メール: <code><?= h($admin['email'] ?? '') ?></code></li>
        <li>表示名: <code><?= h($admin['name']  ?? '') ?></code></li>
    </ul>

    <div class="alert info">
        <strong>⚠️ 確認事項</strong>
        <ul style="margin:8px 0 0 18px">
            <li>Drive サービスアカウントが上記3フォルダに編集権限を持っているか</li>
            <li>OAuth Client ID の「承認済みのJavaScript生成元」に本ドメイン (<code>https://<?= h($_SERVER['HTTP_HOST']) ?></code>) が登録されているか</li>
            <li>HTTPS でアクセスしているか</li>
        </ul>
    </div>

    <form method="post">
        <input type="hidden" name="_token" value="<?= h($_SESSION['_token']) ?>">
        <div class="actions">
            <a class="btn-secondary" href="install.php?step=4">← 戻る</a>
            <button class="btn-primary" type="submit">セットアップを実行 →</button>
        </div>
    </form>
    <?php
}

function render_done(): void {
    $log = $_SESSION['install']['log'] ?? [];
    echo render_layout('セットアップ完了', '
        <h1>🎉 セットアップが完了しました！</h1>
        <p>Olive Note のインストールが正常に完了しました。下のボタンからアプリを開いてログインしてください。</p>
        <pre class="log">' . h(implode("\n", $log)) . '</pre>
        <div class="alert info">
            <strong>🔒 セキュリティ：</strong> installer/ ディレクトリは installer.locked/ にリネームされました。再セットアップが必要な場合は手動で <code>config/config.php</code> を削除してから installer.locked → installer に戻してください。
        </div>
        <div class="actions">
            <a class="btn-primary" href="../">アプリを開く →</a>
        </div>
    ');
}

// ============================================================
// 各ステップのハンドラ
// ============================================================

function handle_step2_db(array $post): void {
    $host = trim($post['db_host'] ?? '');
    $name = trim($post['db_name'] ?? '');
    $user = trim($post['db_user'] ?? '');
    $pass = $post['db_pass'] ?? '';

    try {
        new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $e) {
        $_SESSION['install']['errors']['db'] = $e->getMessage();
        $_SESSION['install']['db'] = compact('host','name','user','pass') + ['host' => $host, 'name' => $name, 'user' => $user, 'pass' => $pass];
        header('Location: install.php?step=2');
        exit;
    }

    $_SESSION['install']['db'] = ['host' => $host, 'name' => $name, 'user' => $user, 'pass' => $pass];
}

function handle_step3_google(array $post): void {
    $_SESSION['install']['google'] = [
        'client_id'       => trim($post['client_id'] ?? ''),
        'sa_email'        => trim($post['sa_email']  ?? ''),
        'sa_pk'           => trim($post['sa_pk']     ?? ''),
        'doc_folder'      => trim($post['doc_folder']?? ''),
        'att_folder'      => trim($post['att_folder']?? ''),
        'ai_folder'       => trim($post['ai_folder'] ?? ''),
        'vertex_project'  => trim($post['vertex_project']  ?? ''),
        'vertex_location' => trim($post['vertex_location'] ?? 'us-central1'),
        'vertex_sa_email' => trim($post['vertex_sa_email'] ?? ''),
        'vertex_sa_pk'    => trim($post['vertex_sa_pk']    ?? ''),
    ];
}

function handle_step4_admin(array $post): void {
    $_SESSION['install']['admin'] = [
        'email'  => trim($post['admin_email']  ?? ''),
        'name'   => trim($post['admin_name']   ?? ''),
        'avatar' => trim($post['admin_avatar'] ?? '🦄'),
    ];
}

function handle_step5_finalize(): void {
    $log = [];
    $db     = $_SESSION['install']['db'];
    $google = $_SESSION['install']['google'];
    $admin  = $_SESSION['install']['admin'];

    $root = dirname(__DIR__);

    // ---- 1. config.php 生成 ----
    $sampleFile = $root . '/config/config.sample.php';
    if (!is_file($sampleFile)) {
        die('config.sample.php が見つかりません');
    }
    $tmpl = file_get_contents($sampleFile);
    $repl = [
        '__DB_HOST__'                => addslashes_php($db['host']),
        '__DB_NAME__'                => addslashes_php($db['name']),
        '__DB_USER__'                => addslashes_php($db['user']),
        '__DB_PASS__'                => addslashes_php($db['pass']),
        '__GOOGLE_CLIENT_ID__'       => addslashes_php($google['client_id']),
        '__DRIVE_SA_EMAIL__'         => addslashes_php($google['sa_email']),
        '__DRIVE_SA_PRIVATE_KEY__'   => addslashes_php(normalize_pem($google['sa_pk'])),
        '__DOC_FOLDER_ID__'          => addslashes_php($google['doc_folder']),
        '__ATTACHMENT_FOLDER_ID__'   => addslashes_php($google['att_folder']),
        '__AI_DOC_FOLDER_ID__'       => addslashes_php($google['ai_folder']),
        '__VERTEX_PROJECT_ID__'      => addslashes_php($google['vertex_project']),
        '__VERTEX_LOCATION__'        => addslashes_php($google['vertex_location']),
        '__VERTEX_SA_EMAIL__'        => addslashes_php($google['vertex_sa_email']),
        '__VERTEX_SA_PRIVATE_KEY__'  => addslashes_php(normalize_pem($google['vertex_sa_pk'])),
        '__INSTANCE_ID__'            => bin2hex(random_bytes(8)),
    ];
    foreach ($repl as $k => $v) {
        $tmpl = str_replace($k, $v, $tmpl);
    }
    if (file_put_contents($root . '/config/config.php', $tmpl) === false) {
        die('config/config.php への書き込みに失敗しました');
    }
    @chmod($root . '/config/config.php', 0640);
    $log[] = '✅ config/config.php を生成';

    // ---- 2. データベース migration を実行 ----
    require_once $root . '/app/lib/bootstrap.php';
    require_once $root . '/app/lib/migrations.php';
    $migResult = olivenote_run_pending_migrations();
    if (!empty($migResult['errors'])) {
        die('Migration エラー: ' . implode(' / ', $migResult['errors']));
    }
    $log[] = '✅ Migration: ' . count($migResult['applied']) . '件適用 (' . implode(', ', $migResult['applied']) . ')';

    // ---- 3. 初期管理者を登録 ----
    $pdo = olivenote_db();
    $pdo->prepare("INSERT INTO members (email, name, avatar, is_admin, default_category)
                   VALUES (?, ?, ?, 1, '')
                   ON DUPLICATE KEY UPDATE name = VALUES(name), avatar = VALUES(avatar), is_admin = 1")
        ->execute([$admin['email'], $admin['name'], $admin['avatar']]);
    $log[] = '✅ 初期管理者 ' . $admin['email'] . ' を登録';

    // ---- 4. インストーラを自分自身ロック ----
    $thisDir = __DIR__;
    $lockedDir = $thisDir . '.locked';
    if (is_dir($thisDir) && !is_dir($lockedDir)) {
        @rename($thisDir, $lockedDir);
        $log[] = '✅ installer/ を installer.locked/ にリネーム';
    }

    $_SESSION['install']['log'] = $log;
}

// ============================================================
// 環境チェック
// ============================================================
function run_env_checks(): array {
    $root = dirname(__DIR__);
    $checks = [];

    $phpv = PHP_VERSION;
    $checks[] = ['ok' => version_compare($phpv, '8.0', '>='), 'name' => 'PHP バージョン (>= 8.0)', 'value' => $phpv];

    $checks[] = ['ok' => extension_loaded('pdo_mysql'), 'name' => 'PDO MySQL', 'value' => extension_loaded('pdo_mysql') ? '有効' : '無効'];
    $checks[] = ['ok' => extension_loaded('openssl'),   'name' => 'OpenSSL',   'value' => extension_loaded('openssl')   ? '有効' : '無効'];
    $checks[] = ['ok' => extension_loaded('curl'),      'name' => 'cURL',      'value' => extension_loaded('curl')      ? '有効' : '無効'];
    $checks[] = ['ok' => extension_loaded('json'),      'name' => 'JSON',      'value' => extension_loaded('json')      ? '有効' : '無効'];
    $checks[] = ['ok' => extension_loaded('zip'),       'name' => 'ZipArchive (アップデート用)', 'value' => extension_loaded('zip') ? '有効' : '無効'];
    $checks[] = ['ok' => extension_loaded('mbstring'),  'name' => 'mbstring',  'value' => extension_loaded('mbstring')  ? '有効' : '無効'];

    $writeDirs = [
        ['config', $root . '/config'],
        ['data/backups', $root . '/data/backups'],
        ['data/cache',   $root . '/data/cache'],
        ['data/tmp',     $root . '/data/tmp'],
        ['app（アップデート用）', $root . '/app'],
    ];
    foreach ($writeDirs as [$label, $path]) {
        $ok = is_dir($path) && is_writable($path);
        $checks[] = ['ok' => $ok, 'name' => $label . ' 書き込み権限', 'value' => $ok ? "$path : OK" : "$path : 書き込み不可"];
    }

    $checks[] = ['ok' => isset($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
                 'name' => 'HTTPS 接続', 'value' => isset($_SERVER['HTTPS']) ? '有効' : 'HTTPでアクセスしている可能性があります'];

    return $checks;
}

// ============================================================
// レイアウト
// ============================================================
function render_layout(string $title, string $body): string {
    $h = $title . ' - Olive Note セットアップ';
    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$h}</title>
<style>
  body { font-family: 'Hiragino Sans', 'Noto Sans JP', 'Yu Gothic', sans-serif; background: linear-gradient(135deg, #ecfdf5, #eff6ff); margin: 0; padding: 24px; color: #1f2937; }
  .container { max-width: 720px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
  h1 { color: #047857; margin-top: 0; border-bottom: 2px solid #d1fae5; padding-bottom: 12px; }
  h3 { color: #374151; margin-top: 20px; }
  .progress { display: flex; gap: 4px; margin-bottom: 24px; }
  .progress .step { flex: 1; padding: 8px 4px; text-align: center; font-size: 12px; border-radius: 6px; background: #f3f4f6; color: #9ca3af; }
  .progress .step.active { background: #10b981; color: white; font-weight: bold; }
  .progress .step.done   { background: #d1fae5; color: #047857; }
  label { display: block; margin-bottom: 14px; font-weight: bold; color: #374151; font-size: 14px; }
  .hint { font-weight: normal; font-size: 11px; color: #6b7280; margin-left: 6px; }
  input, textarea, select { display: block; width: 100%; margin-top: 4px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
  textarea { font-family: 'Consolas', 'Menlo', monospace; font-size: 12px; }
  fieldset { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
  legend { font-weight: bold; color: #047857; padding: 0 8px; }
  .actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #f3f4f6; }
  .btn-primary, .btn-secondary { padding: 12px 24px; border-radius: 8px; font-weight: bold; text-decoration: none; cursor: pointer; border: none; font-size: 14px; }
  .btn-primary { background: #10b981; color: white; }
  .btn-primary:hover { background: #059669; }
  .btn-secondary { background: #f3f4f6; color: #6b7280; }
  .btn-secondary:hover { background: #e5e7eb; }
  .check-table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px; }
  .check-table tr { border-bottom: 1px solid #f3f4f6; }
  .check-table tr.ok { background: #f0fdf4; }
  .check-table tr.ng { background: #fef2f2; }
  .check-table td { padding: 8px 12px; }
  .check-table td.status { width: 40px; text-align: center; font-size: 18px; }
  .check-table td.name { font-weight: bold; }
  .check-table td.value { color: #6b7280; font-family: monospace; font-size: 11px; }
  .alert { padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-size: 14px; }
  .alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .alert.info  { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  ul.summary { background: #f9fafb; padding: 12px 24px; border-radius: 6px; font-size: 13px; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
  pre.log { background: #1f2937; color: #d1fae5; padding: 12px 16px; border-radius: 6px; font-size: 12px; line-height: 1.6; overflow-x: auto; }
</style>
</head>
<body>
<div class="container">
  {$body}
  <p style="text-align:center; color:#9ca3af; font-size:11px; margin-top:32px;">🌿 Olive Note Installer</p>
</div>
</body>
</html>
HTML;
}

function render_progress(int $current, array $steps): string {
    $html = '<div class="progress">';
    foreach ($steps as $num => $label) {
        $cls = $num < $current ? 'done' : ($num === $current ? 'active' : '');
        $html .= "<div class=\"step $cls\">$num. " . h($label) . "</div>";
    }
    $html .= '</div>';
    return $html;
}

// ============================================================
// ユーティリティ
// ============================================================
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** PHP の single-quoted 文字列に埋め込むため backslash と single-quote をエスケープ */
function addslashes_php(string $s): string {
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
}

/** PEM 形式の private key を 1行 \n 区切り文字列に整形 */
function normalize_pem(string $pem): string {
    if ($pem === '') return '';
    // 既に \n リテラル形式 ("-----BEGIN PRIVATE KEY-----\n...") のときはそのまま
    if (strpos($pem, '\\n') !== false && strpos($pem, "\n") === false) {
        return $pem;
    }
    // 改行を \n リテラルに置換
    return str_replace(["\r\n", "\r", "\n"], '\\n', $pem);
}
