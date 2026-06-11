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

// セットアップ完了直後の ?step=done は config.php が既に存在する状態で訪れるため、
// ここで「セットアップ済み」と判定して弾いてしまうと render_done() に到達できない。
// done だけ通過させ、その他のステップは従来通り 409 で弾く。
$incomingStep = $_GET['step'] ?? '';
if (file_exists($configFile) && empty($_GET['force']) && $incomingStep !== 'done') {
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
    3 => '認証方法の選択',
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
    <div class="alert info" style="text-align:left">
        💬 <strong>LINE WORKS 連携（任意）</strong>：通知や、チャットからの課題作成を使えます。
        セットアップ後に <strong>管理画面 →「LINE WORKS 接続設定」</strong>（<code>app/admin/lineworks_settings.php</code>）から設定できます。
        事前準備は <a href="../docs/view.php?doc=LINEWORKS_SETUP.md" target="_blank">LINE WORKS セットアップ手順書</a> をご確認ください。
    </div>
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
        'supabase_url' => '', 'supabase_anon' => '', 'supabase_jwt' => '',
        'sa_email'  => '', 'sa_pk' => '',
        'doc_folder'=> '', 'att_folder' => '', 'ai_folder' => '',
        'vertex_project' => '', 'vertex_location' => 'us-central1',
        'vertex_sa_email' => '', 'vertex_sa_pk' => ''
    ];

    $authMethods = $_SESSION['install']['auth_methods'] ?? [];
    $authCreds = $_SESSION['install']['auth_creds'] ?? [];
    $prevGoogle = !empty($authMethods['google']);
    $prevMicrosoft = !empty($authMethods['microsoft']);
    $prevEmail = !empty($authMethods['email']);

    // セットアップモード（production = Supabase 認証 / demo = 共通パスワード）
    $prevMode = $_SESSION['install']['setup_mode'] ?? 'production';
    $prevDemoPass = $_SESSION['install']['demo_password'] ?? '';

    // Email は初期値 ON
    if (empty($authMethods)) {
        $prevEmail = true;
    }

    $authError = $_SESSION['install']['errors']['auth'] ?? '';
    unset($_SESSION['install']['errors']['auth']);
    ?>
    <h1>③ 認証方法の選択</h1>
    <p>以下の項目を設定します: <strong>①認証方法</strong>、<strong>②Supabase 情報</strong>、<strong>③Google Drive 連携</strong>、<strong>④Vertex AI（任意）</strong><br>
       <strong>初めての場合は</strong> <a href="../docs/view.php?doc=SUPABASE_SETUP.md" target="_blank">Supabase セットアップ手順書</a> と
       <a href="../docs/view.php?doc=DRIVE_SETUP.md" target="_blank">Drive 準備手順書</a> を先にご確認ください。</p>

    <?php if ($authError): ?>
        <div class="alert error"><?= h($authError) ?></div>
    <?php endif; ?>

    <form method="post" onsubmit="return validateAuthMethods()">
        <input type="hidden" name="_token" value="<?= h($_SESSION['_token']) ?>">

        <fieldset>
            <legend>セットアップモード</legend>
            <label style="display: flex; gap: 8px; align-items: flex-start; margin-bottom: 12px;">
                <input type="radio" name="setup_mode" value="production" id="mode-production" <?= $prevMode !== 'demo' ? 'checked' : '' ?> onchange="setMode('production')">
                <span style="flex: 1;">
                    <strong>本番セットアップ</strong>
                    <span class="hint" style="display: block; margin-top: 4px;">Supabase 認証（Google / Microsoft / メール）を使う。Supabase・Google Drive の情報が必要。</span>
                </span>
            </label>
            <label style="display: flex; gap: 8px; align-items: flex-start;">
                <input type="radio" name="setup_mode" value="demo" id="mode-demo" <?= $prevMode === 'demo' ? 'checked' : '' ?> onchange="setMode('demo')">
                <span style="flex: 1;">
                    <strong>デモセットアップ</strong>
                    <span class="hint" style="display: block; margin-top: 4px;">ログインを共通パスワードで簡易化（Supabase 不要）。Google Drive / Vertex AI は下で任意に設定でき、入れれば本番同様にファイル・AI も使えます。営業デモ向け。</span>
                </span>
            </label>
        </fieldset>

        <div id="demo-fields">
            <fieldset>
                <legend>デモ用ログイン設定</legend>
                <p style="font-size: 13px; color: #6b7280; margin: 0 0 12px 0;">
                    登録済みメールアドレスと、ここで決めた共通パスワードでログインできます。Supabase の設定は不要です。
                    （ファイル／ドキュメント機能は下の <strong>③ Google Drive</strong> を、AI 機能は <strong>④ Vertex AI</strong> を入力すれば動作します。空欄ならその機能のみ無効になり、タスク・ボード・ガント等の中核機能は利用できます。）
                </p>
                <label>デモ用共通パスワード
                    <span class="hint">顧客に伝える共通パスワード（4文字以上）</span>
                    <input type="text" name="demo_password" id="demo_password" value="<?= h($prevDemoPass) ?>" placeholder="例: olivenote2026" autocomplete="off">
                </label>
            </fieldset>
        </div>

        <div id="login-prod-fields">
        <fieldset>
            <legend>① 認証方法の選択（最低1つは選択必須）</legend>
            <p style="font-size: 13px; color: #6b7280; margin: 0 0 8px 0;">顧客の組織が使っている認証方式を選択してください。複数選択も可能です。</p>
            <p style="font-size: 12px; color: #92400e; background: #fef3c7; padding: 8px 12px; border-radius: 6px; margin: 0 0 16px 0;">
                ⚠️ Google / Microsoft の Client ID・Secret は <strong>Supabase の Auth Providers 設定に入れる値</strong>と同一のものを指定してください。
                実際の OAuth ハンドシェイクは Supabase が代行し、Olive Note 本体はこれらの値を直接使用しません（config.php に控えとして保存されます）。
                詳細は <a href="../docs/view.php?doc=SUPABASE_SETUP.md" target="_blank">Supabase セットアップ手順書</a> の §2 を参照。
            </p>

            <label style="display: flex; gap: 8px; align-items: flex-start; margin-bottom: 16px;">
                <input type="checkbox" name="auth_google" id="chk-google" value="1" <?= $prevGoogle ? 'checked' : '' ?>>
                <span style="flex: 1;">
                    <strong>Google Workspace / Google Account</strong>
                    <span class="hint" style="display: block; margin-top: 4px;">顧客が Google Workspace を使用している場合や、個人の Google アカウント認証を提供する場合に選択</span>
                </span>
            </label>

            <div id="google-creds" class="<?= $prevGoogle ? '' : 'hidden' ?>" style="margin-left: 24px; margin-bottom: 16px; border-left: 3px solid #3b82f6; padding-left: 12px;">
                <label>Google OAuth Client ID
                    <span class="hint">Google Cloud Console → OAuth 2.0 クライアント から取得</span>
                    <input type="text" name="google_client_id" id="google_client_id" value="<?= h($authCreds['google_client_id'] ?? '') ?>" <?= $prevGoogle ? 'required' : 'disabled' ?> placeholder="例: 123456789-abc...apps.googleusercontent.com">
                </label>
                <label>Google OAuth Client Secret
                    <span class="hint">Google Cloud Console → OAuth 2.0 クライアント の「シークレット」から取得。<strong>Supabase の Auth Providers にも同じ値を入力してください。</strong></span>
                    <input type="text" name="google_client_secret" id="google_client_secret" value="<?= h($authCreds['google_client_secret'] ?? '') ?>" <?= $prevGoogle ? 'required' : 'disabled' ?> placeholder="例: GOCSPX-...">
                </label>
            </div>

            <label style="display: flex; gap: 8px; align-items: flex-start; margin-bottom: 16px;">
                <input type="checkbox" name="auth_microsoft" id="chk-microsoft" value="1" <?= $prevMicrosoft ? 'checked' : '' ?>>
                <span style="flex: 1;">
                    <strong>Microsoft 365 / Azure AD</strong>
                    <span class="hint" style="display: block; margin-top: 4px;">顧客が Microsoft 365（O365）または Azure Active Directory を使用している場合に選択</span>
                </span>
            </label>

            <div id="microsoft-creds" class="<?= $prevMicrosoft ? '' : 'hidden' ?>" style="margin-left: 24px; margin-bottom: 16px; border-left: 3px solid #3b82f6; padding-left: 12px;">
                <label>Microsoft Application (Client) ID
                    <span class="hint">Microsoft Entra ID → App registrations から取得</span>
                    <input type="text" name="microsoft_client_id" id="microsoft_client_id" value="<?= h($authCreds['microsoft_client_id'] ?? '') ?>" <?= $prevMicrosoft ? 'required' : 'disabled' ?> placeholder="例: 12345678-1234-1234...">
                </label>
                <label>Microsoft Client Secret
                    <span class="hint">Microsoft Entra ID → 証明書とシークレット から取得。<strong>Supabase の Auth Providers にも同じ値を入力してください。</strong></span>
                    <input type="text" name="microsoft_client_secret" id="microsoft_client_secret" value="<?= h($authCreds['microsoft_client_secret'] ?? '') ?>" <?= $prevMicrosoft ? 'required' : 'disabled' ?> placeholder="例: ~Z2...">
                </label>
            </div>

            <label style="display: flex; gap: 8px; align-items: flex-start;">
                <input type="checkbox" name="auth_email" id="chk-email" value="1" <?= $prevEmail ? 'checked' : '' ?>>
                <span style="flex: 1;">
                    <strong>📧 Email Magic Link（メール認証）</strong>
                    <span class="hint" style="display: block; margin-top: 4px;">メールアドレスで安全に認証。Supabase の標準 SMTP から送信（追加設定不要）。本番運用で自社ドメインから送りたい場合はセットアップ後に Supabase で SMTP 差し替え可能。</span>
                </span>
            </label>
        </fieldset>

        <fieldset>
            <legend>② Supabase Auth（必須）</legend>
            <label>Project URL
                <span class="hint">例: https://abcdef.supabase.co （Supabase Dashboard → Project Settings → API）</span>
                <input name="supabase_url" required value="<?= h($prev['supabase_url']) ?>" placeholder="https://abcdef.supabase.co">
            </label>
            <label>anon / public Key
                <span class="hint">フロントエンドが使う公開可キー。eyJhbG... で始まる長い JWT</span>
                <textarea name="supabase_anon" required rows="3" placeholder="eyJhbGciOi..."><?= h($prev['supabase_anon']) ?></textarea>
            </label>
            <label>JWT Secret
                <span class="hint">★絶対秘密。サーバー側 JWT 検証用。Project Settings → API ページ下部から取得</span>
                <input name="supabase_jwt" required value="<?= h($prev['supabase_jwt']) ?>" placeholder="super-secret-jwt-key">
            </label>
        </fieldset>
        </div><!-- /#login-prod-fields -->

        <div id="optional-services">
        <fieldset id="drive-fields">
            <legend>③ Google Drive サービスアカウント（本番では必須／デモでは任意）</legend>
            <p style="font-size: 12px; color: #6b7280; margin: 0 0 12px 0;">ファイル・ドキュメント機能で使います。デモセットアップでは空欄でも進めます（その場合ファイル機能のみ無効）。</p>
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
            <legend>④ Vertex AI（オプション・空欄でも可）</legend>
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
        </div><!-- /#optional-services -->

        <div class="actions">
            <a class="btn-secondary" href="install.php?step=2">← 戻る</a>
            <button class="btn-primary" type="submit">次へ →</button>
        </div>
    </form>

    <script>
    // CSS for .hidden class
    const style = document.createElement('style');
    style.textContent = '.hidden { display: none !important; }';
    document.head.appendChild(style);

    // required 初期状態をスナップショット。
    // demo へ切替時に required を外すため、本番へ戻すとき正しく復元できるよう保存しておく。
    // 対象: ログイン本番欄（#login-prod-fields）と Drive 欄（#drive-fields。demo では任意化）。
    ['login-prod-fields', 'drive-fields'].forEach(id => {
        const c = document.getElementById(id);
        if (!c) return;
        c.querySelectorAll('input, textarea, select').forEach(el => {
            el.dataset.req = el.required ? '1' : '0';
        });
    });

    // Google / Microsoft の資格情報入力欄を、チェック状態に同期する
    function syncCred(name) {
        const chk = document.getElementById('chk-' + name);
        const div = document.getElementById(name + '-creds');
        if (!chk || !div) return;
        const inputs = div.querySelectorAll('input');
        // demo モードのときは（ログイン本番欄ごと無効化されるため）資格情報欄も常に無効
        const productionMode = !document.getElementById('mode-demo').checked;
        if (chk.checked && productionMode) {
            div.classList.remove('hidden');
            inputs.forEach(inp => { inp.disabled = false; inp.required = true; });
        } else {
            div.classList.add('hidden');
            inputs.forEach(inp => { inp.disabled = true; inp.required = false; });
        }
    }

    document.getElementById('chk-google').addEventListener('change', () => syncCred('google'));
    document.getElementById('chk-microsoft').addEventListener('change', () => syncCred('microsoft'));

    // セットアップモード切替：
    //   demo       = ログイン本番欄（Supabase/認証方法）を無効化し共通パスワードのみ。
    //                Drive/Vertex は任意入力として残す（入れればファイル・AIも動く）。
    //   production = ログイン本番欄を有効化し、Supabase/Drive を必須に戻す。
    function setMode(mode) {
        const loginProd = document.getElementById('login-prod-fields');
        const drive = document.getElementById('drive-fields');
        const demo = document.getElementById('demo-fields');
        const demoPass = document.getElementById('demo_password');
        if (mode === 'demo') {
            // ログイン本番欄は disabled にして HTML5 検証・送信から除外
            loginProd.classList.add('hidden');
            loginProd.querySelectorAll('input, textarea, select').forEach(el => { el.disabled = true; el.required = false; });
            demo.classList.remove('hidden');
            demoPass.disabled = false;
            demoPass.required = true;
            // Drive は入力欄を残したまま「任意」にする（required を外すが送信はされる）
            drive.querySelectorAll('input, textarea, select').forEach(el => { el.required = false; });
        } else {
            loginProd.classList.remove('hidden');
            demo.classList.add('hidden');
            demoPass.disabled = true;
            demoPass.required = false;
            // ログイン本番欄を再有効化し required をスナップショットから復元
            loginProd.querySelectorAll('input, textarea, select').forEach(el => {
                el.disabled = false;
                el.required = (el.dataset.req === '1');
            });
            // Drive も必須に戻す
            drive.querySelectorAll('input, textarea, select').forEach(el => {
                el.required = (el.dataset.req === '1');
            });
            // Google / Microsoft の資格情報欄はチェック状態に従って再同期（required を上書き）
            syncCred('google');
            syncCred('microsoft');
        }
    }

    // フォーム送信時のバリデーション
    function validateAuthMethods() {
        const demoSelected = document.getElementById('mode-demo').checked;
        if (demoSelected) {
            const dp = document.getElementById('demo_password').value.trim();
            if (dp.length < 4) {
                alert('デモ用共通パスワードは4文字以上で入力してください');
                return false;
            }
            return true;
        }

        const google = document.getElementById('chk-google').checked;
        const microsoft = document.getElementById('chk-microsoft').checked;
        const email = document.getElementById('chk-email').checked;

        if (!google && !microsoft && !email) {
            alert('最低1つの認証方法は選択してください');
            return false;
        }

        // Google を選択している場合、Client ID/Secret が空だと エラー
        if (google) {
            const clientId = document.getElementById('google_client_id').value.trim();
            const secret = document.getElementById('google_client_secret').value.trim();
            if (!clientId || !secret) {
                alert('Google を選択した場合、Client ID と Client Secret の両方が必須です');
                return false;
            }
        }

        // Microsoft を選択している場合、Client ID/Secret が空だと エラー
        if (microsoft) {
            const clientId = document.getElementById('microsoft_client_id').value.trim();
            const secret = document.getElementById('microsoft_client_secret').value.trim();
            if (!clientId || !secret) {
                alert('Microsoft を選択した場合、Client ID と Client Secret の両方が必須です');
                return false;
            }
        }

        return true;
    }

    // 初期表示：選択中モードに合わせてフォーム状態を確定する
    setMode(document.getElementById('mode-demo').checked ? 'demo' : 'production');
    </script>
    <?php
}

