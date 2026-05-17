<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/lib/bootstrap.php';
// セッション開始（HTTPS環境前提でセキュアCookieを使用）
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $input['action'] ?? '';
$payload = $input['payload'] ?? [];

// =====================================================
// 認証ガード: ログイン不要のアクション以外はセッション必須
// =====================================================
$publicActions = ['verifyGoogleAuth', 'logout'];
if (!in_array($action, $publicActions, true)) {
    if (empty($_SESSION['user_email'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required', 'authRequired' => true]);
        exit;
    }
}

// ================================================================
// Google Drive API ヘルパー
// ================================================================
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getGoogleAccessToken(
    string $scope = 'https://www.googleapis.com/auth/drive.file',
    ?string $clientEmail = null,
    ?string $privateKey = null
): string {
    $clientEmail = $clientEmail ?? CLIENT_EMAIL;
    $privateKey  = $privateKey  ?? PRIVATE_KEY;

    $now     = time();
    $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $clientEmail,
        'scope' => $scope,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $signingInput = $header . '.' . $payload;
    // config.php で PRIVATE_KEY をシングルクォート定義しているため、\n はリテラルのまま。
    // openssl_pkey_get_private は実改行のPEM形式しか受け付けないので変換する。
    $pem        = str_replace('\n', "\n", $privateKey);
    $privateKey = openssl_pkey_get_private($pem);
    if ($privateKey === false) {
        throw new Exception('PRIVATE_KEY のパースに失敗しました: ' . openssl_error_string());
    }
    if (!openssl_sign($signingInput, $signature, $privateKey, 'SHA256')) {
        throw new Exception('JWT署名の生成に失敗しました: ' . openssl_error_string());
    }
    $jwt = $signingInput . '.' . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    if (empty($json['access_token'])) {
        throw new Exception('Google認証失敗: ' . ($json['error_description'] ?? $res));
    }
    return $json['access_token'];
}

function uploadFileToDrive(string $name, string $mimeType, string $binary, string $folderId, string $token): array {
    $meta     = json_encode(['name' => $name, 'parents' => [$folderId]]);
    $boundary = 'olivenote_' . uniqid();
    $body     = "--{$boundary}\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . $meta . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: {$mimeType}\r\n\r\n"
              . $binary . "\r\n"
              . "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Content-Type: multipart/related; boundary={$boundary}",
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true);
    if ($code !== 200 || empty($json['id'])) {
        throw new Exception('Drive APIエラー: ' . $res);
    }
    return $json;
}

function createGoogleDoc(string $title, string $folderId, string $token): array {
    $body = json_encode([
        'name'     => $title,
        'mimeType' => 'application/vnd.google-apps.document',
        'parents'  => [$folderId],
    ]);
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id,name,webViewLink,modifiedTime');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true);
    if ($code !== 200 || empty($json['id'])) {
        throw new Exception('Drive APIエラー (createDoc): ' . $res);
    }
    return $json;
}

function createDriveFolder(string $title, string $parentId, string $token): array {
    $body = json_encode([
        'name'     => $title,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents'  => [$parentId],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id,name,webViewLink,modifiedTime');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
            'Expect:',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true);
    if ($code !== 200 || empty($json['id'])) {
        throw new Exception('Drive APIエラー (createFolder): ' . $res);
    }
    return $json;
}

// あるフォルダ配下の生存している子孫IDを再帰的に列挙する（ソフトデリート時のカスケード用）
function collectDescendantIds(PDO $pdo, string $folderId): array {
    $ids   = [];
    $stack = [$folderId];
    $stmt  = $pdo->prepare("SELECT id, mime_type FROM documents WHERE parent_id = ? AND deleted_at IS NULL");
    while (!empty($stack)) {
        $current = array_pop($stack);
        $stmt->execute([$current]);
        while ($row = $stmt->fetch()) {
            $ids[] = $row['id'];
            if (($row['mime_type'] ?? '') === 'application/vnd.google-apps.folder') {
                $stack[] = $row['id'];
            }
        }
    }
    return $ids;
}

function trashDriveFile(string $fileId, string $token): void {
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?supportsAllDrives=true");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode(['trashed' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function docFromRow(array $row): array {
    $mime = $row['mime_type'] ?? '';
    return [
        'id'          => $row['id'],
        'name'        => $row['name'],
        'url'         => $row['url'],
        'parentId'    => $row['parent_id'] ?? null,
        'mimeType'    => $mime,
        'isFolder'    => $mime === 'application/vnd.google-apps.folder',
        'lastUpdated' => date('Y/m/d H:i', strtotime($row['last_updated'])),
    ];
}

// セッションのユーザーが管理者かを確認し、そうでなければ 403 で終了する
function requireAdmin(PDO $pdo): void {
    $email = $_SESSION['user_email'] ?? '';
    if ($email === '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '認証が必要です。']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT is_admin FROM members WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !$row['is_admin']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'この操作は管理者のみ実行できます。']);
        exit;
    }
}

function makeFilePublic(string $fileId, string $token): void {
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}/permissions");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['role' => 'reader', 'type' => 'anyone']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ================================================================
// Vertex AI ヘルパー
// ================================================================
function callVertexAi(string $modelId, array $apiPayload): string {
    $token = getGoogleAccessToken(
        'https://www.googleapis.com/auth/cloud-platform',
        VERTEX_CLIENT_EMAIL,
        VERTEX_PRIVATE_KEY
    );
    $location  = VERTEX_LOCATION;
    $projectId = VERTEX_PROJECT_ID;
    $url = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models/{$modelId}:generateContent";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($apiPayload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
            // 大きいPOSTでcURLが付ける Expect: 100-continue が GoogleのフロントエンドCDNで弾かれて
            // HTTP 417 + bot検出ページが返るのを防ぐため、空ヘッダで抑止
            'Expect:',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new Exception("Vertex AIエラー (HTTP {$code}): " . $res);
    $json = json_decode($res, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') throw new Exception('AIから有効なテキストが返ってきませんでした。');
    return $text;
}

// ================================================================
// Google Docs / Drive Doc ヘルパー（generateAndAppendReleaseNote用）
// ================================================================

// Drive APIでGoogle Docをtext/plainに変換して取得
function exportDriveDocAsText(string $docId, string $token): string {
    $url = "https://www.googleapis.com/drive/v3/files/{$docId}/export?mimeType=" . urlencode('text/plain') . "&supportsAllDrives=true";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("Doc export エラー (HTTP {$code}): " . $res);
    return $res;
}

// Docs API documents.get でドキュメント構造を取得
function docsApiGet(string $docId, string $token): array {
    $ch = curl_init("https://docs.googleapis.com/v1/documents/{$docId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("Docs get エラー (HTTP {$code}): " . $res);
    return json_decode($res, true);
}

function docsApiBatchUpdate(string $docId, array $requests, string $token): void {
    $body = json_encode(['requests' => $requests], JSON_UNESCAPED_UNICODE);
    $ch = curl_init("https://docs.googleapis.com/v1/documents/{$docId}:batchUpdate");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
            'Expect:',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("Docs batchUpdate エラー (HTTP {$code}): " . $res);
}

// Docs APIはUTF-16コードユニット単位でindex指定するため、PHP文字列をUTF-16単位の長さに換算
function utf16Len(string $s): int {
    if ($s === '') return 0;
    return strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) >> 1;
}

// 指定文字列を含む段落のendIndexを返す。見つからなければnull
function findParagraphEndIndexContaining(array $doc, string $needle): ?int {
    foreach ($doc['body']['content'] ?? [] as $el) {
        if (!isset($el['paragraph'])) continue;
        $text = '';
        foreach ($el['paragraph']['elements'] ?? [] as $sub) {
            if (isset($sub['textRun']['content'])) {
                $text .= $sub['textRun']['content'];
            }
        }
        if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
            return $el['endIndex'] ?? null;
        }
    }
    return null;
}

// 本文末尾のindex（最後のelementのendIndex）を返す
function getDocBodyEndIndex(array $doc): int {
    $end = 1;
    foreach ($doc['body']['content'] ?? [] as $el) {
        if (isset($el['endIndex'])) {
            $end = max($end, (int)$el['endIndex']);
        }
    }
    return $end;
}

// プロンプト中のGoogle Drive URLを検出し、サービスアカウントが読める範囲で本文を抽出して追記
function appendDriveFileDataToPrompt(string $promptText): string {
    if ($promptText === '') return '';
    $regex = '#(?:https://docs\.google\.com/(?:document|spreadsheets|presentation)/d/|https://drive\.google\.com/(?:file/d/|open\?id=))([-\w]{25,})#';
    if (!preg_match_all($regex, $promptText, $matches)) return '';

    $token      = getGoogleAccessToken('https://www.googleapis.com/auth/drive.readonly');
    $additional = '';
    $count      = 1;
    $processed  = [];

    foreach ($matches[1] as $fileId) {
        if (isset($processed[$fileId])) continue;
        $processed[$fileId] = true;
        try {
            // メタデータ取得
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?supportsAllDrives=true&fields=id,name,mimeType");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            ]);
            $metaRes  = curl_exec($ch);
            $metaCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($metaCode !== 200) continue;
            $meta = json_decode($metaRes, true);

            $mimeType = $meta['mimeType'] ?? '';
            $exportMap = [
                'application/vnd.google-apps.document'     => 'text/plain',
                'application/vnd.google-apps.spreadsheet'  => 'text/csv',
                'application/vnd.google-apps.presentation' => 'text/plain',
            ];
            if (isset($exportMap[$mimeType])) {
                $url = "https://www.googleapis.com/drive/v3/files/{$fileId}/export?mimeType=" . urlencode($exportMap[$mimeType]) . "&supportsAllDrives=true";
            } elseif ($mimeType === 'text/plain' || $mimeType === 'text/csv') {
                $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&supportsAllDrives=true";
            } else {
                continue;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            ]);
            $textData = curl_exec($ch);
            $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || $textData === false) continue;

            if (mb_strlen($textData) > 15000) {
                $textData = mb_substr($textData, 0, 15000) . "\n...（データ量が多すぎるため以降省略）";
            }
            $additional .= "\n\n【参考資料{$count}（自動取得：" . ($meta['name'] ?? '') . "）】\n" . $textData;
            $count++;
        } catch (Throwable $e) {
            // 個別ファイルの読み込み失敗は黙ってスキップ
        }
    }
    return $additional;
}

