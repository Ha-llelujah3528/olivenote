<?php
/**
 * Olive Note - Migration Runner
 *
 * app/migrations/[0-9][0-9][0-9]_*.sql を順に流す。
 * 適用済みかは schema_migrations テーブルで管理。
 */

if (!defined('OLIVENOTE_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

/**
 * SQL ファイルをセミコロン区切りでステートメントに分割
 * （行頭が --, # で始まる単独コメント行は除外）
 */
function olivenote_split_sql_statements(string $sql): array {
    // 行頭コメントの除去
    $clean = preg_replace('/^[ \t]*(--|#)[^\n]*\n/m', '', $sql);
    // /* ... */ ブロックコメントの除去
    $clean = preg_replace('!/\*.*?\*/!s', '', $clean);
    // セミコロンで分割（クォート内まで考えない素朴な実装）
    $parts = explode(';', $clean);
    $out = [];
    foreach ($parts as $p) {
        $t = trim($p);
        if ($t !== '') $out[] = $t;
    }
    return $out;
}

/**
 * schema_migrations を確実に存在させる（初回の保険）
 */
function olivenote_ensure_migrations_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version     VARCHAR(10)   NOT NULL PRIMARY KEY,
        name        VARCHAR(200)  NOT NULL,
        applied_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * 未適用の migration を順に実行
 *
 * @return array{applied: string[], skipped: string[], errors: string[]}
 */
function olivenote_run_pending_migrations(?PDO $pdo = null, ?string $migrationsDir = null): array {
    $pdo = $pdo ?? olivenote_db();
    $migrationsDir = $migrationsDir ?? OLIVENOTE_MIGRATIONS;

    olivenote_ensure_migrations_table($pdo);

    $appliedRows = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $appliedSet = array_flip($appliedRows);

    $files = glob($migrationsDir . '/[0-9][0-9][0-9]_*.sql');
    if ($files === false) $files = [];
    sort($files);

    $result = ['applied' => [], 'skipped' => [], 'errors' => []];

    foreach ($files as $f) {
        $base = basename($f, '.sql');
        $version = substr($base, 0, 3);
        $name = substr($base, 4);

        if (isset($appliedSet[$version])) {
            $result['skipped'][] = $base;
            continue;
        }

        $sql = file_get_contents($f);
        if ($sql === false) {
            $result['errors'][] = "$base: ファイル読み込み失敗";
            continue;
        }

        $statements = olivenote_split_sql_statements($sql);

        $pdo->beginTransaction();
        try {
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->prepare("INSERT INTO schema_migrations (version, name) VALUES (?, ?)")
                ->execute([$version, $name]);
            $pdo->commit();
            $result['applied'][] = $base;
        } catch (Throwable $e) {
            // DDL は自動コミットされるため rollBack は意味薄いが念のため
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['errors'][] = "$base: " . $e->getMessage();
            // 1つ失敗したら以降は止める
            break;
        }
    }

    return $result;
}

/**
 * 現在のスキーマバージョン（最後に適用された migration 番号）
 */
function olivenote_current_schema_version(?PDO $pdo = null): ?string {
    $pdo = $pdo ?? olivenote_db();
    olivenote_ensure_migrations_table($pdo);
    return $pdo->query("SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1")->fetchColumn() ?: null;
}
