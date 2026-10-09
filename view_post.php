<?php
require_once __DIR__ . '/includes/auth_helper.php';

require_once __DIR__ . '/includes/db.php';

if (file_exists(__DIR__ . '/includes/line_helper.php')) {
    require_once __DIR__ . '/includes/line_helper.php';
}
if (file_exists(__DIR__ . '/includes/google_calendar_helper.php')) {
    require_once __DIR__ . '/includes/google_calendar_helper.php';
}

// 📱 端末固定Cookieがあれば自動ログイン！なければlogin.phpへ
$login_user = checkAuthOrAutoLogin($pdo, $_SERVER['REQUEST_URI'] ?? '');
$current_staff_id = (int)$login_user['staff_id'];
$is_admin = (bool)($login_user['is_admin'] ?? false);
$has_line_id = !empty(trim($login_user['line_user_id'] ?? ''));


$post_id = (int)($_GET['id'] ?? 0);

// POST処理（コメント・既読・未読戻し）
$notice_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    if ($_POST['action_type'] === 'add_comment') {
        $c_text = trim($_POST['comment_text'] ?? '');
        $stamp  = trim($_POST['stamp_code'] ?? '');
        if ($c_text !== '' || $stamp !== '') {
            $stmt_c = $pdo->prepare("INSERT INTO post_comments (post_id, author_id, comment_text, stamp_code, created_at) VALUES (:pid, :aid, :txt, :stamp, NOW())");
            $stmt_c->execute([':pid' => $post_id, ':aid' => $current_staff_id, ':txt' => $c_text, ':stamp' => $stamp]);
        }
    }

    if ($_POST['action_type'] === 'mark_unread') {
        $stmt_del = $pdo->prepare("DELETE FROM post_reads WHERE post_id = :pid AND staff_id = :sid");
        $stmt_del->execute([':pid' => $post_id, ':sid' => $current_staff_id]);
        $_SESSION['notice_msg'] = "↩️ ステータスを「未読」に戻しました。";
    }

    if ($_POST['action_type'] === 'mark_read') {
        $resp_status = $_POST['response_status'] ?? 'ok';
        if (!in_array($resp_status, ['ok', 'question', 'absence', 'read'], true)) {
            $resp_status = 'ok';
        }

        $stmt_r = $pdo->prepare("
            INSERT INTO post_reads (post_id, staff_id, read_at, response_status, response_at) 
            VALUES (:pid, :sid, NOW(), :resp_status, NOW())
            ON CONFLICT (post_id, staff_id) DO UPDATE SET 
                read_at = EXCLUDED.read_at,
                response_status = EXCLUDED.response_status,
                response_at = EXCLUDED.response_at
        ");
        $stmt_r->execute([
            ':pid'         => $post_id,
            ':sid'         => $current_staff_id,
            ':resp_status' => $resp_status
        ]);

        $status_labels = [
            'ok'       => '👍 了解',
            'question' => '❓ 質問あり',
            'absence'  => '⚠️ 不在・不参加',
            'read'     => '👀 既読（確認済）'
        ];
        $my_label = $status_labels[$resp_status] ?? '確認済';

        $send_self_line = isset($_POST['send_self_line']) && $_POST['send_self_line'] === '1';

        if ($send_self_line && function_exists('sendLineNotification')) {
            $stmt_t = $pdo->prepare("SELECT title, content FROM posts WHERE post_id = :pid");
            $stmt_t->execute([':pid' => $post_id]);
            $p_info = $stmt_t->fetch();

            $p_title = $p_info['title'] ?? 'お知らせ';
            $plain_content = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $p_info['content'] ?? '')));

            if (mb_strlen($plain_content) > 1500) {
                $plain_content = mb_substr($plain_content, 0, 1500) . "\n…(以下省略)";
            }

            $memo_msg = "【意思表示受付メモ: {$my_label}】\n■ 件名：{$p_title}\n----------------------------------\n【本文】\n{$plain_content}";

            $line_res = sendLineNotification($pdo, $current_staff_id, $memo_msg);

            if ($line_res['unregistered_count'] > 0) {
                $_SESSION['notice_msg'] = "✓ ステータスを「{$my_label}」に更新しました。（※LINE ID未登録のためLINE通知は送信されませんでした）";
            } else {
                $_SESSION['notice_msg'] = "✓ ステータスを「{$my_label}」に更新し、ご自身のLINEへ確認メモを送信しました。";
            }
        } else {
            $_SESSION['notice_msg'] = "✓ ステータスを「{$my_label}」に更新しました。";
        }
    }

    header("Location: view_post.php?id=" . $post_id);
    exit;
}

