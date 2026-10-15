<?php
/**
 * 院内かわら版 - 外部連携API 共通ヘルパー
 * File: includes/api_helper.php
 */

require_once __DIR__ . '/db.php';

/**
 * CORSおよび共通APIヘッダーを送信し、OPTIONSプリフライトを処理する
 */
function init_api_headers() {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Staff-Id, X-Staff-Code');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }

    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * システムのベースURL（例: http://192.168.1.16/kawara）を取得
 */
function get_api_base_url(): string {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $is_https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // スクリプトパスから /kawara を抽出
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('#^(/[^/]+)#', $script, $m)) {
        $sub = rtrim($m[1], '/');
        // ルート直下ではなく /kawara などのサブディレクトリなら含める
        if ($sub !== '/api') {
            return "{$scheme}://{$host}{$sub}";
        }
    }
    return "{$scheme}://{$host}/kawara";
}

/**
 * 成功JSONレスポンスを返して終了
 */
function send_api_response(array $data, int $status_code = 200) {
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * エラーJSONレスポンスを返して終了
 */
function send_api_error(string $message, int $status_code = 400, string $error_code = 'BAD_REQUEST', array $extra = []) {
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    $resp = array_merge([
        'success'    => false,
        'error_code' => $error_code,
        'message'    => $message
    ], $extra);
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 日本語フォーマットの日時文字列を生成
 */
function format_datetime_ja(?string $dt_str, bool $include_time = true): string {
    if (!$dt_str) return '';
    $ts = strtotime($dt_str);
    if (!$ts) return $dt_str;

    $week_names = ['日', '月', '火', '水', '木', '金', '土'];
    $w = $week_names[(int)date('w', $ts)];
    $d = date('Y/m/d', $ts) . "({$w})";

    if ($include_time) {
        $t = date('H:i', $ts);
        if ($t !== '00:00') {
            return "{$d} {$t}";
        }
    }
    return $d;
}

/**
 * 開催日時の期間フォーマット文字列（例: 2026/10/22(木) 09:00〜16:00）
 */
function format_date_range_ja(?string $start_str, ?string $end_str): string {
    if (!$start_str) return '';
    $s_ts = strtotime($start_str);
    if (!$s_ts) return '';

    $week_names = ['日', '月', '火', '水', '木', '金', '土'];
    $s_w = $week_names[(int)date('w', $s_ts)];
    $s_d = date('Y/m/d', $s_ts);
    $s_t = date('H:i', $s_ts);

    if (!$end_str) {
        return "{$s_d}({$s_w}) " . ($s_t !== '00:00' ? $s_t : '');
    }

    $e_ts = strtotime($end_str);
    if (!$e_ts) {
        return "{$s_d}({$s_w}) " . ($s_t !== '00:00' ? $s_t : '');
    }

    $e_d = date('Y/m/d', $e_ts);
    $e_w = $week_names[(int)date('w', $e_ts)];
    $e_t = date('H:i', $e_ts);

    if ($s_d === $e_d) {
        if ($s_t === '00:00' && ($e_t === '23:59' || $e_t === '00:00')) {
            return "{$s_d}({$s_w}) 終日";
        }
        return "{$s_d}({$s_w}) {$s_t}〜{$e_t}";
    }

    return "{$s_d}({$s_w}) {$s_t} 〜 {$e_d}({$e_w}) {$e_t}";
}

/**
 * 記事行データをAPI用の整形された連想配列に変換する
 *
 * @param array $p 投稿データ
 * @param PDO $pdo
 * @param string $base_url
 * @param array $options ['full' => bool, 'staff_id' => int, 'dept_id' => int]
 * @return array
 */
function format_post_item(array $p, PDO $pdo, string $base_url, array $options = []): array {
    $full = !empty($options['full']);
    $target_staff_id = !empty($options['staff_id']) ? (int)$options['staff_id'] : 0;
    $post_id = (int)$p['post_id'];

    // 開催期間文字列
    $period_label = format_date_range_ja($p['target_datetime'] ?? null, $p['target_end_datetime'] ?? null);

    // 本文の加工（プレーンテキスト、HTMLセーフ版、短縮要約）
    $raw_content = $p['content'] ?? '';
    $plain_text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $raw_content)));
    $summary = mb_substr($plain_text, 0, 140);
    if (mb_strlen($plain_text) > 140) {
        $summary .= '...';
    }

    // 期限切れ判定
    $is_expired = false;
    if (!empty($p['display_until'])) {
        $is_expired = (strtotime($p['display_until']) < time());
    } elseif (!empty($p['target_datetime'])) {
        $end_dt = !empty($p['target_end_datetime']) ? $p['target_end_datetime'] : $p['target_datetime'];
        $is_expired = (date('Y-m-d', strtotime($end_dt)) < date('Y-m-d'));
    }

    // event_schedules のパース
    $event_schedules = [];
    if (!empty($p['event_schedules'])) {
        $raw_slots = is_string($p['event_schedules']) ? json_decode($p['event_schedules'], true) : $p['event_schedules'];
        if (is_array($raw_slots)) {
            $event_schedules = $raw_slots;
        }
    }

    // カテゴリー情報
    $category = [
        'id'         => (int)($p['category_id'] ?? 0),
        'code'       => $p['category_code'] ?? 'general',
        'name'       => $p['category_name'] ?? 'お知らせ',
        'icon_emoji' => $p['icon_emoji'] ?? '📄',
        'color_code' => $p['color_code'] ?? '#005a9c'
    ];

    // 投稿者情報
    $author = [
        'staff_id' => (int)($p['author_id'] ?? 0),
        'name'     => $p['author_name'] ?? ($p['staff_name'] ?? '管理者'),
        'dept'     => $p['author_dept'] ?? ''
    ];

    // 添付画像
    $images = [];
    $image_count = isset($p['image_count']) ? (int)$p['image_count'] : 0;
    if ($full || $image_count > 0) {
        $stmt_img = $pdo->prepare("SELECT image_id, file_path, file_name, file_size FROM post_images WHERE post_id = :pid ORDER BY image_id ASC");
        $stmt_img->execute([':pid' => $post_id]);
        $raw_images = $stmt_img->fetchAll();
        $image_count = count($raw_images);
        foreach ($raw_images as $img) {
            $f_path = ltrim($img['file_path'], '/');
            $images[] = [
                'image_id'  => (int)$img['image_id'],
                'file_name' => $img['file_name'],
                'file_size' => (int)$img['file_size'],
                'file_path' => $f_path,
                'url'       => "{$base_url}/{$f_path}"
            ];
        }
    }

    // 既読・意思表示サマリー（full時のみ、または指定スタッフ時）
    $read_summary = null;
    if ($full) {
        $stmt_rs = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_reads,
                COUNT(CASE WHEN response_status = 'ok' THEN 1 END) AS ok_count,
                COUNT(CASE WHEN response_status = 'question' THEN 1 END) AS question_count,
                COUNT(CASE WHEN response_status = 'absence' THEN 1 END) AS absence_count,
                COUNT(CASE WHEN response_status = 'read' OR response_status IS NULL THEN 1 END) AS read_only_count
            FROM post_reads
            WHERE post_id = :pid
        ");
        $stmt_rs->execute([':pid' => $post_id]);
        $rs = $stmt_rs->fetch();
        $read_summary = [
            'total_reads'    => (int)($rs['total_reads'] ?? 0),
            'ok_count'       => (int)($rs['ok_count'] ?? 0),
            'question_count' => (int)($rs['question_count'] ?? 0),
            'absence_count'  => (int)($rs['absence_count'] ?? 0),
            'read_count'     => (int)($rs['read_only_count'] ?? 0)
        ];
    }

    // 対象部署
    $target_departments = [];
    $is_all_depts = true;
    if ($full) {
        $stmt_td = $pdo->prepare("
            SELECT td.dept_id, td.dept_name, td.dept_code 
            FROM post_target_departments ptd
            JOIN target_departments td ON ptd.dept_id = td.dept_id
            WHERE ptd.post_id = :pid
            ORDER BY td.display_order ASC
        ");
        $stmt_td->execute([':pid' => $post_id]);
        $target_departments = $stmt_td->fetchAll();
        $is_all_depts = empty($target_departments);
    }

    // 対象スタッフ
    $target_staff = [];
    $is_all_staff = true;
    if ($full) {
        $stmt_ts = $pdo->prepare("
            SELECT s.staff_id, s.staff_name, s.dept_id, s.role
            FROM post_target_staff pts
            JOIN staff s ON pts.staff_id = s.staff_id
            WHERE pts.post_id = :pid
            ORDER BY s.kana ASC
        ");
        $stmt_ts->execute([':pid' => $post_id]);
        $target_staff = $stmt_ts->fetchAll();
        $is_all_staff = empty($target_staff);
    }

    // 指定スタッフの既読状況判定
    $staff_status = null;
    if ($target_staff_id > 0) {
        $stmt_my_read = $pdo->prepare("SELECT response_status, response_at, read_at, response_comment FROM post_reads WHERE post_id = :pid AND staff_id = :sid");
        $stmt_my_read->execute([':pid' => $post_id, ':sid' => $target_staff_id]);
        $mr = $stmt_my_read->fetch();

        // 対象判定
        $is_target = true;
        if (!empty($target_departments) || !empty($target_staff)) {
            $stmt_st_info = $pdo->prepare("SELECT dept_id FROM staff WHERE staff_id = :sid");
            $stmt_st_info->execute([':sid' => $target_staff_id]);
            $s_info = $stmt_st_info->fetch();
            $my_dept = (int)($s_info['dept_id'] ?? 0);

            $in_dept = empty($target_departments) || in_array($my_dept, array_column($target_departments, 'dept_id'));
            $in_staff = empty($target_staff) || in_array($target_staff_id, array_column($target_staff, 'staff_id'));
            $is_target = $in_dept || $in_staff;
        }

        $staff_status = [
            'staff_id'         => $target_staff_id,
            'is_target'        => $is_target,
            'is_read'          => !empty($mr),
            'response_status'  => $mr['response_status'] ?? null,
            'response_at'      => $mr['response_at'] ?? null,
            'read_at'          => $mr['read_at'] ?? null,
            'response_comment' => $mr['response_comment'] ?? null
        ];
    }

    $item = [
        'post_id'                  => $post_id,
        'title'                    => $p['title'] ?? '',
        'summary'                  => $summary,
        'category'                 => $category,
        'is_pinned'                => !empty($p['is_pinned']),
        'target_datetime'          => $p['target_datetime'] ?? null,
        'target_end_datetime'      => $p['target_end_datetime'] ?? null,
        'target_period_formatted'  => $period_label,
        'display_until'            => $p['display_until'] ?? null,
        'is_expired'               => $is_expired,
        'author'                   => $author,
        'images'                   => $images,
        'image_count'              => $image_count,
        'comment_count'            => isset($p['comment_count']) ? (int)$p['comment_count'] : 0,
        'has_schedules'            => !empty($event_schedules),
        'view_url'                 => "{$base_url}/view_post.php?id={$post_id}",
        'created_at'               => $p['created_at'] ?? null,
        'updated_at'               => $p['updated_at'] ?? null
    ];

    if ($staff_status !== null) {
        $item['staff_status'] = $staff_status;
    }

    if ($full) {
        $item['content']             = $raw_content;
        $item['content_plain']       = $plain_text;
        $item['content_html_safe']   = nl2br(htmlspecialchars($plain_text, ENT_QUOTES, 'UTF-8'));
        $item['event_schedules']     = $event_schedules;
        $item['target_departments']  = $target_departments;
        $item['is_all_departments']  = $is_all_depts;
        $item['target_staff']        = $target_staff;
        $item['is_all_staff']        = $is_all_staff;
        $item['read_summary']        = $read_summary;
    }

    return $item;
}

/**
 * 複数記事からカレンダー・スケジュール用イベント配列を展開する
 *
 * @param array $posts
 * @param string $base_url
 * @return array
 */
function expand_posts_to_calendar_events(array $posts, string $base_url): array {
    $events = [];

    foreach ($posts as $p) {
        $post_id = (int)$p['post_id'];
        $title = $p['title'] ?? '（無題）';
        $category_name = $p['category_name'] ?? 'お知らせ';
        $category_code = $p['category_code'] ?? 'general';
        $color = $p['color_code'] ?? '#005a9c';
        $icon = $p['icon_emoji'] ?? '📅';
        $view_url = "{$base_url}/view_post.php?id={$post_id}";

        // event_schedules が存在するか確認
        $has_slots = false;
        if (!empty($p['event_schedules'])) {
            $raw_slots = is_string($p['event_schedules']) ? json_decode($p['event_schedules'], true) : $p['event_schedules'];
            if (is_array($raw_slots) && count($raw_slots) > 0) {
                $has_slots = true;
                $total_slots = count($raw_slots);
                foreach ($raw_slots as $idx => $slot) {
                    $s_date = !empty($slot['date']) ? $slot['date'] : substr($slot['start_datetime'] ?? '', 0, 10);
                    if (empty($s_date)) continue;

                    $is_all_day = !empty($slot['is_all_day']);
                    $s_time = $is_all_day ? '' : (!empty($slot['start_time']) ? $slot['start_time'] : (!empty($slot['start_datetime']) ? date('H:i', strtotime($slot['start_datetime'])) : ''));
                    $e_time = $is_all_day ? '' : (!empty($slot['end_time']) ? $slot['end_time'] : (!empty($slot['end_datetime']) ? date('H:i', strtotime($slot['end_datetime'])) : ''));

                    $start_dt = !empty($slot['start_datetime']) ? $slot['start_datetime'] : ($s_date . ($s_time !== '' ? " {$s_time}:00" : " 00:00:00"));
                    $end_dt   = !empty($slot['end_datetime']) ? $slot['end_datetime'] : ((!empty($slot['end_date']) ? $slot['end_date'] : $s_date) . ($e_time !== '' ? " {$e_time}:00" : " 23:59:59"));

                    $slot_num = $idx + 1;
                    $badge = $total_slots > 1 ? "[第{$slot_num}回]" : "";
                    $display_title = ($idx > 0 ? "（続）" : "") . $title . ($badge ? " {$badge}" : "");

                    $events[] = [
                        'id'             => "post_{$post_id}_slot_{$idx}",
                        'post_id'        => $post_id,
                        'slot_index'     => $idx,
                        'title'          => $display_title,
                        'raw_title'      => $title,
                        'date'           => $s_date,
                        'start_time'     => $s_time,
                        'end_time'       => $e_time,
                        'start'          => $start_dt,
                        'end'            => $end_dt,
                        'all_day'        => $is_all_day,
                        'category_id'    => (int)($p['category_id'] ?? 0),
                        'category_code'  => $category_code,
                        'category_name'  => $category_name,
                        'color'          => $color,
                        'icon'           => $icon,
                        'location'       => $slot['location'] ?? '',
                        'memo'           => $slot['memo'] ?? '',
                        'badge'          => $badge,
                        'is_pinned'      => !empty($p['is_pinned']),
                        'view_url'       => $view_url
                    ];
                }
            }
        }

        // event_schedules がなく target_datetime のみの場合
        if (!$has_slots && !empty($p['target_datetime'])) {
            $s_date = substr($p['target_datetime'], 0, 10);
            $s_time = date('H:i', strtotime($p['target_datetime']));
            $is_all_day = ($s_time === '00:00' && empty($p['target_end_datetime']));
            $e_time = !empty($p['target_end_datetime']) ? date('H:i', strtotime($p['target_end_datetime'])) : '';

            $events[] = [
                'id'             => "post_{$post_id}",
                'post_id'        => $post_id,
                'slot_index'     => 0,
                'title'          => $title,
                'raw_title'      => $title,
                'date'           => $s_date,
                'start_time'     => $is_all_day ? '' : $s_time,
                'end_time'       => $e_time,
                'start'          => $p['target_datetime'],
                'end'            => $p['target_end_datetime'] ?? $p['target_datetime'],
                'all_day'        => $is_all_day,
                'category_id'    => (int)($p['category_id'] ?? 0),
                'category_code'  => $category_code,
                'category_name'  => $category_name,
                'color'          => $color,
                'icon'           => $icon,
                'location'       => '',
                'memo'           => '',
                'badge'          => '',
                'is_pinned'      => !empty($p['is_pinned']),
                'view_url'       => $view_url
            ];
        }
    }

    // 日時昇順でソート
    usort($events, function($a, $b) {
        $cmp = strcmp($a['start'], $b['start']);
        if ($cmp === 0) {
            return $a['post_id'] <=> $b['post_id'];
        }
        return $cmp;
    });

    return $events;
}
