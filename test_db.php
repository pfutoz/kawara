<?php
// DB接続共通モジュール読み込み
require_once __DIR__ . '/includes/db.php';

// 全スタッフを取得
$stmt = $pdo->query("SELECT staff_id, staff_name, kana, kana_row, role FROM staff WHERE is_deleted = FALSE ORDER BY kana ASC");
$staff_list = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>スタッフ行分類 (kana_row) 検証テスト</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f4f6f9; color: #333; }
        h2 { color: #005a9c; border-bottom: 2px solid #005a9c; padding-bottom: 5px; }
        table { border-collapse: collapse; width: 100%; max-width: 900px; background: #fff; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; font-size: 0.9rem; }
        th { background: #eef6fc; }
        .target-row { background: #fff3cd; font-weight: bold; }
    </style>
</head>
<body>

    <h2>📋 登録スタッフの行分類（kana_row）一覧チェック</h2>
    <p>「小野 誠吾」さんなどのデータが、どの行（kana_row）に割り振られているかの一覧です。</p>

    <table>
        <tr>
            <th>ID</th>
            <th>スタッフ名</th>
            <th>ふりがな (kana)</th>
            <th>現在の設定 (kana_row)</th>
            <th>役職</th>
        </tr>
        <?php foreach ($staff_list as $st): 
            $is_target = (mb_strpos($st['staff_name'], '小野') !== false);
        ?>
            <tr class="<?= $is_target ? 'target-row' : '' ?>">
                <td><?= htmlspecialchars($st['staff_id']) ?></td>
                <td><b><?= htmlspecialchars($st['staff_name']) ?></b></td>
                <td><?= htmlspecialchars($st['kana']) ?></td>
                <td><b>[<?= htmlspecialchars($st['kana_row']) ?>]</b></td>
                <td><?= htmlspecialchars($st['role']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

</body>
</html>