function render_step4(): void {
    $prev = $_SESSION['install']['admin'] ?? ['email' => '', 'name' => '', 'avatar' => '🦄'];
    $mode = $_SESSION['install']['setup_mode'] ?? 'production';
    ?>
    <h1>④ 初期管理者の登録</h1>
    <p>最初の管理者ユーザー（is_admin = 1）を登録します。<br>
       <strong>このメールアドレスでログインしないとシステムに入れません。</strong>
       <?php if ($mode === 'demo'): ?>（デモモード：このメールアドレス＋共通パスワードでログインします）<?php else: ?>（Google / Microsoft / Magic Link いずれの方法でもOK）<?php endif; ?></p>

    <form method="post">
        <input type="hidden" name="_token" value="<?= h($_SESSION['_token']) ?>">
        <label>メールアドレス
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
    $authMethods = $_SESSION['install']['auth_methods'] ?? [];
    $authCreds = $_SESSION['install']['auth_creds'] ?? [];
    $mode = $_SESSION['install']['setup_mode'] ?? 'production';
    ?>
    <h1>⑤ 確認と実行</h1>
    <p>以下の内容で <code>config/config.php</code> を生成し、データベースを初期化します。</p>

    <?php if ($mode === 'demo'): ?>
    <div class="alert info" style="background:#fef3c7; border-color:#fde68a; color:#92400e;">
        <strong>🧪 デモセットアップ</strong> — ログインを共通パスワードで簡易化した試用モードです（Supabase 不要）。Google Drive / Vertex を入力していればファイル・AI も本番同様に動作します。本番導入（Supabase 認証）に切り替える場合は再セットアップして本番モードを選んでください。
    </div>
    <?php endif; ?>

    <h3>データベース</h3>
    <ul class="summary">
        <li>ホスト: <code><?= h($db['host'] ?? '') ?></code></li>
        <li>DB名: <code><?= h($db['name'] ?? '') ?></code></li>
        <li>ユーザー: <code><?= h($db['user'] ?? '') ?></code></li>
    </ul>

    <h3>認証方法</h3>
    <?php if ($mode === 'demo'): ?>
    <ul class="summary">
        <li>🧪 デモログイン（共通パスワード）</li>
        <li>共通パスワード: <code><?= !empty($_SESSION['install']['demo_password']) ? '設定済' : '(未設定)' ?></code></li>
    </ul>
    <?php else: ?>
    <ul class="summary">
        <?php foreach (['google', 'microsoft', 'email'] as $method): ?>
            <?php if (!empty($authMethods[$method])): ?>
                <li>
                    <?php if ($method === 'google'): ?>
                        ✅ Google OAuth<br>
                        <code style="font-size: 11px;"><?= h(mb_substr($authCreds['google_client_id'] ?? '', 0, 32)) ?>…</code>
                    <?php elseif ($method === 'microsoft'): ?>
                        ✅ Microsoft Azure AD<br>
                        <code style="font-size: 11px;"><?= h(mb_substr($authCreds['microsoft_client_id'] ?? '', 0, 32)) ?>…</code>
                    <?php elseif ($method === 'email'): ?>
                        ✅ Email Magic Link（Supabase 標準 SMTP）
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($mode !== 'demo'): ?>
    <h3>Supabase Auth</h3>
    <ul class="summary">
        <li>Project URL: <code><?= h($google['supabase_url']  ?? '') ?></code></li>
        <li>anon Key: <code><?= h(mb_substr((string)($google['supabase_anon'] ?? ''), 0, 32)) ?>…(省略)</code></li>
        <li>JWT Secret: <code><?= !empty($google['supabase_jwt']) ? '設定済' : '(未設定)' ?></code></li>
    </ul>
    <?php endif; ?>

    <h3>Google Drive / Vertex<?= $mode === 'demo' ? '（任意）' : '' ?></h3>
    <ul class="summary">
        <li>Drive SA: <code><?= !empty($google['sa_email']) ? h($google['sa_email']) : '(未設定 → ファイル機能は無効)' ?></code></li>
        <li>DOC_FOLDER_ID: <code><?= h($google['doc_folder'] ?? '') ?></code></li>
        <li>ATTACHMENT_FOLDER_ID: <code><?= h($google['att_folder'] ?? '') ?></code></li>
        <li>AI_DOC_FOLDER_ID: <code><?= h($google['ai_folder'] ?? '') ?></code></li>
        <li>Vertex Project: <code><?= !empty($google['vertex_project']) ? h($google['vertex_project']) : '(未設定 → AI 機能は無効)' ?></code></li>
    </ul>

    <h3>初期管理者</h3>
    <ul class="summary">
        <li>メール: <code><?= h($admin['email'] ?? '') ?></code></li>
        <li>表示名: <code><?= h($admin['name']  ?? '') ?></code></li>
    </ul>

    <div class="alert info">
        <strong>⚠️ 確認事項</strong>
        <ul style="margin:8px 0 0 18px">
            <?php if ($mode !== 'demo'): ?>
            <li>Drive サービスアカウントが上記3フォルダに編集権限を持っているか</li>
            <li>Supabase の「Authentication → URL Configuration」に本ドメイン (<code>https://<?= h($_SERVER['HTTP_HOST']) ?>/</code>) が Site URL / Redirect URLs として登録されているか</li>
            <?php else: ?>
            <li>デモ用ログインは「初期管理者のメールアドレス＋共通パスワード」で行います。他の利用者を追加する場合はログイン後に「設定 → メンバー管理」で登録してください</li>
            <?php if (!empty($google['sa_email'])): ?>
            <li>Drive サービスアカウントが上記3フォルダに編集権限を持っているか（ファイル機能を使う場合）</li>
            <?php endif; ?>
            <?php endif; ?>
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
            <strong>🔒 セキュリティ：</strong> 再アクセス防止のため、このページを表示した時点で installer/ ディレクトリは installer.locked/ にリネームされます。再セットアップが必要な場合は手動で <code>config/config.php</code> を削除してから installer.locked → installer に戻してください。
        </div>
        <div class="actions">
            <a class="btn-primary" href="../">アプリを開く →</a>
        </div>
    ');

    // HTML を出力し終えたあとに installer/ をリネームする。
    // ここでリネームしておけば、ブラウザは既にレスポンスを受信済みなので、
    // ユーザーがこのページから「アプリを開く」を踏んだ時点で installer は無効化されている。
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // クライアントへのレスポンスを先に確定
    } else {
        // 通常の mod_php 等ではここまでで output が flush される
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    $thisDir   = __DIR__;
    $lockedDir = $thisDir . '.locked';
    if (is_dir($thisDir) && !is_dir($lockedDir)) {
        @rename($thisDir, $lockedDir);
    }
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
        $_SESSION['install']['db'] = compact('host', 'name', 'user', 'pass');
        header('Location: install.php?step=2');
        exit;
    }

    $_SESSION['install']['db'] = ['host' => $host, 'name' => $name, 'user' => $user, 'pass' => $pass];
}

