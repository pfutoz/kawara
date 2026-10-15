<?php
$prod_host  = '192.168.1.16';
$prod_db    = 'kawara';
$prod_user  = 'postgres';
$prod_pass  = 'postgres';

echo "=== 本番DB (192.168.1.16) 接続 & 端末認証マイグレーション ===\n";

try {
    $pdo = new PDO("pgsql:host={$prod_host};port=5432;dbname={$prod_db}", $prod_user, $prod_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✅ 本番DBへの接続に成功しました。\n";

    $sql_file = __DIR__ . '/migrate_device_auth_20261014.sql';
    $sql = file_get_contents($sql_file);

    $pdo->beginTransaction();
    $pdo->exec($sql);
    $pdo->commit();

    echo "✅ 本番DBへのマイグレーション適用が完了しました。\n";

    // 確認
    $stmt = $pdo->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = 'staff' AND column_name IN ('device_token_version', 'line_auth_code', 'line_auth_code_expires_at', 'last_device_authenticated_at')");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - {$c['column_name']} ({$c['data_type']}) default={$c['column_default']}\n";
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ 本番DBエラー: " . $e->getMessage() . "\n";
    exit(1);
}