// ================================================================
// ヘルパー関数
// ================================================================

function taskFromRow(array $row, array $comments = []): array {
    return [
        'id'                 => $row['id'],
        'title'              => $row['title'],
        'description'        => $row['description'] ?? '',
        'status'             => $row['status'],
        'priority'           => $row['priority'],
        'type'               => $row['type'] ?? '',
        'category'           => $row['category'] ?? '',
        'parentId'           => $row['parent_id'],
        'startDate'          => $row['start_date'],
        'dueDate'            => $row['due_date'],
        'implementationDate' => $row['implementation_date'],
        'implementationDays' => (int)($row['implementation_days'] ?? 1),
        'assigneeEmail'      => $row['assignee_email'] ?? '',
        'assigneeName'       => $row['assignee_name'] ?? '',
        'subAssignees'       => json_decode($row['sub_assignees'] ?? '[]', true) ?: [],
        'likes'              => json_decode($row['likes'] ?? '[]', true) ?: [],
        'attachments'        => json_decode($row['attachments'] ?? '[]', true) ?: [],
        'order'              => (float)($row['sort_order'] ?? 0),
        'updatedAt'          => $row['updated_at'] ?? '',
        'comments'           => $comments,
    ];
}

function commentFromRow(array $row): array {
    return [
        'id'          => $row['id'],
        'taskId'      => $row['task_id'],
        'authorEmail' => $row['author_email'],
        'authorName'  => $row['author_name'],
        'text'        => $row['text'],
        'createdAt'   => date('n月j日 H:i', strtotime($row['created_at'])),
        'likes'       => json_decode($row['likes'] ?? '[]', true) ?: [],
        'readBy'      => json_decode($row['read_by'] ?? '[]', true) ?: [],
    ];
}

function notifFromRow(array $row): array {
    return [
        'id'          => $row['id'],
        'targetEmail' => $row['target_email'],
        'senderName'  => $row['sender_name'],
        'taskId'      => $row['task_id'],
        'taskTitle'   => $row['task_title'],
        'message'     => $row['message'],
        'isRead'      => (bool)$row['is_read'],
        'createdAt'   => $row['created_at'],
    ];
}

// タスクIDを採番してDBのlastTaskIdカウンタを更新する（トランザクション付き）
// フォーマット: TASK-XXXX（4桁ゼロ埋め、GAS版踏襲）
function assignNextTaskId(PDO $pdo): string {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'lastTaskId' FOR UPDATE");
        $last = (int)($stmt->fetchColumn() ?: 0);

        // settings側に値が無い/0の場合はtasksテーブルから現行最大番号を拾う（GAS移行直後の保険）
        if ($last === 0) {
            $s2   = $pdo->query("SELECT MAX(CAST(REPLACE(id,'TASK-','') AS UNSIGNED)) FROM tasks WHERE id REGEXP '^TASK-[0-9]+$'");
            $last = (int)($s2->fetchColumn() ?: 0);
        }

        $next = $last + 1;
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('lastTaskId',?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$next, $next]);

        $pdo->commit();
        return 'TASK-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ================================================================
// メインルーティング
// ================================================================

