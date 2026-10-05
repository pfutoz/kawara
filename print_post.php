<?php
session_start();
$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) { exit('DB接続エラー: ' . $e->getMessage()); }

$post_id = (int)($_GET['id'] ?? 0);

// 投稿データの取得
$stmt = $pdo->prepare("
    SELECT p.*, c.category_name, c.icon_emoji, s.staff_name 
    FROM posts p 
    LEFT JOIN post_categories c ON p.category_id = c.category_id 
    LEFT JOIN staff s ON p.author_id = s.staff_id 
    WHERE p.post_id = :id
");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch();

if (!$post) { exit('記事が存在しません。'); }

// 🖼 添付画像パス一覧の取得（file_pathに修正）
$img_stmt = $pdo->prepare("SELECT file_path FROM post_images WHERE post_id = :pid ORDER BY image_id ASC");
$img_stmt->execute([':pid' => $post_id]);
$images = $img_stmt->fetchAll(PDO::FETCH_COLUMN);

$week_names = ['日', '月', '火', '水', '木', '金', '土'];
$start_dt = $post['target_datetime'] ? new DateTime($post['target_datetime']) : null;
$end_dt   = $post['target_end_datetime'] ? new DateTime($post['target_end_datetime']) : null;

$event_date_str = '';
if ($start_dt) {
    $event_date_str = $start_dt->format('Y/m/d') . '(' . $week_names[(int)$start_dt->format('w')] . ') ' . $start_dt->format('H:i');
    if ($end_dt) {
        $event_date_str .= ' 〜 ' . ($start_dt->format('Y-m-d') === $end_dt->format('Y-m-d') ? $end_dt->format('H:i') : $end_dt->format('Y/m/d') . '(' . $week_names[(int)$end_dt->format('w')] . ') ' . $end_dt->format('H:i'));
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>印刷 - <?= htmlspecialchars($post['title']) ?></title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #000; margin: 0; padding: 0; }
        
        .no-print-bar { background: #333; color: #fff; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        .btn-print-action { background: #28a745; color: #fff; border: none; padding: 8px 20px; font-weight: bold; border-radius: 4px; cursor: pointer; }

        .print-container { border: 2px solid #000; padding: 20px; margin: 20px auto; max-width: 800px; }
        .print-header { border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px; display: flex; justify-content: space-between; font-size: 10pt; color: #555; }
        .print-title { font-size: 20pt; font-weight: bold; margin-bottom: 10px; line-height: 1.3; }
        .event-box { background: #f0f0f0; border: 1px solid #000; padding: 8px 12px; font-weight: bold; font-size: 13pt; margin-bottom: 15px; }
        .print-body { font-size: 13pt; line-height: 1.6; margin-bottom: 20px; white-space: pre-wrap; }
        
        /* 🖼 画像を紙面いっぱいに自動拡大 */
        .image-gallery { display: flex; flex-direction: column; gap: 15px; align-items: center; margin-top: 15px; }
        .image-gallery img { width: 100%; max-height: 60vh; object-fit: contain; border: 1px solid #666; }

        @media print {
            .no-print-bar { display: none !important; }
            .print-container { border: none !important; margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <span>🖨️ 掲示用ポスター印刷プレビュー</span>
    <button class="btn-print-action" onclick="window.print()">この内容で印刷する</button>
</div>

<div class="print-container">
    <div class="print-header">
        <div>医療法人小野会 院内掲示</div>
        <div><?= date('Y/m/d H:i', strtotime($post['created_at'])) ?> 投稿</div>
    </div>

    <div class="print-title">
        <?= htmlspecialchars($post['icon_emoji'] ?? '📜') ?> <?= htmlspecialchars($post['title']) ?>
    </div>

    <div style="font-size: 10pt; color: #444; margin-bottom: 10px;">
        発信者: <?= htmlspecialchars($post['staff_name'] ?? '事務部') ?> (<?= htmlspecialchars($post['author_dept'] ?? '事務') ?>)
    </div>

    <?php if ($event_date_str): ?>
        <div class="event-box">
            🗓 実施・対象日時: <?= $event_date_str ?>
        </div>
    <?php endif; ?>

    <div class="print-body">
        <?= $post['content'] ?>
    </div>

    <?php if (!empty($images)): ?>
        <div class="image-gallery">
            <?php foreach ($images as $img): ?>
                <img src="<?= htmlspecialchars($img) ?>" alt="添付画像">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>