$notice_msg = $_SESSION['notice_msg'] ?? '';
unset($_SESSION['notice_msg']);

// 投稿データの取得
$stmt = $pdo->prepare("
    SELECT p.*, c.category_name, c.icon_emoji, c.color_code, s.staff_name AS author_name,
           (SELECT COUNT(*) FROM post_reads rd WHERE rd.post_id = p.post_id AND rd.staff_id = :sid) AS is_my_read
    FROM posts p 
    LEFT JOIN post_categories c ON p.category_id = c.category_id 
    LEFT JOIN staff s ON p.author_id = s.staff_id 
    WHERE p.post_id = :id
");
$stmt->execute([':id' => $post_id, ':sid' => $current_staff_id]);
$post = $stmt->fetch();

if (!$post) { exit('指定された記事が存在しません。'); }

$can_edit = ($is_admin || $post['author_id'] == $current_staff_id);

// 添付画像の取得
$img_stmt = $pdo->prepare("SELECT file_path FROM post_images WHERE post_id = :pid ORDER BY image_id ASC");
$img_stmt->execute([':pid' => $post_id]);
$images = $img_stmt->fetchAll(PDO::FETCH_COLUMN);

// 日時・曜日フォーマット
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

// 対象者＆既読リスト
$dept_stmt = $pdo->prepare("SELECT dept_id FROM post_target_departments WHERE post_id = :pid");
$dept_stmt->execute([':pid' => $post_id]);
$target_dept_ids = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

$staff_stmt = $pdo->prepare("SELECT staff_id FROM post_target_staff WHERE post_id = :pid");
$staff_stmt->execute([':pid' => $post_id]);
$target_staff_ids = $staff_stmt->fetchAll(PDO::FETCH_COLUMN);

$all_active_staff = $pdo->query("SELECT staff_id, staff_name, dept_id FROM staff WHERE is_deleted = FALSE ORDER BY kana ASC")->fetchAll();

$target_members = [];
foreach ($all_active_staff as $st) {
    $is_target = false;
    if (empty($target_dept_ids) && empty($target_staff_ids)) { $is_target = true; }
    else {
        if (!empty($target_dept_ids) && in_array($st['dept_id'], $target_dept_ids)) $is_target = true;
        if (!empty($target_staff_ids) && in_array($st['staff_id'], $target_staff_ids)) $is_target = true;
    }
    if ($is_target) $target_members[] = $st;
}

$read_stmt = $pdo->prepare("SELECT staff_id, response_status, response_at, response_comment FROM post_reads WHERE post_id = :pid");
$read_stmt->execute([':pid' => $post_id]);
$raw_reads = $read_stmt->fetchAll();
$read_map = [];
foreach ($raw_reads as $r) {
    $read_map[(int)$r['staff_id']] = [
        'status'  => !empty($r['response_status']) ? $r['response_status'] : 'read',
        'at'      => $r['response_at'] ? date('m/d H:i', strtotime($r['response_at'])) : '',
        'comment' => $r['response_comment'] ?? ''
    ];
}
$read_staff_ids = array_keys($read_map);
$my_read_info = $read_map[$current_staff_id] ?? null;

// 対象メンバー内の集計
$stats_count = ['ok' => 0, 'question' => 0, 'absence' => 0, 'read' => 0, 'unread' => 0];
foreach ($target_members as $tm) {
    $sid = (int)$tm['staff_id'];
    if (isset($read_map[$sid])) {
        $st = $read_map[$sid]['status'];
        if (isset($stats_count[$st])) $stats_count[$st]++;
        else $stats_count['read']++;
    } else {
        $stats_count['unread']++;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($post['title']) ?> | 院内かわら版</title>
    <style>
        :root { --primary-color: #005a9c; --bg-color: #f4f6f9; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-color); color: #333; margin: 0; padding: 0; line-height: 1.6; }
        header { background: var(--primary-color); color: #fff; padding: 0.8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .btn-back { background: rgba(255,255,255,0.2); color: #fff; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.85rem; }
        
        main { max-width: 850px; margin: 1.5rem auto; padding: 0 1rem; }
        .card { background: #fff; border-radius: 8px; padding: 1.5rem; border: 1px solid #e0e0e0; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .alert-notice { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; padding: 10px 14px; border-radius: 6px; font-size: 0.88rem; font-weight: bold; margin-bottom: 15px; }
        .title { font-size: 1.4rem; font-weight: bold; color: #2c3e50; margin: 10px 0; }
        .meta { font-size: 0.85rem; color: #666; display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; flex-wrap: wrap; gap: 5px; }
        
        .event-box { background: #eef6fc; border: 1px solid #b8daff; color: #004085; padding: 10px 14px; border-radius: 6px; font-weight: bold; margin-bottom: 15px; }
        .content { font-size: 1rem; line-height: 1.7; white-space: pre-wrap; margin-bottom: 20px; }
        
        .image-gallery { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 25px; background: #f8f9fa; padding: 12px; border-radius: 6px; }
        .image-gallery img { max-width: 100%; max-height: 350px; border-radius: 6px; border: 1px solid #ccc; cursor: pointer; transition: transform 0.2s; }
        .image-gallery img:hover { transform: scale(1.02); }

        .read-box {
            background: #eefbf4;
            border: 2px solid #a3e6cd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .btn-unread-reset {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 8px;
        }
        .btn-unread-reset:hover { background: #5a6268; }

        .btn-action-group { display: flex; gap: 10px; align-items: center; margin-top: 15px; flex-wrap: wrap; }
        .btn-print { background: #f8f9fa; border: 1px solid #ccc; color: #333; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.85rem; }
        .btn-edit { background: #e67e22; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.85rem; }

        .section-title { font-size: 1rem; font-weight: bold; margin-top: 25px; padding-bottom: 5px; border-bottom: 2px solid var(--primary-color); }
        .comment-item { border-bottom: 1px dashed #ddd; padding: 8px 0; display: flex; justify-content: space-between; font-size: 0.9rem; }
        .read-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 6px; margin-top: 10px; }
        .read-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.78rem; text-align: center; border: 1px solid transparent; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 4px; }
        .read-badge.is-ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .read-badge.is-question { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .read-badge.is-absence { background: #ede9fe; color: #5b21b6; border-color: #ddd6fe; }
        .read-badge.is-read { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        .read-badge.is-unread { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .stat-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; font-size: 0.76rem; font-weight: bold; border: 1px solid transparent; }
        .stat-chip-ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .stat-chip-question { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .stat-chip-absence { background: #ede9fe; color: #5b21b6; border-color: #ddd6fe; }
        .stat-chip-read { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        .stat-chip-unread { background: #fee2e2; color: #991b1b; border-color: #fecaca; }

        /* 📱 スマホ最適化レスポンシブスタイル */
        @media (max-width: 640px) {
            body { padding: 0; background: #fff; }
            header { padding: 0.6rem 0.8rem; }
            main { margin: 0; padding: 0; }
            .card { border: none; border-radius: 0; padding: 14px 12px; box-shadow: none; }
            .title { font-size: 1.22rem; line-height: 1.4; margin: 8px 0; }
            .meta { font-size: 0.78rem; gap: 4px; flex-direction: column; border-bottom: 1px dashed #e2e8f0; }
            .content { font-size: 0.95rem; line-height: 1.65; }
            .event-box { padding: 8px 10px; font-size: 0.86rem; }
            .image-gallery { padding: 6px; gap: 8px; }
            .image-gallery img { max-height: 240px; }
            .read-box { padding: 12px 10px !important; margin-bottom: 15px; border-radius: 8px; }
            .read-btn-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 6px !important; }
            .read-btn-grid button { padding: 12px 6px !important; font-size: 0.88rem !important; }
            .btn-action-group { flex-direction: column; align-items: stretch; gap: 6px; }
            .btn-action-group a, .btn-action-group button { text-align: center; justify-content: center; }
            .read-grid { grid-template-columns: repeat(auto-fill, minmax(85px, 1fr)); gap: 4px; }
            .read-badge { font-size: 0.72rem; padding: 3px 4px; }
        }
    </style>
</head>
<body>

<header>
    <div style="font-weight:bold; font-size:1.02rem; display:flex; align-items:center; gap:6px;">
        <span>📜</span>
        <span>院内かわら版</span>
    </div>
    <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:0.78rem; background:rgba(255,255,255,0.18); padding:3px 8px; border-radius:4px; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($login_user['staff_name']) ?>">
            👤 <?= htmlspecialchars($login_user['staff_name']) ?>
        </span>
        <a href="login.php?switch_user=1" title="別のアカウントに切り替える" style="color:rgba(255,255,255,0.85); font-size:0.75rem; text-decoration:underline;">切替</a>
        <a href="index.php" class="btn-back" style="background:rgba(255,255,255,0.25);">🏠 メニュー</a>
        <a href="kawara_list.php" class="btn-back">← 一覧</a>
    </div>
</header>

<main>
    <div class="card">
        <?php if ($notice_msg): ?>
            <div class="alert-notice"><?= htmlspecialchars($notice_msg) ?></div>
        <?php endif; ?>

        <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
            <span style="background:<?= $post['color_code'] ?? '#005a9c' ?>; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.8rem; font-weight:bold;">
                <?= htmlspecialchars($post['icon_emoji']) ?> <?= htmlspecialchars($post['category_name']) ?>
            </span>
            <?php if (!$post['is_my_read']): ?>
                <span style="background:#f3e8ff; color:#6b21a8; border:1px solid #9333ea; padding:2px 8px; border-radius:4px; font-size:0.8rem; font-weight:bold;">🟣 未読</span>
            <?php else: ?>
                <span style="background:#e0f2fe; color:#0369a1; border:1px solid #0284c7; padding:2px 8px; border-radius:4px; font-size:0.8rem; font-weight:bold;">🩵 既読</span>
            <?php endif; ?>
            <?php if ($post['is_pinned']): ?>
                <span style="background:#343a40; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.75rem; font-weight:bold;">📌 固定</span>
            <?php endif; ?>
        </div>

        <div class="title"><?= htmlspecialchars($post['title']) ?></div>

        <!-- 🆕 メタ情報に「掲載期限」を追加 -->
        <div class="meta">
            <span>👤 投稿者: <?= htmlspecialchars($post['author_name']) ?> (<?= htmlspecialchars($post['author_dept']) ?>)</span>
            <span>投稿日時: <?= date('Y/m/d H:i', strtotime($post['created_at'])) ?></span>
            <span>掲載期限: <?= empty($post['display_until']) ? '♾️ 無期限' : date('Y/m/d 23:59', strtotime($post['display_until'])) ?></span>
        </div>

        <?php
        $multi_schedules = [];
        if (!empty($post['event_schedules'])) {
            $dec = json_decode($post['event_schedules'], true);
            if (is_array($dec) && count($dec) > 0) {
                $multi_schedules = $dec;
            }
        }
        ?>

        <?php if (!empty($multi_schedules)): ?>
            <div class="event-box" style="display:flex; flex-direction:column; gap:8px;">
                <div style="font-weight:bold; font-size:1.05rem; border-bottom:1px solid #b8daff; padding-bottom:4px; margin-bottom:4px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                    <span>🗓 実施・対象日程 (全 <?= count($multi_schedules) ?> 回)</span>
                    <span style="font-size:0.78rem; font-weight:normal; color:#0369a1;">💡 ボタンから個人のGoogleカレンダーへ直接予定を追加できます</span>
                </div>
                <?php foreach ($multi_schedules as $idx => $sch): 
                    $s_ts = !empty($sch['date']) ? strtotime($sch['date']) : (!empty($sch['start_datetime']) ? strtotime($sch['start_datetime']) : null);
                    $w_name = $s_ts ? $week_names[(int)date('w', $s_ts)] : '';
                    $d_str = $s_ts ? date('Y/m/d', $s_ts) . '(' . $w_name . ')' : ($sch['date'] ?? '未定');
                    $t_str = !empty($sch['is_all_day']) ? '終日' : ($sch['start_time'] ?? '') . ' 〜 ' . ($sch['end_time'] ?? '');
                    $loc   = !empty($sch['location']) ? htmlspecialchars($sch['location']) : '';
                    $memo  = !empty($sch['memo']) ? htmlspecialchars($sch['memo']) : '';

                    // Googleカレンダー登録URL
                    $gcal_url = '#';
                    if (function_exists('build_google_calendar_add_url') && $s_ts) {
                        $sch_is_all_day = !empty($sch['is_all_day']);
                        $start_val = $sch_is_all_day ? date('Y-m-d', $s_ts) : date('Y-m-d', $s_ts) . ' ' . (!empty($sch['start_time']) ? $sch['start_time'] . ':00' : '09:00:00');
                        $end_val = null;
                        if (!$sch_is_all_day && !empty($sch['end_time'])) {
                            $end_val = date('Y-m-d', $s_ts) . ' ' . $sch['end_time'] . ':00';
                        }
                        $host = $_SERVER['HTTP_HOST'] ?? '192.168.1.16';
                        $dtl = "【院内かわら版】" . $post['title'] . "\n" . ($memo ? "メモ: " . $memo . "\n" : "") . "http://" . $host . "/kawara/view_post.php?id=" . $post_id;
                        $gcal_url = build_google_calendar_add_url($post['title'] . " (第" . ($idx + 1) . "回)", $start_val, $end_val, $sch_is_all_day, $dtl, $loc);
                    }
                ?>
                    <div style="font-size:0.92rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; background:rgba(255,255,255,0.7); padding:6px 10px; border-radius:6px; border:1px solid #bfdbfe;">
                        <div>
                            <b>第 <?= $idx + 1 ?> 回:</b> <?= $d_str ?> <?= $t_str ?> <?= $loc ? ' | 📍 ' . $loc : '' ?> <?= $memo ? ' | <span style="color:#c2410c;">※' . $memo . '</span>' : '' ?>
                        </div>
                        <?php if ($gcal_url !== '#'): ?>
                            <a href="<?= htmlspecialchars($gcal_url) ?>" target="_blank" rel="noopener noreferrer" 
                               style="display:inline-flex; align-items:center; gap:4px; background:#1a73e8; color:#fff; text-decoration:none; padding:4px 12px; border-radius:6px; font-size:0.80rem; font-weight:bold; box-shadow:0 1px 2px rgba(0,0,0,0.12); white-space:nowrap;"
                               title="この日程をご自身の個人のGoogleカレンダーに追加">
                                <span>📅</span> 個人のGoogleカレンダーに登録
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($event_date_str): ?>
            <?php
            $single_gcal_url = '#';
            if (function_exists('build_google_calendar_add_url') && $start_dt) {
                $host = $_SERVER['HTTP_HOST'] ?? '192.168.1.16';
                $dtl = "【院内かわら版】" . $post['title'] . "\nhttp://" . $host . "/kawara/view_post.php?id=" . $post_id;
                $single_gcal_url = build_google_calendar_add_url(
                    $post['title'], 
                    $start_dt->format('Y-m-d H:i:s'), 
                    $end_dt ? $end_dt->format('Y-m-d H:i:s') : null, 
                    false, 
                    $dtl, 
                    ''
                );
            }
            ?>
            <div class="event-box" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    🗓 実施・対象日時: <?= $event_date_str ?>
                </div>
                <?php if ($single_gcal_url !== '#'): ?>
                    <a href="<?= htmlspecialchars($single_gcal_url) ?>" target="_blank" rel="noopener noreferrer" 
                       style="display:inline-flex; align-items:center; gap:5px; background:#1a73e8; color:#fff; text-decoration:none; padding:5px 12px; border-radius:6px; font-size:0.82rem; font-weight:bold; box-shadow:0 1px 3px rgba(0,0,0,0.1); white-space:nowrap;"
                       title="この予定をご自身の個人のGoogleカレンダーに追加">
                        <span>📅</span> 個人のGoogleカレンダーに登録
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <div class="content"><?= $post['content'] ?></div>

        <?php if (!empty($images)): ?>
            <div style="font-weight:bold; font-size:0.9rem; margin-bottom:5px;">🖼 添付画像 (<?= count($images) ?>枚)</div>
            <div class="image-gallery">
                <?php foreach ($images as $img): ?>
                    <a href="<?= htmlspecialchars($img) ?>" target="_blank" title="クリックで元画像を大きく開く">
                        <img src="<?= htmlspecialchars($img) ?>" alt="添付画像">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- 意思表示・確認アクションボックス -->
        <div class="read-box" style="text-align:left; background:#f8fafc; border:1px solid #cbd5e1; padding:16px 20px; border-radius:10px; margin-bottom:20px;">
            <?php if ($my_read_info): 
                $my_st = $my_read_info['status'];
                $my_st_labels = ['ok' => ['👍 了解済み', '#166534', '#dcfce7'], 'question' => ['❓ 質問あり', '#92400e', '#fef3c7'], 'absence' => ['⚠️ 不在・不参加', '#5b21b6', '#ede9fe'], 'read' => ['👀 確認（既読）済み', '#334155', '#f1f5f9']];
                $cur_badge = $my_st_labels[$my_st] ?? ['確認済', '#334155', '#f1f5f9'];
            ?>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px; padding-bottom:10px; border-bottom:1px dashed #cbd5e1;">
                    <div style="font-weight:bold; font-size:0.95rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span>あなたの確認状況:</span>
                        <span style="background:<?= $cur_badge[2] ?>; color:<?= $cur_badge[1] ?>; padding:3px 10px; border-radius:6px; font-size:0.88rem; font-weight:800;">
                            <?= $cur_badge[0] ?>
                        </span>
                        <span style="font-size:0.78rem; color:#64748b; font-weight:normal;">(<?= $my_read_info['at'] ?>)</span>
                    </div>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action_type" value="mark_unread">
                        <button type="submit" class="btn-unread-reset" style="margin:0; padding:4px 10px; font-size:0.78rem;">↩️ 未読に戻す</button>
                    </form>
                </div>
                <div style="font-size:0.82rem; color:#64748b; margin-bottom:8px;">ステータスを変更する場合は、以下のボタンを押してください:</div>
            <?php else: ?>
                <div style="font-weight:800; font-size:0.95rem; color:#0f172a; margin-bottom:10px;">
                    📝 この連絡・お知らせの内容を確認し、意思表示を選択してください:
                </div>
            <?php endif; ?>

            <form method="POST" style="margin:0;">
                <input type="hidden" name="action_type" value="mark_read">
                <div class="read-btn-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:8px; margin-bottom:10px;">
                    <button type="submit" name="response_status" value="ok" style="background:#16a34a; color:#fff; border:none; padding:10px 12px; border-radius:6px; font-weight:bold; font-size:0.92rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                        👍 了解しました
                    </button>
                    <button type="submit" name="response_status" value="question" style="background:#d97706; color:#fff; border:none; padding:10px 12px; border-radius:6px; font-weight:bold; font-size:0.92rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                        ❓ 質問あり
                    </button>
                    <button type="submit" name="response_status" value="absence" style="background:#7c3aed; color:#fff; border:none; padding:10px 12px; border-radius:6px; font-weight:bold; font-size:0.92rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                        ⚠️ 不在・不参加
                    </button>
                    <button type="submit" name="response_status" value="read" style="background:#475569; color:#fff; border:none; padding:10px 12px; border-radius:6px; font-weight:bold; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:4px;">
                        👀 既読のみ
                    </button>
                </div>
                <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; color:#475569; cursor:pointer;">
                    <input type="checkbox" name="send_self_line" value="1" <?= $has_line_id ? 'checked' : '' ?> onclick="checkLineIdStatus(event, <?= $has_line_id ? 'true' : 'false' ?>)" style="accent-color:#16a34a; width:15px; height:15px;">
                    <span>📲 同時に自分のLINE宛てにも確認メモを送信する</span>
                </label>
            </form>
        </div>

        <div class="btn-action-group">
            <a href="print_post.php?id=<?= $post_id ?>" target="_blank" class="btn-print">🖨️ ポスター風印刷</a>
            <?php if ($can_edit || (!empty($current_user['is_admin']))): ?>
                <button type="button" onclick="openLineNotifyModal(<?= $post_id ?>)" style="background:#16a34a; color:#fff; border:none; padding:6px 14px; border-radius:4px; font-weight:bold; font-size:0.85rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                    💬 LINE通知（プレビュー・自分宛テスト）
                </button>
            <?php endif; ?>
            <?php if ($can_edit): ?>
                <a href="create_post.php?id=<?= $post_id ?>&return_to=view" class="btn-edit">✏️ 記事を編集する（投稿者/管理者専用）</a>
            <?php endif; ?>
        </div>

        <div class="section-title">💬 コメント・スタンプ</div>
        <?php
        $stmt_cm = $pdo->prepare("SELECT cm.*, s.staff_name FROM post_comments cm LEFT JOIN staff s ON cm.author_id = s.staff_id WHERE cm.post_id = :pid ORDER BY cm.created_at ASC");
        $stmt_cm->execute([':pid' => $post_id]);
        $comments = $stmt_cm->fetchAll();
        ?>
        <?php if (!empty($comments)): ?>
            <div style="margin:10px 0;">
                <?php foreach ($comments as $cm): ?>
                    <div class="comment-item">
                        <div>
                            <b><?= htmlspecialchars($cm['staff_name']) ?>:</b> <?= htmlspecialchars($cm['comment_text']) ?>
                            <?php if ($cm['stamp_code']): ?><span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:0.8rem;"><?= htmlspecialchars($cm['stamp_code']) ?></span><?php endif; ?>
                        </div>
                        <span style="font-size:0.75rem; color:#999;"><?= date('m/d H:i', strtotime($cm['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color:#888; font-size:0.85rem; margin:10px 0;">コメントはありません。</p>
        <?php endif; ?>

        <form method="POST" style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px;">
            <input type="hidden" name="action_type" value="add_comment">
            <input type="text" name="comment_text" placeholder="コメントを入力..." style="flex:1; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.9rem;">
            <button type="submit" name="stamp_code" value="👍 了解です" style="background:#fff; border:1px solid #ccc; padding:6px 10px; border-radius:4px; cursor:pointer;">👍 了解</button>
            <button type="submit" name="stamp_code" value="👌 確認済" style="background:#fff; border:1px solid #ccc; padding:6px 10px; border-radius:4px; cursor:pointer;">👌 確認済</button>
            <button type="submit" style="background:var(--primary-color); color:white; border:none; padding:6px 14px; border-radius:4px; font-weight:bold; cursor:pointer;">送信</button>
        </form>

        <div class="section-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <span>👀 確認・意思表示状況 (確認済 <?= count(array_intersect(array_column($target_members, 'staff_id'), $read_staff_ids)) ?> / 全 <?= count($target_members) ?>名)</span>
            <div style="display:flex; gap:4px; flex-wrap:wrap;">
                <span class="stat-chip stat-chip-ok">👍 了解 <?= $stats_count['ok'] ?></span>
                <?php if ($stats_count['question'] > 0): ?><span class="stat-chip stat-chip-question">❓ 質問 <?= $stats_count['question'] ?></span><?php endif; ?>
                <?php if ($stats_count['absence'] > 0): ?><span class="stat-chip stat-chip-absence">⚠️ 不在 <?= $stats_count['absence'] ?></span><?php endif; ?>
                <span class="stat-chip stat-chip-read">👀 既読 <?= $stats_count['read'] ?></span>
                <span class="stat-chip stat-chip-unread">⏳ 未読 <?= $stats_count['unread'] ?></span>
            </div>
        </div>
        <div class="read-grid">
            <?php foreach ($target_members as $st): 
                $sid = (int)$st['staff_id'];
                $r_info = $read_map[$sid] ?? null;
                $st_type = $r_info ? $r_info['status'] : 'unread';
                $icon = '';
                $cls = 'is-unread';
                if ($st_type === 'ok') { $icon = '👍 '; $cls = 'is-ok'; }
                elseif ($st_type === 'question') { $icon = '❓ '; $cls = 'is-question'; }
                elseif ($st_type === 'absence') { $icon = '⚠️ '; $cls = 'is-absence'; }
                elseif ($st_type === 'read') { $icon = '👀 '; $cls = 'is-read'; }
                $tooltip = htmlspecialchars($st['staff_name']) . ($r_info ? " ({$r_info['at']})" . (!empty($r_info['comment']) ? ": {$r_info['comment']}" : '') : ' (未読)');
            ?>
                <div class="read-badge <?= $cls ?>" title="<?= $tooltip ?>">
                    <?= $icon ?><?= htmlspecialchars($st['staff_name']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<script>
function checkLineIdStatus(e, hasLine) {
    if (e.target.checked && !hasLine) {
        alert("LINEIDが登録されていません。利用したい方は管理者に連絡してください");
        e.target.checked = false;
    }
}
</script>

<?php require_once __DIR__ . '/includes/line_notify_modal.php'; ?>

</body>
</html>