function handle_step3_google(array $post): void {
    // ---- セットアップモード判定 ----
    $mode = (($post['setup_mode'] ?? 'production') === 'demo') ? 'demo' : 'production';
    $_SESSION['install']['setup_mode'] = $mode;

    if ($mode === 'demo') {
        $demoPass = (string)($post['demo_password'] ?? '');
        $_SESSION['install']['demo_password'] = $demoPass;

        // Supabase は使わないので空。Drive / Vertex はデモでも任意で受け付ける
        // （入力があればファイル・AI も本番同様に動作する）。
        $_SESSION['install']['google'] = [
            'supabase_url'    => '', 'supabase_anon'   => '', 'supabase_jwt' => '',
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
        $_SESSION['install']['auth_methods'] = [];
        $_SESSION['install']['auth_creds']   = [];

        if (strlen($demoPass) < 4) {
            $_SESSION['install']['errors']['auth'] = 'デモ用共通パスワードは4文字以上で入力してください';
            header('Location: install.php?step=3');
            exit;
        }
        return;
    }

    // ---- 以下、本番（production）フロー ----
    $_SESSION['install']['google'] = [
        'supabase_url'    => trim($post['supabase_url']  ?? ''),
        'supabase_anon'   => trim($post['supabase_anon'] ?? ''),
        'supabase_jwt'    => trim($post['supabase_jwt']  ?? ''),
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

    // ---- 認証方法: 検証前にセッション退避（エラー戻り時に入力値を保持するため） ----
    $authMethods = [];
    $authCreds = [];

    if (!empty($post['auth_google'])) {
        $authMethods['google'] = true;
        $authCreds['google_client_id']     = trim($post['google_client_id']     ?? '');
        $authCreds['google_client_secret'] = trim($post['google_client_secret'] ?? '');
    }
    if (!empty($post['auth_microsoft'])) {
        $authMethods['microsoft'] = true;
        $authCreds['microsoft_client_id']     = trim($post['microsoft_client_id']     ?? '');
        $authCreds['microsoft_client_secret'] = trim($post['microsoft_client_secret'] ?? '');
    }
    if (!empty($post['auth_email'])) {
        $authMethods['email'] = true;
    }

    $_SESSION['install']['auth_methods'] = $authMethods;
    $_SESSION['install']['auth_creds']   = $authCreds;

    // ---- バリデーション ----
    if (empty($authMethods)) {
        $_SESSION['install']['errors']['auth'] = '最低1つの認証方法は選択してください';
        header('Location: install.php?step=3');
        exit;
    }
    if (!empty($authMethods['google'])) {
        if (empty($authCreds['google_client_id']) || empty($authCreds['google_client_secret'])) {
            $_SESSION['install']['errors']['auth'] = 'Google を選択した場合、Client ID と Client Secret が必須です';
            header('Location: install.php?step=3');
            exit;
        }
    }
    if (!empty($authMethods['microsoft'])) {
        if (empty($authCreds['microsoft_client_id']) || empty($authCreds['microsoft_client_secret'])) {
            $_SESSION['install']['errors']['auth'] = 'Microsoft を選択した場合、Client ID と Client Secret が必須です';
            header('Location: install.php?step=3');
            exit;
        }
    }
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
    $authMethods = $_SESSION['install']['auth_methods'] ?? [];
    $authCreds = $_SESSION['install']['auth_creds'] ?? [];
    $mode = $_SESSION['install']['setup_mode'] ?? 'production';

    // セッション切れ等で認証方法が空の場合はエラー（demo モードは認証方法不要なので除外）
    if ($mode !== 'demo' && empty($authMethods)) {
        die('セッションが切れたか無効な状態です。ステップ3からやり直してください。');
    }
    // demo モードは config 生成の直前に共通パスワードを再検証する
    // （ステップ間の改ざん・直接 POST で短いパスワードがすり抜けるのを防ぐ最終ゲート）
    if ($mode === 'demo' && strlen((string)($_SESSION['install']['demo_password'] ?? '')) < 4) {
        die('デモ用共通パスワードが未設定または短すぎます。ステップ3からやり直してください。');
    }

    $root = dirname(__DIR__);

    // ---- 1. config.php 生成 ----
    $sampleFile = $root . '/config/config.sample.php';
    if (!is_file($sampleFile)) {
        die('config.sample.php が見つかりません');
    }
    $tmpl = file_get_contents($sampleFile);

    // 認証方法配列を PHP 配列リテラル文字列に変換
    $providers = [];
    if (!empty($authMethods['google'])) $providers[] = 'google';
    if (!empty($authMethods['microsoft'])) $providers[] = 'microsoft';
    if (!empty($authMethods['email'])) $providers[] = 'email';
    $providersStr = '[' . implode(', ', array_map(fn($p) => "'$p'", $providers)) . ']';

    // セットアップモードに応じて認証プロバイダ／デモパスワードを確定
    $authProvider = ($mode === 'demo') ? 'demo' : 'supabase';
    $demoPassword = ($mode === 'demo') ? ($_SESSION['install']['demo_password'] ?? '') : '';

    $repl = [
        '__AUTH_PROVIDER__'          => $authProvider,
        '__DEMO_PASSWORD__'          => addslashes_php($demoPassword),
        '__DB_HOST__'                => addslashes_php($db['host']),
        '__DB_NAME__'                => addslashes_php($db['name']),
        '__DB_USER__'                => addslashes_php($db['user']),
        '__DB_PASS__'                => addslashes_php($db['pass']),
        '__SUPABASE_URL__'           => addslashes_php($google['supabase_url']),
        '__SUPABASE_ANON_KEY__'      => addslashes_php($google['supabase_anon']),
        '__SUPABASE_JWT_SECRET__'    => addslashes_php($google['supabase_jwt']),
        '__SUPABASE_PROVIDERS__'     => $providersStr,
        '__GOOGLE_CLIENT_ID__'       => addslashes_php($authCreds['google_client_id'] ?? ''),
        '__GOOGLE_CLIENT_SECRET__'   => addslashes_php($authCreds['google_client_secret'] ?? ''),
        '__MICROSOFT_CLIENT_ID__'    => addslashes_php($authCreds['microsoft_client_id'] ?? ''),
        '__MICROSOFT_CLIENT_SECRET__' => addslashes_php($authCreds['microsoft_client_secret'] ?? ''),
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
    $log[] = ($mode === 'demo')
        ? '✅ config/config.php を生成（デモモード・共通パスワード認証）'
        : '✅ config/config.php を生成（認証方法: ' . implode(', ', $providers) . '）';

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

    // ---- 4. インストーラのロックは done ページ表示後に遅延実行する ----
    // この時点で installer/ をリネームしてしまうと、直後の redirect→step=done が
    // 「installer/install.php」を解決できず 404 になる。
    // → render_done() が HTML を出力し終わってから rename することで解決。
    $log[] = 'ℹ️ installer/ ロックはこのページ表示後に実施します';

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
