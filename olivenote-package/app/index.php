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
        "react-dom/client": "https://esm.sh/react-dom@18.2.0/client",
        "lucide-react": "https://esm.sh/lucide-react@0.292.0?deps=react@18.2.0",
        "@tiptap/react": "https://esm.sh/@tiptap/react@2.10.3?deps=react@18.2.0,react-dom@18.2.0",
        "@tiptap/starter-kit": "https://esm.sh/@tiptap/starter-kit@2.10.3",
        "@tiptap/extension-link": "https://esm.sh/@tiptap/extension-link@2.10.3",
        "@tiptap/extension-task-list": "https://esm.sh/@tiptap/extension-task-list@2.10.3",
        "@tiptap/extension-task-item": "https://esm.sh/@tiptap/extension-task-item@2.10.3",
        "@tiptap/extension-placeholder": "https://esm.sh/@tiptap/extension-placeholder@2.10.3",
        "@tiptap/extension-table": "https://esm.sh/@tiptap/extension-table@2.10.3",
        "@tiptap/extension-table-row": "https://esm.sh/@tiptap/extension-table-row@2.10.3",
        "@tiptap/extension-table-header": "https://esm.sh/@tiptap/extension-table-header@2.10.3",
        "@tiptap/extension-table-cell": "https://esm.sh/@tiptap/extension-table-cell@2.10.3",
        "@tiptap/extension-image": "https://esm.sh/@tiptap/extension-image@2.10.3",
        "tiptap-markdown": "https://esm.sh/tiptap-markdown@0.8.10"
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
  <!-- Floating UI: ドロップダウン等の自動配置（flip / shift）。window.FloatingUIDOM として展開される -->
  <script src="https://cdn.jsdelivr.net/npm/@floating-ui/core@1.6.8"></script>
  <script src="https://cdn.jsdelivr.net/npm/@floating-ui/dom@1.6.13"></script>
  <!-- TipTap (ProseMirror) は importmap 経由で ESM 読み込み。CSS は <style> ブロック内に手書きで定義。 -->
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
      /* リンクラップされた画像（オリジナルが Drive にある状態）はクリック可能を示す */
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link > img.olive-tiptap-image {
        cursor: zoom-in;
        transition: opacity 120ms ease;
      }
      .olive-tiptap-content .ProseMirror a.olive-tiptap-link > img.olive-tiptap-image:hover {
        opacity: 0.85;
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
      Bookmark, Minimize2, Rows, ArrowUp, ArrowDown, ArrowUpDown
    } from 'lucide-react';

    // ===== TipTap (ProseMirror) — description 用 WYSIWYG エディタ =====
    import { useEditor, EditorContent } from '@tiptap/react';
    import StarterKit from '@tiptap/starter-kit';
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
      ({ value, onChange, placeholder, disabled, minHeight }, ref) => {
        const lastEmittedRef = useRef(value || '');
        // onChange は親が render のたびに新規生成し得る → ref で常に最新版を参照
        const onChangeRef = useRef(onChange);
        useEffect(() => { onChangeRef.current = onChange; }, [onChange]);
        // 画像アップロード関数: editor / api を参照するため、ref 経由で editorProps(paste/drop) から呼ぶ
        const uploadAndInsertImageRef = useRef(null);
        const imageInputRef = useRef(null);
        // 進行中・完了・失敗のアップロード状況を可視化（ツールバー直下のステータスバーで表示）
        // shape: [{ id, name, status: 'pending'|'success'|'error', error?: string, retry?: () => void }]
        const [pendingUploads, setPendingUploads] = useState([]);

        const editor = useEditor({
          extensions: [
            StarterKit.configure({
              // StarterKit には Link が含まれないので、別途 TipTapLink で追加
              heading: { levels: [1, 2, 3, 4] },
              codeBlock: { HTMLAttributes: { class: 'olive-tiptap-codeblock' } },
            }),
            TipTapLink.configure({
              openOnClick: false,
              autolink: true,
              linkOnPaste: true,
              HTMLAttributes: {
                class: 'olive-tiptap-link',
                rel: 'noopener noreferrer',
                target: '_blank',
              },
            }),
            TaskList,
            TaskItem.configure({ nested: true }),
            Placeholder.configure({
              placeholder: placeholder || '課題の詳細を入力...（Markdown が使えます）',
            }),
            TipTapTable.configure({ resizable: true, HTMLAttributes: { class: 'olive-tiptap-table' } }),
            TipTapTableRow,
            TipTapTableHeader,
            TipTapTableCell,
            TipTapImage.configure({
              // インライン化することで Link マークが画像に直接乗る
              //   → [![alt](data:...)](href) としてマークダウン往復可能
              //   → クリックでオリジナル Drive ファイルを開く動線が作れる
              inline: true,
              // 圧縮版を data:image/...;base64,... のまま埋め込むため許可必須
              allowBase64: true,
              HTMLAttributes: { class: 'olive-tiptap-image' },
            }),
            Markdown.configure({
              html: false,
              tightLists: true,
              bulletListMarker: '-',
              linkify: true,
              breaks: true,
              transformPastedText: true,
              transformCopiedText: true,
            }),
          ],
          content: value || '',
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
            // 画像クリック: Drive 上のオリジナル(高画質)を別タブで開く
            // 編集中はノード選択を妨げないよう、リンクが pending でない場合のみ動作
            handleClickOn: (view, pos, node, nodePos, event) => {
              if (node.type.name !== 'image') return false;
              const linkMark = node.marks.find(m => m.type.name === 'link');
              if (!linkMark) return false;
              const href = linkMark.attrs && linkMark.attrs.href;
              if (!href || href.startsWith('#pending-')) return false;
              event.preventDefault();
              window.open(href, '_blank', 'noopener,noreferrer');
              return true;
            },
          },
          onUpdate: ({ editor }) => {
            const md = editor.storage.markdown.getMarkdown();
            if (md === lastEmittedRef.current) return;
            lastEmittedRef.current = md;
            const cb = onChangeRef.current;
            if (typeof cb === 'function') cb(md);
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

        // 親から value が変わった時のみ再同期（自分が emit した直後・編集中はスキップ）
        useEffect(() => {
          if (!editor) return;
          const incoming = value || '';
          if (incoming === lastEmittedRef.current) return;
          // 編集中（フォーカス中）は setContent しない → undo 履歴と入力中状態を保護
          if (editor.isFocused) return;
          lastEmittedRef.current = incoming;
          editor.commands.setContent(incoming, false);
          // setContent 後に走る: 残存 #pending-xxx を救済除去
          cleanupStalePendingLinks();
        }, [value, editor]);

        // エディタ初回マウント時にも残存 #pending-xxx を除去する
        // （useEditor の `content` オプションで初期値が入った時点で走らせたい）
        useEffect(() => {
          if (!editor) return;
          cleanupStalePendingLinks();
          // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [editor]);

        // disabled 反映
        useEffect(() => {
          if (!editor) return;
          editor.setEditable(!disabled);
        }, [editor, disabled]);

        React.useImperativeHandle(ref, () => ({
          getMarkdown: () => editor?.storage.markdown.getMarkdown() || '',
          setMarkdown: (md) => {
            if (!editor) return;
            lastEmittedRef.current = md || '';
            editor.commands.setContent(md || '', false);
          },
          insertTemplate: (md) => {
            if (!editor) return;
            const current = editor.storage.markdown.getMarkdown();
            const sep = current && !/\n\n$/.test(current) ? (current.endsWith('\n') ? '\n' : '\n\n') : '';
            const next = current + sep + md;
            lastEmittedRef.current = next;
            editor.commands.setContent(next, false);
            editor.commands.focus('end');
            const cb = onChangeRef.current;
            if (typeof cb === 'function') cb(next);
          },
          focus: () => editor?.commands.focus(),
        }), [editor]);

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
              const base64Data = await fileToBase64(file);
              const res = await api.uploadFile(base64Data);
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
                        {u.status === 'error' && `— Drive 保存に失敗: ${u.error || '不明なエラー'}`}
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

      // ---- ユーザー別表示設定（要件2/3/4/6） ----
      saveUserPreference:    (key, value)                          => callApi('saveUserPreference',  { key, value }),

      // ---- フィルタープリセット（要件5） ----
      listFilterPresets:     ()                                    => callApi('listFilterPresets'),
      saveFilterPreset:      (preset)                              => callApi('saveFilterPreset',    preset),
      deleteFilterPreset:    (id)                                  => callApi('deleteFilterPreset',  { id }),
    };

    const fileToBase64 = (file) => new Promise((resolve, reject) => {
      const reader = new FileReader(); reader.readAsDataURL(file);
      reader.onload = () => resolve({ name: file.name, mimeType: file.type, data: reader.result.split(',')[1] });
      reader.onerror = error => reject(error);
    });

    <?php readfile(__DIR__ . '/App.html'); ?>
    <?php readfile(__DIR__ . '/BoardView.html'); ?>
    <?php readfile(__DIR__ . '/ListView.html'); ?>
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
