<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth/auth.php';

auth_start_session();

header('Content-Type: application/json; charset=utf-8');

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $input['action'] ?? '';
$payload = $input['payload'] ?? [];

// 認証ガード（provider が公開しているアクション + logout 以外はセッション必須）
auth_require_session($action);


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

    // ===== アクセストークンのキャッシュ =====
    // 以前は呼び出しごとに OAuth トークンを新規取得しており、画像アップロード 1 枚
    // につき毎回 Google への往復が発生していた。複数枚を同時にアップロードすると
    // PHP ワーカーを長時間占有し、サイト全体のスローダウン（getInitialData の遅延）
    // を招く一因になっていた。scope + clientEmail 単位でファイルにキャッシュする。
    //   - TTL は Google が返す expires_in 準拠（期限 120 秒前で失効扱い）
    //   - 保存先はドキュメントルート外を最優先。config.php と同じ階層（require の
    //     '../../../config.php' が指す = web 非公開域）に書く。ここはトークン同様の
    //     機密(config.php)が既に置かれている場所なので一貫性がある。書けない環境は
    //     session 保存ディレクトリ→システム一時ディレクトリへフォールバック。
    //     ※ session_save_path() は空文字を返す環境があるため ?: で握りつぶさない
    //   - パーミッションは 0600 に絞り、web 経由でも読めないようにする
    //   - 読み書きに失敗しても素通しで従来どおり毎回取得にフォールバック（安全側）
    $privateBase   = dirname(__DIR__, 3);          // config.php と同階層（ドキュメントルート外）
    $sessionPath   = session_save_path();
    $tokenCacheDir = (is_dir($privateBase) && is_writable($privateBase))
        ? $privateBase
        : (($sessionPath !== '' && is_dir($sessionPath) && is_writable($sessionPath)) ? $sessionPath : sys_get_temp_dir());
    $tokenCacheFile = rtrim($tokenCacheDir, '/\\') . '/.olivenote_gtoken_' . md5($scope . '|' . $clientEmail) . '.json';
    if (is_readable($tokenCacheFile)) {
        $cachedToken = json_decode((string)@file_get_contents($tokenCacheFile), true);
        if (is_array($cachedToken) && !empty($cachedToken['access_token'])
            && isset($cachedToken['expires_at']) && $cachedToken['expires_at'] > time() + 120) {
            return $cachedToken['access_token'];
        }
    }

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

    // 取得できたトークンをキャッシュ（書き込み失敗は無視＝次回また取得するだけ）
    $expiresIn = (int)($json['expires_in'] ?? 3600);
    if (@file_put_contents(
            $tokenCacheFile,
            json_encode(['access_token' => $json['access_token'], 'expires_at' => $now + $expiresIn]),
            LOCK_EX
        ) !== false) {
        @chmod($tokenCacheFile, 0600);
    }

    return $json['access_token'];
}

/**
 * description 内のインライン Base64 画像を AI 入力前に取り除く。
 *
 * フロント (RichMarkdownEditor) は画像挿入時に
 *   [![alt](data:image/...;base64,xxxx)](https://drive.google.com/file/d/{id}/view)
 * という形でリンク付きの base64 画像を埋め込む。これをそのまま AI に送ると
 * 数MB の base64 文字列でトークンが浪費されるため、AI 系エンドポイントの
 * 入力サニタイズとして使う。
 *
 *   [![alt](data:...)](href)   → [画像: alt](href)
 *   ![alt](data:...)           → [画像: alt]
 *
 * alt が空なら "画像" 固定。href はそのまま残すので AI は「画像が貼られている」
 * 事実と Drive 上のリンクを認識できる。
 */
function stripBase64Images(string $md): string {
    if ($md === '') return $md;
    if (strpos($md, 'data:image/') === false) return $md;

    // PCRE のバックトラック爆発を避けるため、文字クラスを厳密に絞る:
    //   - MIME サブタイプ: [a-zA-Z0-9.+-]+
    //   - base64 本体    : [A-Za-z0-9+/=]+   ← ) を含まないため貪欲でも安全
    // それでも数MB の base64 を扱うので、念のため pcre.backtrack_limit を一時的に引き上げる。
    // preg_* が NULL を返した場合は元文字列を返してフェイルセーフ。

    $oldBacktrack = ini_get('pcre.backtrack_limit');
    $oldRecursion = ini_get('pcre.recursion_limit');
    // 50MB の base64 を想定して大きめに（一時設定、関数抜けで戻す）
    ini_set('pcre.backtrack_limit', '100000000');
    ini_set('pcre.recursion_limit', '100000000');

    try {
        // リンク付き画像: [![alt](data:image/...;base64,...)](href)
        $r1 = preg_replace_callback(
            '/\[!\[([^\]]*)\]\(data:image\/[a-zA-Z0-9.+\-]+;base64,[A-Za-z0-9+\/=]+\)\]\(([^)]+)\)/',
            function ($m) {
                $alt = trim($m[1]) !== '' ? trim($m[1]) : '画像';
                return '[画像: ' . $alt . '](' . $m[2] . ')';
            },
            $md
        );
        if ($r1 !== null && preg_last_error() === PREG_NO_ERROR) {
            $md = $r1;
        }

        // リンクなし画像: ![alt](data:image/...;base64,...)
        $r2 = preg_replace_callback(
            '/!\[([^\]]*)\]\(data:image\/[a-zA-Z0-9.+\-]+;base64,[A-Za-z0-9+\/=]+\)/',
            function ($m) {
                $alt = trim($m[1]) !== '' ? trim($m[1]) : '画像';
                return '[画像: ' . $alt . ']';
            },
            $md
        );
        if ($r2 !== null && preg_last_error() === PREG_NO_ERROR) {
            $md = $r2;
        }
    } finally {
        ini_set('pcre.backtrack_limit', $oldBacktrack);
        ini_set('pcre.recursion_limit', $oldRecursion);
    }

    return $md;
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

// Markdown テキストを Drive にインポートし Google Docs 形式に自動変換する。
// Drive API は target mimeType=application/vnd.google-apps.document を指定して
// text/markdown をアップロードすると、見出し・太字・箇条書き・表をネイティブに変換する。
function uploadMarkdownAsGoogleDoc(string $name, string $markdown, string $folderId, string $token): array {
    $meta = json_encode([
        'name'     => $name,
        'mimeType' => 'application/vnd.google-apps.document',
        'parents'  => [$folderId],
    ], JSON_UNESCAPED_UNICODE);
    $boundary = 'olivenote_' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n"
          . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
          . $meta . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/markdown; charset=UTF-8\r\n\r\n"
          . $markdown . "\r\n"
          . "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Content-Type: multipart/related; boundary={$boundary}",
            'Content-Length: ' . strlen($body),
            'Expect:',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true);
    if ($code !== 200 || empty($json['id'])) {
        throw new Exception('Drive API エラー (Markdown→Doc 変換): ' . $res);
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

/**
 * タスクの親子階層バリデーション。
 * - 最大3階層（親→子→孫）まで許可
 * - 親候補チェーンに自分が含まれる場合（循環参照）は NG
 * - 自分の子孫の最大深さと親候補の階層深さの合計が 3 を超えれば NG
 *
 * @return string|null NG 理由（日本語）。OK の場合は null
 */
function validateTaskParentHierarchy(PDO $pdo, ?string $taskId, ?string $parentId): ?string {
    if ($parentId === null || $parentId === '') return null;
    if ($taskId !== null && $taskId === $parentId) {
        return '親に自分自身は指定できません。';
    }

    // (1) 親候補自身は生きていることを必須
    $liveStmt = $pdo->prepare("SELECT parent_id FROM tasks WHERE id = ? AND deleted_at IS NULL");
    $liveStmt->execute([$parentId]);
    $first = $liveStmt->fetch();
    if (!$first) {
        return '選択した親課題が見つかりません。';
    }

    // (2) 祖先チェーンを辿って深さを取得（親候補自身を 1 とする）。
    //     削除済みも含めてカウントすることで「祖父が削除済みで深さが浅く見える」抜けを防ぐ。
    $anyStmt = $pdo->prepare("SELECT parent_id FROM tasks WHERE id = ?");
    $parentDepth = 1;
    $cur = $first['parent_id'] ?: null;
    for ($i = 0; $i < 10 && $cur !== null && $cur !== ''; $i++) {
        // 循環参照: 親候補の祖先チェーンに自分自身が含まれていれば NG
        if ($taskId !== null && $cur === $taskId) {
            return '親に自分の子孫を指定することはできません。';
        }
        $parentDepth++;
        $anyStmt->execute([$cur]);
        $row = $anyStmt->fetch();
        if (!$row) break;
        $cur = $row['parent_id'] ?: null;
    }

    // (2) 自分の subtree の最大深さ（自分自身を 1 とする）
    $subtreeDepth = 1;
    if ($taskId !== null) {
        $frontier = [$taskId];
        for ($d = 0; $d < 10; $d++) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $cstmt = $pdo->prepare("SELECT id FROM tasks WHERE parent_id IN ($placeholders) AND deleted_at IS NULL");
            $cstmt->execute($frontier);
            $children = $cstmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$children) break;
            $subtreeDepth++;
            $frontier = $children;
        }
    }

    if ($parentDepth + $subtreeDepth > 3) {
        return '階層が3段を超えるため、この親課題は選べません。（最大3階層）';
    }
    return null;
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

// "AI生成" サブフォルダ (DOC_FOLDER_ID 直下) の Drive フォルダ ID を返す。
// 無ければ作成し、settings.aiGeneratedDocsFolderId にキャッシュする。
function ensureAiGeneratedDocsFolder(PDO $pdo, string $token): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'aiGeneratedDocsFolderId'");
    $stmt->execute();
    $cached = $stmt->fetchColumn();
    if ($cached) {
        return (string)$cached;
    }

    $q = "name = 'AI生成' and '" . DOC_FOLDER_ID . "' in parents"
       . " and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q'                         => $q,
        'fields'                    => 'files(id,name)',
        'supportsAllDrives'         => 'true',
        'includeItemsFromAllDrives' => 'true',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $folderId = '';
    if ($code === 200) {
        $json = json_decode($res, true);
        if (!empty($json['files'][0]['id'])) {
            $folderId = (string)$json['files'][0]['id'];
        }
    }
    if ($folderId === '') {
        $created  = createDriveFolder('AI生成', DOC_FOLDER_ID, $token);
        $folderId = (string)$created['id'];
    }

    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('aiGeneratedDocsFolderId', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$folderId]);

    return $folderId;
}

