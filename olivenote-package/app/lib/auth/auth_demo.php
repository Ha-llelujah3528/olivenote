<?php
/**
 * lib/auth/auth_demo.php — デモ用簡易ログイン（共通パスワード方式）
 *
 * 営業デモ向け。Supabase / Google / Microsoft / SMTP など外部サービスの
 * 設定を一切せずに動かせる軽量プロバイダ。
 *
 * config.php で下記を設定して有効化する:
 *   define('OLIVENOTE_AUTH_PROVIDER', 'demo');
 *   define('OLIVENOTE_DEMO_PASSWORD', '配布する共通パスワード');
 *
 * ログインできるのは members テーブルに登録済みのメールアドレスのみ
 * （誰が使えるかの管理は本番プロバイダと同じく DB 側で行う）。
 * パスワードは全員共通で OLIVENOTE_DEMO_PASSWORD と照合する。
 *
 * ⚠️ 本番運用には使わないこと。本契約時は provider を 'supabase' に戻す。
 */

function auth_public_actions(): array {
    return ['verifyDemoLogin'];
}

function auth_setup_headers(): void {
    // デモログインは外部 API / FedCM を使わないため特別なヘッダーは不要。
    // auth.php インターフェース上必須なので空関数を定義。
}

/**
 * メアド＋共通パスワードを検証してローカル PHP セッションを確立する。
 *
 * payload: { email: '<メアド>', password: '<共通パスワード>' }
 *   1. OLIVENOTE_DEMO_PASSWORD と password を照合（hash_equals）
 *   2. email が members テーブルに登録済みか確認
 *   3. 両方 OK なら $_SESSION をセットしてログイン成立
 *
 * 失敗時のメッセージは「メアドが無い」「パスワード違い」を区別せず共通化し、
 * 登録済みメアドの有無が外部から推測できないようにする。
 */
function auth_verify_token(array $payload, PDO $pdo): void {
    try {
        if (!defined('OLIVENOTE_DEMO_PASSWORD') || OLIVENOTE_DEMO_PASSWORD === '') {
            echo json_encode(['success' => false, 'error' => 'デモログインのパスワードが設定されていません。管理者にお問い合わせください。']);
            return;
        }

        $email    = strtolower(trim((string)($payload['email'] ?? '')));
        $password = (string)($payload['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'メールアドレスを正しく入力してください']);
            return;
        }

        // 共通パスワード照合。両辺を sha256 でハッシュ化してから hash_equals で
        // 定数時間比較する（入力長による時間差・長さリークも消す）。
        $passwordOk = hash_equals(
            hash('sha256', (string)OLIVENOTE_DEMO_PASSWORD),
            hash('sha256', $password)
        );

        // メンバー登録確認（パスワード可否に関わらず常に問い合わせ、応答時間差を作らない）
        $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $member = $stmt->fetch();

        if (!$passwordOk || !$member) {
            // メアド未登録・パスワード違いを区別しない（情報漏えい防止）
            echo json_encode(['success' => false, 'error' => 'メールアドレスまたはパスワードが違います']);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name']  = $member['name'];
        echo json_encode(['success' => true, 'data' => [
            'email' => $email,
            'name'  => $member['name'],
        ]]);
    } catch (Throwable $e) {
        error_log('[demo-auth] verifyDemoLogin exception: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'サーバーエラーが発生しました']);
    }
}

function auth_render_login_screen(string $appVersion): void {
    $configured = defined('OLIVENOTE_DEMO_PASSWORD') && OLIVENOTE_DEMO_PASSWORD !== '';
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Olive Note - ログイン（デモ）</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="icon" type="image/png" href="favicon.png">
  <!-- ★Tailwind は静的ビルドCSS（パッケージ同梱・自前配信）。Play CDN は撤廃。
       href はブラウザのページ URL（パッケージ root）基準で tailwind.css に解決される。
       filemtime はこのファイル（app/lib/auth/）から 3 階層上＝パッケージ root を指す。 -->
  <link rel="stylesheet" href="tailwind.css?v=<?= @filemtime(__DIR__ . '/../../../tailwind.css') ?: '1' ?>">
</head>
<body class="bg-gradient-to-br from-emerald-50 to-blue-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl p-10 w-full max-w-md text-center">
    <div class="flex items-center justify-center gap-2 mb-2">
      <span class="text-3xl">🌿</span>
      <h1 class="text-3xl font-bold text-gray-800">Olive Note</h1>
    </div>
    <p class="text-gray-500 text-sm mb-2">タスク管理ツール</p>
    <div class="inline-block mb-6 px-2 py-0.5 bg-amber-100 text-amber-700 text-[11px] font-bold rounded">デモ環境</div>

<?php if (!$configured): ?>
    <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg text-left">
      <strong>⚠️ ログイン設定が未完了です</strong><br>
      管理者は config.php に <code>OLIVENOTE_DEMO_PASSWORD</code> を設定してください。
    </div>
<?php else: ?>
    <p class="text-gray-700 text-sm mb-6">配布されたメールアドレスとパスワードでログインしてください。</p>

    <div class="space-y-3 text-left">
      <div>
        <label class="block text-gray-700 text-xs font-bold mb-1">メールアドレス</label>
        <input type="email" id="email-input" placeholder="you@example.com" autocomplete="username"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400" />
      </div>
      <div>
        <label class="block text-gray-700 text-xs font-bold mb-1">パスワード</label>
        <input type="password" id="password-input" placeholder="••••••••" autocomplete="current-password"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400" />
      </div>
      <button id="login-submit" type="button"
        class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm px-4 py-2.5 rounded-lg shadow-sm transition-colors disabled:bg-gray-300 disabled:cursor-not-allowed">
        ログイン
      </button>
    </div>
<?php endif; ?>

    <div id="error-message" class="hidden mt-4 p-3 bg-red-50 text-red-700 text-sm rounded-lg border border-red-200"></div>
    <div id="loading" class="hidden mt-4 text-gray-500 text-sm">ログイン処理中...</div>

    <p class="text-gray-400 text-xs mt-8">
      ※ アクセスには事前のメンバー登録が必要です
    </p>
  </div>

<?php if ($configured): ?>
  <script>
    const errEl   = document.getElementById('error-message');
    const loadEl  = document.getElementById('loading');
    const btn     = document.getElementById('login-submit');
    const emailEl = document.getElementById('email-input');
    const passEl  = document.getElementById('password-input');

    const showError  = (m) => { loadEl.classList.add('hidden'); btn.disabled = false; errEl.textContent = m; errEl.classList.remove('hidden'); };
    const clearError = () => errEl.classList.add('hidden');

    let inFlight = false;
    async function doLogin() {
      if (inFlight) return;
      const email    = emailEl.value.trim();
      const password = passEl.value;
      if (!email || !password) { showError('メールアドレスとパスワードを入力してください'); return; }
      inFlight = true;
      clearError();
      btn.disabled = true;
      loadEl.classList.remove('hidden');
      try {
        const res = await fetch('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'verifyDemoLogin', payload: { email, password } })
        });
        const json = await res.json();
        if (json.success) {
          window.location.reload();
        } else {
          showError(json.error || 'ログインに失敗しました');
          inFlight = false;
        }
      } catch (e) {
        showError('ネットワークエラー: ' + e.message);
        inFlight = false;
      }
    }
    btn.addEventListener('click', doLogin);
    emailEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });
    passEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });
  </script>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}