try {
    switch ($action) {

        // ============================================================
        // getInitialData — 起動時に必要な全データをまとめて返す
        // ============================================================
        case 'getInitialData':
            // Settings
            $stmt     = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $settings = [];
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
            }

            // Members（is_admin DESC で管理者を先頭に）
            $stmt        = $pdo->query("SELECT * FROM members ORDER BY is_admin DESC, email");
            $members     = [];
            $sessionEmail = $_SESSION['user_email'] ?? '';
            $currentUser = null;
            while ($row = $stmt->fetch()) {
                $m = [
                    'email'           => $row['email'],
                    'name'            => $row['name'],
                    'avatar'          => $row['avatar'],
                    'isAdmin'         => (bool)$row['is_admin'],
                    'defaultCategory' => $row['default_category'],
                ];
                $members[] = $m;
                // セッションのemailと完全一致するメンバーをcurrentUserとする
                if ($sessionEmail && $row['email'] === $sessionEmail) {
                    $currentUser = $m;
                }
            }
            // セッションは認証ガードで通過済みのはずだが、念のためフォールバック
            if (!$currentUser && count($members) > 0) $currentUser = $members[0];
            if (!$currentUser) $currentUser = ['email' => 'guest@example.com', 'name' => 'Guest', 'avatar' => '👤', 'isAdmin' => false, 'defaultCategory' => ''];

            // Comments（全件取得してタスクに紐づける）
            $stmt           = $pdo->query("SELECT * FROM comments ORDER BY created_at ASC");
            $commentsByTask = [];
            while ($row = $stmt->fetch()) {
                $commentsByTask[$row['task_id']][] = commentFromRow($row);
            }

            // Tasks（削除されていないもの）
            $stmt  = $pdo->query("SELECT * FROM tasks WHERE deleted_at IS NULL ORDER BY sort_order ASC");
            $tasks = [];
            while ($row = $stmt->fetch()) {
                $tasks[] = taskFromRow($row, $commentsByTask[$row['id']] ?? []);
            }

            // Documents（削除されていないもの、最終更新の新しい順）
            $stmt = $pdo->query("SELECT * FROM documents WHERE deleted_at IS NULL ORDER BY last_updated DESC");
            $docs = [];
            while ($row = $stmt->fetch()) {
                $docs[] = docFromRow($row);
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'currentUser'   => $currentUser,
                    'members'       => $members,
                    'categories'    => $settings['categories'] ?? [],
                    'taskTypes'     => $settings['taskTypes'] ?? [],
                    'taskTemplates' => $settings['taskTemplates'] ?? new stdClass(),
                    'docTags'       => $settings['docTags'] ?? [],
                    'docFileTags'   => $settings['docFileTags'] ?? new stdClass(),
                    'docTemplates'  => $settings['docTemplates'] ?? [],
                    'releaseDocUrl' => $settings['releaseDocUrl'] ?? '',
                    'tasks'         => $tasks,
                    'docs'          => $docs,
                    'docFolderRootId' => DOC_FOLDER_ID,
                ],
            ]);
            break;

        // ============================================================
        // saveTask — 新規作成 or 更新（upsert）
        // ============================================================
        case 'saveTask':
            $task  = $payload['task'] ?? [];
            $isNew = empty($task['id']);

            if ($isNew) {
                $task['id'] = assignNextTaskId($pdo);
            }

            $pdo->prepare("
                INSERT INTO tasks (
                    id, title, description, status, priority, type, category, parent_id,
                    start_date, due_date, implementation_date, implementation_days,
                    assignee_email, assignee_name, sub_assignees, likes, attachments, sort_order
                ) VALUES (
                    :id, :title, :description, :status, :priority, :type, :category, :parent_id,
                    :start_date, :due_date, :implementation_date, :implementation_days,
                    :assignee_email, :assignee_name, :sub_assignees, :likes, :attachments, :sort_order
                ) ON DUPLICATE KEY UPDATE
                    title               = VALUES(title),
                    description         = VALUES(description),
                    status              = VALUES(status),
                    priority            = VALUES(priority),
                    type                = VALUES(type),
                    category            = VALUES(category),
                    parent_id           = VALUES(parent_id),
                    start_date          = VALUES(start_date),
                    due_date            = VALUES(due_date),
                    implementation_date = VALUES(implementation_date),
                    implementation_days = VALUES(implementation_days),
                    assignee_email      = VALUES(assignee_email),
                    assignee_name       = VALUES(assignee_name),
                    sub_assignees       = VALUES(sub_assignees),
                    likes               = VALUES(likes),
                    attachments         = VALUES(attachments),
                    sort_order          = VALUES(sort_order)
            ")->execute([
                ':id'                  => $task['id'],
                ':title'               => $task['title'] ?? '',
                ':description'         => $task['description'] ?? '',
                ':status'              => $task['status'] ?? 'todo',
                ':priority'            => $task['priority'] ?? 'medium',
                ':type'                => $task['type'] ?? '',
                ':category'            => $task['category'] ?? '',
                ':parent_id'           => !empty($task['parentId']) ? $task['parentId'] : null,
                ':start_date'          => !empty($task['startDate']) ? $task['startDate'] : null,
                ':due_date'            => !empty($task['dueDate']) ? $task['dueDate'] : null,
                ':implementation_date' => !empty($task['implementationDate']) ? $task['implementationDate'] : null,
                ':implementation_days' => (int)($task['implementationDays'] ?? 1),
                ':assignee_email'      => $task['assigneeEmail'] ?? '',
                ':assignee_name'       => $task['assigneeName'] ?? '',
                ':sub_assignees'       => json_encode($task['subAssignees'] ?? []),
                ':likes'               => json_encode($task['likes'] ?? []),
                ':attachments'         => json_encode($task['attachments'] ?? []),
                ':sort_order'          => (float)($task['order'] ?? 0),
            ]);

            // 保存後の最新行を取得して返す
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $stmt->execute([$task['id']]);
            $saved = $stmt->fetch();

            // コメントはリクエストからそのまま引き継ぐ（コメントはsaveComment経由で管理）
            echo json_encode(['success' => true, 'data' => taskFromRow($saved, $task['comments'] ?? [])]);
            break;

        // ============================================================
        // deleteTask — ソフトデリート（ゴミ箱に移動）
        // ============================================================
        case 'deleteTask':
            $taskId = $payload['taskId'] ?? '';
            if (!$taskId) {
                echo json_encode(['success' => false, 'error' => 'taskId is required']);
                break;
            }
            $pdo->prepare("UPDATE tasks SET deleted_at = NOW() WHERE id = ?")->execute([$taskId]);
            echo json_encode(['success' => true, 'data' => null]);
            break;

        // ============================================================
        // saveSettings — メンバー全件更新 + 設定キー upsert
        // ============================================================
        case 'saveSettings':
            $s = $payload;
            $pdo->beginTransaction();
            try {
                // メンバー: 全件洗い替え
                $pdo->exec("DELETE FROM members");
                if (!empty($s['members'])) {
                    $ins = $pdo->prepare("INSERT INTO members (email, name, avatar, is_admin, default_category) VALUES (?,?,?,?,?)");
                    foreach ($s['members'] as $m) {
                        $ins->execute([
                            $m['email'] ?? '',
                            $m['name'] ?? '',
                            $m['avatar'] ?? '👤',
                            !empty($m['isAdmin']) ? 1 : 0,
                            $m['defaultCategory'] ?? '',
                        ]);
                    }
                }

                // 設定キー: upsert
                $ups  = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
                $keys = ['categories', 'taskTypes', 'taskTemplates', 'docTags', 'docFileTags', 'docTemplates', 'releaseDocUrl'];
                foreach ($keys as $key) {
                    if (array_key_exists($key, $s)) {
                        $val = json_encode($s[$key]);
                        $ups->execute([$key, $val, $val]);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'data' => null]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ============================================================
        // saveComment — コメントの追加 or 更新（upsert）
        // ============================================================
        case 'saveComment':
            $c = $payload['comment'] ?? $payload;
            if (empty($c['id'])) {
                echo json_encode(['success' => false, 'error' => 'comment id is required']);
                break;
            }
            $pdo->prepare("
                INSERT INTO comments (id, task_id, author_email, author_name, text, likes, read_by, created_at)
                VALUES (:id, :task_id, :author_email, :author_name, :text, :likes, :read_by, NOW())
                ON DUPLICATE KEY UPDATE
                    text    = VALUES(text),
                    likes   = VALUES(likes),
                    read_by = VALUES(read_by)
            ")->execute([
                ':id'           => $c['id'],
                ':task_id'      => $c['taskId'] ?? '',
                ':author_email' => $c['authorEmail'] ?? '',
                ':author_name'  => $c['authorName'] ?? '',
                ':text'         => $c['text'] ?? '',
                ':likes'        => json_encode($c['likes'] ?? []),
                ':read_by'      => json_encode($c['readBy'] ?? []),
            ]);

            $stmt = $pdo->prepare("SELECT * FROM comments WHERE id = ?");
            $stmt->execute([$c['id']]);
            echo json_encode(['success' => true, 'data' => commentFromRow($stmt->fetch())]);
            break;

        // ============================================================
        // deleteComment — コメント削除（物理削除）
        // ============================================================
        case 'deleteComment':
            $commentId = $payload['commentId'] ?? '';
            if (!$commentId) {
                echo json_encode(['success' => false, 'error' => 'commentId is required']);
                break;
            }
            $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$commentId]);
            echo json_encode(['success' => true, 'data' => null]);
            break;

        // ============================================================
        // markCommentsAsRead — 複数コメントの既読化
        // ============================================================
        case 'markCommentsAsRead':
            $commentIds = $payload['commentIds'] ?? [];
            $email      = $payload['email'] ?? '';

            if (!empty($commentIds) && $email) {
                $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
                $stmt = $pdo->prepare("SELECT id, read_by FROM comments WHERE id IN ($placeholders)");
                $stmt->execute($commentIds);
                $upd = $pdo->prepare("UPDATE comments SET read_by = ? WHERE id = ?");

                while ($row = $stmt->fetch()) {
                    $readBy = json_decode($row['read_by'] ?? '[]', true) ?: [];
                    if (!in_array($email, $readBy, true)) {
                        $readBy[] = $email;
                        $upd->execute([json_encode($readBy), $row['id']]);
                    }
                }
            }
            echo json_encode(['success' => true, 'data' => null]);
            break;

        // ============================================================
        // getDeletedTasks — ゴミ箱（ソフトデリート済みタスク一覧）
        // ============================================================
        case 'getDeletedTasks':
            $stmt    = $pdo->query("SELECT * FROM tasks WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
            $deleted = [];
            while ($row = $stmt->fetch()) {
                $t              = taskFromRow($row);
                $t['deletedAt'] = $row['deleted_at'];
                $deleted[]      = $t;
            }
            echo json_encode(['success' => true, 'data' => $deleted]);
            break;

        // ============================================================
        // restoreTask — ゴミ箱から復元
        // ============================================================
        case 'restoreTask':
            $taskId = $payload['taskId'] ?? '';
            if (!$taskId) {
                echo json_encode(['success' => false, 'error' => 'taskId is required']);
                break;
            }
            $pdo->prepare("UPDATE tasks SET deleted_at = NULL WHERE id = ?")->execute([$taskId]);
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $row = $stmt->fetch();
            // SettingsView が res.success と res.restoredTask を参照するため data に success を含める
            echo json_encode(['success' => true, 'data' => ['success' => true, 'restoredTask' => taskFromRow($row)]]);
            break;

        // ============================================================
        // getMyNotifications — 自分宛の通知一覧
        // ============================================================
        case 'getMyNotifications':
            $email = $payload['email'] ?? '';
            if (!$email) {
                echo json_encode(['success' => true, 'data' => []]);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE target_email = ? ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([$email]);
            $notifs = [];
            while ($row = $stmt->fetch()) {
                $notifs[] = notifFromRow($row);
            }
            echo json_encode(['success' => true, 'data' => $notifs]);
            break;

        // ============================================================
        // markNotificationAsRead — 通知を既読にする
        // ============================================================
        case 'markNotificationAsRead':
            $notifId = $payload['notificationId'] ?? '';
            if ($notifId) {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$notifId]);
            }
            echo json_encode(['success' => true, 'data' => null]);
            break;

        // ============================================================
        // createNotification — 通知の作成（メンション等）
        // ============================================================
        case 'createNotification':
            $id = 'notif_' . time() . '_' . rand(100, 999);
            $pdo->prepare("INSERT INTO notifications (id, target_email, sender_name, task_id, task_title, message, created_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $id,
                    $payload['targetEmail'] ?? '',
                    $payload['senderName']  ?? '',
                    $payload['taskId']      ?? '',
                    $payload['taskTitle']   ?? '',
                    $payload['message']     ?? '',
                    date('Y-m-d H:i:s'),
                ]);
            echo json_encode(['success' => true, 'data' => ['id' => $id]]);
            break;

        // ============================================================
        // uploadFile — Google Drive の指定フォルダにアップロード
        // ============================================================
        case 'uploadFile':
            $name     = $payload['name']     ?? 'upload';
            $mimeType = $payload['mimeType'] ?? 'application/octet-stream';
            $data     = $payload['data']     ?? '';

            $allowedTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'text/plain', 'text/csv',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            if (!in_array($mimeType, $allowedTypes)) {
                echo json_encode(['success' => false, 'error' => 'このファイル形式はアップロードできません']);
                break;
            }

            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                echo json_encode(['success' => false, 'error' => 'ファイルのデコードに失敗しました']);
                break;
            }
            if (strlen($decoded) > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'error' => 'ファイルサイズは10MB以下にしてください']);
                break;
            }

            $token     = getGoogleAccessToken();
            $driveFile = uploadFileToDrive($name, $mimeType, $decoded, ATTACHMENT_FOLDER_ID, $token);
            makeFilePublic($driveFile['id'], $token);

            $url = strpos($mimeType, 'image/') === 0
                 ? 'https://lh3.googleusercontent.com/d/' . $driveFile['id']
                 : $driveFile['webViewLink'];

            echo json_encode(['success' => true, 'data' => [
                'id'   => $driveFile['id'],
                'name' => $name,
                'url'  => $url,
            ]]);
            break;

        // ============================================================
        // createDocument — Google Docs を新規作成して documents に登録
        // ============================================================
        case 'createDocument':
            $title    = trim($payload['title'] ?? '');
            $parentId = trim($payload['parentId'] ?? '');
            if ($parentId === '') $parentId = DOC_FOLDER_ID;
            if ($title === '') {
                echo json_encode(['success' => false, 'error' => 'タイトルを入力してください']);
                break;
            }

            $token = getGoogleAccessToken();
            $file  = createGoogleDoc($title, $parentId, $token);

            $url      = $file['webViewLink'] ?? ('https://docs.google.com/document/d/' . $file['id'] . '/edit');
            $modified = !empty($file['modifiedTime']) ? date('Y-m-d H:i:s', strtotime($file['modifiedTime'])) : date('Y-m-d H:i:s');
            $mime     = 'application/vnd.google-apps.document';

            $pdo->prepare("INSERT INTO documents (id, name, url, parent_id, mime_type, last_updated) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$file['id'], $file['name'], $url, $parentId, $mime, $modified]);

            echo json_encode(['success' => true, 'data' => [
                'id'          => $file['id'],
                'name'        => $file['name'],
                'url'         => $url,
                'parentId'    => $parentId,
                'mimeType'    => $mime,
                'isFolder'    => false,
                'lastUpdated' => date('Y/m/d H:i', strtotime($modified)),
            ]]);
            break;

        // ============================================================
        // createFolder — Driveに新規フォルダを作成して documents に登録
        // ============================================================
        case 'createFolder':
            $title    = trim($payload['title'] ?? '');
            $parentId = trim($payload['parentId'] ?? '');
            if ($parentId === '') $parentId = DOC_FOLDER_ID;
            if ($title === '') {
                echo json_encode(['success' => false, 'error' => 'フォルダ名を入力してください']);
                break;
            }

            $token  = getGoogleAccessToken();
            $folder = createDriveFolder($title, $parentId, $token);

            $url      = $folder['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . $folder['id']);
            $modified = !empty($folder['modifiedTime']) ? date('Y-m-d H:i:s', strtotime($folder['modifiedTime'])) : date('Y-m-d H:i:s');
            $mime     = 'application/vnd.google-apps.folder';

            $pdo->prepare("INSERT INTO documents (id, name, url, parent_id, mime_type, last_updated) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$folder['id'], $folder['name'], $url, $parentId, $mime, $modified]);

            echo json_encode(['success' => true, 'data' => [
                'id'          => $folder['id'],
                'name'        => $folder['name'],
                'url'         => $url,
                'parentId'    => $parentId,
                'mimeType'    => $mime,
                'isFolder'    => true,
                'lastUpdated' => date('Y/m/d H:i', strtotime($modified)),
            ]]);
            break;

        // ============================================================
        // moveDocument — ドキュメント/フォルダを別の親フォルダ配下に移動
        //   - Drive側は addParents/removeParents で親を付け替え
        //   - DB側は documents.parent_id を更新
        //   - フォルダの場合、自身の子孫配下への移動は循環するため拒否
        // ============================================================
        case 'moveDocument':
            $fileId      = trim($payload['fileId']      ?? '');
            $newParentId = trim($payload['newParentId'] ?? '');
            if ($fileId === '' || $newParentId === '') {
                echo json_encode(['success' => false, 'error' => 'fileId と newParentId が必要です']);
                break;
            }
            if ($fileId === $newParentId) {
                echo json_encode(['success' => false, 'error' => '自分自身には移動できません']);
                break;
            }

            // 対象の現状取得
            $stmt = $pdo->prepare("SELECT parent_id, mime_type FROM documents WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$fileId]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'error' => '移動対象が見つかりません']);
                break;
            }
            $oldParentId = $row['parent_id'] ?? '';
            $isFolder    = ($row['mime_type'] ?? '') === 'application/vnd.google-apps.folder';

            if ($oldParentId === $newParentId) {
                echo json_encode(['success' => true, 'data' => ['noop' => true]]);
                break;
            }

            // 移動先の親が有効か確認（ルート以外）
            if ($newParentId !== DOC_FOLDER_ID) {
                $check = $pdo->prepare("SELECT mime_type, deleted_at FROM documents WHERE id = ?");
                $check->execute([$newParentId]);
                $parentRow = $check->fetch();
                if (!$parentRow
                    || ($parentRow['mime_type'] ?? '') !== 'application/vnd.google-apps.folder'
                    || !empty($parentRow['deleted_at'])) {
                    echo json_encode(['success' => false, 'error' => '移動先のフォルダが見つかりません']);
                    break;
                }
            }

            // フォルダ移動の循環防止
            if ($isFolder) {
                $descendants = collectDescendantIds($pdo, $fileId);
                if (in_array($newParentId, $descendants, true)) {
                    echo json_encode(['success' => false, 'error' => 'フォルダを自身の子孫配下に移動することはできません']);
                    break;
                }
            }

            // Drive APIで親を付け替え
            $token  = getGoogleAccessToken('https://www.googleapis.com/auth/drive');
            $params = [
                'addParents'        => $newParentId,
                'supportsAllDrives' => 'true',
                'fields'            => 'id,parents,modifiedTime',
            ];
            if ($oldParentId !== '') $params['removeParents'] = $oldParentId;

            $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?' . http_build_query($params);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'PATCH',
                CURLOPT_POSTFIELDS     => '{}',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json; charset=UTF-8',
                ],
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) throw new Exception("Drive 移動エラー (HTTP {$code}): " . $res);

            $pdo->prepare("UPDATE documents SET parent_id = ? WHERE id = ?")
                ->execute([$newParentId, $fileId]);

            echo json_encode(['success' => true, 'data' => [
                'fileId'      => $fileId,
                'newParentId' => $newParentId,
            ]]);
            break;

        // ============================================================
        // deleteDocument — DBはソフトデリート + Driveはtrash
        // ============================================================
        case 'deleteDocument':
            $fileId = $payload['fileId'] ?? '';
            if (!$fileId) {
                echo json_encode(['success' => false, 'error' => 'fileId is required']);
                break;
            }

            // フォルダなら子孫もまとめてソフトデリート（Drive側はtrashで自動的にカスケード）
            $stmt = $pdo->prepare("SELECT mime_type FROM documents WHERE id = ?");
            $stmt->execute([$fileId]);
            $row = $stmt->fetch();
            $isFolder = $row && ($row['mime_type'] ?? '') === 'application/vnd.google-apps.folder';

            $idsToDelete = [$fileId];
            if ($isFolder) {
                $idsToDelete = array_merge($idsToDelete, collectDescendantIds($pdo, $fileId));
            }

            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $pdo->prepare("UPDATE documents SET deleted_at = NOW() WHERE id IN ({$placeholders})")
                ->execute($idsToDelete);

            // Drive側のtrashは失敗してもDB側の削除は維持する（ベストエフォート）
            try {
                $token = getGoogleAccessToken();
                trashDriveFile($fileId, $token);
            } catch (Throwable $e) {
                // ログに残すだけで握りつぶす
                error_log('trashDriveFile failed: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'data' => ['affectedCount' => count($idsToDelete)]]);
            break;

        // ============================================================
        // syncDocumentsFromDrive — DOC_FOLDER_ID 以下のフォルダ階層を再帰的に同期
        //   - フォルダもファイルも全て documents に登録（mime_type で区別）
        //   - parent_id でツリー構造を保持
        //   - Drive にあって DB に無い      → INSERT
        //   - Drive にあって DB にもある    → 差分があれば UPDATE / 復元（deleted_at=NULL）
        //   - DOC_FOLDER_ID 配下に無いが DB の生存行 → ソフトデリート
        //     （ただし parent_id が今回の探索範囲外＝他フォルダツリーのものは触らない）
        //   - 管理者のみ実行可
        // ============================================================
        case 'syncDocumentsFromDrive':
            requireAdmin($pdo);

            $token = getGoogleAccessToken('https://www.googleapis.com/auth/drive.readonly');

            // 1) BFS で DOC_FOLDER_ID 配下のサブフォルダを掘りつつ全アイテム取得
            $driveFiles  = [];                            // id => Drive APIレスポンス + _parent
            $queue       = [DOC_FOLDER_ID];               // これから列挙する親フォルダ
            $visited     = [DOC_FOLDER_ID => true];       // 列挙済みの親フォルダ（=同期管理対象の境界）

            while (!empty($queue)) {
                $currentParent = array_shift($queue);

                $pageToken = null;
                do {
                    $params = [
                        'q'                         => "'" . $currentParent . "' in parents and trashed=false",
                        'fields'                    => 'nextPageToken,files(id,name,webViewLink,modifiedTime,mimeType)',
                        'pageSize'                  => '1000',
                        'supportsAllDrives'         => 'true',
                        'includeItemsFromAllDrives' => 'true',
                    ];
                    if ($pageToken) $params['pageToken'] = $pageToken;
                    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                    ]);
                    $res  = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($code !== 200) throw new Exception("Driveファイル一覧取得エラー (HTTP {$code}): " . $res);

                    $json = json_decode($res, true);
                    foreach ($json['files'] ?? [] as $f) {
                        $f['_parent']           = $currentParent;
                        $driveFiles[$f['id']]   = $f;
                        if (($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder' && !isset($visited[$f['id']])) {
                            $visited[$f['id']] = true;
                            $queue[]           = $f['id'];
                        }
                    }
                    $pageToken = $json['nextPageToken'] ?? null;
                } while ($pageToken);
            }

            // 2) DB側の現状を取得（ソフトデリート行も含めて全件）
            $stmt   = $pdo->query("SELECT id, name, url, parent_id, mime_type, last_updated, deleted_at FROM documents");
            $dbDocs = [];
            while ($row = $stmt->fetch()) {
                $dbDocs[$row['id']] = $row;
            }

            // 3) リコンサイル
            $inserted = 0; $updated = 0; $restored = 0; $deleted = 0;

            $pdo->beginTransaction();
            try {
                $insStmt = $pdo->prepare("INSERT INTO documents (id, name, url, parent_id, mime_type, last_updated) VALUES (?, ?, ?, ?, ?, ?)");
                $updStmt = $pdo->prepare("UPDATE documents SET name = ?, url = ?, parent_id = ?, mime_type = ?, last_updated = ?, deleted_at = NULL WHERE id = ?");
                $delStmt = $pdo->prepare("UPDATE documents SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");

                foreach ($driveFiles as $id => $f) {
                    $name     = $f['name'] ?? '(無題)';
                    $mime     = $f['mimeType'] ?? '';
                    $isFolder = $mime === 'application/vnd.google-apps.folder';
                    $url      = $f['webViewLink'] ?? ($isFolder
                        ? 'https://drive.google.com/drive/folders/' . $id
                        : 'https://drive.google.com/file/d/' . $id . '/view');
                    $modified = !empty($f['modifiedTime']) ? date('Y-m-d H:i:s', strtotime($f['modifiedTime'])) : date('Y-m-d H:i:s');
                    $parentId = $f['_parent'];

                    if (!isset($dbDocs[$id])) {
                        $insStmt->execute([$id, $name, $url, $parentId, $mime, $modified]);
                        $inserted++;
                    } else {
                        $existing      = $dbDocs[$id];
                        $isSoftDeleted = !empty($existing['deleted_at']);
                        $needsUpdate   = $isSoftDeleted
                            || $existing['name']         !== $name
                            || $existing['url']          !== $url
                            || $existing['parent_id']    !== $parentId
                            || $existing['mime_type']    !== $mime
                            || $existing['last_updated'] !== $modified;
                        if ($needsUpdate) {
                            $updStmt->execute([$name, $url, $parentId, $mime, $modified, $id]);
                            if ($isSoftDeleted) $restored++;
                            else                $updated++;
                        }
                    }
                }

                // 「DBにあるがDriveに無い」削除パス
                //   - parent_id が NULL（マイグレーション直後の旧データ）または
                //     parent_id が今回の探索対象（visited）に含まれるものだけが対象。
                //   - 他のフォルダツリー（例：AI生成Doc）に属するものは触らない。
                foreach ($dbDocs as $id => $row) {
                    if (!empty($row['deleted_at']))   continue;
                    if (isset($driveFiles[$id]))      continue;
                    $parent = $row['parent_id'] ?? null;
                    $managed = ($parent === null || $parent === '' || isset($visited[$parent]));
                    if ($managed) {
                        $delStmt->execute([$id]);
                        $deleted++;
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'data' => [
                'inserted'     => $inserted,
                'updated'      => $updated,
                'restored'     => $restored,
                'deleted'      => $deleted,
                'driveTotal'   => count($driveFiles),
                'foldersScanned' => count($visited),
            ]]);
            break;

        // ============================================================
        // generateDocumentFromComment — テンプレートDocコピー＋プレースホルダ置換
        // ============================================================
        case 'generateDocumentFromComment':
            $task         = $payload['task'] ?? [];
            $templateName = $payload['templateName'] ?? '';
            $templateUrl  = $payload['templateUrl'] ?? '';
            $commentText  = $payload['commentText'] ?? '';

            if (!preg_match('#[-\w]{25,}#', $templateUrl, $m)) {
                throw new Exception('テンプレートURLからファイルIDを取得できませんでした。');
            }
            $templateId  = $m[0];
            $newFileName = "【自動生成】{$templateName}_" . ($task['title'] ?? '');

            // Drive(read+write) と Docs API の両スコープを1トークンで取る
            $token = getGoogleAccessToken('https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/documents');

            // 1) テンプレートをコピー
            $copyBody = json_encode([
                'name'    => $newFileName,
                'parents' => [AI_DOC_FOLDER_ID],
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$templateId}/copy?supportsAllDrives=true&fields=id,name,mimeType,webViewLink,modifiedTime");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $copyBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json; charset=UTF-8',
                    'Expect:',
                ],
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) throw new Exception("Drive コピーエラー (HTTP {$code}): " . $res);
            $copied   = json_decode($res, true);
            $newId    = $copied['id'];
            $newUrl   = $copied['webViewLink'] ?? ('https://docs.google.com/document/d/' . $newId . '/edit');
            $newName  = $copied['name'] ?? $newFileName;
            $mimeType = $copied['mimeType'] ?? '';
            $modified = !empty($copied['modifiedTime']) ? date('Y-m-d H:i:s', strtotime($copied['modifiedTime'])) : date('Y-m-d H:i:s');

            // 2) コメント本文から [キー] ブロックを抽出
            $lines        = preg_split('/\r\n|\r|\n/', $commentText);
            $currentKey   = null;
            $replacements = [];
            foreach ($lines as $line) {
                $clean = trim(str_replace('*', '', $line));
                if (preg_match('/^\[(.*?)\]$/', $clean, $mm)) {
                    $currentKey = '[' . $mm[1] . ']';
                    $replacements[$currentKey] = [];
                } elseif ($currentKey !== null) {
                    $replacements[$currentKey][] = $line;
                }
            }

            // 3) Google Docs の場合のみ batchUpdate でプレースホルダ置換
            if ($mimeType === 'application/vnd.google-apps.document') {
                $requests = [];
                foreach ($replacements as $key => $vals) {
                    $value = trim(implode("\n", $vals));
                    if ($value !== '') {
                        $requests[] = [
                            'replaceAllText' => [
                                'containsText' => ['text' => $key, 'matchCase' => true],
                                'replaceText' => $value,
                            ],
                        ];
                    }
                }
                if (!empty($requests)) {
                    $batchBody = json_encode(['requests' => $requests], JSON_UNESCAPED_UNICODE);
                    $ch = curl_init("https://docs.googleapis.com/v1/documents/{$newId}:batchUpdate");
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $batchBody,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => [
                            "Authorization: Bearer {$token}",
                            'Content-Type: application/json; charset=UTF-8',
                            'Expect:',
                        ],
                    ]);
                    $res  = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($code !== 200) throw new Exception("Docs batchUpdate エラー (HTTP {$code}): " . $res);
                }
            }

            // 4) DocsViewに表示するためdocumentsテーブルへ登録
            $pdo->prepare("INSERT INTO documents (id, name, url, last_updated) VALUES (?, ?, ?, ?)")
                ->execute([$newId, $newName, $newUrl, $modified]);

            echo json_encode(['success' => true, 'data' => [
                'id'   => $newId,
                'name' => $newName,
                'url'  => $newUrl,
            ]]);
            break;

        // ============================================================
        // gatherAiInformation — タスク情報をAIで整理・抽出
        // ============================================================
        case 'gatherAiInformation':
            $task       = $payload['task'] ?? [];
            $childTasks = $payload['childTasks'] ?? [];
            $prePrompt  = $payload['prePrompt'] ?? '';

            $todayStr = date('Y年n月j日');
            $title    = $task['title'] ?? '';
            $desc     = !empty($task['description']) ? $task['description'] : '(なし)';

            $taskInfo  = "【システム情報】\n現在の日付: {$todayStr}\n\n";
            $taskInfo .= "【メイン課題】\nタイトル: {$title}\n詳細: {$desc}\n\n";

            if (!empty($task['comments']) && is_array($task['comments'])) {
                $taskInfo .= "【メイン課題のコメント履歴】\n";
                foreach ($task['comments'] as $c) {
                    $taskInfo .= "- " . ($c['authorName'] ?? '') . ": " . ($c['text'] ?? '') . "\n";
                }
                $taskInfo .= "\n";
            }

            if (!empty($childTasks) && is_array($childTasks)) {
                $taskInfo .= "【関連する子課題一覧】\n";
                foreach ($childTasks as $ct) {
                    $ctDesc = !empty($ct['description']) ? $ct['description'] : '(なし)';
                    $taskInfo .= "■ 子課題: " . ($ct['title'] ?? '') . "\n詳細: {$ctDesc}\n";
                    if (!empty($ct['comments']) && is_array($ct['comments'])) {
                        $taskInfo .= "[子課題のコメント]\n";
                        foreach ($ct['comments'] as $c) {
                            $taskInfo .= " - " . ($c['authorName'] ?? '') . ": " . ($c['text'] ?? '') . "\n";
                        }
                    }
                    $taskInfo .= "\n";
                }
            }

            $extraData = appendDriveFileDataToPrompt($prePrompt);

            $promptText = <<<PROMPT
