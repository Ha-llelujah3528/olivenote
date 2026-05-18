<?php
require_once __DIR__ . '/lib/bootstrap.php';
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
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Olive Note - ログイン</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="icon" type="image/png" href="favicon.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="bg-gradient-to-br from-emerald-50 to-blue-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl p-10 w-full max-w-md text-center">
    <div class="flex items-center justify-center gap-2 mb-2">
      <span class="text-3xl">🌿</span>
      <h1 class="text-3xl font-bold text-gray-800">Olive Note</h1>
    </div>
    <p class="text-gray-500 text-sm mb-8">タスク管理ツール</p>

    <p class="text-gray-700 text-sm mb-6">
      登録されているGoogleアカウントでログインしてください。
    </p>

    <div class="flex justify-center mb-4">
      <div id="g_id_onload"
           data-client_id="<?= htmlspecialchars(GOOGLE_CLIENT_ID, ENT_QUOTES) ?>"
           data-callback="handleCredentialResponse"
           data-auto_select="false"
           data-ux_mode="popup"></div>
      <div class="g_id_signin"
           data-type="standard"
           data-shape="rectangular"
           data-theme="filled_blue"
           data-text="signin_with"
           data-size="large"
           data-locale="ja"
           data-logo_alignment="left"></div>
    </div>

    <div id="error-message" class="hidden mt-4 p-3 bg-red-50 text-red-700 text-sm rounded-lg border border-red-200"></div>
    <div id="loading" class="hidden mt-4 text-gray-500 text-sm">ログイン中...</div>

    <p class="text-gray-400 text-xs mt-8">
      ※ アクセスには事前のメンバー登録が必要です
    </p>
  </div>

  <script>
    async function handleCredentialResponse(response) {
      const errEl  = document.getElementById('error-message');
      const loadEl = document.getElementById('loading');
      errEl.classList.add('hidden');
      loadEl.classList.remove('hidden');

      try {
        const res = await fetch('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'verifyGoogleAuth', payload: { idToken: response.credential } })
        });
        const json = await res.json();
        if (json.success) {
          // 認証成功 → メイン画面へ
          window.location.reload();
        } else {
          loadEl.classList.add('hidden');
          errEl.textContent = json.error || 'ログインに失敗しました';
          errEl.classList.remove('hidden');
        }
      } catch (e) {
        loadEl.classList.add('hidden');
        errEl.textContent = 'ネットワークエラー: ' + e.message;
        errEl.classList.remove('hidden');
      }
    }
  </script>
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
    // GAS版との互換性のため残す（使用しない）
    let INJECTED_DATA = null;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <!-- SheetJS (Excel/CSV パース): AI課題生成モーダルで使用。globalThis.XLSX として展開される -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
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
    </style>
</head>
<body class="bg-[#f0f2f5] m-0 p-0 overflow-hidden h-screen">
  
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
      UploadCloud, Check, Edit3, Table
    } from 'lucide-react';

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