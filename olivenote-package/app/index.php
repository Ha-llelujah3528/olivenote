<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth/auth.php';

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

// Provider 固有のヘッダー（Google なら FedCM 用 Permissions-Policy）
auth_setup_headers();
auth_start_session();

// 未ログイン時は provider に応じたログイン画面を出して終了
if (!auth_is_logged_in()) {
    auth_render_login_screen($appVersion);
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
        "react-dom": "https://esm.sh/react-dom@18.2.0",
        "react-dom/client": "https://esm.sh/react-dom@18.2.0/client",
        "react/jsx-runtime": "https://esm.sh/react@18.2.0/jsx-runtime",
        "lucide-react": "https://esm.sh/lucide-react@0.292.0?deps=react@18.2.0",
        "@tiptap/react": "https://esm.sh/@tiptap/react@2.10.3?deps=react@18.2.0,react-dom@18.2.0",
        "@tiptap/starter-kit": "https://esm.sh/@tiptap/starter-kit@2.10.3",
        "@tiptap/extension-paragraph": "https://esm.sh/@tiptap/extension-paragraph@2.10.3",
        "@tiptap/extension-link": "https://esm.sh/@tiptap/extension-link@2.10.3",
        "@tiptap/extension-task-list": "https://esm.sh/@tiptap/extension-task-list@2.10.3",
        "@tiptap/extension-task-item": "https://esm.sh/@tiptap/extension-task-item@2.10.3",
        "@tiptap/extension-placeholder": "https://esm.sh/@tiptap/extension-placeholder@2.10.3",
        "@tiptap/extension-table": "https://esm.sh/@tiptap/extension-table@2.10.3",
        "@tiptap/extension-table-row": "https://esm.sh/@tiptap/extension-table-row@2.10.3",
        "@tiptap/extension-table-header": "https://esm.sh/@tiptap/extension-table-header@2.10.3",
        "@tiptap/extension-table-cell": "https://esm.sh/@tiptap/extension-table-cell@2.10.3",
        "@tiptap/extension-image": "https://esm.sh/@tiptap/extension-image@2.10.3",
        "tiptap-markdown": "https://esm.sh/tiptap-markdown@0.8.10",
        "@excalidraw/excalidraw": "https://esm.sh/@excalidraw/excalidraw@0.17.6?deps=react@18.2.0,react-dom@18.2.0&external=react,react-dom",
        "pusher-js": "https://esm.sh/pusher-js@8.4.0",
        "yjs": "https://esm.sh/yjs@13.6.20",
        "@tiptap/extension-collaboration": "https://esm.sh/@tiptap/extension-collaboration@2.10.3?deps=yjs@13.6.20&external=yjs",
        "@tiptap/extension-collaboration-cursor": "https://esm.sh/@tiptap/extension-collaboration-cursor@2.10.3?deps=yjs@13.6.20&external=yjs",
        "y-prosemirror": "https://esm.sh/y-prosemirror@1.2.12?deps=yjs@13.6.20&external=yjs",
        "y-protocols/awareness": "https://esm.sh/y-protocols@1.0.6/awareness?deps=yjs@13.6.20&external=yjs"
      }
    }
  </script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

  <script>
    // GAS版との互換性のため残す（使用しない）
    let INJECTED_DATA = null;
    // フッタのバージョン表示用。dist/stg どちらでも index.php 冒頭で算出される。
    window.APP_VERSION = <?= json_encode($appVersion, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    // Pusher（リアルタイム同時編集）のフロント設定。PUSHER_KEY が空なら enabled=false で
    // 同期機能は丸ごとスキップされ、従来の単独編集＋DB保存で動作する。秘密鍵(SECRET)は渡さない。
    window.PUSHER = {
      key:     <?= json_encode(defined('PUSHER_KEY') ? PUSHER_KEY : '', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      cluster: <?= json_encode(defined('PUSHER_CLUSTER') && PUSHER_CLUSTER !== '' ? PUSHER_CLUSTER : 'ap3', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      enabled: <?= (defined('PUSHER_KEY') && PUSHER_KEY !== '') ? 'true' : 'false' ?>
    };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <!-- SheetJS (Excel/CSV パース): AI課題生成モーダルで使用。globalThis.XLSX として展開される -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <!-- Wiki: 差分比較 (jsdiff) と PDF 出力 (html2pdf) — グローバルで Diff / html2pdf を提供 -->
  <script src="https://cdn.jsdelivr.net/npm/diff@5.2.0/dist/diff.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.2/dist/html2pdf.bundle.min.js"></script>
  <!-- Floating UI: ドロップダウン等の自動配置（flip / shift）。window.FloatingUIDOM として展開される -->
  <script src="https://cdn.jsdelivr.net/npm/@floating-ui/core@1.6.8"></script>
  <script src="https://cdn.jsdelivr.net/npm/@floating-ui/dom@1.6.13"></script>
  <!-- Excalidraw（ホワイトボード）の CSS。本体 JS は importmap 経由で遅延 import する。
       ※ esm.sh は生の dist CSS を 404 で返すため、CSS は unpkg から取得する。 -->
  <link rel="stylesheet" href="https://unpkg.com/@excalidraw/excalidraw@0.17.6/dist/excalidraw.production.min.css" />
  <!-- TipTap (ProseMirror) は importmap 経由で ESM 読み込み。CSS は <style> ブロック内に手書きで定義。 -->
    <style>
      /* ===== 横オーバーフロー予防ガード =====
         実測ではレイアウトは健全（STG/PRD 全タブ 320〜390px で document の
         overflowPx=0）だが、内部の overflow-x-auto 領域（ボードのカラム/
         ナビのタブ等）からの横スクロール連鎖や、将来の回帰で document 全体が
         横に広がるのを「保険」として断つ。ピンチズーム自体は阻害しない
         （viewport の user-scalable は据え置き）。
         ※ body は別途 .overflow-hidden(Tailwind) で両軸クリップ済。 */
      html {
        overflow-x: clip;            /* 万一の document 横拡大を視覚的に断つ（座標は据え置き） */
      }
      html, body {
        overscroll-behavior-x: none; /* 横スクロール連鎖・端でのバウンドを抑制 */
      }

      /* ===== Excalidraw（ホワイトボード）UIの不要部品を非表示 ===== */
      /* ライブラリ（再利用シェイプ集）機能はこの用途では使わないのでトグルごと隠す。
         メニュー内の「Excalidraw links」等は WhiteboardView 側でカスタム MainMenu に
         置き換えて除外している（CSS ではなく描画で制御）。 */
      .excalidraw .default-sidebar-trigger,
      .excalidraw .sidebar-trigger.default-sidebar-trigger { display: none !important; }

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
      .markdown-body li:has(> input[type="checkbox"]) { list-style: none; margin-left: -1.5em; }
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

      /* ===== TipTap (ProseMirror) エディタのスタイル =====
         description 用 WYSIWYG。inline markdown shortcut（`# `, `**bold**`, `- ` 等）が標準で動く。 */
      .olive-tiptap-content .ProseMirror {
        min-height: 240px;
        padding: 12px 16px;
        outline: none;
        font-family: inherit;
        line-height: 1.65;
        color: #1f2937;
      }
      .olive-tiptap-content .ProseMirror:focus { outline: none; }
      .olive-tiptap-content .ProseMirror > * + * { margin-top: 0.6em; }
      .olive-tiptap-content .ProseMirror h1 { font-size: 1.5em; font-weight: bold; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
      .olive-tiptap-content .ProseMirror h2 { font-size: 1.3em; font-weight: bold; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
      .olive-tiptap-content .ProseMirror h3 { font-size: 1.15em; font-weight: bold; }
      .olive-tiptap-content .ProseMirror h4 { font-size: 1.05em; font-weight: bold; }
      .olive-tiptap-content .ProseMirror ul,
      .olive-tiptap-content .ProseMirror ol { padding-left: 1.5em; }
      .olive-tiptap-content .ProseMirror ul { list-style: disc; }
      .olive-tiptap-content .ProseMirror ol { list-style: decimal; }
      .olive-tiptap-content .ProseMirror li > p { margin: 0; }
      .olive-tiptap-content .ProseMirror blockquote {
        border-left: 3px solid #d1d5db;
        padding-left: 12px;
        color: #4b5563;
        font-style: italic;
      }
      .olive-tiptap-content .ProseMirror code {
        background: #f3f4f6;
        padding: 1px 5px;
        border-radius: 3px;
        font-family: Consolas, "Courier New", Menlo, Monaco, monospace;
        font-size: 0.92em;
      }
      .olive-tiptap-content .ProseMirror pre {
        background: #f3f4f6;
        padding: 10px 12px;
        border-radius: 5px;
        overflow-x: auto;
      }
      .olive-tiptap-content .ProseMirror pre code {
        background: transparent;
        padding: 0;
        font-size: 0.9em;
      }
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link {
        color: #2563eb;
        text-decoration: underline;
        word-break: break-all;
      }
      .olive-tiptap-content .ProseMirror hr {
        border: none;
        border-top: 1px solid #d1d5db;
        margin: 1em 0;
      }
      /* タスクリスト（GFM） */
      .olive-tiptap-content .ProseMirror ul[data-type="taskList"] {
        list-style: none;
        padding-left: 0;
      }
      .olive-tiptap-content .ProseMirror ul[data-type="taskList"] li {
        display: flex;
        align-items: flex-start;
        gap: 8px;
      }
      .olive-tiptap-content .ProseMirror ul[data-type="taskList"] li > label {
        flex-shrink: 0;
        margin-top: 4px;
        user-select: none;
      }
      .olive-tiptap-content .ProseMirror ul[data-type="taskList"] li > div {
        flex: 1 1 auto;
      }
      /* テーブル（StarterKit には無いが、貼り付け時の表組み対応） */
      .olive-tiptap-content .ProseMirror table {
        border-collapse: collapse;
        margin: 0.5em 0;
        width: auto;
      }
      .olive-tiptap-content .ProseMirror table th,
      .olive-tiptap-content .ProseMirror table td {
        border: 1px solid #d1d5db;
        padding: 6px 10px;
      }
      .olive-tiptap-content .ProseMirror table th {
        background: #f3f4f6;
        font-weight: bold;
      }
      /* placeholder（@tiptap/extension-placeholder） */
      /* 同時編集カーソル（@tiptap/extension-collaboration-cursor, Phase 2）
         色はライブラリが border-color / background-color をインライン指定する。ここはレイアウトのみ。 */
      .olive-tiptap-content .ProseMirror .collaboration-cursor__caret {
        border-left: 1px solid #0d0d0d;
        border-right: 1px solid #0d0d0d;
        margin-left: -1px;
        margin-right: -1px;
        pointer-events: none;
        position: relative;
        word-break: normal;
      }
      .olive-tiptap-content .ProseMirror .collaboration-cursor__label {
        border-radius: 3px 3px 3px 0;
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        left: -1px;
        line-height: normal;
        padding: 1px 6px;
        position: absolute;
        top: -1.4em;
        user-select: none;
        white-space: nowrap;
      }
      /* インライン画像: 段落内に配置されるが、視覚的にはブロック的に見せる
         （max-width で段落幅に収まる、margin で前後と分離） */
      .olive-tiptap-content .ProseMirror img.olive-tiptap-image {
        max-width: 100%;
        height: auto;
        border-radius: 4px;
        display: inline-block;
        vertical-align: top;
        margin: 0.25em 0;
        border: 1px solid #e5e7eb;
        background-color: #f9fafb;
      }
      .olive-tiptap-content .ProseMirror img.olive-tiptap-image.ProseMirror-selectednode {
        outline: 2px solid #4D7A2D;
      }
      /* リンクラップされた画像（オリジナルが Drive にある状態）。
         クリックは「ノード選択（→幅プリセット表示）」に変えたため、虫眼鏡(zoom-in)では
         なく pointer で「クリックできる」ことだけ示す。Drive 原本を開く動線は選択時に
         画像のそばへ出る浮動メニュー／ツールバーの「原寸を開く」に分離した。 */
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link > img.olive-tiptap-image {
        cursor: pointer;
        transition: opacity 120ms ease;
      }
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link > img.olive-tiptap-image:hover {
        opacity: 0.92;
      }
      /* placeholder リンク (#pending-xxx) のときはまだ Drive 保存中なのでカーソルを変えない */
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link[href^="#pending-"] > img.olive-tiptap-image {
        cursor: default;
        outline: 1px dashed #f59e0b;
      }
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link[href^="#pending-"] > img.olive-tiptap-image:hover {
        opacity: 1;
      }
      .olive-tiptap-content .ProseMirror p.is-editor-empty:first-child::before {
        content: attr(data-placeholder);
        float: left;
        color: #9ca3af;
        pointer-events: none;
        height: 0;
      }

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

      /* ===== Classic: スラッシュ不透明度 (bg-olive-XXX/NN 等) の取りこぼし対策 =====
         Tailwind は色/opacity の組み合わせを別クラスとして展開するため、
         上のベースクラス上書きでは透明度バリエーションに適用されない。
         例: ヘッダのタブメニュー hover が常にオリーブ緑になっていた原因。 */
      [data-theme="classic"] .bg-olive-50\/20  { background-color: rgb(239 246 255 / 0.2) !important; }
      [data-theme="classic"] .bg-olive-50\/30  { background-color: rgb(239 246 255 / 0.3) !important; }
      [data-theme="classic"] .bg-olive-50\/40  { background-color: rgb(239 246 255 / 0.4) !important; }
      [data-theme="classic"] .bg-olive-50\/50  { background-color: rgb(239 246 255 / 0.5) !important; }
      [data-theme="classic"] .bg-olive-100\/50 { background-color: rgb(219 234 254 / 0.5) !important; }
      [data-theme="classic"] .bg-olive-500\/30 { background-color: rgb(59 130 246 / 0.3) !important; }
      [data-theme="classic"] .hover\:bg-olive-50\/40:hover  { background-color: rgb(239 246 255 / 0.4) !important; }
      [data-theme="classic"] .hover\:bg-olive-600\/50:hover { background-color: rgb(37 99 235 / 0.5) !important; }
      [data-theme="classic"] .active\:bg-olive-100\/50:active { background-color: rgb(219 234 254 / 0.5) !important; }
      [data-theme="classic"] .group:hover .group-hover\:bg-olive-50\/40 { background-color: rgb(239 246 255 / 0.4) !important; }
      /* hover:text-olive-900 は既存上書きに無かったので追加（クラシック系のテキスト基調はグレー） */
      [data-theme="classic"] .hover\:text-olive-900:hover { color: #374151 !important; }
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
    import React, { useState, useMemo, useEffect, useRef, useCallback } from 'react';
    import { createRoot } from 'react-dom/client';
    import {
      Columns, CalendarDays, Plus, X, MessageSquare, AlignLeft, Calendar as CalendarIcon,
      Search, CheckSquare, Flame, ThumbsUp, Settings, ChevronUp, ChevronDown, Trash2, Edit,
      Eye, Link as LinkIcon, Filter, CornerDownRight, AlertTriangle, Paperclip, Download, Loader2,
      FileText, ExternalLink, FilePlus, SaveAll, Tag, Copy,
      List, ListOrdered, Grid, Image as ImageIcon, Bell, Star, Sparkles, Wand2, Save, MessageCircle, Send, Bot,
      CheckCircle, XCircle, LogOut, RefreshCw, Folder, FolderPlus, Home, ChevronRight, Printer,
      UploadCloud, Check, Edit3, Table, Palette,
      Bookmark, Minimize2, Rows, ArrowUp, ArrowDown, ArrowUpDown,
      History, ChevronLeft, BookOpen, GitBranch, RotateCcw, FileDown, StickyNote, User
    } from 'lucide-react';

    // ===== TipTap (ProseMirror) — description 用 WYSIWYG エディタ =====
    import { useEditor, EditorContent, Editor } from '@tiptap/react';
    import StarterKit from '@tiptap/starter-kit';
    import Paragraph from '@tiptap/extension-paragraph';
    import TipTapLink from '@tiptap/extension-link';
    import TaskList from '@tiptap/extension-task-list';
    import TaskItem from '@tiptap/extension-task-item';
    import Placeholder from '@tiptap/extension-placeholder';
    // lucide-react に同名 Table アイコンがあるためエイリアス
    import TipTapTable from '@tiptap/extension-table';
    import TipTapTableRow from '@tiptap/extension-table-row';
    import TipTapTableHeader from '@tiptap/extension-table-header';
    import TipTapTableCell from '@tiptap/extension-table-cell';
    import TipTapImage from '@tiptap/extension-image';
    import { Markdown } from 'tiptap-markdown';
    // ===== Wiki 同時編集 (Yjs) — 設計: docs/wiki-collab-design.md =====
    import * as Y from 'yjs';
    import Collaboration from '@tiptap/extension-collaboration';
    import CollaborationCursor from '@tiptap/extension-collaboration-cursor';
    import { prosemirrorJSONToYDoc } from 'y-prosemirror';
    import * as awarenessProtocol from 'y-protocols/awareness';

    // ===== 空段落を保持する Paragraph（バグ: 改行が round-trip で詰まる対策）=====
    //   prosemirror-markdown の既定 serializer は「空段落＝無出力 + ブロック区切りは常に
    //   空行1つへ正規化」するため、ユーザーが Enter を連打して入れた空行が保存→再オープンで
    //   1つに畳まれてしまう。空段落だけ NBSP( ) を1文字書くと、markdown-it 再パース時に
    //   「中身のある段落」として復元され、連続空行がそのまま保持される（隔離ハーネスで往復実証済）。
    //   tiptap-markdown は拡張の storage.markdown.serialize を serializer として使うため、
    //   StarterKit の paragraph を無効化し、この拡張で置換する。
    const MarkdownParagraph = Paragraph.extend({
      addStorage() {
        return {
          markdown: {
            serialize(state, node) {
              if (node.childCount === 0) {
                state.write(String.fromCharCode(160));  // 空段落 → NBSP(U+00A0) で空行を残す
                state.closeBlock(node);
                return;
              }
              // 中身のある段落は prosemirror-markdown 既定と同じ挙動
              state.renderInline(node);
              state.closeBlock(node);
            },
            parse: {},
          },
        };
      },
    });

    // ===== 空チェックボックスを保存 Markdown から除去する =====
    //   上記 MarkdownParagraph で空段落を NBSP 化した副作用として、空の taskItem は
    //   `- [ ] <NBSP>` と serialize され、markdown-it が「中身あり」と判定して再オープンで
    //   本物の空チェックボックスとして復活してしまう（= 入力していないのにチェックボックスが出る）。
    //   そこで emit する Markdown 文字列から「マーカー + 空チェックボックス + 空白/NBSP のみ」の
    //   行を落とす。テキストや画像のある実チェックボックス（`- [ ] やること`）は残す。
    //   空行(NBSP段落)は行頭が `-` でないのでマッチせず保持される（隔離ハーネスで実証済）。
    const EMPTY_CHECKBOX_LINE = /^\s*[-*+] \[[ xX]\]\s*$/;  // \s は NBSP(U+00A0) も含む
    const HAS_CHECKBOX = /[-*+] \[[ xX]\]/;                  // チェックボックス自体が無ければ即 return 用
    const stripEmptyCheckboxes = (md) => {
      const s = md || '';
      // onUpdate は毎キー走るので、チェックボックスを含まない大半の文書は split せず素通し
      // （巨大ドキュメントでの split/filter/join コストを避ける）
      if (!HAS_CHECKBOX.test(s)) return s;
      return s.split('\n').filter(line => !EMPTY_CHECKBOX_LINE.test(line)).join('\n');
    };

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

    // 課題カード用パステルカラー（要件1：ボードのみ反映）
    // id が null/空のときは色なし（デフォルト表示）
    //   bg     : ボードカードの背景（淡い色）
    //   border : ボードカードの枠線
    //   bar    : ListView などで使う細い色帯（互換用）
    //   swatch : モーダルのカラーピッカー表示用
    const CARD_COLORS = [
      { id: 'red',    label: '赤',    bg: 'bg-red-100',    border: 'border-red-200',    bar: 'bg-red-400',    swatch: '#fecaca' },
      { id: 'orange', label: '橙',    bg: 'bg-orange-100', border: 'border-orange-200', bar: 'bg-orange-400', swatch: '#fed7aa' },
      { id: 'yellow', label: '黄',    bg: 'bg-yellow-100', border: 'border-yellow-200', bar: 'bg-yellow-400', swatch: '#fef08a' },
      { id: 'green',  label: '緑',    bg: 'bg-green-100',  border: 'border-green-200',  bar: 'bg-green-400',  swatch: '#bbf7d0' },
      { id: 'blue',   label: '青',    bg: 'bg-blue-100',   border: 'border-blue-200',   bar: 'bg-blue-400',   swatch: '#bfdbfe' },
      { id: 'purple', label: '紫',    bg: 'bg-purple-100', border: 'border-purple-200', bar: 'bg-purple-400', swatch: '#e9d5ff' },
      { id: 'gray',   label: 'グレー', bg: 'bg-gray-100',   border: 'border-gray-300',   bar: 'bg-gray-400',   swatch: '#e5e7eb' }
    ];
    const getCardColor = (id) => CARD_COLORS.find(c => c.id === id) || null;

    const AVATARS = ['👤', '🐶', '🐱', '🦊', '🐻', '🐼', '🐰', '🐯', '🐸', '🐷', '🦄', '🤖', '👻', '👾', '🚀'];
    const getTodayStr = () => new Date().toLocaleDateString('sv-SE');
    const DAYS_OF_WEEK = ['日', '月', '火', '水', '木', '金', '土'];

    // 任意の日付文字列を YYYY-MM-DD に正規化する。
    // 解釈できるフォーマット例:
    //   "2026-05-28"           → "2026-05-28"
    //   "20260528"             → "2026-05-28"
    //   "2026/5/28" / "2026.5.28" → "2026-05-28"
    //   "5/28" / "5-28" / "5.28"  → 今年の "YYYY-05-28"
    //   ""                     → ""（空欄を空欄のまま許容）
    // 解釈不能なら null を返す（呼び出し側で旧値に巻き戻すなど対応）。
    const parseFlexibleDate = (raw) => {
      if (raw == null) return '';
      const s = String(raw).trim();
      if (!s) return '';
      const pad = (n) => String(n).padStart(2, '0');
      const isValid = (y, m, d) => {
        if (!Number.isInteger(y) || !Number.isInteger(m) || !Number.isInteger(d)) return false;
        if (m < 1 || m > 12 || d < 1 || d > 31) return false;
        const dt = new Date(y, m - 1, d);
        return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
      };
      const build = (y, m, d) => isValid(y, m, d) ? `${String(y).padStart(4,'0')}-${pad(m)}-${pad(d)}` : null;

      let m;
      // YYYY-MM-DD / YYYY/M/D / YYYY.M.D
      m = s.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/);
      if (m) return build(+m[1], +m[2], +m[3]);
      // 8 桁数字 YYYYMMDD
      m = s.match(/^(\d{4})(\d{2})(\d{2})$/);
      if (m) return build(+m[1], +m[2], +m[3]);
      // M/D / M-D / M.D（年は今年）
      m = s.match(/^(\d{1,2})[-\/.](\d{1,2})$/);
      if (m) return build(new Date().getFullYear(), +m[1], +m[2]);
      return null;
    };

    // 柔軟な日付入力コンポーネント。
    // - 通常は <input type="text"> で表示し、8桁数字 / "YYYY/M/D" / "M/D" 等を受け付ける
    // - blur / Enter で parseFlexibleDate にかけ、YYYY-MM-DD に正規化して onChange を発火
    // - 解釈できない入力は赤枠で示し、値は親へ流さない（次の操作で訂正できる）
    // - 右端のカレンダーアイコンから native の date picker を呼べる
    // 既存の <input type="date" name=".." value=.. onChange=.. /> をそのまま差し替えられる
    // インターフェース（onChange は { target: { name, value } } を渡す）。
    const SmartDateInput = ({ value, onChange, name, disabled, className, ariaLabel, placeholder }) => {
      const [text, setText] = useState(value || '');
      const [invalid, setInvalid] = useState(false);
      const pickerRef = useRef(null);

      useEffect(() => {
        setText(value || '');
        setInvalid(false);
      }, [value]);

      const emit = (next) => {
        if (typeof onChange === 'function') onChange({ target: { name, value: next } });
      };

      const commit = () => {
        const trimmed = (text || '').trim();
        if (!trimmed) {
          setInvalid(false);
          if ((value || '') !== '') emit('');
          return;
        }
        const normalized = parseFlexibleDate(trimmed);
        if (normalized == null) { setInvalid(true); return; }
        setInvalid(false);
        setText(normalized);
        if (normalized !== (value || '')) emit(normalized);
      };

      const openPicker = () => {
        const el = pickerRef.current;
        if (!el || disabled) return;
        if (typeof el.showPicker === 'function') {
          try { el.showPicker(); return; } catch (e) { /* Safari 旧版などはフォールバック */ }
        }
        el.focus();
        el.click();
      };

      const baseCls = className || '';
      const invalidCls = invalid ? ' border-red-400 ring-1 ring-red-300' : '';

      return (
        <div className="relative">
          <input
            type="text"
            inputMode="numeric"
            value={text}
            name={name}
            disabled={disabled}
            aria-label={ariaLabel || name || '日付'}
            placeholder={placeholder || 'YYYY-MM-DD'}
            onChange={(e) => { setText(e.target.value); if (invalid) setInvalid(false); }}
            onBlur={commit}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); commit(); } }}
            className={`${baseCls} pr-9${invalidCls}`}
          />
          <input
            ref={pickerRef}
            type="date"
            value={value || ''}
            tabIndex={-1}
            aria-hidden="true"
            onChange={(e) => {
              setInvalid(false);
              setText(e.target.value);
              emit(e.target.value);
            }}
            style={{ position: 'absolute', opacity: 0, width: 0, height: 0, pointerEvents: 'none' }}
          />
          <button
            type="button"
            onClick={openPicker}
            disabled={disabled}
            tabIndex={-1}
            title="カレンダーから選択"
            className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-olive-700 disabled:opacity-40"
          >
            <CalendarIcon size={16} />
          </button>
        </div>
      );
    };

    // ============================================================
    // Drive 画像アップロードの同時実行セマフォ
    //   画像を複数枚まとめて貼り付け／ドロップすると、1 枚ごとにサーバー(api.php)の
    //   uploadFile（Driveへ原本アップロード＋公開設定）が走る。これらを同時に殺到
    //   させると PHP ワーカーを食い潰し、他ユーザーのページ読み込み(getInitialData)
    //   まで巻き込んで全体が固まる原因になっていた。そこで同時実行数を絞り、超過分は
    //   キューで順番待ちにする（プレビューは即時 base64 埋め込みなので体感は損なわない）。
    // ============================================================
    const driveUploadLimiter = (() => {
      const MAX_CONCURRENT = 2;
      let active = 0;
      const queue = [];
      const pump = () => {
        if (active >= MAX_CONCURRENT || queue.length === 0) return;
        active++;
        const { task, resolve, reject } = queue.shift();
        Promise.resolve()
          .then(task)
          .then(resolve, reject)
          .finally(() => { active--; pump(); });
      };
      // task: () => Promise<any> を受け取り、空きスロットで実行する
      return (task) => new Promise((resolve, reject) => {
        queue.push({ task, resolve, reject });
        pump();
      });
    })();

    // ============================================================
    // ResizableImage — 画像にプリセット幅（小/中/大/原寸）を持たせる拡張
    //   Markdown 保存の制約: 素の Markdown 画像記法 `![alt](src)` には幅を書く構文が
    //   無い。一方 `![alt](src "title")` の title は tiptap-markdown / markdown-it が
    //   標準で往復できる。そこで「幅トークン」を image の title 属性に格納する:
    //     - title が "25%" | "50%" | "100%" のときは幅指定とみなし style:width に変換、
    //       tooltip(title 属性) としては出さない（ユーザーに "50%" が見えないように）
    //     - それ以外の title は通常の tooltip としてそのまま出す（現状 title は未使用）
    //   これで Markdown は標準のまま（リンク巻き `[![alt](src "50%")](href)` も維持）、
    //   保存/再読込でサイズが保たれる。PDF/印刷/プレビュー（marked 経由）は出力 HTML を
    //   applyImageWidthTokens() で後処理して同じ幅を反映する。
    // ============================================================
    const IMG_WIDTH_TOKEN_RE = /^(25|50|100)%$/;

    // ============================================================
    // 画像貼り付けの暴走ガード（2段構え）
    //   画像は body_md に base64 データURIとして直接保存される設計のため、意図しない連続
    //   貼り付けで body_md が十数MBに肥大化すると、読み込み時に TipTap の同期マウントで
    //   画面が固まる（実運用で 10MB / 約45秒フリーズの事故が発生）。
    //
    //   (1) 重複スキップ（主軸 / DUP_WINDOW_MS）:
    //       「誤爆の連続貼り付け」は実体として "同じ画像をもう一度貼る"（Ctrl+V 連打・二度押し・
    //       同じファイルの再ドロップ）。直近に挿入したのと中身が同一の画像が短時間に再挿入され
    //       たらスキップする。中身比較なので、D&D の複数枚・1枚ずつ（=全部別画像）は素通りする。
    //   (2) 合計サイズの最終防壁（MAX_DOC_IMAGE_CHARS = 50MB）:
    //       意図的・事故を問わず、これを超えたら止める安全装置。通常運用ではまず当たらない。
    //   base64 の文字数 ≒ バイト数。
    const MAX_DOC_IMAGE_CHARS = 50 * 1024 * 1024; // 50MB（最終防壁）
    const DUP_WINDOW_MS = 4000;                   // この時間内に同一画像が再挿入されたら誤爆とみなしスキップ
    // ドキュメント内の base64 画像 src の合計文字数を数える（容量ガード用）
    const sumEmbeddedImageChars = (editor) => {
      if (!editor || editor.isDestroyed) return 0;
      let total = 0;
      editor.state.doc.descendants((node) => {
        if (node.type.name === 'image' && typeof node.attrs.src === 'string' && node.attrs.src.startsWith('data:')) {
          total += node.attrs.src.length;
        }
      });
      return total;
    };
    const ResizableImage = TipTapImage.extend({
      addAttributes() {
        return {
          ...this.parent?.(),
          title: {
            default: null,
            // markdown-it 経由の <img title="50%"> からそのまま読む（既定挙動と同じ）
            parseHTML: (el) => el.getAttribute('title'),
            renderHTML: (attrs) => {
              const t = attrs.title;
              if (typeof t === 'string' && IMG_WIDTH_TOKEN_RE.test(t)) {
                return { style: 'width:' + t };   // 幅トークンは style 化・tooltip 非表示
              }
              // 幅トークン以外（原寸=null/空、万一の "null" 文字列等）は title 属性を一切出さない。
              // 現状このエディタは画像 title を「幅トークンの保存先」専用に使っており通常の
              // tooltip 用途は無いため、ゴミ tooltip（"null" 等）の表示を確実に防ぐ。
              return {};
            },
          },
        };
      },
    });

    // ============================================================
    // 共有: エディタ拡張ビルダ（スキーマ部分のみ）
    //   RichMarkdownEditor 本体と、Wiki 同時編集の seed 用 headless エディタが
    //   「全く同じ ProseMirror スキーマ」を使うための単一ソース。editorProps / 画像アップロード等の
    //   「振る舞い」はスキーマに無関係なのでここには含めない（呼び出し側で付与）。
    //   collab 時は StarterKit の history を無効化（undo/redo は Collaboration に委譲）。
    // ============================================================
    const buildBaseEditorExtensions = (placeholder, opts = {}) => {
      const disableHistory = opts.history === false;
      return [
        StarterKit.configure({
          heading: { levels: [1, 2, 3, 4] },
          codeBlock: { HTMLAttributes: { class: 'olive-tiptap-codeblock' } },
          paragraph: false,
          ...(disableHistory ? { history: false } : {}),
        }),
        MarkdownParagraph,
        TipTapLink.configure({
          openOnClick: false,
          autolink: true,
          linkOnPaste: true,
          HTMLAttributes: { class: 'olive-tiptap-link', rel: 'noopener noreferrer', target: '_blank' },
        }),
        TaskList,
        TaskItem.configure({ nested: true }),
        Placeholder.configure({ placeholder: placeholder || '課題の詳細を入力...（Markdown が使えます）' }),
        TipTapTable.configure({ resizable: true, HTMLAttributes: { class: 'olive-tiptap-table' } }),
        TipTapTableRow,
        TipTapTableHeader,
        TipTapTableCell,
        ResizableImage.configure({ inline: true, allowBase64: true, HTMLAttributes: { class: 'olive-tiptap-image' } }),
        Markdown.configure({
          html: false, tightLists: true, bulletListMarker: '-', linkify: true, breaks: true,
          transformPastedText: true, transformCopiedText: true,
        }),
      ];
    };

    // ============================================================
    // Wiki 同時編集 (Yjs) — base64 ヘルパ / seeder / Pusher 中継プロバイダ
    //   設計: docs/wiki-collab-design.md（§3 provider, §4 seed, §11）
    // ============================================================
    // Yjs の update / state vector はバイナリ(Uint8Array)。Pusher / API は文字列なので base64 で運ぶ。
    const _u8ToB64 = (u8) => {
      let s = ''; const CH = 0x8000;
      for (let i = 0; i < u8.length; i += CH) s += String.fromCharCode.apply(null, u8.subarray(i, i + CH));
      return btoa(s);
    };
    const _b64ToU8 = (b64) => {
      const bin = atob(b64); const u8 = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
      return u8;
    };

    // markdown → Y.Doc 初期状態(Update バイト列)。
    //   1) 本番と同一スキーマの headless 非collab エディタで markdown を PM JSON にパース
    //   2) prosemirrorJSONToYDoc(schema, json, 'default') で Y.Doc 構築
    //      ※ フラグメント名は必ず 'default'（TipTap Collaboration の既定）。'prosemirror' だと
    //        「seed したのにエディタが空」になる（設計 §11.A）。
    const seedYdocUpdateFromMarkdown = (markdown, title) => {
      const ed = new Editor({ extensions: buildBaseEditorExtensions('', { history: true }), content: markdown || '' });
      let json = null, schema = null;
      try { json = ed.getJSON(); schema = ed.schema; } catch (_) {} finally { ed.destroy(); }
      // パースに失敗したら空ドキュメントとして seed（少なくとも有効な Y.Doc を返す）
      const ydoc = new Y.Doc();
      try { if (json && schema) Y.applyUpdate(ydoc, Y.encodeStateAsUpdate(prosemirrorJSONToYDoc(schema, json, 'default'))); } catch (_) {}
      // タイトルも同じ Y.Doc に Y.Text('title') として seed する（本文と同じ条件付き UPDATE で
      // 直列化されるため、勝者/敗者/ピア収束すべてで本文・タイトルが一貫する。設計 §4）。
      try { if (title) ydoc.getText('title').insert(0, String(title)); } catch (_) {}
      const update = Y.encodeStateAsUpdate(ydoc);
      ydoc.destroy();
      return update;
    };

    // Pusher presence チャンネルを Yjs の中継に使う軽量プロバイダ。
    //   - ローカル update を client-yjs-update で全員へ配信（150ms バッチ + 9KB チャンク）
    //   - 受信は applyUpdate(…, 'remote') で適用（origin 判定でエコー防止）
    //   - 欠損自己修復: state vector を交換し差分(client-yjs-sync)を返送（subscribe直後/member_added/15s毎）
    //   - presence メンバーは onPresence(members, myId) で WikiView に通知
    //   ※ provider は「DB 永続化のハブではない」。同期は完全 P2P で、特定ピアに依存しない。
    const createPusherYjsProvider = (pageId, ydoc, opts = {}) => {
      const onPresence = opts.onPresence || function () {};
      const MAX_BYTES = 9000;
      const channelName = 'presence-wiki-' + pageId;
      let channel = null, pusher = null, myId = null, destroyed = false, msgSeq = 0;
      const membersInfo = new Map();
      let batch = [], flushTimer = null, hbTimer = null;
      const partials = new Map();   // mid -> { parts:[], n, got }

      // ===== awareness（カーソル/選択範囲の共有, Phase 2）=====
      //   CollaborationCursor 拡張に渡す provider.awareness。状態は ephemeral（DB 非永続）。
      //   ローカル変化は client-aware で中継、受信は applyAwarenessUpdate(…, 'remote')。
      const awareness = new awarenessProtocol.Awareness(ydoc);
      let awareThrottle = 0, awareTrailing = null, awarePending = new Set();

      const send = (event, data) => { try { if (channel) channel.trigger(event, data); } catch (_) {} };

      // u8 を base64 化し、9KB を超えるなら mid 付きでチャンク分割送信
      const sendBytes = (event, base, u8) => {
        const b64 = _u8ToB64(u8);
        if (b64.length <= MAX_BYTES) { send(event, { ...base, u: b64 }); return; }
        const mid = (myId || 'x') + ':' + (++msgSeq);
        const n = Math.ceil(b64.length / MAX_BYTES);
        for (let i = 0; i < n; i++) send(event, { ...base, mid, i, n, u: b64.slice(i * MAX_BYTES, (i + 1) * MAX_BYTES) });
      };

      // チャンク受信を組み立てて u8 を cb に渡す（単一チャンクは即時）
      //   欠損チャンクで partials が無限に溜まらないよう、n に上限を設け、古い未完エントリは破棄する。
      const MAX_CHUNKS = 256;     // full-sync でもこの枚数(=最大 ~2.3MB base64)で十分。超過は不正として無視
      const PARTIAL_TTL = 30000;  // 30 秒で揃わなかった未完メッセージは破棄
      const recvBytes = (data, cb) => {
        if (data.mid == null || data.n == null) { try { cb(_b64ToU8(data.u)); } catch (_) {} return; }
        const n = data.n | 0, i = data.i | 0;
        if (n <= 0 || n > MAX_CHUNKS || i < 0 || i >= n) return;   // 異常な i/n は捨てる
        const now = Date.now();
        // 経年した未完エントリを掃除（メモリリーク防止）
        if (partials.size > 0) for (const [k, v] of partials) { if (now - v.t > PARTIAL_TTL) partials.delete(k); }
        let rec = partials.get(data.mid);
        if (!rec) { rec = { parts: new Array(n).fill(null), n, got: 0, t: now }; partials.set(data.mid, rec); }
        if (rec.n !== n) return;   // 同一 mid で枚数が食い違う = 不整合、捨てる
        if (rec.parts[i] == null) { rec.parts[i] = data.u; rec.got++; }
        if (rec.got === rec.n) { partials.delete(data.mid); try { cb(_b64ToU8(rec.parts.join(''))); } catch (_) {} }
      };

      // ローカル update を捕捉 → 150ms バッチ → merge → 配信
      const onLocalUpdate = (update, origin) => {
        if (origin === 'remote') return;   // 受信由来は再配信しない（エコー防止）
        batch.push(update);
        if (flushTimer) return;
        flushTimer = setTimeout(() => {
          flushTimer = null;
          if (!batch.length || !channel) { batch = []; return; }
          const merged = Y.mergeUpdates(batch); batch = [];
          sendBytes('client-yjs-update', {}, merged);
        }, 150);
      };
      ydoc.on('update', onLocalUpdate);

      const sendStateVector = () => {
        if (channel) send('client-yjs-sv', { uid: myId, sv: _u8ToB64(Y.encodeStateVector(ydoc)) });
      };

      const applyRemote = (u8) => { try { Y.applyUpdate(ydoc, u8, 'remote'); } catch (_) {} };

      // awareness 変化を client-aware で配信（カーソル移動は高頻度なので 80ms スロットル）。
      const flushAware = () => {
        awareThrottle = Date.now();
        if (!channel || awarePending.size === 0) { awarePending = new Set(); return; }
        let u8; try { u8 = awarenessProtocol.encodeAwarenessUpdate(awareness, Array.from(awarePending)); } catch (_) { awarePending = new Set(); return; }
        awarePending = new Set();
        sendBytes('client-aware', {}, u8);
      };
      const onAwareUpdate = ({ added, updated, removed }, origin) => {
        if (origin === 'remote') return;   // 受信由来は再配信しない
        for (const c of added) awarePending.add(c);
        for (const c of updated) awarePending.add(c);
        for (const c of removed) awarePending.add(c);
        const now = Date.now();
        if (now - awareThrottle > 80) { flushAware(); }
        else { if (awareTrailing) clearTimeout(awareTrailing); awareTrailing = setTimeout(flushAware, 80); }
      };
      awareness.on('update', onAwareUpdate);
      const applyRemoteAware = (u8) => { try { awarenessProtocol.applyAwarenessUpdate(awareness, u8, 'remote'); } catch (_) {} };
      // 新規参加者に自分（と手元で見えている全員）の awareness を一括送信する。
      const sendFullAwareness = () => {
        if (!channel) return;
        const clients = Array.from(awareness.getStates().keys());
        if (clients.length === 0) return;
        let u8; try { u8 = awarenessProtocol.encodeAwarenessUpdate(awareness, clients); } catch (_) { return; }
        sendBytes('client-aware', {}, u8);
      };

      const mapMember = (m) => ({ id: m.id, name: (m.info && m.info.name) || 'ゲスト', color: (m.info && m.info.color) || '#3b82f6' });
      const emitPresence = () => onPresence(Array.from(membersInfo.values()), myId);

      // ready は「購読が成立して初期メンバーが確定したか」を返す Promise。
      //   解決値: { ok:true, members, myId } / { ok:false }
      //   → 初期同期で「自分だけか／既存ピアが居るか」を確実に判定できるようにする。
      let resolveReady; const ready = new Promise((r) => { resolveReady = r; });
      let readyDone = false;
      const finishReady = (val) => { if (!readyDone) { readyDone = true; resolveReady(val); } };

      const setup = async () => {
        try { pusher = await ensurePusher(); } catch (_) { pusher = null; }
        if (!pusher || destroyed) { finishReady({ ok: false }); return; }
        channel = pusher.subscribe(channelName);
        const failTimer = setTimeout(() => finishReady({ ok: false, timeout: true }), 8000);
        channel.bind('pusher:subscription_succeeded', (m) => {
          clearTimeout(failTimer);
          myId = (m && m.me) ? m.me.id : null;
          membersInfo.clear();
          try { m.each((mm) => membersInfo.set(mm.id, mapMember(mm))); } catch (_) {}
          emitPresence();
          sendStateVector();   // 後発参加: 既存ピアへ差分を要求
          finishReady({ ok: true, members: Array.from(membersInfo.values()), myId });
        });
        channel.bind('pusher:subscription_error', () => { clearTimeout(failTimer); finishReady({ ok: false }); });
        channel.bind('pusher:member_added', (mm) => {
          membersInfo.set(mm.id, mapMember(mm)); emitPresence();
          sendStateVector();    // 相手が後発: SV 交換を促す
          sendFullAwareness();  // 相手に自分のカーソルを見せる
          // エディタ未マウントで自分の awareness がまだ空のタイミングに備えて 1 秒後に再送
          setTimeout(() => { if (!destroyed) sendFullAwareness(); }, 1000);
        });
        channel.bind('pusher:member_removed', (mm) => { membersInfo.delete(mm.id); emitPresence(); });
        channel.bind('client-aware', (data) => { if (data && data.u != null) recvBytes(data, applyRemoteAware); });
        channel.bind('client-yjs-update', (data) => { if (data && data.u != null) recvBytes(data, applyRemote); });
        channel.bind('client-yjs-sv', (data) => {
          if (!data || data.sv == null || data.uid === myId) return;
          let diff; try { diff = Y.encodeStateAsUpdate(ydoc, _b64ToU8(data.sv)); } catch (_) { return; }
          if (diff && diff.length > 2) sendBytes('client-yjs-sync', { to: data.uid }, diff);  // 空 update は ~2byte
        });
        channel.bind('client-yjs-sync', (data) => {
          if (!data || data.u == null || (data.to && data.to !== myId)) return;
          recvBytes(data, applyRemote);
        });
        hbTimer = setInterval(sendStateVector, 15000);
      };

      setup();

      return {
        channelName,
        ready,                      // Promise<{ ok, members?, myId? }>
        awareness,                  // CollaborationCursor 拡張に渡す（カーソル共有）
        getMyId: () => myId,
        getMembers: () => Array.from(membersInfo.values()),
        encodeState: () => _u8ToB64(Y.encodeStateAsUpdate(ydoc)),
        destroy: () => {
          destroyed = true;
          try { ydoc.off('update', onLocalUpdate); } catch (_) {}
          try { awareness.off('update', onAwareUpdate); } catch (_) {}
          if (awareTrailing) { clearTimeout(awareTrailing); awareTrailing = null; }
          if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
          if (hbTimer) { clearInterval(hbTimer); hbTimer = null; }
          if (channel) {
            try { channel.unbind_all(); } catch (_) {}
            try { if (pusher) pusher.unsubscribe(channelName); } catch (_) {}
          }
          channel = null;
          // 自分の awareness 状態を消してから破棄（他端末から自分のカーソルが消える）
          try { awarenessProtocol.removeAwarenessStates(awareness, [awareness.clientID], 'local'); } catch (_) {}
          try { awareness.destroy(); } catch (_) {}
        },
      };
    };

    // ============================================================
    // RichMarkdownEditor — TipTap (ProseMirror) ベースの WYSIWYG エディタ
    // TaskModal / Wiki（Sprint 3）共通で使う想定。
    //
    // 特徴:
    // - Notion 風の inline markdown shortcut が標準で動く:
    //   `# `→H1, `## `→H2, `**bold**`→太字, `*italic*`→斜体, `- `→箇条書き,
    //   `1. `→番号付き, `> `→引用, `[ ] `→タスクリスト, ``` →コードブロック, `---`→水平線
    // - markdown 永続化は tiptap-markdown（serialize/parse）
    // - 自前ツールバーで主要装飾を露出。アイコンは lucide-react
    // - forwardedRef 経由で getMarkdown / setMarkdown / insertTemplate / focus を露出
    // ============================================================
    const RichMarkdownEditor = React.forwardRef(
      ({ value, onChange, placeholder, disabled, minHeight, mentionMembers, collab }, ref) => {
        // collab = { ydoc } が渡されたら Wiki 同時編集モード。content を渡さず Collaboration に本文を委ねる。
        // 渡されない（TaskModal 等）なら従来どおり value(markdown) ベースで動く。
        const isCollab = !!(collab && collab.ydoc);
        const lastEmittedRef = useRef(value || '');
        // onChange は親が render のたびに新規生成し得る → ref で常に最新版を参照
        const onChangeRef = useRef(onChange);
        useEffect(() => { onChangeRef.current = onChange; }, [onChange]);
        // 画像アップロード関数: editor / api を参照するため、ref 経由で editorProps(paste/drop) から呼ぶ
        const uploadAndInsertImageRef = useRef(null);
        const imageInputRef = useRef(null);
        // 直近に挿入した画像の署名 [{ sig, t }]（重複スキップ判定用。時間窓 DUP_WINDOW_MS で間引く）
        const recentImageSigsRef = useRef([]);
        // 進行中・完了・失敗のアップロード状況を可視化（ツールバー直下のステータスバーで表示）
        // shape: [{ id, name, status: 'pending'|'success'|'error', error?: string, retry?: () => void }]
        const [pendingUploads, setPendingUploads] = useState([]);

        // ===== @メンションサジェスト =====
        const [mentionSuggest, setMentionSuggest] = useState({ active: false, members: [], selectedIndex: 0, rect: null });
        const mentionSuggestRef = useRef({ active: false, members: [], selectedIndex: 0, rect: null });
        useEffect(() => { mentionSuggestRef.current = mentionSuggest; }, [mentionSuggest]);
        const mentionMembersRef = useRef(mentionMembers || []);
        useEffect(() => { mentionMembersRef.current = mentionMembers || []; }, [mentionMembers]);
        // useEditor の onUpdate / keydown listener から参照するため先に宣言（初期値 null; 初回 render 後すぐ差し替わる）
        const detectMentionRef = useRef(null);
        const insertMentionRef = useRef(null);
        // =====================================

        const editor = useEditor({
          // 拡張のスキーマ部分は共有ビルダで生成（seed 用 headless エディタと完全に同一スキーマ）。
          // collab 時は history を Collaboration に委譲し、Collaboration 拡張を末尾に足す。
          extensions: [
            ...buildBaseEditorExtensions(placeholder, { history: !isCollab }),
            ...(isCollab ? [Collaboration.configure({ document: collab.ydoc })] : []),
            // Phase 2: 他参加者のカーソル/選択範囲を表示（provider.awareness 経由）。
            ...(isCollab && collab.provider && collab.provider.awareness ? [
              CollaborationCursor.configure({
                provider: collab.provider,
                user: collab.user || { name: 'ゲスト', color: '#3b82f6' },
              }),
            ] : []),
          ],
          // collab 時は本文を Y.Doc から取るので content を渡さない（渡すと二重化＝重複の原因）。
          content: isCollab ? '' : (value || ''),
          editable: !disabled,
          editorProps: {
            attributes: {
              class: 'olive-tiptap-prosemirror',
              spellcheck: 'false',
            },
            handleKeyDown: (view, event) => {
              // Ctrl+Y → redo（TipTap 標準は Ctrl+Shift+Z のみ。Windows ユーザー向けに追加）
              if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'y') {
                event.preventDefault();
                editor?.chain().focus().redo().run();
                return true;
              }
              return false;
            },
            // クリップボードからの画像ペーストを Drive へ自動アップロード→挿入
            handlePaste: (view, event) => {
              const items = event.clipboardData && event.clipboardData.items;
              if (!items) return false;
              for (let i = 0; i < items.length; i++) {
                const item = items[i];
                if (item.kind === 'file' && item.type.startsWith('image/')) {
                  const file = item.getAsFile();
                  if (file) {
                    event.preventDefault();
                    uploadAndInsertImageRef.current && uploadAndInsertImageRef.current(file);
                    return true;
                  }
                }
              }
              return false;
            },
            // ドラッグ&ドロップされた画像ファイルを Drive へ自動アップロード→挿入
            handleDrop: (view, event, slice, moved) => {
              if (moved) return false;
              const files = event.dataTransfer && event.dataTransfer.files;
              if (!files || files.length === 0) return false;
              const images = Array.from(files).filter(f => f.type.startsWith('image/'));
              if (images.length === 0) return false;
              event.preventDefault();
              images.forEach(f => uploadAndInsertImageRef.current && uploadAndInsertImageRef.current(f));
              return true;
            },
            // 画像クリック: 画像ノードを選択する（→ ツールバーに幅プリセット 小/中/大/原寸 が出る）。
            //   以前はここで Drive 原本を window.open して return true しており、
            //   ProseMirror 既定のノード選択を奪っていた。その結果クリックしても
            //   isActive('image') が true にならず「幅を調整できない」状態だった。
            //   Drive オリジナルを開く動線は、選択時ツールバーの「原寸を開く」ボタンに分離。
            handleClickOn: (view, pos, node, nodePos, event) => {
              if (node.type.name !== 'image') return false;
              if (disabled || !editor) return false;   // 閲覧専用時は既定挙動に委ねる
              event.preventDefault();
              editor.chain().setNodeSelection(nodePos).run();
              return true;
            },
          },
          onUpdate: ({ editor, transaction }) => {
            const md = stripEmptyCheckboxes(editor.storage.markdown.getMarkdown());
            if (md !== lastEmittedRef.current) {
              lastEmittedRef.current = md;
              // autoClean = パース時に湧く幽霊 taskItem の自動除去。ユーザー編集ではないので
              // onChange(=dirty 化) を発火させない（開いただけで「未保存」になるのを防ぐ）。
              if (!transaction || !transaction.getMeta('autoClean')) {
                const cb = onChangeRef.current;
                if (typeof cb === 'function') cb(md);
              }
            }
            // @メンションサジェスト: カーソル直前の @... パターンを検出
            detectMentionRef.current && detectMentionRef.current(editor);
          },
          // カーソル移動だけでコンテンツが変わらないケース（矢印キー・クリック）でも dropdown を更新する
          onSelectionUpdate: ({ editor }) => {
            detectMentionRef.current && detectMentionRef.current(editor);
          },
          // フォーカスを失ったら空チェックボックス(空 taskItem)を doc から除去する。
          // 「見えている内容＝保存内容」を担保する後始末（編集中は触らないので onBlur で実行）。
          // クロージャの editor は初回 render で null 固定になり得るため、引数の editor を渡す。
          onBlur: ({ editor }) => {
            removeEmptyTaskItems(editor);
          },
        });

        // エディタ内に残存する #pending-xxx リンク（前回 Drive アップロード未完了で
        // モーダルが閉じられた場合のゴミ）を除去する。画像本体は残し、リンクマークだけ剥がす。
        const cleanupStalePendingLinks = () => {
          if (!editor || editor.isDestroyed) return;
          const targets = [];
          editor.state.doc.descendants((node, pos) => {
            if (node.type.name !== 'image') return;
            const hasPending = node.marks.some(m => m.type.name === 'link' && m.attrs && typeof m.attrs.href === 'string' && m.attrs.href.startsWith('#pending-'));
            if (hasPending) targets.push({ pos, attrs: node.attrs, marks: node.marks });
          });
          if (targets.length === 0) return;
          const tr = editor.state.tr;
          targets.forEach(({ pos, attrs, marks }) => {
            const otherMarks = marks.filter(m => m.type.name !== 'link');
            tr.setNodeMarkup(pos, undefined, attrs, otherMarks);
          });
          editor.view.dispatch(tr);
        };

        // 空のチェックボックス(taskItem)を doc から除去する。
        //   tiptap-markdown@0.8.10 は空 taskItem を `- [ ] ` と serialize し、再オープンで
        //   `- \[ \]` 等へ化けて「入力していないのにチェックボックスが出る」状態になる。
        //   serializer 上書きは実チェックボックスの往復を壊したため（隔離ハーネスで確認済）、
        //   doc 側で空項目を消す方式を採用。onBlur で実行するので編集中の入力は妨げない。
        const removeEmptyTaskItems = (ed) => {
          if (!ed || ed.isDestroyed || !ed.isEditable) return;
          const isEmptyItem = (n) => {
            const onlyChild = n.childCount === 1 ? n.firstChild : null;
            return n.content.size === 0 || (onlyChild && onlyChild.content.size === 0);
          };
          const deletions = [];
          ed.state.doc.descendants((node, pos) => {
            if (node.type.name !== 'taskList') return true;
            const items = [];
            node.forEach((child, offset) => {
              items.push({ child, from: pos + 1 + offset, to: pos + 1 + offset + child.nodeSize });
            });
            const empties = items.filter(it => it.child.type.name === 'taskItem' && isEmptyItem(it.child));
            if (empties.length === 0) return false;
            if (empties.length === items.length) {
              // 全項目が空 → リストごと削除（最後の1項目だけ消すとスキーマが空項目を復活させる）
              deletions.push({ from: pos, to: pos + node.nodeSize });
            } else {
              empties.forEach(it => deletions.push({ from: it.from, to: it.to }));
            }
            return false; // taskList 配下にはこれ以上潜らない
          });
          if (deletions.length === 0) return;
          deletions.sort((a, b) => b.from - a.from); // 後方から消して前方位置を保つ
          const tr = ed.state.tr;
          tr.setMeta('autoClean', true);       // onUpdate 側で dirty 化を抑制するための目印
          tr.setMeta('addToHistory', false);   // 自動除去は undo 履歴に積まない
          deletions.forEach(d => tr.delete(d.from, d.to));
          ed.view.dispatch(tr);
        };

        // 親から value が変わった時のみ再同期（自分が emit した直後・編集中はスキップ）
        //   collab 時は本文を Y.Doc が支配するので value→setContent は一切行わない（Yjs と衝突するため）。
        useEffect(() => {
          if (!editor || isCollab) return;
          const incoming = value || '';
          if (incoming === lastEmittedRef.current) return;
          // 編集中（フォーカス中）は setContent しない → undo 履歴と入力中状態を保護
          if (editor.isFocused) return;
          lastEmittedRef.current = incoming;
          editor.commands.setContent(incoming, false);
          // setContent 後に走る: 残存 #pending-xxx を救済除去 + パース時に湧く幽霊 taskItem の除去
          cleanupStalePendingLinks();
          removeEmptyTaskItems(editor);
        }, [value, editor]);

        // エディタ初回マウント時にも残存 #pending-xxx を除去する
        // （useEditor の `content` オプションで初期値が入った時点で走らせたい）
        useEffect(() => {
          if (!editor) return;
          cleanupStalePendingLinks();
          removeEmptyTaskItems(editor);
          // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [editor]);

        // disabled 反映
        useEffect(() => {
          if (!editor) return;
          editor.setEditable(!disabled);
        }, [editor, disabled]);

        React.useImperativeHandle(ref, () => ({
          getMarkdown: () => stripEmptyCheckboxes(editor?.storage.markdown.getMarkdown() || ''),
          setMarkdown: (md) => {
            if (!editor) return;
            lastEmittedRef.current = md || '';
            editor.commands.setContent(md || '', false);
          },
          insertTemplate: (md) => {
            if (!editor) return;
            const current = stripEmptyCheckboxes(editor.storage.markdown.getMarkdown());
            const sep = current && !/\n\n$/.test(current) ? (current.endsWith('\n') ? '\n' : '\n\n') : '';
            const next = stripEmptyCheckboxes(current + sep + md);
            lastEmittedRef.current = next;
            editor.commands.setContent(next, false);
            editor.commands.focus('end');
            const cb = onChangeRef.current;
            if (typeof cb === 'function') cb(next);
          },
          // カーソル位置にテキストを挿入（フォーカスも当てる）。@メンション挿入や
          // ファイル添付後の URL 挿入など、末尾追記ではなくカーソル位置に入れたい時に使う。
          // insertTemplate と同様、挿入後に onChange を明示的に呼んで state を確実に更新する。
          insertAtCursor: (text) => {
            if (!editor) return;
            editor.chain().focus().insertContent(text).run();
            const md = stripEmptyCheckboxes(editor.storage.markdown.getMarkdown());
            lastEmittedRef.current = md;
            const cb = onChangeRef.current;
            if (typeof cb === 'function') cb(md);
          },
          focus: () => editor?.commands.focus(),
        }), [editor]);

        // ===== @メンションサジェスト: 挿入 / 検出 / キーボード操作 =====

        // カーソル直前の @... パターンを選択した member で置換してプレーンテキストとして挿入する
        const insertMentionSuggestion = (member) => {
          if (!editor) return;
          const { state } = editor;
          const { selection } = state;
          const { $from } = selection;
          const textBefore = $from.parent.textContent.slice(0, $from.parentOffset);
          const match = textBefore.match(/@([^\s　]*)$/);
          if (!match) { setMentionSuggest(prev => ({ ...prev, active: false })); return; }
          const from = selection.from - match[0].length;
          const to = selection.from;
          editor.chain().focus().deleteRange({ from, to }).insertContent(`@${member.name} `).run();
          setMentionSuggest({ active: false, members: [], selectedIndex: 0, rect: null });
        };

        // render のたびに ref を最新関数で差し替える（useEffect 外で同期的に実行）
        detectMentionRef.current = (editor) => {
          const allMembers = mentionMembersRef.current;
          if (!allMembers.length) return;
          const { state, view } = editor;
          const { selection } = state;
          if (selection.from !== selection.to) {
            setMentionSuggest(prev => prev.active ? { ...prev, active: false } : prev);
            return;
          }
          const { $from } = selection;
          const textBefore = $from.parent.textContent.slice(0, $from.parentOffset);
          const match = textBefore.match(/@([^\s　]*)$/);
          if (match) {
            const query = match[1];
            const filtered = allMembers.filter(m => m.name?.toLowerCase().includes(query.toLowerCase()));
            if (filtered.length > 0) {
              const coords = view.coordsAtPos(selection.from);
              setMentionSuggest({ active: true, members: filtered, selectedIndex: 0, rect: { top: coords.bottom + 4, left: coords.left } });
              return;
            }
          }
          setMentionSuggest(prev => prev.active ? { ...prev, active: false } : prev);
        };
        insertMentionRef.current = insertMentionSuggestion;

        // エディタ DOM に capture フェーズで keydown を差し込み、mention 表示中の↑↓/Tab/Enter/Esc を横取りする
        useEffect(() => {
          if (!editor) return;
          const dom = editor.view.dom;
          const onKeyDown = (e) => {
            const ms = mentionSuggestRef.current;
            if (!ms.active) return;
            if (e.key === 'Escape') { e.preventDefault(); setMentionSuggest(prev => ({ ...prev, active: false })); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); setMentionSuggest(prev => ({ ...prev, selectedIndex: (prev.selectedIndex + 1) % prev.members.length })); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); setMentionSuggest(prev => ({ ...prev, selectedIndex: (prev.selectedIndex - 1 + prev.members.length) % prev.members.length })); return; }
            if (e.key === 'Tab' || e.key === 'Enter') {
              e.preventDefault();
              e.stopPropagation();
              const cur = mentionSuggestRef.current;
              if (cur.active && cur.members[cur.selectedIndex]) {
                insertMentionRef.current && insertMentionRef.current(cur.members[cur.selectedIndex]);
              }
            }
          };
          dom.addEventListener('keydown', onKeyDown, true);
          return () => dom.removeEventListener('keydown', onKeyDown, true);
        }, [editor]);

        // フォーカスが外れたらドロップダウンを閉じる（ただし mention item への mousedown は preventDefault で blur を防ぐ）
        useEffect(() => {
          if (!editor) return;
          const dom = editor.view.dom;
          const onBlur = () => setMentionSuggest(prev => prev.active ? { ...prev, active: false } : prev);
          // blur はバブルしないため focusout を使う（TipTap 内のネストした contenteditable でも確実に発火する）
          dom.addEventListener('focusout', onBlur);
          return () => dom.removeEventListener('focusout', onBlur);
        }, [editor]);
        // =====================================

        // ツールバーのボタン生成ヘルパ
        const tbBtn = (active, onClick, children, title) => (
          <button
            type="button"
            onMouseDown={(e) => { e.preventDefault(); onClick(); }}
            title={title}
            disabled={disabled || !editor}
            className={`px-2 py-1 text-xs rounded border transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 ${
              active
                ? 'bg-olive-100 border-olive-400 text-olive-900 font-bold'
                : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
            }`}
          >{children}</button>
        );

        // ===== 画像の挿入とアップロード =====
        //
        // 設計（Drive 直リンクが Google のホットリンク制限で破損表示になる問題への対処）:
        //   1. ブラウザ側で canvas を使って最大幅 1280px に圧縮した base64 画像をエディタに即時埋め込み
        //      → ユーザーは Drive アップロードを待たずにプレビュー確認できる
        //   2. 画像はリンクラップ ([![alt](data:...)](#pending-{uploadId})) して挿入
        //   3. 裏で原本(オリジナル)を Drive にアップロード
        //   4. アップロード完了時、#pending-{uploadId} → https://drive.google.com/file/d/{id}/view に置換
        //      → 画像クリックで Drive 上のオリジナル(高画質)を別タブで開ける
        //   5. 進行中・失敗・成功はツールバー直下のステータスバーで可視化
        //      → 「Drive 保存中にブラウザ閉じないで」をユーザーが直感的に理解できる
        //
        // api.uploadFile (image MIME) は lh3.googleusercontent.com/d/{id} URL を返すが、
        // それは <img src> として使うと壊れるため res.id だけ利用してプレビュー専用 URL を組む。

        // canvas を使って画像を maxWidth 以下に縮小する
        // - PNG は透過保持のため PNG のまま圧縮（デザイン用途のスクショ対応）
        // - それ以外は JPEG q=0.7 で圧縮（サイズ削減効果が高い）
        const compressImage = (file, maxWidth = 1280, jpegQuality = 0.7) => {
          return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
              try {
                URL.revokeObjectURL(url);
                let w = img.naturalWidth || img.width;
                let h = img.naturalHeight || img.height;
                if (w > maxWidth) {
                  h = Math.round(h * maxWidth / w);
                  w = maxWidth;
                }
                const canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);
                const isPng = (file.type || '').toLowerCase() === 'image/png';
                const outMime = isPng ? 'image/png' : 'image/jpeg';
                canvas.toBlob((blob) => {
                  if (!blob) { reject(new Error('画像の圧縮に失敗しました (canvas.toBlob)')); return; }
                  const r = new FileReader();
                  r.onload = () => resolve({ dataUrl: String(r.result), mime: outMime });
                  r.onerror = () => reject(new Error('圧縮画像の読み込みに失敗しました'));
                  r.readAsDataURL(blob);
                }, outMime, isPng ? undefined : jpegQuality);
              } catch (e) { reject(e); }
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('画像のデコードに失敗しました')); };
            img.src = url;
          });
        };

        // 原本を Drive へアップロードして、エディタ内の placeholder リンクを Drive URL に置換する
        // 注意: editor のクロージャ参照を避けるため、関数内で editorRef.current を使うのではなく
        //       下記の uploadAndInsertImageRef 用 useEffect 内で再定義する設計でもよいが、
        //       現状 useEditor は同一マウント中は同じインスタンスを返すため、ここで定義した
        //       startBackgroundDriveUpload は同マウント中の editor を参照し続ける（問題なし）。
        //       マウント跨ぎ（モーダル閉じ→開く）は cleanupStalePendingLinks() で対処。
        const startBackgroundDriveUpload = (file, uploadId) => {
          (async () => {
            try {
              // 同時実行セマフォ経由でアップロード（複数枚同時貼り付け時のサーバー殺到を防ぐ）。
              // base64 化も重い処理なのでスロット内で行い、待機中の余計なメモリ確保も避ける。
              const res = await driveUploadLimiter(async () => {
                const base64Data = await fileToBase64(file);
                return api.uploadFile(base64Data);
              });
              if (!res || !res.id) throw new Error('Drive へのアップロードに失敗しました');
              const originalUrl = `https://drive.google.com/file/d/${res.id}/view`;
              const placeholderHref = `#pending-${uploadId}`;

              // ドキュメント内を走査して、対象 placeholder を持つ image ノードのリンクマークを置換
              if (editor && !editor.isDestroyed) {
                const linkType = editor.schema.marks.link;
                // 走査と適用を分ける: pos のズレを完全に避けるため、まず対象 pos を集める
                // （image はリーフでサイズ1のため実害は出にくいが、保険として安全パターン）
                const targets = [];
                editor.state.doc.descendants((node, pos) => {
                  if (node.type.name !== 'image') return;
                  const hasPending = node.marks.some(m => m.type.name === 'link' && m.attrs && m.attrs.href === placeholderHref);
                  if (hasPending) targets.push({ pos, attrs: node.attrs, marks: node.marks });
                });
                if (targets.length > 0) {
                  const tr = editor.state.tr;
                  targets.forEach(({ pos, attrs, marks }) => {
                    const otherMarks = marks.filter(m => m.type.name !== 'link');
                    const newLink = linkType.create({
                      href: originalUrl, target: '_blank', rel: 'noopener noreferrer', class: 'olive-tiptap-link',
                    });
                    tr.setNodeMarkup(pos, undefined, attrs, [...otherMarks, newLink]);
                  });
                  editor.view.dispatch(tr);
                }
              }

              // ステータス → success、3秒後にエントリ消去
              setPendingUploads(prev => prev.map(u => u.id === uploadId ? { ...u, status: 'success' } : u));
              setTimeout(() => {
                setPendingUploads(prev => prev.filter(u => u.id !== uploadId));
              }, 3000);
            } catch (e) {
              const msg = (e && e.message) ? e.message : String(e);
              setPendingUploads(prev => prev.map(u => u.id === uploadId ? {
                ...u,
                status: 'error',
                error: msg,
                retry: () => {
                  setPendingUploads(p => p.map(x => x.id === uploadId ? { ...x, status: 'pending', error: undefined, retry: undefined } : x));
                  startBackgroundDriveUpload(file, uploadId);
                },
              } : u));
            }
          })();
        };


        useEffect(() => {
          uploadAndInsertImageRef.current = async (file) => {
            if (!editor || !file) return;
            if (!file.type.startsWith('image/')) {
              window.alert('画像ファイルを選択してください');
              return;
            }
            if (file.size > 50 * 1024 * 1024) {
              window.alert('画像は50MB以下にしてください');
              return;
            }

            // 1. 圧縮（失敗時は原本そのまま使う = フォールバック）
            let compressed;
            try {
              compressed = await compressImage(file, 1280, 0.7);
            } catch (e) {
              try {
                const fallback = await new Promise((res, rej) => {
                  const r = new FileReader();
                  r.onload = () => res({ dataUrl: String(r.result), mime: file.type });
                  r.onerror = () => rej(new Error('画像読み込みに失敗しました'));
                  r.readAsDataURL(file);
                });
                compressed = fallback;
              } catch (e2) {
                window.alert('画像の処理に失敗しました: ' + (e2.message || e2));
                return;
              }
            }

            // 1.4. 重複スキップ: 直近 DUP_WINDOW_MS 内に挿入したのと「中身が同じ」画像なら、
            //   誤爆の連続貼り付け（Ctrl+V 連打・二度押し・同ファイル再ドロップ）とみなしスキップ。
            //   署名は圧縮後 dataUrl から作る（同じ元画像→同じ圧縮結果なので一致する）。長さ +
            //   先頭/末尾の断片で十分に弁別でき、巨大文字列を保持せずに済む。中身比較なので
            //   D&D 複数枚・1枚ずつ（全部別画像）は一致せず素通りする。
            const dataUrl = compressed.dataUrl || '';
            const sig = dataUrl.length + '|' + dataUrl.slice(0, 48) + '|' + dataUrl.slice(-48);
            const nowTs = Date.now();
            const recentSigs = recentImageSigsRef.current.filter(e => nowTs - e.t < DUP_WINDOW_MS);
            if (recentSigs.some(e => e.sig === sig)) {
              recentImageSigsRef.current = recentSigs; // 古いエントリを間引いて保存
              setPendingUploads(prev => [...prev, {
                id: 'dup-' + nowTs.toString(36) + Math.random().toString(36).slice(2, 6),
                name: file.name || '画像',
                status: 'error',
                kind: 'limit',
                error: '同じ画像が連続して貼り付けられたためスキップしました（誤操作防止）。別の画像はそのまま貼り付けできます。',
              }]);
              return;
            }
            recentSigs.push({ sig, t: nowTs });
            recentImageSigsRef.current = recentSigs;

            // 1.5. 最終防壁: 埋め込み済み画像 + 今回の圧縮後サイズが上限(50MB)を超えるなら挿入中止。
            //   alert 連発を避けるためステータスバーで通知。通常運用ではまず当たらない安全装置。
            const newImageChars = dataUrl.length;
            if (sumEmbeddedImageChars(editor) + newImageChars > MAX_DOC_IMAGE_CHARS) {
              const limitMb = (MAX_DOC_IMAGE_CHARS / 1024 / 1024).toFixed(1);
              setPendingUploads(prev => [...prev, {
                id: 'limit-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
                name: file.name || '画像',
                status: 'error',
                kind: 'limit',
                error: `このドキュメントの画像合計容量が上限（約${limitMb}MB）に達したため追加できません。不要な画像を削除するか、ドキュメントを分割してください。`,
              }]);
              return;
            }

            // 2. アップロードID発番 → エディタに即時挿入（リンク先は placeholder）
            const uploadId = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
            const placeholderHref = `#pending-${uploadId}`;
            const ran = editor.chain().focus().insertContent({
              type: 'image',
              attrs: { src: compressed.dataUrl, alt: file.name || '' },
              marks: [{
                type: 'link',
                attrs: { href: placeholderHref, target: '_blank', rel: 'noopener noreferrer', class: 'olive-tiptap-link' },
              }],
            }).run();
            if (!ran) {
              window.alert('画像コマンドが実行できませんでした（Image extension 未登録の可能性）');
              return;
            }

            // 3. ステータスバーに pending エントリ追加
            setPendingUploads(prev => [...prev, { id: uploadId, name: file.name || '画像', status: 'pending' }]);

            // 4. 裏でオリジナルを Drive へ
            startBackgroundDriveUpload(file, uploadId);
          };
        }, [editor]);

        const handleImageButtonClick = () => {
          if (imageInputRef.current) imageInputRef.current.click();
        };
        const handleImageInputChange = async (e) => {
          const files = Array.from(e.target.files || []);
          for (const f of files) {
            if (uploadAndInsertImageRef.current) await uploadAndInsertImageRef.current(f);
          }
          if (imageInputRef.current) imageInputRef.current.value = '';
        };

        // ステータスバーから手動でエントリを消す
        const handleDismissPendingUpload = (id) => {
          setPendingUploads(prev => prev.filter(u => u.id !== id));
        };

        const handleSetLink = () => {
          if (!editor) return;
          const previousUrl = editor.getAttributes('link').href;
          const url = window.prompt('URL を入力してください', previousUrl || 'https://');
          if (url === null) return; // キャンセル
          if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
          }
          editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
        };

        if (!editor) {
          return (
            <div className="border border-gray-300 rounded-lg bg-white" style={{ minHeight: minHeight || '240px' }}>
              <div className="p-3 text-gray-400 text-sm">エディタを読み込み中...</div>
            </div>
          );
        }

        // 選択中の画像ノードに紐づく Drive リンク（あれば「原寸を開く」ボタンを出す）。
        // handleClickOn で画像をノード選択する設計に変えたため、原本を開く動線をここに分離。
        const selectedImageHref = (() => {
          const sel = editor.state.selection;
          const node = sel && sel.node;
          if (!node || node.type.name !== 'image') return '';
          const lm = node.marks.find(m => m.type.name === 'link');
          const href = lm && lm.attrs && lm.attrs.href;
          return (href && !href.startsWith('#pending-')) ? href : '';
        })();

        // 画像幅を変更する。chain().focus() は NodeSelection を解除してしまい、変更のたびに
        // 選択が外れて幅メニューが消える（毎回クリックし直しになる）ため focus は使わず、
        // 変更後に同じ位置へ NodeSelection を張り直して選択を維持する。
        // token は '25%' | '50%' | '100%' | null（null = サイズ指定解除）。
        const setImageWidth = (token) => {
          const sel = editor.state.selection;
          const pos = (sel && sel.node && sel.node.type.name === 'image') ? sel.from : null;
          let chain = editor.chain().updateAttributes('image', { title: token });
          if (pos !== null) chain = chain.setNodeSelection(pos);
          chain.run();
        };

        return (
          <div className="olive-rich-md-editor border border-gray-300 rounded-lg bg-white">
            <div className="olive-tiptap-toolbar sticky top-0 z-10 flex flex-wrap items-center gap-1 p-1.5 border-b border-gray-200 bg-gray-50 rounded-t-lg">
              {tbBtn(editor.isActive('heading', { level: 1 }), () => editor.chain().focus().toggleHeading({ level: 1 }).run(), 'H1', '見出し1 (# )')}
              {tbBtn(editor.isActive('heading', { level: 2 }), () => editor.chain().focus().toggleHeading({ level: 2 }).run(), 'H2', '見出し2 (## )')}
              {tbBtn(editor.isActive('heading', { level: 3 }), () => editor.chain().focus().toggleHeading({ level: 3 }).run(), 'H3', '見出し3 (### )')}
              <span className="w-px h-5 bg-gray-300 mx-1" />
              {tbBtn(editor.isActive('bold'), () => editor.chain().focus().toggleBold().run(), <span style={{ fontWeight: 'bold' }}>B</span>, '太字 (Ctrl+B / **)')}
              {tbBtn(editor.isActive('italic'), () => editor.chain().focus().toggleItalic().run(), <span style={{ fontStyle: 'italic' }}>I</span>, '斜体 (Ctrl+I / *)')}
              {tbBtn(editor.isActive('strike'), () => editor.chain().focus().toggleStrike().run(), <span style={{ textDecoration: 'line-through' }}>S</span>, '取り消し線 (~~)')}
              {tbBtn(editor.isActive('code'), () => editor.chain().focus().toggleCode().run(), <span style={{ fontFamily: 'Consolas, monospace' }}>{'<>'}</span>, 'インラインコード (`)')}
              <span className="w-px h-5 bg-gray-300 mx-1" />
              {tbBtn(editor.isActive('bulletList'), () => editor.chain().focus().toggleBulletList().run(), <List size={14} />, '箇条書き (- )')}
              {tbBtn(editor.isActive('orderedList'), () => editor.chain().focus().toggleOrderedList().run(), <ListOrdered size={14} />, '番号付きリスト (1. )')}
              {tbBtn(editor.isActive('taskList'), () => editor.chain().focus().toggleTaskList().run(), <CheckSquare size={14} />, 'チェックリスト ([ ] )')}
              <span className="w-px h-5 bg-gray-300 mx-1" />
              {tbBtn(editor.isActive('blockquote'), () => editor.chain().focus().toggleBlockquote().run(), '❝', '引用 (> )')}
              {tbBtn(editor.isActive('codeBlock'), () => editor.chain().focus().toggleCodeBlock().run(), '```', 'コードブロック (```)')}
              {tbBtn(editor.isActive('link'), handleSetLink, <LinkIcon size={14} />, 'リンク')}
              {tbBtn(false, () => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(), <Table size={14} />, 'テーブル挿入（3×3）')}
              {tbBtn(false, handleImageButtonClick, <ImageIcon size={14} />, '画像を挿入（ペースト/D&D 可）')}
              <input
                ref={imageInputRef}
                type="file"
                accept="image/*"
                multiple
                style={{ display: 'none' }}
                onChange={handleImageInputChange}
              />
              {/* カーソルがテーブル内にある時だけ表示する行/列操作 */}
              {editor.isActive('table') && (
                <>
                  <span className="w-px h-5 bg-gray-300 mx-1" />
                  {tbBtn(false, () => editor.chain().focus().addRowAfter().run(), '行+', '下に行を追加')}
                  {tbBtn(false, () => editor.chain().focus().addColumnAfter().run(), '列+', '右に列を追加')}
                  {tbBtn(false, () => editor.chain().focus().deleteRow().run(), '行−', '行を削除')}
                  {tbBtn(false, () => editor.chain().focus().deleteColumn().run(), '列−', '列を削除')}
                  {tbBtn(false, () => editor.chain().focus().toggleHeaderRow().run(), 'Hヘッダ', 'ヘッダ行を切替')}
                  {tbBtn(false, () => editor.chain().focus().deleteTable().run(), '表削除', 'テーブルごと削除')}
                </>
              )}
              {/* 画像が選択されている時だけ表示する幅プリセット（小/中/大/原寸）。
                  幅は image の title 属性に "25%"|"50%"|"100%" として持たせ Markdown 往復する。 */}
              {editor.isActive('image') && (
                <>
                  <span className="w-px h-5 bg-gray-300 mx-1" />
                  <span className="text-[11px] text-gray-500 px-0.5 select-none">画像幅</span>
                  {tbBtn(editor.isActive('image', { title: '25%' }),  () => setImageWidth('25%'),  '小',   '画像幅 25%')}
                  {tbBtn(editor.isActive('image', { title: '50%' }),  () => setImageWidth('50%'),  '中',   '画像幅 50%')}
                  {tbBtn(editor.isActive('image', { title: '100%' }), () => setImageWidth('100%'), '大',   '画像幅 100%')}
                  {tbBtn(false, () => setImageWidth(null), '原寸', '原寸（サイズ指定を解除）')}
                  {selectedImageHref
                    ? tbBtn(false, () => window.open(selectedImageHref, '_blank', 'noopener,noreferrer'), '原寸を開く', 'Drive のオリジナル画像を新規タブで開く')
                    : null}
                </>
              )}
              <span className="w-px h-5 bg-gray-300 mx-1" />
              {tbBtn(false, () => editor.chain().focus().undo().run(), '↶', '元に戻す (Ctrl+Z)')}
              {tbBtn(false, () => editor.chain().focus().redo().run(), '↷', 'やり直し (Ctrl+Y / Ctrl+Shift+Z)')}
            </div>
            {/* 画像アップロード進捗バー: pending/error が残っている間だけ表示。success は3秒で自動消去。 */}
            {pendingUploads.length > 0 && (
              <div className="olive-tiptap-upload-status px-2 py-1.5 border-b border-gray-200 bg-amber-50/60 flex flex-col gap-1 text-xs">
                {pendingUploads.map((u) => (
                  <div key={u.id} className="flex items-center gap-2">
                    {u.status === 'pending' && (
                      <span className="inline-block w-3 h-3 border-2 border-amber-400 border-t-transparent rounded-full animate-spin shrink-0" />
                    )}
                    {u.status === 'success' && (
                      <Check size={14} className="text-green-600 shrink-0" />
                    )}
                    {u.status === 'error' && (
                      <XCircle size={14} className="text-red-600 shrink-0" />
                    )}
                    <span className="truncate flex-1 min-w-0 text-gray-800">
                      <span className="font-medium">{u.name}</span>
                      <span className="text-gray-500 ml-1">
                        {u.status === 'pending' && '— Drive にオリジナル保存中...（このまま閉じないでください）'}
                        {u.status === 'success' && '— Drive 保存完了。画像クリックで原本を別タブ表示できます'}
                        {u.status === 'error' && (u.kind === 'limit'
                          ? `— ${u.error || '画像を追加できませんでした'}`
                          : `— Drive 保存に失敗: ${u.error || '不明なエラー'}`)}
                      </span>
                    </span>
                    {u.status === 'error' && u.retry && (
                      <button
                        type="button"
                        onClick={u.retry}
                        className="px-2 py-0.5 text-xs rounded border border-red-300 text-red-700 bg-white hover:bg-red-50 shrink-0"
                      >再試行</button>
                    )}
                    {u.status !== 'pending' && (
                      <button
                        type="button"
                        onClick={() => handleDismissPendingUpload(u.id)}
                        title="この通知を消す"
                        className="px-1 text-gray-400 hover:text-gray-700 shrink-0"
                      >×</button>
                    )}
                  </div>
                ))}
              </div>
            )}
            <div className="olive-tiptap-content" style={{ minHeight: minHeight || '240px' }}>
              <EditorContent editor={editor} />
            </div>
            {/* @メンションサジェストドロップダウン（position:fixed でスクロール/overflow の影響を受けない） */}
            {mentionSuggest.active && mentionSuggest.rect && (
              <div
                className="fixed w-56 bg-white border border-gray-200 rounded-lg shadow-xl overflow-hidden"
                style={{ top: mentionSuggest.rect.top, left: mentionSuggest.rect.left, zIndex: 9999 }}
              >
                <div className="p-1.5 bg-olive-50 border-b border-gray-100 text-xs font-bold text-olive-800">
                  TabキーかEnterキーで選択
                </div>
                <ul className="max-h-48 overflow-y-auto">
                  {mentionSuggest.members.map((m, idx) => (
                    <li
                      key={m.email}
                      ref={(el) => { if (el && idx === mentionSuggest.selectedIndex) el.scrollIntoView({ block: 'nearest' }); }}
                      onMouseDown={(e) => { e.preventDefault(); insertMentionSuggestion(m); }}
                      className={`px-3 py-2 text-sm cursor-pointer flex items-center gap-2 ${idx === mentionSuggest.selectedIndex ? 'bg-olive-100 font-bold text-olive-900' : 'hover:bg-gray-50 text-gray-700'}`}
                    >
                      <span>{m.avatar}</span> {m.name}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {/* 画像選択時に画像のすぐ上へ出す幅メニュー（position:fixed）。
                BubbleMenu(tippy) は React の DOM 整合性を壊し insertBefore クラッシュを
                招いたため不採用。メンションサジェストと同じ自前 fixed 方式にする。
                座標は選択位置から coordsAtPos で都度算出（本コンポーネントは selection 変化で
                再レンダリングされる）。onMouseDown preventDefault で操作中も画像選択を保持。 */}
            {editor.isActive('image') && (() => {
              let coords = null;
              try { coords = editor.view.coordsAtPos(editor.state.selection.from); } catch (_) { coords = null; }
              if (!coords) return null;
              return (
                <div
                  className="fixed z-[9999] flex items-center gap-1 bg-white border border-gray-300 rounded-md shadow-lg px-1.5 py-1"
                  style={{ top: Math.max(8, coords.top - 44), left: coords.left }}
                  onMouseDown={(e) => e.preventDefault()}
                >
                  <span className="text-[11px] text-gray-500 px-0.5 select-none">画像幅</span>
                  {tbBtn(editor.isActive('image', { title: '25%' }),  () => setImageWidth('25%'),  '小',   '画像幅 25%')}
                  {tbBtn(editor.isActive('image', { title: '50%' }),  () => setImageWidth('50%'),  '中',   '画像幅 50%')}
                  {tbBtn(editor.isActive('image', { title: '100%' }), () => setImageWidth('100%'), '大',   '画像幅 100%')}
                  {tbBtn(false, () => setImageWidth(null), '原寸', '原寸（サイズ指定を解除）')}
                  {selectedImageHref
                    ? tbBtn(false, () => window.open(selectedImageHref, '_blank', 'noopener,noreferrer'), '原寸を開く', 'Drive のオリジナル画像を新規タブで開く')
                    : null}
                </div>
              );
            })()}
          </div>
        );
      }
    );

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
      findOrphanAttachments:      ()                 => callApi('findOrphanAttachments'),
      trashOrphanAttachments:     (ids)              => callApi('trashOrphanAttachments', { ids }),
      createDocument:             (title, parentId)         => callApi('createDocument', { title, parentId }),
      createFolder:               (title, parentId)         => callApi('createFolder',   { title, parentId }),
      moveDocument:               (fileId, newParentId)     => callApi('moveDocument',   { fileId, newParentId }),
      deleteDocument:             (fileId)                  => callApi('deleteDocument', { fileId }),
      syncDocumentsFromDrive:     ()                        => callApi('syncDocumentsFromDrive'),

      // ---- Wiki ----
      listWikiPages:        ()                                 => callApi('listWikiPages'),
      getWikiPage:          (id)                               => callApi('getWikiPage', { id }),
      saveWikiPage:         (payload)                          => callApi('saveWikiPage', payload),
      getWikiRevisions:     (pageId)                           => callApi('getWikiRevisions', { pageId }),
      getWikiRevisionDiff:  (pageId, revisionA, revisionB)     => callApi('getWikiRevisionDiff', { pageId, revisionA, revisionB }),
      restoreWikiRevision:  (pageId, revisionNo)               => callApi('restoreWikiRevision', { pageId, revisionNo }),
      deleteWikiPage:       (id)                               => callApi('deleteWikiPage', { id }),
      moveWikiPage:         (id, parentId, sortOrder)          => callApi('moveWikiPage', { id, parentId, sortOrder }),
      duplicateWikiPage:    (id)                                => callApi('duplicateWikiPage', { id }),

      // ---- Wiki 同時編集 (Yjs) ----
      getWikiYdoc:          (id)                                => callApi('getWikiYdoc', { id }),
      seedWikiYdoc:         (id, ydocState)                     => callApi('seedWikiYdoc', { id, ydocState }),
      saveWikiYdoc:         (id, ydocState)                     => callApi('saveWikiYdoc', { id, ydocState }),

      // ---- ホワイトボード（フリーボード） ----
      listWhiteboards:      ()                                 => callApi('listWhiteboards'),
      getWhiteboard:        (id)                               => callApi('getWhiteboard', { id }),
      saveWhiteboard:       (payload)                          => callApi('saveWhiteboard', payload),
      deleteWhiteboard:     (id)                               => callApi('deleteWhiteboard', { id }),
      analyzeWhiteboardImage:(payload)                         => callApi('analyzeWhiteboardImage', payload),

      // ---- AI仕様書（systemSpecForAI）取得 ----
      getSystemSpecForAI:          ()                            => callApi('getSystemSpecForAI'),

      // ---- AI ----
      gatherAiInformation:         (payload)                    => callApi('gatherAiInformation', payload),
      chatWithOliveAI:             (mode, history, taskContext, tasksContext = null, model = null) => callApi('chatWithOliveAI', { mode, history, taskContext, tasksContext, model }),
      generateTasksFromContext:    (payload)                    => callApi('generateTasksFromContext', payload),
      generateDocumentFromComment: (payload)                    => callApi('generateDocumentFromComment', payload),
      generateAndAppendReleaseNote:(payload)                    => callApi('generateAndAppendReleaseNote', payload),
      generateAdvisorDoc:          (task, history, formatPrompt) => callApi('generateAdvisorDoc', { task, history, formatPrompt }),
      generateImage:               (task, prompt, aspectRatio)   => callApi('generateImage',      { task, prompt, aspectRatio }),

      // ---- ユーザー別表示設定（要件2/3/4/6） ----
      saveUserPreference:    (key, value)                          => callApi('saveUserPreference',  { key, value }),

      // ---- フィルタープリセット（要件5） ----
      listFilterPresets:     ()                                    => callApi('listFilterPresets'),
      saveFilterPreset:      (preset)                              => callApi('saveFilterPreset',    preset),
      deleteFilterPreset:    (id)                                  => callApi('deleteFilterPreset',  { id }),

      // ---- リアルタイム同時編集（Pusher）----
      pusherAuth:            (socketId, channel)                   => callApi('pusherAuth', { socketId, channel }),
    };

    const fileToBase64 = (file) => new Promise((resolve, reject) => {
      const reader = new FileReader(); reader.readAsDataURL(file);
      reader.onload = () => resolve({ name: file.name, mimeType: file.type, data: reader.result.split(',')[1] });
      reader.onerror = error => reject(error);
    });

    // ============================================================
    // Pusher（リアルタイム同時編集）クライアント — 遅延ロードの単一インスタンス
    //   - pusher-js は importmap 経由で「実際に同時編集タブを開いたとき」だけ動的 import
    //     する（低スペック対策＝起動を軽く保つ）。
    //   - 認証は private/presence 用のカスタム authorizer → api.pusherAuth（サーバ署名）。
    //   - window.PUSHER.enabled が false（PUSHER_KEY 未設定）なら null を返し、呼び出し側は
    //     同期せず従来どおり単独編集＋DB保存にフォールバックする。
    //   - ホワイトボード/Wiki から共通参照（同一 <script> スコープ）。
    // ============================================================
    let __pusherInstance = null;
    let __pusherLoading  = null;
    const ensurePusher = async () => {
      if (!window.PUSHER || !window.PUSHER.enabled) return null;   // 同期無効＝フォールバック
      if (__pusherInstance) return __pusherInstance;
      if (__pusherLoading) return __pusherLoading;
      __pusherLoading = (async () => {
        const mod = await import('pusher-js');
        const Pusher = (mod && mod.default) ? mod.default : mod;
        __pusherInstance = new Pusher(window.PUSHER.key, {
          cluster: window.PUSHER.cluster,
          forceTLS: true,
          // カスタム authorizer: private/presence の購読要求をサーバ署名(api.pusherAuth)で許可。
          authorizer: (channel) => ({
            authorize: (socketId, callback) => {
              api.pusherAuth(socketId, channel.name)
                .then((data) => callback(null, data))
                .catch((err) => callback(err, null));
            },
          }),
        });
        return __pusherInstance;
      })();
      try { return await __pusherLoading; }
      finally { __pusherLoading = null; }
    };

    // marked.parse の出力 HTML に画像幅トークンを反映する後処理。
    //   RichMarkdownEditor は画像の幅を <img title="50%"> として Markdown に保存する
    //   （素の Markdown に幅構文が無いため title フィールドを流用）。marked はこの title を
    //   そのまま title 属性として出すので、ここで title="NN%" を style:width に変換し、
    //   tooltip としては出さないようにする。PDF/印刷/プレビューでもエディタと同じ幅で表示
    //   するための共通ユーティリティ（App.html / TaskModal.html / WikiView.html /
    //   MarkdownPreview.html から参照）。
    const applyImageWidthTokens = (html) => {
      if (!html || html.indexOf('title=') === -1) return html;
      return html.replace(/<img\b[^>]*>/gi, (tag) => {
        const m = tag.match(/title="(\d{1,3}%)"/i);
        if (!m) return tag;                       // 幅トークン以外の title はそのまま
        const w = m[1];
        let out = tag.replace(/\s*title="\d{1,3}%"/i, '');   // tooltip トークンを除去
        if (/\sstyle="/i.test(out)) {
          out = out.replace(/style="([^"]*)"/i, (s, css) => `style="${css};width:${w}"`);
        } else {
          out = out.replace(/<img\b/i, `<img style="width:${w}"`);
        }
        return out;
      });
    };

    // ============================================================
    // AI モデル選択肢（タスクアドバイザー / コンシェルジュ「表示中の課題」用）
    //   value はそのまま api.chatWithOliveAI(..., model) で送られ、サーバ側
    //   OLIVE_AI_MODELS レジストリで検証・解決される。各 value は必ず OLIVE_AI_MODELS の
    //   キーに含めること（含まれないとサーバで fallback に落ち選択が効かない）。
    //   ※ 逆は不可ではない: gemini-2.5-flash は「使い方」モードの固定値用にサーバ側のみ存在し、
    //     ここ（選択肢）には出さない。つまり options はレジストリの部分集合でよい。
    //   App.html / TaskModal.html から共通参照（同一 <script> スコープ）。
    // ============================================================
    const AI_MODEL_OPTIONS = [
      { value: 'gemini-3.5-flash',  label: 'Gemini 3.5 Flash' },
      { value: 'gemini-2.5-pro',    label: 'Gemini 2.5 Pro' },
      // --- third-party (Claude) は G-gen 契約確認 OK で再有効化（2026-06-08）---
      //   バックエンド（OLIVE_AI_MODELS / callVertexClaude / callOliveAiModel）と同期。
      // Opus 4.8 は Vertex のモデル別クォータが未付与で 429（RESOURCE_EXHAUSTED）になるため
      //   G-gen にクォータ増枠を申請中。枠が付くまで選択肢から一時非表示（2026-06-08）。
      //   バックエンドの OLIVE_AI_MODELS 側は休眠残置（このコメントを外せば即復活）。
      // { value: 'claude-opus-4-8',   label: 'Claude Opus 4.8' },
      { value: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6' },
      { value: 'claude-haiku-4-5',  label: 'Claude Haiku 4.5' },
    ];
    const AI_MODEL_DEFAULT = 'gemini-2.5-pro';
    // 永続prefs（userPrefs.aiModelAdvisor / aiModelConciergeTasks）に、現在は選択肢に無い
    // モデル（例: 一時無効化した claude-*）が残っていても、表示中の AI_MODEL_OPTIONS に
    // 含まれない値は既定へ丸める。UIから断った provider をサーバへ漏らさないためのガード。
    const sanitizeAiModel = (v) => AI_MODEL_OPTIONS.some(o => o.value === v) ? v : AI_MODEL_DEFAULT;

    // ============================================================
    // 課題の CSV エクスポート（一覧/ボードのフィルタ済み課題・個別課題で共用）
    //   - コメント・画像（添付の実体）は出力しない。添付はファイル名のみ。
    //   - description は保存済みの Markdown 文字列をそのまま出力する。
    //   - Excel(日本語環境)で文字化けしないよう UTF-8 BOM を付与し、改行は CRLF。
    //   App.html / TaskModal.html から共通参照（同一 <script> スコープ・STATUSES/PRIORITIES 参照）。
    // ============================================================
    const CSV_EXPORT_COLUMNS = [
      { key: 'id',                 label: '課題ID' },
      { key: 'title',              label: '課題名' },
      { key: 'status',             label: 'ステータス' },
      { key: 'priority',           label: '優先度' },
      { key: 'type',               label: '種別' },
      { key: 'category',           label: 'カテゴリ' },
      { key: 'parentId',           label: '親課題ID' },
      { key: 'assigneeName',       label: '担当者' },
      { key: 'assigneeEmail',      label: '担当者メール' },
      { key: 'subAssignees',       label: 'サブ担当者' },
      { key: 'startDate',          label: '開始日' },
      { key: 'dueDate',            label: '期限日' },
      { key: 'implementationDate', label: '実施日' },
      { key: 'implementationDays', label: '実施日数' },
      { key: 'likes',              label: 'いいね数' },
      { key: 'attachments',        label: '添付ファイル名' },
      { key: 'createdAt',          label: '作成日時' },
      { key: 'updatedAt',          label: '更新日時' },
      { key: 'description',        label: '詳細(Markdown)' },
    ];

    // CSV セル1個をエスケープ。改行/カンマ/" を含む値は " で囲み、内部の " は2重化。
    const csvEscapeCell = (val) => {
      const s = (val == null) ? '' : String(val);
      return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };

    // 1課題 → CSV 1行ぶんの配列（CSV_EXPORT_COLUMNS の順）。
    const taskToCsvRow = (task, memberNameByEmail) => {
      const statusLabel   = (STATUSES.find(s => s.id === task.status)     || {}).label ?? (task.status   || '');
      const priorityLabel = (PRIORITIES.find(p => p.id === task.priority) || {}).label ?? (task.priority || '');
      const subNames    = (task.subAssignees || []).map(e => memberNameByEmail.get(e) || e).join(' / ');
      const attachNames = (task.attachments  || []).map(a => a && a.name).filter(Boolean).join(' / ');
      return CSV_EXPORT_COLUMNS.map(col => {
        switch (col.key) {
          case 'status':       return statusLabel;
          case 'priority':     return priorityLabel;
          case 'subAssignees': return subNames;
          case 'likes':        return (task.likes || []).length;
          case 'attachments':  return attachNames;
          default:             return task[col.key] != null ? task[col.key] : '';
        }
      });
    };

    const buildTasksCsv = (tasks, memberNameByEmail) => {
      const header = CSV_EXPORT_COLUMNS.map(c => c.label);
      const rows = (tasks || []).map(t => taskToCsvRow(t, memberNameByEmail));
      return [header, ...rows].map(cells => cells.map(csvEscapeCell).join(',')).join('\r\n');
    };

    // ファイル名に使えない文字を除去（課題名をファイル名に使うケース用）。
    const sanitizeFilename = (name) => String(name || '').replace(/[\\\/:*?"<>|\r\n]+/g, '_').slice(0, 80);

    // 課題（配列 or 単体）を CSV としてダウンロードさせる。
    //   options: { members?: [{email,name}], filename?: string }
    const downloadTasksCsv = (tasks, options = {}) => {
      const list = Array.isArray(tasks) ? tasks : [tasks];
      const memberNameByEmail = new Map((options.members || []).map(m => [m.email, m.name]));
      const csv = buildTasksCsv(list, memberNameByEmail);
      // 先頭に UTF-8 BOM (U+FEFF) を付与 → Excel(日本語環境) で文字化けを防ぐ
      const BOM = String.fromCharCode(0xFEFF);
      const blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = options.filename || ('olivenote_tasks_' + new Date().toLocaleDateString('sv-SE') + '.csv');
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    <?php readfile(__DIR__ . '/App.html'); ?>
    <?php readfile(__DIR__ . '/BoardView.html'); ?>
    <?php readfile(__DIR__ . '/ListView.html'); ?>
    <?php readfile(__DIR__ . '/TaskModal.html'); ?>
    <?php readfile(__DIR__ . '/TaskAutoGenerateModal.html'); ?>
    <?php readfile(__DIR__ . '/GanttView.html'); ?>
    <?php readfile(__DIR__ . '/CalendarView.html'); ?>
    <?php readfile(__DIR__ . '/FilesView.html'); ?>
    <?php readfile(__DIR__ . '/WikiView.html'); ?>
    <?php readfile(__DIR__ . '/WhiteboardView.html'); ?>
    <?php readfile(__DIR__ . '/SettingsView.html'); ?>
    <?php readfile(__DIR__ . '/MarkdownPreview.html'); ?>
    const root = createRoot(document.getElementById('root'));
    root.render(<App />);
  </script>
</body>
</html>
