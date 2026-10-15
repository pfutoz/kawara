<?php
$prod_host  = '192.168.1.16';
$prod_db    = 'kawara';
$prod_user  = 'postgres';
$prod_pass  = 'postgres';

$pdo = new PDO("pgsql:host={$prod_host};port=5432;dbname={$prod_db}", $prod_user, $prod_pass);
$stmt = $pdo->query("SELECT staff_id, staff_name, role, line_user_id, pin_code FROM staff WHERE staff_name LIKE '%山本%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "--- 本番DBの山本さんデータ ---\n";
foreach ($rows as $r) {
    echo "ID: " . $r['staff_id'] . "\n";
    echo "名前: " . $r['staff_name'] . "\n";
    echo "役職: " . $r['role'] . "\n";
    echo "LINE ID: " . ($r['line_user_id'] ? $r['line_user_id'] : '【未登録】') . "\n";
    echo "PIN: " . ($r['pin_code'] ? $r['pin_code'] : 'なし') . "\n";
    echo "---------------------------\n";
}
