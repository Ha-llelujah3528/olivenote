<?php
/**
 * Olive Note - LINE WORKS 接続設定（管理者のみ）
 *
 * /admin/lineworks_settings.php （= app/admin/lineworks_settings.php）
 *
 * パッケージ版で、インストール後に config/config.php の LINE WORKS 定数を
 * 画面から書き換えるための「臨時の設定画面」。
 *   - api.php 側は定数（LINEWORKS_*）をそのまま読むため無改造。
 *   - この画面が config/config.php の define('LINEWORKS_*', ...) 行を書き換える。
 *   - 値は web 非公開域の config/config.php に保存される（DBには入れない）。
 *
 * 設定手順は ../docs/view.php?doc=LINEWORKS_SETUP.md を参照。
 */
require_once __DIR__ . '/../lib/bootstrap.php';

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// ログイン確認
if (empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo '<p>ログインが必要です。<a href="../">トップへ戻る</a></p>';
    exit;
}
// 管理者確認
$stmt = olivenote_db()->prepare("SELECT is_admin FROM members WHERE email = ?");
$stmt->execute([$_SESSION['user_email']]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo '<p>このページは管理者のみアクセス可能です。<a href="../">トップへ戻る</a></p>';
    exit;
}

// 設定項目の定義（キー => [ラベル, 複数行か, 補足]）
$FIELDS = [
    'LINEWORKS_BOT_ID'          => ['Bot No.',         false, 'Developer Console → Bot 詳細の数字'],
    'LINEWORKS_CLIENT_ID'       => ['Client ID',       false, 'アプリ(App)の Client ID'],
    'LINEWORKS_CLIENT_SECRET'   => ['Client Secret',   false, 'アプリ(App)の Client Secret'],
    'LINEWORKS_SERVICE_ACCOUNT' => ['Service Account', false, '例: xxxx.serviceaccount@ドメイン'],
    'LINEWORKS_PRIVATE_KEY'     => ['Private Key',      true,  'アプリの Private Key（PEM）。-----BEGIN〜END----- をそのまま貼り付け'],
    'LINEWORKS_BOT_SECRET'      => ['Bot Secret',       false, 'Bot 詳細の Bot Secret（受信の署名検証用）'],
    'LINEWORKS_CRON_TOKEN'      => ['Cron Token',       false, '定期通知URLの保護用。任意のランダム文字列'],
];

$configFile = dirname(__DIR__, 2) . '/config/config.php'; // app/admin → package root → config/config.php

// config.php から現在値を読む（定数ではなくファイルから直接。保存直後も最新を表示するため）
$cfgRaw = is_readable($configFile) ? (string)file_get_contents($configFile) : '';
function lw_extract_value(string $cfg, string $key): string {
    $pattern = "/define\\(\\s*'" . preg_quote($key, '/') . "'\\s*,\\s*'((?:\\\\.|[^'\\\\])*)'\\s*\\)\\s*;/s";
    if (preg_match($pattern, $cfg, $m)) {
        // 単一引用符文字列のアンエスケープ（\\ と \' のみ）
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $m[1]);
    }
    return '';
}

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_file($configFile)) {
        $error = 'config/config.php が見つかりません。先にインストーラでセットアップを完了してください。';
    } elseif (!is_writable($configFile)) {
        $error = 'config/config.php が書き込み不可です。サーバーでファイルのパーミッション（書き込み権限）を確認してください。';
    } else {
        $cfg = (string)file_get_contents($configFile);
        foreach ($FIELDS as $key => $_meta) {
            $val = (string)($_POST[$key] ?? '');
            $val = str_replace(["\r\n", "\r"], "\n", $val);          // 改行正規化（PEM 用）
            $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $val); // PHP 単一引用符向けエスケープ（実改行は保持）
            $newLine = "define('{$key}', '{$escaped}');";
            $pattern = "/define\\(\\s*'" . preg_quote($key, '/') . "'\\s*,\\s*'(?:\\\\.|[^'\\\\])*'\\s*\\)\\s*;/s";
            if (preg_match($pattern, $cfg)) {
                // 置換文字列の $ / \ 解釈を避けるためコールバックで差し込む
                $cfg = preg_replace_callback($pattern, function () use ($newLine) { return $newLine; }, $cfg, 1);
            } else {
                // 旧 config（LINE WORKS 行が無い）には末尾に追記
                $cfg = rtrim($cfg, "\r\n") . "\n" . $newLine . "\n";
            }
        }
        if (file_put_contents($configFile, $cfg, LOCK_EX) === false) {
            $error = 'config/config.php への書き込みに失敗しました。';
        } else {
            // PRG: 保存後はリダイレクトして再送信を防ぐ
            header('Location: lineworks_settings.php?saved=1');
            exit;
        }
    }
    // エラー時はそのまま下に再描画（入力値は $_POST を優先表示）
}

if (isset($_GET['saved'])) $saved = true;

