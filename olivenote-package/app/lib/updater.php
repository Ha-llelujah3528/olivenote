<?php
/**
 * Olive Note - Updater
 *
 * - リモート manifest 取得
 * - バージョン比較
 * - ZIPダウンロード・展開・差し替え
 * - migration 自動実行
 * - ロールバック
 *
 * すべて同期処理。アップデート中は MAINTENANCE.lock を立てる。
 */

if (!defined('OLIVENOTE_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/migrations.php';

class OliveNoteUpdater {

    private string $root;
    private string $appDir;
    private string $dataDir;
    private string $backupsDir;
    private string $tmpDir;

    public function __construct() {
        $this->root       = OLIVENOTE_ROOT;
        $this->appDir     = OLIVENOTE_APP;
        $this->dataDir    = OLIVENOTE_DATA;
        $this->backupsDir = $this->dataDir . '/backups';
        $this->tmpDir     = $this->dataDir . '/tmp';
        @mkdir($this->backupsDir, 0750, true);
        @mkdir($this->tmpDir,     0750, true);
    }

    /** リモートマニフェストを取得 */
    public function fetchManifest(): array {
        $url = defined('OLIVENOTE_UPDATE_MANIFEST_URL') ? OLIVENOTE_UPDATE_MANIFEST_URL : '';
        if (!$url) throw new RuntimeException('OLIVENOTE_UPDATE_MANIFEST_URL が設定されていません');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'OliveNote-Updater/' . OLIVENOTE_VERSION,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http !== 200) {
            throw new RuntimeException("Manifest取得失敗 (HTTP $http): $err");
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['latest'])) {
            throw new RuntimeException('Manifest の形式が不正です');
        }
        return $json;
    }

    /** 現在のバージョン */
    public function currentVersion(): string {
        return OLIVENOTE_VERSION;
    }

    /** バージョンを比較して更新が必要か判定 */
    public function isUpdateAvailable(string $latest): bool {
        return version_compare($latest, $this->currentVersion(), '>');
    }

    /**
     * アップデートを実行
     * 進捗を $logger コールバック (function(string $msg): void) で受け取る
     */
    public function performUpdate(array $manifest, ?callable $logger = null): array {
        $log = function (string $m) use ($logger) { if ($logger) $logger($m); };

        $latest      = $manifest['latest'];
        $downloadUrl = $manifest['download_url'] ?? '';
        $expectSha   = $manifest['sha256']       ?? '';
        if (!$downloadUrl) throw new RuntimeException('download_url が manifest にありません');

        $this->enterMaintenance();
        $log("🔒 メンテナンスモード ON");

        try {
            $log("📦 v{$this->currentVersion()} → v{$latest} へ更新します");

            // 1. DB バックアップ
            $dbBackup = $this->dumpDatabase();
            $log("💾 DBバックアップ: " . basename($dbBackup));

            // 2. app/ をバックアップ
            $appBackup = $this->backupAppDir();
            $log("💾 app/ バックアップ: " . basename($appBackup));

            // 3. ZIPダウンロード
            $zipPath = $this->tmpDir . '/olivenote-' . $latest . '.zip';
            $this->downloadFile($downloadUrl, $zipPath, $log);
            $log("⬇️  ZIPダウンロード完了");

            // 4. ハッシュ検証
            if ($expectSha) {
                $actual = hash_file('sha256', $zipPath);
                if (!hash_equals($expectSha, $actual)) {
                    throw new RuntimeException("ZIPのSHA256が不一致 (expect: $expectSha, actual: $actual)");
                }
                $log("🔑 SHA256検証 OK");
            } else {
                $log("⚠️  SHA256検証スキップ（manifestに hash 未指定）");
            }

            // 5. ZIPを一時ディレクトリに展開
            $extractDir = $this->tmpDir . '/extract-' . time();
            @mkdir($extractDir, 0750, true);
            $this->extractZip($zipPath, $extractDir);
            $log("📂 ZIP展開完了");

            // 6. パッケージ内の app/ を見つける
            $newAppDir = $this->findAppDirInExtract($extractDir);
            if (!$newAppDir) {
                throw new RuntimeException("展開後のディレクトリに app/ が見つかりません");
            }

            // 7. 既存 app/ を削除 → 新 app/ をコピー
            $this->rmrf($this->appDir);
            $this->copydir($newAppDir, $this->appDir);
            $log("🔄 app/ を新バージョンに置き換え");

            // 7.5 docs/ も更新（パッケージに含まれていれば）
            // インストーラのリンク先や、運用者が後から参照するため最新版を維持する
            $newDocsDir = dirname($newAppDir) . '/docs';
            if (is_dir($newDocsDir)) {
                $docsDir = $this->root . '/docs';
                $this->rmrf($docsDir);
                $this->copydir($newDocsDir, $docsDir);
                $log("📚 docs/ を更新");
            }

            // 8. config.sample.php も更新（パッケージに含まれていれば）
            $newSample = dirname($newAppDir) . '/config/config.sample.php';
            if (is_file($newSample)) {
                @copy($newSample, $this->root . '/config/config.sample.php');
                $log("📝 config.sample.php を更新");
            }

            // 9. migrations 実行
            // bootstrap を再読み込み（VERSION 更新を反映）
            // 既存の PDO は維持されるので問題なし
            $migResult = olivenote_run_pending_migrations();
            if (!empty($migResult['errors'])) {
                throw new RuntimeException("Migration エラー: " . implode(' / ', $migResult['errors']));
            }
            $log("📊 Migration: " . count($migResult['applied']) . "件適用");

            // 10. クリーンアップ
            $this->rmrf($extractDir);
            @unlink($zipPath);
            $log("🧹 一時ファイル削除");

            $this->exitMaintenance();
            $log("🔓 メンテナンスモード OFF");
            $log("✅ アップデート完了: v{$latest}");

            return [
                'success'    => true,
                'oldVersion' => $this->currentVersion(),
                'newVersion' => $latest,
                'migrations' => $migResult['applied'],
                'backups'    => ['db' => basename($dbBackup), 'app' => basename($appBackup)],
            ];

        } catch (Throwable $e) {
            $log("❌ エラー: " . $e->getMessage());
            $log("⏪ ロールバックを試みます...");
            try {
                if (isset($appBackup) && is_file($appBackup)) {
                    $this->restoreAppDir($appBackup);
                    $log("✅ app/ をロールバック");
                }
            } catch (Throwable $e2) {
                $log("❌ ロールバックも失敗: " . $e2->getMessage());
            }
            $this->exitMaintenance();
            throw $e;
        }
    }

    // ============================================================
    // バックアップ
    // ============================================================

    private function dumpDatabase(): string {
        $pdo  = olivenote_db();
        $ts   = date('Ymd-His');
        $file = $this->backupsDir . '/db-' . $ts . '.sql';

        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $fh = fopen($file, 'w');
        if (!$fh) throw new RuntimeException("バックアップファイル作成失敗");

        fwrite($fh, "-- Olive Note DB backup at $ts\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $t) {
            // CREATE TABLE
            $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
            fwrite($fh, "-- Table: $t\nDROP TABLE IF EXISTS `$t`;\n");
            fwrite($fh, $row[1] . ";\n\n");

            // データ
            $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cols = array_map(fn($c) => "`$c`", array_keys($r));
                $vals = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string)$v);
                }, array_values($r));
                fwrite($fh, "INSERT INTO `$t` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        // 古いバックアップを5世代に絞る
        $this->rotateBackups($this->backupsDir, 'db-*.sql', 5);
        return $file;
    }

    private function backupAppDir(): string {
        $ts   = date('Ymd-His');
        $file = $this->backupsDir . '/app-' . $this->currentVersion() . '-' . $ts . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("appバックアップ作成失敗");
        }
        $this->addDirToZip($zip, $this->appDir, basename($this->appDir));
        $zip->close();

        $this->rotateBackups($this->backupsDir, 'app-*.zip', 3);
        return $file;
    }

    private function restoreAppDir(string $zipPath): void {
        $tmp = $this->tmpDir . '/rollback-' . time();
        @mkdir($tmp, 0750, true);
        $this->extractZip($zipPath, $tmp);
        $this->rmrf($this->appDir);
        $this->copydir($tmp . '/app', $this->appDir);
        $this->rmrf($tmp);
    }

    private function rotateBackups(string $dir, string $pattern, int $keep): void {
        $files = glob($dir . '/' . $pattern);
        if (!$files) return;
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a)); // 新しい順
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }

    // ============================================================
    // ZIP / ファイル操作
    // ============================================================

    private function downloadFile(string $url, string $dest, callable $log): void {
        $fh = fopen($dest, 'w');
        if (!$fh) throw new RuntimeException("ダウンロード先ファイル作成失敗: $dest");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'OliveNote-Updater/' . OLIVENOTE_VERSION,
        ]);
        $ok   = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if (!$ok || $http !== 200) {
            @unlink($dest);
            throw new RuntimeException("ダウンロード失敗 (HTTP $http): $err");
        }
    }

    private function extractZip(string $zipPath, string $destDir): void {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("ZIPオープン失敗: $zipPath");
        }
        if (!$zip->extractTo($destDir)) {
            $zip->close();
            throw new RuntimeException("ZIP展開失敗");
        }
        $zip->close();
    }

    /** 展開後のディレクトリツリーから app/index.php を含む親を返す */
    private function findAppDirInExtract(string $base): ?string {
        // 候補:  $base/app  または  $base/olivenote/app  など 1階層下
        if (is_file($base . '/app/index.php')) return $base . '/app';
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (is_file($sub . '/app/index.php')) return $sub . '/app';
        }
        return null;
    }

    private function addDirToZip(ZipArchive $zip, string $src, string $base): void {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = $base . '/' . substr($f->getPathname(), strlen($src) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($f->isFile()) $zip->addFile($f->getPathname(), $rel);
        }
    }

    private function rmrf(string $path): void {
        if (!file_exists($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') continue;
            $this->rmrf($path . '/' . $f);
        }
        @rmdir($path);
    }

    private function copydir(string $src, string $dst): void {
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) $this->copydir($s, $d);
            else            copy($s, $d);
        }
    }

    // ============================================================
    // メンテモード
    // ============================================================

    private function enterMaintenance(): void {
        file_put_contents($this->dataDir . '/MAINTENANCE.lock', date('c'));
    }

    public function exitMaintenance(): void {
        @unlink($this->dataDir . '/MAINTENANCE.lock');
    }
}
