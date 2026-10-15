<?php
require_once __DIR__ . '/../includes/db.php';

echo "=== 端末認証拡張マイグレーション適用 ===\n";

try {
    $sql_file = __DIR__ . '/migrate_device_auth_20261014.sql';
    $sql = file_get_contents($sql_file);

    $pdo->beginTransaction();
    $pdo->exec($sql);
    $pdo->commit();

    echo "✅ マイグレーションが正常に適用されました。\n";

    // 確認
    $stmt = $pdo->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = 'staff' AND column_name IN ('device_token_version', 'line_auth_code', 'line_auth_code_expires_at', 'last_device_authenticated_at')");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - {$c['column_name']} ({$c['data_type']}) default={$c['column_default']}\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ エラー: " . $e->getMessage() . "\n";
    exit(1);
}