// あるフォルダ配下の生存している子孫IDを再帰的に列挙する（ソフトデリート時のカスケード用）
function collectDescendantIds(PDO $pdo, string $folderId): array {
    $ids   = [];
    $stack = [$folderId];
    $stmt  = $pdo->prepare("SELECT id, mime_type FROM files WHERE parent_id = ? AND deleted_at IS NULL");
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

// Vertex AI Imagen (画像生成) を :predict エンドポイントで呼ぶ。
// 戻り値は predictions 配列 (各要素は {bytesBase64Encoded, mimeType})。
function callVertexImagen(string $modelId, array $payload): array {
    $token = getGoogleAccessToken(
        'https://www.googleapis.com/auth/cloud-platform',
        VERTEX_CLIENT_EMAIL,
        VERTEX_PRIVATE_KEY
    );
    $location  = VERTEX_LOCATION;
    $projectId = VERTEX_PROJECT_ID;
    $url = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models/{$modelId}:predict";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
            'Expect:',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new Exception("Vertex Imagen エラー (HTTP {$code}): " . $res);
    $json = json_decode($res, true);
    if (!is_array($json) || empty($json['predictions'])) {
        throw new Exception('Imagen から有効な応答が返ってきませんでした: ' . $res);
    }
    return $json['predictions'];
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
        'cardColor'          => $row['card_color'] ?? null,
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
// Wiki 用 UUID v4 採番（フレームワーク非依存・ランダム）
// ================================================================
function generateWikiUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ================================================================
// メインルーティング
// ================================================================

try {
    // 認証関連 action（provider 固有 verify / logout）は lib/auth で処理
    if (auth_dispatch($action, $payload, $pdo)) exit;

    // ここから先の通常データ action は $_SESSION を「読む」だけで書き込まない
    // （$_SESSION への書き込みは auth_google.php の verify と auth.php の logout のみで、
    //   いずれも上の auth_dispatch で処理済み）。そのためセッションロックを早期に解放する。
    // これにより、同一ユーザーが複数リクエストを並行実行しても（例: 画像の複数枚同時
    // アップロード）セッションロックで直列化されず、長い Drive アップロード中に本人の
    // 他リクエストや再読み込み（getInitialData）がブロックされない。読み取り済みの
    // $_SESSION 値は close 後もそのまま参照できる。
    session_write_close();

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
            $stmt = $pdo->query("SELECT * FROM files WHERE deleted_at IS NULL ORDER BY last_updated DESC");
            $docs = [];
            while ($row = $stmt->fetch()) {
                $docs[] = docFromRow($row);
            }

            // User preferences（ログイン中ユーザーの表示設定）
            $userPrefs = new stdClass();
            $filterPresets = [];
            if (!empty($currentUser['email'])) {
                try {
                    $stmt = $pdo->prepare("SELECT pref_key, pref_value FROM user_preferences WHERE user_email = ?");
                    $stmt->execute([$currentUser['email']]);
                    $prefsArr = [];
                    while ($row = $stmt->fetch()) {
                        $prefsArr[$row['pref_key']] = json_decode($row['pref_value'], true);
                    }
                    $userPrefs = (object)$prefsArr;

                    $stmt = $pdo->prepare("SELECT id, name, filters, sort_order FROM filter_presets WHERE user_email = ? ORDER BY sort_order ASC, id ASC");
                    $stmt->execute([$currentUser['email']]);
                    while ($row = $stmt->fetch()) {
                        $filterPresets[] = [
                            'id'        => (int)$row['id'],
                            'name'      => $row['name'],
                            'filters'   => json_decode($row['filters'], true) ?: new stdClass(),
                            'sortOrder' => (int)$row['sort_order'],
                        ];
                    }
                } catch (Throwable $e) {
                    // テーブル未作成（マイグレーション未適用）でも getInitialData は壊さない
                    error_log('[getInitialData] user_preferences/filter_presets load failed: ' . $e->getMessage());
                }
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
                    'userPreferences' => $userPrefs,
                    'filterPresets'   => $filterPresets,
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

            // 親子階層バリデーション（最大3階層、循環参照防止）
            $hierErr = validateTaskParentHierarchy(
                $pdo,
                $isNew ? null : $task['id'],
                !empty($task['parentId']) ? $task['parentId'] : null
            );
            if ($hierErr !== null) {
                echo json_encode(['success' => false, 'error' => $hierErr]);
                break;
            }

            $pdo->prepare("
                INSERT INTO tasks (
                    id, title, description, status, priority, type, category, card_color, parent_id,
                    start_date, due_date, implementation_date, implementation_days,
                    assignee_email, assignee_name, sub_assignees, likes, attachments, sort_order
                ) VALUES (
                    :id, :title, :description, :status, :priority, :type, :category, :card_color, :parent_id,
                    :start_date, :due_date, :implementation_date, :implementation_days,
                    :assignee_email, :assignee_name, :sub_assignees, :likes, :attachments, :sort_order
                ) ON DUPLICATE KEY UPDATE
                    title               = VALUES(title),
                    description         = VALUES(description),
                    status              = VALUES(status),
                    priority            = VALUES(priority),
                    type                = VALUES(type),
                    category            = VALUES(category),
                    card_color          = VALUES(card_color),
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
                ':card_color'          => !empty($task['cardColor']) ? $task['cardColor'] : null,
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
            $mimeType = $payload['mimeType'] ?? '';
            $data     = $payload['data']     ?? '';

            // ブラウザが MIME を判定できなかった場合は Drive 側で推定させるため octet-stream を渡す
            if (trim($mimeType) === '') {
                $mimeType = 'application/octet-stream';
            }
            // `text/html; charset=utf-8` のような charset 付きで届くケースに備え、パラメータ部は落とす
            if (strpos($mimeType, ';') !== false) {
                $mimeType = trim(substr($mimeType, 0, strpos($mimeType, ';')));
            }
            // 想定外の文字が混入していれば octet-stream にフォールバック（Drive が拒否する形式ならエラーで返る）
            if (!preg_match('#^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$#', $mimeType)) {
                $mimeType = 'application/octet-stream';
            }

            $decoded = base64_decode($data, true);
            if ($decoded === false || $decoded === '') {
                echo json_encode(['success' => false, 'error' => 'ファイルのデコードに失敗しました']);
                break;
            }
            // Drive 側の上限は実質無いが、PHP の post_max_size と整合する 50MB を上限とする
            if (strlen($decoded) > 50 * 1024 * 1024) {
                echo json_encode(['success' => false, 'error' => 'ファイルサイズは50MB以下にしてください']);
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

            $pdo->prepare("INSERT INTO files (id, name, url, parent_id, mime_type, last_updated) VALUES (?, ?, ?, ?, ?, ?)")
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

            $pdo->prepare("INSERT INTO files (id, name, url, parent_id, mime_type, last_updated) VALUES (?, ?, ?, ?, ?, ?)")
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
            $stmt = $pdo->prepare("SELECT parent_id, mime_type FROM files WHERE id = ? AND deleted_at IS NULL");
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
                $check = $pdo->prepare("SELECT mime_type, deleted_at FROM files WHERE id = ?");
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

            $pdo->prepare("UPDATE files SET parent_id = ? WHERE id = ?")
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
            $stmt = $pdo->prepare("SELECT mime_type FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $row = $stmt->fetch();
            $isFolder = $row && ($row['mime_type'] ?? '') === 'application/vnd.google-apps.folder';

            $idsToDelete = [$fileId];
            if ($isFolder) {
                $idsToDelete = array_merge($idsToDelete, collectDescendantIds($pdo, $fileId));
            }

            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $pdo->prepare("UPDATE files SET deleted_at = NOW() WHERE id IN ({$placeholders})")
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
            $stmt   = $pdo->query("SELECT id, name, url, parent_id, mime_type, last_updated, deleted_at FROM files");
            $dbDocs = [];
            while ($row = $stmt->fetch()) {
                $dbDocs[$row['id']] = $row;
            }

            // 3) リコンサイル
            $inserted = 0; $updated = 0; $restored = 0; $deleted = 0;

            $pdo->beginTransaction();
            try {
                $insStmt = $pdo->prepare("INSERT INTO files (id, name, url, parent_id, mime_type, last_updated) VALUES (?, ?, ?, ?, ?, ?)");
                $updStmt = $pdo->prepare("UPDATE files SET name = ?, url = ?, parent_id = ?, mime_type = ?, last_updated = ?, deleted_at = NULL WHERE id = ?");
                $delStmt = $pdo->prepare("UPDATE files SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");

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
        // findOrphanAttachments — ATTACHMENT_FOLDER_ID 配下で
        //   tasks.description / tasks.attachments / comments.text の
        //   いずれからも参照されていないファイルを抽出（dry-run 専用、削除はしない）
        //   - 管理者のみ実行可
        //   - description / comments の参照は Drive ファイル ID の正規表現抽出
        //     （file/d/{id} / lh3.googleusercontent.com/d/{id} / uc?id={id} すべて見る）
        // ============================================================
        case 'findOrphanAttachments':
            requireAdmin($pdo);

            $token = getGoogleAccessToken('https://www.googleapis.com/auth/drive.readonly');

            // 1) ATTACHMENT_FOLDER_ID 配下を全列挙（trashed=false のみ）
            $driveFiles = [];
            $pageToken  = null;
            do {
                $params = [
                    'q'                         => "'" . ATTACHMENT_FOLDER_ID . "' in parents and trashed=false",
                    'fields'                    => 'nextPageToken,files(id,name,size,mimeType,createdTime,modifiedTime,webViewLink,thumbnailLink)',
                    'pageSize'                  => '1000',
                    'supportsAllDrives'         => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ];
                if ($pageToken) $params['pageToken'] = $pageToken;
                $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);
                $ch  = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                ]);
                $res  = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code !== 200) throw new Exception("Drive 一覧取得エラー (HTTP {$code}): " . $res);
                $json = json_decode($res, true);
                foreach ($json['files'] ?? [] as $f) {
                    $driveFiles[$f['id']] = $f;
                }
                $pageToken = $json['nextPageToken'] ?? null;
            } while ($pageToken);

            // 2) DB の生存テキスト・JSON すべてから Drive ファイル ID を集約
            //    deleted_at が set されている課題も「論理削除のため復元される可能性」を考慮して含める
            $referencedIds = [];
            $patterns      = [
                '#drive\.google\.com/file/d/([a-zA-Z0-9_-]{15,})#',
                '#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]{15,})#',
                '#drive\.google\.com/uc\?id=([a-zA-Z0-9_-]{15,})#',
                '#drive\.google\.com/thumbnail\?id=([a-zA-Z0-9_-]{15,})#',
            ];
            $collectFromText = function (string $txt) use (&$referencedIds, $patterns) {
                if ($txt === '') return;
                foreach ($patterns as $p) {
                    if (preg_match_all($p, $txt, $m)) {
                        foreach ($m[1] as $id) $referencedIds[$id] = true;
                    }
                }
            };

            // tasks.description（ソフトデリート分も含む）
            $stmt = $pdo->query("SELECT description FROM tasks WHERE description IS NOT NULL AND description <> ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $collectFromText((string)($row['description'] ?? ''));
            }

            // tasks.attachments（JSON 配列 [{id, name, url}, ...]）
            $stmt = $pdo->query("SELECT attachments FROM tasks WHERE attachments IS NOT NULL AND attachments <> ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $arr = json_decode((string)$row['attachments'], true);
                if (is_array($arr)) {
                    foreach ($arr as $a) {
                        if (is_array($a) && !empty($a['id'])) {
                            $referencedIds[(string)$a['id']] = true;
                        }
                        // 念のため url からも抽出
                        if (is_array($a) && !empty($a['url'])) {
                            $collectFromText((string)$a['url']);
                        }
                    }
                }
            }

            // comments.text
            $stmt = $pdo->query("SELECT text FROM comments WHERE text IS NOT NULL AND text <> ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $collectFromText((string)($row['text'] ?? ''));
            }

            // 3) 孤児抽出
            $orphans   = [];
            $totalSize = 0;
            foreach ($driveFiles as $id => $f) {
                if (!isset($referencedIds[$id])) {
                    $size      = isset($f['size']) ? (int)$f['size'] : 0;
                    $totalSize += $size;
                    $orphans[] = [
                        'id'            => $id,
                        'name'          => $f['name']         ?? '(無題)',
                        'size'          => $size,
                        'mimeType'      => $f['mimeType']     ?? '',
                        'createdTime'   => $f['createdTime']  ?? '',
                        'modifiedTime' => $f['modifiedTime'] ?? '',
                        'webViewLink'   => $f['webViewLink']  ?? '',
                        'thumbnailLink' => $f['thumbnailLink'] ?? '',
                    ];
                }
            }

            // 新しい順にソート（ユーザーがざっと見て安心して削除判断できるように）
            usort($orphans, function ($a, $b) {
                return strcmp($b['modifiedTime'], $a['modifiedTime']);
            });

            echo json_encode(['success' => true, 'data' => [
                'driveTotal'           => count($driveFiles),
                'referencedTotal'      => count($referencedIds),
                'orphanCount'          => count($orphans),
                'orphanTotalSizeBytes' => $totalSize,
                'orphans'              => $orphans,
            ]]);
            break;

        // ============================================================
        // trashOrphanAttachments — クライアントから渡された ID 群を
        //   再度孤児判定でフィルタしてから Drive ゴミ箱へ移動（trashed=true）
        //   - 管理者のみ
        //   - dry-run と execute の間に DB に新しい参照が増えた場合への safeguard として
        //     execute 直前に再スキャンを実施し、参照に出現するようになった ID は除外する
        //   - 30 日以内なら Google Drive ゴミ箱から復元可能（完全削除はしない）
        // ============================================================
        case 'trashOrphanAttachments':
            requireAdmin($pdo);

            $rawIds = $payload['ids'] ?? [];
            if (!is_array($rawIds) || count($rawIds) === 0) {
                echo json_encode(['success' => false, 'error' => '削除対象 ID が指定されていません']);
                break;
            }
            // 文字列化して unique 化
            $requestedIds = [];
            foreach ($rawIds as $rid) {
                $rid = (string)$rid;
                if ($rid !== '') $requestedIds[$rid] = true;
            }
            if (count($requestedIds) === 0) {
                echo json_encode(['success' => false, 'error' => '削除対象 ID が空です']);
                break;
            }

            // ---- safeguard: 直前にもう一度参照スキャンを走らせて、現役参照に出現した ID は除外 ----
            $referencedNow = [];
            $patterns      = [
                '#drive\.google\.com/file/d/([a-zA-Z0-9_-]{15,})#',
                '#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]{15,})#',
                '#drive\.google\.com/uc\?id=([a-zA-Z0-9_-]{15,})#',
                '#drive\.google\.com/thumbnail\?id=([a-zA-Z0-9_-]{15,})#',
            ];
            $collect = function (string $txt) use (&$referencedNow, $patterns) {
                if ($txt === '') return;
                foreach ($patterns as $p) {
                    if (preg_match_all($p, $txt, $m)) {
                        foreach ($m[1] as $id) $referencedNow[$id] = true;
                    }
                }
            };
            $stmt = $pdo->query("SELECT description FROM tasks WHERE description IS NOT NULL AND description <> ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $collect((string)$row['description']);
            $stmt = $pdo->query("SELECT attachments FROM tasks WHERE attachments IS NOT NULL AND attachments <> ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $arr = json_decode((string)$row['attachments'], true);
                if (is_array($arr)) foreach ($arr as $a) {
                    if (is_array($a) && !empty($a['id'])) $referencedNow[(string)$a['id']] = true;
                    if (is_array($a) && !empty($a['url'])) $collect((string)$a['url']);
                }
            }
            $stmt = $pdo->query("SELECT text FROM comments WHERE text IS NOT NULL AND text <> ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $collect((string)$row['text']);

            $token   = getGoogleAccessToken();
            $trashed = [];
            $failed  = [];
            $skippedNowReferenced = [];

            foreach (array_keys($requestedIds) as $fid) {
                if (isset($referencedNow[$fid])) {
                    // safeguard 発動: 再スキャンで参照が見つかった ID は触らない
                    $skippedNowReferenced[] = $fid;
                    continue;
                }
                try {
                    trashDriveFile($fid, $token);
                    $trashed[] = $fid;
                } catch (Throwable $e) {
                    $failed[] = ['id' => $fid, 'error' => $e->getMessage()];
                }
            }

            echo json_encode(['success' => true, 'data' => [
                'requestedCount'       => count($requestedIds),
                'trashedCount'         => count($trashed),
                'failedCount'          => count($failed),
                'skippedNowReferencedCount' => count($skippedNowReferenced),
                'failed'               => $failed,
                'skippedNowReferenced' => $skippedNowReferenced,
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
            $pdo->prepare("INSERT INTO files (id, name, url, last_updated) VALUES (?, ?, ?, ?)")
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
            $desc     = !empty($task['description']) ? stripBase64Images((string)$task['description']) : '(なし)';

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

                    // description のインライン Base64 画像は AI に渡さない（トークン浪費回避）
                    foreach ($cappedTasks as &$_ct) {
                        if (is_array($_ct) && isset($_ct['description'])) {
                            $_ct['description'] = stripBase64Images((string)$_ct['description']);
                        }
                    }
                    unset($_ct);

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
                    $desc   = stripBase64Images((string)($taskContext['description'] ?? '未設定'));
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
        // generateAdvisorDoc — タスクアドバイザーの会話を Google Doc 化
        //   入力 payload:
        //     task:         { id, title, description, status, dueDate, priority, type, category,
        //                     assigneeName, assigneeEmail, ... } — TaskModal の formData
        //     history:      [{role: 'user'|'model', text}] アドバイザーチャット履歴
        //     formatPrompt: string — ユーザー指定のドキュメント形式/観点
        //   出力 data: { success, docId, docUrl, docName, comment }
        // ============================================================
        case 'generateAdvisorDoc':
            try {
                $task         = is_array($payload['task'] ?? null)    ? $payload['task']    : [];
                $chatHistory  = is_array($payload['history'] ?? null) ? $payload['history'] : [];
                $formatPrompt = trim((string)($payload['formatPrompt'] ?? ''));

                $taskId    = (string)($task['id']            ?? '');
                $title     = (string)($task['title']         ?? '無題');
                $desc      = stripBase64Images((string)($task['description'] ?? ''));
                $status    = (string)($task['status']        ?? '');
                $due       = (string)($task['dueDate']       ?? '');
                $priority  = (string)($task['priority']      ?? '');
                $typeName  = (string)($task['type']          ?? '');
                $category  = (string)($task['category']      ?? '');
                $assigneeN = (string)($task['assigneeName']  ?? '');

                if ($taskId === '') {
                    echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => '課題IDが指定されていません。']]);
                    break;
                }
                if ($formatPrompt === '') {
                    echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => 'ドキュメントの形式（プロンプト）を入力してください。']]);
                    break;
                }

                // 壁打ち履歴を整形
                $historyLines = [];
                foreach ($chatHistory as $msg) {
                    $r = (($msg['role'] ?? '') === 'model') ? 'アドバイザー' : 'ユーザー';
                    $t = trim((string)($msg['text'] ?? ''));
                    if ($t === '') continue;
                    $historyLines[] = "## {$r}\n{$t}";
                }
                $historyText = count($historyLines) > 0 ? implode("\n\n", $historyLines) : '（壁打ち履歴なし）';

                $taskBlock =
                    "- ID: {$taskId}\n" .
                    "- タイトル: {$title}\n" .
                    "- 詳細: "       . ($desc      !== '' ? $desc      : '（未設定）') . "\n" .
                    "- ステータス: " . ($status    !== '' ? $status    : '（未設定）') . "\n" .
                    "- 期限: "       . ($due       !== '' ? $due       : '（未設定）') . "\n" .
                    "- 優先度: "     . ($priority  !== '' ? $priority  : '（未設定）') . "\n" .
                    "- 種別: "       . ($typeName  !== '' ? $typeName  : '（未設定）') . "\n" .
                    "- カテゴリ: "   . ($category  !== '' ? $category  : '（未設定）') . "\n" .
                    "- 担当者: "     . ($assigneeN !== '' ? $assigneeN : '（未設定）');

                $prompt =
                    "あなたは Olive Note のドキュメント作成アシスタントです。\n" .
                    "下記の課題情報とアドバイザーとの壁打ち履歴を踏まえ、ユーザーが指定した形式で Google Docs に取り込むための **Markdown 形式** のドキュメントを生成してください。\n" .
                    "Drive API が Markdown を Google Docs に自動変換するため、見出し・太字・箇条書き・表などはすべて Markdown 記法を使ってください。\n\n" .
                    "【出力ルール】\n" .
                    "- 見出しは `# / ## / ###` を必ず使う。文書冒頭にタイトル相当の `#` を置く。\n" .
                    "- 強調は `**太字**`、リストは `- ` または `1. `、表は GitHub Flavored Markdown のパイプ表記を使う。\n" .
                    "- コードや擬似コードを示す場合のみ ``` フェンスで囲う。それ以外で ``` を使わない。\n" .
                    "- 出力はドキュメント本文のみ。前置きや後書き（『以下に作成します』『ご確認ください』等）は書かない。\n" .
                    "- 全体を ``` でラップするのは禁止。\n\n" .
                    "【内容ルール】\n" .
                    "- 課題情報・会話履歴に書かれていない事実を新たに作らない。推測が必要な箇所は『要確認』と明記する。\n" .
                    "- 文章は読み手が単独で理解できる粒度で記述する。\n" .
                    "- ユーザーが指定した形式（議事録／要件定義書／進捗報告 等）を優先する。\n\n" .
                    "【課題情報】\n" .
                    $taskBlock . "\n\n" .
                    "【アドバイザー壁打ち履歴】\n" .
                    $historyText . "\n\n" .
                    "【ユーザーからの指示（出力形式・観点）】\n" .
                    $formatPrompt;

                $generated = callVertexAi('gemini-2.5-pro', [
                    'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 8192],
                ]);
                // 文書全体が ``` でラップされていた場合は剥がす
                $trimmed = ltrim($generated);
                if (preg_match('/^`{3}[a-zA-Z0-9]*\s*\n/', $trimmed)) {
                    $generated = preg_replace('/^`{3}[a-zA-Z0-9]*\s*\n/', '', $trimmed);
                    $generated = preg_replace('/`{3}\s*$/', '', $generated);
                }
                $generated = rtrim($generated, "\r\n");
                if (trim($generated) === '') {
                    throw new Exception('AI が空のテキストを返しました。');
                }

                // Drive のみ (Docs API は使わなくなった)
                $token    = getGoogleAccessToken('https://www.googleapis.com/auth/drive');
                $folderId = ensureAiGeneratedDocsFolder($pdo, $token);

                // Markdown を Google Docs として直接アップロード（Drive が自動変換）
                $today    = date('Y-m-d');
                $docTitle = "【AI生成】{$title}_{$today}";
                $doc      = uploadMarkdownAsGoogleDoc($docTitle, $generated, $folderId, $token);
                $docId    = $doc['id'];
                $docUrl   = $doc['webViewLink'] ?? ('https://docs.google.com/document/d/' . $docId . '/edit');

                // リンクで開けるよう public reader を付与
                makeFilePublic($docId, $token);

                // 課題のコメント欄に Doc リンクを追加
                $authorEmail = (string)($_SESSION['user_email'] ?? '');
                $authorName  = '';
                if ($authorEmail !== '') {
                    $u = $pdo->prepare("SELECT name FROM members WHERE email = ?");
                    $u->execute([$authorEmail]);
                    $authorName = (string)($u->fetchColumn() ?: '');
                }

                $shortFormat = mb_substr($formatPrompt, 0, 80) . (mb_strlen($formatPrompt) > 80 ? '…' : '');
                $safeDocTitle = htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8');
                $safeDocUrl   = htmlspecialchars($docUrl,   ENT_QUOTES, 'UTF-8');
                $safeFormat   = htmlspecialchars($shortFormat, ENT_QUOTES, 'UTF-8');
                $commentText =
                    "📝 **【AIによるドキュメント生成】**\n" .
                    "形式指定: {$safeFormat}\n\n" .
                    "<a href=\"{$safeDocUrl}\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"text-blue-600 hover:underline font-bold\">📎 {$safeDocTitle}</a>";

                $commentId = 'comment_aidoc_' . bin2hex(random_bytes(8));
                $pdo->prepare("
                    INSERT INTO comments (id, task_id, author_email, author_name, text, likes, read_by, created_at)
                    VALUES (:id, :task_id, :author_email, :author_name, :text, :likes, :read_by, NOW())
                ")->execute([
                    ':id'           => $commentId,
                    ':task_id'      => $taskId,
                    ':author_email' => $authorEmail,
                    ':author_name'  => $authorName,
                    ':text'         => $commentText,
                    ':likes'        => json_encode([]),
                    ':read_by'      => json_encode($authorEmail !== '' ? [$authorEmail] : []),
                ]);
                $stmt = $pdo->prepare("SELECT * FROM comments WHERE id = ?");
                $stmt->execute([$commentId]);
                $savedComment = commentFromRow($stmt->fetch());

                echo json_encode(['success' => true, 'data' => [
                    'success' => true,
                    'docId'   => $docId,
                    'docUrl'  => $docUrl,
                    'docName' => $docTitle,
                    'comment' => $savedComment,
                ]]);
            } catch (Throwable $e) {
                echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => $e->getMessage()]]);
            }
            break;

        // ============================================================
        // generateImage — Vertex AI Imagen で画像を生成 → AI生成フォルダ保存 → コメント貼付
        //   入力 payload:
        //     task:         { id, title, ... } TaskModal.formData
        //     prompt:       string ユーザーが指定する画像生成プロンプト
        //     aspectRatio:  '1:1' | '16:9' | '9:16' | '4:3' | '3:4' (省略時 '1:1')
        //   出力 data: { success, fileId, fileUrl, fileName, comment }
        // ============================================================
        case 'generateImage':
            try {
                $task        = is_array($payload['task'] ?? null) ? $payload['task'] : [];
                $imgPrompt   = trim((string)($payload['prompt'] ?? ''));
                $aspectRatio = (string)($payload['aspectRatio'] ?? '1:1');

                $taskId   = (string)($task['id']    ?? '');
                $taskName = (string)($task['title'] ?? '無題');

                if ($taskId === '') {
                    echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => '課題IDが指定されていません。']]);
                    break;
                }
                if ($imgPrompt === '') {
                    echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => '画像生成プロンプトを入力してください。']]);
                    break;
                }
                $allowedRatios = ['1:1', '16:9', '9:16', '4:3', '3:4'];
                if (!in_array($aspectRatio, $allowedRatios, true)) {
                    $aspectRatio = '1:1';
                }

                // Imagen 呼び出し（1枚ずつ生成）
                $predictions = callVertexImagen('imagen-3.0-generate-002', [
                    'instances'  => [['prompt' => $imgPrompt]],
                    'parameters' => [
                        'sampleCount'    => 1,
                        'aspectRatio'    => $aspectRatio,
                        'language'       => 'auto',
                        'addWatermark'   => false,
                    ],
                ]);

                $first = $predictions[0] ?? [];
                $b64   = (string)($first['bytesBase64Encoded'] ?? '');
                if ($b64 === '') {
                    throw new Exception('Imagen が画像データを返しませんでした。安全フィルタで弾かれた可能性があります。');
                }
                $mime  = (string)($first['mimeType'] ?? 'image/png');
                $bin   = base64_decode($b64, true);
                if ($bin === false) {
                    throw new Exception('画像のデコードに失敗しました。');
                }

                // Drive にアップロード（AI生成フォルダ配下）
                $driveToken = getGoogleAccessToken('https://www.googleapis.com/auth/drive');
                $folderId   = ensureAiGeneratedDocsFolder($pdo, $driveToken);

                $ext = ($mime === 'image/jpeg') ? 'jpg' : 'png';
                $stamp    = date('Ymd_His');
                $fileName = "ai_image_{$stamp}_" . bin2hex(random_bytes(3)) . ".{$ext}";

                $driveFile = uploadFileToDrive($fileName, $mime, $bin, $folderId, $driveToken);
                makeFilePublic($driveFile['id'], $driveToken);

                $fileId    = $driveFile['id'];
                $directUrl = 'https://lh3.googleusercontent.com/d/' . $fileId;  // インライン表示用
                $viewUrl   = $driveFile['webViewLink'] ?? ('https://drive.google.com/file/d/' . $fileId . '/view');

                // コメント本文（インライン画像 + 別タブリンク）
                $authorEmail = (string)($_SESSION['user_email'] ?? '');
                $authorName  = '';
                if ($authorEmail !== '') {
                    $u = $pdo->prepare("SELECT name FROM members WHERE email = ?");
                    $u->execute([$authorEmail]);
                    $authorName = (string)($u->fetchColumn() ?: '');
                }

                $shortPrompt    = mb_substr($imgPrompt, 0, 80) . (mb_strlen($imgPrompt) > 80 ? '…' : '');
                $safeShortPrompt = htmlspecialchars($shortPrompt, ENT_QUOTES, 'UTF-8');
                $safeViewUrl    = htmlspecialchars($viewUrl,  ENT_QUOTES, 'UTF-8');
                $safeDirectUrl  = htmlspecialchars($directUrl, ENT_QUOTES, 'UTF-8');
                $safeFileName   = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
                $safeAspect     = htmlspecialchars($aspectRatio, ENT_QUOTES, 'UTF-8');
                $commentText =
                    "🎨 **【AIによる画像生成】**\n" .
                    "プロンプト: {$safeShortPrompt}（{$safeAspect}）\n\n" .
                    "![{$safeFileName}]({$safeDirectUrl})\n" .
                    "<a href=\"{$safeViewUrl}\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"text-xs text-blue-600 hover:underline\">📎 画像を別タブで開く</a>";

                $commentId = 'comment_aiimg_' . bin2hex(random_bytes(8));
                $pdo->prepare("
                    INSERT INTO comments (id, task_id, author_email, author_name, text, likes, read_by, created_at)
                    VALUES (:id, :task_id, :author_email, :author_name, :text, :likes, :read_by, NOW())
                ")->execute([
                    ':id'           => $commentId,
                    ':task_id'      => $taskId,
                    ':author_email' => $authorEmail,
                    ':author_name'  => $authorName,
                    ':text'         => $commentText,
                    ':likes'        => json_encode([]),
                    ':read_by'      => json_encode($authorEmail !== '' ? [$authorEmail] : []),
                ]);
                $stmt = $pdo->prepare("SELECT * FROM comments WHERE id = ?");
                $stmt->execute([$commentId]);
                $savedComment = commentFromRow($stmt->fetch());

                echo json_encode(['success' => true, 'data' => [
                    'success'  => true,
                    'fileId'   => $fileId,
                    'fileUrl'  => $viewUrl,
                    'imageUrl' => $directUrl,
                    'fileName' => $fileName,
                    'comment'  => $savedComment,
                ]]);
            } catch (Throwable $e) {
                echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => $e->getMessage()]]);
            }
            break;

        // ============================================================
        // generateTasksFromContext — 複数の資料(画像/PDF/CSV/テキスト)から課題を一括生成
        //   入力 payload:
        //     sources:      [{kind: 'text'|'image'|'pdf'|'sheet', name: string,
        //                     mimeType?: string, text?: string, dataBase64?: string}]
        //     instructions: ユーザーからの追加指示 (任意)
        //     context: { categories: [...], taskTypes: [...], members: [{email,name}],
        //                existingTasks: [{id,title}], today: 'YYYY-MM-DD' }
        // ============================================================
        case 'generateTasksFromContext':
            try {
                $sources      = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
                $instructions = trim((string)($payload['instructions'] ?? ''));
                $ctx          = is_array($payload['context'] ?? null)  ? $payload['context']  : [];

                $categories    = is_array($ctx['categories']    ?? null) ? $ctx['categories']    : [];
                $taskTypes     = is_array($ctx['taskTypes']     ?? null) ? $ctx['taskTypes']     : [];
                $members       = is_array($ctx['members']       ?? null) ? $ctx['members']       : [];
                $existingTasks = is_array($ctx['existingTasks'] ?? null) ? $ctx['existingTasks'] : [];
                $today         = (string)($ctx['today'] ?? date('Y-m-d'));

                if (count($sources) === 0 && $instructions === '') {
                    echo json_encode(['success' => true, 'data' => ['success' => false, 'error' => '生成元となる資料または指示が指定されていません。']]);
                    break;
                }

                // 名前→categoryColorIndex などは無視。AI には名前リストだけ渡す
                $categoryNames = array_values(array_map(fn($c) => is_array($c) ? ($c['name'] ?? '') : (string)$c, $categories));
                $categoryNames = array_values(array_filter($categoryNames, fn($x) => $x !== ''));
                $typeNames     = array_values(array_filter(array_map(fn($t) => (string)$t, $taskTypes), fn($x) => $x !== ''));

                // メンバーは email/name のペアで提示。AI が name でマッチして email を返す
                $memberList = [];
                foreach ($members as $m) {
                    if (!is_array($m)) continue;
                    $email = (string)($m['email'] ?? '');
                    $name  = (string)($m['name']  ?? '');
                    if ($email === '') continue;
                    $memberList[] = ['email' => $email, 'name' => $name];
                }

                // 既存タスクは「親候補」と「重複検出」両方に使う
                // title + description(短縮) + category/type/dueDate を AI に提示
                $existingForCtx = [];
                foreach ($existingTasks as $t) {
                    if (!is_array($t)) continue;
                    $id    = (string)($t['id'] ?? '');
                    $title = (string)($t['title'] ?? '');
                    if ($id === '' || $title === '') continue;
                    $existingForCtx[] = [
                        'id'          => $id,
                        'title'       => $title,
                        // インライン Base64 画像を先に剥がしてから 200 字に切る（生 base64 で 200 字埋まる事故を防ぐ）
                        'description' => mb_substr(stripBase64Images((string)($t['description'] ?? '')), 0, 200),
                        'category'    => (string)($t['category'] ?? ''),
                        'type'        => (string)($t['type'] ?? ''),
                        'dueDate'     => (string)($t['dueDate'] ?? ''),
                    ];
                }
                // 多すぎる場合は先頭 200 件まで（client 側で recency 順に絞られている前提）
                if (count($existingForCtx) > 200) $existingForCtx = array_slice($existingForCtx, 0, 200);

                // -----------------------------------------------------------
                // システム指示
                // -----------------------------------------------------------
                $systemInstruction =
                    "あなたはタスク管理ツール「Olive Note」の課題生成アシスタントです。\n" .
                    "ユーザーから渡される複数の資料（テキスト・画像・PDF・表データ）を分析し、" .
                    "Olive Note に登録できる『課題』を 1 件以上の JSON 配列として返してください。\n\n" .

                    "【最重要：コンテキスト厳守ルール（ハルシネーション禁止）】\n" .
                    "あなたが生成してよいのは、ユーザーから提供された下記いずれかに **直接根拠が記述されている内容のみ** です。\n" .
                    "  (a) 添付資料（テキスト・画像・PDF・表データ）の本文\n" .
                    "  (b) ユーザーからの追加指示\n" .
                    "それ以外（あなたの一般知識・ベストプラクティス・業界常識・前提知識）からの **補完・推測・追加は一切禁止** です。具体的には:\n" .
                    "- 資料に書かれていないタスクを「あった方が良い」と判断して生成しない（例: 資料に『見積もり作成』だけがあるとき、勝手に『見積もりレビュー』『顧客承認取得』を追加しない）\n" .
                    "- description 本文に資料から派生しない一般論・コツ・注意事項を盛り込まない（例: 資料に『契約書を送付』とだけある場合、『電子署名の場合は…』のような知識ベースの補足を書かない）\n" .
                    "- 担当者・期限・優先度・カテゴリ・種別は **資料中に明示的な根拠がある場合のみ** 設定する。読み取れない項目は null か空文字。『常識的に妥当』『プロジェクトの性質から推定して』のような推測は禁止\n" .
                    "- 固有名詞・数値・日付・URL・人名は資料から **そのまま転記** する。表記揺れの正規化や省略形の展開を含めて改変しない\n" .
                    "- 資料が極端に少ない／曖昧で課題化できない場合は、JSON 配列を空 `[]` で返してよい。無理に水増ししない\n" .
                    "- 各タスクの sourceHint には、どの資料のどの記述から生成したかを必ず記入する。記入できない場合はそのタスクを生成してはいけない\n" .
                    "ユーザーの追加指示で具体的な書式・粒度・担当者の固定が指示された場合は、それは『コンテキストの一部』として遵守してよい。\n\n" .

                    "【出力フォーマット】\n" .
                    "次のスキーマで JSON 配列のみを返す。前後の説明文・コードフェンス・markdown は禁止:\n" .
                    "[\n" .
                    "  {\n" .
                    "    \"tempId\":         \"T1\",                 // 同じレスポンス内で一意な仮ID。親子参照に使う\n" .
                    "    \"title\":          \"...\",                // 80文字以内、簡潔に\n" .
                    "    \"description\":    \"<Markdown>\",          // ★必ず Markdown 形式で記述。仕様は下記『description のMarkdownガイド』参照\n" .
                    "    \"priority\":       \"high\"|\"medium\"|\"low\",  // 既定: medium。必ずこの3つの英字IDから選ぶ（日本語ラベルは不可）\n" .
                    "    \"type\":           \"...\",                // 種別一覧から選択。該当無しなら空文字\n" .
                    "    \"category\":       \"...\",                // カテゴリ一覧から選択。該当無しなら空文字\n" .
                    "    \"assigneeEmail\":  \"user@example.com\",   // メンバー一覧から該当者の email。判別不能なら空文字\n" .
                    "    \"assigneeName\":   \"...\",                // assigneeEmail とセットで返す名前\n" .
                    "    \"subAssigneeEmails\": [\"...\"],           // サブ担当者の email 配列。任意。無ければ []\n" .
                    "    \"dueDate\":        \"YYYY-MM-DD\"|null,    // 期限。資料から読み取れない場合は null\n" .
                    "    \"startDate\":      \"YYYY-MM-DD\"|null,    // 開始日。読み取れない場合 null\n" .
                    "    \"parentTempId\":   \"T0\"|null,            // 親が同じレスポンス内にある場合に参照\n" .
                    "    \"parentExistingId\": \"TASK-xxxx\"|null,    // 親が既存タスクの場合に参照\n" .
                    "    \"duplicateOfTaskId\": \"TASK-xxxx\"|null,    // 既存タスクと同じ内容を表していると判断した場合、その TASK-ID を返す。確信が無ければ null\n" .
                    "    \"duplicateReason\":   \"...\"|null,          // duplicateOfTaskId が non-null の時、判断根拠を 60字以内で\n" .
                    "    \"sourceHint\":     \"...\"                 // この課題を導いた元資料の短いメモ。ユーザー確認用\n" .
                    "  }\n" .
                    "]\n" .
                    "注: status はこの機能では新規作成扱い (\"todo\") に固定するため AI 側は出力しなくてよい。\n\n" .

                    "【生成ルール】\n" .
                    "- 資料から自然に分割できる単位で課題化する。冗長に細分化しすぎない\n" .
                    "- 親子関係（プロジェクト > サブタスク 等）が読み取れる場合は parentTempId で表現\n" .
                    "- 親候補に該当する既存タスクがあれば parentExistingId を設定\n" .
                    "- カテゴリと種別は **必ず下記の一覧から選ぶ**。新規創出は禁止。該当無しは空文字\n" .
                    "- 担当者はメンバーの name で資料中の表記とゆるくマッチし、メンバー一覧の email を返す\n" .
                    "- 日付は資料中の表現（『来週金曜』『5/30 まで』『相対：3日後』など）を today を基準に解釈\n" .
                    "- 推測できない値は null か空文字。捏造しない\n" .
                    "- title は名詞句で短く\n" .
                    "- 追加指示があればそれを最優先で反映\n\n" .

                    "【description の Markdown ガイド】\n" .
                    "description は **必ず Markdown 形式** で記述する。Olive Note のタスク詳細画面は marked.js でレンダリングされ、見出し・箇条書き・チェックリスト・表・コードブロックを綺麗に表示する。\n" .
                    "推奨構成（資料の情報量に応じて取捨選択）:\n" .
                    "  ### 概要\n" .
                    "  この課題で達成したいこと・背景を 1〜3 行で。\n\n" .
                    "  ### 進め方 / 手順\n" .
                    "  - [ ] ステップ 1\n" .
                    "  - [ ] ステップ 2\n\n" .
                    "  ### 完了条件\n" .
                    "  - 〇〇 ができている\n" .
                    "  - △△ が確認されている\n\n" .
                    "  ### 参考 / 注意点\n" .
                    "  （資料から拾える補足。なければ省略）\n\n" .
                    "ルール:\n" .
                    "- 改行は実際の `\\n`（JSON 文字列内エスケープ）で入れる。1行ベタ書き禁止\n" .
                    "- 見出しは `### ` 以下を推奨（タスク内の階層上 H1/H2 は使わない）\n" .
                    "- 実行可能なアクションは `- [ ] ` でチェックリスト化\n" .
                    "- 強調が必要なら `**太字**`、コード/コマンドは `` `バッククォート` `` で囲む\n" .
                    "- 内容が薄いタスクは『### 概要』だけでも可。逆に過剰なテンプレ埋めは避ける\n" .
                    "- 元資料の固有名詞・数値・期日は そのまま残す。捏造しない\n\n" .

                    "【重複検出（重要）】\n" .
                    "下記『既存タスク一覧』と意味的に同じ内容のタスクを生成した場合、必ず duplicateOfTaskId に該当する TASK-ID を入れ、duplicateReason に理由を簡潔に書くこと。判断基準:\n" .
                    "- 件名がほぼ同じ、または言い換えのレベル\n" .
                    "- description の主要な目的・成果物が一致\n" .
                    "- 担当者・カテゴリ・期限が同一付近で対象が重なる\n" .
                    "完全一致でなくても『これは既にある課題と同じ作業を指している可能性が高い』と判断したら必ず flag する。確信が無ければ null のまま。\n" .
                    "ユーザーが後で判断するため、duplicate flag を付けても出力からは除外しない（必ず JSON 配列の要素として返す）。\n\n" .

                    "【現在の日付】 {$today}\n" .
                    "【カテゴリ一覧】 " . json_encode($categoryNames, JSON_UNESCAPED_UNICODE) . "\n" .
                    "【種別一覧】 "   . json_encode($typeNames,     JSON_UNESCAPED_UNICODE) . "\n" .
                    "【メンバー一覧】 " . json_encode($memberList,    JSON_UNESCAPED_UNICODE) . "\n" .
                    "【既存タスク一覧（親候補 & 重複検出用、抜粋）】 " . json_encode($existingForCtx, JSON_UNESCAPED_UNICODE) . "\n";

                if ($instructions !== '') {
                    $systemInstruction .= "\n【ユーザーからの追加指示】\n" . $instructions . "\n";
                }

                // -----------------------------------------------------------
                // user message を parts 配列に組み立て
                // -----------------------------------------------------------
                $parts = [];
                $parts[] = ['text' => '以下の資料を元に課題 JSON を生成してください。'];

                foreach ($sources as $src) {
                    if (!is_array($src)) continue;
                    $kind = (string)($src['kind'] ?? '');
                    $name = (string)($src['name'] ?? '');

                    if ($kind === 'text' || $kind === 'sheet') {
                        $text = (string)($src['text'] ?? '');
                        if ($text === '') continue;
                        $label = $kind === 'sheet' ? "[表データ: {$name}]" : "[テキスト資料: {$name}]";
                        // 1ソース 50000 文字超えはトリム（UTF-8 安全に切る）
                        if (mb_strlen($text) > 50000) $text = mb_substr($text, 0, 50000) . "\n…(以下省略)";
                        $parts[] = ['text' => $label . "\n" . $text];
                    } elseif ($kind === 'image' || $kind === 'pdf') {
                        $mime = (string)($src['mimeType'] ?? ($kind === 'pdf' ? 'application/pdf' : 'image/jpeg'));
                        $b64  = (string)($src['dataBase64'] ?? '');
                        if ($b64 === '') continue;
                        $parts[] = ['text' => "[添付: {$name}]"];
                        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];
                    }
                }

                // -----------------------------------------------------------
                // Vertex AI 呼び出し
                // -----------------------------------------------------------
                $apiPayload = [
                    'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents'           => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig'   => [
                        // ハルシネーション抑制のため低温（資料からの忠実な抽出に振る）
                        'temperature'      => 0.1,
                        'topP'             => 0.8,
                        'maxOutputTokens'  => 32768,
                        'responseMimeType' => 'application/json',
                    ],
                ];

                $rawOutput = callVertexAi('gemini-2.5-pro', $apiPayload);

                // JSON 抜き出し（マークダウンフェンスが混じる場合の保険）
                $jsonText = trim($rawOutput);
                if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $jsonText, $m)) $jsonText = $m[1];

                $tasks = json_decode($jsonText, true);
                if (!is_array($tasks)) {
                    echo json_encode(['success' => true, 'data' => [
                        'success' => false,
                        'error'   => 'AI 応答を JSON としてパースできませんでした',
                        'raw'     => mb_substr($rawOutput, 0, 2000)
                    ]]);
                    break;
                }

                // サニタイズ（最低限の型チェック）
                $clean = [];
                $allowedCategories = array_flip($categoryNames);
                $allowedTypes      = array_flip($typeNames);
                $allowedEmails     = array_flip(array_column($memberList, 'email'));
                // 既存タスクの ID を flip して、duplicateOfTaskId / parentExistingId の検証に使う
                // 同一 ID が混入した場合に集合が縮まないよう、array_unique で重複排除してから flip
                $allowedExistingIds = array_flip(array_unique(array_column($existingForCtx, 'id')));
                // 既存タスクの title を id 引きでサーバ側でも保険のハードルール重複チェックに使う
                $existingTitleToId = [];
                foreach ($existingForCtx as $et) {
                    $tt = mb_strtolower(trim((string)$et['title']));
                    if ($tt !== '' && !isset($existingTitleToId[$tt])) $existingTitleToId[$tt] = $et['id'];
                }

                foreach ($tasks as $i => $t) {
                    if (!is_array($t)) continue;

                    // AI が返した duplicate flag を検証（実在 ID のみ採用）
                    $dupId = isset($t['duplicateOfTaskId']) && is_string($t['duplicateOfTaskId']) && isset($allowedExistingIds[$t['duplicateOfTaskId']])
                        ? $t['duplicateOfTaskId']
                        : null;

                    // ハードルール: 件名が既存タスクと **完全に一致** する場合は強制的に重複 flag を立てる（AI が見落とした保険）。
                    // ただし「対応」「確認」のような短い汎用件名は誤検知が多いため 4 文字以上の場合に限定する。
                    if ($dupId === null) {
                        $titleKey = mb_strtolower(trim((string)($t['title'] ?? '')));
                        if (mb_strlen($titleKey) >= 4 && isset($existingTitleToId[$titleKey])) {
                            $dupId = $existingTitleToId[$titleKey];
                            $t['duplicateReason'] = '件名が既存タスクと完全一致';
                        }
                    }

                    $clean[] = [
                        'tempId'           => (string)($t['tempId'] ?? ('T' . ($i + 1))),
                        'title'            => mb_substr((string)($t['title'] ?? ''), 0, 200),
                        'description'      => (string)($t['description'] ?? ''),
                        'priority'         => in_array(($t['priority'] ?? ''), ['high','medium','low'], true) ? $t['priority'] : 'medium',
                        'type'             => isset($allowedTypes[$t['type'] ?? '']) ? $t['type'] : '',
                        'category'         => isset($allowedCategories[$t['category'] ?? '']) ? $t['category'] : '',
                        'assigneeEmail'    => isset($allowedEmails[$t['assigneeEmail'] ?? '']) ? $t['assigneeEmail'] : '',
                        'assigneeName'     => (string)($t['assigneeName'] ?? ''),
                        'subAssigneeEmails'=> is_array($t['subAssigneeEmails'] ?? null) ? array_values(array_filter($t['subAssigneeEmails'], fn($e) => isset($allowedEmails[$e]))) : [],
                        'dueDate'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($t['dueDate'] ?? ''))   ? $t['dueDate']   : null,
                        'startDate'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($t['startDate'] ?? '')) ? $t['startDate'] : null,
                        'parentTempId'     => isset($t['parentTempId']) && $t['parentTempId'] !== null && $t['parentTempId'] !== '' ? (string)$t['parentTempId'] : null,
                        'parentExistingId' => isset($t['parentExistingId']) && is_string($t['parentExistingId']) && isset($allowedExistingIds[$t['parentExistingId']]) ? $t['parentExistingId'] : null,
                        'duplicateOfTaskId'=> $dupId,
                        'duplicateReason'  => $dupId !== null ? mb_substr((string)($t['duplicateReason'] ?? ''), 0, 200) : null,
                        'sourceHint'       => mb_substr((string)($t['sourceHint'] ?? ''), 0, 300),
                    ];
                }

                echo json_encode(['success' => true, 'data' => [
                    'success' => true,
                    'tasks'   => $clean,
                ]]);
            } catch (Throwable $e) {
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
        // saveUserPreference — ユーザー別表示設定の upsert（要件2/3/4/6）
        // ============================================================
        case 'saveUserPreference':
            $email = $_SESSION['user_email'] ?? '';
            $key   = $payload['key']   ?? '';
            $value = $payload['value'] ?? null;
            if (!$email || !$key) {
                echo json_encode(['success' => false, 'error' => 'unauthorized or missing key']);
                break;
            }
            if (strlen($key) > 64) {
                echo json_encode(['success' => false, 'error' => 'pref_key too long']);
                break;
            }
            $pdo->prepare("
                INSERT INTO user_preferences (user_email, pref_key, pref_value)
                VALUES (:email, :k, :v)
                ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)
            ")->execute([
                ':email' => $email,
                ':k'     => $key,
                ':v'     => json_encode($value, JSON_UNESCAPED_UNICODE),
            ]);
            echo json_encode(['success' => true, 'data' => null]);
            break;

        // ============================================================
        // listFilterPresets — 自分のフィルタープリセット一覧（要件5）
        // ============================================================
        case 'listFilterPresets':
            $email = $_SESSION['user_email'] ?? '';
            if (!$email) {
                echo json_encode(['success' => false, 'error' => 'unauthorized']);
                break;
            }
            $stmt = $pdo->prepare("SELECT id, name, filters, sort_order FROM filter_presets WHERE user_email = ? ORDER BY sort_order ASC, id ASC");
            $stmt->execute([$email]);
            $presets = [];
            while ($row = $stmt->fetch()) {
                $presets[] = [
                    'id'        => (int)$row['id'],
                    'name'      => $row['name'],
                    'filters'   => json_decode($row['filters'], true) ?: new stdClass(),
                    'sortOrder' => (int)$row['sort_order'],
                ];
            }
            echo json_encode(['success' => true, 'data' => $presets]);
            break;

        // ============================================================
        // saveFilterPreset — フィルタープリセットの新規作成 or 更新（要件5）
        //   payload: { id?: number, name: string, filters: object, sortOrder?: number }
        // ============================================================
        case 'saveFilterPreset':
            $email = $_SESSION['user_email'] ?? '';
            if (!$email) {
                echo json_encode(['success' => false, 'error' => 'unauthorized']);
                break;
            }
            $id        = isset($payload['id']) ? (int)$payload['id'] : 0;
            $name      = trim((string)($payload['name'] ?? ''));
            $filters   = $payload['filters'] ?? [];
            $sortOrder = (int)($payload['sortOrder'] ?? 0);
            if ($name === '') {
                echo json_encode(['success' => false, 'error' => 'name is required']);
                break;
            }
            if (mb_strlen($name) > 120) {
                $name = mb_substr($name, 0, 120);
            }
            $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);

            if ($id > 0) {
                // 自分のプリセットだけ更新可
                $stmt = $pdo->prepare("UPDATE filter_presets SET name = ?, filters = ?, sort_order = ? WHERE id = ? AND user_email = ?");
                $stmt->execute([$name, $filtersJson, $sortOrder, $id, $email]);
                if ($stmt->rowCount() === 0) {
                    // 該当なし → 新規扱いに落として作成
                    $stmt = $pdo->prepare("INSERT INTO filter_presets (user_email, name, filters, sort_order) VALUES (?,?,?,?)");
                    $stmt->execute([$email, $name, $filtersJson, $sortOrder]);
                    $id = (int)$pdo->lastInsertId();
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO filter_presets (user_email, name, filters, sort_order) VALUES (?,?,?,?)");
                $stmt->execute([$email, $name, $filtersJson, $sortOrder]);
                $id = (int)$pdo->lastInsertId();
            }

            echo json_encode(['success' => true, 'data' => [
                'id'        => $id,
                'name'      => $name,
                'filters'   => $filters,
                'sortOrder' => $sortOrder,
            ]]);
            break;

        // ============================================================
        // deleteFilterPreset — 自分のプリセットを物理削除（要件5）
        // ============================================================
        case 'deleteFilterPreset':
            $email = $_SESSION['user_email'] ?? '';
            $id    = isset($payload['id']) ? (int)$payload['id'] : 0;
            if (!$email || $id <= 0) {
                echo json_encode(['success' => false, 'error' => 'unauthorized or invalid id']);
                break;
            }
            $stmt = $pdo->prepare("DELETE FROM filter_presets WHERE id = ? AND user_email = ?");
            $stmt->execute([$id, $email]);
            echo json_encode(['success' => true, 'data' => ['deleted' => $stmt->rowCount()]]);
            break;

        // ============================================================
        // ============================================================
        // Wiki (社内ドキュメント) API
        //   - wiki_pages       : ページ本体（id=UUID, parent_id で階層）
        //   - wiki_revisions   : 編集履歴（保存毎に revision_no を 1 ずつ増やす）
        //   - permalink は wiki_pages.id を使うため title 変更・移動でも不変
        // ============================================================

        // listWikiPages — ツリー描画用にメタだけ返す（body_md は除外）
        case 'listWikiPages':
            $stmt = $pdo->query(
                "SELECT id, title, parent_id, sort_order, created_by, updated_by, created_at, updated_at " .
                "FROM wiki_pages WHERE deleted_at IS NULL " .
                "ORDER BY parent_id IS NULL DESC, parent_id, sort_order ASC, created_at ASC"
            );
            $pages = [];
            while ($row = $stmt->fetch()) {
                $pages[] = [
                    'id'        => $row['id'],
                    'title'     => $row['title'],
                    'parentId'  => $row['parent_id'],
                    'sortOrder' => (float)$row['sort_order'],
                    'createdBy' => $row['created_by'],
                    'updatedBy' => $row['updated_by'],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at'],
                ];
            }
            echo json_encode(['success' => true, 'data' => ['pages' => $pages]]);
            break;

        // getWikiPage — 本文込みで 1 ページ取得
        case 'getWikiPage':
            $id = $payload['id'] ?? '';
            if ($id === '') { echo json_encode(['success' => false, 'error' => 'id is required']); break; }
            $stmt = $pdo->prepare("SELECT * FROM wiki_pages WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) { echo json_encode(['success' => false, 'error' => 'not_found']); break; }
            echo json_encode(['success' => true, 'data' => ['page' => [
                'id'        => $row['id'],
                'title'     => $row['title'],
                'bodyMd'    => $row['body_md'] ?? '',
                'parentId'  => $row['parent_id'],
                'sortOrder' => (float)$row['sort_order'],
                'createdBy' => $row['created_by'],
                'updatedBy' => $row['updated_by'],
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at'],
            ]]]);
            break;

        // saveWikiPage — 新規/更新の UPSERT + revision 追加
        //   - 新規: id を採番（UUID）、parent_id を payload で指定可
        //   - 更新: 既存 id を payload に含める。body_md/title のどちらかが変わったら revision を 1 件追加
        case 'saveWikiPage':
            $editorEmail = $_SESSION['user_email'] ?? '';
            $editorName  = '';
            if ($editorEmail !== '') {
                $u = $pdo->prepare("SELECT name FROM members WHERE email = ?");
                $u->execute([$editorEmail]);
                $editorName = (string)($u->fetchColumn() ?: '');
            }
            $title       = trim((string)($payload['title'] ?? ''));
            $bodyMd      = (string)($payload['bodyMd'] ?? '');
            $parentId    = isset($payload['parentId']) && $payload['parentId'] !== '' ? $payload['parentId'] : null;
            $changeSum   = trim((string)($payload['changeSummary'] ?? ''));
            $id          = (string)($payload['id'] ?? '');

            // 親 page の存在チェック（parentId 指定時）— 削除済みは弾く
            if ($parentId !== null) {
                $chk = $pdo->prepare("SELECT 1 FROM wiki_pages WHERE id = ? AND deleted_at IS NULL");
                $chk->execute([$parentId]);
                if (!$chk->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'parent_not_found']);
                    break;
                }
            }

            $pdo->beginTransaction();
            try {
                if ($id === '') {
                    // ----- 新規作成 -----
                    $id = generateWikiUuid();
                    // sort_order: 同じ parent_id の中で末尾に置く
                    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM wiki_pages WHERE " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?"));
                    $maxStmt->execute($parentId === null ? [] : [$parentId]);
                    $sortOrder = ((float)$maxStmt->fetchColumn()) + 1024.0;

                    $ins = $pdo->prepare(
                        "INSERT INTO wiki_pages (id, title, body_md, parent_id, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $ins->execute([$id, $title === '' ? '(無題)' : $title, $bodyMd, $parentId, $sortOrder, $editorEmail, $editorEmail]);
                    $revNo = 1;
                } else {
                    // ----- 更新 -----
                    $existing = $pdo->prepare("SELECT title, body_md FROM wiki_pages WHERE id = ? AND deleted_at IS NULL");
                    $existing->execute([$id]);
                    $cur = $existing->fetch();
                    if (!$cur) { throw new Exception('not_found'); }

                    $upd = $pdo->prepare("UPDATE wiki_pages SET title = ?, body_md = ?, updated_by = ? WHERE id = ?");
                    $upd->execute([$title === '' ? '(無題)' : $title, $bodyMd, $editorEmail, $id]);

                    // revision_no は同じ page_id の中で連番
                    $maxRev = $pdo->prepare("SELECT COALESCE(MAX(revision_no), 0) FROM wiki_revisions WHERE page_id = ?");
                    $maxRev->execute([$id]);
                    $revNo = ((int)$maxRev->fetchColumn()) + 1;

                    // 変更が無ければ revision を追加しない（無駄なログ防止）
                    if ($cur['title'] === ($title === '' ? '(無題)' : $title) && (string)$cur['body_md'] === $bodyMd) {
                        $pdo->commit();
                        echo json_encode(['success' => true, 'data' => ['id' => $id, 'revisionNo' => null, 'unchanged' => true]]);
                        break;
                    }
                }

                $insRev = $pdo->prepare(
                    "INSERT INTO wiki_revisions (page_id, revision_no, title, body_md, editor_email, editor_name, change_summary) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insRev->execute([$id, $revNo, $title === '' ? '(無題)' : $title, $bodyMd, $editorEmail, $editorName, $changeSum]);

                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['id' => $id, 'revisionNo' => $revNo]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // getWikiRevisions — 履歴一覧（本文は含めず軽量）
        case 'getWikiRevisions':
            $pageId = $payload['pageId'] ?? '';
            if ($pageId === '') { echo json_encode(['success' => false, 'error' => 'pageId is required']); break; }
            $stmt = $pdo->prepare(
                "SELECT id, revision_no, title, editor_email, editor_name, change_summary, created_at " .
                "FROM wiki_revisions WHERE page_id = ? ORDER BY revision_no DESC"
            );
            $stmt->execute([$pageId]);
            $revs = [];
            while ($row = $stmt->fetch()) {
                $revs[] = [
                    'id'             => (int)$row['id'],
                    'revisionNo'     => (int)$row['revision_no'],
                    'title'          => $row['title'],
                    'editorEmail'    => $row['editor_email'],
                    'editorName'     => $row['editor_name'],
                    'changeSummary'  => $row['change_summary'],
                    'createdAt'      => $row['created_at'],
                ];
            }
            echo json_encode(['success' => true, 'data' => ['revisions' => $revs]]);
            break;

        // getWikiRevisionDiff — 2 revision の本文を返す（フロントで jsdiff にかける）
        //   どちらかが省略された場合は「現在 (wiki_pages 本体)」を相手にする
        case 'getWikiRevisionDiff':
            $pageId = $payload['pageId'] ?? '';
            $revA   = isset($payload['revisionA']) ? (int)$payload['revisionA'] : 0;
            $revB   = isset($payload['revisionB']) ? (int)$payload['revisionB'] : 0;
            if ($pageId === '' || $revA <= 0 || $revB <= 0) {
                echo json_encode(['success' => false, 'error' => 'pageId / revisionA / revisionB are required']);
                break;
            }
            $stmt = $pdo->prepare("SELECT revision_no, title, body_md FROM wiki_revisions WHERE page_id = ? AND revision_no IN (?, ?)");
            $stmt->execute([$pageId, $revA, $revB]);
            $byNo = [];
            while ($row = $stmt->fetch()) {
                $byNo[(int)$row['revision_no']] = [
                    'title'  => $row['title'],
                    'bodyMd' => $row['body_md'] ?? '',
                ];
            }
            if (!isset($byNo[$revA]) || !isset($byNo[$revB])) {
                echo json_encode(['success' => false, 'error' => 'revision_not_found']);
                break;
            }
            echo json_encode(['success' => true, 'data' => [
                'a' => ['revisionNo' => $revA] + $byNo[$revA],
                'b' => ['revisionNo' => $revB] + $byNo[$revB],
            ]]);
            break;

        // restoreWikiRevision — 指定 revision を現在版に巻き戻す（新 revision として記録）
        case 'restoreWikiRevision':
            $editorEmail = $_SESSION['user_email'] ?? '';
            $editorName  = '';
            if ($editorEmail !== '') {
                $u = $pdo->prepare("SELECT name FROM members WHERE email = ?");
                $u->execute([$editorEmail]);
                $editorName = (string)($u->fetchColumn() ?: '');
            }
            $pageId      = $payload['pageId'] ?? '';
            $revNoSrc    = isset($payload['revisionNo']) ? (int)$payload['revisionNo'] : 0;
            if ($pageId === '' || $revNoSrc <= 0) {
                echo json_encode(['success' => false, 'error' => 'pageId / revisionNo are required']);
                break;
            }
            $pdo->beginTransaction();
            try {
                $src = $pdo->prepare("SELECT title, body_md FROM wiki_revisions WHERE page_id = ? AND revision_no = ?");
                $src->execute([$pageId, $revNoSrc]);
                $row = $src->fetch();
                if (!$row) { throw new Exception('revision_not_found'); }

                $upd = $pdo->prepare("UPDATE wiki_pages SET title = ?, body_md = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL");
                $upd->execute([$row['title'], $row['body_md'], $editorEmail, $pageId]);
                if ($upd->rowCount() === 0) { throw new Exception('page_not_found'); }

                $maxRev = $pdo->prepare("SELECT COALESCE(MAX(revision_no), 0) FROM wiki_revisions WHERE page_id = ?");
                $maxRev->execute([$pageId]);
                $newRev = ((int)$maxRev->fetchColumn()) + 1;

                $insRev = $pdo->prepare(
                    "INSERT INTO wiki_revisions (page_id, revision_no, title, body_md, editor_email, editor_name, change_summary) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insRev->execute([$pageId, $newRev, $row['title'], $row['body_md'], $editorEmail, $editorName, 'restore from rev ' . $revNoSrc]);

                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['revisionNo' => $newRev]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // deleteWikiPage — ソフトデリート（子孫もまとめて）
        case 'deleteWikiPage':
            $id = $payload['id'] ?? '';
            if ($id === '') { echo json_encode(['success' => false, 'error' => 'id is required']); break; }
            $pdo->beginTransaction();
            try {
                // 子孫を BFS で集める
                $toDelete = [$id];
                $frontier = [$id];
                $guard = 0;
                while (!empty($frontier) && $guard++ < 1000) {
                    $place = implode(',', array_fill(0, count($frontier), '?'));
                    $stmt = $pdo->prepare("SELECT id FROM wiki_pages WHERE parent_id IN ($place) AND deleted_at IS NULL");
                    $stmt->execute($frontier);
                    $next = [];
                    while ($row = $stmt->fetch()) {
                        $toDelete[] = $row['id'];
                        $next[] = $row['id'];
                    }
                    $frontier = $next;
                }
                $place = implode(',', array_fill(0, count($toDelete), '?'));
                $upd = $pdo->prepare("UPDATE wiki_pages SET deleted_at = NOW() WHERE id IN ($place) AND deleted_at IS NULL");
                $upd->execute($toDelete);
                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['affected' => $upd->rowCount()]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // duplicateWikiPage — 単一ページ複製（子孫は含めない）
        //   - 新規 UUID 採番、title に「(コピー)」付与
        //   - 同じ parent_id に末尾追加（sort_order = max+1024）
        //   - 複製先に初期 revision 1 を記録
        case 'duplicateWikiPage':
            $editorEmail = $_SESSION['user_email'] ?? '';
            $editorName  = '';
            if ($editorEmail !== '') {
                $u = $pdo->prepare("SELECT name FROM members WHERE email = ?");
                $u->execute([$editorEmail]);
                $editorName = (string)($u->fetchColumn() ?: '');
            }
            $srcId = $payload['id'] ?? '';
            if ($srcId === '') { echo json_encode(['success' => false, 'error' => 'id is required']); break; }

            $src = $pdo->prepare("SELECT title, body_md, parent_id FROM wiki_pages WHERE id = ? AND deleted_at IS NULL");
            $src->execute([$srcId]);
            $row = $src->fetch();
            if (!$row) { echo json_encode(['success' => false, 'error' => 'source_not_found']); break; }

            $newId    = generateWikiUuid();
            $newTitle = ($row['title'] === '' ? '(無題)' : $row['title']) . ' (コピー)';
            $parentId = $row['parent_id'];

            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM wiki_pages WHERE " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?") . " AND deleted_at IS NULL");
            $maxStmt->execute($parentId === null ? [] : [$parentId]);
            $sortOrder = ((float)$maxStmt->fetchColumn()) + 1024.0;

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare(
                    "INSERT INTO wiki_pages (id, title, body_md, parent_id, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->execute([$newId, $newTitle, $row['body_md'], $parentId, $sortOrder, $editorEmail, $editorEmail]);

                $insRev = $pdo->prepare(
                    "INSERT INTO wiki_revisions (page_id, revision_no, title, body_md, editor_email, editor_name, change_summary) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insRev->execute([$newId, 1, $newTitle, $row['body_md'], $editorEmail, $editorName, 'duplicated from ' . $srcId]);

                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['id' => $newId, 'title' => $newTitle]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // moveWikiPage — parent_id / sort_order を変更（D&D 用）
        //   循環参照防止: 自分自身を子孫にする移動は弾く
        case 'moveWikiPage':
            $id        = $payload['id'] ?? '';
            $parentId  = array_key_exists('parentId', $payload)
                ? (($payload['parentId'] === null || $payload['parentId'] === '') ? null : (string)$payload['parentId'])
                : null;
            $sortOrder = isset($payload['sortOrder']) ? (float)$payload['sortOrder'] : null;
            if ($id === '') { echo json_encode(['success' => false, 'error' => 'id is required']); break; }

            // 親 page の存在チェック（saveWikiPage と整合）— 削除済みは弾く
            if ($parentId !== null) {
                $chk = $pdo->prepare("SELECT 1 FROM wiki_pages WHERE id = ? AND deleted_at IS NULL");
                $chk->execute([$parentId]);
                if (!$chk->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'parent_not_found']);
                    break;
                }
            }

            // 循環参照チェック: parentId が自分の子孫だったら拒否
            if ($parentId !== null) {
                if ($parentId === $id) { echo json_encode(['success' => false, 'error' => 'self_parent']); break; }
                $cursor = $parentId;
                $guard = 0;
                while ($cursor !== null && $guard++ < 100) {
                    if ($cursor === $id) { echo json_encode(['success' => false, 'error' => 'circular_reference']); break 2; }
                    $stmt = $pdo->prepare("SELECT parent_id FROM wiki_pages WHERE id = ? AND deleted_at IS NULL");
                    $stmt->execute([$cursor]);
                    $row = $stmt->fetch();
                    if (!$row) break;
                    $cursor = $row['parent_id'];
                }
            }

            if ($sortOrder === null) {
                $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM wiki_pages WHERE " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?") . " AND id <> ?");
                $maxStmt->execute($parentId === null ? [$id] : [$parentId, $id]);
                $sortOrder = ((float)$maxStmt->fetchColumn()) + 1024.0;
            }
            $upd = $pdo->prepare("UPDATE wiki_pages SET parent_id = ?, sort_order = ? WHERE id = ? AND deleted_at IS NULL");
            $upd->execute([$parentId, $sortOrder, $id]);
            echo json_encode(['success' => true, 'data' => ['affected' => $upd->rowCount(), 'sortOrder' => $sortOrder]]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
