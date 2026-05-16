<?php
/**
 * Olive Note - エントリポイント
 *
 * 1. メンテナンスモードの確認
 * 2. インストール状態の確認 → 未インストールなら installer/ へ
 * 3. 通常起動 → app/index.php を include
 */

// メンテナンスモード（アップデート中）
if (file_exists(__DIR__ . '/data/MAINTENANCE.lock')) {
    http_response_code(503);
    header('Retry-After: 60');
    if (file_exists(__DIR__ . '/app/maintenance.html')) {
        readfile(__DIR__ . '/app/maintenance.html');
    } else {
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>メンテナンス中</title></head><body style="font-family:sans-serif;text-align:center;padding:40px"><h1>🌿 Olive Note</h1><p>ただいまアップデート中です。しばらくしてからアクセスしてください。</p></body></html>';
    }
    exit;
}

// 未インストール → セットアップウィザードへ
if (!file_exists(__DIR__ . '/config/config.php')) {
    if (is_dir(__DIR__ . '/installer')) {
        header('Location: installer/install.php');
        exit;
    } else {
        http_response_code(500);
        echo 'config/config.php が見つかりません。setup を実行してください。';
        exit;
    }
}

// アプリ本体を読み込む
require __DIR__ . '/app/index.php';
