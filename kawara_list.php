<?php
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/db.php';

if (file_exists(__DIR__ . '/includes/line_helper.php')) {
    require_once __DIR__ . '/includes/line_helper.php';
}

// 🌟 カレンダー・医師予定ヘルパー読み込み
require_once __DIR__ . '/includes/calendar_helper_jimucho.php';
require_once __DIR__ . '/includes/traditional_calendar_helper.php';
require_once __DIR__ . '/includes/google_calendar_helper.php';
require_once __DIR__ . '/includes/doctor_schedule_helper.php';

// 📱 端末固定Cookieがあれば自動ログイン！なければlogin.phpへ
$login_user = checkAuthOrAutoLogin($pdo, $_SERVER['REQUEST_URI'] ?? '');
$current_staff_id = (int)$login_user['staff_id'];
$is_admin = (bool)($login_user['is_admin'] ?? false);
$is_jimucho = ($current_staff_id === 15 || mb_strpos($login_user['staff_name'] ?? '', '山本') !== false || mb_strpos($login_user['role'] ?? '', '事務') !== false);
$can_see_jimucho = ($is_admin || $is_jimucho);
$has_line_id = !empty(trim($login_user['line_user_id'] ?? ''));

// 🔄 Googleカレンダー手動同期Ajax
if (isset($_GET['action']) && $_GET['action'] === 'sync_gcal') {
    header('Content-Type: application/json; charset=utf-8');
    $res = sync_all_google_calendars($pdo, true);
    echo json_encode($res);
    exit;
}

// 🌟 ダッシュボードメモ（月・日）Ajax保存・削除（一般ユーザーも利用可能）
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'save_dashboard_note') {
    header('Content-Type: application/json; charset=utf-8');
    $type    = trim($_REQUEST['target_type'] ?? '');
    $key     = trim($_REQUEST['target_key'] ?? '');
    $content = trim($_REQUEST['content'] ?? '');

    if (!in_array($type, ['month', 'date']) || $key === '') {
        echo json_encode(['success' => false, 'error' => 'パラメータが不正です']);
        exit;
    }

    if ($content === '') {
        $stmt_del = $pdo->prepare("DELETE FROM dashboard_notes WHERE target_type = :type AND target_key = :key");
        $stmt_del->execute([':type' => $type, ':key' => $key]);
        echo json_encode(['success' => true, 'action' => 'deleted', 'type' => $type, 'key' => $key]);
        exit;
    } else {
        $stmt_upsert = $pdo->prepare("
            INSERT INTO dashboard_notes (target_type, target_key, content, updated_at)
            VALUES (:type, :key, :content, NOW())
            ON CONFLICT (target_type, target_key) DO UPDATE SET
                content = EXCLUDED.content,
                updated_at = NOW()
        ");
        $stmt_upsert->execute([':type' => $type, ':key' => $key, ':content' => $content]);
        echo json_encode(['success' => true, 'action' => 'saved', 'type' => $type, 'key' => $key, 'content' => $content]);
        exit;
    }
}

// 本日の生存確認・安否報告チェック ＆ BCPモード判定
$active_safety_event = $pdo->query("SELECT * FROM safety_events WHERE is_active = TRUE ORDER BY event_id DESC LIMIT 1")->fetch();
$safety_mode = $active_safety_event['safety_mode'] ?? (!empty($active_safety_event['is_disaster_mode']) ? 'disaster' : 'normal');

$my_safety_reported_today = false;
if ($safety_mode !== 'normal') {
    $today_start = date('Y-m-d 00:00:00');
    $stmt_safety = $pdo->prepare("SELECT COUNT(*) FROM safety_checks WHERE staff_id = :id AND reported_at >= :today");
    $stmt_safety->execute([':id' => $current_staff_id, ':today' => $today_start]);
    $my_safety_reported_today = ($stmt_safety->fetchColumn() > 0);
}

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

    header("Location: kawara_list.php?" . http_build_query($_GET));
    exit;
}

$notice_msg = $_SESSION['notice_msg'] ?? '';
unset($_SESSION['notice_msg']);

// 4. カテゴリーリスト＆パラメータ取得
$selected_cat  = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$date_filter   = $_GET['date_filter'] ?? 'all';
$search_query  = trim($_GET['q'] ?? '');
$current_page  = max(1, (int)($_GET['page'] ?? 1));
$per_page      = isset($_GET['limit']) ? max(5, min(100, (int)$_GET['limit'])) : 25;

// 表示モード（デフォルトは一覧 'list'、2週間カードは '2weeks'）
$view_mode = $_GET['mode'] ?? 'list';
if (!in_array($view_mode, ['list', '2weeks'])) {
    $view_mode = 'list';
}

$today_str = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today_str;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = $today_str;
}

// 🩺 医師予定表の申し送りメモ（画面上部で常に確認可能）
$doctor_memo = fetch_doctor_memo();

$categories = $pdo->query("SELECT * FROM post_categories WHERE is_active = TRUE ORDER BY display_order")->fetchAll();

// URL生成ヘルパー（現在の絞り込み条件・キーワード・モード・日付を保持）
$build_url = function($df = null, $cat = null, $page = 1, $q = null, $mode = null, $date = null) use ($date_filter, $selected_cat, $search_query, $per_page, $view_mode, $selected_date) {
    $p = [];
    $use_mode = ($mode !== null) ? $mode : $view_mode;
    if ($use_mode !== 'list') {
        $p['mode'] = $use_mode;
        $use_date = ($date !== null) ? $date : $selected_date;
        if ($use_date && $use_date !== date('Y-m-d')) {
            $p['date'] = $use_date;
        }
    }

    $use_df   = ($df !== null) ? $df : $date_filter;
    $use_cat  = ($cat !== null) ? (int)$cat : $selected_cat;
    $use_page = ($page !== null) ? (int)$page : 1;
    $use_q    = ($q !== null) ? trim($q) : $search_query;

    if ($use_df !== 'all') $p['date_filter'] = $use_df;
    if ($use_cat > 0) $p['cat'] = $use_cat;
    if ($use_q !== '') $p['q'] = $use_q;
    if ($use_page > 1) $p['page'] = $use_page;
    if (isset($_GET['limit']) && (int)$_GET['limit'] !== 25) $p['limit'] = $per_page;

    return 'kawara_list.php' . (!empty($p) ? '?' . http_build_query($p) : '');
};

// ==========================================
// 📅 2週間カード表示用 データ準備
// ==========================================
$week_days = [];
$week_groups = [];
$week_span_events = [[], []];
$gcal_timed_events_by_date = [];
$doctor_events_by_date = [];
$gcal_channels = [];
$prev_2weeks_date = '';
$next_2weeks_date = '';
$week_monday_ts = time();
$period_sunday_ts = time();