あなたは優秀なプロジェクトマネージャーのアシスタントです。
以下の【課題の全情報】を漏れなく読み込み、【ユーザーからの指示（抽出項目）】に従って必要な情報を抽出し、整理してください。

{$taskInfo}

【ユーザーからの指示（抽出項目）】
{$prePrompt}
{$extraData}

【出力厳守ルール】
- 抽出した各項目は、必ず以下のフォーマットで出力してください。Markdownの表やJSONなど、これ以外の形式は一切禁止です。
- 項目名は必ず「[ ]」で囲み、太字などの装飾（**など）をせずに単独の行に記述してください。
- その次の行から、該当する抽出内容を記述してください。
- 余計な挨拶（「以下に整理しました」など）は一切不要です。抽出した結果のみを直接出力してください。

【出力フォーマット例】
[依頼内容]
〇〇に関する調査と報告書の作成

[実施日時]
2026年4月17日
PROMPT;

            $aiOutput = callVertexAi('gemini-2.5-flash', [
                'contents'         => [['role' => 'user', 'parts' => [['text' => $promptText]]]],
                'generationConfig' => ['temperature' => 0.2],
            ]);
            echo json_encode(['success' => true, 'data' => ['text' => $aiOutput]]);
            break;

        // ============================================================
        // generateAndAppendReleaseNote — リリースノート追記＋AI仕様書全文更新
        // ============================================================
        case 'generateAndAppendReleaseNote':
            $roughNotes = $payload['roughNotes'] ?? '';
            $docUrl     = $payload['docUrl'] ?? '';
            if ($roughNotes === '' || $docUrl === '') {
                throw new Exception('必要な情報（メモ、URL）が不足しています。');
            }
            if (!preg_match('#[-\w]{25,}#', $docUrl, $mm1)) throw new Exception('リリースノートURLが不正です。');
            $releaseDocId = $mm1[0];

            // Drive(read) と Docs(read+write) の両スコープ（リリースノートDoc用）
            $token = getGoogleAccessToken('https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/documents');

            // ---- ① リリースノート（人間用）の生成と追記 ----
            $currentReleaseText = exportDriveDocAsText($releaseDocId, $token);

            $nextVersion = 1;
            if (preg_match('/バージョン\s*(\d+)/u', $currentReleaseText, $vm)) {
                $nextVersion = (int)$vm[1] + 1;
            }
            $nowStr = date('Y/m/d H:i');

            $releasePrompt = <<<PROMPT
