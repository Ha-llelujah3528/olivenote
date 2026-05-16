<?php
/**
 * Olive Note - アップデーター UI
 *
 * /admin/updater_ui.php （ルートから見ると app/admin/updater_ui.php）
 * このページは管理者のみアクセス可能
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Olive Note - システムアップデート</title>
<style>
  body { font-family: 'Hiragino Sans', 'Noto Sans JP', sans-serif; background: #f0f2f5; margin: 0; padding: 24px; color: #1f2937; }
  .container { max-width: 720px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
  h1 { color: #0f766e; margin-top: 0; border-bottom: 2px solid #ccfbf1; padding-bottom: 12px; }
  .version-box { display: flex; gap: 24px; padding: 20px; background: #f9fafb; border-radius: 10px; margin: 20px 0; }
  .version-box .col { flex: 1; }
  .version-box .label { font-size: 11px; color: #6b7280; font-weight: bold; text-transform: uppercase; }
  .version-box .value { font-size: 24px; font-weight: bold; color: #1f2937; }
  .version-box .badge { display: inline-block; background: #f59e0b; color: white; font-size: 11px; font-weight: bold; padding: 2px 8px; border-radius: 99px; margin-left: 8px; vertical-align: middle; }
  .changelog { background: #f9fafb; padding: 16px 20px; border-radius: 8px; border-left: 4px solid #10b981; font-size: 13px; line-height: 1.7; white-space: pre-wrap; }
  .btn { padding: 14px 28px; border-radius: 8px; font-weight: bold; font-size: 15px; cursor: pointer; border: none; }
  .btn-primary { background: linear-gradient(135deg, #10b981, #059669); color: white; }
  .btn-primary:hover { background: linear-gradient(135deg, #059669, #047857); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn-secondary { background: #e5e7eb; color: #374151; }
  pre.log { background: #1f2937; color: #d1fae5; padding: 16px; border-radius: 8px; font-size: 12px; line-height: 1.7; max-height: 400px; overflow-y: auto; margin-top: 20px; font-family: 'Consolas', monospace; }
  .alert { padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-size: 14px; }
  .alert.info  { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  .alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  a.back { color: #2563eb; text-decoration: none; font-size: 13px; }
</style>
</head>
<body>
<div class="container">
  <h1>⚙️ システムアップデート</h1>
  <p><a class="back" href="../../">← トップへ戻る</a></p>

  <div id="status">
    <div class="alert info">最新版情報を取得中...</div>
  </div>

  <div id="action" style="margin-top: 20px;"></div>
  <pre class="log" id="log" style="display:none"></pre>
</div>

<script>
async function check() {
  const res = await fetch('updater_api.php?action=check');
  const json = await res.json();
  const statusEl = document.getElementById('status');
  const actionEl = document.getElementById('action');

  if (!json.success) {
    statusEl.innerHTML = '<div class="alert error">❌ ' + (json.error || '不明なエラー') + '</div>';
    return;
  }

  const { current, latest, updateAvailable, changelog } = json.data;

  let html = '<div class="version-box">';
  html += '<div class="col"><div class="label">現在のバージョン</div><div class="value">v' + current + '</div></div>';
  html += '<div class="col"><div class="label">最新バージョン</div><div class="value">v' + latest;
  if (updateAvailable) html += ' <span class="badge">UPDATE</span>';
  html += '</div></div></div>';
  statusEl.innerHTML = html;

  if (changelog) {
    statusEl.innerHTML += '<h3>📝 変更内容</h3><div class="changelog">' + escapeHtml(changelog) + '</div>';
  }

  if (updateAvailable) {
    actionEl.innerHTML = '<button class="btn btn-primary" onclick="runUpdate()">アップデートを実行</button>';
  } else {
    actionEl.innerHTML = '<div class="alert success">✅ 既に最新版です</div>';
  }
}

async function runUpdate() {
  if (!confirm('アップデートを実行しますか？\n\n所要時間: 1〜3分程度\nアップデート中はユーザーがアプリを使えなくなります。')) return;

  document.getElementById('action').innerHTML = '<div class="alert info">⏳ アップデート実行中...画面を閉じないでください。</div>';
  const logEl = document.getElementById('log');
  logEl.style.display = 'block';
  logEl.textContent = '🚀 アップデートを開始しています...\n';

  try {
    const res = await fetch('updater_api.php?action=run', { method: 'POST' });
    const text = await res.text();
    // レスポンスは JSON だが念のため
    let json;
    try { json = JSON.parse(text); } catch (_) { logEl.textContent += text; return; }

    if (json.log) logEl.textContent = json.log.join('\n');

    if (json.success) {
      document.getElementById('action').innerHTML =
        '<div class="alert success">✅ アップデート完了！ページを再読み込みします...</div>';
      setTimeout(() => location.reload(), 2500);
    } else {
      document.getElementById('action').innerHTML =
        '<div class="alert error">❌ アップデートに失敗しました: ' + escapeHtml(json.error || '') + '</div>' +
        '<p style="font-size:12px;color:#6b7280;">バックアップから自動でロールバックを試みました。data/backups/ に手動復旧用のバックアップがあります。</p>';
    }
  } catch (e) {
    document.getElementById('action').innerHTML =
      '<div class="alert error">❌ 通信エラー: ' + e.message + '</div>';
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

check();
</script>
</body>
</html>
