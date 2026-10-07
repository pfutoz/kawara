<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) { exit('DB接続エラー'); }

$post_id = (int)($_GET['id'] ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);

// 記事取得
$stmt = $pdo->prepare("
    SELECT p.*, c.category_name, c.icon_emoji, s.staff_name 
    FROM posts p 
    LEFT JOIN post_categories c ON p.category_id = c.category_id 
    LEFT JOIN staff s ON p.author_id = s.staff_id 
    WHERE p.post_id = :id
");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch();

if (!$post) {
    exit('記事が存在しません');
}

// 添付画像取得
$img_stmt = $pdo->prepare("SELECT file_path FROM post_images WHERE post_id = :pid ORDER BY image_id ASC");
$img_stmt->execute([':pid' => $post_id]);
$images = $img_stmt->fetchAll(PDO::FETCH_COLUMN);

// 部署・出力先情報取得
$dept_name = '全館共通';
$printer_id = (int)($_GET['printer_id'] ?? 0);
if ($printer_id > 0) {
    $p_stmt = $pdo->prepare("SELECT dept_display_name FROM department_printers WHERE dept_printer_id = :pid");
    $p_stmt->execute([':pid' => $printer_id]);
    $dept_name = $p_stmt->fetchColumn() ?: '';
}
if (empty($dept_name) && $dept_id > 0) {
    $d_stmt = $pdo->prepare("SELECT dept_name FROM target_departments WHERE dept_id = :id");
    $d_stmt->execute([':id' => $dept_id]);
    $dept_name = $d_stmt->fetchColumn() ?: '全館共通';
}

$clean_dept = trim(preg_replace('/掲示用$/u', '', $dept_name));
if (empty($clean_dept) || $clean_dept === '全館共通' || $clean_dept === '院内') {
    $badge_text = '【院内掲示用】';
    $dept_display_title = '院内全館（共通）';
} else {
    $badge_text = '【' . $clean_dept . ' 掲示用】';
    $dept_display_title = $clean_dept;
}

$week_names = ['日', '月', '火', '水', '木', '金', '土'];

// 日程解析（複数日程 JSONB または target_datetime）
$schedules = [];
if (!empty($post['event_schedules'])) {
    $decoded = json_decode($post['event_schedules'], true);
    if (is_array($decoded) && count($decoded) > 0) {
        $schedules = $decoded;
    }
}
if (empty($schedules) && !empty($post['target_datetime'])) {
    $schedules[] = [
        'start_datetime' => $post['target_datetime'],
        'end_datetime'   => $post['target_end_datetime'],
        'location'       => '',
        'memo'           => ''
    ];
}

// 基準日（指定日または本日）の取得
$target_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
    $target_date = date('Y-m-d');
}

$parsed_schedules = [];
foreach ($schedules as $sch) {
    $s_date = $sch['date'] ?? '';
    $e_date = $sch['end_date'] ?? $s_date;
    $s_time = $sch['start_time'] ?? '';
    $e_time = $sch['end_time'] ?? '';
    $is_allday = !empty($sch['is_all_day']);
    $loc = $sch['location'] ?? '';
    $memo = $sch['memo'] ?? '';

    if (empty($s_date) && !empty($sch['start_datetime'])) {
        $ts = strtotime($sch['start_datetime']);
        if ($ts) {
            $s_date = date('Y-m-d', $ts);
            $s_time = date('H:i', $ts);
        }
    }
    if (empty($e_date) && !empty($sch['end_datetime'])) {
        $ts = strtotime($sch['end_datetime']);
        if ($ts) {
            $e_date = date('Y-m-d', $ts);
            $e_time = date('H:i', $ts);
        }
    }
    if (empty($e_date) || $e_date < $s_date) $e_date = $s_date;

    $dt_str = '';
    if (!empty($s_date)) {
        $s_ts = strtotime($s_date);
        $w = $s_ts ? $week_names[(int)date('w', $s_ts)] : '';
        $dt_str = date('Y年m月d日', $s_ts) . ' (' . $w . ')';
        if ($e_date !== $s_date && !empty($e_date)) {
            $e_ts = strtotime($e_date);
            $ew = $e_ts ? $week_names[(int)date('w', $e_ts)] : '';
            $dt_str .= ' 〜 ' . date('m月d日', $e_ts) . ' (' . $ew . ')';
        }
    }

    $time_str = '';
    if ($is_allday) {
        $time_str = '終日';
    } elseif (!empty($s_time)) {
        $time_str = $s_time . (!empty($e_time) ? ' 〜 ' . $e_time : '');
    }

    $formatted = trim($dt_str . ' ' . $time_str);
    if (empty($formatted)) {
        $formatted = '随時実施';
    }

    $parsed_schedules[] = [
        'start_date' => $s_date,
        'end_date'   => $e_date,
        'start_time' => $s_time,
        'end_time'   => $e_time,
        'is_allday'  => $is_allday,
        'location'   => $loc,
        'memo'       => $memo,
        'formatted'  => $formatted,
    ];
}

