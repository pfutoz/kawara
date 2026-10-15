<?php
/**
 * 院内かわら版 - 外部連携API メインエンドポイント
 * エンドポイント: /kawara/api/posts.php
 * 
 * 記事一覧・詳細・検索・イベント・統計・カテゴリーを外部システムへ提供
 */

require_once __DIR__ . '/../includes/api_helper.php';

// APIヘッダー初期化 ＆ CORS対応
init_api_headers();

$base_url = get_api_base_url();
$action = trim($_GET['action'] ?? '');
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    // 1. カテゴリー一覧アクション
    if ($action === 'categories') {
        handle_categories_action($pdo);
    }

    // 2. カレンダー・サイネージ用イベント一覧アクション
    if ($action === 'events') {
        handle_events_action($pdo, $base_url);
    }

    // 3. ダッシュボード・ヘッダー用サマリー統計アクション
    if ($action === 'stats') {
        handle_stats_action($pdo);
    }

    // 4. 単一記事の詳細取得
    if ($post_id > 0) {
        handle_single_post_action($pdo, $post_id, $base_url);
    }

    // 5. デフォルト: 記事一覧・検索取得
    handle_posts_list_action($pdo, $base_url);

} catch (Exception $e) {
    error_log('Kawara API Error: ' . $e->getMessage());
    send_api_error('サーバー内部エラーが発生しました: ' . $e->getMessage(), 500, 'INTERNAL_SERVER_ERROR');
}

/**
 * -------------------------------------------------------------------------
 * アクション: カテゴリー一覧取得
 * -------------------------------------------------------------------------
 */
function handle_categories_action(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT 
            c.category_id,
            c.category_code,
            c.category_name,
            c.icon_emoji,
            c.color_code,
            c.display_order,
            (
                SELECT COUNT(*) 
                FROM posts p 
                WHERE p.category_id = c.category_id 
                  AND (p.display_until IS NULL OR p.display_until >= NOW())
            ) AS active_post_count,
            (
                SELECT MAX(p.created_at) 
                FROM posts p 
                WHERE p.category_id = c.category_id
            ) AS latest_post_at
        FROM post_categories c
        WHERE c.is_active = TRUE
        ORDER BY c.display_order ASC, c.category_id ASC
    ");
    $categories = $stmt->fetchAll();

    $result = [];
    foreach ($categories as $cat) {
        $result[] = [
            'category_id'       => (int)$cat['category_id'],
            'category_code'     => $cat['category_code'],
            'category_name'     => $cat['category_name'],
            'icon_emoji'        => $cat['icon_emoji'] ?? '📄',
            'color_code'        => $cat['color_code'] ?? '#005a9c',
            'display_order'     => (int)$cat['display_order'],
            'active_post_count' => (int)$cat['active_post_count'],
            'latest_post_at'    => $cat['latest_post_at']
        ];
    }

    send_api_response([
        'action'     => 'categories',
        'count'      => count($result),
        'categories' => $result
    ]);
}

/**
 * -------------------------------------------------------------------------
 * アクション: カレンダー用イベント展開一覧取得
 * -------------------------------------------------------------------------
 */
