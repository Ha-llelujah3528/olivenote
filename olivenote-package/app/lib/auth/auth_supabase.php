<?php
/**
 * lib/auth/auth_supabase.php — Supabase Auth 実装（dist パッケージ版用）
 *
 * 想定: config.php で SUPABASE_URL / SUPABASE_ANON_KEY / SUPABASE_JWT_SECRET が定義済。
 * SUPABASE_PROVIDERS（任意の配列、未定義時は ['email']）で有効プロバイダを切替。
 *
 * フロントは @supabase/supabase-js v2 を CDN から読み込み、
 * Google / Microsoft Azure / Email Magic Link 経由で発行された access_token を
 * `verifySupabaseAuth` action にポストする。
 */

function auth_public_actions(): array {
    return ['verifySupabaseAuth'];
}

function auth_setup_headers(): void {
    // Supabase 認証ではブラウザ FedCM API を使わないため Permissions-Policy は不要。
    // 何もしないが、auth.php インターフェース上必須なので空関数を定義。
}

// ================================================================
// Supabase JWT 検証ヘルパー
//
// Supabase の access_token は HS256 (HMAC-SHA256) で署名されている。
// シークレットは Supabase プロジェクト設定 (Settings > API > JWT Secret)
// から取得して config.php の SUPABASE_JWT_SECRET に格納する。
//
// 戻り値: 検証成功時は payload (配列)、失敗時は null
// ================================================================
function auth_supabase_base64url_decode(string $s): ?string {
    $remainder = strlen($s) % 4;
    if ($remainder) $s .= str_repeat('=', 4 - $remainder);
    $decoded = base64_decode(strtr($s, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function auth_supabase_verify_jwt(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;

    $headerRaw = auth_supabase_base64url_decode($h64);
    if ($headerRaw === null) return null;
    $header = json_decode($headerRaw, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') return null;

    $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
    $actual   = auth_supabase_base64url_decode($s64);
    if ($actual === null || !hash_equals($expected, $actual)) return null;

    $payloadRaw = auth_supabase_base64url_decode($p64);
    if ($payloadRaw === null) return null;
    $jwtPayload = json_decode($payloadRaw, true);
    if (!is_array($jwtPayload)) return null;

    $now = time();
    if (isset($jwtPayload['exp']) && (int)$jwtPayload['exp'] < $now) return null;
    if (isset($jwtPayload['nbf']) && (int)$jwtPayload['nbf'] > $now) return null;

    return $jwtPayload;
}

/**
 * Supabase が発行した access_token (JWT) を検証してローカル PHP セッションを確立する。
 *
 * payload: { accessToken: '<Supabase JWT>' }
 *   1. HS256 署名検証 (SUPABASE_JWT_SECRET)
 *   2. aud == 'authenticated' / exp の検証
 *   3. email を取り出して members テーブルに登録があるか確認
 *   4. あれば $_SESSION にセットしてログイン成立
 *
 * 認証プロバイダ (Google / Microsoft Azure AD / Email Magic Link)
 * は Supabase 側で吸収。Supabase が JWT を発行している時点で
 * email は verified と扱える。
 */
function auth_verify_token(array $payload, PDO $pdo): void {
    try {
        $accessToken = (string)($payload['accessToken'] ?? '');
        if ($accessToken === '') {
            echo json_encode(['success' => false, 'error' => 'アクセストークンが指定されていません']);
            return;
        }
        if (!defined('SUPABASE_JWT_SECRET') || SUPABASE_JWT_SECRET === '') {
            echo json_encode(['success' => false, 'error' => 'Supabase 設定が未完了です。管理者にお問い合わせください。']);
            return;
        }

        $jwtPayload = auth_supabase_verify_jwt($accessToken, SUPABASE_JWT_SECRET);
        if (!$jwtPayload) {
            echo json_encode(['success' => false, 'error' => 'トークンが無効または期限切れです。再ログインしてください。']);
            return;
        }
        // aud は RFC 7519 上 string でも array でも合法。Supabase は通常 string
        // 'authenticated' を返すが将来仕様変更や別認証経路で配列になる可能性に備える
        $aud = $jwtPayload['aud'] ?? null;
        $audOk = is_array($aud) ? in_array('authenticated', $aud, true) : ($aud === 'authenticated');
        if (!$audOk) {
            echo json_encode(['success' => false, 'error' => 'トークンの audience が不正です']);
            return;
        }

        $email = strtolower((string)($jwtPayload['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'メールアドレスが取得できませんでした']);
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $member = $stmt->fetch();
        if (!$member) {
            // メンバー未登録 — レスポンスには email を含めず汎用メッセージにとどめる
            // (ブラウザの DevTools で見ても情報漏えいしないようにする)
            echo json_encode(['success' => false, 'error' => 'このアカウントは Olive Note のメンバーとして登録されていません。管理者にお問い合わせください。']);
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
        error_log('[supabase-auth] verifySupabaseAuth exception: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'サーバーエラーが発生しました']);
    }
}

function auth_render_login_screen(string $appVersion): void {
    // Supabase 設定が揃っているか
    $supabaseConfigured = defined('SUPABASE_URL') && SUPABASE_URL !== '' && SUPABASE_URL !== '__SUPABASE_URL__'
        && defined('SUPABASE_ANON_KEY') && SUPABASE_ANON_KEY !== '' && SUPABASE_ANON_KEY !== '__SUPABASE_ANON_KEY__';

    // 有効な認証プロバイダを確認
    $providers = defined('SUPABASE_PROVIDERS') ? SUPABASE_PROVIDERS : ['email'];
    $hasGoogle = in_array('google', $providers, true);
    $hasMicrosoft = in_array('microsoft', $providers, true);
    $hasEmail = in_array('email', $providers, true);
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Olive Note - ログイン</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="icon" type="image/png" href="favicon.png">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-emerald-50 to-blue-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl p-10 w-full max-w-md text-center">
    <div class="flex items-center justify-center gap-2 mb-2">
      <span class="text-3xl">🌿</span>
      <h1 class="text-3xl font-bold text-gray-800">Olive Note</h1>
    </div>
    <p class="text-gray-500 text-sm mb-8">タスク管理ツール</p>

<?php if (!$supabaseConfigured): ?>
    <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg text-left">
      <strong>⚠️ ログイン設定が未完了です</strong><br>
      管理者は config.php に <code>SUPABASE_URL</code> / <code>SUPABASE_ANON_KEY</code> / <code>SUPABASE_JWT_SECRET</code> を設定してください。
      手順: <code>docs/SUPABASE_SETUP.md</code>
    </div>
<?php else: ?>
    <p class="text-gray-700 text-sm mb-6">
      登録されているアカウントでログインしてください。
    </p>

    <!-- ===== ログイン方式 ===== -->
    <div id="oauth-buttons" class="space-y-2 mb-5">
      <?php if ($hasGoogle): ?>
      <button id="btn-google" class="w-full flex items-center justify-center gap-2 border border-gray-300 hover:bg-gray-50 rounded-lg px-4 py-2.5 text-sm font-bold text-gray-700 transition-colors">
        <svg class="w-5 h-5" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5h-1.9V20H24v8h11.3c-1.6 4.7-6 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 5.1 29.3 3 24 3 12.4 3 3 12.4 3 24s9.4 21 21 21 21-9.4 21-21c0-1.2-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.8 1.2 8 3l5.7-5.7C34 5.1 29.3 3 24 3 16.3 3 9.6 7.6 6.3 14.7z"/><path fill="#4CAF50" d="M24 45c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.1 36.2 26.7 37 24 37c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 40.4 16.2 45 24 45z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.2 4.3-4.1 5.6l6.2 5.2c-.4.4 6.6-4.8 6.6-14.8 0-1.2-.1-2.4-.4-3.5z"/></svg>
        Google でログイン
      </button>
      <?php endif; ?>

      <?php if ($hasMicrosoft): ?>
      <button id="btn-microsoft" class="w-full flex items-center justify-center gap-2 border border-gray-300 hover:bg-gray-50 rounded-lg px-4 py-2.5 text-sm font-bold text-gray-700 transition-colors">
        <svg class="w-4 h-4" viewBox="0 0 23 23"><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M12 1h10v10H12z"/><path fill="#00A4EF" d="M1 12h10v10H1z"/><path fill="#FFB900" d="M12 12h10v10H12z"/></svg>
        Microsoft でログイン
      </button>
      <?php endif; ?>
    </div>

    <?php if (($hasGoogle || $hasMicrosoft) && $hasEmail): ?>
    <div class="my-5 flex items-center gap-3">
      <div class="flex-1 h-px bg-gray-200"></div>
      <span class="text-gray-400 text-xs">または</span>
      <div class="flex-1 h-px bg-gray-200"></div>
    </div>
    <?php endif; ?>

    <!-- ===== Email Magic Link ===== -->
    <?php if ($hasEmail): ?>
    <div id="email-section">
      <div id="email-input-step">
        <label class="block text-left text-gray-700 text-xs font-bold mb-2">📧 メールアドレスでログイン</label>
        <div class="flex gap-2">
          <input type="email" id="email-input" placeholder="you@example.com"
            class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400"
            autocomplete="email" />
          <button id="email-submit" type="button"
            class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm px-4 py-2 rounded-lg shadow-sm transition-colors disabled:bg-gray-300 disabled:cursor-not-allowed"
          >送信</button>
        </div>
        <p class="text-gray-400 text-[11px] text-left mt-2">入力したアドレス宛にワンタイムログインリンクを送信します。</p>
      </div>

      <div id="email-sent-step" class="hidden p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-left">
        <div class="text-emerald-700 text-sm font-bold mb-1">✉️ メールを送信しました</div>
        <div class="text-emerald-700 text-xs">届かない場合は迷惑メールフォルダもご確認ください。<br>または<a href="#" id="email-reset" class="underline">別のアドレスで再送信</a>できます。</div>
      </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

    <div id="error-message" class="hidden mt-4 p-3 bg-red-50 text-red-700 text-sm rounded-lg border border-red-200"></div>
    <div id="loading" class="hidden mt-4 text-gray-500 text-sm">ログイン処理中...</div>

    <p class="text-gray-400 text-xs mt-8">
      ※ アクセスには事前のメンバー登録が必要です
    </p>
  </div>

<?php if ($supabaseConfigured): ?>
  <script type="module">
    import { createClient } from 'https://esm.sh/@supabase/supabase-js@2';

    const supabase = createClient(
      <?= json_encode(SUPABASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      <?= json_encode(SUPABASE_ANON_KEY, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      { auth: { detectSessionInUrl: true, flowType: 'implicit' } }
    );

    const errEl  = document.getElementById('error-message');
    const loadEl = document.getElementById('loading');
    const showError   = (m) => { loadEl.classList.add('hidden'); errEl.textContent = m; errEl.classList.remove('hidden'); };
    const clearError  = () => errEl.classList.add('hidden');
    const showLoading = () => { clearError(); loadEl.classList.remove('hidden'); };

    // 二重実行防止フラグ（onAuthStateChange と初期 getSession のレースを防ぐ）
    let exchangeInFlight = false;

    // PHP セッションに JWT を引き渡してアプリ側ログインを成立させる
    async function exchangeForPhpSession(accessToken) {
      if (exchangeInFlight) return;
      exchangeInFlight = true;
      try {
        const res = await fetch('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'verifySupabaseAuth', payload: { accessToken } })
        });
        const json = await res.json();
        if (json.success) {
          // Supabase 側のセッションは消して PHP セッション基準で運用する
          await supabase.auth.signOut();
          const cleanUrl = window.location.origin + window.location.pathname;
          window.history.replaceState({}, document.title, cleanUrl);
          window.location.replace(cleanUrl);
        } else {
          showError(json.error || 'ログインに失敗しました');
          await supabase.auth.signOut();
          exchangeInFlight = false;  // 再試行可能にする
        }
      } catch (e) {
        showError('ネットワークエラー: ' + e.message);
        exchangeInFlight = false;
      }
    }

    // OAuth リダイレクト戻り or Magic Link クリック後のセッション拾い上げは
    // onAuthStateChange に一本化（getSession で重複実行しない）。
    // detectSessionInUrl=true なので Supabase SDK が URL ハッシュからセッションを
    // 拾い、SIGNED_IN イベントが必ず一度発火する。
    supabase.auth.onAuthStateChange(async (event, session) => {
      if (event === 'SIGNED_IN' && session?.access_token) {
        showLoading();
        await exchangeForPhpSession(session.access_token);
      }
    });

    // 既に確立済みの Supabase セッションが残っている異常系のフォールバック
    // (例: 前回 PHP セッション確立に失敗してリロードした場合)。
    // onAuthStateChange と二重発火しないよう、URL に code/access_token が無い
    // ときだけ既存セッション確認をする。
    (async () => {
      const hasUrlAuth = window.location.hash.includes('access_token')
                      || window.location.search.includes('code=');
      if (hasUrlAuth) return;  // onAuthStateChange に委ねる
      const { data } = await supabase.auth.getSession();
      if (data?.session?.access_token) {
        showLoading();
        await exchangeForPhpSession(data.session.access_token);
      }
    })();

    // ----- Google -----
    const btnGoogle = document.getElementById('btn-google');
    if (btnGoogle) {
      btnGoogle.addEventListener('click', async () => {
        clearError();
        const { error } = await supabase.auth.signInWithOAuth({
          provider: 'google',
          options: { redirectTo: window.location.origin + window.location.pathname }
        });
        if (error) showError(error.message);
      });
    }

    // ----- Microsoft (Azure) -----
    const btnMicrosoft = document.getElementById('btn-microsoft');
    if (btnMicrosoft) {
      btnMicrosoft.addEventListener('click', async () => {
        clearError();
        const { error } = await supabase.auth.signInWithOAuth({
          provider: 'azure',
          options: {
            scopes: 'email openid profile',
            redirectTo: window.location.origin + window.location.pathname
          }
        });
        if (error) showError(error.message);
      });
    }

    // ----- Email Magic Link -----
    const emailInput  = document.getElementById('email-input');
    const emailSubmit = document.getElementById('email-submit');
    if (emailSubmit && emailInput) {
      const emailStep1  = document.getElementById('email-input-step');
      const emailStep2  = document.getElementById('email-sent-step');
      const emailReset  = document.getElementById('email-reset');

      async function sendMagicLink() {
        const email = emailInput.value.trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          showError('有効なメールアドレスを入力してください'); return;
        }
        clearError();
        emailSubmit.disabled = true;
        emailSubmit.textContent = '送信中...';
        const { error } = await supabase.auth.signInWithOtp({
          email,
          options: { emailRedirectTo: window.location.origin + window.location.pathname }
        });
        emailSubmit.disabled = false;
        emailSubmit.textContent = '送信';
        if (error) { showError(error.message); return; }
        emailStep1.classList.add('hidden');
        emailStep2.classList.remove('hidden');
      }
      emailSubmit.addEventListener('click', sendMagicLink);
      emailInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') sendMagicLink(); });
      if (emailReset) {
        emailReset.addEventListener('click', (e) => {
          e.preventDefault();
          emailStep2.classList.add('hidden');
          emailStep1.classList.remove('hidden');
          emailInput.value = '';
          emailInput.focus();
        });
      }
    }
  </script>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}