あなたはプロダクトマネージャーです。以下のエンジニアのメモを読み取り、ユーザー向けのリリースノートを作成してください。
【厳守ルール】
- 挨拶、返事、結びの言葉は絶対に書かないでください。
- 必ず以下のJSONフォーマットのみを出力してください。Markdown記法（###や**）はデータ内に含めないでください。
- 専門用語はなるべくユーザーに伝わる言葉に少し噛み砕いてください。

【バージョン情報】
バージョン {$nextVersion}
日付: {$nowStr}

【メモ】
{$roughNotes}

【JSONフォーマット例】
{
  "title": "🎉 バージョン {$nextVersion} アップデート情報（{$nowStr}）",
  "sections": [
    {
      "heading": "✨ 【新機能】",
      "items": [
        "設定メニューから、過去のアップデート履歴を確認できるようになりました。",
        "アプリ内で手軽にご覧いただけます。"
      ]
    },
    {
      "heading": "🛠️ 【改善・修正】",
      "items": [
        "〇〇の不具合を修正しました。"
      ]
    }
  ]
}
PROMPT;

            $releaseOutput = callVertexAi('gemini-2.5-pro', [
                'contents'         => [['role' => 'user', 'parts' => [['text' => $releasePrompt]]]],
                'generationConfig' => ['temperature' => 0.1],
            ]);
            // コードフェンス除去
            $releaseOutput = preg_replace('/^`{3}(?:json|markdown|text)?\s*/i', '', $releaseOutput);
            $releaseOutput = preg_replace('/`{3}\s*$/', '', $releaseOutput);

            // JSON部分のみ抽出
            if (!preg_match('/\{[\s\S]*\}/', $releaseOutput, $jm)) {
                throw new Exception('AIが指定のJSON形式で出力しませんでした。');
            }
            $releaseData = json_decode($jm[0], true);
            if (!$releaseData || !isset($releaseData['title'], $releaseData['sections']) || !is_array($releaseData['sections'])) {
                throw new Exception('AI出力JSONの構造が不正です。');
            }

            // 挿入位置: 「更新履歴」を含む段落の直後。なければbody先頭(index=1)
            $relStruct = docsApiGet($releaseDocId, $token);
            $insertIndex = findParagraphEndIndexContaining($relStruct, '更新履歴');
            if ($insertIndex === null) $insertIndex = 1;

            // 挿入テキストを組み立てつつUTF-16単位でオフセット計測
            $textBuf = '';
            $pos     = 0;

            $append = function (string $s) use (&$textBuf, &$pos) {
                $textBuf .= $s;
                $pos     += utf16Len($s);
            };

            $append("\n"); // 上の余白
            $titleStart = $insertIndex + $pos;
            $append($releaseData['title']);
            $titleEndExclNL = $insertIndex + $pos; // bold範囲はここまで（\n含めず）
            $append("\n");
            $titleEndIncNL  = $insertIndex + $pos; // 段落スタイル(HEADING_3)はここまで
            $append("\n");

            $headRanges = [];
            foreach ($releaseData['sections'] as $sec) {
                $hStart = $insertIndex + $pos;
                $append((string)($sec['heading'] ?? ''));
                $hEnd   = $insertIndex + $pos;
                $append("\n");
                $headRanges[] = [$hStart, $hEnd];

                foreach ((array)($sec['items'] ?? []) as $item) {
                    $append('・' . (string)$item . "\n");
                }
                $append("\n"); // セクション間余白
            }

            // batchUpdate 構築
            $reqs = [];
            $reqs[] = ['insertText' => ['location' => ['index' => $insertIndex], 'text' => $textBuf]];
            $reqs[] = ['updateParagraphStyle' => [
                'range'          => ['startIndex' => $titleStart, 'endIndex' => $titleEndIncNL],
                'paragraphStyle' => ['namedStyleType' => 'HEADING_3'],
                'fields'         => 'namedStyleType',
            ]];
            $reqs[] = ['updateTextStyle' => [
                'range'     => ['startIndex' => $titleStart, 'endIndex' => $titleEndExclNL],
                'textStyle' => ['bold' => true],
                'fields'    => 'bold',
            ]];
            foreach ($headRanges as $r) {
                if ($r[1] > $r[0]) {
                    $reqs[] = ['updateTextStyle' => [
                        'range'     => ['startIndex' => $r[0], 'endIndex' => $r[1]],
                        'textStyle' => ['bold' => true],
                        'fields'    => 'bold',
                    ]];
                }
            }
            docsApiBatchUpdate($releaseDocId, $reqs, $token);

            // ---- ② AI仕様書（コンシェルジュの脳みそ）をDB上で全文書き換え ----
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'systemSpecForAI'");
            $stmt->execute();
            $rawSpec = $stmt->fetchColumn();
            $currentSpecText = ($rawSpec === false || $rawSpec === null) ? '' : (string)$rawSpec;

            $specPrompt = <<<PROMPT
