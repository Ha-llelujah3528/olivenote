<?php
require_once __DIR__ . '/lib/bootstrap.php';
// アプリのバージョン情報
//   - dist (パッケージ版): bootstrap.php が OLIVENOTE_VERSION 定数を VERSION ファイルから定義済
//   - stg/prd (生サーバー版): 同階層の VERSION ファイルを直接読む
//   - どちらも該当しなければ 'dev'
$appVersion = 'dev';
if (defined('OLIVENOTE_VERSION') && OLIVENOTE_VERSION) {
    $appVersion = OLIVENOTE_VERSION;
} elseif (is_file(__DIR__ . '/VERSION')) {
    $vRaw = @file_get_contents(__DIR__ . '/VERSION');
    if ($vRaw !== false) {
        $vTrim = trim($vRaw);
        if ($vTrim !== '') $appVersion = $vTrim;
    }
}

// Google Identity Services (FedCM) を許可するため Permissions-Policy を明示
header('Permissions-Policy: identity-credentials-get=(self "https://accounts.google.com")');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$isLoggedIn = !empty($_SESSION['user_email']);

// 未ログイン時はログイン画面を出して終了
if (!$isLoggedIn) {
?>
<?php
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
      <?= json_encode(SUPABASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      <?= json_encode(SUPABASE_ANON_KEY, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <base target="_top">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Olive Note</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="icon" type="image/png" href="favicon.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            olive: {
              50:  '#F2F6EE',
              100: '#E8F2DF',
              200: '#B8D4A8',
              300: '#9DB88A',
              500: '#6A9D50',
              600: '#4D7A2D',
              700: '#3D6222',
              800: '#2C4A1C',
              900: '#2A3E1C',
            }
          }
        }
      }
    }
  </script>
  <script type="importmap">
    {
      "imports": {
        "react": "https://esm.sh/react@18.2.0",
        "react-dom/client": "https://esm.sh/react-dom@18.2.0/client",
        "lucide-react": "https://esm.sh/lucide-react@0.292.0?deps=react@18.2.0"
      }
    }
  </script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
  
  <script>
    // GAS版との互換性のため残す（使用しない）
    let INJECTED_DATA = null;
    // フッタのバージョン表示用。dist/stg どちらでも index.php 冒頭で算出される。
    window.APP_VERSION = <?= json_encode($appVersion, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <!-- SheetJS (Excel/CSV パース): AI課題生成モーダルで使用。globalThis.XLSX として展開される -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
      /* ===== タスクモーダル開閉アニメーション（引き出し風） ===== */
      /* 背景: ふわっとフェードイン */
      @keyframes olive-modal-backdrop-in {
        from { opacity: 0; }
        to   { opacity: 1; }
      }
      /* 本体: 少し上から滑り降りつつ縦に伸びる「引き出しを引っ張り出す」イメージ */
      @keyframes olive-modal-drawer-in {
        0%   { opacity: 0; transform: translate3d(0, -28px, 0) scaleY(0.92) scaleX(0.97); }
        60%  { opacity: 1; }
        100% { opacity: 1; transform: translate3d(0, 0, 0)     scaleY(1)    scaleX(1); }
      }
      .olive-modal-backdrop {
        animation: olive-modal-backdrop-in 180ms ease-out both;
      }
      .olive-modal-drawer {
        transform-origin: top center;
        animation: olive-modal-drawer-in 320ms cubic-bezier(0.16, 1, 0.3, 1) both;
        will-change: transform, opacity;
      }
      /* アクセシビリティ: モーション低減設定時はアニメ無効 */
      @media (prefers-reduced-motion: reduce) {
        .olive-modal-backdrop,
        .olive-modal-drawer { animation: none !important; }
      }

      /* Tailwind環境でMarkdownの見た目を綺麗にするための追加スタイル */
      .markdown-body h1 { font-size: 1.5em; font-weight: bold; border-bottom: 1px solid #eaecef; margin-top: 24px; padding-bottom: 8px; }
      .markdown-body h2 { font-size: 1.25em; font-weight: bold; border-bottom: 1px solid #eaecef; margin-top: 20px; padding-bottom: 6px; }
      .markdown-body h3 { font-size: 1.1em; font-weight: bold; margin-top: 16px; }
      .markdown-body ul { list-style-type: disc; margin-left: 1.5em; margin-bottom: 16px; }
      .markdown-body ol { list-style-type: decimal; margin-left: 1.5em; margin-bottom: 16px; }
      .markdown-body table {
        display: block;          /* スマホで横スクロール可能に */
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        border-collapse: collapse;
        margin-bottom: 16px;
        font-size: 14px;
        white-space: nowrap;     /* セル内テキストを横一行で */
        -webkit-overflow-scrolling: touch;
      }
      .markdown-body th, .markdown-body td { border: 1px solid #d1d5db; padding: 6px 10px; }
      .markdown-body th { background-color: #f3f4f6; font-weight: bold; }
      .markdown-body img { max-width: 100%; height: auto; border-radius: 4px; }
      .markdown-body input[type="checkbox"] { margin-right: 6px; }
      .markdown-body a {
        color: #2563eb;
        text-decoration: underline;
        word-break: break-all;       /* 長いURLでも折り返す */
        overflow-wrap: anywhere;
      }
      /* マークダウン本文も長い英数字列で折り返す（コメント内URL等） */
      .markdown-body { word-break: break-word; overflow-wrap: anywhere; }
      .markdown-body pre { overflow-x: auto; max-width: 100%; }
      .markdown-body code { word-break: break-all; }

      /* ===== OliveNote カラーリニューアル: 入力フィールド視認性向上 ===== */
      input:not([type=checkbox]):not([type=radio]),
      textarea,
      select {
        background-color: #ffffff;
        border-color: #9DB88A;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
      }
      input:not([type=checkbox]):not([type=radio]):focus,
      textarea:focus,
      select:focus {
        border-color: #4D7A2D !important;
        box-shadow: 0 0 0 3px rgba(77,122,45,0.2) !important;
        outline: none !important;
      }
      /* Tailwind の ring ユーティリティを olive に上書き */
      .focus\:ring-blue-500:focus,
      .focus\:ring-2:focus {
        --tw-ring-color: rgba(77,122,45,0.35);
      }

      /* ===== Classic Theme (旧デザイン: blue/indigo + navy header) ===== */
      /* data-theme は早期スクリプトで <html> に設定される */
      [data-theme="classic"] body { background-color: #f0f2f5 !important; }
      [data-theme="classic"] .bg-olive-50    { background-color: #eff6ff !important; }
      [data-theme="classic"] .bg-olive-100   { background-color: #dbeafe !important; }
      [data-theme="classic"] .bg-olive-300   { background-color: #93c5fd !important; }
      [data-theme="classic"] .bg-olive-500   { background-color: #3b82f6 !important; }
      [data-theme="classic"] .bg-olive-600   { background-color: #2563eb !important; }
      [data-theme="classic"] .bg-olive-700   { background-color: #2c3e50 !important; }
      [data-theme="classic"] .bg-olive-800   { background-color: #1e2a35 !important; }
      [data-theme="classic"] .border-olive-200 { border-color: #bfdbfe !important; }
      [data-theme="classic"] .border-olive-300 { border-color: #93c5fd !important; }
      [data-theme="classic"] .border-olive-400 { border-color: #60a5fa !important; }
      [data-theme="classic"] .border-olive-500 { border-color: #3b82f6 !important; }
      [data-theme="classic"] .border-olive-600 { border-color: #2563eb !important; }
      [data-theme="classic"] .border-olive-800 { border-color: #1e2a35 !important; }
      [data-theme="classic"] .text-olive-200 { color: #bfdbfe !important; }
      [data-theme="classic"] .text-olive-500 { color: #3b82f6 !important; }
      [data-theme="classic"] .text-olive-600 { color: #2563eb !important; }
      [data-theme="classic"] .text-olive-700 { color: #1d4ed8 !important; }
      [data-theme="classic"] .text-olive-800 { color: #1e40af !important; }
      [data-theme="classic"] .text-olive-900 { color: #374151 !important; }
      [data-theme="classic"] .hover\:bg-olive-50:hover   { background-color: #eff6ff !important; }
      [data-theme="classic"] .hover\:bg-olive-100:hover  { background-color: #dbeafe !important; }
      [data-theme="classic"] .hover\:bg-olive-500:hover  { background-color: #3b82f6 !important; }
      [data-theme="classic"] .hover\:bg-olive-600:hover  { background-color: #2563eb !important; }
      [data-theme="classic"] .hover\:bg-olive-700:hover  { background-color: #1d4ed8 !important; }
      [data-theme="classic"] .hover\:bg-olive-800:hover  { background-color: #1e3a8a !important; }
      [data-theme="classic"] .hover\:text-olive-600:hover { color: #2563eb !important; }
      [data-theme="classic"] .hover\:text-olive-800:hover { color: #1e40af !important; }
      [data-theme="classic"] .hover\:border-olive-300:hover,
      [data-theme="classic"] .hover\:border-olive-400:hover { border-color: #93c5fd !important; }
      /* Classic: 入力フィールドは元のニュートラルなグレーに戻す */
      [data-theme="classic"] input:not([type=checkbox]):not([type=radio]),
      [data-theme="classic"] textarea,
      [data-theme="classic"] select {
        border-color: #d1d5db !important;
      }
      [data-theme="classic"] input:not([type=checkbox]):not([type=radio]):focus,
      [data-theme="classic"] textarea:focus,
      [data-theme="classic"] select:focus {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.2) !important;
      }
      [data-theme="classic"] .focus\:ring-olive-400:focus,
      [data-theme="classic"] .focus\:ring-olive-500:focus,
      [data-theme="classic"] .focus\:ring-olive-600:focus {
        --tw-ring-color: rgba(59,130,246,0.35) !important;
      }
      /* Classic: 静的 ring も blue 系へ */
      [data-theme="classic"] .ring-olive-300 { --tw-ring-color: #93c5fd !important; }
      [data-theme="classic"] .ring-olive-400 { --tw-ring-color: #60a5fa !important; }
      [data-theme="classic"] .ring-olive-500 { --tw-ring-color: #3b82f6 !important; }
      [data-theme="classic"] .ring-olive-600 { --tw-ring-color: #2563eb !important; }
      /* Classic: SVG fill / 部分ボーダーも blue 系へ */
      [data-theme="classic"] .fill-olive-600 { fill: #2563eb !important; }
      [data-theme="classic"] .border-t-olive-500 { border-top-color: #3b82f6 !important; }

      /* ===== Olive コンシェルジュ FAB（テーマ追随） ===== */
      .olive-concierge-fab {
        background: linear-gradient(to right, #3D6222, #6A9D50);
        box-shadow: 0 4px 14px 0 rgba(77,122,45,0.4);
      }
      .olive-concierge-fab:hover {
        box-shadow: 0 6px 20px rgba(77,122,45,0.3);
      }
      [data-theme="classic"] .olive-concierge-fab {
        background: linear-gradient(to right, #2563eb, #4f46e5) !important;
        box-shadow: 0 4px 14px 0 rgba(37,99,235,0.39) !important;
      }
      [data-theme="classic"] .olive-concierge-fab:hover {
        box-shadow: 0 6px 20px rgba(37,99,235,0.23) !important;
      }

      /* ===== Classic: レスポンシブ修飾子の取りこぼし対策 ===== */
      @media (min-width: 768px) {
        [data-theme="classic"] .md\:bg-olive-50  { background-color: #eff6ff !important; }
        [data-theme="classic"] .md\:bg-olive-100 { background-color: #dbeafe !important; }
      }
      /* group-hover / active / focus-within の olive 系も blue へ */
      [data-theme="classic"] .group:hover .group-hover\:bg-olive-50,
      [data-theme="classic"] .group:hover .group-hover\:bg-olive-100 { background-color: #eff6ff !important; }
      [data-theme="classic"] .group:hover .group-hover\:text-olive-600 { color: #2563eb !important; }
      [data-theme="classic"] .active\:bg-olive-100:active { background-color: #dbeafe !important; }
      [data-theme="classic"] .focus\:border-olive-400:focus,
      [data-theme="classic"] .focus\:border-olive-600:focus { border-color: #3b82f6 !important; }
      [data-theme="classic"] .focus-within\:border-olive-600:focus-within { border-color: #3b82f6 !important; }
      [data-theme="classic"] .focus-within\:ring-olive-400:focus-within { --tw-ring-color: rgba(59,130,246,0.35) !important; }
      [data-theme="classic"] .hover\:text-olive-500:hover { color: #3b82f6 !important; }
      [data-theme="classic"] .hover\:border-olive-200:hover { border-color: #bfdbfe !important; }
    </style>
    <script>
      // テーマを描画前に適用してフラッシュを防ぐ
      (function() {
        try {
          var t = localStorage.getItem('olivenote_theme');
          if (t && t !== 'olive') {
            document.documentElement.setAttribute('data-theme', t);
          }
        } catch (e) {}
      })();
    </script>
</head>
<body class="bg-olive-50 m-0 p-0 overflow-hidden h-screen">
  
  <div id="root" class="h-full"></div>

  <script type="text/babel" data-type="module">
    import React, { useState, useMemo, useEffect, useRef } from 'react';
    import { createRoot } from 'react-dom/client';
    import {
      Columns, CalendarDays, Plus, X, MessageSquare, AlignLeft, Calendar as CalendarIcon,
      Search, CheckSquare, Flame, ThumbsUp, Settings, ChevronUp, ChevronDown, Trash2, Edit,
      Eye, Link as LinkIcon, Filter, CornerDownRight, AlertTriangle, Paperclip, Download, Loader2,
      FileText, ExternalLink, FilePlus, SaveAll, Tag, Copy,
      List, ListOrdered, Grid, Image as ImageIcon, Bell, Star, Sparkles, Wand2, Save, MessageCircle, Send, Bot,
      CheckCircle, XCircle, LogOut, RefreshCw, Folder, FolderPlus, Home, ChevronRight, Printer,
      UploadCloud, Check, Edit3, Table, Palette
    } from 'lucide-react';

    // ===== テーマ定義（将来追加可能） =====
    const THEMES = [
      { id: 'olive',   label: 'オリーブ（新）', previewColor: '#4D7A2D', desc: '深緑＋クリーム' },
      { id: 'classic', label: 'クラシック',     previewColor: '#2563eb', desc: '青＋ネイビーヘッダー' }
    ];

    const STATUSES = [
      { id: 'todo', label: '未対応', bgColor: 'bg-gray-200', borderColor: 'border-gray-300', textColor: 'text-gray-700', barColor: 'bg-gray-400' },
      { id: 'in-progress', label: '処理中', bgColor: 'bg-blue-100', borderColor: 'border-blue-200', textColor: 'text-blue-800', barColor: 'bg-blue-500' },
      { id: 'waiting', label: '先方連絡待ち', bgColor: 'bg-purple-100', borderColor: 'border-purple-200', textColor: 'text-purple-800', barColor: 'bg-purple-400' },
      { id: 'review', label: 'レビュー', bgColor: 'bg-yellow-100', borderColor: 'border-yellow-200', textColor: 'text-yellow-800', barColor: 'bg-yellow-400' },
      { id: 'done', label: '完了', bgColor: 'bg-gray-300', borderColor: 'border-gray-400', textColor: 'text-gray-600', barColor: 'bg-gray-500' }
    ];
    const PRIORITIES = [
      { id: 'low', label: '低', textColor: 'text-blue-700', bgColor: 'bg-blue-50', borderColor: 'border-blue-200' },
      { id: 'medium', label: '中', textColor: 'text-yellow-700', bgColor: 'bg-yellow-50', borderColor: 'border-yellow-200' },
      { id: 'high', label: '高', textColor: 'text-red-700', bgColor: 'bg-red-50', borderColor: 'border-red-200' }
    ];
    
    const CATEGORY_COLORS = [
      { bg: 'bg-red-100', text: 'text-red-800', border: 'border-red-200' },
      { bg: 'bg-orange-100', text: 'text-orange-800', border: 'border-orange-200' },
      { bg: 'bg-yellow-100', text: 'text-yellow-800', border: 'border-yellow-200' },
      { bg: 'bg-green-100', text: 'text-green-800', border: 'border-green-200' },
      { bg: 'bg-teal-100', text: 'text-teal-800', border: 'border-teal-200' },
      { bg: 'bg-cyan-100', text: 'text-cyan-800', border: 'border-cyan-200' },
      { bg: 'bg-blue-100', text: 'text-blue-800', border: 'border-blue-200' },
      { bg: 'bg-indigo-100', text: 'text-indigo-800', border: 'border-indigo-200' },
      { bg: 'bg-purple-100', text: 'text-purple-800', border: 'border-purple-200' },
      { bg: 'bg-pink-100', text: 'text-pink-800', border: 'border-pink-200' }
    ];
    const DEFAULT_CAT_COLOR = { bg: 'bg-gray-100', text: 'text-gray-800', border: 'border-gray-200' };
    const AVATARS = ['👤', '🐶', '🐱', '🦊', '🐻', '🐼', '🐰', '🐯', '🐸', '🐷', '🦄', '🤖', '👻', '👾', '🚀'];
    const getTodayStr = () => new Date().toLocaleDateString('sv-SE');
    const DAYS_OF_WEEK = ['日', '月', '火', '水', '木', '金', '土'];

    // api.php と通信する共通関数（レスポンスの json.data を返す）
    const callApi = async (action, payload = {}) => {
      const res  = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, payload })
      });
      // セッション切れ → ログイン画面へリダイレクト
      if (res.status === 401) {
        window.location.reload();
        throw new Error('Session expired');
      }
      const json = await res.json();
      if (!json.success) throw new Error(json.error || 'API Error');
      return json.data;
    };

    // React コンポーネントが使用する API オブジェクト
    const api = {
      // ---- 認証 ----
      logout:                ()                                        => callApi('logout'),

      // ---- コアデータ ----
      getInitialData:        ()                                        => callApi('getInitialData'),
      saveTask:              (taskData)                                => callApi('saveTask', { task: taskData }),
      deleteTask:            (taskId)                                  => callApi('deleteTask', { taskId }),
      saveSettings:          (settingsData)                            => callApi('saveSettings', settingsData),

      // ---- コメント ----
      saveComment:           (commentData)                             => callApi('saveComment', { comment: commentData }),
      deleteComment:         (commentId)                               => callApi('deleteComment', { commentId }),
      markCommentsAsRead:    (commentIds, email)                       => callApi('markCommentsAsRead', { commentIds, email }),

      // ---- ゴミ箱 ----
      getDeletedTasks:       ()                                        => callApi('getDeletedTasks'),
      restoreTask:           (taskId)                                  => callApi('restoreTask', { taskId }),

      // ---- 通知 ----
      getMyNotifications:    (email)                                   => callApi('getMyNotifications', { email }),
      markNotificationAsRead:(notificationId)                          => callApi('markNotificationAsRead', { notificationId }),
      createNotification:    (targetEmail, senderName, taskId, taskTitle, message) =>
                               callApi('createNotification', { targetEmail, senderName, taskId, taskTitle, message }),

      // ---- Google Drive ----
      uploadFile:                 (fileData)         => callApi('uploadFile', fileData),
      createDocument:             (title, parentId)         => callApi('createDocument', { title, parentId }),
      createFolder:               (title, parentId)         => callApi('createFolder',   { title, parentId }),
      moveDocument:               (fileId, newParentId)     => callApi('moveDocument',   { fileId, newParentId }),
      deleteDocument:             (fileId)                  => callApi('deleteDocument', { fileId }),
      syncDocumentsFromDrive:     ()                        => callApi('syncDocumentsFromDrive'),

      // ---- AI仕様書（systemSpecForAI）取得 ----
      getSystemSpecForAI:          ()                            => callApi('getSystemSpecForAI'),

      // ---- AI ----
      gatherAiInformation:         (payload)                    => callApi('gatherAiInformation', payload),
      chatWithOliveAI:             (mode, history, taskContext, tasksContext = null) => callApi('chatWithOliveAI', { mode, history, taskContext, tasksContext }),
      generateTasksFromContext:    (payload)                    => callApi('generateTasksFromContext', payload),
      generateDocumentFromComment: (payload)                    => callApi('generateDocumentFromComment', payload),
      generateAndAppendReleaseNote:(payload)                    => callApi('generateAndAppendReleaseNote', payload),
      generateAdvisorDoc:          (task, history, formatPrompt) => callApi('generateAdvisorDoc', { task, history, formatPrompt }),
      generateImage:               (task, prompt, aspectRatio)   => callApi('generateImage',      { task, prompt, aspectRatio }),
    };

    const fileToBase64 = (file) => new Promise((resolve, reject) => {
      const reader = new FileReader(); reader.readAsDataURL(file);
      reader.onload = () => resolve({ name: file.name, mimeType: file.type, data: reader.result.split(',')[1] });
      reader.onerror = error => reject(error);
    });

    <?php readfile(__DIR__ . '/App.html'); ?>
    <?php readfile(__DIR__ . '/BoardView.html'); ?>
    <?php readfile(__DIR__ . '/TaskModal.html'); ?>
    <?php readfile(__DIR__ . '/TaskAutoGenerateModal.html'); ?>
    <?php readfile(__DIR__ . '/GanttView.html'); ?>
    <?php readfile(__DIR__ . '/CalendarView.html'); ?>
    <?php readfile(__DIR__ . '/DocsView.html'); ?>
    <?php readfile(__DIR__ . '/SettingsView.html'); ?>
    <?php readfile(__DIR__ . '/MarkdownPreview.html'); ?>
    const root = createRoot(document.getElementById('root'));
    root.render(<App />);
  </script>
</body>
</html>