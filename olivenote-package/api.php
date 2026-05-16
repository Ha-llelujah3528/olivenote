<?php
/**
 * Olive Note - API エントリポイント
 * フロントエンドからの fetch('api.php', ...) を受ける薄いプロキシ。
 * 実体は app/api.php。
 */

// メンテナンス中は API も停止
if (file_exists(__DIR__ . '/data/MAINTENANCE.lock')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 60');
    echo json_encode(['success' => false, 'error' => 'maintenance']);
    exit;
}

// 未インストール
if (!file_exists(__DIR__ . '/config/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'not installed']);
    exit;
}

require __DIR__ . '/app/api.php';
