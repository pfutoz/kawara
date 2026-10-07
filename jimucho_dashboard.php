<?php
require_once __DIR__ . '/includes/auth_helper.php';

// 2. DB接続
$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}

// 📱 端末固定Cookieがあれば自動復元！なければlogin.phpへ
$current_user = checkAuthOrAutoLogin($pdo, $_SERVER['REQUEST_URI'] ?? '');
$current_staff_id = (int)$current_user['staff_id'];


// 事務長（山本太 / staff_id = 15）または システム管理者（is_admin = true）のみアクセス許可
$is_admin = (bool)($current_user['is_admin'] ?? false);
$is_jimucho = ($current_staff_id === 15 || mb_strpos($current_user['staff_name'], '山本') !== false || mb_strpos($current_user['role'], '事務') !== false);

if (!$is_admin && !$is_jimucho) {
    // 権限がない場合は通常一覧へリダイレクト
    header("Location: index.php");
    exit;
}

// 3. 休日ヘルパー読み込み
require_once __DIR__ . '/includes/calendar_helper_jimucho.php';
if (file_exists(__DIR__ . '/includes/line_helper.php')) {
    require_once __DIR__ . '/includes/line_helper.php';
}

// 3.5 Ajax API エンドポイント（イベント詳細・部署別既読・全日程スロット取得）
if (isset($_GET['action']) && $_GET['action'] === 'get_post_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['post_id'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'error' => '記事IDが不正です']);
        exit;
    }

    $stmt_p = $pdo->prepare("
        SELECT p.*, c.category_name, c.icon_emoji, s.staff_name 
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id
        WHERE p.post_id = :id
    ");
    $stmt_p->execute([':id' => $pid]);
    $p = $stmt_p->fetch();
    if (!$p) {
        echo json_encode(['success' => false, 'error' => '記事が見つかりません']);
        exit;
    }

    // 既読・返答スタッフ
    $stmt_reads = $pdo->prepare("SELECT staff_id, response_status, response_at, response_comment FROM post_reads WHERE post_id = :pid");
    $stmt_reads->execute([':pid' => $pid]);
    $raw_reads = $stmt_reads->fetchAll();
    $read_map = [];
    foreach ($raw_reads as $r) {
        $read_map[(int)$r['staff_id']] = [
            'status'   => !empty($r['response_status']) ? $r['response_status'] : 'read',
            'at'       => $r['response_at'] ? date('m/d H:i', strtotime($r['response_at'])) : '',
            'comment'  => $r['response_comment'] ?? ''
        ];
    }

    // 部署一覧
    $stmt_depts = $pdo->query("SELECT dept_id, dept_name FROM target_departments WHERE dept_id > 1 ORDER BY dept_id ASC");
    $depts = $stmt_depts->fetchAll();

    // スタッフ一覧
    $stmt_staff = $pdo->query("SELECT staff_id, staff_name, role, dept_id, line_user_id FROM staff WHERE is_deleted IS NOT TRUE");
    $staffs = $stmt_staff->fetchAll();

    $dept_stats = [];
    $total_target = 0;
    $total_read = 0;
    $total_ok = 0;
    $total_question = 0;
    $total_absence = 0;

    foreach ($depts as $dept) {
        $did = (int)$dept['dept_id'];
        $d_staffs = array_values(array_filter($staffs, function($s) use ($did) { return (int)$s['dept_id'] === $did; }));
        $d_total = count($d_staffs);
        $total_target += $d_total;

        $d_read = 0;
        $d_ok = 0;
        $d_question = 0;
        $d_absence = 0;
        $d_unread = [];
        $d_responses = [];

        foreach ($d_staffs as $st) {
            $sid = (int)$st['staff_id'];
            if (isset($read_map[$sid])) {
                $d_read++;
                $st_status = $read_map[$sid]['status'];
                if ($st_status === 'ok') {
                    $d_ok++;
                } elseif ($st_status === 'question') {
                    $d_question++;
                } elseif ($st_status === 'absence') {
                    $d_absence++;
                }

                $d_responses[] = [
                    'staff_id'   => $sid,
                    'staff_name' => $st['staff_name'],
                    'status'     => $st_status,
                    'at'         => $read_map[$sid]['at'],
                    'comment'    => $read_map[$sid]['comment']
                ];
            } else {
                $d_unread[] = [
                    'staff_id'   => $sid,
                    'staff_name' => $st['staff_name'],
                    'has_line'   => !empty($st['line_user_id'])
                ];
            }
        }

        $total_read += $d_read;
        $total_ok += $d_ok;
        $total_question += $d_question;
        $total_absence += $d_absence;

        $dept_stats[] = [
            'dept_id'        => $did,
            'dept_name'      => $dept['dept_name'],
            'total'          => $d_total,
            'read_count'     => $d_read,
            'ok_count'       => $d_ok,
            'question_count' => $d_question,
            'absence_count'  => $d_absence,
            'percent'        => $d_total > 0 ? round(($d_read / $d_total) * 100) : 0,
            'unread_list'    => $d_unread,
            'response_list'  => $d_responses
        ];
    }

    $summary_stats = [
        'total'          => $total_target,
        'read_count'     => $total_read,
        'ok_count'       => $total_ok,
        'question_count' => $total_question,
        'absence_count'  => $total_absence,
        'unread_count'   => $total_target - $total_read,
        'percent'        => $total_target > 0 ? round(($total_read / $total_target) * 100) : 0
    ];

    // スロット展開
    $slots = [];
    if (!empty($p['event_schedules'])) {
        $raw_slots = json_decode($p['event_schedules'], true) ?: [];
        foreach ($raw_slots as $raw_sl) {
            $s_date = !empty($raw_sl['date']) ? $raw_sl['date'] : substr($raw_sl['start_datetime'] ?? '', 0, 10);
            $s_time = !empty($raw_sl['is_all_day']) ? '終日' : (!empty($raw_sl['start_time']) ? $raw_sl['start_time'] : (!empty($raw_sl['start_datetime']) ? date('H:i', strtotime($raw_sl['start_datetime'])) : ''));
            $e_time = !empty($raw_sl['is_all_day']) ? '' : (!empty($raw_sl['end_time']) ? $raw_sl['end_time'] : (!empty($raw_sl['end_datetime']) ? date('H:i', strtotime($raw_sl['end_datetime'])) : ''));
            $start_dt = !empty($raw_sl['start_datetime']) ? $raw_sl['start_datetime'] : ($s_date . ' ' . (!empty($raw_sl['start_time']) ? $raw_sl['start_time'] : '00:00:00'));
            $end_dt = !empty($raw_sl['end_datetime']) ? $raw_sl['end_datetime'] : ((!empty($raw_sl['end_date']) ? $raw_sl['end_date'] : $s_date) . ' ' . (!empty($raw_sl['end_time']) ? $raw_sl['end_time'] : '23:59:59'));

            if (!empty($s_date)) {
                $slots[] = [
                    'schedule_id'    => $raw_sl['schedule_id'] ?? ('slot_' . (count($slots) + 1)),
                    'date'           => $s_date,
                    'start_time'     => $s_time,
                    'end_time'       => $e_time,
                    'start_datetime' => $start_dt,
                    'end_datetime'   => $end_dt,
                    'location'       => $raw_sl['location'] ?? '',
                    'memo'           => $raw_sl['memo'] ?? '',
                    'is_all_day'     => !empty($raw_sl['is_all_day'])
                ];
            }
        }
        usort($slots, function($a, $b) {
            return strcmp($a['start_datetime'], $b['start_datetime']);
        });
    }
    if (empty($slots) && !empty($p['target_datetime'])) {
        $slots[] = [
            'schedule_id'    => 'slot_1',
            'date'           => substr($p['target_datetime'], 0, 10),
            'start_time'     => date('H:i', strtotime($p['target_datetime'])),
            'end_time'       => !empty($p['target_end_datetime']) ? date('H:i', strtotime($p['target_end_datetime'])) : '',
            'start_datetime' => $p['target_datetime'],
            'end_datetime'   => $p['target_end_datetime'] ?? '',
            'location'       => '',
            'memo'           => '',
            'is_all_day'     => false
        ];
    }

    $today_check = date('Y-m-d');
    foreach ($slots as &$sl) {
        $sl_date = substr($sl['start_datetime'] ?? '', 0, 10);
        if ($sl_date < $today_check) {
            $sl['status'] = 'past';
            $sl['status_label'] = '終了';
        } elseif ($sl_date === $today_check) {
            $sl['status'] = 'today';
            $sl['status_label'] = '本日';
        } else {
            $sl['status'] = 'future';
            $sl['status_label'] = '予定';
        }
    }
    unset($sl);

    echo json_encode([
        'success'       => true,
        'post'          => [
            'post_id'       => $p['post_id'],
            'title'         => $p['title'],
            'content'       => $p['content'],
            'category_name' => $p['category_name'] ?? 'お知らせ',
            'icon_emoji'    => $p['icon_emoji'] ?? '🔧',
            'staff_name'    => $p['staff_name'] ?? '事務部',
            'created_at'    => date('Y/m/d H:i', strtotime($p['created_at'])),
            'slots'         => $slots
        ],
        'dept_stats'    => $dept_stats,
        'summary_stats' => $summary_stats
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. カレンダー日付パラメータ＆表示モード（デフォルトは週表示）
$view_mode = (isset($_GET['view']) && $_GET['view'] === 'month') ? 'month' : 'week';

$year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$today_str = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today_str;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = $today_str;
}

// 週間計算 (月曜始まり 7日間: Mon〜Sun)
$sel_ts = strtotime($selected_date);
$dow = (int)date('w', $sel_ts); // 0=Sun, 1=Mon, ..., 6=Sat
$days_from_mon = ($dow === 0) ? 6 : ($dow - 1);
$week_monday_ts = strtotime("-{$days_from_mon} days", $sel_ts);
$week_sunday_ts = strtotime("+6 days", $week_monday_ts);
$week_start_date = date('Y-m-d', $week_monday_ts);
$week_end_date   = date('Y-m-d', $week_sunday_ts);

// 週ナビゲーション用リンク日付
$prev_week_date = date('Y-m-d', strtotime('-7 days', $week_monday_ts));
$next_week_date = date('Y-m-d', strtotime('+7 days', $week_monday_ts));

// 今日の事務長状態
$today_status = check_jimucho_calendar($today_str);
$selected_status = check_jimucho_calendar($selected_date);

// 月の最初と最後の日付
$first_day_ts = strtotime(sprintf('%04d-%02d-01', $year, $month));
$days_in_month = (int)date('t', $first_day_ts);
$month_start_date = sprintf('%04d-%02d-01', $year, $month);
$month_end_date   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

// 前月・翌月リンク用
$prev_year  = ($month === 1) ? $year - 1 : $year;
$prev_month = ($month === 1) ? 12 : $month - 1;
$next_year  = ($month === 12) ? $year + 1 : $year;
$next_month = ($month === 12) ? 1 : $month + 1;

// 5. 該当月および前後のイベント記事（target_datetime または event_schedules）の取得
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
// 月間・週間の双方をカバーする検索期間
$calendar_start_range = date('Y-m-d 00:00:00', min(
    strtotime('-7 days', $first_day_ts),
    strtotime('-7 days', $week_monday_ts)
));
$calendar_end_range   = date('Y-m-d 23:59:59', max(
    strtotime('+14 days', strtotime($month_end_date)),
    strtotime('+7 days', $week_sunday_ts)
));

$stmt_events->execute([
    ':s_start' => $calendar_start_range,
    ':s_end'   => $calendar_end_range
]);
$all_event_posts = $stmt_events->fetchAll();

// 日付ごとにイベントをマッピング（初日＝通常カード、2日目以降＝(続) 件名 [第〇回]）
$date_events_map = [];
foreach ($all_event_posts as $ep) {
    // 複数日程 JSONB の展開
    $has_slot = false;
    if (!empty($ep['event_schedules'])) {
        $raw_slots = json_decode($ep['event_schedules'], true);
        if (is_array($raw_slots) && count($raw_slots) > 0) {
            // スロット標準化
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

            // 日時順にソート
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
    // target_datetime 単一の場合
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

// 各日内のイベントを開始時刻順にソート
foreach ($date_events_map as $d => &$evList) {
    usort($evList, function($a, $b) {
        return strcmp($a['start_time'], $b['start_time']);
    });
}
unset($evList);

// 週間カード用の7日間データ生成 (月〜日)
$week_days = [];
for ($i = 0; $i < 7; $i++) {
    $cur_ts = strtotime("+{$i} days", $week_monday_ts);
    $cur_d_str = date('Y-m-d', $cur_ts);
    $style_info = get_jimucho_cell_style($cur_d_str);
    $week_days[] = [
        'date_str'    => $cur_d_str,
        'ts'          => $cur_ts,
        'day_num'     => date('j', $cur_ts),
        'month_num'   => date('n', $cur_ts),
        'dow_text'    => ['日','月','火','水','木','金','土'][(int)date('w', $cur_ts)],
        'style_info'  => $style_info,
        'duty_info'   => $style_info['info'],
        'events'      => $date_events_map[$cur_d_str] ?? [],
        'is_today'    => ($cur_d_str === $today_str),
        'is_selected' => ($cur_d_str === $selected_date)
    ];
}

// 6. 選択された日のイベント一覧
$selected_day_events = $date_events_map[$selected_date] ?? [];

// 7. 部署一覧と職員数の集計（監視ハブ用）
$stmt_depts = $pdo->query("SELECT dept_id, dept_name FROM target_departments WHERE dept_id > 1 ORDER BY dept_id ASC");
$departments = $stmt_depts->fetchAll();

// 全有効スタッフ
$stmt_staff = $pdo->query("SELECT staff_id, staff_name, role, dept_id, line_user_id FROM staff WHERE is_deleted IS NOT TRUE");
$all_staff = $stmt_staff->fetchAll();
$dept_staff_counts = [];
$total_active_staff = count($all_staff);
foreach ($all_staff as $st) {
    $did = (int)$st['dept_id'];
    $dept_staff_counts[$did] = ($dept_staff_counts[$did] ?? 0) + 1;
}

// 直近の連絡・お知らせ（最新6件）および各記事の既読・返答集計
$stmt_recent_posts = $pdo->query("
    SELECT p.post_id, p.title, p.content, p.created_at, c.category_name, c.icon_emoji, s.staff_name,
           (SELECT COUNT(*) FROM post_reads pr WHERE pr.post_id = p.post_id) AS read_count,
           (SELECT COUNT(*) FROM post_reads pr WHERE pr.post_id = p.post_id AND pr.response_status = 'ok') AS ok_count,
           (SELECT COUNT(*) FROM post_reads pr WHERE pr.post_id = p.post_id AND pr.response_status = 'question') AS question_count,
           (SELECT COUNT(*) FROM post_reads pr WHERE pr.post_id = p.post_id AND pr.response_status = 'absence') AS absence_count
    FROM posts p
    LEFT JOIN post_categories c ON p.category_id = c.category_id
    LEFT JOIN staff s ON p.author_id = s.staff_id
    ORDER BY p.post_id DESC
    LIMIT 6
");
$recent_posts = $stmt_recent_posts->fetchAll();

// 部署別既読・返答集計の事前計算（最新または指定記事）
$focus_post_id = !empty($recent_posts) ? $recent_posts[0]['post_id'] : 0;
if (isset($_GET['focus_post'])) {
    $focus_post_id = (int)$_GET['focus_post'];
}

$dept_read_stats = [];
$focus_summary = [
    'total' => 0, 'read' => 0, 'ok' => 0, 'question' => 0, 'absence' => 0, 'unread' => 0, 'percent' => 0
];

if ($focus_post_id > 0) {
    // この記事の既読・返答詳細
    $stmt_reads = $pdo->prepare("SELECT staff_id, response_status, response_at, response_comment FROM post_reads WHERE post_id = :pid");
    $stmt_reads->execute([':pid' => $focus_post_id]);
    $raw_reads = $stmt_reads->fetchAll();
    $read_map = [];
    foreach ($raw_reads as $r) {
        $read_map[(int)$r['staff_id']] = [
            'status'  => !empty($r['response_status']) ? $r['response_status'] : 'read',
            'at'      => $r['response_at'] ? date('m/d H:i', strtotime($r['response_at'])) : '',
            'comment' => $r['response_comment'] ?? ''
        ];
    }

    foreach ($departments as $dept) {
        $did = (int)$dept['dept_id'];
        $d_staffs = array_values(array_filter($all_staff, function($s) use ($did) { return (int)$s['dept_id'] === $did; }));
        $d_total = count($d_staffs);
        $d_read = 0;
        $d_ok = 0;
        $d_question = 0;
        $d_absence = 0;
        $d_unread_list = [];
        $d_response_list = [];

        foreach ($d_staffs as $s) {
            $sid = (int)$s['staff_id'];
            if (isset($read_map[$sid])) {
                $d_read++;
                $st_status = $read_map[$sid]['status'];
                if ($st_status === 'ok') $d_ok++;
                elseif ($st_status === 'question') $d_question++;
                elseif ($st_status === 'absence') $d_absence++;

                $d_response_list[] = [
                    'staff_id'   => $sid,
                    'staff_name' => $s['staff_name'],
                    'status'     => $st_status,
                    'at'         => $read_map[$sid]['at'],
                    'comment'    => $read_map[$sid]['comment']
                ];
            } else {
                $d_unread_list[] = $s;
            }
        }

        $focus_summary['total'] += $d_total;
        $focus_summary['read'] += $d_read;
        $focus_summary['ok'] += $d_ok;
        $focus_summary['question'] += $d_question;
        $focus_summary['absence'] += $d_absence;

        $pct = $d_total > 0 ? round(($d_read / $d_total) * 100) : 0;
        $dept_read_stats[$did] = [
            'dept_name'      => $dept['dept_name'],
            'total'          => $d_total,
            'read_count'     => $d_read,
            'ok_count'       => $d_ok,
            'question_count' => $d_question,
            'absence_count'  => $d_absence,
            'percent'        => $pct,
            'unread_list'    => $d_unread_list,
            'response_list'  => $d_response_list
        ];
    }
    $focus_summary['unread'] = $focus_summary['total'] - $focus_summary['read'];
    $focus_summary['percent'] = $focus_summary['total'] > 0 ? round(($focus_summary['read'] / $focus_summary['total']) * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👔 事務長モード - かわら版</title>
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --primary-light: #e0f2fe;
            --bg-base: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --border-dark: #cbd5e1;
            --text-main: #0f172a;
            --text-sub: #475569;
            --danger: #e11d48;
            --danger-bg: #fff1f2;
            --warning: #f59e0b;
            --warning-bg: #fffbeb;
            --success: #10b981;
            --success-bg: #ecfdf5;
            --purple: #8b5cf6;
            --purple-bg: #f5f3ff;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ヘッダー */
        .top-navbar {
            background: #0f172a;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-badge {
            background: linear-gradient(135deg, #0284c7, #2563eb);
            color: #fff;
            font-weight: 800;
            font-size: 0.82rem;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }
        .brand-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
        }
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-switch-timeline {
            background: #334155;
            color: #f1f5f9;
            text-decoration: none;
            padding: 8px 16px;
            font-size: 0.88rem;
            font-weight: 700;
            border-radius: 6px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-switch-timeline:hover {
            background: #475569;
            color: #fff;
        }

        /* 動的ステータスアラートバー */
        .status-alert-bar {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.95rem;
            font-weight: 600;
            border-bottom: 1px solid var(--border);
        }
        .status-alert-work {
            background: var(--success-bg);
            color: #065f46;
            border-left: 6px solid var(--success);
        }
        .status-alert-pre-off {
            background: var(--warning-bg);
            color: #92400e;
            border-left: 6px solid var(--warning);
            animation: pulse-border 2s infinite;
        }
        .status-alert-off {
            background: #f1f5f9;
            color: #475569;
            border-left: 6px solid #64748b;
        }
        @keyframes pulse-border {
            0%, 100% { border-left-color: #f59e0b; }
            50% { border-left-color: #dc2626; }
        }

        /* クイックツールバー */
        .quick-toolbar {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 10px 24px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .tool-btn {
            background: #ffffff;
            color: var(--text-main);
            border: 1px solid var(--border-dark);
            padding: 8px 16px;
            font-size: 0.88rem;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .tool-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .tool-btn-primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary-dark);
        }
        .tool-btn-primary:hover {
            background: var(--primary-dark);
        }
        .tool-btn-warning {
            background: #fff7ed;
            color: #c2410c;
            border-color: #fdba74;
        }
        .tool-btn-warning:hover {
            background: #ffedd5;
        }

        /* メインコンテナ（左右2分割） */
        .dashboard-body {
            display: flex;
            flex: 1;
            padding: 14px 18px;
            gap: 16px;
            min-width: 0;
        }
        .left-col {
            flex: 62;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: flex 0.2s ease;
        }
        .right-col {
            flex: 38;
            min-width: 320px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: opacity 0.2s ease;
        }

        /* カレンダーカード */
        .calendar-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow);
            padding: 12px 14px;
        }
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            gap: 8px;
            flex-wrap: wrap;
        }
        .cal-nav-btn {
            background: #f8fafc;
            border: 1px solid var(--border-dark);
            padding: 5px 10px;
            border-radius: 6px;
            font-weight: 700;
            text-decoration: none;
            color: var(--text-main);
            font-size: 0.82rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .cal-nav-btn:hover { background: #e2e8f0; }
        .cal-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-main);
        }

        /* カレンダーグリッド */
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 3px;
            width: 100%;
            overflow: hidden;
        }
        .cal-th {
            text-align: center;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 6px 2px;
            color: var(--text-sub);
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cal-th .th-sub {
            font-size: 0.68rem;
            font-weight: normal;
            opacity: 0.85;
        }
        .cal-th-sun { color: #dc2626; }
        .cal-th-thu { color: #0284c7; }
        .cal-th-sat { color: #7c3aed; }

        .cal-day-cell {
            min-height: 82px;
            min-width: 0;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 3px 4px;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            transition: all 0.12s;
            position: relative;
            background: #ffffff;
            text-decoration: none;
            color: inherit;
            box-sizing: border-box;
        }
        .cal-day-cell:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
            border-color: #94a3b8;
            z-index: 2;
        }
        .cal-day-selected {
            border: 2px solid var(--primary) !important;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.25);
            background: #f0f9ff !important;
        }
        .cal-day-today {
            font-weight: 900;
        }
        .cal-day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2px;
            min-width: 0;
            gap: 2px;
        }
        .cal-day-num {
            font-size: 0.86rem;
            font-weight: 800;
            line-height: 1;
            flex-shrink: 0;
        }
        .badge-duty {
            font-size: 0.60rem;
            font-weight: 700;
            padding: 1px 3px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
            flex-shrink: 1;
            max-width: calc(100% - 20px);
            text-align: right;
        }
        .badge-duty-work { background: #e0f2fe; color: #0369a1; }
        .badge-duty-thu  { background: #e0f2fe; color: #0284c7; }
        .badge-duty-sat  { background: #f3e8ff; color: #7e22ce; }
        .badge-duty-hol  { background: #ffe4e6; color: #be123c; }

        /* イベントリスト（マス内） */
        .cal-events-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
            overflow: hidden;
            min-width: 0;
            flex: 1;
        }
        .cal-event-pill {
            font-size: 0.66rem;
            font-weight: 700;
            padding: 1px 3px;
            border-radius: 3px;
            background: #f1f5f9;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 2px;
            border-left: 3px solid #64748b;
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
            line-height: 1.3;
        }
        .cal-event-pill .ev-badge-cont {
            background: #8b5cf6;
            color: #fff;
            font-size: 0.58rem;
            padding: 0 2px;
            border-radius: 2px;
            font-weight: 800;
            flex-shrink: 0;
            line-height: 1.2;
        }
        .cal-event-pill .ev-icon {
            flex-shrink: 0;
            font-size: 0.68rem;
            line-height: 1;
        }
        .cal-event-pill .ev-time {
            flex-shrink: 0;
            font-size: 0.62rem;
            color: #475569;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.3px;
        }
        .cal-event-pill .ev-title {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cal-event-pill-warn {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
            animation: pulse-warn 2s infinite;
            font-size: 0.62rem;
            padding: 1px 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            display: block;
        }

        /* ビュー切り替えスイッチ */
        .view-switcher {
            display: inline-flex;
            background: #e2e8f0;
            padding: 3px;
            border-radius: 8px;
            gap: 2px;
        }
        .view-switch-btn {
            padding: 6px 14px;
            font-size: 0.85rem;
            font-weight: 700;
            border-radius: 6px;
            text-decoration: none;
            color: #475569;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .view-switch-btn:hover {
            color: #0f172a;
            background: rgba(255, 255, 255, 0.5);
        }
        .view-switch-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* 1週間カード表示レイアウト */
        .week-view-wrapper {
            padding: 18px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex: 1;
        }
        .week-navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface);
            padding: 12px 20px;
            border-radius: 10px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            flex-wrap: wrap;
            gap: 10px;
        }
        .week-nav-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-main);
        }
        .week-grid-7cols {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 12px;
            align-items: stretch;
        }
        @media (max-width: 1300px) {
            .week-grid-7cols {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        .week-day-col {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: box-shadow 0.2s;
            min-height: 520px;
        }
        .week-day-col:hover {
            box-shadow: var(--shadow-lg);
        }
        .week-day-col.is-today {
            border: 2px solid var(--primary);
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.2);
            background: #fdfefe;
        }
        .week-day-header {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .week-day-col.is-today .week-day-header {
            background: #eff6ff;
            border-bottom-color: #bfdbfe;
        }
        .week-day-header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .week-day-date {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .badge-today {
            background: var(--primary);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .week-btn-add {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0369a1;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 900;
            transition: all 0.15s;
            border: 1px solid #bae6fd;
            line-height: 1;
        }
        .week-btn-add:hover {
            background: var(--primary);
            color: #ffffff;
            transform: scale(1.1);
        }
        .week-day-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            align-items: center;
        }
        .week-day-body {
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            overflow-y: auto;
            background: #fafbfc;
        }

        /* 週間イベントカード */
        .week-event-card {
            background: #ffffff;
            border: 1px solid var(--border-dark);
            border-left: 4px solid var(--primary);
            border-radius: 8px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            display: flex;
            flex-direction: column;
            gap: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            text-decoration: none;
            color: inherit;
        }
        .week-event-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }
        .week-event-card-continuation {
            border-left: 4px solid var(--purple);
            background: #faf5ff;
            border-color: #ddd6fe;
        }
        .week-event-card-continuation:hover {
            border-color: var(--purple);
        }
        .wec-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
        }
        .wec-time {
            font-weight: 800;
            color: #0369a1;
            font-size: 0.82rem;
        }
        .week-event-card-continuation .wec-time {
            color: #6d28d9;
        }
        .wec-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.35;
            word-break: break-word;
        }
        .wec-loc {
            font-size: 0.76rem;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 3px;
            font-weight: 600;
        }
        .wec-memo {
            font-size: 0.74rem;
            color: #64748b;
            background: rgba(255,255,255,0.7);
            padding: 3px 6px;
            border-radius: 4px;
            border: 1px dashed #cbd5e1;
            line-height: 1.3;
        }
        .wec-badge-cont {
            background: var(--purple);
            color: #fff;
            font-size: 0.65rem;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 3px;
        }
        .wec-slot-badge {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 700;
            color: #6d28d9;
            background: #ede9fe;
            padding: 1px 5px;
            border-radius: 3px;
            margin-left: 4px;
        }
        .wec-warn-pill {
            background: #fee2e2;
            color: #b91c1c;
            font-size: 0.7rem;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            align-self: flex-start;
            margin-top: 2px;
        }
        .empty-day-placeholder {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 30px 10px;
            gap: 6px;
        }

        /* スロット一覧テーブル */
        .slots-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .slots-table th, .slots-table td {
            padding: 8px 10px;
            border: 1px solid var(--border);
            text-align: left;
        }
        .slots-table th {
            background: #f8fafc;
            font-weight: 700;
            color: var(--text-sub);
        }
        .slot-status-past {
            background: #f1f5f9;
            color: #64748b;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .slot-status-today {
            background: #dcfce7;
            color: #15803d;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 800;
        }
        .slot-status-future {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
        }

        /* 右カラム：パネル */
        .detail-panel-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
        }
        .panel-header-box {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .panel-date-title {
            font-size: 1.15rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* タブ */
        .panel-tabs {
            display: flex;
            background: #f1f5f9;
            border-bottom: 1px solid var(--border);
        }
        .panel-tab {
            flex: 1;
            padding: 10px 14px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-sub);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.15s;
        }
        .panel-tab.active {
            background: #ffffff;
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            padding: 16px 20px;
            flex: 1;
            overflow-y: auto;
            display: none;
        }
        .tab-content.active { display: block; }

        /* イベント詳細カード */
        .event-detail-item {
            background: #f8fafc;
            border: 1px solid var(--border-dark);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 12px;
            transition: all 0.15s;
        }
        .event-detail-item:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        .event-detail-title {
            font-size: 1.05rem;
            font-weight: 800;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .event-detail-time {
            font-size: 0.88rem;
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 6px;
        }
        .event-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-action-sm {
            padding: 5px 12px;
            font-size: 0.8rem;
            font-weight: 700;
            border-radius: 4px;
            border: 1px solid var(--border-dark);
            background: #fff;
            color: var(--text-main);
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-action-sm:hover { background: #f1f5f9; }
        .btn-action-print {
            background: #0284c7;
            color: #fff;
            border-color: #0369a1;
        }
        .btn-action-print:hover { background: #0369a1; }

        /* 部署別集計プログレス */
        .dept-stat-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: #ffffff;
        }
        .dept-stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 0.92rem;
            font-weight: 700;
        }
        .progress-bar-bg {
            height: 10px;
            background: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 6px;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #0284c7, #10b981);
            border-radius: 5px;
            transition: width 0.4s ease;
        }
        .unread-tags-box {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed var(--border);
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .unread-staff-pill {
            background: #fee2e2;
            color: #991b1b;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .response-pill {
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            border: 1px solid transparent;
        }
        .response-pill-ok {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }
        .response-pill-question {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }
        .response-pill-absence {
            background: #ede9fe;
            color: #5b21b6;
            border-color: #ddd6fe;
        }
        .response-pill-read {
            background: #f1f5f9;
            color: #475569;
            border-color: #e2e8f0;
        }
        .status-badge-mini {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 1px 6px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
        }

        /* モーダル */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            max-width: 650px;
            width: 100%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        .modal-title { font-size: 1.15rem; font-weight: 800; color: var(--text-main); }
        .modal-body { padding: 20px; overflow-y: auto; }
        .modal-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #f8fafc;
        }

        /* トースト通知 */
        #toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 10000;
        }
        .toast-msg {
            background: #0f172a;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slide-in 0.3s ease;
        }
        /* 📱 スマホ最適化レスポンシブスタイル */
        @media (max-width: 768px) {
            .top-navbar { padding: 8px 12px !important; }
            .brand-title { font-size: 0.98rem !important; }
            .nav-actions { gap: 6px !important; font-size: 0.78rem !important; }
            .nav-actions span { font-size: 0.78rem !important; }
            .btn-switch-timeline { padding: 5px 10px !important; font-size: 0.78rem !important; }
            .view-switcher { display: none !important; } /* スマホでは歴月切り替え不要 */
            .quick-toolbar { padding: 6px 10px !important; overflow-x: auto !important; flex-wrap: nowrap !important; -webkit-overflow-scrolling: touch; }
            .quick-toolbar .tool-btn { white-space: nowrap !important; font-size: 0.76rem !important; padding: 5px 10px !important; }
            .status-alert-bar { padding: 8px 12px !important; font-size: 0.82rem !important; flex-direction: column; align-items: flex-start; gap: 4px; }
            .week-view-wrapper { padding: 8px 6px !important; }
            .week-navbar { flex-direction: column !important; align-items: flex-start !important; gap: 6px !important; padding: 10px !important; }
            .week-grid-7cols { display: flex !important; flex-direction: column !important; gap: 10px !important; }
            .week-day-col { min-height: auto !important; }
        }
        @keyframes slide-in {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>

<!-- 1. トップナビバー（コンパクト化・氏名のみ） -->
<div class="top-navbar">
    <div class="nav-brand" style="gap:8px;">
        <span class="brand-title" style="font-size:1.05rem; font-weight:900;">👔 事務長モード</span>
    </div>
    <div class="nav-actions">
        <span style="font-size:0.84rem; color:#cbd5e1;">
            👤 <b><?= htmlspecialchars($current_user['staff_name']) ?></b>
        </span>
        <a href="index.php" class="btn-switch-timeline" style="padding:5px 12px; font-size:0.8rem;">
            📜 かわら版
        </a>
    </div>
</div>

<!-- 2. 動的ステータスアラートバー -->
<?php
$alert_class = 'status-alert-work';
if ($today_status['is_pre_off_day']) {
    $alert_class = 'status-alert-pre-off';
} elseif ($today_status['is_closed']) {
    $alert_class = 'status-alert-off';
}
?>
<div class="status-alert-bar <?= $alert_class ?>">
    <div style="display:flex; align-items:center; gap:8px;">
        <?php if ($today_status['is_pre_off_day']): ?>
            <span>🔔</span>
            <span><b>【不在前日アラート】</b> <?= htmlspecialchars($today_status['pre_off_reason']) ?></span>
        <?php elseif ($today_status['is_closed']): ?>
            <span>⚪</span>
            <span>本日は事務長<b>【公休日（不在日: <?= htmlspecialchars($today_status['reason']) ?>）】</b>です。</span>
        <?php else: ?>
            <span>🟢</span>
            <span>本日は事務長<b>【出勤日】</b>です。院内の予定・点検・職員連絡のステータスを確認してください。</span>
        <?php endif; ?>
    </div>
    <div>
        本日: <?= date('Y/m/d') ?> (<?= ['日','月','火','水','木','金','土'][(int)date('w')] ?>)
    </div>
</div>

<!-- 3. クイック操作ツールバー -->
<div class="quick-toolbar">
    <!-- ビュー切り替えスイッチ -->
    <div class="view-switcher">
        <a href="?view=month&year=<?= $year ?>&month=<?= $month ?>&date=<?= $selected_date ?>" class="view-switch-btn <?= $view_mode === 'month' ? 'active' : '' ?>">
            📅 月間表示
        </a>
        <a href="?view=week&date=<?= $selected_date ?>" class="view-switch-btn <?= $view_mode === 'week' ? 'active' : '' ?>">
            📆 1週間カード
        </a>
    </div>

    <div style="height:24px; width:1px; background:#cbd5e1; margin:0 4px;"></div>

    <a href="create_post.php" class="tool-btn tool-btn-primary">
        ➕ 予定・工事の登録
    </a>
    <button type="button" class="tool-btn tool-btn-warning" onclick="openAbsenceSummaryModal()">
        📄 不在期間まとめ印刷（伝達シート）
    </button>
    <button type="button" class="tool-btn" onclick="openPrinterSettingsModal()">
        ⚙️ 部署別プリンタ設定
    </button>
    <a href="safety_contacts.php" class="tool-btn">
        🚨 安否確認・緊急連絡網
    </a>
</div>

<?php if ($view_mode === 'week'): ?>
<!-- 4. メインコンテナ（1週間カード表示: 7列全幅グリッド） -->
<div class="week-view-wrapper">
    <!-- 週間ナビゲーションバー -->
    <div class="week-navbar">
        <div style="display:flex; align-items:center; gap:10px;">
            <a href="?view=week&date=<?= $prev_week_date ?>" class="cal-nav-btn">
                ◀ 前週
            </a>
            <div class="week-nav-title">
                📆 <?= date('Y年n月j日', $week_monday_ts) ?>(月) 〜 <?= date('n月j日', $week_sunday_ts) ?>(日)
            </div>
            <a href="?view=week&date=<?= $next_week_date ?>" class="cal-nav-btn">
                翌週 ▶
            </a>
            <a href="?view=week&date=<?= $today_str ?>" class="cal-nav-btn" style="background:#e0f2fe; color:#0369a1; margin-left:6px;">
                今週へ
            </a>
        </div>
        <div style="font-size:0.85rem; color:#64748b; font-weight:600;">
            💡 各カードをクリックすると【全日程一覧・部署別既読・PowerShell排紙】が開きます
        </div>
    </div>

    <!-- 7列カードグリッド (月曜〜日曜) -->
    <div class="week-grid-7cols">
        <?php foreach ($week_days as $wd): 
            $duty = $wd['duty_info'];
            $has_absence_warn = ($duty['is_closed'] && count($wd['events']) > 0);
        ?>
            <div class="week-day-col <?= $wd['is_today'] ? 'is-today' : '' ?>">
                <!-- カラムヘッダー -->
                <div class="week-day-header">
                    <div class="week-day-header-top">
                        <div class="week-day-date">
                            <span><?= $wd['month_num'] ?>/<?= $wd['day_num'] ?></span>
                            <span style="font-size:0.85rem; color:<?= (int)date('w', $wd['ts']) === 0 ? '#dc2626' : ((int)date('w', $wd['ts']) === 4 ? '#0284c7' : ((int)date('w', $wd['ts']) === 6 ? '#7c3aed' : '#334155')) ?>;">(<?= $wd['dow_text'] ?>)</span>
                            <?php if ($wd['is_today']): ?>
                                <span class="badge-today">今日</span>
                            <?php endif; ?>
                        </div>
                        <a href="create_post.php?date=<?= $wd['date_str'] ?>" class="week-btn-add" title="この日に新しい予定・工事を登録">
                            ＋
                        </a>
                    </div>
                    <div class="week-day-badges">
                        <span class="badge-duty badge-duty-<?= $duty['badge_type'] ?>">
                            <?= htmlspecialchars($duty['badge_label']) ?>
                        </span>
                        <?php if ($duty['is_pre_off_day']): ?>
                            <span class="badge-duty" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a;">
                                🔔 不在前日
                            </span>
                        <?php endif; ?>
                        <?php if ($has_absence_warn): ?>
                            <span class="badge-duty" style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca;">
                                ⚠️ 不在日作業
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- イベント一覧リスト -->
                <div class="week-day-body">
                    <?php if (empty($wd['events'])): ?>
                        <div class="empty-day-placeholder">
                            <span style="font-size:1.4rem; opacity:0.35;">☕</span>
                            <span>予定なし</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($wd['events'] as $ev): ?>
                            <?php if ($ev['is_continuation']): ?>
                                <!-- 2回目以降の日程：(続) カード -->
                                <div class="week-event-card week-event-card-continuation" onclick="openEventDetailModal(<?= $ev['post_id'] ?>, '<?= $wd['date_str'] ?>')">
                                    <div class="wec-header">
                                        <span class="wec-badge-cont">続</span>
                                        <span class="wec-time"><?= $ev['start_time'] ?><?= !empty($ev['end_time']) ? '〜' . $ev['end_time'] : '' ?></span>
                                    </div>
                                    <div class="wec-title">
                                        <?= htmlspecialchars($ev['title']) ?>
                                        <span class="wec-slot-badge"><?= $ev['slot_badge'] ?></span>
                                    </div>
                                    <?php if (!empty($ev['location'])): ?>
                                        <div class="wec-loc">📍 <?= htmlspecialchars($ev['location']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['memo'])): ?>
                                        <div class="wec-memo">📝 <?= htmlspecialchars($ev['memo']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($duty['is_closed']): ?>
                                        <div class="wec-warn-pill">⚠️ 不在日作業</div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- 初日または単一日程：メインカード -->
                                <div class="week-event-card" onclick="openEventDetailModal(<?= $ev['post_id'] ?>, '<?= $wd['date_str'] ?>')">
                                    <div class="wec-header">
                                        <span style="font-size:1.05rem;"><?= htmlspecialchars($ev['icon']) ?></span>
                                        <span class="wec-time"><?= $ev['start_time'] ?><?= !empty($ev['end_time']) ? '〜' . $ev['end_time'] : '' ?></span>
                                    </div>
                                    <div class="wec-title"><?= htmlspecialchars($ev['title']) ?></div>
                                    <?php if (!empty($ev['location'])): ?>
                                        <div class="wec-loc">📍 <?= htmlspecialchars($ev['location']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['memo'])): ?>
                                        <div class="wec-memo">📝 <?= htmlspecialchars($ev['memo']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($ev['total_slots'] > 1): ?>
                                        <div class="wec-slot-badge">全<?= $ev['total_slots'] ?>日程 (第1回)</div>
                                    <?php endif; ?>
                                    <?php if ($duty['is_closed']): ?>
                                        <div class="wec-warn-pill">⚠️ 不在日作業</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<!-- 4. メインコンテナ（左右2分割 月間表示） -->
<div class="dashboard-body">
    <!-- 左カラム：月間業務カレンダー -->
    <div class="left-col">
        <div class="calendar-card">
            <div class="calendar-header">
                <a href="?year=<?= $prev_year ?>&month=<?= $prev_month ?>&date=<?= $selected_date ?>" class="cal-nav-btn">
                    ◀ 前月
                </a>
                <div class="cal-title">
                    🗓 <?= $year ?>年 <?= $month ?>月 業務カレンダー
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <a href="?year=<?= date('Y') ?>&month=<?= date('n') ?>&date=<?= $today_str ?>" class="cal-nav-btn" style="background:#e0f2fe; color:#0369a1;">
                        今日へ
                    </a>
                    <a href="?year=<?= $next_year ?>&month=<?= $next_month ?>&date=<?= $selected_date ?>" class="cal-nav-btn">
                        翌月 ▶
                    </a>
                    <button type="button" class="cal-nav-btn" onclick="toggleCalendarExpand()" id="btn-toggle-expand" title="カレンダーの全幅/分割表示を切替">
                        ⛶ 全幅表示
                    </button>
                </div>
            </div>

            <!-- カレンダー曜日見出し -->
            <div class="cal-grid">
                <div class="cal-th cal-th-sun">日</div>
                <div class="cal-th">月</div>
                <div class="cal-th">火</div>
                <div class="cal-th">水</div>
                <div class="cal-th cal-th-thu">木 <span class="th-sub">(公休)</span></div>
                <div class="cal-th">金</div>
                <div class="cal-th cal-th-sat">土 <span class="th-sub">(公休)</span></div>

                <?php
                // カレンダーの空白マス
                $first_dow = (int)date('w', $first_day_ts);
                for ($blank = 0; $blank < $first_dow; $blank++) {
                    echo '<div class="cal-day-cell" style="background:#f8fafc; opacity:0.3; cursor:default;"></div>';
                }

                // 日付マスのループ
                for ($d = 1; $d <= $days_in_month; $d++) {
                    $cur_date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $style_info = get_jimucho_cell_style($cur_date_str);
                    $duty_info  = $style_info['info'];
                    $is_selected = ($cur_date_str === $selected_date);
                    $day_events  = $date_events_map[$cur_date_str] ?? [];

                    // 不在日作業警告フラグ
                    $has_absence_warn = ($duty_info['is_closed'] && count($day_events) > 0);

                    // 短縮バッジラベル（幅圧迫を防ぐ）
                    $raw_badge_label = $duty_info['badge_label'];
                    $short_badge_label = $raw_badge_label;
                    if ($raw_badge_label === '出勤日') {
                        $short_badge_label = '出勤';
                    } elseif ($raw_badge_label === '木曜公休' || $raw_badge_label === '土曜公休') {
                        $short_badge_label = '公休';
                    } elseif ($raw_badge_label === '日祝休診') {
                        $short_badge_label = '休診';
                    }
                ?>
                    <a href="?view=month&year=<?= $year ?>&month=<?= $month ?>&date=<?= $cur_date_str ?>" 
                       class="cal-day-cell <?= $is_selected ? 'cal-day-selected' : '' ?> <?= $style_info['is_today'] ? 'cal-day-today' : '' ?>"
                       style="background: <?= $style_info['bg'] ?>; color: <?= $style_info['fg'] ?>; border: <?= $style_info['border'] ?>;">
                        
                        <div class="cal-day-header">
                            <span class="cal-day-num"><?= $d ?></span>
                            <span class="badge-duty badge-duty-<?= $duty_info['badge_type'] ?>" title="<?= htmlspecialchars($raw_badge_label) ?>">
                                <?= htmlspecialchars($short_badge_label) ?>
                            </span>
                        </div>

                        <!-- 予定リスト -->
                        <div class="cal-events-list">
                            <?php if ($has_absence_warn): ?>
                                <div class="cal-event-pill-warn" title="事務長不在日の作業予定が入っています (<?= count($day_events) ?>件)">
                                    ⚠️ 不在日 (<?= count($day_events) ?>件)
                                </div>
                            <?php endif; ?>

                            <?php 
                            $disp_count = 0;
                            foreach ($day_events as $ev): 
                                if ($disp_count >= 2) {
                                    echo '<div style="font-size:0.62rem; color:#64748b; font-weight:bold; padding-left:2px;">＋他 ' . (count($day_events) - 2) . ' 件</div>';
                                    break;
                                }
                                $disp_count++;
                            ?>
                                <?php if ($ev['is_continuation']): ?>
                                    <div class="cal-event-pill" style="border-left-color: #8b5cf6; background: #faf5ff;" title="<?= htmlspecialchars($ev['display_title']) ?> <?= $ev['slot_badge'] ?>" onclick="event.preventDefault(); event.stopPropagation(); openEventDetailModal(<?= $ev['post_id'] ?>, '<?= $cur_date_str ?>');">
                                        <span class="ev-badge-cont">続</span>
                                        <span class="ev-time"><?= htmlspecialchars($ev['start_time']) ?></span>
                                        <span class="ev-title"><?= htmlspecialchars($ev['title']) ?> <?= $ev['slot_badge'] ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="cal-event-pill" title="<?= htmlspecialchars($ev['title']) ?>" onclick="event.preventDefault(); event.stopPropagation(); openEventDetailModal(<?= $ev['post_id'] ?>, '<?= $cur_date_str ?>');">
                                        <span class="ev-icon"><?= htmlspecialchars($ev['icon']) ?></span>
                                        <span class="ev-time"><?= htmlspecialchars($ev['start_time']) ?></span>
                                        <span class="ev-title"><?= htmlspecialchars($ev['title']) ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </a>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- 右カラム：選択日詳細パネル ＆ 監視ハブ -->
    <div class="right-col">
        <div class="detail-panel-card">
            <!-- パネルヘッダー -->
            <div class="panel-header-box">
                <div class="panel-date-title">
                    <span>🗓 <?= date('Y/m/d', strtotime($selected_date)) ?> (<?= ['日','月','火','水','木','金','土'][(int)date('w', strtotime($selected_date))] ?>)</span>
                    <span class="badge-duty badge-duty-<?= $selected_status['badge_type'] ?>" style="font-size:0.8rem; padding:3px 8px;">
                        <?= htmlspecialchars($selected_status['badge_label']) ?>
                    </span>
                    <?php if ($selected_status['is_closed'] && count($selected_day_events) > 0): ?>
                        <span style="background:#fee2e2; color:#b91c1c; font-size:0.75rem; font-weight:bold; padding:2px 8px; border-radius:4px;">
                            ⚠️ 不在日作業
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- タブ切替 -->
            <div class="panel-tabs">
                <div class="panel-tab active" onclick="switchTab('tab-events', this)">
                    📅 選択日の予定・工事 (<?= count($selected_day_events) ?>件)
                </div>
                <div class="panel-tab" onclick="switchTab('tab-reads', this)">
                    📊 連絡・部署別既読集計
                </div>
            </div>

            <!-- タブ1: 選択日の予定・工事 -->
            <div id="tab-events" class="tab-content active">
                <?php if (empty($selected_day_events)): ?>
                    <div style="text-align:center; padding:50px 20px; color:#64748b;">
                        <div style="font-size:2.5rem; margin-bottom:10px;">📋</div>
                        <div style="font-weight:bold; font-size:1rem;">この日の予定・工事はありません</div>
                        <p style="font-size:0.85rem; margin-top:6px;">新しい工事や設備点検、行事の予定を追加するには上の「予定・工事の登録」ボタンをご利用ください。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($selected_day_events as $ev): 
                        $raw = $ev['raw_post'];
                    ?>
                        <div class="event-detail-item">
                            <div class="event-detail-title">
                                <span>
                                    <?php if ($ev['is_continuation']): ?>
                                        <span style="background:#8b5cf6; color:#fff; font-size:0.72rem; padding:2px 5px; border-radius:4px; font-weight:800; margin-right:4px;">続</span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($ev['icon']) ?> <?= htmlspecialchars($ev['title']) ?> <?= $ev['slot_badge'] ?>
                                </span>
                                <span style="font-size:0.8rem; color:#64748b; font-weight:normal;">#<?= $ev['post_id'] ?></span>
                            </div>
                            <div class="event-detail-time">
                                ⏰ <?= $ev['start_time'] ?><?= !empty($ev['end_time']) ? ' 〜 ' . $ev['end_time'] : '' ?>
                                <?= !empty($ev['location']) ? ' | 📍 ' . htmlspecialchars($ev['location']) : '' ?>
                            </div>
                            <div style="font-size:0.88rem; color:#334155; line-height:1.5; margin-bottom:8px;">
                                <?= mb_strimwidth(strip_tags($raw['content']), 0, 160, '…') ?>
                            </div>

                            <!-- アクションボタン群 -->
                            <div class="event-actions">
                                <button type="button" class="btn-action-sm" onclick="openEventDetailModal(<?= $ev['post_id'] ?>, '<?= $selected_date ?>')">
                                    📋 全日程・既読
                                </button>
                                <button type="button" class="btn-action-sm" onclick="openLineNotifyModal(<?= $ev['post_id'] ?>)" style="background:#16a34a; color:#fff; border-color:#15803d; font-weight:bold;">
                                    💬 LINE通知
                                </button>
                                <button type="button" class="btn-action-sm btn-action-print" onclick="openPrintDispatchModal(<?= $ev['post_id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>', '<?= $selected_date ?>')">
                                    🚀 部署別PowerShell排紙
                                </button>
                                <a href="print_dept_poster.php?id=<?= $ev['post_id'] ?>&date=<?= $selected_date ?>" target="_blank" class="btn-action-sm">
                                    🖨️ ポスタープレビュー
                                </a>
                                <a href="view_post.php?id=<?= $ev['post_id'] ?>" class="btn-action-sm">
                                    🔍 記事詳細
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- タブ2: 連絡・部署別既読集計 -->
            <div id="tab-reads" class="tab-content">
                <!-- 記事セレクター＆アクションバー -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:260px;">
                        <span style="font-size:0.85rem; font-weight:bold; color:#475569; white-space:nowrap;">📢 集計対象記事:</span>
                        <select onchange="location.href='jimucho_dashboard.php?date=<?= urlencode($selected_date) ?>&view=<?= urlencode($view_mode) ?>&focus_post=' + this.value" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem; font-weight:600; color:#0f172a; flex:1; max-width:400px; background:#fff;">
                            <?php foreach ($recent_posts as $rp): ?>
                                <option value="<?= (int)$rp['post_id'] ?>" <?= ((int)$rp['post_id'] === (int)$focus_post_id) ? 'selected' : '' ?>>
                                    #<?= (int)$rp['post_id'] ?> <?= htmlspecialchars($rp['title']) ?> (<?= $rp['read_count'] ?>名確認)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($focus_post_id > 0): ?>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn-action-sm" onclick="openEventDetailModal(<?= (int)$focus_post_id ?>, '<?= $selected_date ?>')" style="background:#fff; color:#334155; border-color:#cbd5e1;">
                                📋 詳細モーダル
                            </button>
                            <button type="button" class="btn-action-sm" onclick="openLineNotifyModal(<?= (int)$focus_post_id ?>)" style="background:#059669; color:#fff; border-color:#047857; font-weight:bold;">
                                💬 LINE通知プレビュー・送信
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($focus_post_id > 0): ?>
                    <!-- 全体レスポンスサマリーカード -->
                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px;">
                            <div style="font-size:0.85rem; font-weight:800; color:#334155;">
                                📊 全体確認状況: <span style="font-size:1.1rem; color:#0f172a;"><?= $focus_summary['read'] ?></span> / <?= $focus_summary['total'] ?>名 (<?= $focus_summary['percent'] ?>%)
                            </div>
                            <!-- 内訳バッジ -->
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <span class="status-badge-mini" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;">
                                    👍 了解: <b><?= $focus_summary['ok'] ?></b>名
                                </span>
                                <?php if ($focus_summary['question'] > 0): ?>
                                    <span class="status-badge-mini" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">
                                        ❓ 質問: <b><?= $focus_summary['question'] ?></b>名
                                    </span>
                                <?php endif; ?>
                                <?php if ($focus_summary['absence'] > 0): ?>
                                    <span class="status-badge-mini" style="background:#ede9fe; color:#5b21b6; border:1px solid #ddd6fe;">
                                        ⚠️ 不在: <b><?= $focus_summary['absence'] ?></b>名
                                    </span>
                                <?php endif; ?>
                                <span class="status-badge-mini" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca;">
                                    ⏳ 未読・未返答: <b><?= $focus_summary['unread'] ?></b>名
                                </span>
                            </div>
                        </div>
                        <div class="progress-bar-bg" style="height:8px;">
                            <div class="progress-bar-fill" style="width: <?= $focus_summary['percent'] ?>%;"></div>
                        </div>
                    </div>

                    <!-- 部署別カード一覧 -->
                    <?php foreach ($dept_read_stats as $did => $stat): ?>
                        <div class="dept-stat-card" style="margin-bottom:10px;">
                            <div class="dept-stat-header" style="margin-bottom:6px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="font-weight:800; color:#0f172a;"><?= htmlspecialchars($stat['dept_name']) ?></span>
                                    <!-- 部署内ステータスカウント -->
                                    <div style="display:flex; gap:4px;">
                                        <?php if ($stat['ok_count'] > 0): ?>
                                            <span class="status-badge-mini" style="background:#dcfce7; color:#166534;">👍 <?= $stat['ok_count'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($stat['question_count'] > 0): ?>
                                            <span class="status-badge-mini" style="background:#fef3c7; color:#92400e;">❓ <?= $stat['question_count'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($stat['absence_count'] > 0): ?>
                                            <span class="status-badge-mini" style="background:#ede9fe; color:#5b21b6;">⚠️ <?= $stat['absence_count'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span style="font-size:0.85rem;">
                                    <b><?= $stat['read_count'] ?></b> / <?= $stat['total'] ?> 人既読 (<?= $stat['percent'] ?>%)
                                </span>
                            </div>
                            <div class="progress-bar-bg" style="margin-bottom:8px;">
                                <div class="progress-bar-fill" style="width: <?= $stat['percent'] ?>%;"></div>
                            </div>

                            <!-- 返答済みスタッフタグ -->
                            <?php if (!empty($stat['response_list'])): ?>
                                <div style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:6px;">
                                    <?php foreach ($stat['response_list'] as $resp): 
                                        $p_cls = 'response-pill-read';
                                        $p_icon = '👀';
                                        if ($resp['status'] === 'ok') { $p_cls = 'response-pill-ok'; $p_icon = '👍'; }
                                        elseif ($resp['status'] === 'question') { $p_cls = 'response-pill-question'; $p_icon = '❓'; }
                                        elseif ($resp['status'] === 'absence') { $p_cls = 'response-pill-absence'; $p_icon = '⚠️'; }
                                    ?>
                                        <span class="response-pill <?= $p_cls ?>" title="<?= htmlspecialchars($resp['staff_name']) ?> (<?= $resp['at'] ?>)<?= !empty($resp['comment']) ? ': ' . htmlspecialchars($resp['comment']) : '' ?>">
                                            <span><?= $p_icon ?></span>
                                            <span><?= htmlspecialchars($resp['staff_name']) ?></span>
                                            <?php if (!empty($resp['comment'])): ?>
                                                <span style="font-size:0.7rem; opacity:0.85; max-width:80px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">💬<?= htmlspecialchars($resp['comment']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- 未読スタッフタグ -->
                            <?php if (!empty($stat['unread_list'])): ?>
                                <div class="unread-tags-box" style="margin-top:4px;">
                                    <span style="font-weight:bold; margin-right:4px; font-size:0.75rem; color:#991b1b;">⏳ 未読:</span>
                                    <?php foreach ($stat['unread_list'] as $un_staff): ?>
                                        <span class="unread-staff-pill">
                                            <?= htmlspecialchars($un_staff['staff_name']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="font-size:0.75rem; color:#059669; font-weight:bold; margin-top:2px;">
                                    ✓ 全員確認完了
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- モーダル1: 部署別PowerShell一括排紙モーダル -->
<div id="modal-print-dispatch" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">🚀 部署別PowerShell自動排紙</div>
            <button type="button" onclick="closeModal('modal-print-dispatch')" style="border:none; background:none; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.9rem; color:#475569; margin-bottom:14px;">
                排紙対象の記事: <b id="dispatch-post-title">-</b>
            </p>
            <div style="background:#f1f5f9; padding:12px; border-radius:8px; margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div style="font-weight:bold; font-size:0.88rem;">排紙する出力先を選択してください (<span id="dispatch-selected-count">0</span>か所選択中):</div>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn-action-sm" onclick="toggleAllDispatchCheckboxes(true)" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:0.75rem;">全選択</button>
                        <button type="button" class="btn-action-sm" onclick="toggleAllDispatchCheckboxes(false)" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.75rem;">全解除</button>
                    </div>
                </div>
                <div id="dispatch-dept-checkboxes" style="display:flex; flex-direction:column; gap:10px; max-height:320px; overflow-y:auto; padding-right:4px;">
                    <!-- JSで部署グループごとに動的生成 -->
                </div>
            </div>
            <div style="font-size:0.82rem; color:#64748b; line-height:1.4;">
                💡 <b>自動迂回機能</b>: メインプリンタがオフラインまたは用紙切れの場合、自動的に登録された代替（予備）プリンタへ迂回排紙されます。<br>
                💡 1つの部署に複数台のプリンタが登録されている場合（例: 看護部3か所、事務2か所等）、チェックされたすべての出力先へ同時に自動排紙されます。
            </div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="tool-btn" style="background:#eff6ff; color:#1d4ed8; border-color:#93c5fd; font-weight:bold;" onclick="previewDispatchPoster()">
                    👁️ 印刷プレビュー確認
                </button>
                <span style="font-size:0.78rem; color:#64748b;">※別タブで内容を確認できます</span>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="button" class="tool-btn" onclick="closeModal('modal-print-dispatch')">キャンセル</button>
                <button type="button" id="btn-execute-dispatch" class="tool-btn tool-btn-primary" onclick="executePrintDispatch()">
                    🖨️ 選択した出力先へ一括排紙実行
                </button>
            </div>
        </div>
    </div>
</div>

<!-- モーダル2: 不在期間まとめ印刷モーダル -->
<div id="modal-absence-summary" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">📄 事務長不在期間まとめ伝達シート印刷</div>
            <button type="button" onclick="closeModal('modal-absence-summary')" style="border:none; background:none; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.9rem; color:#475569; margin-bottom:12px;">
                不在期間の工事・予定を自動で束ねた「朝礼・回覧伝達シート」を出力します。
            </p>
            <div style="margin-bottom:12px;">
                <label style="font-weight:bold; font-size:0.88rem; display:block; margin-bottom:4px;">基準日（水曜夕方または金曜夕方）:</label>
                <input type="date" id="absence-base-date" value="<?= $today_str ?>" style="padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:1rem; width:100%;">
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="button" class="tool-btn tool-btn-primary" onclick="openAbsencePrintPreview()" style="flex:1; justify-content:center;">
                    📄 ブラウザ印刷プレビューを開く
                </button>
                <button type="button" class="tool-btn tool-btn-warning" onclick="dispatchAbsenceSummaryPS()" style="flex:1; justify-content:center;">
                    🚀 全出力先プリンタへ一括排紙
                </button>
            </div>
        </div>
    </div>
</div>

<!-- モーダル3: 部署別プリンタ設定モーダル -->
<div id="modal-printer-settings" class="modal-overlay">
    <div class="modal-box" style="max-width:880px; width:95%;">
        <div class="modal-header">
            <div class="modal-title">⚙️ 部署別・出力先プリンタ設定（複数箇所対応）</div>
            <button type="button" onclick="closeModal('modal-printer-settings')" style="border:none; background:none; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <div id="printer-settings-loading" style="text-align:center; padding:30px; color:#64748b;">
                🔄 Windows上の実機プリンタ一覧を取得しています...
            </div>
            <div id="printer-settings-content" style="display:none;">
                <div style="max-height:360px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.86rem;">
                        <thead>
                            <tr style="background:#f1f5f9; text-align:left; position:sticky; top:0; z-index:1;">
                                <th style="padding:8px 10px; border:1px solid #cbd5e1; width:120px;">所属部署</th>
                                <th style="padding:8px 10px; border:1px solid #cbd5e1; width:190px;">出力先名称</th>
                                <th style="padding:8px 10px; border:1px solid #cbd5e1;">メインプリンタ</th>
                                <th style="padding:8px 10px; border:1px solid #cbd5e1;">代替（予備）プリンタ</th>
                                <th style="padding:8px 10px; border:1px solid #cbd5e1; width:110px; text-align:center;">操作</th>
                            </tr>
                        </thead>
                        <tbody id="printer-settings-tbody">
                            <!-- JSで動的生成 -->
                        </tbody>
                    </table>
                </div>

                <!-- ＋ 新しい出力先を追加カード -->
                <div style="margin-top:14px; padding:12px 14px; background:#f8fafc; border:1.5px dashed #94a3b8; border-radius:8px;">
                    <div style="font-weight:bold; font-size:0.86rem; color:#1e293b; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <span>＋</span><span>新しい出力先プリンタを追加（例: 看護部3F病棟、事務奥 等）</span>
                    </div>
                    <div style="display:grid; grid-template-columns: 140px 180px 1fr 1fr auto; gap:8px; align-items:center;">
                        <select id="new-printer-dept" style="padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.84rem;"></select>
                        <input type="text" id="new-printer-name" placeholder="出力先表示名" style="padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.84rem;">
                        <select id="new-printer-primary" style="padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.84rem;"></select>
                        <select id="new-printer-fallback" style="padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.84rem;"></select>
                        <button type="button" onclick="addPrinterSetting()" style="padding:6px 14px; background:#005a9c; color:#fff; font-weight:bold; border-radius:4px; border:none; cursor:pointer; font-size:0.84rem; white-space:nowrap;">
                            追加
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="tool-btn" onclick="closeModal('modal-printer-settings')">閉じる</button>
        </div>
    </div>
</div>

<!-- モーダル4: イベント詳細 ＆ 全日程スロット ＆ 部署別既読進捗モーダル -->
<div id="modal-event-detail" class="modal-overlay">
    <div class="modal-box" style="max-width:780px;">
        <div class="modal-header">
            <div class="modal-title" id="med-title-header" style="display:flex; align-items:center; gap:8px;">
                <span id="med-icon" style="font-size:1.3rem;">🔧</span>
                <span id="med-title">イベント詳細</span>
            </div>
            <button type="button" onclick="closeModal('modal-event-detail')" style="border:none; background:none; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="display:flex; flex-direction:column; gap:16px;">
            <!-- 概要メタ情報 -->
            <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px 16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <span id="med-category-badge" class="badge-duty badge-duty-work" style="font-size:0.8rem; padding:2px 8px;">カテゴリ</span>
                    <span style="font-size:0.82rem; color:#64748b;">
                        投稿者: <b id="med-author">-</b> | 投稿日時: <span id="med-created">-</span>
                    </span>
                </div>
                <div id="med-content" style="font-size:0.9rem; color:#334155; line-height:1.6; max-height:140px; overflow-y:auto; padding-top:4px;">
                    <!-- 記事本文 -->
                </div>
            </div>

            <!-- 全日程スロット一覧 -->
            <div>
                <div style="font-size:0.95rem; font-weight:800; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between;">
                    <span style="display:flex; align-items:center; gap:6px;">
                        <span>📅</span>
                        <span>全登録日程（全<span id="med-slot-count">1</span>日程）</span>
                    </span>
                    <span style="font-size:0.78rem; color:#64748b;">
                        ※ 複数日におよぶ工事・点検のスケジュール一覧
                    </span>
                </div>
                <div style="border:1px solid #cbd5e1; border-radius:8px; overflow:hidden;">
                    <table class="slots-table" style="margin-top:0;">
                        <thead>
                            <tr>
                                <th style="width:70px; text-align:center;">回数</th>
                                <th style="width:170px;">日時</th>
                                <th style="width:130px;">実施場所</th>
                                <th>特記事項・作業メモ</th>
                                <th style="width:65px; text-align:center;">状態</th>
                            </tr>
                        </thead>
                        <tbody id="med-slots-tbody">
                            <!-- JS動的生成 -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 部署別既読進捗 -->
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div style="font-size:0.95rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                        <span>📊</span>
                        <span>部署別 既読進捗状況</span>
                    </div>
                    <button type="button" id="med-btn-line" class="btn-action-sm" onclick="openLineNotifyModal(currentDetailPostId)" style="background:#059669; color:#fff; border-color:#047857; font-weight:bold;">
                        📲 LINE通知・意思表示配信
                    </button>
                </div>
                <div id="med-dept-stats-container" style="display:flex; flex-direction:column; gap:8px; max-height:200px; overflow-y:auto; padding-right:4px;">
                    <!-- JS動的生成 -->
                </div>
            </div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="btn-action-sm" onclick="openLineNotifyModal(currentDetailPostId)" style="background:#16a34a; color:#fff; border-color:#15803d; font-weight:bold;">
                    💬 LINE通知プレビュー
                </button>
                <button type="button" class="btn-action-sm btn-action-print" onclick="openPrintDispatchFromDetail()">
                    🚀 部署別PowerShell排紙
                </button>
                <a id="med-link-poster" href="#" target="_blank" class="btn-action-sm">
                    🖨️ ポスタープレビュー
                </a>
                <a id="med-link-edit" href="#" class="btn-action-sm">
                    ✏️ 記事編集
                </a>
                <a id="med-link-view" href="#" class="btn-action-sm">
                    🔍 記事詳細
                </a>
            </div>
            <button type="button" class="tool-btn" onclick="closeModal('modal-event-detail')">閉じる</button>
        </div>
    </div>
</div>

<!-- LINE通知プレビュー ＆ テスト送信モーダル（共通コンポーネント） -->
<?php require_once __DIR__ . '/includes/line_notify_modal.php'; ?>

<!-- トースト通知コンテナ -->
<div id="toast-container"></div>

<script>
// タブ切り替え
function switchTab(tabId, el) {
    document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

// モーダル開閉
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// トースト通知表示
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast-msg';
    toast.innerHTML = `<span>${type === 'error' ? '⚠️' : '✓'}</span><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 5000);
}

// 部署プリンタ設定の読み込み
let installedPrinters = [];
let deptPrinters = [];
let availableDepts = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function openPrinterSettingsModal() {
    openModal('modal-printer-settings');
    const loading = document.getElementById('printer-settings-loading');
    const content = document.getElementById('printer-settings-content');
    loading.style.display = 'block';
    content.style.display = 'none';

    try {
        // 1. 実機プリンタ取得
        const pRes = await fetch('api/printers_api.php?action=get_installed_printers');
        const pData = await pRes.json();
        installedPrinters = pData.printers || [];

        // 2. 部署設定 & 部署リスト取得
        const sRes = await fetch('api/printers_api.php?action=get_settings');
        const sData = await sRes.json();
        deptPrinters = sData.settings || [];
        availableDepts = sData.departments || [];

        renderPrinterSettingsTable();
        populateNewPrinterDeptSelect();
        loading.style.display = 'none';
        content.style.display = 'block';
    } catch (e) {
        loading.innerHTML = '❌ プリンタ情報の取得に失敗しました';
    }
}

function populateNewPrinterDeptSelect() {
    const selDept = document.getElementById('new-printer-dept');
    selDept.innerHTML = availableDepts.map(d => `<option value="${d.dept_id}">${escapeHtml(d.dept_name)}</option>`).join('');

    const selPrimary = document.getElementById('new-printer-primary');
    selPrimary.innerHTML = `<option value="">-- メイン選択 --</option>` + installedPrinters.map(p => 
        `<option value="${escapeHtml(p.Name)}">${escapeHtml(p.Name)}</option>`
    ).join('');

    const selFallback = document.getElementById('new-printer-fallback');
    selFallback.innerHTML = `<option value="">(代替なし)</option>` + installedPrinters.map(p => 
        `<option value="${escapeHtml(p.Name)}">${escapeHtml(p.Name)}</option>`
    ).join('');
}

function renderPrinterSettingsTable() {
    const tbody = document.getElementById('printer-settings-tbody');
    tbody.innerHTML = '';

    deptPrinters.forEach(dp => {
        const tr = document.createElement('tr');
        
        let primaryOptions = installedPrinters.map(p => 
            `<option value="${escapeHtml(p.Name)}" ${p.Name === dp.primary_printer_name ? 'selected' : ''}>${escapeHtml(p.Name)} ${p.PrinterStatus === 128 ? '(オフライン)' : ''}</option>`
        ).join('');

        let fallbackOptions = `<option value="">(設定なし)</option>` + installedPrinters.map(p => 
            `<option value="${escapeHtml(p.Name)}" ${p.Name === dp.fallback_printer_name ? 'selected' : ''}>${escapeHtml(p.Name)}</option>`
        ).join('');

        tr.innerHTML = `
            <td style="padding:8px 10px; border:1px solid #cbd5e1; font-weight:bold; color:#0f172a; white-space:nowrap;">
                ${escapeHtml(dp.dept_name)}
            </td>
            <td style="padding:8px 10px; border:1px solid #cbd5e1;">
                <input type="text" id="display-${dp.dept_printer_id}" value="${escapeHtml(dp.dept_display_name || dp.dept_name)}" style="width:100%; padding:5px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.85rem; box-sizing:border-box;">
            </td>
            <td style="padding:8px 10px; border:1px solid #cbd5e1;">
                <select id="primary-${dp.dept_printer_id}" style="width:100%; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.84rem;">${primaryOptions}</select>
            </td>
            <td style="padding:8px 10px; border:1px solid #cbd5e1;">
                <select id="fallback-${dp.dept_printer_id}" style="width:100%; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.84rem;">${fallbackOptions}</select>
            </td>
            <td style="padding:8px 10px; border:1px solid #cbd5e1; text-align:center; white-space:nowrap;">
                <button type="button" class="btn-action-sm btn-action-print" onclick="savePrinterSetting(${dp.dept_printer_id})">保存</button>
                <button type="button" class="btn-action-sm" onclick="deletePrinterSetting(${dp.dept_printer_id}, '${escapeHtml(dp.dept_display_name || dp.dept_name)}')" style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; margin-left:4px;">削除</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

async function savePrinterSetting(deptPrinterId) {
    const primary = document.getElementById(`primary-${deptPrinterId}`).value;
    const fallback = document.getElementById(`fallback-${deptPrinterId}`).value;
    const display = document.getElementById(`display-${deptPrinterId}`).value;

    const fd = new FormData();
    fd.append('action', 'save_settings');
    fd.append('dept_printer_id', deptPrinterId);
    fd.append('primary_printer', primary);
    fd.append('fallback_printer', fallback);
    fd.append('dept_display_name', display);

    try {
        const res = await fetch('api/printers_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✓ 出力先プリンタ設定を保存しました');
            const sRes = await fetch('api/printers_api.php?action=get_settings');
            const sData = await sRes.json();
            deptPrinters = sData.settings || [];
        } else {
            showToast('❌ 保存エラー: ' + data.error, 'error');
        }
    } catch (e) {
        showToast('❌ 通信エラーが発生しました', 'error');
    }
}

async function addPrinterSetting() {
    const deptId = document.getElementById('new-printer-dept').value;
    const name = document.getElementById('new-printer-name').value.trim();
    const primary = document.getElementById('new-printer-primary').value;
    const fallback = document.getElementById('new-printer-fallback').value;

    if (!primary) {
        alert('メインプリンタを選択してください。');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'add_printer');
    fd.append('dept_id', deptId);
    fd.append('dept_display_name', name);
    fd.append('primary_printer', primary);
    fd.append('fallback_printer', fallback);

    try {
        const res = await fetch('api/printers_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✓ 新しい出力先を追加しました');
            document.getElementById('new-printer-name').value = '';
            const sRes = await fetch('api/printers_api.php?action=get_settings');
            const sData = await sRes.json();
            deptPrinters = sData.settings || [];
            renderPrinterSettingsTable();
        } else {
            showToast('❌ 追加エラー: ' + data.error, 'error');
        }
    } catch (e) {
        showToast('❌ 通信エラーが発生しました', 'error');
    }
}

async function deletePrinterSetting(deptPrinterId, name) {
    if (!confirm(`出力先「${name}」を削除してもよろしいですか？`)) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'delete_printer');
    fd.append('dept_printer_id', deptPrinterId);

    try {
        const res = await fetch('api/printers_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✓ 出力先を削除しました');
            const sRes = await fetch('api/printers_api.php?action=get_settings');
            const sData = await sRes.json();
            deptPrinters = sData.settings || [];
            renderPrinterSettingsTable();
        } else {
            showToast('❌ 削除エラー: ' + data.error, 'error');
        }
    } catch (e) {
        showToast('❌ 通信エラーが発生しました', 'error');
    }
}

// 排紙モーダル制御
let currentDispatchPostId = 0;
let currentDispatchTargetDate = '';
async function openPrintDispatchModal(postId, title, targetDate = '') {
    currentDispatchPostId = postId;
    currentDispatchTargetDate = targetDate || '<?= $selected_date ?>';
    document.getElementById('dispatch-post-title').textContent = title;
    openModal('modal-print-dispatch');

    if (deptPrinters.length === 0) {
        const sRes = await fetch('api/printers_api.php?action=get_settings');
        const sData = await sRes.json();
        deptPrinters = sData.settings || [];
    }

    renderDispatchCheckboxes();
}

// 印刷プレビュー確認（指示を出す前に別タブで確認）
function previewDispatchPoster() {
    if (!currentDispatchPostId) {
        showToast('対象記事が選択されていません', 'error');
        return;
    }
    const url = `print_dept_poster.php?id=${currentDispatchPostId}${currentDispatchTargetDate ? '&date=' + encodeURIComponent(currentDispatchTargetDate) : ''}`;
    window.open(url, '_blank');
}

function renderDispatchCheckboxes() {
    const container = document.getElementById('dispatch-dept-checkboxes');
    container.innerHTML = '';

    // 部署ごとにグループ化
    const groups = {};
    deptPrinters.forEach(dp => {
        if (!groups[dp.dept_id]) {
            groups[dp.dept_id] = {
                dept_id: dp.dept_id,
                dept_name: dp.dept_name,
                printers: []
            };
        }
        groups[dp.dept_id].printers.push(dp);
    });

    Object.values(groups).forEach(g => {
        const grpBox = document.createElement('div');
        grpBox.style.background = '#ffffff';
        grpBox.style.border = '1px solid #cbd5e1';
        grpBox.style.borderRadius = '6px';
        grpBox.style.padding = '8px 12px';

        const header = document.createElement('div');
        header.style.display = 'flex';
        header.style.justifyContent = 'space-between';
        header.style.alignItems = 'center';
        header.style.borderBottom = '1px dashed #e2e8f0';
        header.style.paddingBottom = '4px';
        header.style.marginBottom = '6px';
        header.innerHTML = `
            <label style="font-weight:bold; font-size:0.88rem; color:#0f172a; display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" class="grp-toggle-${g.dept_id}" checked onchange="toggleDeptGroup(${g.dept_id}, this.checked)">
                <span>${escapeHtml(g.dept_name)}</span>
                <span style="font-size:0.78rem; color:#64748b; font-weight:normal;">(計 ${g.printers.length}か所)</span>
            </label>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="print_dept_poster.php?id=${currentDispatchPostId}&dept_id=${g.dept_id}${currentDispatchTargetDate ? '&date=' + encodeURIComponent(currentDispatchTargetDate) : ''}" target="_blank" style="font-size:0.76rem; color:#0284c7; text-decoration:none; font-weight:bold;" title="${escapeHtml(g.dept_name)}向けポスターをプレビュー">👁️ プレビュー</a>
                <span style="font-size:0.75rem; color:#64748b; cursor:pointer;" onclick="toggleDeptGroupDirect(${g.dept_id})">一括切替</span>
            </div>
        `;
        grpBox.appendChild(header);

        const itemsBox = document.createElement('div');
        itemsBox.style.display = 'flex';
        itemsBox.style.flexDirection = 'column';
        itemsBox.style.gap = '6px';
        itemsBox.style.paddingLeft = '22px';

        g.printers.forEach(dp => {
            const pLabel = document.createElement('label');
            pLabel.style.display = 'flex';
            pLabel.style.alignItems = 'center';
            pLabel.style.gap = '8px';
            pLabel.style.fontSize = '0.84rem';
            pLabel.style.cursor = 'pointer';
            pLabel.innerHTML = `
                <input type="checkbox" name="dispatch_printer" value="${dp.dept_printer_id}" data-dept="${g.dept_id}" checked onchange="onPrinterCheckboxChange(${g.dept_id})" style="width:16px; height:16px;">
                <span><b>${escapeHtml(dp.dept_display_name || dp.dept_name)}</b> <span style="color:#64748b; font-size:0.78rem;">(メイン: ${escapeHtml(dp.primary_printer_name)} / 予備: ${escapeHtml(dp.fallback_printer_name || 'なし')})</span></span>
            `;
            itemsBox.appendChild(pLabel);
        });
        grpBox.appendChild(itemsBox);
        container.appendChild(grpBox);
    });

    updateDispatchCount();
}

function onPrinterCheckboxChange(deptId) {
    const allInGrp = Array.from(document.querySelectorAll(`input[name="dispatch_printer"][data-dept="${deptId}"]`));
    const checkedInGrp = allInGrp.filter(cb => cb.checked);
    const grpToggle = document.querySelector(`.grp-toggle-${deptId}`);
    if (grpToggle) {
        grpToggle.checked = (checkedInGrp.length > 0);
        grpToggle.indeterminate = (checkedInGrp.length > 0 && checkedInGrp.length < allInGrp.length);
    }
    updateDispatchCount();
}

function toggleDeptGroup(deptId, isChecked) {
    document.querySelectorAll(`input[name="dispatch_printer"][data-dept="${deptId}"]`).forEach(cb => {
        cb.checked = isChecked;
    });
    updateDispatchCount();
}

function toggleDeptGroupDirect(deptId) {
    const grpToggle = document.querySelector(`.grp-toggle-${deptId}`);
    if (grpToggle) {
        grpToggle.checked = !grpToggle.checked;
        toggleDeptGroup(deptId, grpToggle.checked);
    }
}

function toggleAllDispatchCheckboxes(isChecked) {
    document.querySelectorAll('input[name="dispatch_printer"]').forEach(cb => {
        cb.checked = isChecked;
    });
    document.querySelectorAll('[class^="grp-toggle-"]').forEach(cb => {
        cb.checked = isChecked;
        cb.indeterminate = false;
    });
    updateDispatchCount();
}

function updateDispatchCount() {
    const checked = document.querySelectorAll('input[name="dispatch_printer"]:checked');
    const el = document.getElementById('dispatch-selected-count');
    if (el) el.textContent = checked.length;
}

async function executePrintDispatch() {
    const checked = Array.from(document.querySelectorAll('input[name="dispatch_printer"]:checked')).map(cb => cb.value);
    if (checked.length === 0) {
        alert('排紙先の出力先を少なくとも1つ選択してください。');
        return;
    }

    const btn = document.getElementById('btn-execute-dispatch');
    btn.disabled = true;
    btn.textContent = `⏳ ${checked.length}か所へPowerShell排紙中...`;

    const fd = new FormData();
    fd.append('action', 'dispatch_print');
    fd.append('post_id', currentDispatchPostId);
    fd.append('target_date', currentDispatchTargetDate);
    fd.append('print_type', 'poster');
    checked.forEach(id => fd.append('printer_ids[]', id));

    try {
        const res = await fetch('api/print_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        btn.textContent = '🖨️ 選択した出力先へ一括排紙実行';
        closeModal('modal-print-dispatch');

        if (data.success && data.results) {
            data.results.forEach(r => {
                if (r.status === 'fallback_used') {
                    showToast(`⚠️ [${r.dept_name}] メイン故障のため、代替(${r.printer_used})へ迂回排紙しました`, 'warning');
                } else if (r.status === 'success') {
                    showToast(`✓ [${r.dept_name}] ${r.printer_used} へ正常排紙しました`);
                } else {
                    showToast(`❌ [${r.dept_name}] 排紙失敗: ${r.message}`, 'error');
                }
            });
        } else {
            showToast('❌ 排紙処理に失敗しました: ' + (data.error || ''), 'error');
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = '🖨️ 選択した出力先へ一括排紙実行';
        showToast('❌ サーバーとの通信エラー', 'error');
    }
}

// 不在期間まとめ印刷モーダル
function openAbsenceSummaryModal() {
    openModal('modal-absence-summary');
}

function openAbsencePrintPreview() {
    const bDate = document.getElementById('absence-base-date').value;
    window.open(`print_absence_summary.php?date=${encodeURIComponent(bDate)}`, '_blank');
}

async function dispatchAbsenceSummaryPS() {
    const bDate = document.getElementById('absence-base-date').value;
    if (deptPrinters.length === 0) {
        const sRes = await fetch('api/printers_api.php?action=get_settings');
        const sData = await sRes.json();
        deptPrinters = sData.settings || [];
    }

    if (!confirm(`登録済みの全出力先（全${deptPrinters.length}か所）へ「不在期間まとめ伝達シート」を一括排紙しますか？`)) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'dispatch_print');
    fd.append('print_type', 'absence_summary');
    fd.append('target_date', bDate);
    deptPrinters.forEach(dp => fd.append('printer_ids[]', dp.dept_printer_id));

    try {
        showToast(`⏳ 全${deptPrinters.length}か所のプリンタへ伝達シートをPowerShell送信中...`);
        const res = await fetch('api/print_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        closeModal('modal-absence-summary');

        if (data.success && data.results) {
            data.results.forEach(r => {
                if (r.status === 'fallback_used') {
                    showToast(`⚠️ [${r.dept_name}] 代替(${r.printer_used})へ迂回排紙しました`, 'warning');
                } else if (r.status === 'success') {
                    showToast(`✓ [${r.dept_name}] まとめシートを排紙しました`);
                } else {
                    showToast(`❌ [${r.dept_name}] 排紙失敗: ${r.message}`, 'error');
                }
            });
        }
    } catch (e) {
        showToast('❌ 排紙処理エラー', 'error');
    }
}

// 未読者LINE催促 -> LINE通知プレビューモーダルを開く
function triggerLineReminder(postId) {
    openLineNotifyModal(postId);
}

// イベント詳細・全日程スロット・既読モーダル制御
let currentDetailPostId = 0;
let currentDetailPostTitle = '';
let currentDetailTargetDate = '';

async function openEventDetailModal(postId, targetDate) {
    currentDetailPostId = postId;
    currentDetailTargetDate = targetDate || '';
    openModal('modal-event-detail');

    // 初期化と読み込み表示
    document.getElementById('med-icon').textContent = '⏳';
    document.getElementById('med-title').textContent = '読み込み中...';
    document.getElementById('med-content').innerHTML = '<div style="color:#64748b; padding:10px;">データを取得しています...</div>';
    document.getElementById('med-slots-tbody').innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:#64748b;">読み込み中...</td></tr>';
    document.getElementById('med-dept-stats-container').innerHTML = '<div style="text-align:center; padding:16px; color:#64748b;">読み込み中...</div>';

    try {
        const res = await fetch(`jimucho_dashboard.php?action=get_post_detail&post_id=${postId}`);
        const data = await res.json();
        if (!data.success) {
            alert('記事情報の取得に失敗しました: ' + (data.error || ''));
            closeModal('modal-event-detail');
            return;
        }

        const post = data.post;
        currentDetailPostTitle = post.title;

        // ヘッダー情報
        document.getElementById('med-icon').textContent = post.icon_emoji || '🔧';
        document.getElementById('med-title').textContent = post.title;
        document.getElementById('med-category-badge').textContent = post.category_name;
        document.getElementById('med-author').textContent = post.staff_name;
        document.getElementById('med-created').textContent = post.created_at;
        document.getElementById('med-content').innerHTML = post.content || '<span style="color:#94a3b8;">(本文なし)</span>';

        // リンク設定
        document.getElementById('med-link-poster').href = `print_dept_poster.php?id=${post.post_id}${targetDate ? '&date=' + encodeURIComponent(targetDate) : ''}`;
        document.getElementById('med-link-edit').href = `create_post.php?edit_id=${post.post_id}`;
        document.getElementById('med-link-view').href = `view_post.php?id=${post.post_id}`;

        // 全日程スロットテーブル描画
        const slots = post.slots || [];
        document.getElementById('med-slot-count').textContent = slots.length;
        const tbody = document.getElementById('med-slots-tbody');
        tbody.innerHTML = '';

        if (slots.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:10px; color:#94a3b8;">日程が登録されていません</td></tr>';
        } else {
            slots.forEach((sl, idx) => {
                const tr = document.createElement('tr');
                const isSelectedSlot = (targetDate && sl.start_datetime && sl.start_datetime.startsWith(targetDate));
                if (isSelectedSlot) {
                    tr.style.background = '#f0fdf4';
                }

                let statusBadge = '';
                if (sl.status === 'today') {
                    statusBadge = '<span class="slot-status-today">🟢 本日</span>';
                } else if (sl.status === 'past') {
                    statusBadge = '<span class="slot-status-past">終了</span>';
                } else {
                    statusBadge = '<span class="slot-status-future">予定</span>';
                }

                const startStr = sl.start_datetime ? sl.start_datetime.replace(':00', '').replaceAll('-', '/') : '-';
                const endStr = sl.end_datetime ? ' 〜 ' + sl.end_datetime.substr(11, 5) : '';

                tr.innerHTML = `
                    <td style="text-align:center; font-weight:bold; color:#475569;">第${idx + 1}回</td>
                    <td style="font-weight:700; color:#0f172a;">${startStr}${endStr}</td>
                    <td style="color:#334155;">${sl.location ? '📍 ' + escapeHtml(sl.location) : '<span style="color:#94a3b8;">-</span>'}</td>
                    <td style="color:#475569;">${sl.memo ? escapeHtml(sl.memo) : '<span style="color:#94a3b8;">-</span>'}</td>
                    <td style="text-align:center;">${statusBadge}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        // 部署別既読進捗描画
        const deptContainer = document.getElementById('med-dept-stats-container');
        deptContainer.innerHTML = '';
        const deptStats = data.dept_stats || [];
        const summary = data.summary_stats || null;

        if (summary) {
            const sumBox = document.createElement('div');
            sumBox.style.cssText = 'background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:12px;';
            sumBox.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:6px;">
                    <span style="font-size:0.85rem; font-weight:800; color:#334155;">
                        📊 全体確認状況: <b style="font-size:1.05rem; color:#0f172a;">${summary.read_count}</b> / ${summary.total}人 (${summary.percent}%)
                    </span>
                    <div style="display:flex; gap:5px; flex-wrap:wrap;">
                        <span class="status-badge-mini" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;">👍 了解: <b>${summary.ok_count}</b></span>
                        ${summary.question_count > 0 ? `<span class="status-badge-mini" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">❓ 質問: <b>${summary.question_count}</b></span>` : ''}
                        ${summary.absence_count > 0 ? `<span class="status-badge-mini" style="background:#ede9fe; color:#5b21b6; border:1px solid #ddd6fe;">⚠️ 不在: <b>${summary.absence_count}</b></span>` : ''}
                        <span class="status-badge-mini" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca;">⏳ 未読: <b>${summary.unread_count}</b></span>
                    </div>
                </div>
                <div class="progress-bar-bg" style="height:6px;">
                    <div class="progress-bar-fill" style="width:${summary.percent}%;"></div>
                </div>
            `;
            deptContainer.appendChild(sumBox);
        }

        deptStats.forEach(stat => {
            const card = document.createElement('div');
            card.className = 'dept-stat-card';
            card.style.padding = '8px 12px';
            card.style.marginBottom = '8px';

            let countBadges = '';
            if (stat.ok_count > 0) countBadges += `<span class="status-badge-mini" style="background:#dcfce7; color:#166534;">👍 ${stat.ok_count}</span> `;
            if (stat.question_count > 0) countBadges += `<span class="status-badge-mini" style="background:#fef3c7; color:#92400e;">❓ ${stat.question_count}</span> `;
            if (stat.absence_count > 0) countBadges += `<span class="status-badge-mini" style="background:#ede9fe; color:#5b21b6;">⚠️ ${stat.absence_count}</span> `;

            let respHtml = '';
            if (stat.response_list && stat.response_list.length > 0) {
                const respPills = stat.response_list.map(r => {
                    let cls = 'response-pill-read';
                    let icon = '👀';
                    if (r.status === 'ok') { cls = 'response-pill-ok'; icon = '👍'; }
                    else if (r.status === 'question') { cls = 'response-pill-question'; icon = '❓'; }
                    else if (r.status === 'absence') { cls = 'response-pill-absence'; icon = '⚠️'; }
                    const cmt = r.comment ? ` 💬${escapeHtml(r.comment)}` : '';
                    return `<span class="response-pill ${cls}" title="${escapeHtml(r.staff_name)} (${r.at})${cmt}"><span>${icon}</span><span>${escapeHtml(r.staff_name)}</span></span>`;
                }).join(' ');
                respHtml = `<div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; margin-bottom:4px;">${respPills}</div>`;
            }

            let unreadHtml = '';
            if (stat.unread_list && stat.unread_list.length > 0) {
                const pills = stat.unread_list.map(u => `<span class="unread-staff-pill">${escapeHtml(u.staff_name)}</span>`).join(' ');
                unreadHtml = `<div class="unread-tags-box" style="margin-top:4px; padding-top:4px;"><span style="font-weight:bold; margin-right:4px; font-size:0.75rem; color:#991b1b;">⏳ 未読:</span>${pills}</div>`;
            } else {
                unreadHtml = `<div style="font-size:0.75rem; color:#059669; font-weight:bold; margin-top:4px;">✓ 全員確認完了</div>`;
            }

            card.innerHTML = `
                <div class="dept-stat-header" style="font-size:0.88rem; margin-bottom:4px;">
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span>${escapeHtml(stat.dept_name)}</span>
                        ${countBadges}
                    </div>
                    <span style="font-size:0.82rem;"><b>${stat.read_count}</b> / ${stat.total}人 (${stat.percent}%)</span>
                </div>
                <div class="progress-bar-bg" style="height:6px; margin-bottom:4px;">
                    <div class="progress-bar-fill" style="width:${stat.percent}%;"></div>
                </div>
                ${respHtml}
                ${unreadHtml}
            `;
            deptContainer.appendChild(card);
        });

    } catch (e) {
        alert('通信エラーが発生しました: ' + e.message);
        closeModal('modal-event-detail');
    }
}

function openPrintDispatchFromDetail() {
    closeModal('modal-event-detail');
    openPrintDispatchModal(currentDetailPostId, currentDetailPostTitle, currentDetailTargetDate);
}

function triggerModalLineReminder() {
    triggerLineReminder(currentDetailPostId);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

// カレンダー全幅・分割表示切り替え
function toggleCalendarExpand() {
    const rCol = document.querySelector('.right-col');
    const lCol = document.querySelector('.left-col');
    const btn = document.getElementById('btn-toggle-expand');
    if (!rCol || !lCol) return;
    if (rCol.style.display === 'none') {
        rCol.style.display = '';
        lCol.style.flex = '62';
        if (btn) btn.innerHTML = '⛶ 全幅表示';
        localStorage.setItem('kawara_cal_expand', '0');
    } else {
        rCol.style.display = 'none';
        lCol.style.flex = '100';
        if (btn) btn.innerHTML = '🗗 分割表示に戻す';
        localStorage.setItem('kawara_cal_expand', '1');
    }
}

// ユーザーの前回選択を反映
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('kawara_cal_expand') === '1') {
        const rCol = document.querySelector('.right-col');
        const lCol = document.querySelector('.left-col');
        const btn = document.getElementById('btn-toggle-expand');
        if (rCol && lCol) {
            rCol.style.display = 'none';
            lCol.style.flex = '100';
            if (btn) btn.innerHTML = '🗗 分割表示に戻す';
        }
    }
});
</script>

</body>
</html>