function handle_events_action(PDO $pdo, string $base_url) {
    $today = date('Y-m-d');
    $start_date = $_GET['start'] ?? ($_GET['start_date'] ?? date('Y-m-01'));
    $end_date   = $_GET['end']   ?? ($_GET['end_date']   ?? date('Y-m-t', strtotime('+1 month')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   $end_date   = date('Y-m-t', strtotime('+1 month'));

    $sql = "
        SELECT p.*, c.category_name, c.category_code, c.icon_emoji, c.color_code, s.staff_name
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id
        WHERE (
            (DATE(p.target_datetime) >= :s_start AND DATE(p.target_datetime) <= :s_end)
            OR (p.event_schedules IS NOT NULL AND p.event_schedules::text != '[]' AND p.event_schedules::text != 'null')
        )
    ";
    $params = [
        ':s_start' => $start_date,
        ':s_end'   => $end_date
    ];

    // カテゴリー絞り込み
    if (!empty($_GET['category'])) {
        $cat = $_GET['category'];
        if (is_numeric($cat)) {
            $sql .= " AND p.category_id = :cat_id";
            $params[':cat_id'] = (int)$cat;
        } else {
            $sql .= " AND c.category_code = :cat_code";
            $params[':cat_code'] = $cat;
        }
    }

    $sql .= " ORDER BY p.target_datetime ASC, p.post_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    // 日付ごとにイベントを展開
    $all_events = expand_posts_to_calendar_events($posts, $base_url);

    // 範囲内のイベントのみをフィルタリング
    $filtered = array_values(array_filter($all_events, function($ev) use ($start_date, $end_date) {
        return ($ev['date'] >= $start_date && $ev['date'] <= $end_date);
    }));

    send_api_response([
        'action'     => 'events',
        'start_date' => $start_date,
        'end_date'   => $end_date,
        'count'      => count($filtered),
        'events'     => $filtered
    ]);
}

/**
 * -------------------------------------------------------------------------
 * アクション: 統計サマリー取得
 * -------------------------------------------------------------------------
 */
function handle_stats_action(PDO $pdo) {
    $today_str = date('Y-m-d');
    $staff_id = resolve_staff_id($pdo);

    // 掲載中件数
    $total_active = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE (display_until IS NULL OR display_until >= NOW())")->fetchColumn();

    // 緊急・重要件数
    $urgent_count = (int)$pdo->query("
        SELECT COUNT(*) FROM posts p 
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        WHERE (p.display_until IS NULL OR p.display_until >= NOW())
          AND (p.is_pinned = TRUE OR c.category_code IN ('urgent', 'important'))
    ")->fetchColumn();

    // 本日の予定件数
    $today_count = (int)$pdo->query("
        SELECT COUNT(*) FROM posts p 
        WHERE (
            DATE(p.target_datetime) = '{$today_str}' 
            OR (p.event_schedules IS NOT NULL AND p.event_schedules::text LIKE '%{$today_str}%')
        )
    ")->fetchColumn();

    // 未読件数（staff_id指定時）
    $unread_count = null;
    if ($staff_id > 0) {
        $stmt_un = $pdo->prepare("
            SELECT COUNT(*) FROM posts p
            WHERE (p.display_until IS NULL OR p.display_until >= NOW())
              AND NOT EXISTS (
                  SELECT 1 FROM post_reads pr WHERE pr.post_id = p.post_id AND pr.staff_id = :sid
              )
        ");
        $stmt_un->execute([':sid' => $staff_id]);
        $unread_count = (int)$stmt_un->fetchColumn();
    }

    // 災害モードステータス
    $active_event = $pdo->query("SELECT * FROM safety_events WHERE is_active = TRUE ORDER BY event_id DESC LIMIT 1")->fetch();
    $is_disaster = !empty($active_event['is_disaster_mode']);

    send_api_response([
        'action'             => 'stats',
        'date'               => $today_str,
        'is_disaster_mode'   => $is_disaster,
        'total_active_posts' => $total_active,
        'urgent_posts_count' => $urgent_count,
        'today_events_count' => $today_count,
        'unread_count'       => $unread_count,
        'staff_id'           => $staff_id > 0 ? $staff_id : null
    ]);
}

/**
 * -------------------------------------------------------------------------
 * アクション: 単一記事の詳細取得
 * -------------------------------------------------------------------------
 */
function handle_single_post_action(PDO $pdo, int $post_id, string $base_url) {
    $staff_id = resolve_staff_id($pdo);

    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            c.category_name, c.category_code, c.icon_emoji, c.color_code,
            s.staff_name AS author_name,
            (SELECT COUNT(*) FROM post_images img WHERE img.post_id = p.post_id) AS image_count,
            (SELECT COUNT(*) FROM post_comments cm WHERE cm.post_id = p.post_id) AS comment_count
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id
        WHERE p.post_id = :pid
    ");
    $stmt->execute([':pid' => $post_id]);
    $p = $stmt->fetch();

    if (!$p) {
        send_api_error("指定された記事 (ID: {$post_id}) は存在しません。", 404, 'POST_NOT_FOUND');
    }

    $item = format_post_item($p, $pdo, $base_url, [
        'full'     => true,
        'staff_id' => $staff_id
    ]);

    // コメント詳細の追加（オプション include_comments=1）
    if (!empty($_GET['include_comments'])) {
        $stmt_c = $pdo->prepare("
            SELECT cm.comment_id, cm.post_id, cm.author_id, s.staff_name, cm.comment_text, cm.stamp_code, cm.created_at
            FROM post_comments cm
            LEFT JOIN staff s ON cm.author_id = s.staff_id
            WHERE cm.post_id = :pid
            ORDER BY cm.created_at ASC
        ");
        $stmt_c->execute([':pid' => $post_id]);
        $raw_c = $stmt_c->fetchAll();
        $comments = [];
        foreach ($raw_c as $c) {
            $comments[] = [
                'comment_id'   => (int)$c['comment_id'],
                'author_id'    => (int)$c['author_id'],
                'author_name'  => $c['staff_name'] ?? '匿名',
                'comment_text' => $c['comment_text'] ?? '',
                'stamp_code'   => $c['stamp_code'] ?? '',
                'created_at'   => $c['created_at']
            ];
        }
        $item['comments'] = $comments;
    }

    send_api_response([
        'action' => 'post_detail',
        'post'   => $item
    ]);
}

/**
 * -------------------------------------------------------------------------
 * アクション: 記事一覧取得 ＆ 検索
 * -------------------------------------------------------------------------
 */
function handle_posts_list_action(PDO $pdo, string $base_url) {
    $staff_id = resolve_staff_id($pdo);

    // ページネーション設定
    $limit  = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 10;
    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : (($page - 1) * $limit);

    // 項目精度 (fields)
    $is_full = (isset($_GET['fields']) && $_GET['fields'] === 'full');

    // ソート順
    $sort = $_GET['sort'] ?? 'created_desc';

    // フィルタ条件構築
    $where = [];
    $params = [];

    // 1. 掲載状態 / 日付フィルタ (filter)
    $filter = $_GET['filter'] ?? 'active';
    $today_str     = date('Y-m-d');
    $tomorrow_str  = date('Y-m-d', strtotime('+1 day'));
    $this_m_start  = date('Y-m-01');
    $this_m_end    = date('Y-m-t');
    $next_m_start  = date('Y-m-01', strtotime('first day of next month'));
    $next_m_end    = date('Y-m-t', strtotime('last day of next month'));

    switch ($filter) {
        case 'all':
            // 掲載期間無制限
            break;
        case 'past':
            $where[] = "(p.display_until < NOW() OR (p.display_until IS NULL AND p.target_datetime IS NOT NULL AND DATE(COALESCE(p.target_end_datetime, p.target_datetime)) < '{$today_str}'))";
            break;
        case 'pinned':
            $where[] = "p.is_pinned = TRUE";
            $where[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
            break;
        case 'urgent':
            $where[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
            $where[] = "(p.is_pinned = TRUE OR c.category_code IN ('urgent', 'important') OR (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$today_str}'))";
            break;
        case 'today':
            $where[] = "(
                (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$today_str}')
                OR (p.event_schedules IS NOT NULL AND p.event_schedules::text LIKE '%{$today_str}%')
            )";
            break;
        case 'tomorrow':
            $where[] = "(
                (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$tomorrow_str}')
                OR (p.event_schedules IS NOT NULL AND p.event_schedules::text LIKE '%{$tomorrow_str}%')
            )";
            break;
        case 'upcoming':
            $where[] = "(
                (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) >= '{$today_str}')
                OR (p.display_until IS NULL OR p.display_until >= NOW())
            )";
            break;
        case 'this_month':
            $where[] = "(p.target_datetime IS NOT NULL AND DATE(p.target_datetime) >= '{$this_m_start}' AND DATE(p.target_datetime) <= '{$this_m_end}')";
            break;
        case 'next_month':
            $where[] = "(p.target_datetime IS NOT NULL AND DATE(p.target_datetime) >= '{$next_m_start}' AND DATE(p.target_datetime) <= '{$next_m_end}')";
            break;
        case 'unread':
            // 指定スタッフの未読記事
            $where[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
            if ($staff_id > 0) {
                $where[] = "NOT EXISTS (SELECT 1 FROM post_reads pr WHERE pr.post_id = p.post_id AND pr.staff_id = :filter_staff_id)";
                $params[':filter_staff_id'] = $staff_id;
            }
            break;
        case 'active':
        default:
            $where[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
            break;
    }

    // 2. カテゴリー絞り込み
    $cat_param = $_GET['category'] ?? ($_GET['category_id'] ?? '');
    if ($cat_param !== '') {
        if (is_numeric($cat_param)) {
            $where[] = "p.category_id = :cat_id";
            $params[':cat_id'] = (int)$cat_param;
        } else {
            $where[] = "c.category_code = :cat_code";
            $params[':cat_code'] = trim($cat_param);
        }
    }

    // 3. ピン留め個別フラグ
    if (isset($_GET['is_pinned'])) {
        $val = filter_var($_GET['is_pinned'], FILTER_VALIDATE_BOOLEAN);
        $where[] = "p.is_pinned = " . ($val ? 'TRUE' : 'FALSE');
    }

    // 4. 特定日絞り込み (date)
    if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
        $d = $_GET['date'];
        $where[] = "(
            (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = :target_d)
            OR (p.event_schedules IS NOT NULL AND p.event_schedules::text LIKE :target_d_like)
        )";
        $params[':target_d'] = $d;
        $params[':target_d_like'] = "%{$d}%";
    }

    // 5. 期間指定 (date_from / date_to)
    if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
        $where[] = "(DATE(COALESCE(p.target_end_datetime, p.target_datetime, p.created_at)) >= :d_from)";
        $params[':d_from'] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
        $where[] = "(DATE(COALESCE(p.target_datetime, p.created_at)) <= :d_to)";
        $params[':d_to'] = $_GET['date_to'];
    }

    // 6. キーワード検索 (q / search)
    $search = trim($_GET['q'] ?? ($_GET['search'] ?? ''));
    if ($search !== '') {
        $words = preg_split('/\s+/', $search);
        foreach ($words as $idx => $w) {
            if ($w === '') continue;
            $p_key = ":q_word_{$idx}";
            $where[] = "(p.title ILIKE {$p_key} OR p.content ILIKE {$p_key} OR s.staff_name ILIKE {$p_key} OR c.category_name ILIKE {$p_key})";
            $params[$p_key] = '%' . $w . '%';
        }
    }

    // 7. 部署絞り込み (dept_id)
    if (!empty($_GET['dept_id']) && (int)$_GET['dept_id'] > 0) {
        $d_id = (int)$_GET['dept_id'];
        $where[] = "(
            NOT EXISTS (SELECT 1 FROM post_target_departments WHERE post_id = p.post_id)
            OR EXISTS (SELECT 1 FROM post_target_departments WHERE post_id = p.post_id AND dept_id = :req_dept_id)
        )";
        $params[':req_dept_id'] = $d_id;
    }

    // WHERE句合成
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // 総件数カウント
    $count_sql = "
        SELECT COUNT(*)
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id
        {$where_sql}
    ";
    $stmt_cnt = $pdo->prepare($count_sql);
    $stmt_cnt->execute($params);
    $total_count = (int)$stmt_cnt->fetchColumn();

    // ソート順決定
    switch ($sort) {
        case 'pinned_first':
            $order_by = "p.is_pinned DESC, p.created_at DESC";
            break;
        case 'target_asc':
            $order_by = "p.target_datetime ASC NULLS LAST, p.created_at DESC";
            break;
        case 'target_desc':
            $order_by = "p.target_datetime DESC NULLS LAST, p.created_at DESC";
            break;
        case 'id_desc':
            $order_by = "p.post_id DESC";
            break;
        case 'created_asc':
            $order_by = "p.created_at ASC";
            break;
        case 'created_desc':
        default:
            $order_by = "p.is_pinned DESC, p.created_at DESC";
            break;
    }

    // データ取得
    $data_sql = "
        SELECT 
            p.*, 
            c.category_name, c.category_code, c.icon_emoji, c.color_code,
            s.staff_name AS author_name,
            (SELECT COUNT(*) FROM post_images img WHERE img.post_id = p.post_id) AS image_count,
            (SELECT COUNT(*) FROM post_comments cm WHERE cm.post_id = p.post_id) AS comment_count
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id
        {$where_sql}
        ORDER BY {$order_by}
        LIMIT :limit OFFSET :offset
    ";

    $stmt_data = $pdo->prepare($data_sql);
    foreach ($params as $k => $v) {
        $stmt_data->bindValue($k, $v);
    }
    $stmt_data->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt_data->execute();
    $rows = $stmt_data->fetchAll();

    // アイテム整形
    $posts = [];
    foreach ($rows as $row) {
        $posts[] = format_post_item($row, $pdo, $base_url, [
            'full'     => $is_full,
            'staff_id' => $staff_id
        ]);
    }

    $total_pages = $limit > 0 ? (int)ceil($total_count / $limit) : 1;

    send_api_response([
        'action'          => 'posts_list',
        'total_count'     => $total_count,
        'page'            => $page,
        'limit'           => $limit,
        'offset'          => $offset,
        'total_pages'     => $total_pages,
        'has_next'        => ($page < $total_pages),
        'has_prev'        => ($page > 1),
        'filter_applied'  => [
            'filter'   => $filter,
            'category' => $cat_param !== '' ? $cat_param : null,
            'search'   => $search !== '' ? $search : null,
            'sort'     => $sort,
            'staff_id' => $staff_id > 0 ? $staff_id : null
        ],
        'posts'           => $posts
    ]);
}

/**
 * リクエストヘッダーまたはパラメータから staff_id を特定する
 */
function resolve_staff_id(PDO $pdo): int {
    if (!empty($_GET['staff_id']) && (int)$_GET['staff_id'] > 0) {
        return (int)$_GET['staff_id'];
    }

    // ヘッダー X-Staff-Id
    $header_sid = $_SERVER['HTTP_X_STAFF_ID'] ?? null;
    if (!empty($header_sid) && (int)$header_sid > 0) {
        return (int)$header_sid;
    }

    // staff_code による検索 (クエリまたはヘッダー)
    $stf_code = trim($_GET['staff_code'] ?? ($_SERVER['HTTP_X_STAFF_CODE'] ?? ''));
    if ($stf_code !== '') {
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_code = :code LIMIT 1");
        $stmt->execute([':code' => $stf_code]);
        $sid = $stmt->fetchColumn();
        if ($sid) {
            return (int)$sid;
        }
    }

    return 0;
}
