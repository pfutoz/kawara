<?php
/**
 * 院内かわら版 - 外部埋め込み用ウィジェット (iframe対応)
 * エンドポイント: /kawara/api/widget.php
 * 
 * 外部のWebシステムやポータル、サイネージに <iframe> でそのまま埋め込める
 * 高品位・レスポンシブなお知らせコンポーネント
 */

require_once __DIR__ . '/../includes/api_helper.php';

$base_url = get_api_base_url();

$theme   = $_GET['theme'] ?? 'light'; // light, dark, hospital, compact
$layout  = $_GET['layout'] ?? 'list'; // list, cards, ticker
$limit   = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 5;
$filter  = $_GET['filter'] ?? 'active';
$cat     = $_GET['category'] ?? ($_GET['category_id'] ?? '');
$target  = $_GET['target'] ?? '_blank';
$show_header = !isset($_GET['header']) || $_GET['header'] !== '0';

// API内部呼び出しでデータを取得
$_GET['limit'] = $limit;
$_GET['filter'] = $filter;
if ($cat !== '') $_GET['category'] = $cat;
$_GET['fields'] = 'summary';

// posts.php と同様のクエリを実行
$where = [];
$params = [];
$today_str = date('Y-m-d');

if ($filter === 'urgent') {
    $where[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
    $where[] = "(p.is_pinned = TRUE OR c.category_code IN ('urgent', 'important') OR (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$today_str}'))";
} elseif ($filter === 'today') {
    $where[] = "(
        (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$today_str}')
        OR (p.event_schedules IS NOT NULL AND p.event_schedules::text LIKE '%{$today_str}%')
    )";
} elseif ($filter === 'pinned') {
    $where[] = "p.is_pinned = TRUE AND (p.display_until IS NULL OR p.display_until >= NOW())";
} else {
    $where[] = "(p.display_until IS NULL OR p.display_until >= NOW())";
}

