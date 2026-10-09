<?php
/**
 * 本番DB（192.168.1.16）マイグレーション実行スクリプト（2026-10-09版）
 * 使い方:
 *   php scripts/apply_prod_migration_20261009.php --dry-run
 *   php scripts/apply_prod_migration_20261009.php
 */

$is_dry_run = in_array('--dry-run', $argv);
$prod_host  = '192.168.1.16';
$prod_db    = 'kawara';
$prod_user  = 'postgres';
$prod_pass  = 'postgres';

echo "========================================================\n";
echo "🐘 院内かわら版 本番環境DBマイグレーション (2026-10-09)\n";
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

$sql_file = __DIR__ . '/migrate_to_prod_20261009.sql';
if (!file_exists($sql_file)) {
    echo "❌ マイグレーションファイルが見つかりません: {$sql_file}\n";
    exit(1);
}

$sql = file_get_contents($sql_file);

try {
    $pdo->beginTransaction();

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

echo "\n--------------------------------------------------------\n";
echo "📋 本番DBカラム検証:\n";
$stmt = $pdo->prepare("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = 'google_calendar_channels' AND column_name = 'show_in_kawara'");
$stmt->execute();
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "  - show_in_kawara: ✅ 存在 ({$col['data_type']}, default: {$col['column_default']})\n";
} else {
    echo "  - show_in_kawara: " . ($is_dry_run ? "ℹ️ ロールバックされたため未存在（想定通り）" : "❌ 未存在") . "\n";
}

if (!$is_dry_run) {
    echo "\n📋 現在のチャンネル一覧:\n";
    $channels = $pdo->query("SELECT channel_id, account_name, calendar_name, is_enabled, show_in_kawara FROM google_calendar_channels ORDER BY display_order, channel_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($channels as $c) {
        $enabled = $c['is_enabled'] ? '有効' : '無効';
        $k_disp  = $c['show_in_kawara'] ? '🟢 かわら版表示' : '⚪ かわら版非表示';
        echo "  [ID: {$c['channel_id']}] {$c['account_name']} / {$c['calendar_name']} ({$enabled} / {$k_disp})\n";
    }
}
echo "========================================================\n";
