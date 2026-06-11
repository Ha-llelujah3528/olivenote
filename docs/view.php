<?php
// ============================================================
// Olive Note ドキュメントビューア
//
// 同階層の .md ファイルを marked.js でレンダリングする。
// ?doc=OAUTH_SETUP.md のようにクエリで指定。
// ホワイトリスト方式でパストラバーサルを防止。
// ============================================================

$doc = isset($_GET['doc']) ? (string)$_GET['doc'] : '';

// 公開してよいドキュメント一覧（ファイル名のみ。サブディレクトリ不可）
$allowed = [
    'SUPABASE_SETUP.md',
    'OAUTH_SETUP.md',
    'DRIVE_SETUP.md',
    'VERTEX_SETUP.md',
    'INSTALL_GUIDE.md',
    'UPDATE_GUIDE.md',
    'CLIENT_DEPLOYMENT.md',
    'LINEWORKS_SETUP.md',
];

// ドキュメント名が指定されていない場合は一覧を表示
if ($doc === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Olive Note ドキュメント</title>';
    echo '<body style="font-family:sans-serif;max-width:600px;margin:2em auto;padding:0 1em">';
    echo '<h1>📚 Olive Note ドキュメント</h1><ul>';
    foreach ($allowed as $name) {
        $path = __DIR__ . '/' . $name;
        if (is_file($path)) {
            $label = htmlspecialchars(preg_replace('/\.md$/', '', $name), ENT_QUOTES, 'UTF-8');
            $href  = 'view.php?doc=' . urlencode($name);
            echo "<li><a href=\"{$href}\">{$label}</a></li>";
        }
    }
    echo '</ul></body>';
    exit;
}

if (!in_array($doc, $allowed, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found: 指定されたドキュメントは公開対象外です。\n";
    echo "利用可能: " . implode(', ', $allowed) . "\n";
    exit;
}

$path = __DIR__ . '/' . $doc;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found: ファイルが見つかりません ({$doc})\n";
    exit;
}

$markdown = (string)file_get_contents($path);
$titleRaw = preg_replace('/\.md$/', '', $doc);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8') ?> - Olive Note ドキュメント</title>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif; max-width: 900px; margin: 0 auto; padding: 2em 1.2em; line-height: 1.75; color: #1f2937; background: #fafafa; }
  h1, h2, h3, h4 { color: #065f46; line-height: 1.4; }
  h1 { border-bottom: 2px solid #10b981; padding-bottom: .3em; margin-top: 0; }
  h2 { border-bottom: 1px solid #d1d5db; padding-bottom: .2em; margin-top: 2.2em; }
  h3 { margin-top: 1.5em; }
  a { color: #0369a1; }
  a:hover { color: #0c4a6e; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; color: #be123c; }
  pre { background: #1f2937; color: #f3f4f6; padding: 1em 1.2em; border-radius: 8px; overflow-x: auto; line-height: 1.5; }
  pre code { background: transparent; color: inherit; padding: 0; font-size: 0.88em; }
  table { border-collapse: collapse; margin: 1em 0; }
  th, td { border: 1px solid #d1d5db; padding: 6px 14px; }
  th { background: #f3f4f6; font-weight: 600; }
  blockquote { border-left: 4px solid #10b981; margin: 1em 0; padding: .5em 1.2em; background: #ecfdf5; color: #064e3b; border-radius: 0 6px 6px 0; }
  blockquote p { margin: .3em 0; }
  ul, ol { padding-left: 1.6em; }
  li { margin: .2em 0; }
  img { max-width: 100%; height: auto; }
  hr { border: none; border-top: 1px solid #e5e7eb; margin: 2em 0; }
  .topnav { background: #064e3b; color: #fff; padding: .8em 1.2em; border-radius: 8px; margin-bottom: 1.5em; display: flex; justify-content: space-between; align-items: center; }
  .topnav a { color: #d1fae5; text-decoration: none; font-size: 0.9em; }
  .topnav a:hover { color: #fff; }
  .topnav strong { font-size: 0.95em; letter-spacing: .03em; }
</style>
</head>
<body>
<div class="topnav">
  <strong>🌿 Olive Note ドキュメント</strong>
  <span>
    <a href="view.php">📚 ドキュメント一覧</a>
    &nbsp;|&nbsp;
    <a href="javascript:history.back()">← 戻る</a>
  </span>
</div>
<div id="content">読み込み中...</div>
<script>
  const raw = <?= json_encode($markdown, JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('content').innerHTML = marked.parse(raw);
</script>
</body>
</html>
