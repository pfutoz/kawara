<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=kawara', 'postgres', 'postgres');
$stmt = $pdo->query("SELECT staff_id, staff_name, line_user_id FROM staff WHERE staff_name LIKE '%山本%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "--- ローカルDBの山本さんデータ ---\n";
foreach ($rows as $r) {
    echo "ID: " . $r['staff_id'] . "\n";
    echo "名前: " . $r['staff_name'] . "\n";
    echo "LINE ID: " . ($r['line_user_id'] ?: '【未登録】') . "\n";
}