if ($view_mode === '2weeks') {
    // 週間 / 2週間 計算 (月曜始まり: Mon〜Sun)
    $sel_ts = strtotime($selected_date);
    $dow = (int)date('w', $sel_ts); // 0=Sun, 1=Mon, ..., 6=Sat
    $days_from_mon = ($dow === 0) ? 6 : ($dow - 1);
    $week_monday_ts = strtotime("-{$days_from_mon} days", $sel_ts);

    $week_count = 2;
    $period_end_offset = 13;
    $period_sunday_ts = strtotime("+{$period_end_offset} days", $week_monday_ts);

    $week_start_date = date('Y-m-d', $week_monday_ts);
    $week_end_date   = date('Y-m-d', $period_sunday_ts);

    // 2週ナビゲーション用リンク日付 (14日前後)
    $prev_2weeks_date = date('Y-m-d', strtotime("-14 days", $week_monday_ts));
    $next_2weeks_date = date('Y-m-d', strtotime("+14 days", $week_monday_ts));

    // かわら版イベントの取得
    $stmt_events = $pdo->prepare("
        SELECT p.*, c.category_name, c.icon_emoji, s.staff_name 
        FROM posts p 
        LEFT JOIN post_categories c ON p.category_id = c.category_id 
        LEFT JOIN staff s ON p.author_id = s.staff_id 
        WHERE (
            (p.target_datetime >= :s_start AND p.target_datetime <= :s_end)
            OR (p.event_schedules IS NOT NULL AND p.event_schedules::text != '[]' AND p.event_schedules::text != 'null')
        )
        ORDER BY p.target_datetime ASC, p.post_id ASC
    ");
    $calendar_start_range = date('Y-m-d 00:00:00', strtotime('-3 days', $week_monday_ts));
    $calendar_end_range   = date('Y-m-d 23:59:59', strtotime('+3 days', $period_sunday_ts));

    $stmt_events->execute([
        ':s_start' => $calendar_start_range,
        ':s_end'   => $calendar_end_range
    ]);
    $all_event_posts = $stmt_events->fetchAll();

    // 日付ごとにイベントをマッピング（初日＝通常カード、2日目以降＝(続) 件名 [第〇回]）
    $date_events_map = [];
    foreach ($all_event_posts as $ep) {
        $has_slot = false;
        if (!empty($ep['event_schedules'])) {
            $raw_slots = json_decode($ep['event_schedules'], true);
            if (is_array($raw_slots) && count($raw_slots) > 0) {
                $slots = [];
                foreach ($raw_slots as $rsl) {
                    $s_date = !empty($rsl['date']) ? $rsl['date'] : substr($rsl['start_datetime'] ?? '', 0, 10);
                    $s_time = !empty($rsl['is_all_day']) ? '終日' : (!empty($rsl['start_time']) ? $rsl['start_time'] : (!empty($rsl['start_datetime']) ? date('H:i', strtotime($rsl['start_datetime'])) : ''));
                    $e_time = !empty($rsl['is_all_day']) ? '' : (!empty($rsl['end_time']) ? $rsl['end_time'] : (!empty($rsl['end_datetime']) ? date('H:i', strtotime($rsl['end_datetime'])) : ''));
                    $start_dt = !empty($rsl['start_datetime']) ? $rsl['start_datetime'] : ($s_date . ' ' . (!empty($rsl['start_time']) ? $rsl['start_time'] : '00:00:00'));
                    $end_dt = !empty($rsl['end_datetime']) ? $rsl['end_datetime'] : ((!empty($rsl['end_date']) ? $rsl['end_date'] : $s_date) . ' ' . (!empty($rsl['end_time']) ? $rsl['end_time'] : '23:59:59'));

                    if (!empty($s_date)) {
                        $slots[] = [
                            'schedule_id'    => $rsl['schedule_id'] ?? ('slot_' . (count($slots) + 1)),
                            'date'           => $s_date,
                            'start_time'     => $s_time,
                            'end_time'       => $e_time,
                            'start_datetime' => $start_dt,
                            'end_datetime'   => $end_dt,
                            'location'       => $rsl['location'] ?? '',
                            'memo'           => $rsl['memo'] ?? '',
                            'is_all_day'     => !empty($rsl['is_all_day'])
                        ];
                    }
                }

                usort($slots, function($a, $b) {
                    return strcmp($a['start_datetime'], $b['start_datetime']);
                });

                $total_slots = count($slots);
                foreach ($slots as $idx => $slot) {
                    $d = $slot['date'];
                    $slot_num = $idx + 1;
                    $is_first = ($idx === 0);
                    $is_continuation = ($idx > 0);
                    $display_title = $is_continuation ? "（続）" . $ep['title'] : $ep['title'];
                    $slot_badge = $total_slots > 1 ? "[第{$slot_num}回]" : "";

                    $date_events_map[$d][] = [
                        'post_id'         => (int)$ep['post_id'],
                        'title'           => $ep['title'],
                        'display_title'   => $display_title,
                        'slot_num'        => $slot_num,
                        'total_slots'     => $total_slots,
                        'is_first'        => $is_first,
                        'is_continuation' => $is_continuation,
                        'slot_badge'      => $slot_badge,
                        'start_time'      => $slot['start_time'],
                        'end_time'        => $slot['end_time'],
                        'location'        => $slot['location'],
                        'memo'            => $slot['memo'],
                        'icon'            => $ep['icon_emoji'] ?? '🔧',
                        'author'          => $ep['staff_name'] ?? '事務部',
                        'raw_post'        => $ep,
                        'all_slots'       => $slots
                    ];
                    $has_slot = true;
                }
            }
        }
        if (!$has_slot && !empty($ep['target_datetime'])) {
            $d = substr($ep['target_datetime'], 0, 10);
            $date_events_map[$d][] = [
                'post_id'         => (int)$ep['post_id'],
                'title'           => $ep['title'],
                'display_title'   => $ep['title'],
                'slot_num'        => 1,
                'total_slots'     => 1,
                'is_first'        => true,
                'is_continuation' => false,
                'slot_badge'      => '',
                'start_time'      => date('H:i', strtotime($ep['target_datetime'])),
                'end_time'        => !empty($ep['target_end_datetime']) ? date('H:i', strtotime($ep['target_end_datetime'])) : '',
                'location'        => '',
                'memo'            => '',
                'icon'            => $ep['icon_emoji'] ?? '🔧',
                'author'          => $ep['staff_name'] ?? '事務部',
                'raw_post'        => $ep,
                'all_slots'       => [
                    [
                        'schedule_id'    => 'slot_1',
                        'start_datetime' => $ep['target_datetime'],
                        'end_datetime'   => $ep['target_end_datetime'] ?? '',
                        'location'       => '',
                        'memo'           => ''
                    ]
                ]
            ];
        }
    }

    foreach ($date_events_map as $d => &$evList) {
        usort($evList, function($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });
    }
    unset($evList);

    // 🌟 ダッシュボードメモ（月メモ・日メモ）の取得
    $cur_month_key = date('Y-m', $week_monday_ts);
    $cur_month_label = date('Y年n月', $week_monday_ts);
    $stmt_m_note = $pdo->prepare("SELECT content FROM dashboard_notes WHERE target_type = 'month' AND target_key = :k");
    $stmt_m_note->execute([':k' => $cur_month_key]);
    $month_note = $stmt_m_note->fetchColumn() ?: '';

    $stmt_d_notes = $pdo->prepare("SELECT target_key, content FROM dashboard_notes WHERE target_type = 'date' AND target_key >= :s AND target_key <= :e");
    $stmt_d_notes->execute([':s' => $week_start_date, ':e' => $week_end_date]);
    $date_notes_map = $stmt_d_notes->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // 14日分配列
    for ($i = 0; $i < 14; $i++) {
        $cur_ts = strtotime("+{$i} days", $week_monday_ts);
        $cur_d_str = date('Y-m-d', $cur_ts);
        $style_info = get_jimucho_cell_style($cur_d_str);
        $traditional_info = get_traditional_calendar_info($cur_d_str);

        $week_days[] = [
            'date_str'    => $cur_d_str,
            'ts'          => $cur_ts,
            'day_num'     => date('j', $cur_ts),
            'month_num'   => date('n', $cur_ts),
            'dow_text'    => ['日','月','火','水','木','金','土'][(int)date('w', $cur_ts)],
            'style_info'  => $style_info,
            'duty_info'   => $style_info['info'],
            'traditional' => $traditional_info,
            'events'      => $date_events_map[$cur_d_str] ?? [],
            'day_note'    => $date_notes_map[$cur_d_str] ?? '',
            'is_today'    => ($cur_d_str === $today_str),
            'is_selected' => ($cur_d_str === $selected_date)
        ];
    }

    // 週ごとにグループ化（第1週、第2週）
    $week_groups = [
        0 => array_slice($week_days, 0, 7),
        1 => array_slice($week_days, 7, 7)
    ];

    // Googleカレンダーチャンネル（master_menteで「かわら版表示」が有効なもののみ取得）
    $gcal_channels = $pdo->query("SELECT * FROM google_calendar_channels WHERE is_enabled = TRUE AND (show_in_kawara IS NOT FALSE) ORDER BY display_order ASC, channel_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Google同期（15分以上経過）
    $last_gcal_sync_ts = $pdo->query("SELECT MAX(synced_at) FROM google_calendar_events_cache")->fetchColumn();
    if (!empty($gcal_channels) && (!$last_gcal_sync_ts || (time() - strtotime($last_gcal_sync_ts)) > 900)) {
        @sync_all_google_calendars($pdo, false);
    }

    $gcal_channel_ids = array_column($gcal_channels, 'channel_id');
    $gcal_raw_events = !empty($gcal_channel_ids) ? get_cached_google_events_for_range($pdo, $week_start_date, $week_end_date, $gcal_channel_ids) : [];

    // 医師予定表 (yotei API)
    $doctor_raw_events = fetch_doctor_events($week_start_date, $week_end_date, ['expand_period' => 1]);
    $doctor_events_by_date = map_doctor_events_by_date($doctor_raw_events);

    // Googleイベント分類＆スパン計算
    foreach ($gcal_raw_events as $gev) {
        $ev_start_date = substr($gev['start_datetime'], 0, 10);
        $ev_end_date   = substr($gev['end_datetime'], 0, 10);
        $is_all_day    = !empty($gev['is_all_day']);
        $is_multi_day  = ($ev_start_date !== $ev_end_date);

        if ($is_all_day || $is_multi_day) {
            for ($w = 0; $w < 2; $w++) {
                $w_mon_ts = strtotime("+" . ($w * 7) . " days", $week_monday_ts);
                $start_diff = (int)round((strtotime($ev_start_date) - $w_mon_ts) / 86400);
                $end_diff   = (int)round((strtotime($ev_end_date) - $w_mon_ts) / 86400);

                $col_start = max(1, $start_diff + 1);
                $col_end   = min(7, $end_diff + 1);

                if ($col_start <= 7 && $col_end >= 1 && $col_start <= $col_end) {
                    $col_span = $col_end - $col_start + 1;
                    $w_gev = $gev;
                    $w_gev['start_col'] = $col_start;
                    $w_gev['col_span']  = $col_span;
                    $w_gev['is_clipped_start'] = ($start_diff < 0);
                    $w_gev['is_clipped_end']   = ($end_diff > 6);
                    $week_span_events[$w][] = $w_gev;
                }
            }
        } else {
            if (!isset($gcal_timed_events_by_date[$ev_start_date])) {
                $gcal_timed_events_by_date[$ev_start_date] = [];
            }
            $gcal_timed_events_by_date[$ev_start_date][] = $gev;
        }
    }
}

// ==========================================
// 📋 一覧表示用 データ準備（SQL発行 ＆ ページネーション）
// ==========================================
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

$tomorrow_str   = date('Y-m-d', strtotime('+1 day'));
$plus7_end_str  = date('Y-m-d', strtotime('+7 days'));

$this_month_start = date('Y-m-01');
$this_month_end   = date('Y-m-t');

$next_month_start = date('Y-m-01', strtotime('first day of next month'));
$next_month_end   = date('Y-m-t', strtotime('last day of next month'));

$where_clauses = [];
$sql_params = [];

if ($date_filter === 'past') {
    $where_clauses[] = "(p.display_until < NOW() OR (p.display_until IS NULL AND p.target_datetime IS NOT NULL AND DATE(COALESCE(p.target_end_datetime, p.target_datetime)) < '{$today_str}'))";
} elseif ($date_filter === 'all_history') {
    // 全履歴モード
} else {
    $where_clauses[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
}

if ($selected_cat > 0) {
    $where_clauses[] = "p.category_id = :cat_id";
    $sql_params[':cat_id'] = $selected_cat;
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

if ($search_query !== '') {
    $keywords = preg_split('/[\s　]+/u', $search_query, -1, PREG_SPLIT_NO_EMPTY);
    $kw_conditions = [];
    foreach ($keywords as $idx => $kw) {
        $p_t = ":kw_t_{$idx}";
        $p_c = ":kw_c_{$idx}";
        $p_a = ":kw_a_{$idx}";
        $kw_conditions[] = "(p.title ILIKE {$p_t} OR p.content ILIKE {$p_c} OR s.staff_name ILIKE {$p_a})";
        $sql_params[$p_t] = '%' . $kw . '%';
        $sql_params[$p_c] = '%' . $kw . '%';
        $sql_params[$p_a] = '%' . $kw . '%';
    }
    if (!empty($kw_conditions)) {
        $where_clauses[] = "(" . implode(" AND ", $kw_conditions) . ")";
    }
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$stmt_posts = $pdo->prepare($sql);
$stmt_posts->execute($sql_params);
$raw_posts = $stmt_posts->fetchAll();

// 重要度判定 ＆ ソートスコア計算
$now = new DateTime();
$week_names = ['日', '月', '火', '水', '木', '金', '土'];

foreach ($raw_posts as &$p) {
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

    $is_read = (bool)$p['is_my_read'];
    $sort_score = 0;

    if ($p['is_pinned']) {
        $sort_score += 100000000;
    }
    if (!$is_read) {
        $sort_score += 50000000;
    }
    if ($priority_level === 'urgent') {
        $sort_score += 30000000;
    } elseif ($priority_level === 'important') {
        $sort_score += 10000000;
    }
    $sort_score += $created_dt->getTimestamp();

    $p['priority_level'] = $priority_level;
    $p['is_urgent'] = $is_urgent;
    $p['is_within_24h'] = $is_within_24h;
    $p['is_today_event'] = $is_today_event;
    $p['is_new_post'] = $is_new_post;
    $p['sort_score'] = $sort_score;

    $clean_content = strip_tags($p['content']);
    $clean_content = preg_replace('/\s+/', ' ', $clean_content);
    $p['plain_summary'] = mb_substr($clean_content, 0, 75) . (mb_strlen($clean_content) > 75 ? '...' : '');

    $p['author_dept'] = '事務部';
    if (!empty($p['author_id'])) {
        $stmt_dept = $pdo->prepare("SELECT td.dept_name FROM staff s JOIN target_departments td ON s.dept_id = td.dept_id WHERE s.staff_id = :sid");
        $stmt_dept->execute([':sid' => $p['author_id']]);
        $d_name = $stmt_dept->fetchColumn();
        if ($d_name) $p['author_dept'] = $d_name;
    }
}
unset($p);

usort($raw_posts, function($a, $b) {
    if ($a['sort_score'] !== $b['sort_score']) {
        return ($a['sort_score'] > $b['sort_score']) ? -1 : 1;
    }
    return $b['post_id'] <=> $a['post_id'];
});

$total_posts = count($raw_posts);
$total_pages = max(1, (int)ceil($total_posts / $per_page));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;
$paged_raw_posts = array_slice($raw_posts, $offset, $per_page);

$all_active_staff_stmt = $pdo->query("SELECT staff_id, staff_name, dept_id FROM staff WHERE is_deleted IS NOT TRUE");
$all_active_staff = $all_active_staff_stmt->fetchAll();

$posts = [];
$target_stmt = $pdo->prepare("SELECT dept_id FROM post_target_departments WHERE post_id = :pid");
$read_stmt = $pdo->prepare("SELECT staff_id FROM post_reads WHERE post_id = :pid");

foreach ($paged_raw_posts as $p) {
    $target_stmt->execute([':pid' => $p['post_id']]);
    $target_depts = $target_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array(1, $target_depts)) {
        $target_members = $all_active_staff;
    } else {
        $target_members = array_filter($all_active_staff, function($s) use ($target_depts) {
            return in_array($s['dept_id'], $target_depts);
        });
    }

    $read_stmt->execute([':pid' => $p['post_id']]);
    $read_staff_ids = $read_stmt->fetchAll(PDO::FETCH_COLUMN);

    $p['target_members']  = $target_members;
    $p['read_staff_ids']  = $read_staff_ids;
    $p['read_count']      = count(array_intersect(array_column($target_members, 'staff_id'), $read_staff_ids));
    $p['total_targets']   = count($target_members);

    $posts[] = $p;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>院内かわら版 - <?= $view_mode === '2weeks' ? '2週間カレンダー' : '一覧' ?></title>
    <script>
        (function() {
            if (localStorage.getItem('kawara_density_mode') === 'ultra') {
                document.documentElement.classList.add('density-ultra');
            }
        })();
    </script>
    <style>
        :root {
            --primary: #1e293b;
            --primary-light: #334155;
            --accent: #0284c7;
            --accent-hover: #0369a1;
            --accent-light: #e0f2fe;
            --bg-main: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --danger: #f43f5e;
            --success: #10b981;
            --warning: #f59e0b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hiragino Sans", "Meiryo", sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* ==========================================
           🌟 ヘッダー（落ち着いたスレートネイビー）
           ========================================== */
        header {
            background: var(--primary);
            color: #fff;
            padding: 0.45rem 1rem;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.15);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .header-container {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-logo-link {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #ffffff;
        }
        .header-logo-icon { font-size: 1.35rem; }
        .header-logo-text {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .header-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            transition: all 0.15s ease;
            white-space: nowrap;
            cursor: pointer;
        }
        .header-nav-btn:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateY(-1px);
        }

        /* 👔 事務長モードボタン（管理者・山本太専用、分かりやすい場所に独立配置） */
        .btn-nav-jimucho {
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            border: 1px solid #3b82f6 !important;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35);
        }
        .btn-nav-jimucho:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af) !important;
            box-shadow: 0 3px 10px rgba(37, 99, 235, 0.5);
        }

        .btn-nav-menu {
            background: rgba(255, 255, 255, 0.16);
        }

        .btn-nav-user {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.15);
        }
        .badge-switch {
            font-size: 0.68rem;
            background: rgba(255, 255, 255, 0.25);
            padding: 1px 5px;
            border-radius: 3px;
            margin-left: 2px;
        }

        .btn-line-header {
            font-size: 0.76rem;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-line-header.is-linked {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .btn-line-header.is-unlinked {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        /* ☰ その他ドロップダウン */
        .header-dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
            min-width: 190px;
            z-index: 1100;
            overflow: hidden;
            animation: fadeIn 0.15s ease-out;
        }
        .dropdown-menu.active {
            display: block;
        }
        .dropdown-item {
            display: block;
            padding: 9px 14px;
            font-size: 0.84rem;
            color: var(--text-main);
            text-decoration: none;
            font-weight: 600;
            transition: background 0.15s;
        }
        .dropdown-item:hover {
            background: #f1f5f9;
            color: var(--accent);
        }
        .dropdown-item.text-admin {
            color: #c2410c;
        }
        .dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 4px 0;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ==========================================
           メインレイアウト
           ========================================== */
        main {
            max-width: 1380px;
            margin: 0.5rem auto;
            padding: 0 0.8rem;
        }

        .alert-notice {
            background: var(--accent-light);
            color: var(--accent-hover);
            border: 1px solid #bae6fd;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.84rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        /* 🩺 医師予定表 申し送りメモ アコーディオンバナー */
        .doctor-memo-banner {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-left: 5px solid #0d9488;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .doctor-memo-header {
            padding: 6px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            background: #f0fdfa;
            user-select: none;
        }
        .doctor-memo-header:hover {
            background: #ccfbf1;
        }
        .doctor-memo-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f766e;
        }
        .doctor-memo-icon { font-size: 1.05rem; }
        .doctor-memo-date {
            font-size: 0.74rem;
            color: #64748b;
            font-weight: normal;
            margin-left: 6px;
        }
        .doctor-memo-arrow {
            font-size: 0.78rem;
            color: #0f766e;
            font-weight: 700;
        }
        .doctor-memo-body {
            display: none;
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            font-size: 0.88rem;
            color: #1e293b;
            line-height: 1.6;
        }
        .doctor-memo-banner.is-open .doctor-memo-body {
            display: block;
        }

        /* ==========================================
           フィルター ＆ ツールバー
           ========================================== */
        .filter-section {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 6px 12px;
            margin-bottom: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .toolbar-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-create {
            background: #10b981;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 0.84rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 1px 3px rgba(16, 185, 129, 0.3);
            white-space: nowrap;
            transition: all 0.15s;
        }
        .btn-create:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        /* 🌟 表示モード切替（セグメントスイッチ） */
        .view-mode-switch {
            display: inline-flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
        }
        .btn-mode-tab {
            padding: 4px 12px;
            border-radius: 5px;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            color: #64748b;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .btn-mode-tab.active {
            background: #ffffff;
            color: #0284c7;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .btn-mode-tab:hover:not(.active) {
            color: #0f172a;
        }

        .btn-print-top {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-print-top:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        .search-form-wrap { display: flex; align-items: center; gap: 4px; }
        .search-input-box {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 2px 4px 2px 8px;
            transition: all 0.2s;
        }
        .search-input-box:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }
        .search-input {
            border: none;
            outline: none;
            font-size: 0.82rem;
            padding: 3px 4px;
            width: 170px;
            color: #334155;
            background: transparent;
        }
        .btn-clear-q {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 0.85rem;
            cursor: pointer;
            padding: 2px 5px;
            text-decoration: none;
            line-height: 1;
        }
        .btn-clear-q:hover { color: #ef4444; }
        .btn-search {
            background: var(--accent);
            color: #ffffff;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .btn-search:hover { background: var(--accent-hover); }

        .date-filter-group {
            display: flex;
            gap: 3px;
            background: #f8fafc;
            padding: 3px;
            border-radius: 6px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid #e2e8f0;
        }
        .btn-date {
            text-decoration: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: 700;
            color: #64748b;
            transition: all 0.15s;
        }
        .btn-date:hover { background: #e2e8f0; color: #0f172a; }
        .btn-date.active { background: var(--accent); color: #fff; }

        .cat-tabs {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            padding-top: 6px;
            border-top: 1px dashed #e2e8f0;
        }
        .cat-tab {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 2px 8px;
            border-radius: 12px;
            text-decoration: none;
            color: #64748b;
            font-size: 0.74rem;
            font-weight: 700;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .cat-tab:hover { background: #e0f2fe; border-color: #7dd3fc; color: #0369a1; }
        .cat-tab.active { background: #334155; color: #fff; border-color: #334155; }

        /* ==========================================
           📋 一覧表示 カードデザイン（上品な医療系モダン）
           ========================================== */
        .post-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .post-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .post-card:hover {
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
            transform: translateY(-1px);
        }

        /* 未読カード：淡いブルー背景 ＋ スカイブルー左端ライン ＋ パルス発光ドット */
        .post-card.is-unread {
            background: #f8faff;
            border-left: 4px solid var(--accent);
        }
        .unread-indicator {
            position: absolute;
            top: 12px;
            right: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            font-weight: 800;
            color: #0284c7;
            background: #e0f2fe;
            padding: 2px 7px;
            border-radius: 10px;
        }
        .unread-dot {
            width: 7px;
            height: 7px;
            background: #0284c7;
            border-radius: 50%;
            animation: soft-pulse 2s infinite ease-in-out;
        }
        @keyframes soft-pulse {
            0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.4); }
            50% { opacity: 0.5; transform: scale(1.1); box-shadow: 0 0 0 4px rgba(2, 132, 199, 0); }
        }

        /* 既読カード：静かなオフホワイト */
        .post-card.is-read {
            background: #ffffff;
            border-left: 3px solid #cbd5e1;
        }

        /* 直近・緊急：上品なコーラルローズのアクセントライン */
        .post-card.p-urgent {
            border-left-color: var(--danger) !important;
        }
        .post-card.p-urgent.is-unread {
            background: #fff5f5;
        }

        .post-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
            padding-right: 70px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .badge-urgent { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-24h { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-new { background: #f43f5e; color: #fff; font-size: 0.65rem; font-weight: 800; padding: 1px 5px; border-radius: 3px; }
        .badge-pinned { background: #334155; color: #fff; }
        .badge-cat { padding: 2px 7px; border-radius: 4px; color: #fff; font-size: 0.72rem; font-weight: 700; }

        .post-title-wrapper { margin-bottom: 4px; }
        .post-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
            text-decoration: none;
            line-height: 1.4;
        }
        .post-title:hover { color: var(--accent); }
        .post-title.text-urgent { color: #e11d48; }
        .post-author-tag { font-size: 0.78rem; color: #64748b; margin-left: 4px; font-weight: normal; }

        .post-meta {
            font-size: 0.78rem;
            color: #64748b;
            display: flex;
            gap: 12px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .event-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.84rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .event-box.urgent-box {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #be123c;
        }

        .post-body-preview {
            font-size: 0.9rem;
            color: #334155;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .read-action-bar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .btn-unread-reset {
            background: transparent;
            border: 1px solid #cbd5e1;
            color: #64748b;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .btn-unread-reset:hover { background: #f1f5f9; color: #0f172a; }

        .toggle-bar { display: flex; gap: 8px; margin-top: 6px; }
        .toggle-btn {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #475569;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
        }
        .toggle-btn:hover { background: #e2e8f0; color: #0f172a; }
        .accordion-content {
            display: none;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            margin-top: 6px;
            font-size: 0.82rem;
        }

        /* ページネーション */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.2rem;
            flex-wrap: wrap;
            gap: 8px;
        }
        .pagination { display: flex; gap: 4px; }
        .page-link {
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .page-link.active { background: var(--accent); color: #fff; border-color: var(--accent); }

        /* ==========================================
           📅 2週間カードカレンダー（7列×2週 グリッド）
           事務長モードと完全一致の構造＆スタイル
           ========================================== */
        .week-view-wrapper {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .week-navbar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 6px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .cal-nav-btn {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.15s;
        }
        .cal-nav-btn:hover { background: #e2e8f0; color: #0f172a; }
        .week-nav-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-gcal-sync {
            background: #ffffff;
            color: #0284c7;
            border: 1px solid #7dd3fc;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }
        .btn-gcal-sync:hover { background: #e0f2fe; }

        /* Googleカレンダー チャンネルトグルバー */
        .gcal-toggle-bar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.8rem;
        }
        .gcal-toggle-label { font-weight: 700; color: #475569; }
        .gcal-toggle-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .gcal-toggle-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 3px 8px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .gcal-toggle-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .week-block-header {
            background: #f1f5f9;
            border-left: 4px solid var(--accent);
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.86rem;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
        }

        /* 終日・複数日帯レーン */
        .week-span-container {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 6px;
            margin-bottom: -6px;
        }
        .week-span-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .week-span-bar {
            border-radius: 4px;
            padding: 4px 8px;
            color: #ffffff;
            font-size: 0.74rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .week-span-bar:hover { opacity: 0.9; }

        /* 7列カードグリッド */
        .week-grid-7cols {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        .week-day-col {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            min-height: 155px;
            overflow: hidden;
        }
        .week-day-col.is-today {
            border: 2px solid #0284c7;
            background: #fcfdfe;
        }

        .week-day-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 4px 6px;
        }
        .week-day-header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .week-day-date {
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .badge-today {
            background: #0284c7;
            color: #fff;
            font-size: 0.65rem;
            padding: 1px 5px;
            border-radius: 3px;
        }
        .week-btn-add {
            background: #e2e8f0;
            color: #334155;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 900;
            text-decoration: none;
        }
        .week-btn-add:hover { background: var(--accent); color: #fff; }

        .week-traditional-row {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            margin-top: 3px;
        }
        .trad-badge {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .trad-rokuyo.rokuyo-taian { background: #fee2e2; color: #dc2626; }
        .trad-rokuyo.rokuyo-tomobiki { background: #eff6ff; color: #2563eb; }
        .trad-rokuyo.rokuyo-butsumetsu { background: #f1f5f9; color: #64748b; }
        .trad-rokuyo.rokuyo-default { background: #f8fafc; color: #475569; }
        .trad-solar { background: #dcfce7; color: #15803d; }
        .trad-moon { background: #fef3c7; color: #b45309; }
        .trad-moon-mini { font-size: 0.68rem; color: #64748b; }

        .week-day-badges {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            margin-top: 3px;
        }
        .badge-duty {
            font-size: 0.65rem;
            padding: 1px 5px;
            border-radius: 3px;
            font-weight: 700;
        }
        .badge-duty-closed { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .badge-duty-pm_closed,
        .badge-duty-pm-closed { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .badge-duty-open { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-duty-normal { background: #f1f5f9; color: #475569; }

        .week-day-body {
            padding: 6px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
        }

        /* 医師予定カード */
        .week-doctor-card {
            border-left: 3px solid #0284c7;
            background: #f0f9ff;
            border-radius: 4px;
            padding: 4px 6px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            transition: all 0.15s;
        }
        .week-doctor-card:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.08); }
        .doctor-badge-tag {
            font-size: 0.65rem;
            color: #fff;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 700;
            margin-left: auto;
        }

        /* Googleカレンダー時間指定カード */
        .week-gcal-card {
            border-left: 3px solid #1a73e8;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left-width: 3px;
            border-radius: 4px;
            padding: 4px 6px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .week-gcal-card:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.08); }
        .gcal-cal-tag {
            font-size: 0.62rem;
            color: #fff;
            padding: 1px 4px;
            border-radius: 3px;
            margin-left: auto;
        }

        /* かわら版イベントカード */
        .week-event-card {
            border-left: 3px solid #0d9488;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left-width: 3px;
            border-radius: 4px;
            padding: 4px 6px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            transition: all 0.15s;
        }
        .week-event-card:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .week-event-card-continuation {
            border-left-color: #64748b;
            background: #f8fafc;
        }
        .wec-header {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            color: #64748b;
        }
        .wec-time { font-weight: 700; color: #0f172a; }
        .wec-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #0f172a;
            margin-top: 2px;
            line-height: 1.3;
        }
        .wec-badge-cont {
            background: #64748b;
            color: #fff;
            font-size: 0.62rem;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 800;
        }
        .wec-slot-badge {
            font-size: 0.65rem;
            color: #0284c7;
            font-weight: 700;
        }

        .empty-day-placeholder {
            text-align: center;
            padding: 1.5rem 0.5rem;
            color: #94a3b8;
            font-size: 0.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        /* ==========================================
           モーダル（医師詳細、Google詳細、LINE連携）
           ========================================== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.active { display: flex; animation: fadeIn 0.2s; }
        .modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 520px;
            border-radius: 12px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        .modal-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { margin: 0; font-size: 1rem; color: #0f172a; display: flex; align-items: center; gap: 6px; }
        .modal-close {
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.4rem;
            cursor: pointer;
            line-height: 1;
        }
        .modal-close:hover { color: #0f172a; }
        .modal-body { padding: 18px; overflow-y: auto; font-size: 0.88rem; }

        /* LINE連携モーダル用 */
        .line-modal-card { max-width: 480px; }
        .link-code-digit {
            font-family: monospace;
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: 8px;
            color: #065f46;
            background: #ecfdf5;
            border: 2px dashed #06c755;
            border-radius: 8px;
            padding: 8px;
            text-align: center;
            margin: 10px 0;
        }
        /* 📌 今月の重点目標・重要メモ（月メモ） */
        .month-note-banner {
            background: #fffbeb;
            border: 1px solid #fef08a;
            border-left: 5px solid #f59e0b;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .month-note-banner:hover {
            background: #fefce8;
            border-color: #f59e0b;
        }
        .month-note-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .month-note-title {
            font-size: 0.86rem;
            font-weight: 800;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .month-note-body {
            margin-top: 4px;
        }
        .month-note-text {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.45;
        }
        .month-note-placeholder {
            font-size: 0.82rem;
            color: #b45309;
            opacity: 0.85;
            font-weight: 600;
        }
        .btn-note-edit {
            background: #ffffff;
            border: 1px solid #fde68a;
            color: #b45309;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-note-edit:hover {
            background: #f59e0b;
            color: #ffffff;
            border-color: #f59e0b;
        }

        /* 📝 2週間カレンダー用 日付メモボタン＆カード */
        .week-btn-note {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #f8fafc;
            color: #64748b;
            font-size: 0.8rem;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            transition: all 0.15s ease;
            line-height: 1;
            padding: 0;
        }
        .week-btn-note:hover {
            background: #fef3c7;
            color: #b45309;
            border-color: #fde68a;
            transform: scale(1.1);
        }
        .week-btn-note.has-note {
            background: #fef3c7;
            color: #b45309;
            border-color: #fde68a;
            font-weight: bold;
        }
        .week-note-card {
            background: #fffbeb;
            border: 1px solid #fef08a;
            border-left: 4px solid #f59e0b;
            border-radius: 5px;
            padding: 4px 6px;
            font-size: 0.76rem;
            color: #78350f;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 4px;
            line-height: 1.35;
            margin-bottom: 3px;
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            transition: all 0.15s ease;
        }
        .week-note-card:hover {
            background: #fefce8;
            border-color: #f59e0b;
            transform: translateY(-1px);
        }

        /* 🗜️ 表示密度切替ボタン */
        .btn-density-toggle {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-density-toggle:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
        .density-ultra .btn-density-toggle,
        .btn-density-toggle.is-active {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 1px 4px rgba(2, 132, 199, 0.35);
        }
        .density-ultra .btn-density-toggle:hover,
        .btn-density-toggle.is-active:hover {
            background: #0369a1;
        }

        /* ========================================================
           🗜️ 限界圧縮モード（Ultra Compact Mode）
           縦方向の余白・高さを極限まで削ぎ落とし、1画面に2週間を凝縮
           ======================================================== */
        .density-ultra header {
            padding: 3px 12px;
        }
        .density-ultra .header-logo-icon { font-size: 1.1rem; }
        .density-ultra .header-logo-text { font-size: 0.95rem; }
        .density-ultra .header-nav-btn {
            padding: 2px 8px;
            font-size: 0.74rem;
        }
        .density-ultra main {
            margin: 2px auto;
            padding: 0 6px;
            max-width: 100%;
        }
        .density-ultra .alert-notice {
            padding: 3px 8px;
            margin-bottom: 2px;
            font-size: 0.78rem;
        }
        .density-ultra .doctor-memo-banner {
            margin-bottom: 3px;
            border-radius: 4px;
            border-left-width: 3px;
        }
        .density-ultra .doctor-memo-header {
            padding: 3px 8px;
            font-size: 0.78rem;
        }
        .density-ultra .doctor-memo-title {
            font-size: 0.78rem;
            gap: 4px;
        }
        .density-ultra .doctor-memo-body {
            padding: 6px 10px;
            font-size: 0.78rem;
            line-height: 1.3;
        }
        .density-ultra .filter-section {
            padding: 3px 8px;
            margin-bottom: 3px;
            gap: 3px;
            border-radius: 4px;
        }
        .density-ultra .btn-create {
            padding: 3px 9px;
            font-size: 0.76rem;
        }
        .density-ultra .view-mode-switch {
            padding: 2px;
        }
        .density-ultra .btn-mode-tab {
            padding: 2px 8px;
            font-size: 0.74rem;
        }
        .density-ultra .btn-density-toggle {
            padding: 2px 7px;
            font-size: 0.72rem;
        }
        .density-ultra .search-input-box {
            padding: 1px 4px;
        }
        .density-ultra .search-input {
            font-size: 0.74rem;
            padding: 1px 3px;
            width: 130px;
        }
        .density-ultra .btn-search {
            padding: 3px 8px;
            font-size: 0.74rem;
        }

        /* 2週間カレンダーの極限圧縮 */
        .density-ultra .week-view-wrapper {
            gap: 3px;
        }
        .density-ultra .week-navbar {
            padding: 3px 8px;
            border-radius: 4px;
        }
        .density-ultra .cal-nav-btn {
            padding: 2px 8px;
            font-size: 0.72rem;
        }
        .density-ultra .week-nav-title {
            font-size: 0.82rem;
            gap: 4px;
        }
        .density-ultra .month-note-banner {
            padding: 2px 8px;
            margin-bottom: 3px;
            border-left-width: 3px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }
        .density-ultra .month-note-header {
            display: inline-flex;
            flex-shrink: 0;
            gap: 4px;
        }
        .density-ultra .month-note-title {
            font-size: 0.74rem;
            white-space: nowrap;
        }
        .density-ultra .month-note-body {
            margin-top: 0;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .density-ultra .month-note-text {
            font-size: 0.74rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.2;
        }
        .density-ultra .month-note-placeholder {
            font-size: 0.72rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .density-ultra .btn-note-edit {
            padding: 1px 5px;
            font-size: 0.66rem;
            flex-shrink: 0;
        }
        .density-ultra .btn-gcal-sync {
            padding: 2px 8px;
            font-size: 0.72rem;
        }
        .density-ultra .gcal-toggle-bar {
            padding: 2px 8px;
            font-size: 0.72rem;
            gap: 4px;
            border-radius: 4px;
        }
        .density-ultra .gcal-toggle-pill {
            padding: 1px 6px;
            font-size: 0.68rem;
        }
        .density-ultra .week-block-header {
            padding: 2px 6px;
            font-size: 0.74rem;
            margin-top: 1px;
            border-radius: 3px;
        }
        .density-ultra .week-span-container {
            padding: 2px 4px;
            margin-bottom: -2px;
        }
        .density-ultra .week-span-bar {
            padding: 1px 5px;
            font-size: 0.66rem;
            line-height: 1.2;
        }
        .density-ultra .week-grid-7cols {
            gap: 3px;
        }
        .density-ultra .week-day-col {
            min-height: 95px !important;
            border-radius: 4px;
        }
        .density-ultra .week-day-header {
            padding: 2px 4px;
            gap: 1px;
        }
        .density-ultra .week-day-date {
            font-size: 0.76rem;
            gap: 2px;
        }
        .density-ultra .badge-today {
            font-size: 0.58rem;
            padding: 0 3px;
        }
        .density-ultra .week-btn-add,
        .density-ultra .week-btn-note {
            width: 16px;
            height: 16px;
            font-size: 0.68rem;
        }
        .density-ultra .week-traditional-row {
            margin-top: 0px;
            gap: 2px;
        }
        .density-ultra .trad-badge {
            font-size: 0.58rem;
            padding: 0 3px;
            line-height: 1.1;
        }
        .density-ultra .trad-moon-mini {
            font-size: 0.58rem;
        }
        .density-ultra .badge-duty {
            font-size: 0.58rem;
            padding: 0 3px;
            line-height: 1.1;
        }
        .density-ultra .week-day-body {
            padding: 2px;
            gap: 2px;
        }
        .density-ultra .week-note-card {
            padding: 1px 4px;
            font-size: 0.68rem;
            margin-bottom: 2px;
            line-height: 1.15;
            border-left-width: 2px;
            border-radius: 3px;
            box-shadow: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .density-ultra .week-doctor-card,
        .density-ultra .week-gcal-card,
        .density-ultra .week-event-card {
            padding: 1px 4px;
            border-radius: 3px;
            border-left-width: 2px;
            box-shadow: none;
        }
        .density-ultra .wec-header {
            font-size: 0.62rem;
            gap: 2px;
        }
        .density-ultra .wec-time {
            font-size: 0.62rem;
        }
        .density-ultra .doctor-badge-tag,
        .density-ultra .gcal-cal-tag {
            font-size: 0.56rem;
            padding: 0 3px;
            line-height: 1.1;
        }
        .density-ultra .wec-title {
            font-size: 0.68rem;
            line-height: 1.15;
            margin-top: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .density-ultra .wec-loc,
        .density-ultra .wec-memo {
            display: none;
        }

        /* 一覧モードの限界圧縮 */
        .density-ultra .post-list {
            gap: 3px;
        }
        .density-ultra .post-card {
            padding: 5px 8px;
            border-radius: 4px;
        }
        .density-ultra .post-title {
            font-size: 0.86rem;
            margin-bottom: 1px;
        }
        .density-ultra .post-meta {
            font-size: 0.70rem;
            gap: 6px;
            margin-bottom: 2px;
        }
        .density-ultra .post-body-preview {
            font-size: 0.76rem;
            line-height: 1.25;
            margin-bottom: 3px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .density-ultra .read-action-bar {
            padding: 2px 6px;
            margin-bottom: 3px;
        }

        /* レスポンシブ最適化 */
        @media (max-width: 900px) {
            .week-grid-7cols { grid-template-columns: repeat(2, 1fr); }
            .week-span-grid { display: none; }
        }
        @media (max-width: 640px) {
            header { padding: 0.5rem 0.8rem; }
            .header-logo-text { font-size: 1rem; }
            .week-grid-7cols { grid-template-columns: 1fr; }
            .desktop-filter-pills { display: none !important; }
            .search-input { width: 100%; }
            .search-form-wrap { width: 100%; }
            .search-input-box { width: 100%; }
        }
    </style>
</head>
<body>

    <!-- 🌟 ヘッダー -->
    <header>
        <div class="header-container">
            <div class="header-left">
                <a href="kawara_list.php" class="header-logo-link">
                    <span class="header-logo-icon">📜</span>
                    <span class="header-logo-text">院内かわら版</span>
                </a>
            </div>

            <div class="header-right">
                <!-- 🏠 メインメニューへ -->
                <a href="index.php" class="header-nav-btn btn-nav-menu" title="かわら版メニューへ">
                    🏠 メニュー
                </a>

                <!-- 👔 事務長モード（管理者と山本太のみ表示！分かりやすい場所に独立配置） -->
                <?php if ($can_see_jimucho): ?>
                    <a href="jimucho_dashboard.php" class="header-nav-btn btn-nav-jimucho" title="事務長用ダッシュボードへ">
                        👔 事務長モード
                    </a>
                <?php endif; ?>

                <!-- 👤 ユーザー名＆切替 -->
                <a href="login.php?switch_user=1" class="header-nav-btn btn-nav-user" title="クリックしてユーザー切替">
                    👤 <?= htmlspecialchars($login_user['staff_name']) ?>
                    <span class="badge-switch">切替</span>
                </a>

                <!-- 📱 LINE連携バッジ -->
                <button type="button" class="btn-line-header <?= $has_line_id ? 'is-linked' : 'is-unlinked' ?>" onclick="openLineLinkModal()" title="LINE連携設定">
                    <?= $has_line_id ? '🟢 LINE済' : '📱 LINE未' ?>
                </button>

                <!-- ☰ その他メニュー ドロップダウン -->
                <div class="header-dropdown" id="headerDropdown">
                    <button type="button" class="header-nav-btn" onclick="toggleHeaderDropdown(event)">
                        ☰ その他 ▼
                    </button>
                    <div class="dropdown-menu" id="dropdownMenu">
                        <a href="safety_contacts.php" class="dropdown-item">🛡️ 連絡網・安否確認</a>
                        <a href="/index.php" class="dropdown-item">🏥 院内ポータル</a>
                        <a href="help.php" class="dropdown-item" target="_blank">❓ 使い方ガイド</a>
                        <?php if ($is_admin): ?>
                            <div class="dropdown-divider"></div>
                            <a href="master_mente.php" class="dropdown-item text-admin">⚙️ システムマスタ管理</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main>
        <!-- セッション通知メッセージ -->
        <?php if ($notice_msg): ?>
            <div class="alert-notice"><?= htmlspecialchars($notice_msg) ?></div>
        <?php endif; ?>

        <!-- 🛡️ BCP安否確認バナー -->
        <?php if ($safety_mode !== 'normal' && !$my_safety_reported_today): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; border-left:5px solid #dc2626; padding:8px 12px; border-radius:6px; margin-bottom:0.8rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span style="font-size:0.84rem; color:#b91c1c; font-weight:bold;">
                    🚨 【安否確認】本日の生存報告が未報告です
                </span>
                <a href="safety_contacts.php" style="background:#dc2626; color:#fff; font-size:0.78rem; font-weight:bold; padding:4px 10px; border-radius:4px; text-decoration:none;">
                    1クリック報告 →
                </a>
            </div>
        <?php endif; ?>

        <!-- 🩺 医師予定表 申し送りメモ欄（アコーディオンバナー） -->
        <?php if (!empty($doctor_memo) && !empty($doctor_memo['content'])): ?>
            <div class="doctor-memo-banner" id="doctorMemoBanner">
                <div class="doctor-memo-header" onclick="toggleDoctorMemo()">
                    <div class="doctor-memo-title">
                        <span class="doctor-memo-icon">🩺</span>
                        <span>医師予定表 申し送りメモ</span>
                        <?php if (!empty($doctor_memo['updated_at'])): ?>
                            <span class="doctor-memo-date">(<?= date('n/j H:i', strtotime($doctor_memo['updated_at'])) ?> 更新)</span>
                        <?php endif; ?>
                    </div>
                    <span class="doctor-memo-arrow" id="doctorMemoArrow">▼ 開く</span>
                </div>
                <div class="doctor-memo-body" id="doctorMemoBody">
                    <?= nl2br(htmlspecialchars($doctor_memo['content'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ツールバー ＆ フィルターセクション -->
        <div class="filter-section">
            <div class="toolbar-group">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <a href="create_post.php" class="btn-create">✏️ 新規投稿</a>

                    <!-- 🌟 2大表示モード切替（一覧 ⇄ 2週カード） -->
                    <div class="view-mode-switch">
                        <a href="<?= $build_url(null, null, 1, null, 'list') ?>" class="btn-mode-tab <?= $view_mode === 'list' ? 'active' : '' ?>">
                            📋 一覧
                        </a>
                        <a href="<?= $build_url(null, null, 1, null, '2weeks') ?>" class="btn-mode-tab <?= $view_mode === '2weeks' ? 'active' : '' ?>">
                            📅 2週カード
                        </a>
                    </div>

                    <!-- 🗜️ 縦表示密度切替（標準圧縮 ⇄ 限界圧縮） -->
                    <button type="button" class="btn-density-toggle" onclick="toggleDensityMode()" title="縦方向を極限まで圧縮して1画面に収めるモードを切り替えます">
                        <span class="density-icon">🗜️</span> <span class="density-label">限界圧縮</span>
                    </button>

                    <?php if ($view_mode === 'list'): ?>
                        <button type="button" class="btn-print-top" onclick="window.print()">
                            🖨️ 印刷
                        </button>
                    <?php endif; ?>
                </div>

                <!-- 🔍 キーワード検索フォーム -->
                <form method="GET" action="kawara_list.php" class="search-form-wrap">
                    <?php if ($view_mode !== 'list'): ?><input type="hidden" name="mode" value="<?= htmlspecialchars($view_mode) ?>"><?php endif; ?>
                    <?php if ($date_filter !== 'all'): ?><input type="hidden" name="date_filter" value="<?= htmlspecialchars($date_filter) ?>"><?php endif; ?>
                    <?php if ($selected_cat > 0): ?><input type="hidden" name="cat" value="<?= $selected_cat ?>"><?php endif; ?>
                    <div class="search-input-box">
                        <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="🔍 キーワード検索..." class="search-input">
                        <?php if ($search_query !== ''): ?>
                            <a href="<?= $build_url(null, null, 1, '') ?>" class="btn-clear-q" title="検索クリア">✕</a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn-search">検索</button>
                </form>

                <?php if ($view_mode === 'list'): ?>
                    <!-- 💻 PC用 期間フィルター -->
                    <div class="date-filter-group desktop-filter-pills">
                        <a href="<?= $build_url('all') ?>" class="btn-date <?= $date_filter === 'all' ? 'active' : '' ?>">全期間</a>
                        <a href="<?= $build_url('today') ?>" class="btn-date <?= $date_filter === 'today' ? 'active' : '' ?>">今日</a>
                        <a href="<?= $build_url('tomorrow') ?>" class="btn-date <?= $date_filter === 'tomorrow' ? 'active' : '' ?>">明日</a>
                        <a href="<?= $build_url('plus7') ?>" class="btn-date <?= $date_filter === 'plus7' ? 'active' : '' ?>">+7日</a>
                        <a href="<?= $build_url('this_month') ?>" class="btn-date <?= $date_filter === 'this_month' ? 'active' : '' ?>">今月</a>
                        <a href="<?= $build_url('next_month') ?>" class="btn-date <?= $date_filter === 'next_month' ? 'active' : '' ?>">来月</a>
                        <span style="color:#cbd5e1; margin:0 2px;">|</span>
                        <a href="<?= $build_url('past') ?>" class="btn-date <?= $date_filter === 'past' ? 'active' : '' ?>" style="<?= $date_filter === 'past' ? 'background:#64748b; color:#fff;' : '' ?>">📁 過去分</a>
                        <a href="<?= $build_url('all_history') ?>" class="btn-date <?= $date_filter === 'all_history' ? 'active' : '' ?>" style="<?= $date_filter === 'all_history' ? 'background:#0284c7; color:#fff;' : '' ?>">🌐 全履歴</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($view_mode === 'list'): ?>
                <!-- 💻 PC用 カテゴリタブ -->
                <div class="cat-tabs desktop-filter-pills">
                    <a href="<?= $build_url(null, 0) ?>" class="cat-tab <?= $selected_cat === 0 ? 'active' : '' ?>">全て</a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= $build_url(null, $cat['category_id']) ?>" class="cat-tab <?= $selected_cat == $cat['category_id'] ? 'active' : '' ?>">
                            <?= $cat['icon_emoji'] ?> <?= htmlspecialchars($cat['category_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==========================================
             メインコンテンツの切り替え
             ========================================== -->
        <?php if ($view_mode === '2weeks'): ?>
            <!-- 🌟 2週間カード表示（7列×2週 グリッド） -->
            <div class="week-view-wrapper">
                <!-- 週間ナビゲーションバー -->
                <div class="week-navbar">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <a href="?mode=2weeks&date=<?= $prev_2weeks_date ?>" class="cal-nav-btn">
                            ◀ 前2週
                        </a>
                        <div class="week-nav-title">
                            📅 <?= date('Y年n月j日', $week_monday_ts) ?>(月) 〜 <?= date('n月j日', $period_sunday_ts) ?>(日)
                            <span style="font-size:0.78rem; color:#0284c7; background:#e0f2fe; padding:2px 8px; border-radius:12px; margin-left:6px; font-weight:800;">2週間カード</span>
                        </div>
                        <a href="?mode=2weeks&date=<?= $next_2weeks_date ?>" class="cal-nav-btn">
                            翌2週 ▶
                        </a>
                        <a href="?mode=2weeks&date=<?= $today_str ?>" class="cal-nav-btn" style="background:#e0f2fe; color:#0369a1;">
                            今週へ
                        </a>

                        <?php if (!empty($gcal_channels)): ?>
                            <button type="button" class="btn-gcal-sync" id="btnGcalSync" onclick="syncGoogleCalendar()">
                                <span id="gcalSyncIcon">🔄</span> Google同期
                            </button>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:0.8rem; color:#64748b;">💡 クリックで詳細</span>
                    </div>
                </div>

                <!-- 📌 今月の重点目標・重要メモ（2週間カレンダー用） -->
                <div class="month-note-banner" 
                     data-note-type="month"
                     data-note-key="<?= htmlspecialchars($cur_month_key, ENT_QUOTES) ?>"
                     data-note-content="<?= htmlspecialchars($month_note ?? '', ENT_QUOTES) ?>"
                     data-note-label="<?= htmlspecialchars($cur_month_label, ENT_QUOTES) ?>"
                     onclick="openNoteModal(this, null, null, null, event)">
                    <div class="month-note-header">
                        <span class="month-note-title">
                            <span>📌</span> <?= htmlspecialchars($cur_month_label) ?>の重点目標・重要メモ
                        </span>
                        <button type="button" class="btn-note-edit" 
                                data-note-type="month"
                                data-note-key="<?= htmlspecialchars($cur_month_key, ENT_QUOTES) ?>"
                                data-note-content="<?= htmlspecialchars($month_note ?? '', ENT_QUOTES) ?>"
                                data-note-label="<?= htmlspecialchars($cur_month_label, ENT_QUOTES) ?>"
                                onclick="event.stopPropagation(); openNoteModal(this, null, null, null, event);">
                            ✏️ 編集
                        </button>
                    </div>
                    <div class="month-note-body">
                        <?php if (!empty($month_note)): ?>
                            <div class="month-note-text"><?= nl2br(htmlspecialchars($month_note)) ?></div>
                        <?php else: ?>
                            <div class="month-note-placeholder">＋ 今月の重点目標・重要メモを追加（例：10月内視鏡システム最終レビュー、ISO更新審査対応）</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($gcal_channels)): ?>
                    <!-- Googleカレンダー表示切り替えバー -->
                    <div class="gcal-toggle-bar">
                        <span class="gcal-toggle-label">📅 Googleカレンダー:</span>
                        <div class="gcal-toggle-group">
                            <?php foreach ($gcal_channels as $ch): ?>
                                <label class="gcal-toggle-pill">
                                    <input type="checkbox" class="gcal-channel-chk" checked onchange="toggleGcalChannel(<?= $ch['channel_id'] ?>, this.checked)">
                                    <span class="gcal-toggle-dot" style="background: <?= htmlspecialchars($ch['color_theme']) ?>;"></span>
                                    <span><?= htmlspecialchars($ch['calendar_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 週グループ展開（第1週・第2週） -->
                <?php foreach ($week_groups as $w_idx => $w_days): 
                    $w_span_events = $week_span_events[$w_idx] ?? [];
                ?>
                    <div class="week-block-header">
                        <span>🗓️ 第<?= $w_idx + 1 ?>週：<?= date('Y/n/j', $w_days[0]['ts']) ?>(月) 〜 <?= date('n/j', $w_days[6]['ts']) ?>(日)</span>
                        <span style="font-size:0.76rem; font-weight:normal; color:#64748b;">7日間</span>
                    </div>

                    <?php if (!empty($w_span_events)): ?>
                        <!-- Googleカレンダー 終日・複数日 帯レーン -->
                        <div class="week-span-container">
                            <div class="week-span-grid">
                                <?php foreach ($w_span_events as $gev): ?>
                                    <div class="week-span-bar gcal-item gcal-ch-<?= $gev['channel_id'] ?>"
                                         style="grid-column: <?= $gev['start_col'] ?> / span <?= $gev['col_span'] ?>; background-color: <?= htmlspecialchars($gev['color_theme']) ?>;"
                                         onclick='openGcalDetailModal(<?= json_encode($gev, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                         title="<?= htmlspecialchars($gev['title']) ?> (<?= htmlspecialchars($gev['calendar_name']) ?>)">
                                        <span>🗓️</span>
                                        <span><?= htmlspecialchars($gev['title']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 7列カードグリッド (月曜〜日曜) -->
                    <div class="week-grid-7cols">
                        <?php foreach ($w_days as $wd): 
                            $duty = $wd['duty_info'];
                            $has_absence_warn = ($duty['is_closed'] && count($wd['events']) > 0);
                        ?>
                            <div class="week-day-col <?= $wd['is_today'] ? 'is-today' : '' ?>">
                                <!-- カラムヘッダー -->
                                <div class="week-day-header">
                                    <div class="week-day-header-top">
                                        <div class="week-day-date">
                                            <span><?= $wd['month_num'] ?>/<?= $wd['day_num'] ?></span>
                                            <span style="font-size:0.82rem; color:<?= (int)date('w', $wd['ts']) === 0 ? '#dc2626' : ((int)date('w', $wd['ts']) === 6 ? '#7c3aed' : '#334155') ?>;">(<?= $wd['dow_text'] ?>)</span>
                                            <?php if ($wd['is_today']): ?>
                                                <span class="badge-today">今日</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:4px;">
                                            <button type="button" 
                                                    class="week-btn-note <?= !empty($wd['day_note']) ? 'has-note' : '' ?>" 
                                                    title="この日のメモを入力・編集"
                                                    data-note-type="date"
                                                    data-note-key="<?= htmlspecialchars($wd['date_str'], ENT_QUOTES) ?>"
                                                    data-note-content="<?= htmlspecialchars($wd['day_note'] ?? '', ENT_QUOTES) ?>"
                                                    data-note-label="<?= htmlspecialchars($wd['date_str'], ENT_QUOTES) ?>"
                                                    onclick="openNoteModal(this, null, null, null, event);">
                                                📝
                                            </button>
                                            <a href="create_post.php?date=<?= $wd['date_str'] ?>" class="week-btn-add" title="この日に新しい予定を登録">
                                                ＋
                                            </a>
                                        </div>
                                    </div>

                                    <!-- 六曜・二十四節気・月齢バッジ -->
                                    <?php 
                                        $trad = $wd['traditional'] ?? null;
                                        if ($trad):
                                            $rokuyo = $trad['rokuyo'];
                                            $r_class = 'rokuyo-default';
                                            if ($rokuyo === '大安') $r_class = 'rokuyo-taian';
                                            elseif ($rokuyo === '友引') $r_class = 'rokuyo-tomobiki';
                                            elseif ($rokuyo === '仏滅') $r_class = 'rokuyo-butsumetsu';
                                    ?>
                                        <div class="week-traditional-row">
                                            <span class="trad-badge trad-rokuyo <?= $r_class ?>" title="六曜: <?= htmlspecialchars($rokuyo) ?>">
                                                <?= htmlspecialchars($rokuyo) ?>
                                            </span>
                                            <?php if (!empty($trad['solar_term'])): ?>
                                                <span class="trad-badge trad-solar" title="二十四節気: <?= htmlspecialchars($trad['solar_term']) ?>">
                                                    🌿 <?= htmlspecialchars($trad['solar_term']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="trad-moon-mini" title="月齢 <?= $trad['moon_age'] ?>">
                                                <?= $trad['moon_phase_emoji'] ?><small><?= $trad['moon_age'] ?></small>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- 外来休診・午後休診バッジ -->
                                    <?php if (!empty($duty['badge_label'])): ?>
                                        <div class="week-day-badges">
                                            <span class="badge-duty badge-duty-<?= htmlspecialchars($duty['badge_type']) ?>">
                                                <?= htmlspecialchars($duty['badge_label']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- イベントリスト -->
                                <div class="week-day-body">
                                    <!-- 🌟 日付メモ（登録されている場合、最上部に付箋表示） -->
                                    <?php if (!empty($wd['day_note'])): ?>
                                        <div class="week-note-card" 
                                             data-note-type="date"
                                             data-note-key="<?= htmlspecialchars($wd['date_str'], ENT_QUOTES) ?>"
                                             data-note-content="<?= htmlspecialchars($wd['day_note'], ENT_QUOTES) ?>"
                                             data-note-label="<?= htmlspecialchars($wd['date_str'], ENT_QUOTES) ?>"
                                             onclick="openNoteModal(this, null, null, null, event);" 
                                             title="クリックしてメモを編集">
                                            <span style="font-size:0.85rem; flex-shrink:0;">📌</span>
                                            <span style="flex:1; word-break:break-word;"><?= nl2br(htmlspecialchars($wd['day_note'])) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- 🩺 医師予定表（休診・不在・出張・診察） -->
                                    <?php if (!empty($doctor_events_by_date[$wd['date_str']])): ?>
                                        <?php foreach ($doctor_events_by_date[$wd['date_str']] as $dev): 
                                            $d_doc = $dev['doctor'] ?? [];
                                            $d_is_absence = ($dev['event_type'] === 'absence');
                                            $d_time_text = $dev['is_all_day'] ? '終日' : (($dev['start_time'] ?? '') . (!empty($dev['end_time']) ? '〜' . $dev['end_time'] : ''));
                                            $d_card_border = $d_is_absence ? '#dc2626' : ($d_doc['department_color'] ?? '#0284c7');
                                            $d_bg = $d_is_absence ? '#fff5f5' : '#f0f9ff';
                                        ?>
                                            <div class="week-doctor-card"
                                                 style="border-left-color: <?= htmlspecialchars($d_card_border) ?>; background: <?= htmlspecialchars($d_bg) ?>;"
                                                 onclick='openDoctorDetailModal(<?= json_encode($dev, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                 title="🩺 <?= htmlspecialchars($dev['title']) ?> (<?= htmlspecialchars($d_doc['name'] ?? '') ?>)">
                                                <div class="wec-header">
                                                    <span><?= htmlspecialchars($dev['event_icon'] ?? ($d_is_absence ? '🔴' : '🩺')) ?></span>
                                                    <span class="wec-time" style="color:<?= $d_is_absence ? '#b91c1c' : '#0369a1' ?>;"><?= htmlspecialchars($d_time_text) ?></span>
                                                    <span class="doctor-badge-tag" style="background:<?= htmlspecialchars($d_doc['department_color'] ?? '#475569') ?>;">
                                                        <?= htmlspecialchars($d_doc['short_name'] ?? $d_doc['name'] ?? '医師') ?>
                                                    </span>
                                                </div>
                                                <div class="wec-title" style="color:<?= $d_is_absence ? '#991b1b' : '#0f172a' ?>;">
                                                    <?= htmlspecialchars($dev['title']) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <!-- 🗓️ Googleカレンダー 時間指定予定 -->
                                    <?php if (!empty($gcal_timed_events_by_date[$wd['date_str']])): ?>
                                        <?php foreach ($gcal_timed_events_by_date[$wd['date_str']] as $gte): ?>
                                            <div class="week-gcal-card gcal-item gcal-ch-<?= $gte['channel_id'] ?>"
                                                 style="border-left-color: <?= htmlspecialchars($gte['color_theme']) ?>;"
                                                 onclick='openGcalDetailModal(<?= json_encode($gte, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                 title="<?= htmlspecialchars($gte['title']) ?> (<?= htmlspecialchars($gte['calendar_name']) ?>)">
                                                <div class="wec-header">
                                                    <span>🗓️</span>
                                                    <span class="wec-time"><?= date('H:i', strtotime($gte['start_datetime'])) ?><?= !empty($gte['end_datetime']) ? '〜' . date('H:i', strtotime($gte['end_datetime'])) : '' ?></span>
                                                    <span class="gcal-cal-tag" style="background-color: <?= htmlspecialchars($gte['color_theme']) ?>;">
                                                        <?= htmlspecialchars($gte['calendar_name']) ?>
                                                    </span>
                                                </div>
                                                <div class="wec-title"><?= htmlspecialchars($gte['title']) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <!-- 📜 かわら版 記事イベントカード -->
                                    <?php 
                                        $has_any_events = !empty($wd['events']) || !empty($gcal_timed_events_by_date[$wd['date_str']]) || !empty($doctor_events_by_date[$wd['date_str']]);
                                    ?>
                                    <?php if (!$has_any_events): ?>
                                        <div class="empty-day-placeholder">
                                            <span style="font-size:1.2rem; opacity:0.35;">☕</span>
                                            <span>予定なし</span>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($wd['events'] as $ev): ?>
                                            <?php if ($ev['is_continuation']): ?>
                                                <!-- 2日目以降 (続) カード -->
                                                <div class="week-event-card week-event-card-continuation" onclick="location.href='view_post.php?id=<?= $ev['post_id'] ?>'">
                                                    <div class="wec-header">
                                                        <span class="wec-badge-cont">続</span>
                                                        <span class="wec-time"><?= $ev['start_time'] ?><?= !empty($ev['end_time']) ? '〜' . $ev['end_time'] : '' ?></span>
                                                    </div>
                                                    <div class="wec-title">
                                                        <?= htmlspecialchars($ev['title']) ?>
                                                        <span class="wec-slot-badge"><?= $ev['slot_badge'] ?></span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <!-- 初日 メインカード -->
                                                <div class="week-event-card" onclick="location.href='view_post.php?id=<?= $ev['post_id'] ?>'">
                                                    <div class="wec-header">
                                                        <span><?= htmlspecialchars($ev['icon']) ?></span>
                                                        <span class="wec-time"><?= $ev['start_time'] ?><?= !empty($ev['end_time']) ? '〜' . $ev['end_time'] : '' ?></span>
                                                    </div>
                                                    <div class="wec-title"><?= htmlspecialchars($ev['title']) ?></div>
                                                    <?php if ($ev['total_slots'] > 1): ?>
                                                        <div class="wec-slot-badge">全<?= $ev['total_slots'] ?>日程 (第1回)</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- 📋 一覧表示モード -->
            <!-- 🔍 キーワード検索中バナー -->
            <?php if ($search_query !== ''): ?>
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-left:4px solid var(--accent); padding:8px 12px; border-radius:6px; margin-bottom:0.8rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; font-size:0.84rem;">
                    <div style="color:#1e3a8a; font-weight:600;">
                        🔍 「<strong><?= htmlspecialchars($search_query) ?></strong>」の検索結果: <strong><?= $total_posts ?></strong> 件
                    </div>
                    <a href="<?= $build_url(null, null, 1, '') ?>" style="color:#0284c7; text-decoration:none; font-weight:700;">✕ 検索解除</a>
                </div>
            <?php endif; ?>

            <div class="post-list">
                <?php if (empty($posts)): ?>
                    <div style="text-align:center; padding: 3rem; background:#fff; border-radius:8px; color:#888; border:1px solid var(--border-color);">
                        <?= $date_filter === 'past' ? '過去の投稿はありません。' : '該当するお知らせや予定はありません。' ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $p): 
                        $p_lvl = $p['priority_level'];
                        $is_read = (bool)$p['is_my_read'];
                        $is_expired = !empty($p['display_until']) && strtotime($p['display_until']) < time();
                        $is_past_event = empty($p['display_until']) && !empty($p['target_datetime']) && strtotime($p['target_datetime']) < strtotime($today_str);
                        $card_class = "p-{$p_lvl} " . ($is_read ? 'is-read' : 'is-unread');
                        
                        $read_cnt   = $p['read_count'];
                        $total_cnt  = $p['total_targets'];
                        $unread_cnt = max(0, $total_cnt - $read_cnt);
                    ?>
                        <div class="post-card <?= $card_class ?>">
                            <!-- 未読パルス発光インジケーター -->
                            <?php if (!$is_read): ?>
                                <div class="unread-indicator" title="未読のお知らせです">
                                    <span class="unread-dot"></span>
                                    <span>未読</span>
                                </div>
                            <?php endif; ?>

                            <div class="post-header">
                                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                    <?php if ($is_expired): ?>
                                        <span class="badge" style="background:#64748b; color:#fff;">⏱️ 掲載終了</span>
                                    <?php elseif ($is_past_event): ?>
                                        <span class="badge" style="background:#64748b; color:#fff;">📜 過去の予定</span>
                                    <?php endif; ?>

                                    <?php if ($p_lvl === 'urgent' && !$is_expired && !$is_past_event): ?>
                                        <span class="badge badge-urgent">🚨 直近/緊急</span>
                                    <?php elseif ($p['is_within_24h'] && !$is_expired && !$is_past_event): ?>
                                        <span class="badge badge-24h">⏰ 24時間以内</span>
                                    <?php endif; ?>

                                    <?php if ($p['is_new_post']): ?>
                                        <span class="badge-new">NEW</span>
                                    <?php endif; ?>

                                    <?php if ($p['is_pinned']): ?>
                                        <span class="badge badge-pinned">📌 固定</span>
                                    <?php endif; ?>

                                    <span class="badge-cat" style="background-color: <?= $p['color_code'] ?? '#0284c7' ?>;">
                                        <?= $p['icon_emoji'] ?> <?= htmlspecialchars($p['category_name'] ?? '一般') ?>
                                    </span>
                                </div>
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
                                <span>👤 投稿者: <?= htmlspecialchars($p['author_name'] ?? '事務部') ?></span>
                                <span>📅 投稿: <?= date('n/j H:i', strtotime($p['created_at'])) ?></span>
                                <?php if ($p['image_count'] > 0): ?><span>🖼 画像: <?= $p['image_count'] ?>枚</span><?php endif; ?>
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
                                    
                                    <button type="submit" style="background:#10b981; color:white; border:none; padding:6px 14px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:0.84rem;">
                                        ✓ 内容を確認しました（既読を付ける）
                                    </button>

                                    <label style="font-size:0.8rem; color:#065f46; font-weight:bold; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                        <input type="checkbox" name="send_self_line" value="1" <?= $has_line_id ? 'checked' : '' ?> onclick="checkLineIdStatus(event, <?= $has_line_id ? 'true' : 'false' ?>)" style="accent-color:#10b981; width:15px; height:15px;">
                                        <span>📲 自分のLINE宛てにも本文メモを送信</span>
                                    </label>
                                </form>
                            <?php else: ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; background:#f1f5f9; padding:5px 12px; border-radius:6px; margin-bottom:8px; flex-wrap:wrap; gap:5px;">
                                    <span style="font-size:0.8rem; color:#475569; font-weight:bold;">🩵 このお知らせは確認済み（既読）です</span>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action_type" value="mark_unread">
                                        <input type="hidden" name="post_id" value="<?= $p['post_id'] ?>">
                                        <button type="submit" class="btn-unread-reset">↩️ 未読に戻す</button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <div class="toggle-bar">
                                <button type="button" class="toggle-btn" onclick="toggleAccordion('comments_<?= $p['post_id'] ?>', this)">
                                    💬 コメント (<?= $p['comment_count'] ?>件) ▼
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
                                <?php if (empty($comments)): ?>
                                    <div style="color:#888;">コメントはまだありません。</div>
                                <?php else: ?>
                                    <?php foreach ($comments as $cm): ?>
                                        <div style="border-bottom:1px dashed #e2e8f0; padding:4px 0; margin-bottom:4px;">
                                            <strong><?= htmlspecialchars($cm['staff_name'] ?? 'スタッフ') ?></strong>
                                            <span style="font-size:0.72rem; color:#888;"><?= date('n/j H:i', strtotime($cm['created_at'])) ?></span>:
                                            <?= htmlspecialchars($cm['comment_text'] ?? '') ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <form method="POST" style="margin-top:8px; display:flex; gap:6px;">
                                    <input type="hidden" name="action_type" value="add_comment">
                                    <input type="hidden" name="post_id" value="<?= $p['post_id'] ?>">
                                    <input type="text" name="comment_text" placeholder="コメントを入力..." style="flex:1; padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.8rem;">
                                    <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">送信</button>
                                </form>
                            </div>

                            <div id="reads_<?= $p['post_id'] ?>" class="accordion-content">
                                <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                    <?php foreach ($p['target_members'] as $tm): 
                                        $tm_read = in_array($tm['staff_id'], $p['read_staff_ids']);
                                    ?>
                                        <span style="font-size:0.72rem; padding:1px 6px; border-radius:3px; background:<?= $tm_read ? '#dcfce7' : '#f1f5f9' ?>; color:<?= $tm_read ? '#15803d' : '#94a3b8' ?>;">
                                            <?= $tm_read ? '✓' : '未' ?> <?= htmlspecialchars($tm['staff_name']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ページネーション -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div style="font-size:0.82rem; color:#64748b;">
                        合計 <?= $total_posts ?> 件中 <?= $offset + 1 ?>〜<?= min($offset + $per_page, $total_posts) ?> 件を表示
                    </div>
                    <div class="pagination">
                        <?php if ($current_page > 1): ?>
                            <a href="<?= $build_url(null, null, $current_page - 1) ?>" class="page-link">◀ 前へ</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <a href="<?= $build_url(null, null, $i) ?>" class="page-link <?= $i === $current_page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?= $build_url(null, null, $current_page + 1) ?>" class="page-link">次へ ▶</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- 🌟 モーダル群 -->

    <!-- 0. 📝 ダッシュボードメモ（月・日）編集モーダル -->
    <div class="modal-overlay" id="modal-dashboard-note" onclick="if(event.target===this) closeModal('modal-dashboard-note')">
        <div class="modal-card" style="max-width:520px;">
            <div class="modal-header" style="border-bottom: 2px solid #f59e0b;">
                <h3><span>📝</span> <span id="note-modal-title">メモの編集</span></h3>
                <button type="button" class="modal-close" onclick="closeModal('modal-dashboard-note')">×</button>
            </div>
            <div class="modal-body" style="display:flex; flex-direction:column; gap:12px;">
                <input type="hidden" id="note-modal-type" value="">
                <input type="hidden" id="note-modal-key" value="">
                
                <div style="font-size:0.86rem; color:#64748b;" id="note-modal-desc">
                    対象: <b id="note-modal-target-label" style="color:#0f172a;"></b>
                </div>

                <div>
                    <textarea id="note-modal-content" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px; font-size:0.92rem; line-height:1.5; box-sizing:border-box; resize:vertical; font-family:inherit;" placeholder="メモ内容を入力してください"></textarea>
                </div>
                <div style="font-size:0.75rem; color:#94a3b8;">
                    💡 空欄にして「保存」または「削除」を押すとメモが消去されます。一般スタッフを含め全員に共有されます。
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
                    <button type="button" onclick="clearDashboardNote()" style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; padding:6px 12px; border-radius:6px; font-size:0.82rem; font-weight:bold; cursor:pointer;">
                        🗑️ 削除
                    </button>
                    <div style="display:flex; gap:8px;">
                        <button type="button" onclick="closeModal('modal-dashboard-note')" style="background:#fff; border:1px solid #cbd5e1; padding:6px 14px; border-radius:6px; font-size:0.84rem; cursor:pointer; color:#475569;">
                            キャンセル
                        </button>
                        <button type="button" id="btn-save-note" onclick="saveDashboardNote()" style="background:#f59e0b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-size:0.84rem; font-weight:bold; cursor:pointer;">
                            💾 保存する
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 1. 医師予定詳細モーダル -->
    <div class="modal-overlay" id="modal-doctor-detail" onclick="if(event.target===this) closeModal('modal-doctor-detail')">
        <div class="modal-card">
            <div class="modal-header">
                <h3><span id="doc-detail-icon">🩺</span> <span id="doc-detail-title">医師予定詳細</span></h3>
                <button type="button" class="modal-close" onclick="closeModal('modal-doctor-detail')">×</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; gap:6px; align-items:center; margin-bottom:12px;">
                    <span id="doc-detail-badge" class="badge" style="background:#0284c7; color:#fff;">診察</span>
                    <span id="doc-detail-dept-badge" class="badge" style="background:#475569; color:#fff;">診療科</span>
                    <strong id="doc-detail-doctor-name" style="font-size:1.05rem; color:#0f172a;"></strong>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; margin-bottom:12px;">
                    <div style="font-size:0.78rem; color:#64748b;">日時</div>
                    <div id="doc-detail-time" style="font-size:0.95rem; font-weight:800; color:#0f172a;"></div>
                </div>
                <div style="margin-bottom:12px;">
                    <div style="font-size:0.78rem; color:#64748b;">予定内容</div>
                    <div id="doc-detail-event-title" style="font-size:1rem; font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div id="doc-detail-note-box" style="display:none; background:#fffbeb; border:1px solid #fef3c7; border-radius:6px; padding:10px; margin-bottom:12px;">
                    <div style="font-size:0.78rem; color:#b45309; font-weight:bold;">備考・申し送り</div>
                    <div id="doc-detail-note" style="font-size:0.88rem; color:#78350f; margin-top:4px; white-space:pre-wrap;"></div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0;">
                    <a id="doc-detail-add-my-cal" href="#" target="_blank" rel="noopener noreferrer" 
                       style="display:inline-flex; align-items:center; gap:6px; background:#1a73e8; color:#fff; padding:6px 14px; border-radius:6px; font-size:0.84rem; font-weight:bold; text-decoration:none; box-shadow:0 1px 3px rgba(0,0,0,0.15);">
                        <span>📅</span> 自分のGoogleカレンダーに登録
                    </a>
                    <a id="doc-detail-link" href="../yotei/calendar.php" target="_blank" rel="noopener noreferrer" style="font-size:0.82rem; color:#64748b; text-decoration:none; font-weight:600;">
                        医師予定表システムを開く →
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Googleカレンダー予定詳細モーダル -->
    <div class="modal-overlay" id="modal-gcal-detail" onclick="if(event.target===this) closeModal('modal-gcal-detail')">
        <div class="modal-card">
            <div class="modal-header">
                <h3><span>🗓️</span> <span id="gcal-detail-title">予定詳細</span></h3>
                <button type="button" class="modal-close" onclick="closeModal('modal-gcal-detail')">×</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; gap:6px; align-items:center; margin-bottom:12px;">
                    <span id="gcal-detail-cal-badge" class="badge" style="background:#1a73e8; color:#fff;">Google</span>
                    <span id="gcal-detail-acct" style="font-size:0.78rem; color:#64748b;"></span>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; margin-bottom:12px;">
                    <div style="font-size:0.78rem; color:#64748b;">日時</div>
                    <div id="gcal-detail-time" style="font-size:0.95rem; font-weight:800; color:#0f172a;"></div>
                </div>
                <div id="gcal-detail-loc-box" style="display:none; margin-bottom:12px;">
                    <div style="font-size:0.78rem; color:#64748b;">場所</div>
                    <div id="gcal-detail-location" style="font-size:0.9rem; font-weight:600; color:#0f172a;"></div>
                </div>
                <div id="gcal-detail-desc-box" style="display:none; margin-bottom:12px;">
                    <div style="font-size:0.78rem; color:#64748b;">説明</div>
                    <div id="gcal-detail-desc" style="font-size:0.86rem; color:#334155; white-space:pre-wrap; background:#f8fafc; padding:8px; border-radius:6px; border:1px solid #e2e8f0;"></div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0;">
                    <a id="gcal-detail-add-my-cal" href="#" target="_blank" rel="noopener noreferrer" 
                       style="display:inline-flex; align-items:center; gap:6px; background:#1a73e8; color:#fff; padding:6px 14px; border-radius:6px; font-size:0.84rem; font-weight:bold; text-decoration:none; box-shadow:0 1px 3px rgba(0,0,0,0.15);">
                        <span>📅</span> 自分のGoogleカレンダーに登録
                    </a>
                    <a id="gcal-detail-link" href="#" target="_blank" rel="noopener noreferrer" style="font-size:0.82rem; color:#64748b; text-decoration:none; font-weight:600;">
                        元の予定を開く →
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. 📱 LINE連携モーダル -->
    <div class="modal-overlay" id="lineLinkModal" onclick="if(event.target===this) closeLineLinkModal()">
        <div class="modal-card line-modal-card">
            <div class="modal-header" style="background:#06c755; color:#fff;">
                <h3 style="color:#fff;">📱 LINE公式アカウント連携</h3>
                <button type="button" class="modal-close" style="color:#fff;" onclick="closeLineLinkModal()">×</button>
            </div>
            <div class="modal-body">
                <div style="text-align:center; margin-bottom:14px;">
                    <div id="modalLineBadge" style="display:inline-block; font-size:0.8rem; font-weight:800; padding:3px 10px; border-radius:12px;">
                        確認中...
                    </div>
                </div>

                <div id="modalUnlinkedView">
                    <p style="font-size:0.86rem; color:#334155; margin-bottom:10px;">
                        院内かわら版公式LINEと友だち追加し、下の連携コードを送信してください。
                    </p>
                    <div class="link-code-digit" id="lineLinkCode">----</div>
                    <div style="text-align:center; margin-bottom:12px;">
                        <button type="button" onclick="copyLinkCode()" style="background:#fff; border:1px solid #cbd5e1; padding:4px 12px; border-radius:4px; font-size:0.8rem; font-weight:bold; cursor:pointer;">
                            📋 コードをコピー
                        </button>
                    </div>
                    <div style="text-align:center;">
                        <a id="btnLineAddFriend" href="#" target="_blank" style="background:#06c755; color:#fff; text-decoration:none; padding:8px 16px; border-radius:6px; font-weight:bold; font-size:0.88rem; display:inline-block;">
                            👉 LINEで友だち追加する
                        </a>
                    </div>
                </div>

                <div id="modalLinkedView" style="display:none; text-align:center; padding:10px 0;">
                    <div style="font-size:2.5rem; margin-bottom:6px;">✅</div>
                    <h4 style="color:#15803d; margin-bottom:6px;">LINE連携完了済み</h4>
                    <p style="font-size:0.84rem; color:#64748b; margin-bottom:14px;">
                        緊急連絡やBCP安否確認があなたのLINEへ届きます。<br>
                        登録ID: <span id="modalMaskedId" style="font-family:monospace; background:#e2e8f0; padding:2px 6px; border-radius:4px;"></span>
                    </p>
                    <button type="button" onclick="unlinkMyLine()" style="background:#fff; color:#dc2626; border:1px solid #fca5a5; padding:6px 12px; border-radius:6px; font-size:0.78rem; font-weight:bold; cursor:pointer;">
                        連携を解除する
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         JavaScript スクリプト
         ========================================== -->
    <script>
        // ☰ その他ドロップダウン開閉
        function toggleHeaderDropdown(e) {
            e.stopPropagation();
            const menu = document.getElementById('dropdownMenu');
            if (menu) {
                menu.classList.toggle('active');
            }
        }
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('dropdownMenu');
            if (menu && !menu.contains(e.target)) {
                menu.classList.remove('active');
            }
        });

        // 🩺 医師申し送りメモ アコーディオン開閉
        function toggleDoctorMemo() {
            const banner = document.getElementById('doctorMemoBanner');
            const arrow = document.getElementById('doctorMemoArrow');
            if (banner) {
                banner.classList.toggle('is-open');
                if (arrow) {
                    arrow.textContent = banner.classList.contains('is-open') ? '▲ 閉じる' : '▼ 開く';
                }
            }
        }

        // コメント・既読 アコーディオン開閉
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

        // モーダル共通制御
        function openModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.add('active');
        }
        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.remove('active');
        }

        // 📝 ダッシュボードメモ（月・日）の入力・編集
        function openNoteModal(typeOrEl, key, currentContent, label, ev) {
            if (ev && typeof ev.preventDefault === 'function') {
                ev.preventDefault();
                ev.stopPropagation();
            }
            if (window.event) {
                if (typeof window.event.preventDefault === 'function') window.event.preventDefault();
                if (typeof window.event.stopPropagation === 'function') window.event.stopPropagation();
            }

            let type = 'date';
            let targetKey = '';
            let content = '';
            let targetText = '';

            if (typeOrEl && typeof typeOrEl === 'object' && (typeOrEl.dataset || typeOrEl.getAttribute)) {
                type = typeOrEl.dataset?.noteType || typeOrEl.getAttribute('data-note-type') || 'date';
                targetKey = typeOrEl.dataset?.noteKey || typeOrEl.getAttribute('data-note-key') || '';
                content = typeOrEl.dataset?.noteContent || typeOrEl.getAttribute('data-note-content') || '';
                targetText = typeOrEl.dataset?.noteLabel || typeOrEl.getAttribute('data-note-label') || targetKey;
            } else {
                type = typeOrEl || 'date';
                targetKey = key || '';
                content = currentContent || '';
                targetText = label || targetKey;
            }

            const typeInput = document.getElementById('note-modal-type');
            const keyInput = document.getElementById('note-modal-key');
            const contentArea = document.getElementById('note-modal-content');
            const titleEl = document.getElementById('note-modal-title');
            const targetLabel = document.getElementById('note-modal-target-label');

            if (!typeInput || !keyInput || !contentArea) return;

            typeInput.value = type;
            keyInput.value = targetKey;
            contentArea.value = content || '';

            if (type === 'month') {
                if (titleEl) titleEl.textContent = '📌 今月の重点目標・重要メモ';
                if (targetLabel) targetLabel.textContent = targetText ? `${targetText} の重点メモ` : targetKey;
                contentArea.placeholder = '例：10月内視鏡システム最終レビュー、ISO更新審査、新電子カルテ導入説明会';
            } else {
                if (titleEl) titleEl.textContent = '📝 日付メモの編集';
                if (targetLabel) targetLabel.textContent = targetText ? `${targetText} のメモ` : targetKey;
                contentArea.placeholder = '例：午前中に消防署立ち入り検査、薬品棚卸し、医師ミーティングなど';
            }

            openModal('modal-dashboard-note');
            setTimeout(() => {
                contentArea.focus();
            }, 150);
        }

        function clearDashboardNote() {
            if (!confirm('このメモを削除しますか？')) return;
            document.getElementById('note-modal-content').value = '';
            saveDashboardNote();
        }

        function saveDashboardNote() {
            const type = document.getElementById('note-modal-type').value;
            const key = document.getElementById('note-modal-key').value;
            const content = document.getElementById('note-modal-content').value.trim();
            const btn = document.getElementById('btn-save-note');
            
            if (btn) btn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'save_dashboard_note');
            formData.append('target_type', type);
            formData.append('target_key', key);
            formData.append('content', content);
            
            fetch('kawara_list.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeModal('modal-dashboard-note');
                    location.reload();
                } else {
                    if (btn) btn.disabled = false;
                    alert('⚠️ メモ保存エラー: ' + (data.error || '不明なエラー'));
                }
            })
            .catch(err => {
                if (btn) btn.disabled = false;
                alert('⚠️ 通信エラー: ' + err.message);
            });
        }

        // 🩺 医師予定詳細モーダル
        function openDoctorDetailModal(ev) {
            if (!ev) return;
            const doc = ev.doctor || {};
            const isAbsence = (ev.event_type === 'absence');

            document.getElementById('doc-detail-icon').textContent = ev.event_icon || (isAbsence ? '🔴' : '🩺');
            document.getElementById('doc-detail-title').textContent = (doc.name || '医師') + ' の予定詳細';

            const badge = document.getElementById('doc-detail-badge');
            badge.textContent = ev.event_type_label || (isAbsence ? '休診・不在' : '診察予定');
            badge.style.backgroundColor = isAbsence ? '#dc2626' : '#0284c7';

            const deptBadge = document.getElementById('doc-detail-dept-badge');
            deptBadge.textContent = doc.department_name || '診療科';
            deptBadge.style.backgroundColor = doc.department_color || '#475569';

            document.getElementById('doc-detail-doctor-name').textContent = (doc.name || '') + (doc.title ? ' ' + doc.title : '');

            let timeStr = '';
            const dateStr = (ev.date || ev.start_date || '').replace(/-/g, '/');
            if (ev.is_all_day) {
                timeStr = `${dateStr} 終日`;
            } else {
                const sTime = ev.start_time || '';
                const eTime = ev.end_time || '';
                timeStr = `${dateStr} ${sTime}${eTime ? ' 〜 ' + eTime : ''}`;
            }
            document.getElementById('doc-detail-time').textContent = timeStr;
            document.getElementById('doc-detail-event-title').textContent = ev.title || '(無題)';

            const noteBox = document.getElementById('doc-detail-note-box');
            const noteEl = document.getElementById('doc-detail-note');
            if (ev.note && ev.note.trim() !== '') {
                noteEl.textContent = ev.note;
                noteBox.style.display = 'block';
            } else {
                noteBox.style.display = 'none';
            }

            const linkBtn = document.getElementById('doc-detail-link');
            const yStr = (ev.date || ev.start_date || '').substring(0, 4);
            const mStr = parseInt((ev.date || ev.start_date || '').substring(5, 7), 10) || '';
            linkBtn.href = `../yotei/calendar.php?year=${yStr}&month=${mStr}`;

            // 📅 自分のGoogleカレンダーに登録リンク生成
            const addDocCalBtn = document.getElementById('doc-detail-add-my-cal');
            if (addDocCalBtn) {
                const docFullName = (doc.name || '') + (doc.title ? ' ' + doc.title : '');
                const docTitle = docFullName + ' - ' + (ev.title || '医師予定');
                const docDate = ev.date || ev.start_date || '';
                const docAllDay = !!ev.is_all_day;
                let startDt = docDate;
                let endDt = null;
                if (!docAllDay && ev.start_time) {
                    startDt = `${docDate} ${ev.start_time}:00`;
                    if (ev.end_time) endDt = `${docDate} ${ev.end_time}:00`;
                }
                const docDtl = `【医師予定表】${docFullName}\n科: ${doc.department_name || ''}\n${ev.note ? '備考: ' + ev.note : ''}`;
                addDocCalBtn.href = generateGoogleCalendarUrl({
                    title: docTitle,
                    startDatetime: startDt,
                    endDatetime: endDt,
                    isAllDay: docAllDay,
                    details: docDtl,
                    location: '小野寺病院'
                });
            }

            openModal('modal-doctor-detail');
        }

        // 🗓️ Googleカレンダー予定詳細モーダル
        function openGcalDetailModal(ev) {
            if (!ev) return;
            document.getElementById('gcal-detail-title').textContent = ev.title || '(無題の予定)';
            
            const badge = document.getElementById('gcal-detail-cal-badge');
            badge.textContent = ev.calendar_name || 'Googleカレンダー';
            badge.style.backgroundColor = ev.color_theme || '#1a73e8';

            const acct = document.getElementById('gcal-detail-acct');
            acct.textContent = ev.account_name ? `(${ev.account_name})` : '';

            let timeStr = '';
            const sDate = ev.start_datetime ? ev.start_datetime.substring(0, 10).replace(/-/g, '/') : '';
            const eDate = ev.end_datetime ? ev.end_datetime.substring(0, 10).replace(/-/g, '/') : '';
            const isAllDay = (ev.is_all_day == 1 || ev.is_all_day === true || ev.is_all_day === 'true');

            if (isAllDay) {
                timeStr = (sDate === eDate || !eDate) ? `${sDate} 終日` : `${sDate} 〜 ${eDate} 終日`;
            } else {
                const sTime = ev.start_datetime ? ev.start_datetime.substring(11, 16) : '';
                const eTime = ev.end_datetime ? ev.end_datetime.substring(11, 16) : '';
                timeStr = (sDate === eDate) ? `${sDate} ${sTime} 〜 ${eTime}` : `${sDate} ${sTime} 〜 ${eDate} ${eTime}`;
            }
            document.getElementById('gcal-detail-time').textContent = timeStr;

            const locBox = document.getElementById('gcal-detail-loc-box');
            const locEl = document.getElementById('gcal-detail-location');
            if (ev.location && ev.location.trim() !== '') {
                locEl.textContent = ev.location;
                locBox.style.display = 'block';
            } else {
                locBox.style.display = 'none';
            }

            const descBox = document.getElementById('gcal-detail-desc-box');
            const descEl = document.getElementById('gcal-detail-desc');
            if (ev.description && ev.description.trim() !== '') {
                descEl.textContent = ev.description;
                descBox.style.display = 'block';
            } else {
                descBox.style.display = 'none';
            }

            const linkBtn = document.getElementById('gcal-detail-link');
            if (ev.html_link) {
                linkBtn.href = ev.html_link;
                linkBtn.style.display = 'inline-flex';
            } else {
                linkBtn.style.display = 'none';
            }

            // 📅 自分のGoogleカレンダーに登録リンク生成
            const addGcalBtn = document.getElementById('gcal-detail-add-my-cal');
            if (addGcalBtn) {
                let dtl = ev.description || '';
                if (ev.account_name || ev.calendar_name) {
                    dtl = `【${ev.calendar_name || 'Google予定'} (${ev.account_name || ''})】\n` + dtl;
                }
                addGcalBtn.href = generateGoogleCalendarUrl({
                    title: ev.title || '予定',
                    startDatetime: ev.start_datetime,
                    endDatetime: ev.end_datetime,
                    isAllDay: isAllDay,
                    details: dtl.trim(),
                    location: ev.location || ''
                });
            }

            openModal('modal-gcal-detail');
        }

        // 📅 自分のGoogleカレンダーに登録用URL生成ヘルパー
        function generateGoogleCalendarUrl(options) {
            const { title, startDatetime, endDatetime, isAllDay, details, location } = options;
            if (!title || !startDatetime) return '#';

            const cleanStart = String(startDatetime).trim();
            const cleanEnd = endDatetime ? String(endDatetime).trim() : '';

            let datesStr = '';
            if (isAllDay) {
                const sYmd = cleanStart.substring(0, 10).replace(/[^0-9]/g, '');
                let eDateObj;
                if (cleanEnd) {
                    const eYmd = cleanEnd.substring(0, 10);
                    eDateObj = new Date(eYmd + 'T00:00:00');
                } else {
                    const sYmdHyphen = cleanStart.substring(0, 10);
                    eDateObj = new Date(sYmdHyphen + 'T00:00:00');
                }
                eDateObj.setDate(eDateObj.getDate() + 1);
                const eY = eDateObj.getFullYear();
                const eM = String(eDateObj.getMonth() + 1).padStart(2, '0');
                const eD = String(eDateObj.getDate()).padStart(2, '0');
                datesStr = `${sYmd}/${eY}${eM}${eD}`;
            } else {
                const parseToGcalTime = (dtStr) => {
                    const d = new Date(dtStr.replace(' ', 'T'));
                    if (isNaN(d.getTime())) return '';
                    const y = d.getFullYear();
                    const m = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    const h = String(d.getHours()).padStart(2, '0');
                    const min = String(d.getMinutes()).padStart(2, '0');
                    const s = String(d.getSeconds()).padStart(2, '0');
                    return `${y}${m}${day}T${h}${min}${s}`;
                };
                const sFormatted = parseToGcalTime(cleanStart);
                let eFormatted = cleanEnd ? parseToGcalTime(cleanEnd) : '';
                if (!eFormatted && sFormatted) {
                    const d = new Date(cleanStart.replace(' ', 'T'));
                    d.setHours(d.getHours() + 1);
                    const y = d.getFullYear();
                    const m = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    const h = String(d.getHours()).padStart(2, '0');
                    const min = String(d.getMinutes()).padStart(2, '0');
                    const s = String(d.getSeconds()).padStart(2, '0');
                    eFormatted = `${y}${m}${day}T${h}${min}${s}`;
                }
                datesStr = `${sFormatted}/${eFormatted}`;
            }

            const params = new URLSearchParams();
            params.set('action', 'TEMPLATE');
            params.set('text', title);
            params.set('dates', datesStr);
            params.set('ctz', 'Asia/Tokyo');
            if (details) params.set('details', details);
            if (location) params.set('location', location);

            return 'https://calendar.google.com/calendar/render?' + params.toString();
        }


        // Googleカレンダー チャンネルトグル
        function toggleGcalChannel(channelId, isChecked) {
            const items = document.querySelectorAll(`.gcal-ch-${channelId}`);
            items.forEach(el => {
                el.style.display = isChecked ? '' : 'none';
            });
        }

        // Googleカレンダー手動同期
        function syncGoogleCalendar() {
            const btn = document.getElementById('btnGcalSync');
            const icon = document.getElementById('gcalSyncIcon');
            if (btn) btn.disabled = true;
            if (icon) icon.textContent = '⏳';

            fetch('kawara_list.php?action=sync_gcal')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || '同期が完了しました');
                        location.reload();
                    } else {
                        if (btn) btn.disabled = false;
                        if (icon) icon.textContent = '🔄';
                        alert('⚠️ 同期エラー: ' + (data.message || ''));
                    }
                })
                .catch(err => {
                    if (btn) btn.disabled = false;
                    if (icon) icon.textContent = '🔄';
                    alert('通信エラーが発生しました: ' + err.message);
                });
        }

        // ==========================================
        // 📱 LINE連携モーダル制御
        // ==========================================
        let linePollingTimer = null;
        async function openLineLinkModal() {
            openModal('lineLinkModal');
            await refreshLineStatus();
        }
        function closeLineLinkModal() {
            closeModal('lineLinkModal');
            if (linePollingTimer) clearInterval(linePollingTimer);
        }
        async function refreshLineStatus() {
            try {
                const res = await fetch('api/line_link_status.php?action=status', { cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;

                const badge = document.getElementById('modalLineBadge');
                const unlinkedView = document.getElementById('modalUnlinkedView');
                const linkedView = document.getElementById('modalLinkedView');
                const maskedIdEl = document.getElementById('modalMaskedId');
                const btnFriend = document.getElementById('btnLineAddFriend');

                if (data.bot_add_url && btnFriend) {
                    btnFriend.href = data.bot_add_url;
                }

                if (data.is_linked) {
                    badge.textContent = '🟢 連携中';
                    badge.style.background = '#dcfce7';
                    badge.style.color = '#15803d';
                    unlinkedView.style.display = 'none';
                    linkedView.style.display = 'block';
                    maskedIdEl.textContent = data.line_user_id_mask;
                    if (linePollingTimer) clearInterval(linePollingTimer);
                } else {
                    badge.textContent = '⚠️ 未連携';
                    badge.style.background = '#fef3c7';
                    badge.style.color = '#b45309';
                    unlinkedView.style.display = 'block';
                    linkedView.style.display = 'none';

                    if (data.active_code) {
                        document.getElementById('lineLinkCode').textContent = data.active_code;
                    } else {
                        const codeRes = await fetch('api/line_link_status.php?action=generate_code', { method: 'POST' });
                        const codeData = await codeRes.json();
                        if (codeData.success) {
                            document.getElementById('lineLinkCode').textContent = codeData.link_code;
                        }
                    }

                    if (!linePollingTimer) {
                        linePollingTimer = setInterval(refreshLineStatus, 4000);
                    }
                }
            } catch (e) {
                console.error(e);
            }
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
            if (!confirm('LINE連携を解除しますか？')) return;
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

        function checkLineIdStatus(e, hasLine) {
            if (e.target.checked && !hasLine) {
                if (confirm("LINE IDがまだ登録されていません。\n今すぐLINE連携を設定しますか？")) {
                    openLineLinkModal();
                }
                e.target.checked = false;
            }
        }

        // 🗜️ 縦表示密度（限界圧縮モード）切り替え
        function toggleDensityMode() {
            const isUltra = document.documentElement.classList.toggle('density-ultra');
            try {
                localStorage.setItem('kawara_density_mode', isUltra ? 'ultra' : 'normal');
            } catch (e) {}
            syncDensityButtonUI(isUltra);
        }

        function syncDensityButtonUI(isUltra) {
            if (isUltra === undefined) {
                isUltra = document.documentElement.classList.contains('density-ultra');
            }
            const btns = document.querySelectorAll('.btn-density-toggle');
            btns.forEach(btn => {
                const icon = btn.querySelector('.density-icon');
                const label = btn.querySelector('.density-label');
                if (isUltra) {
                    btn.classList.add('is-active');
                    if (icon) icon.textContent = '📐';
                    if (label) label.textContent = '標準圧縮に戻す';
                    btn.setAttribute('title', '標準の圧縮表示に戻します');
                } else {
                    btn.classList.remove('is-active');
                    if (icon) icon.textContent = '🗜️';
                    if (label) label.textContent = '限界圧縮';
                    btn.setAttribute('title', '縦方向を極限まで圧縮して1画面に収めるモードに切り替えます');
                }
            });
        }
        document.addEventListener('DOMContentLoaded', () => {
            syncDensityButtonUI();
        });
    </script>
</body>
</html>
