<?php
// 1. セッション開始と30分タイムアウト処理
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$timeout_duration = 1800; // 30分

if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: login.php?reason=timeout");
        exit;
    }
}
$_SESSION['last_activity'] = time();

// 未ログイン状態のチェック（認証ガード）
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php");
    exit;
}

// 2. DB接続設定 ＆ LINEヘルパー読み込み
$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}

if (file_exists('includes/line_helper.php')) {
    require_once 'includes/line_helper.php';
}

// 3. ログインユーザー情報（line_user_idも一緒に取得）
$current_staff_id = (int)$_SESSION['staff_id'];
$stmt_user = $pdo->prepare("SELECT staff_id, staff_name, role, dept_id, is_admin, line_user_id FROM staff WHERE staff_id = :id");
$stmt_user->execute([':id' => $current_staff_id]);
$login_user = $stmt_user->fetch();

if (!$login_user) { header("Location: login.php"); exit; }
$is_admin = (bool)($login_user['is_admin'] ?? false);
$has_line_id = !empty(trim($login_user['line_user_id'] ?? ''));

// 本日の生存確認・安否報告チェック
$today_start = date('Y-m-d 00:00:00');
$stmt_safety = $pdo->prepare("SELECT COUNT(*) FROM safety_checks WHERE staff_id = :id AND reported_at >= :today");
$stmt_safety->execute([':id' => $current_staff_id, ':today' => $today_start]);
$my_safety_reported_today = ($stmt_safety->fetchColumn() > 0);

// POST処理（コメント追加・既読・未読戻し）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $p_id = (int)$_POST['post_id'];
    
    if ($_POST['action_type'] === 'add_comment') {
        $c_text = trim($_POST['comment_text'] ?? '');
        $stamp  = trim($_POST['stamp_code'] ?? '');
        if ($c_text !== '' || $stamp !== '') {
            $stmt_c = $pdo->prepare("INSERT INTO post_comments (post_id, author_id, comment_text, stamp_code, created_at) VALUES (:pid, :aid, :txt, :stamp, NOW())");
            $stmt_c->execute([':pid' => $p_id, ':aid' => $current_staff_id, ':txt' => $c_text, ':stamp' => $stamp]);
        }
    }

    // 既読を未読に戻す処理
    if ($_POST['action_type'] === 'mark_unread') {
        $stmt_del = $pdo->prepare("DELETE FROM post_reads WHERE post_id = :pid AND staff_id = :sid");
        $stmt_del->execute([':pid' => $p_id, ':sid' => $current_staff_id]);
        $_SESSION['notice_msg'] = "↩️ ステータスを「未読」に戻しました。";
    }

    if ($_POST['action_type'] === 'mark_read') {
        $stmt_r = $pdo->prepare("INSERT INTO post_reads (post_id, staff_id, read_at) VALUES (:pid, :sid, NOW()) ON CONFLICT DO NOTHING");
        $stmt_r->execute([':pid' => $p_id, ':sid' => $current_staff_id]);

        $send_self_line = isset($_POST['send_self_line']) && $_POST['send_self_line'] === '1';

        // 自分のLINE宛てへ本文入り既読メモを送信
        if ($send_self_line && function_exists('sendLineNotification')) {
            $stmt_post = $pdo->prepare("SELECT title, content, target_datetime, target_end_datetime FROM posts WHERE post_id = :pid");
            $stmt_post->execute([':pid' => $p_id]);
            $p_info = $stmt_post->fetch();

            $p_title = $p_info['title'] ?? 'お知らせ';
            $plain_content = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $p_info['content'] ?? '')));
            
            if (mb_strlen($plain_content) > 1500) {
                $plain_content = mb_substr($plain_content, 0, 1500) . "\n…(以下省略)";
            }

            $memo_msg = "【既読完了メモ】\n■ 件名：{$p_title}\n----------------------------------\n【本文】\n{$plain_content}";

            $line_res = sendLineNotification($pdo, $current_staff_id, $memo_msg);

            if ($line_res['unregistered_count'] > 0) {
                $_SESSION['notice_msg'] = "✓ 既読を記録しました。（※LINE IDが未登録のため自分のLINE宛てのメモ送信はスキップされました）";
            } else {
                $_SESSION['notice_msg'] = "✓ 既読を記録し、自分のLINEに本文メモを送信しました。";
            }
        } else {
            $_SESSION['notice_msg'] = "✓ 既読を記録しました。";
        }
    }

    header("Location: index.php?" . http_build_query($_GET));
    exit;
}

$notice_msg = $_SESSION['notice_msg'] ?? '';
unset($_SESSION['notice_msg']);

// 4. カテゴリーリスト＆パラメータ取得
$selected_cat  = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$date_filter   = $_GET['date_filter'] ?? 'all';
$categories    = $pdo->query("SELECT * FROM post_categories WHERE is_active = TRUE ORDER BY display_order")->fetchAll();

// 5. 投稿一覧の取得SQL
$sql = "SELECT 
            p.*, 
            c.category_name, c.category_code, c.icon_emoji, c.color_code,
            s.staff_name AS author_name,
            (SELECT COUNT(*) FROM post_images img WHERE img.post_id = p.post_id) AS image_count,
            (SELECT COUNT(*) FROM post_comments cm WHERE cm.post_id = p.post_id) AS comment_count,
            (SELECT COUNT(*) FROM post_reads rd WHERE rd.post_id = p.post_id AND rd.staff_id = {$current_staff_id}) AS is_my_read
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id";

$today_str      = date('Y-m-d');
$tomorrow_str   = date('Y-m-d', strtotime('+1 day'));
$plus7_end_str  = date('Y-m-d', strtotime('+7 days'));

$this_month_start = date('Y-m-01');
$this_month_end   = date('Y-m-t');

$next_month_start = date('Y-m-01', strtotime('first day of next month'));
$next_month_end   = date('Y-m-t', strtotime('last day of next month'));

$where_clauses = [];

