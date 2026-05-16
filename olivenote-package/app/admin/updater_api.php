<?php
/**
 * Olive Note - アップデーター API
 * GET  ?action=check  → 現在バージョン & 最新バージョン取得
 * POST ?action=run    → アップデート実行（同期処理、レスポンスにログを含む）
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/updater.php';

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('Content-Type: application/json; charset=utf-8');

// 認証
if (empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}
$stmt = olivenote_db()->prepare("SELECT is_admin FROM members WHERE email = ?");
$stmt->execute([$_SESSION['user_email']]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $updater = new OliveNoteUpdater();

    if ($action === 'check') {
        $manifest = $updater->fetchManifest();
        echo json_encode([
            'success' => true,
            'data' => [
                'current'         => $updater->currentVersion(),
                'latest'          => $manifest['latest'],
                'updateAvailable' => $updater->isUpdateAvailable($manifest['latest']),
                'changelog'       => $manifest['changelog'] ?? '',
                'published_at'    => $manifest['published_at'] ?? '',
            ],
        ]);
        exit;
    }

    if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // 長時間処理
        set_time_limit(600);
        ignore_user_abort(true);

        $manifest = $updater->fetchManifest();
        if (!$updater->isUpdateAvailable($manifest['latest'])) {
            echo json_encode(['success' => false, 'error' => '既に最新版です']);
            exit;
        }

        $log = [];
        $result = $updater->performUpdate($manifest, function (string $m) use (&$log) {
            $log[] = '[' . date('H:i:s') . '] ' . $m;
        });

        echo json_encode(['success' => true, 'data' => $result, 'log' => $log]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'log'     => $log ?? [],
    ]);
}