// 「その日の分」のみを抽出（特定日の掲示ポスター）
$display_schedules = [];
if (!empty($parsed_schedules)) {
    // 1. 指定日（または本日）に合致する日程を抽出
    foreach ($parsed_schedules as $ps) {
        if (!empty($ps['start_date']) && $target_date >= $ps['start_date'] && $target_date <= $ps['end_date']) {
            $display_schedules[] = $ps;
        }
    }

    // 2. 合致がなく直近（未来）の予定があれば直近の1件を採用
    if (empty($display_schedules)) {
        foreach ($parsed_schedules as $ps) {
            if (!empty($ps['start_date']) && $ps['start_date'] >= $target_date) {
                $display_schedules[] = $ps;
                break;
            }
        }
    }

    // 3. すべて過去の場合は最新の1件を採用
    if (empty($display_schedules)) {
        $display_schedules[] = end($parsed_schedules);
    }
}
// 本文の長さ・行数を判定してフォントサイズを動的に最適化（A4縦1枚にスマートに収める）
$plain_text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h3>'], "\n", $post['content'] ?? ''));
$text_len = mb_strlen($plain_text);
$line_count = count(array_filter(explode("\n", $plain_text), fn($l) => trim($l) !== ''));

$content_font_class = 'font-normal';
if ($line_count > 16 || $text_len > 400) {
    $content_font_class = 'font-compact';
} elseif ($line_count > 10 || $text_len > 220) {
    $content_font_class = 'font-medium';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>【<?= htmlspecialchars($dept_name) ?> 掲示用】<?= htmlspecialchars($post['title']) ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif;
            color: #111;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .poster-container {
            width: 100%;
            height: calc(100vh - 20mm);
            border: 4px solid #1e293b;
            border-radius: 8px;
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        /* ヘッダー */
        .poster-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #1e293b;
            padding-bottom: 12px;
        }
        .header-sub {
            font-size: 13pt;
            font-weight: bold;
            color: #475569;
        }
        .header-badge {
            background: #1e3a8a;
            color: #ffffff;
            font-size: 19pt;
            font-weight: 900;
            padding: 6px 22px;
            border-radius: 6px;
            letter-spacing: 1px;
        }
        .post-date {
            font-size: 11pt;
            color: #64748b;
        }

        /* タイトルエリア */
        .title-box {
            margin: 14px 0 12px 0;
            background: #f8fafc;
            border-left: 8px solid #0284c7;
            padding: 12px 18px;
            border-radius: 4px;
        }
        .main-title {
            font-size: 24pt;
            font-weight: 900;
            line-height: 1.3;
            color: #0f172a;
        }

        /* 予定日時・場所ボックス */
        .schedule-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: #f1f5f9;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px 18px;
            margin-bottom: 14px;
        }
        .schedule-row {
            display: flex;
            font-size: 15pt;
            line-height: 1.4;
        }
        .schedule-label {
            width: 140px;
            font-weight: bold;
            color: #334155;
            flex-shrink: 0;
        }
        .schedule-val {
            font-weight: 800;
            color: #0284c7;
        }

        /* 要項・本文エリア（A4縦いっぱいに広がるフレックスボックス） */
        .content-box {
            color: #1e293b;
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            padding: 20px 24px;
            flex-grow: 1;
            overflow: hidden;
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
        }

        /* 文字サイズ自動オートフィット */
        .content-box.font-normal {
            font-size: 16pt;
            line-height: 1.8;
        }
        .content-box.font-normal li {
            font-size: 16pt;
            margin-bottom: 10px;
        }

        .content-box.font-medium {
            font-size: 14pt;
            line-height: 1.6;
        }
        .content-box.font-medium li {
            font-size: 14pt;
            margin-bottom: 8px;
        }

        .content-box.font-compact {
            font-size: 12pt;
            line-height: 1.5;
        }
        .content-box.font-compact li {
            font-size: 12pt;
            margin-bottom: 6px;
        }

        .content-box p {
            margin: 0 0 10px 0;
        }
        .content-box ul, .content-box ol {
            margin: 6px 0 12px 28px;
            padding: 0;
        }
        .content-box strong {
            color: #0f172a;
        }
        .content-box h1, .content-box h2, .content-box h3 {
            margin: 8px 0 10px 0;
            font-size: 18pt;
            color: #0f172a;
        }
        .content-box hr {
            border: none;
            border-top: 1px dashed #cbd5e1;
            margin: 12px 0;
        }

        /* フッターサイン欄 */
        .footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-top: 2px solid #1e293b;
            padding-top: 12px;
        }
        .author-info {
            font-size: 11pt;
            color: #475569;
        }
        .sign-area {
            display: flex;
            gap: 16px;
        }
        .sign-box {
            width: 140px;
            height: 64px;
            border: 2px solid #334155;
            border-radius: 4px;
            text-align: center;
            font-size: 9pt;
            font-weight: bold;
            color: #475569;
            padding-top: 4px;
            background: #f8fafc;
        }
        
        @media print {
            .no-print { display: none !important; }
            .poster-container {
                height: 100vh;
                border-width: 3px;
                padding: 16px 20px;
            }
        }
    </style>
