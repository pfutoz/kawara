<?php
$pdo = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=kawara', 'postgres', 'postgres');
$stmt = $pdo->query("SELECT * FROM line_link_codes WHERE staff_id = 15 ORDER BY code_id DESC LIMIT 1");
$c = $stmt->fetch(PDO::FETCH_ASSOC);

echo "--- 山本太さんの連携コード状況 ---\n";
echo "Code: " . ($c['link_code'] ?? 'None') . "\n";
echo "Expires: " . ($c['expires_at'] ?? 'None') . "\n";
echo "Used: " . ($c['is_used'] ? 'YES' : 'NO') . "\n";

$s = $pdo->query("SELECT staff_id, staff_name, line_user_id FROM staff WHERE staff_id = 15")->fetch(PDO::FETCH_ASSOC);
echo "LINE ID: " . ($s['line_user_id'] ?: '【未登録】') . "\n";
