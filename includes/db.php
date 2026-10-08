<?php
/**
 * 院内かわら版 - 共通データベース接続モジュール
 * File: includes/db.php
 */

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', '5432');
if (!defined('DB_NAME')) define('DB_NAME', 'kawara');
if (!defined('DB_USER')) define('DB_USER', 'postgres');
if (!defined('DB_PASS')) define('DB_PASS', 'postgres');

/**
 * PDOインスタンスを取得（シングルトンで接続を1本に保持）
 *
 * @return PDO
 */
function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            error_log('Database Connection Error: ' . $e->getMessage());

            // APIリクエストか画面アクセスかによってエラー出力を切り替え
            $is_api = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
                   || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                   || (PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_URI']));

            if ($is_api) {
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['error' => 'データベース接続エラー', 'success' => false], JSON_UNESCAPED_UNICODE);
            } else {
                if (!headers_sent()) {
                    http_response_code(500);
                }
                echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>システムエラー</title></head>';
                echo '<body style="font-family: sans-serif; padding: 30px; text-align: center;">';
                echo '<h2 style="color: #c0392b;">データベースに接続できませんでした</h2>';
                echo '<p>時間をおいて再度お試しいただくか、システム管理者にお問い合わせください。</p>';
                echo '</body></html>';
            }
            exit(1);
        }
    }

    return $pdo;
}

// 既存の全ファイル（$pdo を前提としているロジック）との完全な下位互換性を保つため、
// 読み込み時にグローバル変数 $pdo を提供する
$pdo = getDBConnection();
