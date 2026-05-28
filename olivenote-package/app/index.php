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
  <!-- Floating UI: ドロップダウン等の自動配置（flip / shift）。window.FloatingUIDOM として展開される -->
  <script src="https://cdn.jsdelivr.net/npm/@floating-ui/core@1.6.8"></script>
  <script src="https://cdn.jsdelivr.net/npm/@floating-ui/dom@1.6.13"></script>
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