// 表示用の現在値（保存直後はファイル＝最新を反映）
$current = [];
foreach ($FIELDS as $key => $_meta) {
    $current[$key] = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '')
        ? (string)($_POST[$key] ?? '')
        : lw_extract_value($cfgRaw, $key);
}
$enabled = trim($current['LINEWORKS_BOT_ID']) !== '' && !preg_match('/^__[A-Za-z0-9_]+__$/', trim($current['LINEWORKS_BOT_ID']));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Olive Note - LINE WORKS 接続設定</title>
<style>
  body { font-family: 'Hiragino Sans', 'Noto Sans JP', sans-serif; background: #f0f2f5; margin: 0; padding: 24px; color: #1f2937; }
  .container { max-width: 720px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
  h1 { color: #0f766e; margin-top: 0; border-bottom: 2px solid #ccfbf1; padding-bottom: 12px; font-size: 22px; }
  label.field { display: block; margin: 18px 0 4px; font-weight: bold; font-size: 14px; }
  .hint { font-size: 12px; color: #6b7280; font-weight: normal; margin-left: 6px; }
  input[type=text], textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 12px; font-size: 14px; font-family: 'Consolas', monospace; }
  textarea { min-height: 140px; resize: vertical; }
  .btn { padding: 13px 28px; border-radius: 8px; font-weight: bold; font-size: 15px; cursor: pointer; border: none; }
  .btn-primary { background: linear-gradient(135deg, #10b981, #059669); color: white; }
  .btn-primary:hover { background: linear-gradient(135deg, #059669, #047857); }
  .alert { padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-size: 14px; }
  .alert.info  { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  .alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  .badge { display:inline-block; font-size:11px; font-weight:bold; padding:2px 10px; border-radius:99px; vertical-align:middle; margin-left:8px; }
  .badge.on  { background:#dcfce7; color:#166534; }
  .badge.off { background:#f3f4f6; color:#6b7280; }
  a.back { color: #2563eb; text-decoration: none; font-size: 13px; }
  details { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; margin:16px 0; font-size:13px; }
  summary { cursor:pointer; font-weight:bold; color:#0f766e; }
  details ol { line-height:1.8; }
</style>
</head>
<body>
<div class="container">
  <h1>🟢 LINE WORKS 接続設定
    <span class="badge <?= $enabled ? 'on' : 'off' ?>"><?= $enabled ? '有効' : '未設定' ?></span>
  </h1>
  <p><a class="back" href="../../">← トップへ戻る</a></p>

  <?php if ($saved): ?>
    <div class="alert success">✅ 保存しました。LINE WORKS 連携の設定を更新しました。</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert error">❌ <?= h($error) ?></div>
  <?php endif; ?>

  <div class="alert info">
    LINE WORKS との連携設定です。各値の取得方法は
    <a href="../../docs/view.php?doc=LINEWORKS_SETUP.md" target="_blank">LINE WORKS セットアップ手順書</a> を参照してください。
    <strong>Bot No.</strong> を空のままにすると連携は無効になります。
  </div>

  <details>
    <summary>かんたん手順（クリックで展開）</summary>
    <ol>
      <li>LINE WORKS Developer Console で <strong>アプリ(App)</strong> を作成 → Client ID / Secret / Service Account / Private Key を取得。OAuth Scope に <code>bot</code> <code>bot.message</code> <code>user.read</code> を付与。</li>
      <li><strong>Bot</strong> を作成 → Bot No. / Bot Secret を取得。Callback URL に <code>(このサイト)/app/api.php?lw=callback</code> を登録、イベントは「メッセージ」。</li>
      <li>管理者画面で Bot を公開し、メンバーに追加。</li>
      <li>下のフォームに値を入力して保存。</li>
      <li>各メンバーの設定（OliveNote → 設定 → メンバー管理）で <strong>LINE WORKS ID</strong> を登録。</li>
      <li>定期通知（期限・週次）を使う場合は、サーバーの Cron で毎朝 <code>(このサイト)/app/api.php?lw=cron&token=(Cron Token)</code> を叩く。</li>
    </ol>
    詳細は <a href="../../docs/view.php?doc=LINEWORKS_SETUP.md" target="_blank">セットアップ手順書</a> を参照。
  </details>

  <form method="post" action="lineworks_settings.php">
    <?php foreach ($FIELDS as $key => $meta): [$label, $multiline, $hintTxt] = $meta; ?>
      <label class="field" for="<?= h($key) ?>"><?= h($label) ?><span class="hint"><?= h($hintTxt) ?></span></label>
      <?php if ($multiline): ?>
        <textarea id="<?= h($key) ?>" name="<?= h($key) ?>" spellcheck="false"><?= h($current[$key]) ?></textarea>
      <?php else: ?>
        <input type="text" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($current[$key]) ?>" autocomplete="off" spellcheck="false">
      <?php endif; ?>
    <?php endforeach; ?>

    <div style="margin-top:24px;">
      <button type="submit" class="btn btn-primary">保存する</button>
    </div>
  </form>
</div>
</body>
</html>