if ($cat !== '') {
    if (is_numeric($cat)) {
        $where[] = "p.category_id = :cat_id";
        $params[':cat_id'] = (int)$cat;
    } else {
        $where[] = "c.category_code = :cat_code";
        $params[':cat_code'] = $cat;
    }
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "
    SELECT p.*, c.category_name, c.category_code, c.icon_emoji, c.color_code, s.staff_name AS author_name,
           (SELECT COUNT(*) FROM post_images img WHERE img.post_id = p.post_id) AS image_count
    FROM posts p
    LEFT JOIN post_categories c ON p.category_id = c.category_id
    LEFT JOIN staff s ON p.author_id = s.staff_id
    {$where_sql}
    ORDER BY p.is_pinned DESC, p.created_at DESC
    LIMIT {$limit}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// 災害モード状態判定
$safety = $pdo->query("SELECT * FROM safety_events WHERE is_active = TRUE ORDER BY event_id DESC LIMIT 1")->fetch();
$is_disaster = !empty($safety['is_disaster_mode']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>院内かわら版 ウィジェット</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #005a9c;
            --primary-dark: #004085;
            --primary-light: #eff6ff;
            --bg-color: <?= $theme === 'dark' ? '#0f172a' : ($theme === 'hospital' ? '#f0fdf4' : '#ffffff') ?>;
            --card-bg: <?= $theme === 'dark' ? '#1e293b' : '#ffffff' ?>;
            --text-main: <?= $theme === 'dark' ? '#f8fafc' : '#1e293b' ?>;
            --text-sub: <?= $theme === 'dark' ? '#94a3b8' : '#64748b' ?>;
            --border-color: <?= $theme === 'dark' ? '#334155' : '#e2e8f0' ?>;
            --hover-bg: <?= $theme === 'dark' ? '#334155' : '#f8fafc' ?>;
        }

        body {
            font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
            font-size: 13px;
            line-height: 1.5;
            overflow-x: hidden;
        }

        .widget-wrap {
            padding: 8px 12px;
            max-width: 100%;
        }

        .widget-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 8px;
            margin-bottom: 8px;
            border-bottom: 2px solid var(--primary);
        }

        .widget-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 800;
            color: var(--primary);
            text-decoration: none;
        }
        .widget-title:hover { opacity: 0.85; }

        .disaster-badge {
            background: #ef4444;
            color: #fff;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: bold;
            animation: pulse 1.8s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }

        .widget-more-link {
            font-size: 11px;
            color: var(--text-sub);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 2px;
            transition: color 0.15s;
        }
        .widget-more-link:hover { color: var(--primary); }

        /* リストレイアウト */
        .widget-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .widget-item {
            display: block;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 10px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s;
        }
        .widget-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.06);
            background: var(--hover-bg);
            border-color: #cbd5e1;
        }

        .widget-item.is-pinned {
            border-left: 4px solid #ef4444;
            background: <?= $theme === 'dark' ? '#291b1e' : '#fff8f8' ?>;
        }

        .item-top {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
            flex-wrap: wrap;
        }

        .cat-chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            color: #fff;
        }

        .pin-chip {
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            padding: 1px 5px;
            border-radius: 3px;
        }

        .item-date {
            font-size: 10.5px;
            color: var(--text-sub);
            margin-left: auto;
        }

        .item-title {
            font-size: 12.5px;
            font-weight: 700;
            line-height: 1.4;
            margin-bottom: 3px;
            color: var(--text-main);
        }

        .item-summary {
            font-size: 11px;
            color: var(--text-sub);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        }

        .item-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 10px;
            color: var(--text-sub);
        }

        .item-author {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .item-icons {
            display: flex;
            gap: 6px;
        }

        /* ティッカーレイアウト */
        .widget-ticker {
            display: flex;
            align-items: center;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 6px 10px;
            overflow: hidden;
            gap: 8px;
        }
        .ticker-label {
            background: var(--primary);
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .ticker-content {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-decoration: none;
            color: var(--text-main);
            font-weight: 600;
            font-size: 12px;
        }
        .ticker-content:hover { text-decoration: underline; color: var(--primary); }

        /* カードレイアウト */
        .widget-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 8px;
        }

        .empty-notice {
            text-align: center;
            padding: 20px;
            color: var(--text-sub);
            font-size: 12px;
        }
    </style>
</head>
<body>

<div class="widget-wrap">
    <?php if ($show_header): ?>
    <div class="widget-header">
        <a href="<?= htmlspecialchars($base_url) ?>/kawara_list.php" target="<?= htmlspecialchars($target) ?>" class="widget-title">
            <span>📰</span>
            <span>院内かわら版</span>
            <?php if ($is_disaster): ?>
                <span class="disaster-badge">🚨 災害対策本部発令中</span>
            <?php endif; ?>
        </a>
        <a href="<?= htmlspecialchars($base_url) ?>/kawara_list.php" target="<?= htmlspecialchars($target) ?>" class="widget-more-link">
            一覧を見る <span>&rarr;</span>
        </a>
    </div>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
        <div class="empty-notice">
            現在、表示対象のお知らせはありません。
        </div>
    <?php elseif ($layout === 'ticker'): ?>
        <?php $tp = $posts[0]; ?>
        <div class="widget-ticker">
            <span class="ticker-label"><?= htmlspecialchars($tp['category_name'] ?? 'お知らせ') ?></span>
            <a href="<?= htmlspecialchars($base_url) ?>/view_post.php?id=<?= (int)$tp['post_id'] ?>" target="<?= htmlspecialchars($target) ?>" class="ticker-content">
                <?= !empty($tp['is_pinned']) ? '📌 ' : '' ?><?= htmlspecialchars($tp['title']) ?>
            </a>
        </div>
    <?php else: ?>
        <div class="<?= $layout === 'cards' ? 'widget-cards' : 'widget-list' ?>">
            <?php foreach ($posts as $p): 
                $post_id = (int)$p['post_id'];
                $cat_color = !empty($p['color_code']) ? $p['color_code'] : '#005a9c';
                $plain_content = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $p['content'] ?? '')));
                $short_desc = mb_substr($plain_content, 0, 70) . (mb_strlen($plain_content) > 70 ? '...' : '');
                $p_date = date('n/j', strtotime($p['created_at']));
                if (!empty($p['target_datetime'])) {
                    $p_date = date('n/j', strtotime($p['target_datetime']));
                }
            ?>
            <a href="<?= htmlspecialchars($base_url) ?>/view_post.php?id=<?= $post_id ?>" target="<?= htmlspecialchars($target) ?>" class="widget-item <?= !empty($p['is_pinned']) ? 'is-pinned' : '' ?>">
                <div class="item-top">
                    <?php if (!empty($p['is_pinned'])): ?>
                        <span class="pin-chip">📌 注目</span>
                    <?php endif; ?>
                    <span class="cat-chip" style="background: <?= htmlspecialchars($cat_color) ?>;">
                        <?= htmlspecialchars($p['icon_emoji'] ?? '📄') ?> <?= htmlspecialchars($p['category_name'] ?? 'お知らせ') ?>
                    </span>
                    <span class="item-date"><?= htmlspecialchars($p_date) ?></span>
                </div>

                <div class="item-title">
                    <?= htmlspecialchars($p['title']) ?>
                </div>

                <?php if ($short_desc !== ''): ?>
                    <div class="item-summary">
                        <?= htmlspecialchars($short_desc) ?>
                    </div>
                <?php endif; ?>

                <div class="item-bottom">
                    <span class="item-author">👤 <?= htmlspecialchars($p['author_name'] ?? '担当者') ?></span>
                    <div class="item-icons">
                        <?php if (!empty($p['image_count']) && (int)$p['image_count'] > 0): ?>
                            <span>📷 <?= (int)$p['image_count'] ?></span>
                        <?php endif; ?>
                        <?php if (!empty($p['event_schedules']) && $p['event_schedules'] !== '[]'): ?>
                            <span>🗓️</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
