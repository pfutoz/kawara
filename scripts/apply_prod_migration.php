<?php
/**
 * 本番DB（192.168.1.16）マイグレーション実行スクリプト
 * 使い方:
 *   php scripts/apply_prod_migration.php --dry-run   (事前確認・シミュレーション)
 *   php scripts/apply_prod_migration.php             (本番DBへ安全適用)
 */

$is_dry_run = in_array('--dry-run', $argv);
$prod_host  = '192.168.1.16';
$prod_db    = 'kawara';
$prod_user  = 'postgres';
$prod_pass  = 'postgres';

echo "========================================================\n";
echo "🐘 院内かわら版 本番環境DBマイグレーション\n";
echo "========================================================\n";
echo "対象ホスト: {$prod_host} (DB: {$prod_db})\n";
echo "モード:     " . ($is_dry_run ? "🔍 DRY-RUN (変更はコミットされずロールバックされます)" : "⚡ 本番適用実行") . "\n";
echo "--------------------------------------------------------\n";

try {
    $pdo = new PDO("pgsql:host={$prod_host};port=5432;dbname={$prod_db}", $prod_user, $prod_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✅ 本番DBへの接続に成功しました。\n\n";
} catch (Exception $e) {
    echo "❌ 本番DB接続失敗: " . $e->getMessage() . "\n";
    exit(1);
}

$sql_file = __DIR__ . '/migrate_to_prod_20261008.sql';
if (!file_exists($sql_file)) {
    echo "❌ マイグレーションファイルが見つかりません: {$sql_file}\n";
    exit(1);
}

$sql = file_get_contents($sql_file);

try {
    $pdo->beginTransaction();

    // SQLファイル内のBEGIN/COMMITを除去してPDOのトランザクション管理に委ねる
    $clean_sql = preg_replace('/^\s*(BEGIN|COMMIT);\s*$/mi', '', $sql);
    $pdo->exec($clean_sql);

    echo "✅ SQLスクリプトの実行に成功しました。\n";

    if ($is_dry_run) {
        $pdo->rollBack();
        echo "🔍 DRY-RUN 完了: トランザクションをロールバックしました（DBへの恒久的な変更はありません）。\n";
    } else {
        $pdo->commit();
        echo "🎉 本番適用完了: トランザクションが正常にコミットされました！\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ マイグレーションエラー発生（ロールバック完了）: " . $e->getMessage() . "\n";
    exit(1);
}

// 適用後テーブル状態の検証
echo "\n--------------------------------------------------------\n";
echo "📋 本番DBテーブル存在確認:\n";
$tables_to_check = ['safety_events', 'dashboard_notes', 'google_calendar_channels', 'google_calendar_events_cache'];
foreach ($tables_to_check as $tbl) {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t");
    $stmt->execute([':t' => $tbl]);
    $exists = (bool)$stmt->fetchColumn();
    echo "  - {$tbl}: " . ($exists ? "✅ 存在" : "❌ 未存在") . "\n";
}
