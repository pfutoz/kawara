<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$timeout_duration = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy(); header("Location: login.php?reason=timeout"); exit;
}
$_SESSION['last_activity'] = time();

// 未ログイン状態のチェック（認証ガード）
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php");
    exit;
}

$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) { exit('DB接続エラー: ' . $e->getMessage()); }

if (file_exists(__DIR__ . '/includes/line_helper.php')) {
    require_once __DIR__ . '/includes/line_helper.php';
}

$current_staff_id = (int)$_SESSION['staff_id'];
$stmt_user = $pdo->prepare("SELECT staff_id, staff_name, role, dept_id, is_admin, line_user_id FROM staff WHERE staff_id = :id");
$stmt_user->execute([':id' => $current_staff_id]);
$login_user = $stmt_user->fetch();
if (!$login_user) { header("Location: login.php"); exit; }
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
        $stmt_r = $pdo->prepare("INSERT INTO post_reads (post_id, staff_id, read_at) VALUES (:pid, :sid, NOW()) ON CONFLICT DO NOTHING");
        $stmt_r->execute([':pid' => $post_id, ':sid' => $current_staff_id]);

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

            $memo_msg = "【既読完了メモ】\n■ 件名：{$p_title}\n----------------------------------\n【本文】\n{$plain_content}";

            $line_res = sendLineNotification($pdo, $current_staff_id, $memo_msg);

            if ($line_res['unregistered_count'] > 0) {
                $_SESSION['notice_msg'] = "✓ 既読を付けました。（※LINE IDが未登録のためLINE通知は送信されませんでした）";
            } else {
                $_SESSION['notice_msg'] = "✓ 既読を付けました。自分のLINEに本文メモを送信しました。";
            }
        } else {
            $_SESSION['notice_msg'] = "✓ 既読を付けました。";
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

$read_stmt = $pdo->prepare("SELECT staff_id FROM post_reads WHERE post_id = :pid");
$read_stmt->execute([':pid' => $post_id]);
$read_staff_ids = $read_stmt->fetchAll(PDO::FETCH_COLUMN);
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
        .read-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.78rem; text-align: center; }
        .read-badge.is-read { background: #d4edda; color: #155724; }
        .read-badge.is-unread { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<header>
    <div style="font-weight:bold; font-size:1.1rem;">📜 お知らせ詳細閲覧</div>
    <div>
        <a href="index.php" class="btn-back">← 一覧へ戻る</a>
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
            <div class="event-box" style="display:flex; flex-direction:column; gap:6px;">
                <div style="font-weight:bold; font-size:1.05rem; border-bottom:1px solid #b8daff; padding-bottom:4px; margin-bottom:4px;">
                    🗓 実施・対象日程 (全 <?= count($multi_schedules) ?> 回)
                </div>
                <?php foreach ($multi_schedules as $idx => $sch): 
                    $s_ts = !empty($sch['date']) ? strtotime($sch['date']) : (!empty($sch['start_datetime']) ? strtotime($sch['start_datetime']) : null);
                    $w_name = $s_ts ? $week_names[(int)date('w', $s_ts)] : '';
                    $d_str = $s_ts ? date('Y/m/d', $s_ts) . '(' . $w_name . ')' : ($sch['date'] ?? '未定');
                    $t_str = !empty($sch['is_all_day']) ? '終日' : ($sch['start_time'] ?? '') . ' 〜 ' . ($sch['end_time'] ?? '');
                    $loc   = !empty($sch['location']) ? '📍 場所: ' . htmlspecialchars($sch['location']) : '';
                    $memo  = !empty($sch['memo']) ? '※' . htmlspecialchars($sch['memo']) : '';
                ?>
                    <div style="font-size:0.95rem;">
                        <b>第 <?= $idx + 1 ?> 回:</b> <?= $d_str ?> <?= $t_str ?> <?= $loc ? ' | ' . $loc : '' ?> <?= $memo ? ' | <span style="color:#c2410c;">' . $memo . '</span>' : '' ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($event_date_str): ?>
            <div class="event-box">
                🗓 実施・対象日時: <?= $event_date_str ?>
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

        <?php if (!$post['is_my_read']): ?>
            <div class="read-box">
                <form method="POST">
                    <input type="hidden" name="action_type" value="mark_read">
                    
                    <button type="submit" style="background:#28a745; color:white; border:none; padding:12px 30px; border-radius:6px; font-weight:bold; font-size:1rem; cursor:pointer; width:100%; max-width:400px; box-shadow:0 2px 6px rgba(40,167,69,0.3);">
                         内容を確認しました（既読を付ける）
                    </button>
                    <br>
                    <label style="display:inline-flex; align-items:center; gap:6px; margin-top:10px; font-size:0.85rem; color:#0f5132; font-weight:bold; cursor:pointer;">
                        <input type="checkbox" name="send_self_line" value="1" <?= $has_line_id ? 'checked' : '' ?> onclick="checkLineIdStatus(event, <?= $has_line_id ? 'true' : 'false' ?>)" style="accent-color:#198754; width:16px; height:16px;">
                        <span>📲 自分のLINE宛てにこの記事の本文テキストをメモ送信する</span>
                    </label>
                </form>
            </div>
        <?php else: ?>
            <div style="color:#0369a1; font-weight:bold; background:#e0f2fe; padding:12px; border-radius:6px; text-align:center; margin-bottom:15px;">
                <div>🩵 このお知らせは確認済み（既読）です</div>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action_type" value="mark_unread">
                    <button type="submit" class="btn-unread-reset">↩️ この記事を未読に戻す</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="btn-action-group">
            <a href="print_post.php?id=<?= $post_id ?>" target="_blank" class="btn-print">🖨️ ポスター風印刷</a>
            <?php if ($can_edit): ?>
                <a href="create_post.php?id=<?= $post_id ?>" class="btn-edit">✏️ 記事を編集する（投稿者/管理者専用）</a>
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

        <div class="section-title">👀 確認状況 (既読 <?= count(array_intersect(array_column($target_members, 'staff_id'), $read_staff_ids)) ?> / 全 <?= count($target_members) ?>名)</div>
        <div class="read-grid">
            <?php foreach ($target_members as $st): $is_st_read = in_array($st['staff_id'], $read_staff_ids); ?>
                <div class="read-badge <?= $is_st_read ? 'is-read' : 'is-unread' ?>">
                    <?= htmlspecialchars($st['staff_name']) ?> <?= $is_st_read ? '✓' : '' ?>
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

</body>
</html>