// 掲載期限・過去投稿モードの判定
if ($date_filter === 'past') {
    // 過去の投稿モード：掲載期限終了、または対象日時が過去の投稿
    $where_clauses[] = "(p.display_until < NOW() OR (p.display_until IS NULL AND p.target_datetime IS NOT NULL AND DATE(COALESCE(p.target_end_datetime, p.target_datetime)) < '{$today_str}'))";
} elseif ($date_filter === 'all_history') {
    // 全履歴モード：過去・現在・未来すべての投稿（条件制限なし）
} else {
    // 通常モード（全期間・今日・明日など）：現在掲載中の投稿
    $where_clauses[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
}

if ($selected_cat > 0) {
    $where_clauses[] = "p.category_id = " . $selected_cat;
}

if ($date_filter === 'today') {
    $where_clauses[] = "p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$today_str}'";
} elseif ($date_filter === 'tomorrow') {
    $where_clauses[] = "p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$tomorrow_str}'";
} elseif ($date_filter === 'plus7') {
    $where_clauses[] = "p.target_datetime IS NOT NULL AND DATE(p.target_datetime) >= '{$today_str}' AND DATE(p.target_datetime) <= '{$plus7_end_str}'";
} elseif ($date_filter === 'this_month') {
    $where_clauses[] = "p.target_datetime IS NOT NULL AND DATE(p.target_datetime) >= '{$this_month_start}' AND DATE(p.target_datetime) <= '{$this_month_end}'";
} elseif ($date_filter === 'next_month') {
    $where_clauses[] = "p.target_datetime IS NOT NULL AND DATE(p.target_datetime) >= '{$next_month_start}' AND DATE(p.target_datetime) <= '{$next_month_end}'";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$raw_posts = $pdo->query($sql)->fetchAll();

// 6. 重要度判定 ＆ ソートスコア計算
$now = new DateTime();
$week_names = ['日', '月', '火', '水', '木', '金', '土'];
$posts = [];

foreach ($raw_posts as $p) {
    $start_dt = $p['target_datetime'] ? new DateTime($p['target_datetime']) : null;
    $end_dt   = $p['target_end_datetime'] ? new DateTime($p['target_end_datetime']) : null;
    $created_dt = new DateTime($p['created_at']);
    
    $is_urgent = false;
    $is_within_24h = false;
    $is_today_event = false;

    $is_new_post = ($now->getTimestamp() - $created_dt->getTimestamp()) <= 86400;

    if ($start_dt) {
        $diff_sec = $start_dt->getTimestamp() - $now->getTimestamp();
        
        if (($diff_sec <= 10800 && ($end_dt ? $now <= $end_dt : $diff_sec >= -86400)) || $p['category_code'] === 'urgent') {
            $is_urgent = true;
        }
        if ($diff_sec > 0 && $diff_sec <= 86400) {
            $is_within_24h = true;
        }
        if ($start_dt->format('Y-m-d') === $now->format('Y-m-d')) {
            $is_today_event = true;
        }

        $start_str = $start_dt->format('Y/m/d') . '(' . $week_names[(int)$start_dt->format('w')] . ') ' . $start_dt->format('H:i');

        if ($end_dt) {
            if ($start_dt->format('Y-m-d') === $end_dt->format('Y-m-d')) {
                $end_str = $end_dt->format('H:i');
            } else {
                $end_str = $end_dt->format('Y/m/d') . '(' . $week_names[(int)$end_dt->format('w')] . ') ' . $end_dt->format('H:i');
            }
            $p['formatted_event_date'] = $start_str . ' 〜 ' . $end_str;
        } else {
            $p['formatted_event_date'] = $start_str;
        }
    } else {
        if ($p['category_code'] === 'urgent') {
            $is_urgent = true;
        }
        $p['formatted_event_date'] = null;
    }

    if ($is_urgent) {
        $priority_level = 'urgent';
    } elseif ($is_within_24h || $is_today_event || $p['is_pinned'] || in_array($p['category_code'], ['important', 'facility'])) {
        $priority_level = 'important';
    } else {
        $priority_level = 'normal';
    }

    $sort_score = 0;
    if ($priority_level === 'urgent') $sort_score = 3000;
    elseif ($priority_level === 'important') $sort_score = 2000;
    else $sort_score = 1000;

    if ($p['is_pinned']) $sort_score += 5000;
    if (!$p['is_my_read']) $sort_score += 100;

    $dept_stmt = $pdo->prepare("SELECT dept_id FROM post_target_departments WHERE post_id = :pid");
    $dept_stmt->execute([':pid' => $p['post_id']]);
    $target_dept_ids = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

    $staff_stmt = $pdo->prepare("SELECT staff_id FROM post_target_staff WHERE post_id = :pid");
    $staff_stmt->execute([':pid' => $p['post_id']]);
    $target_staff_ids = $staff_stmt->fetchAll(PDO::FETCH_COLUMN);

    $all_active_staff = $pdo->query("SELECT staff_id, staff_name, dept_id FROM staff WHERE is_deleted = FALSE ORDER BY kana ASC")->fetchAll();
    
    $target_members = [];
    foreach ($all_active_staff as $st) {
        $is_target = false;
        if (empty($target_dept_ids) && empty($target_staff_ids)) {
            $is_target = true;
        } else {
            if (!empty($target_dept_ids) && in_array($st['dept_id'], $target_dept_ids)) $is_target = true;
            if (!empty($target_staff_ids) && in_array($st['staff_id'], $target_staff_ids)) $is_target = true;
        }
        if ($is_target) $target_members[] = $st;
    }

    $read_stmt = $pdo->prepare("SELECT staff_id FROM post_reads WHERE post_id = :pid");
    $read_stmt->execute([':pid' => $p['post_id']]);
    $read_staff_ids = $read_stmt->fetchAll(PDO::FETCH_COLUMN);

    $p['priority_level']  = $priority_level;
    $p['is_within_24h']   = $is_within_24h;
    $p['is_new_post']     = $is_new_post;
    $p['sort_score']      = $sort_score;
    $p['target_members']  = $target_members;
    $p['read_staff_ids']  = $read_staff_ids;
    $p['read_count']      = count(array_intersect(array_column($target_members, 'staff_id'), $read_staff_ids));
    $p['total_targets']   = count($target_members);

    $p['plain_summary']   = mb_substr(trim(strip_tags($p['content'])), 0, 150);

    $posts[] = $p;
}

usort($posts, function($a, $b) use ($date_filter) {
    if ($date_filter === 'past' || $date_filter === 'all_history') {
        // 過去の投稿一覧 / 全履歴では、対象日時または作成日時の新しい順（降順）で時系列表示
        $time_a = strtotime($a['target_datetime'] ?? $a['created_at']);
        $time_b = strtotime($b['target_datetime'] ?? $b['created_at']);
        if ($time_a === $time_b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        }
        return $time_b - $time_a;
    }
    if ($a['sort_score'] === $b['sort_score']) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    }
    return $b['sort_score'] - $a['sort_score'];
});
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>院内かわら版 | 医療法人小野会</title>
    <style>
        :root {
            --primary-color: #005a9c;
            --bg-color: #f4f6f9;
            --card-bg: #ffffff;
            --text-color: #333333;
            --border-color: #e0e0e0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-color); color: var(--text-color); line-height: 1.5; }

        header { background: var(--primary-color); color: #fff; padding: 0.8rem 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header-container { max-width: 950px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header-title h1 { font-size: 1.25rem; font-weight: bold; }
        .header-title span { font-size: 0.8rem; opacity: 0.9; margin-left: 6px; }

        .header-right { display: flex; align-items: center; gap: 12px; }
        .user-info { font-size: 0.82rem; background: rgba(255,255,255,0.18); padding: 4px 10px; border-radius: 4px; display: flex; align-items: center; gap: 6px; text-decoration: none; color: #fff; }
        .user-info:hover { background: rgba(255,255,255,0.3); }
        .btn-header { background: rgba(255,255,255,0.2); color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: bold; }

        main { max-width: 950px; margin: 1.2rem auto; padding: 0 1rem; }

        .alert-notice { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; padding: 10px 14px; border-radius: 6px; font-size: 0.88rem; font-weight: bold; margin-bottom: 1.2rem; }

        .filter-section { background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px; margin-bottom: 1.2rem; display: flex; flex-direction: column; gap: 10px; }
        .toolbar-group { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }

        .btn-create { background: #28a745; color: white; border: none; padding: 7px 16px; border-radius: 6px; font-weight: bold; font-size: 0.88rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); white-space: nowrap; }
        .btn-create:hover { background: #218838; }

        .btn-toggle-compact { background: #005a9c; color: white; border: 1px solid #004085; padding: 6px 14px; border-radius: 6px; font-size: 0.82rem; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .btn-toggle-compact:hover { background: #004085; }
        .btn-toggle-compact.is-active { background: #e67e22; border-color: #d35400; }

        .date-filter-group { display: flex; gap: 4px; background: #eef2f5; padding: 3px; border-radius: 6px; flex-wrap: wrap; align-items: center; }
        .btn-date { text-decoration: none; padding: 4px 10px; border-radius: 4px; font-size: 0.78rem; font-weight: bold; color: #495057; transition: all 0.15s; }
        .btn-date:hover { background: rgba(255,255,255,0.7); }
        .btn-date.active { background: var(--primary-color); color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        .cat-tabs { display: flex; gap: 4px; flex-wrap: wrap; padding-top: 6px; border-top: 1px dashed #eee; }
        .cat-tab { background: #f8f9fa; border: 1px solid #ced4da; padding: 2px 8px; border-radius: 12px; text-decoration: none; color: #555; font-size: 0.75rem; font-weight: bold; white-space: nowrap; transition: all 0.15s; }
        .cat-tab:hover { background: #eef6fc; border-color: var(--primary-color); }
        .cat-tab.active { background: #495057; color: #fff; border-color: #495057; }

        .badge { display: inline-flex; align-items: center; gap: 2px; padding: 2px 7px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; line-height: 1.2; }
        .badge-urgent { background-color: #dc3545; color: #ffffff; border: 1.5px solid #a71d2a; box-shadow: 0 0 6px rgba(220, 53, 69, 0.6); }
        .badge-24h { background-color: #fff9db; color: #856404; border: 1.5px solid #f1c40f; }
        .badge-unread { background-color: #f3e8ff; color: #6b21a8; border: 1.5px solid #9333ea; }
        .badge-read { background-color: #e0f2fe; color: #0369a1; border: 1.5px solid #0284c7; }
        .badge-new { background-color: #e74c3c; color: #ffffff; padding: 1px 5px; border-radius: 3px; font-size: 0.68rem; font-weight: bold; }
        .badge-pinned { background-color: #343a40; color: #ffffff; }

        .post-list { display: flex; flex-direction: column; gap: 1rem; }
        .post-card { background: var(--card-bg); border-radius: 8px; padding: 1.25rem; transition: all 0.15s; position: relative; border: 1px solid var(--border-color); }

        .post-card.p-urgent.is-unread { border: 3px solid #dc3545; animation: pulse-red 2.5s infinite; }
        .post-card.p-urgent.is-read { border: 2px solid #dc3545; background: #fff8f8; }
        .post-card.p-important.is-unread { border: 2px solid #fd7e14; border-left: 6px solid #fd7e14; background: #fff9f5; }
        .post-card.p-important.is-read { border: 1px solid #e0e0e0; border-left: 5px solid #fd7e14; background: #ffffff; }
        .post-card.p-normal.is-unread { border-left: 5px solid #005a9c; background: #ffffff; }
        .post-card.p-normal.is-read { border: 1px solid #e9ecef; background: #fdfdfd; opacity: 0.85; }
        .post-card.is-expired { opacity: 0.92; background: #fcfcfc; }
        .post-card.is-expired.p-urgent.is-unread { animation: none; }

        @keyframes pulse-red {
            0% { background-color: #ffffff; box-shadow: 0 0 0 rgba(220, 53, 69, 0); }
            50% { background-color: #fff0f1; box-shadow: 0 0 12px rgba(220, 53, 69, 0.4); }
            100% { background-color: #ffffff; box-shadow: 0 0 0 rgba(220, 53, 69, 0); }
        }

        .post-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .badge-cat { padding: 2px 8px; border-radius: 4px; color: #fff; font-size: 0.75rem; font-weight: bold; }

        .post-title { font-size: 1.15rem; font-weight: bold; color: #2c3e50; text-decoration: none; margin-bottom: 6px; display: block; }
        .post-title:hover { color: #005a9c; text-decoration: underline; }
        .post-title.text-urgent { color: #dc3545; }

        .post-meta { font-size: 0.82rem; color: #777; display: flex; gap: 15px; margin-bottom: 10px; flex-wrap: wrap; }
        .event-box { background: #eef6fc; border: 1px solid #b8daff; color: #004085; padding: 8px 12px; border-radius: 6px; font-size: 0.88rem; font-weight: bold; margin-bottom: 10px; }
        .event-box.urgent-box { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }

        .post-body-preview { font-size: 0.92rem; color: #444; line-height: 1.5; margin-bottom: 12px; }

        .read-action-bar {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            padding: 8px 12px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }

        .btn-unread-reset {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-unread-reset:hover { background: #5a6268; }

        .toggle-bar { display: flex; border-top: 1px solid #eee; border-bottom: 1px solid #eee; background: #fafafa; margin-top: 10px; }
        .toggle-btn { flex: 1; padding: 8px; border: none; background: none; font-size: 0.83rem; font-weight: bold; color: #555; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .toggle-btn:first-child { border-right: 1px solid #eee; }
        .toggle-btn:hover { background: #eef6fc; color: #005a9c; }
        .toggle-btn.active { background: #eef6fc; color: #005a9c; border-bottom: 2px solid #005a9c; }

        .accordion-content { display: none; background: #fdfdfd; padding: 12px; border-bottom: 1px solid #eee; font-size: 0.88rem; }
        .comment-item { border-bottom: 1px dashed #ddd; padding: 6px 0; display: flex; justify-content: space-between; }
        .stamp-badge { background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 12px; font-weight: bold; font-size: 0.8rem; }
        .stamp-select-btn { background: #fff; border: 1px solid #ccc; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.82rem; }

        .read-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 6px; margin-top: 8px; }
        .read-user-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.78rem; text-align: center; }
        .read-user-badge.is-read { background: #d4edda; color: #155724; }
        .read-user-badge.is-unread { background: #f8d7da; color: #721c24; }

        .post-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 0.8rem; color: #888; flex-wrap: wrap; gap: 5px; }
        .btn-print { background: #f8f9fa; border: 1px solid #ccc; color: #333; padding: 3px 10px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
        .btn-print:hover { background: #e2e6ea; }

        body.mode-compact .post-list { gap: 4px !important; }
        body.mode-compact .post-card { 
            padding: 6px 12px !important; 
            animation: none !important; 
            display: flex !important; 
            align-items: center !important; 
            justify-content: space-between !important; 
            gap: 10px !important; 
            border-radius: 4px !important; 
        }
        body.mode-compact .post-header { margin-bottom: 0 !important; }
        body.mode-compact .post-header > span { display: none !important; }
        body.mode-compact .post-title-wrapper { flex: 1; display: flex; align-items: center; gap: 8px; overflow: hidden; white-space: nowrap; }
        body.mode-compact .post-title { margin-bottom: 0 !important; font-size: 0.95rem !important; overflow: hidden; text-overflow: ellipsis; }
        body.mode-compact .post-author-tag { font-size: 0.75rem; color: #777; white-space: nowrap; }
        
        body.mode-compact .post-body-preview,
        body.mode-compact .post-meta,
        body.mode-compact .event-box,
        body.mode-compact .toggle-bar,
        body.mode-compact .accordion-content,
        body.mode-compact .post-footer,
        body.mode-compact .read-action-bar { display: none !important; }

        /* 📱 LINE連携ボタンスタイル */
        .btn-line-header {
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
            text-decoration: none;
        }
        .btn-line-header.is-linked {
            background: #e8f9ee;
            color: #06c755;
            border: 1px solid #b2e8c4;
        }
        .btn-line-header.is-linked:hover {
            background: #d4f4de;
        }
        .btn-line-header.is-unlinked {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            animation: pulse-line-btn 1.5s infinite alternate;
        }
        @keyframes pulse-line-btn {
            from { transform: scale(1); }
            to { transform: scale(1.04); }
        }

        /* 📱 LINE未登録アナウンスバナー */
        .line-register-banner {
            background: linear-gradient(135deg, #f0fdf4 0%, #e8f9ee 100%);
            border: 1.5px solid #86efac;
            border-left: 5px solid #06c755;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 2px 6px rgba(6, 199, 85, 0.08);
        }
        .line-banner-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .line-banner-icon {
            font-size: 1.6rem;
            line-height: 1;
        }
        .line-banner-title {
            font-size: 0.88rem;
            font-weight: 800;
            color: #065f46;
        }
        .line-banner-desc {
            font-size: 0.75rem;
            color: #475569;
            margin-top: 2px;
        }
        .btn-line-banner {
            background: #06c755;
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: bold;
            padding: 6px 14px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(6, 199, 85, 0.3);
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-line-banner:hover {
            background: #05b04a;
            transform: translateY(-1px);
        }

        /* 📱 LINE連携モーダル */
        .line-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .line-modal-overlay.active { display: flex; animation: fade-in 0.2s; }

        .line-modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 480px;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        .line-modal-header {
            background: #06c755;
            color: #ffffff;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .line-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .line-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
            opacity: 0.85;
        }
        .line-modal-close:hover { opacity: 1; }

        .line-modal-body {
            padding: 18px 20px;
            overflow-y: auto;
        }
        .line-step-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }
        .line-step-num {
            display: inline-block;
            background: #06c755;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
            margin-right: 5px;
        }
        .line-step-title {
            font-size: 0.88rem;
            font-weight: 800;
            color: #1e293b;
        }
        .link-code-digit {
            font-family: 'Outfit', monospace;
            font-size: 2.2rem;
            font-weight: 900;
            letter-spacing: 10px;
            color: #065f46;
            background: #ecfdf5;
            border: 2px dashed #06c755;
            border-radius: 8px;
            padding: 8px 14px;
            text-align: center;
            margin: 10px 0;
        }
        .btn-copy-code {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-copy-code:hover { background: #f1f5f9; color: #1e293b; }
        .line-waiting-box {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.8rem;
            color: #059669;
            background: #f0fdf4;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
            border: 1px solid #bbf7d0;
        }
        .line-spinner {
            width: 16px;
            height: 16px;
            border: 2.5px solid #86efac;
            border-top-color: #059669;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <header>
        <div class="header-container">
            <div class="header-title">
                <h1>📜 院内かわら版 <span>医療法人小野会</span></h1>
            </div>
            <div class="header-right">
                <a href="login.php" class="user-info" title="クリックしてユーザーを切り替え">
                    👤 <span style="font-weight:bold;"><?= htmlspecialchars($login_user['staff_name']) ?></span> (<?= htmlspecialchars($login_user['role']) ?>)
                    <span style="font-size:0.7rem; background:rgba(255,255,255,0.3); padding:1px 5px; border-radius:3px; margin-left:2px;">変更</span>
                </a>
                <button type="button" class="btn-line-header <?= $has_line_id ? 'is-linked' : 'is-unlinked' ?>" id="headerLineBtn" onclick="openLineLinkModal()" title="LINE連携設定">
                    <?= $has_line_id ? '🟢 LINE連携済' : '📱 LINE未登録' ?>
                </button>
                <div class="header-actions">
                    <a href="safety_contacts.php" class="btn-header" style="background:#28a745;">🛡️ 連絡網・安否</a>
                    <a href="/index.php" class="btn-header">ポータル</a>
                    <a href="help.php" class="btn-header" style="background:#17a2b8;" target="_blank">❓ 使い方</a>
                    <?php if ($is_admin): ?><a href="master_mente.php" class="btn-header" style="background:#e67e22;">⚙️ メンテ</a><?php endif; ?>
                    <a href="login.php" class="btn-header" style="background:#e74c3c;">切替</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <?php if ($notice_msg): ?>
            <div class="alert-notice"><?= htmlspecialchars($notice_msg) ?></div>
        <?php endif; ?>

        <?php if (!$has_line_id): ?>
            <div class="line-register-banner" id="lineNoticeBanner">
                <div class="line-banner-left">
                    <span class="line-banner-icon">📱</span>
                    <div>
                        <div class="line-banner-title">【BCP安否確認・重要連絡】LINEが未登録です</div>
                        <div class="line-banner-desc">有事の生存点呼や緊急アナウンスをスマホで受け取れるよう、公式LINEとの連携をお願いします。</div>
                    </div>
                </div>
                <button type="button" class="btn-line-banner" onclick="openLineLinkModal()">
                    👉 LINE連携コードを発行する（約30秒）
                </button>
            </div>
        <?php endif; ?>

        <?php if (!$my_safety_reported_today): ?>
            <div style="background:#fff8ee; border:1px solid #fde68a; border-left:5px solid #e67e22; padding:8px 14px; border-radius:6px; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span style="font-size:0.85rem; color:#b45309; font-weight:bold;">
                    🛡️ 本日の生存チェック（BCP安否確認）がまだ報告されていません
                </span>
                <a href="safety_contacts.php" style="background:#28a745; color:#fff; font-size:0.78rem; font-weight:bold; padding:4px 10px; border-radius:4px; text-decoration:none;">
                    1クリックで報告する →
                </a>
            </div>
        <?php endif; ?>

        <div class="filter-section">
            <div class="toolbar-group">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <a href="create_post.php" class="btn-create">✏️ お知らせを新規投稿</a>
                    
                    <button type="button" id="compactToggleBtn" class="btn-toggle-compact" onclick="toggleCompactMode()">
                        📄 1行コンパクト表示
                    </button>

                    <button type="button" class="btn-toggle-compact" onclick="window.print()" style="background:#6c757d; border-color:#5a6268;">
                        🖨️ 一覧印刷
                    </button>

                    <?php
                    $build_url = function($df, $cat) {
                        $p = [];
                        if ($df !== 'all') $p['date_filter'] = $df;
                        if ($cat > 0) $p['cat'] = $cat;
                        return 'index.php' . (!empty($p) ? '?' . http_build_query($p) : '');
                    };
                    ?>

                    <?php if ($date_filter === 'past'): ?>
                        <a href="<?= $build_url('all', $selected_cat) ?>" class="btn-toggle-compact" style="background:#495057; border-color:#343a40; text-decoration:none;">
                            🔙 通常一覧に戻る
                        </a>
                    <?php else: ?>
                        <a href="<?= $build_url('past', $selected_cat) ?>" class="btn-toggle-compact" style="background:#5c636a; border-color:#4e555b; text-decoration:none;">
                            📁 過去の投稿を見る
                        </a>
                    <?php endif; ?>
                </div>

                <div class="date-filter-group">
                    <a href="<?= $build_url('all', $selected_cat) ?>" class="btn-date <?= $date_filter === 'all' ? 'active' : '' ?>">全期間</a>
                    <a href="<?= $build_url('today', $selected_cat) ?>" class="btn-date <?= $date_filter === 'today' ? 'active' : '' ?>">今日</a>
                    <a href="<?= $build_url('tomorrow', $selected_cat) ?>" class="btn-date <?= $date_filter === 'tomorrow' ? 'active' : '' ?>">明日</a>
                    <a href="<?= $build_url('plus7', $selected_cat) ?>" class="btn-date <?= $date_filter === 'plus7' ? 'active' : '' ?>">+7日</a>
                    <a href="<?= $build_url('this_month', $selected_cat) ?>" class="btn-date <?= $date_filter === 'this_month' ? 'active' : '' ?>">今月</a>
                    <a href="<?= $build_url('next_month', $selected_cat) ?>" class="btn-date <?= $date_filter === 'next_month' ? 'active' : '' ?>">来月</a>
                    <span style="color:#ced4da; margin:0 2px;">|</span>
                    <a href="<?= $build_url('past', $selected_cat) ?>" class="btn-date <?= $date_filter === 'past' ? 'active' : '' ?>" style="<?= $date_filter === 'past' ? 'background:#5c636a; color:#fff;' : '' ?>">📁 過去の投稿</a>
                    <a href="<?= $build_url('all_history', $selected_cat) ?>" class="btn-date <?= $date_filter === 'all_history' ? 'active' : '' ?>" style="<?= $date_filter === 'all_history' ? 'background:#17a2b8; color:#fff;' : '' ?>">🌐 全履歴(過去含む)</a>
                </div>
            </div>

            <div class="cat-tabs">
                <a href="<?= $build_url($date_filter, 0) ?>" class="cat-tab <?= $selected_cat === 0 ? 'active' : '' ?>">全て</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="<?= $build_url($date_filter, $cat['category_id']) ?>" class="cat-tab <?= $selected_cat == $cat['category_id'] ? 'active' : '' ?>">
                        <?= $cat['icon_emoji'] ?> <?= htmlspecialchars($cat['category_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($date_filter === 'past'): ?>
            <div style="background:#f1f3f5; border:1px solid #ced4da; border-left:5px solid #6c757d; padding:10px 14px; border-radius:6px; margin-bottom:1.2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <strong style="color:#495057; font-size:0.95rem;">📁 過去の投稿（掲載終了・過去分）を表示しています</strong>
                    <span style="font-size:0.82rem; color:#666; margin-left:8px;">（合計 <?= count($posts) ?> 件）</span>
                </div>
                <a href="<?= $build_url('all', $selected_cat) ?>" style="font-size:0.82rem; color:#005a9c; text-decoration:none; font-weight:bold; background:#fff; border:1px solid #005a9c; padding:4px 10px; border-radius:4px;">🔙 通常表示（掲載中のみ）に戻る</a>
            </div>
        <?php elseif ($date_filter === 'all_history'): ?>
            <div style="background:#eef6fc; border:1px solid #b8daff; border-left:5px solid #005a9c; padding:10px 14px; border-radius:6px; margin-bottom:1.2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <strong style="color:#004085; font-size:0.95rem;">🌐 全履歴（過去を含むすべての投稿）を表示しています</strong>
                    <span style="font-size:0.82rem; color:#666; margin-left:8px;">（合計 <?= count($posts) ?> 件）</span>
                </div>
                <a href="<?= $build_url('all', $selected_cat) ?>" style="font-size:0.82rem; color:#005a9c; text-decoration:none; font-weight:bold; background:#fff; border:1px solid #005a9c; padding:4px 10px; border-radius:4px;">🔙 通常表示（掲載中のみ）に戻る</a>
            </div>
        <?php endif; ?>

        <div class="post-list">
            <?php if (empty($posts)): ?>
                <div style="text-align:center; padding: 3rem; background:#fff; border-radius:8px; color:#888;">
                    <?= $date_filter === 'past' ? '過去の投稿はありません。' : '該当するお知らせや予定はありません。' ?>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $p): 
                    $p_lvl = $p['priority_level'];
                    $is_read = (bool)$p['is_my_read'];
                    $is_expired = !empty($p['display_until']) && strtotime($p['display_until']) < time();
                    $is_past_event = empty($p['display_until']) && !empty($p['target_datetime']) && strtotime($p['target_datetime']) < strtotime($today_str);
                    $expired_class = ($is_expired || $is_past_event) ? 'is-expired' : '';

                    $card_class = "p-{$p_lvl} " . ($is_read ? 'is-read' : 'is-unread') . " " . $expired_class;
                    $can_edit = ($is_admin || $p['author_id'] == $current_staff_id);
                    
                    $read_cnt   = $p['read_count'];
                    $total_cnt  = $p['total_targets'];
                    $unread_cnt = max(0, $total_cnt - $read_cnt);
                ?>
                    <div class="post-card <?= $card_class ?>" 
                         data-is-read="<?= $is_read ? '1' : '0' ?>"
                         data-priority="<?= $p_lvl ?>"
                         data-within-24h="<?= $p['is_within_24h'] ? '1' : '0' ?>">
                        
                        <div class="post-header">
                            <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                <?php if ($is_expired): ?>
                                    <span class="badge" style="background:#6c757d; color:#ffffff;">⏱️ 掲載終了</span>
                                <?php elseif ($is_past_event): ?>
                                    <span class="badge" style="background:#6c757d; color:#ffffff;">📜 過去の予定</span>
                                <?php endif; ?>

                                <?php if ($p_lvl === 'urgent' && !$is_expired && !$is_past_event): ?>
                                    <span class="badge badge-urgent">🚨 直近/緊急</span>
                                <?php elseif ($p['is_within_24h'] && !$is_expired && !$is_past_event): ?>
                                    <span class="badge badge-24h">⏰ 24時間以内</span>
                                <?php endif; ?>

                                <?php if (!$is_read): ?>
                                    <span class="badge badge-unread">🟣 未読</span>
                                <?php else: ?>
                                    <span class="badge badge-read">🩵 既読</span>
                                <?php endif; ?>

                                <?php if ($p['is_new_post']): ?>
                                    <span class="badge-new">NEW</span>
                                <?php endif; ?>

                                <?php if ($p['is_pinned']): ?><span class="badge badge-pinned">📌 固定</span><?php endif; ?>
                                
                                <span class="badge-cat" style="background-color: <?= $p['color_code'] ?? '#005a9c' ?>;">
                                    <?= $p['icon_emoji'] ?> <?= htmlspecialchars($p['category_name'] ?? '一般') ?>
                                </span>
                            </div>
                            <span style="font-size: 0.78rem; color: #999;">投稿: <?= date('Y/m/d H:i', strtotime($p['created_at'])) ?></span>
                        </div>

                        <div class="post-title-wrapper">
                            <a href="view_post.php?id=<?= $p['post_id'] ?>" 
                               class="post-title <?= $p_lvl === 'urgent' ? 'text-urgent' : '' ?>"
                               title="<?= htmlspecialchars($p['plain_summary']) ?>">
                                <?= htmlspecialchars($p['title']) ?>
                            </a>
                            <span class="post-author-tag">(<?= htmlspecialchars($p['author_dept'] ?? '事務部') ?>)</span>
                        </div>

                        <div class="post-meta">
                            <span>👤 投稿者: <?= htmlspecialchars($p['author_name'] ?? '事務部') ?> (<?= htmlspecialchars($p['author_dept']) ?>)</span>
                            <?php if ($p['image_count'] > 0): ?><span>🖼 画像: <?= $p['image_count'] ?>枚</span><?php endif; ?>
                            <!-- 🆕 掲載期限を追加 -->
                            <span>掲載期限: <?= empty($p['display_until']) ? '♾️ 無期限' : date('Y/m/d 23:59', strtotime($p['display_until'])) ?></span>
                        </div>

                        <?php if ($p['formatted_event_date']): ?>
                            <div class="event-box <?= $p_lvl === 'urgent' ? 'urgent-box' : '' ?>">
                                🗓 実施・対象日時: <?= $p['formatted_event_date'] ?>
                            </div>
                        <?php endif; ?>

                        <div class="post-body-preview">
                            <?= $p['content'] ?>
                        </div>

                        <?php if (!$is_read): ?>
                            <form method="POST" class="read-action-bar">
                                <input type="hidden" name="action_type" value="mark_read">
                                <input type="hidden" name="post_id" value="<?= $p['post_id'] ?>">
                                
                                <button type="submit" style="background:#28a745; color:white; border:none; padding:7px 16px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.88rem; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                                     内容を確認しました（既読を付ける）
                                </button>

                                <label style="font-size:0.82rem; color:#0f5132; font-weight:bold; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                    <input type="checkbox" name="send_self_line" value="1" <?= $has_line_id ? 'checked' : '' ?> onclick="checkLineIdStatus(event, <?= $has_line_id ? 'true' : 'false' ?>)" style="accent-color:#198754; width:16px; height:16px;">
                                    <span>📲 自分のLINEにも内容をメモとして送信する</span>
                                </label>
                            </form>
                        <?php else: ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; background:#e0f2fe; padding:6px 12px; border-radius:6px; margin-bottom:10px; flex-wrap:wrap; gap:5px;">
                                <span style="font-size:0.82rem; color:#0369a1; font-weight:bold;">🩵 このお知らせは確認済み（既読）です</span>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action_type" value="mark_unread">
                                    <input type="hidden" name="post_id" value="<?= $p['post_id'] ?>">
                                    <button type="submit" class="btn-unread-reset">↩️ 未読に戻す</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="toggle-bar">
                            <button type="button" class="toggle-btn" onclick="toggleAccordion('comments_<?= $p['post_id'] ?>', this)">
                                💬 コメント・スタンプ (<?= $p['comment_count'] ?>件) ▼
                            </button>
                            <button type="button" class="toggle-btn" onclick="toggleAccordion('reads_<?= $p['post_id'] ?>', this)">
                                👀 既読 <?= $read_cnt ?> / 未読 <?= $unread_cnt ?>名 (対象<?= $total_cnt ?>名) ▼
                            </button>
                        </div>

                        <div id="comments_<?= $p['post_id'] ?>" class="accordion-content">
                            <?php
                            $stmt_cm = $pdo->prepare("SELECT cm.*, s.staff_name FROM post_comments cm LEFT JOIN staff s ON cm.author_id = s.staff_id WHERE cm.post_id = :pid ORDER BY cm.created_at ASC");
                            $stmt_cm->execute([':pid' => $p['post_id']]);
                            $comments = $stmt_cm->fetchAll();
                            ?>

                            <?php if (!empty($comments)): ?>
                                <div style="margin-bottom:10px;">
                                    <?php foreach ($comments as $cm): ?>
                                        <div class="comment-item">
                                            <div>
                                                <b><?= htmlspecialchars($cm['staff_name']) ?>:</b> 
                                                <?= htmlspecialchars($cm['comment_text']) ?>
                                                <?php if ($cm['stamp_code']): ?>
                                                    <span class="stamp-badge"><?= htmlspecialchars($cm['stamp_code']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span style="font-size:0.75rem; color:#999;"><?= date('m/d H:i', strtotime($cm['created_at'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="color:#888; margin-bottom:10px;">コメントやスタンプはまだありません。</p>
                            <?php endif; ?>

                            <form method="POST" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                <input type="hidden" name="action_type" value="add_comment">
                                <input type="hidden" name="post_id" value="<?= $p['post_id'] ?>">
                                
                                <input type="text" name="comment_text" placeholder="一言コメントを入力..." style="flex:1; padding:6px; border:1px solid #ccc; border-radius:4px; font-size:0.85rem;">
                                
                                <button type="submit" name="stamp_code" value="👍 了解です" class="stamp-select-btn">👍 了解</button>
                                <button type="submit" name="stamp_code" value="🙏 感謝" class="stamp-select-btn">🙏 感謝</button>
                                <button type="submit" name="stamp_code" value="👌 確認済" class="stamp-select-btn">👌 確認済</button>

                                <button type="submit" style="background:#005a9c; color:white; border:none; padding:6px 12px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.82rem;">送信</button>
                            </form>
                        </div>

                        <div id="reads_<?= $p['post_id'] ?>" class="accordion-content">
                            <div style="font-size:0.8rem; color:#666; margin-bottom:5px;">対象スタッフの確認状況（グリーン: 既読 / ピンク: 未読）:</div>
                            <div class="read-grid">
                                <?php foreach ($p['target_members'] as $st): 
                                    $is_st_read = in_array($st['staff_id'], $p['read_staff_ids']);
                                ?>
                                    <div class="read-user-badge <?= $is_st_read ? 'is-read' : 'is-unread' ?>">
                                        <?= htmlspecialchars($st['staff_name']) ?> <?= $is_st_read ? '✓' : '' ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="post-footer">
                            <div>
                                <a href="print_post.php?id=<?= $p['post_id'] ?>" target="_blank" class="btn-print">🖨️ 単体印刷</a>
                                <span style="margin-left: 10px;">掲載期限: <?= empty($p['display_until']) ? '♾️ 無期限' : date('Y/m/d 23:59', strtotime($p['display_until'])) ?></span>
                            </div>
                            
                            <div>
                                <a href="view_post.php?id=<?= $p['post_id'] ?>" style="color:#005a9c; text-decoration:none; font-weight:bold; margin-right:12px;">詳細をみる →</a>
                                <?php if ($can_edit): ?>
                                    <a href="create_post.php?id=<?= $p['post_id'] ?>" style="color:#e67e22; text-decoration:none; font-weight:bold;">✏️ 編集</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

<!-- 📱 LINE公式アカウント連携モーダル -->
<div id="lineLinkModal" class="line-modal-overlay" onclick="if(event.target===this) closeLineLinkModal()">
    <div class="line-modal-card">
        <div class="line-modal-header">
            <h3>📱 公式LINE 連携設定</h3>
            <button type="button" class="line-modal-close" onclick="closeLineLinkModal()">&times;</button>
        </div>
        <div class="line-modal-body">
            <!-- ユーザー表示 -->
            <div style="display:flex; justify-content:space-between; align-items:center; background:#f1f5f9; padding:8px 12px; border-radius:8px; margin-bottom:14px;">
                <div style="font-size:0.85rem; font-weight:bold; color:#1e293b;">
                    👤 <?= htmlspecialchars($login_user['staff_name']) ?> 様 (<?= htmlspecialchars($login_user['role']) ?>)
                </div>
                <div id="modalLineBadge" style="font-size:0.75rem; font-weight:bold; padding:2px 8px; border-radius:12px;">
                    確認中...
                </div>
            </div>

            <!-- 状態 A: 未連携の場合（3ステップ連携フロー） -->
            <div id="modalUnlinkedView">
                <div class="line-step-box">
                    <div style="display:flex; align-items:center; margin-bottom:8px;">
                        <span class="line-step-num">Step 1</span>
                        <span class="line-step-title">公式LINEを友だち追加</span>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <div style="text-align:center;">
                            <img id="lineQrImg" src="https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=https%3A%2F%2Fline.me%2FR%2Fti%2Fp%2F%40tmw3446q" alt="LINE友だち追加QR" style="width:100px; height:100px; border:1px solid #cbd5e1; border-radius:6px; padding:3px; background:#fff;">
                            <div style="font-size:0.65rem; color:#64748b; margin-top:2px;">QRコードをスキャン</div>
                        </div>
                        <div style="flex:1; min-width:180px;">
                            <div style="font-size:0.8rem; color:#334155; margin-bottom:6px;">
                                スマホのLINEカメラでQRを読み取るか、以下から友だち追加してください。
                            </div>
                            <a id="btnLineAddFriend" href="https://line.me/R/ti/p/@tmw3446q" target="_blank" style="display:inline-flex; align-items:center; gap:6px; background:#06c755; color:#fff; padding:6px 12px; border-radius:6px; font-size:0.78rem; font-weight:bold; text-decoration:none;">
                                💬 LINEで友だち追加を開く
                            </a>
                            <div style="font-size:0.7rem; color:#64748b; margin-top:4px;">
                                ID検索: <span id="modalBotId" style="font-weight:bold; color:#0f172a;">@tmw3446q</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="line-step-box">
                    <div style="display:flex; align-items:center; margin-bottom:6px;">
                        <span class="line-step-num">Step 2</span>
                        <span class="line-step-title">トーク画面でこのコードを送信</span>
                    </div>
                    <div style="font-size:0.78rem; color:#475569;">
                        公式アカウントのトーク画面に、下記の【数字4桁】をそのまま送信してください：
                    </div>
                    <div class="link-code-digit" id="lineLinkCode">
                        ----
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <button type="button" class="btn-copy-code" onclick="copyLinkCode()">📋 コードをコピー</button>
                        <div style="font-size:0.74rem; color:#64748b;">
                            有効期限: <span id="lineCodeCountdown" style="font-weight:bold; color:#d97706;">--:--</span>
                            <button type="button" onclick="generateNewLinkCode()" style="background:none; border:none; color:#0284c7; cursor:pointer; font-size:0.72rem; text-decoration:underline; margin-left:4px;">再発行</button>
                        </div>
                    </div>
                </div>

                <div class="line-waiting-box">
                    <div class="line-spinner"></div>
                    <div>
                        <strong>Step 3: トークからの送信を待機しています...</strong>
                        <div style="font-size:0.72rem; color:#065f46; margin-top:2px;">コードが送信されると、約2〜3秒で自動的に連携完了へ切り替わります。</div>
                    </div>
                </div>
            </div>

            <!-- 状態 B: 連携済みの場合（完了画面） -->
            <div id="modalLinkedView" style="display:none; text-align:center; padding:10px 0;">
                <div style="font-size:3rem; margin-bottom:8px;">🎉</div>
                <h4 style="margin:0 0 6px 0; color:#065f46; font-size:1.15rem;">LINE連携が完了しています</h4>
                <p style="font-size:0.82rem; color:#475569; margin:0 0 16px 0;">
                    有事のBCP安否確認や緊急アナウンスがあなたのLINEへ届きます。<br>
                    登録ID: <span id="modalMaskedId" style="font-family:monospace; background:#e2e8f0; padding:2px 6px; border-radius:4px; font-weight:bold;"></span>
                </p>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <button type="button" onclick="closeLineLinkModal()" style="background:#06c755; color:#fff; border:none; padding:8px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">
                        閉じる
                    </button>
                    <button type="button" onclick="unlinkMyLine()" style="background:#fff; color:#dc2626; border:1px solid #fca5a5; padding:8px 14px; border-radius:6px; font-size:0.78rem; font-weight:bold; cursor:pointer;">
                        連携を解除
                    </button>
                </div>
            </div>

            <!-- アコーディオン：テスト・手動入力用（Webhook未開通環境でも確実にテスト可能） -->
            <div style="margin-top:14px; border-top:1px dashed #cbd5e1; padding-top:10px;">
                <details style="font-size:0.76rem; color:#64748b;">
                    <summary style="cursor:pointer; font-weight:bold; color:#475569;">🛠️ 手動登録 / 開発テスト用連携メニュー</summary>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-top:6px; border:1px solid #e2e8f0;">
                        <p style="margin:0 0 6px 0;">直接LINE User ID（U...）を入力して登録するか、テスト用の模擬IDで即座に連携をテストできます：</p>
                        <div style="display:flex; gap:6px; margin-bottom:6px;">
                            <input type="text" id="manualLineUserId" placeholder="例: U1234567890abcdef..." style="flex:1; padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.75rem; font-family:monospace;">
                            <button type="button" onclick="manualSaveLineId()" style="background:#0284c7; color:#fff; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">保存</button>
                        </div>
                        <button type="button" onclick="simulateTestLink()" style="background:#e2e8f0; color:#1e293b; border:1px solid #cbd5e1; padding:3px 8px; border-radius:4px; font-size:0.72rem; cursor:pointer;">
                            ⚡ 模擬LINE IDで即時テスト連携
                        </button>
                    </div>
                </details>
            </div>
        </div>
    </div>
</div>

<script>
function checkLineIdStatus(e, hasLine) {
    if (e.target.checked && !hasLine) {
        if (confirm("LINE IDがまだ登録されていません。\n今すぐLINE連携を設定しますか？")) {
            openLineLinkModal();
        }
        e.target.checked = false;
    }
}

let isCompact = (localStorage.getItem('kawara_is_compact') === '1');

function applyCompactMode() {
    const btn = document.getElementById('compactToggleBtn');
    if (isCompact) {
        document.body.classList.add('mode-compact');
        btn.classList.add('is-active');
        btn.innerHTML = '🖼️ 通常表示に戻す';
    } else {
        document.body.classList.remove('mode-compact');
        btn.classList.remove('is-active');
        btn.innerHTML = '📄 1行コンパクト表示';
    }
}

function toggleCompactMode() {
    isCompact = !isCompact;
    localStorage.setItem('kawara_is_compact', isCompact ? '1' : '0');
    applyCompactMode();
}

document.addEventListener('DOMContentLoaded', applyCompactMode);

function toggleAccordion(targetId, btn) {
    const target = document.getElementById(targetId);
    const isVisible = (target.style.display === 'block');

    const card = btn.closest('.post-card');
    card.querySelectorAll('.accordion-content').forEach(el => el.style.display = 'none');
    card.querySelectorAll('.toggle-btn').forEach(el => el.classList.remove('active'));

    if (!isVisible) {
        target.style.display = 'block';
        btn.classList.add('active');
    }
}

// ==========================================
// 📱 LINE連携モーダル制御 & 自動ポーリングJS
// ==========================================
let linePollingTimer = null;
let lineCountdownTimer = null;
let lineRemainingSeconds = 0;

async function openLineLinkModal() {
    document.getElementById('lineLinkModal').classList.add('active');
    await refreshLineStatus();
}

function closeLineLinkModal() {
    document.getElementById('lineLinkModal').classList.remove('active');
    stopLinePolling();
    stopLineCountdown();
}

async function refreshLineStatus() {
    try {
        const res = await fetch('api/line_link_status.php?action=status', { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.success) return;

        updateModalUI(data);

        if (!data.is_linked) {
            if (!data.active_code || data.remaining_sec <= 30) {
                await generateNewLinkCode();
            } else {
                displayLinkCode(data.active_code, data.remaining_sec);
                startLinePolling();
            }
        } else {
            stopLinePolling();
            stopLineCountdown();
        }
    } catch (e) {
        console.error(e);
    }
}

function updateModalUI(data) {
    const badge = document.getElementById('modalLineBadge');
    const unlinkedView = document.getElementById('modalUnlinkedView');
    const linkedView = document.getElementById('modalLinkedView');
    const maskedIdEl = document.getElementById('modalMaskedId');
    const botIdEl = document.getElementById('modalBotId');
    const btnFriend = document.getElementById('btnLineAddFriend');
    const qrImg = document.getElementById('lineQrImg');
    const headerBtn = document.getElementById('headerLineBtn');
    const topBanner = document.getElementById('lineNoticeBanner');

    if (data.bot_basic_id) {
        botIdEl.textContent = data.bot_basic_id;
        btnFriend.href = data.bot_add_url;
        qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=' + encodeURIComponent(data.bot_add_url);
    }

    if (data.is_linked) {
        badge.textContent = '🟢 連携中';
        badge.style.background = '#dcfce7';
        badge.style.color = '#15803d';
        unlinkedView.style.display = 'none';
        linkedView.style.display = 'block';
        maskedIdEl.textContent = data.line_user_id_mask;
        if (headerBtn) {
            headerBtn.className = 'btn-line-header is-linked';
            headerBtn.textContent = '🟢 LINE連携済';
        }
        if (topBanner) topBanner.style.display = 'none';
    } else {
        badge.textContent = '⚠️ 未連携';
        badge.style.background = '#fef3c7';
        badge.style.color = '#b45309';
        unlinkedView.style.display = 'block';
        linkedView.style.display = 'none';
        if (headerBtn) {
            headerBtn.className = 'btn-line-header is-unlinked';
            headerBtn.textContent = '📱 LINE未登録';
        }
    }
}

async function generateNewLinkCode() {
    try {
        const res = await fetch('api/line_link_status.php?action=generate_code', { method: 'POST', cache: 'no-store' });
        const data = await res.json();
        if (data.success) {
            displayLinkCode(data.link_code, data.remaining_sec);
            startLinePolling();
        }
    } catch (e) {
        console.error(e);
    }
}

function displayLinkCode(code, sec) {
    document.getElementById('lineLinkCode').textContent = code;
    lineRemainingSeconds = sec;
    startLineCountdown();
}

function startLineCountdown() {
    stopLineCountdown();
    updateCountdownText();
    lineCountdownTimer = setInterval(() => {
        lineRemainingSeconds--;
        if (lineRemainingSeconds <= 0) {
            stopLineCountdown();
            generateNewLinkCode();
        } else {
            updateCountdownText();
        }
    }, 1000);
}

function updateCountdownText() {
    const m = Math.floor(lineRemainingSeconds / 60);
    const s = lineRemainingSeconds % 60;
    const txt = `${m}:${s < 10 ? '0' : ''}${s}`;
    const el = document.getElementById('lineCodeCountdown');
    if (el) el.textContent = txt;
}

function stopLineCountdown() {
    if (lineCountdownTimer) clearInterval(lineCountdownTimer);
    lineCountdownTimer = null;
}

function startLinePolling() {
    stopLinePolling();
    linePollingTimer = setInterval(async () => {
        try {
            const res = await fetch('api/line_link_status.php?action=status', { cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();
            if (data.success && data.is_linked) {
                stopLinePolling();
                stopLineCountdown();
                updateModalUI(data);
            }
        } catch (e) {}
    }, 3000);
}

function stopLinePolling() {
    if (linePollingTimer) clearInterval(linePollingTimer);
    linePollingTimer = null;
}

function copyLinkCode() {
    const code = document.getElementById('lineLinkCode').textContent.trim();
    if (!code || code === '----') return;
    navigator.clipboard.writeText(code).then(() => {
        alert('連携コード【' + code + '】をコピーしました！LINEのトーク画面に貼り付けて送信してください。');
    }).catch(() => {
        alert('コード：' + code);
    });
}

async function unlinkMyLine() {
    if (!confirm('LINE連携を解除しますか？\n（解除すると緊急安否確認やお知らせが届かなくなります）')) return;
    try {
        const res = await fetch('api/line_link_status.php?action=unlink', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            alert('LINE連携を解除しました。');
            refreshLineStatus();
        }
    } catch (e) {
        alert('解除に失敗しました。');
    }
}

async function manualSaveLineId() {
    const val = document.getElementById('manualLineUserId').value.trim();
    if (!val) {
        alert('LINE IDを入力してください。');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'manual_link');
    fd.append('line_user_id', val);
    const res = await fetch('api/line_link_status.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        alert('LINE IDを登録しました！');
        refreshLineStatus();
    }
}

async function simulateTestLink() {
    const fd = new FormData();
    fd.append('action', 'manual_link');
    const res = await fetch('api/line_link_status.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        alert('テスト用LINE IDで連携しました！');
        refreshLineStatus();
    }
}
</script>

</body>
</html>