あなたは優秀なシステムアーキテクトです。
以下は現在の「Olive Note AI用システム仕様書（マークダウン）」です。

【現在の仕様書】
{$currentSpecText}

【今回のアップデート内容（エンジニアのメモ）】
{$roughNotes}

上記のアップデート内容を踏まえ、現在の仕様書を最新の状態に書き換えてください。
変更がない部分はそのまま残し、変更・追加された機能についてのみ、該当箇所を修正・追記してください。
出力は、Markdownのコードブロック記法を使用せず、更新後の仕様書の全文をそのままプレーンテキストで出力してください。
PROMPT;

            $specOutput = callVertexAi('gemini-2.5-pro', [
                'contents'         => [['role' => 'user', 'parts' => [['text' => $specPrompt]]]],
                'generationConfig' => ['temperature' => 0.1],
            ]);
            $specOutput = preg_replace('/^`{3}(?:json|markdown|text)?\s*/i', '', $specOutput);
            $specOutput = preg_replace('/`{3}\s*$/', '', $specOutput);
            $specOutput = rtrim($specOutput, "\r\n");

            // settings.systemSpecForAI を上書き（rawマークダウンで保存）
            $upd = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('systemSpecForAI', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $upd->execute([$specOutput]);

            echo json_encode(['success' => true, 'data' => [
                'success' => true,
                'version' => $nextVersion,
                'docUrl'  => $docUrl,
            ]]);
            break;

        // ============================================================
        // chatWithOliveAI — コンシェルジュ／アドバイザーの2モードチャット
        // ============================================================
        case 'chatWithOliveAI':
            try {
                $mode         = $payload['mode'] ?? '';
                $chatHistory  = $payload['history'] ?? [];
                $taskContext  = $payload['taskContext']  ?? null;
                $tasksContext = $payload['tasksContext'] ?? null;

                $systemInstruction = '';
                $targetModel       = '';
                $maxTokens         = 1024;

                if ($mode === 'concierge') {
                    // 仕様書はDBに直接保管（settings.systemSpecForAI、rawマークダウン）
                    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'systemSpecForAI'");
                    $stmt->execute();
                    $raw = $stmt->fetchColumn();
                    $systemSpec = ($raw === false || $raw === null || $raw === '') ? 'システム仕様書が設定されていません。' : (string)$raw;

                    $systemInstruction = "あなたはタスク管理ツール「Olive Note」の専属AIコンシェルジュです。\n"
                        . "ユーザーがツールの使い方で困らないよう、以下のシステム仕様書に基づいて的確にサポートしてください。\n"
                        . "回答は必要に応じてMarkdown（太字、リスト等）を使って読みやすく整形し、長文になりすぎないよう【最大でも400文字程度】で簡潔にまとめてください。\n\n"
                        . "【Olive Note システム仕様書】\n"
                        . $systemSpec;
                    $targetModel = 'gemini-2.5-flash';
                    $maxTokens   = 2048;

                } elseif ($mode === 'concierge-tasks') {
                    // 表示中の課題（フィルター適用後）に関する分析・質問応答
                    $filtersSummary = isset($tasksContext['filtersSummary']) ? (string)$tasksContext['filtersSummary'] : '（フィルター指定なし）';
                    $tasks          = is_array($tasksContext['tasks'] ?? null) ? $tasksContext['tasks'] : [];
                    $totalCount     = count($tasks);

                    // ペイロード爆発の予防: 300件で頭打ち
                    $cappedTasks = $totalCount > 300 ? array_slice($tasks, 0, 300) : $tasks;
                    $cappedNote  = $totalCount > 300 ? "（実際の表示件数 {$totalCount} 件のうち先頭 300 件を分析対象としています）\n" : '';

                    $tasksJson = json_encode($cappedTasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($tasksJson === false) $tasksJson = '[]';

                    $systemInstruction = "あなたはタスク管理ツール「Olive Note」の専属アナリストです。\n"
                        . "ユーザーが現在画面上で見ている『フィルター適用済の課題リスト』を分析し、質問に答えてください。\n"
                        . "回答は必要に応じてMarkdown（見出し・表・箇条書き）を使って読みやすく整形し、根拠となるタスクIDがあれば `TASK-XXXX` の形で引用してください。\n"
                        . "リストに存在しないタスクや、与えられていない情報については推測せず『データに無いため不明』と明示してください。\n"
                        . "件数の集計・期限超過の検出・担当者ごとの負荷比較・カテゴリ別の傾向など、定量的な質問にも具体的な数字で答えてください。\n\n"
                        . "【現在のフィルター条件】\n"
                        . $filtersSummary . "\n"
                        . "【表示中の課題件数】" . $totalCount . " 件\n"
                        . $cappedNote
                        . "\n【課題データ (JSON)】\n"
                        . $tasksJson;
                    $targetModel = 'gemini-2.5-pro';
                    $maxTokens   = 16384;

                } elseif ($mode === 'advisor') {
                    $title  = $taskContext['title']       ?? '未設定';
                    $desc   = $taskContext['description'] ?? '未設定';
                    $status = $taskContext['status']      ?? '未設定';
                    $due    = $taskContext['dueDate']     ?? '未設定';
                    if ($desc === '') $desc = '未設定';
                    if ($due === '' || $due === null) $due = '未設定';

                    $systemInstruction = "あなたは優秀なタスク管理アドバイザーです。\n"
                        . "ユーザーが現在直面している以下のタスクについて、壁打ち相手となり、解決策や進め方のアドバイスを提供してください。\n"
                        . "回答は必要に応じてMarkdownを使用し、アクションにつながる具体的な提案を含めてください。文字数制限はありません。\n\n"
                        . "【現在相談対象のタスク情報】\n"
                        . "- タイトル: {$title}\n"
                        . "- 詳細: {$desc}\n"
                        . "- ステータス: {$status}\n"
                        . "- 期限: {$due}";
                    $targetModel = 'gemini-2.5-pro';
                    $maxTokens   = 16384;

                } else {
                    echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => '無効なモードが指定されました。']]);
                    break;
                }

                // 会話履歴をVertex AIフォーマットに変換
                $contents = [];
                foreach ($chatHistory as $msg) {
                    $role = (($msg['role'] ?? '') === 'model') ? 'model' : 'user';
                    $contents[] = [
                        'role'  => $role,
                        'parts' => [['text' => $msg['text'] ?? '']],
                    ];
                }

                $apiPayload = [
                    'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents'           => $contents,
                    'generationConfig'   => [
                        'temperature'     => 0.4,
                        'maxOutputTokens' => $maxTokens,
                    ],
                ];

                $reply = callVertexAi($targetModel, $apiPayload);
                echo json_encode(['success' => true, 'data' => ['success' => true, 'reply' => $reply]]);
            } catch (Throwable $e) {
                // フロントエンドが {success, reply, error} を期待しているため、AI失敗は内側でキャッチしてエラー文を返す
                echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => $e->getMessage()]]);
            }
            break;

        // ============================================================
        // getSystemSpecForAI — AI仕様書本文をDBから取得（SettingsView表示用）
        // ============================================================
        case 'getSystemSpecForAI':
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'systemSpecForAI'");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            // setting_valueは「rawマークダウン文字列」で保存する設計
            // （他のsettingsキーは json_encode 済みだが、これは可読性と更新の単純さを優先）
            $spec = ($raw === false || $raw === null) ? '' : (string)$raw;
            echo json_encode(['success' => true, 'data' => ['text' => $spec]]);
            break;

        // ============================================================
        // verifyGoogleAuth — Google ID Token検証 + セッション開始
        // ============================================================
        case 'verifyGoogleAuth':
            $idToken = $payload['idToken'] ?? '';
            if (!$idToken) {
                echo json_encode(['success' => false, 'error' => 'IDトークンが指定されていません']);
                break;
            }

            // Googleのtokeninfoエンドポイントで検証（署名・有効期限・aud検証もGoogle側でやってくれる）
            $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code !== 200) {
                echo json_encode(['success' => false, 'error' => 'IDトークンの検証に失敗しました']);
                break;
            }
            $tokenData = json_decode($res, true);

            // audience（クライアントID）の照合
            if (($tokenData['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
                echo json_encode(['success' => false, 'error' => 'クライアントIDが一致しません']);
                break;
            }
            // emailの確認
            $email = $tokenData['email'] ?? '';
            if (!$email || ($tokenData['email_verified'] ?? '') !== 'true') {
                echo json_encode(['success' => false, 'error' => 'メールアドレスが確認できません']);
                break;
            }

            // membersテーブルに登録があるか
            $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $member = $stmt->fetch();
            if (!$member) {
                echo json_encode(['success' => false, 'error' => 'このGoogleアカウント (' . htmlspecialchars($email) . ') は登録されていません。管理者に連絡してください。']);
                break;
            }

            // セッション再生成（セッション固定攻撃対策）
            session_regenerate_id(true);
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name']  = $member['name'];
            echo json_encode(['success' => true, 'data' => [
                'email' => $email,
                'name'  => $member['name'],
            ]]);
            break;

        // ============================================================
        // logout — セッション破棄
        // ============================================================
        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']);
            }
            session_destroy();
            echo json_encode(['success' => true, 'data' => null]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