</head>
<body>

<div class="no-print" style="background:#1e293b; color:#fff; padding:8px 16px; display:flex; justify-content:space-between; align-items:center;">
    <span>🖨️ 現場貼り出しポスター（A4縦）プレビュー [対象: <b><?= htmlspecialchars($dept_display_title) ?></b>]</span>
    <button onclick="window.print()" style="background:#0284c7; color:#fff; font-weight:bold; border:none; padding:6px 16px; border-radius:4px; cursor:pointer;">
        🖨️ この内容でブラウザ印刷
    </button>
</div>

<div class="poster-container">
    <!-- ヘッダー -->
    <div class="poster-header">
        <div class="header-sub">医療法人小野会 院内連絡掲示</div>
        <div class="header-badge"><?= htmlspecialchars($badge_text) ?></div>
        <div class="post-date">発行日: <?= date('Y/m/d', strtotime($post['created_at'])) ?></div>
    </div>

    <!-- メイン件名 -->
    <div class="title-box">
        <div class="main-title">
            <?= htmlspecialchars($post['icon_emoji'] ?? '⚠️') ?> <?= htmlspecialchars($post['title']) ?>
        </div>
    </div>

    <!-- 実施日時・スケジュール -->
    <div class="schedule-grid">
        <?php foreach ($display_schedules as $idx => $sch): ?>
            <div class="schedule-row">
                <div class="schedule-label">🗓 <?= (count($display_schedules) > 1 ? '日程 ' . ($idx + 1) : '実施日時') ?>:</div>
                <div class="schedule-val">
                    <?= htmlspecialchars($sch['formatted']) ?>
                    <?= !empty($sch['location']) ? '（場所: ' . htmlspecialchars($sch['location']) . '）' : '' ?>
                    <?= !empty($sch['memo']) ? ' <span style="font-size:11.5pt; color:#c2410c; font-weight:normal;">※' . htmlspecialchars($sch['memo']) . '</span>' : '' ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 記事要項・詳細（A4縦スペースを有効活用） -->
    <div class="content-box <?= $content_font_class ?>">
        <?php
        $raw_content = $post['content'] ?? '';
        if (strip_tags($raw_content) !== $raw_content) {
            echo $raw_content;
        } else {
            echo nl2br(htmlspecialchars($raw_content));
        }
        ?>

        <?php if (!empty($images)): ?>
            <div style="display:flex; flex-wrap:wrap; gap:12px; justify-content:center; margin-top:14px;">
                <?php foreach ($images as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="添付画像" style="max-width:100%; max-height:220px; object-fit:contain; border:1px solid #cbd5e1; border-radius:4px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- フッター＆現場受領印欄 -->
    <div class="footer-bar">
        <div class="author-info">
            発信: 事務部（<?= htmlspecialchars($post['staff_name'] ?? '山本 太') ?>） / 医療法人小野会 かわら版
        </div>
        <div class="sign-area">
            <div class="sign-box">朝礼伝達済<br><br>[　　] チェック</div>
            <div class="sign-box">現場リーダー受領印<br><br>[　　　　] 印</div>
        </div>
    </div>
</div>

</body>
</html>
