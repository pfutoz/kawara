<?php
/**
 * 医療法人小野会 医師予定表・休診情報ビューア（スマホ・外部ブラウザ完全対応）
 * File: doctor_schedule.php
 * 
 * LINEリッチメニューや外部ブラウザから1タップで閲覧可能。
 * かわら版の30日端末Cookie認証と完全連動し、追加ログインなしで即座に表示されます。
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/doctor_schedule_helper.php';

// 📱 端末固定Cookieがあれば自動復元！なければlogin.phpへ
$login_user = checkAuthOrAutoLogin($pdo, $_SERVER['REQUEST_URI'] ?? 'doctor_schedule.php');

$is_admin = (!empty($login_user['is_admin']) || (int)($login_user['staff_id'] ?? 0) === 15);

// -------------------------------------------------------------
// パラメータ取得
// -------------------------------------------------------------
$view = $_GET['view'] ?? 'today'; // 'today', 'week', 'month'
if (!in_array($view, ['today', 'week', 'month'], true)) {
    $view = 'today';
}

$target_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
    $target_date = date('Y-m-d');
}

$target_ts = strtotime($target_date);
$selected_doctor_id = isset($_GET['doctor_id']) && is_numeric($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

// 医師マスタ取得
$doctors = fetch_doctors_list();

// -------------------------------------------------------------
// 表示期間・データ取得
// -------------------------------------------------------------
$week_names = ['日', '月', '火', '水', '木', '金', '土'];

// 本日サマリー＆申し送りメモ取得
$today_summary = fetch_doctor_today_summary($target_date);
$has_memo = !empty($today_summary['memo']['has_memo']);
$memo_data = $today_summary['memo'] ?? null;

// ビュー別の期間計算
if ($view === 'today') {
    $query_start = $target_date;
    $query_end = $target_date;
    $prev_date = date('Y-m-d', strtotime('-1 day', $target_ts));
    $next_date = date('Y-m-d', strtotime('+1 day', $target_ts));
} elseif ($view === 'week') {
    // 月曜始まりの1週間
    $day_of_week = (int)date('N', $target_ts); // 1 (月) 〜 7 (日)
    $week_start_ts = strtotime('-' . ($day_of_week - 1) . ' days', $target_ts);
    $week_end_ts = strtotime('+6 days', $week_start_ts);
    $query_start = date('Y-m-d', $week_start_ts);
    $query_end = date('Y-m-d', $week_end_ts);
    $prev_date = date('Y-m-d', strtotime('-7 days', $target_ts));
    $next_date = date('Y-m-d', strtotime('+7 days', $target_ts));
} else { // month
    $year = (int)date('Y', $target_ts);
    $month = (int)date('m', $target_ts);
    $first_day_ts = strtotime("{$year}-{$month}-01");
    $days_in_month = (int)date('t', $first_day_ts);
    
    // カレンダーグリッド用に月曜〜日曜枠を算出
    $first_dow = (int)date('N', $first_day_ts); // 1〜7
    $cal_start_ts = strtotime('-' . ($first_dow - 1) . ' days', $first_day_ts);
    $last_day_ts = strtotime("{$year}-{$month}-{$days_in_month}");
    $last_dow = (int)date('N', $last_day_ts);
    $cal_end_ts = strtotime('+' . (7 - $last_dow) . ' days', $last_day_ts);
    
    $query_start = date('Y-m-d', $cal_start_ts);
    $query_end = date('Y-m-d', $cal_end_ts);
    $prev_date = date('Y-m-d', strtotime('-1 month', $first_day_ts));
    $next_date = date('Y-m-d', strtotime('+1 month', $first_day_ts));
}

// 予定イベント取得
$options = ['expand_period' => 1];
if ($selected_doctor_id > 0) {
    $options['doctor_id'] = $selected_doctor_id;
}
$events_raw = fetch_doctor_events($query_start, $query_end, $options);
$events_by_date = map_doctor_events_by_date($events_raw);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>医師予定表・休診情報 | 医療法人小野会</title>
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #003e6d;
            --primary-light: #e0f2fe;
            --accent-red: #dc2626;
            --accent-red-bg: #fef2f2;
            --accent-orange: #ea580c;
            --accent-green: #16a34a;
            --accent-green-bg: #f0fdf4;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.08), 0 2px 4px -1px rgba(0,0,0,0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 70px;
        }

        /* 📱 ヘッダー（固定） */
        header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff;
            box-shadow: var(--shadow-md);
            padding: 10px 16px;
        }

        .header-content {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand-box {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #fff;
        }

        .brand-icon {
            font-size: 1.7rem;
            line-height: 1;
        }

        .brand-title {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .brand-sub {
            font-size: 0.72rem;
            color: rgba(255, 255, 255, 0.85);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            color: #ffffff;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: bold;
            transition: all 0.2s;
        }
        .header-btn:active {
            background: rgba(255, 255, 255, 0.3);
        }

        /* コンテナ */
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 12px 14px;
        }

        /* 📢 申し送りメモ・バナー */
        .memo-banner {
            background: #fffbeb;
            border: 1.5px solid #fde68a;
            border-left: 5px solid #f59e0b;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 14px;
            box-shadow: var(--shadow-sm);
        }

        .memo-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: bold;
            color: #92400e;
            font-size: 0.92rem;
            margin-bottom: 6px;
        }

        .memo-content {
            font-size: 0.86rem;
            color: #78350f;
            white-space: pre-wrap;
            line-height: 1.6;
        }

        /* 🔘 ビュー切り替えタブ（Segmented Control） */
        .view-tabs {
            display: flex;
            background: #e2e8f0;
            padding: 4px;
            border-radius: 10px;
            margin-bottom: 14px;
        }

        .view-tab-btn {
            flex: 1;
            text-align: center;
            padding: 8px 6px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #475569;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .view-tab-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        /* 🔍 医師フィルターピル */
        .doctor-filter-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 12px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .doctor-filter-wrap::-webkit-scrollbar {
            display: none;
        }

        .doc-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #334155;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: var(--shadow-sm);
        }

        .doc-filter-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        .doc-filter-btn .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        /* 🗓️ ナビゲーションバー */
        .date-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }

        .nav-arrow-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: bold;
        }
        .nav-arrow-btn:active {
            background: #e2e8f0;
        }

        .date-title-box {
            text-align: center;
        }

        .date-main-text {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-main);
        }

        .today-jump-btn {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            background: var(--primary-light);
            color: var(--primary);
            padding: 2px 8px;
            border-radius: 12px;
            text-decoration: none;
            margin-top: 3px;
        }

        /* 📦 イベントカード */
        .section-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            margin-top: 14px;
        }

        .event-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-left: 5px solid var(--primary);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 10px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.1s;
        }
        .event-card:active {
            transform: scale(0.99);
        }

        .event-card.is-absence {
            border-left-color: var(--accent-red);
            background: #fffcfc;
        }

        .event-card.is-clinic {
            border-left-color: #0284c7;
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .doctor-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 4px;
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: bold;
        }

        .event-time {
            font-size: 0.85rem;
            font-weight: 800;
            color: #475569;
        }
        .is-absence .event-time {
            color: var(--accent-red);
        }

        .event-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .is-absence .event-title {
            color: #991b1b;
        }

        .event-desc {
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* 🗓️ 週間ビュー用カード */
        .week-day-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .week-day-box.is-today {
            border: 2px solid var(--primary);
        }

        .week-day-header {
            background: #f1f5f9;
            padding: 8px 12px;
            font-size: 0.9rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
        }

        .week-day-box.is-today .week-day-header {
            background: var(--primary-light);
            color: var(--primary);
        }

        .week-day-body {
            padding: 10px 12px;
        }

        .empty-hint {
            text-align: center;
            padding: 14px;
            font-size: 0.84rem;
            color: #94a3b8;
        }

        /* 📆 月間カレンダーグリッド */
        .month-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            background: var(--border-color);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
        }

        .month-dow-header {
            background: #f1f5f9;
            text-align: center;
            padding: 6px 2px;
            font-size: 0.75rem;
            font-weight: bold;
            color: #475569;
        }

        .month-cell {
            background: #ffffff;
            min-height: 80px;
            padding: 4px;
            font-size: 0.75rem;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: var(--text-main);
        }
        .month-cell.other-month {
            background: #f8fafc;
            opacity: 0.45;
        }
        .month-cell.is-today {
            background: #f0f9ff;
            border: 1.5px solid var(--primary);
        }

        .cell-day-num {
            font-weight: bold;
            margin-bottom: 3px;
        }
        .cell-event-pill {
            display: block;
            border-radius: 3px;
            padding: 2px 4px;
            font-size: 0.65rem;
            font-weight: bold;
            color: #fff;
            margin-bottom: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* 📱 ボトム固定ナビゲーション（スマホ用） */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 90;
            background: #ffffff;
            border-top: 1px solid var(--border-color);
            display: flex;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        }

        .bottom-nav-item {
            flex: 1;
            text-align: center;
            padding: 8px 4px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.72rem;
            font-weight: 700;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }

        .bottom-nav-item.active {
            color: var(--primary);
        }

        .bottom-nav-icon {
            font-size: 1.25rem;
            line-height: 1;
        }
    </style>
</head>
<body>

    <!-- 📱 ヘッダー -->
    <header>
        <div class="header-content">
            <a href="doctor_schedule.php" class="brand-box">
                <span class="brand-icon">🩺</span>
                <div>
                    <div class="brand-title">医師予定表</div>
                    <div class="brand-sub">医療法人小野会 外来・休診</div>
                </div>
            </a>
            <div class="header-actions">
                <a href="index.php" class="header-btn" title="かわら版トップへ">
                    📜 かわら版
                </a>
                <a href="safety_contacts.php" class="header-btn" style="background:#dc2626; border-color:#ef4444;" title="緊急安否確認へ">
                    🚨 安否確認
                </a>
            </div>
        </div>
    </header>

    <div class="container">

        <!-- 📢 申し送り・特記事項バナー（存在時のみ表示） -->
        <?php if ($has_memo && !empty($memo_data['text'])): ?>
            <div class="memo-banner">
                <div class="memo-header">
                    <span>📢 医師申し送り・特記事項</span>
                    <span style="font-size:0.75rem; color:#b45309; font-weight:normal;">
                        更新: <?= !empty($memo_data['updated_at']) ? date('n/j H:i', strtotime($memo_data['updated_at'])) : '' ?>
                    </span>
                </div>
                <div class="memo-content"><?= htmlspecialchars($memo_data['text']) ?></div>
            </div>
        <?php endif; ?>

        <!-- 🔘 ビュー切り替えタブ（本日 / 週間 / 月間） -->
        <div class="view-tabs">
            <a href="doctor_schedule.php?view=today&date=<?= $target_date ?>&doctor_id=<?= $selected_doctor_id ?>"
               class="view-tab-btn <?= $view === 'today' ? 'active' : '' ?>">
                📌 本日 (<?= date('n/j', $target_ts) ?>)
            </a>
            <a href="doctor_schedule.php?view=week&date=<?= $target_date ?>&doctor_id=<?= $selected_doctor_id ?>"
               class="view-tab-btn <?= $view === 'week' ? 'active' : '' ?>">
                🗓️ 週間カレンダー
            </a>
            <a href="doctor_schedule.php?view=month&date=<?= $target_date ?>&doctor_id=<?= $selected_doctor_id ?>"
               class="view-tab-btn <?= $view === 'month' ? 'active' : '' ?>">
                📆 月間カレンダー
            </a>
        </div>

        <!-- 🔍 医師フィルターピル -->
        <div class="doctor-filter-wrap">
            <a href="doctor_schedule.php?view=<?= $view ?>&date=<?= $target_date ?>&doctor_id=0"
               class="doc-filter-btn <?= $selected_doctor_id === 0 ? 'active' : '' ?>">
                👨‍⚕️ 全員表示
            </a>
            <?php foreach ($doctors as $doc): 
                $d_active = ($selected_doctor_id === (int)$doc['id']);
            ?>
                <a href="doctor_schedule.php?view=<?= $view ?>&date=<?= $target_date ?>&doctor_id=<?= $doc['id'] ?>"
                   class="doc-filter-btn <?= $d_active ? 'active' : '' ?>"
                   style="<?= $d_active ? 'background:' . htmlspecialchars($doc['department_color'] ?? '#005a9c') . '; border-color:' . htmlspecialchars($doc['department_color'] ?? '#005a9c') . ';' : '' ?>">
                    <span class="dot" style="background:<?= htmlspecialchars($doc['department_color'] ?? '#475569') ?>;"></span>
                    <?= htmlspecialchars($doc['name']) ?> (<?= htmlspecialchars($doc['title'] ?? '医師') ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <!-- 🗓️ 日付ナビゲーションバー -->
        <div class="date-nav-bar">
            <a href="doctor_schedule.php?view=<?= $view ?>&date=<?= $prev_date ?>&doctor_id=<?= $selected_doctor_id ?>"
               class="nav-arrow-btn" title="前の期間へ">◀</a>

            <div class="date-title-box">
                <div class="date-main-text">
                    <?php if ($view === 'today'): ?>
                        <?= date('Y年n月j日', $target_ts) ?> (<?= $week_names[(int)date('w', $target_ts)] ?>)
                    <?php elseif ($view === 'week'): ?>
                        <?= date('n/j', $week_start_ts) ?> (<?= $week_names[(int)date('w', $week_start_ts)] ?>) 〜 <?= date('n/j', $week_end_ts) ?> (<?= $week_names[(int)date('w', $week_end_ts)] ?>)
                    <?php else: ?>
                        <?= date('Y年 n月', $target_ts) ?>
                    <?php endif; ?>
                </div>
                <?php if ($target_date !== date('Y-m-d')): ?>
                    <a href="doctor_schedule.php?view=<?= $view ?>&date=<?= date('Y-m-d') ?>&doctor_id=<?= $selected_doctor_id ?>" class="today-jump-btn">
                        今日へ戻る
                    </a>
                <?php endif; ?>
            </div>

            <a href="doctor_schedule.php?view=<?= $view ?>&date=<?= $next_date ?>&doctor_id=<?= $selected_doctor_id ?>"
               class="nav-arrow-btn" title="次の期間へ">▶</a>
        </div>

        <!-- ========================================================= -->
        <!-- 1. 本日ビュー (Today View) -->
        <!-- ========================================================= -->
        <?php if ($view === 'today'): ?>
            <?php 
                $today_events = $events_by_date[$target_date] ?? [];
                $absence_events = [];
                $clinic_events = [];
                $other_events = [];

                foreach ($today_events as $ev) {
                    if (($ev['event_type'] ?? '') === 'absence') {
                        $absence_events[] = $ev;
                    } elseif (($ev['event_type'] ?? '') === 'clinic') {
                        $clinic_events[] = $ev;
                    } else {
                        $other_events[] = $ev;
                    }
                }
            ?>

            <!-- 休診・不在セクション -->
            <div class="section-title">
                <span>🔴 休診・不在・公休</span>
                <span style="font-size:0.8rem; font-weight:normal; color:#dc2626;">(<?= count($absence_events) ?>件)</span>
            </div>

            <?php if (!empty($absence_events)): ?>
                <?php foreach ($absence_events as $ev): 
                    $doc = $ev['doctor'] ?? [];
                    $time_text = !empty($ev['is_all_day']) ? '終日休診' : (($ev['start_time'] ?? '') . (!empty($ev['end_time']) ? '〜' . $ev['end_time'] : ''));
                ?>
                    <div class="event-card is-absence">
                        <div class="card-top">
                            <span class="doctor-badge" style="background:<?= htmlspecialchars($doc['department_color'] ?? '#dc2626') ?>;">
                                <?= htmlspecialchars($doc['department_name'] ?? '') ?> <?= htmlspecialchars($doc['name'] ?? '医師') ?>
                            </span>
                            <span class="event-time">⚠️ <?= htmlspecialchars($time_text) ?></span>
                        </div>
                        <div class="event-title"><?= htmlspecialchars($ev['title']) ?></div>
                        <?php if (!empty($ev['description'])): ?>
                            <div class="event-desc"><?= nl2br(htmlspecialchars($ev['description'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="week-day-box">
                    <div class="empty-hint" style="color:#16a34a; font-weight:bold;">
                        ✓ 本日の休診・不在予定はありません
                    </div>
                </div>
            <?php endif; ?>

            <!-- 外来診療・その他セクション -->
            <div class="section-title">
                <span>🩺 外来診療・会議・その他予定</span>
                <span style="font-size:0.8rem; font-weight:normal; color:#475569;">(<?= count($clinic_events) + count($other_events) ?>件)</span>
            </div>

            <?php if (!empty($clinic_events) || !empty($other_events)): ?>
                <?php foreach (array_merge($clinic_events, $other_events) as $ev): 
                    $doc = $ev['doctor'] ?? [];
                    $time_text = !empty($ev['is_all_day']) ? '終日' : (($ev['start_time'] ?? '') . (!empty($ev['end_time']) ? '〜' . $ev['end_time'] : ''));
                ?>
                    <div class="event-card is-clinic">
                        <div class="card-top">
                            <span class="doctor-badge" style="background:<?= htmlspecialchars($doc['department_color'] ?? '#0284c7') ?>;">
                                <?= htmlspecialchars($doc['department_name'] ?? '') ?> <?= htmlspecialchars($doc['name'] ?? '医師') ?>
                            </span>
                            <span class="event-time">⏰ <?= htmlspecialchars($time_text) ?></span>
                        </div>
                        <div class="event-title"><?= htmlspecialchars($ev['title']) ?></div>
                        <?php if (!empty($ev['description'])): ?>
                            <div class="event-desc"><?= nl2br(htmlspecialchars($ev['description'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="week-day-box">
                    <div class="empty-hint">本日の個別登録予定はありません</div>
                </div>
            <?php endif; ?>

        <!-- ========================================================= -->
        <!-- 2. 週間ビュー (Week View) -->
        <!-- ========================================================= -->
        <?php elseif ($view === 'week'): ?>
            <?php for ($d = 0; $d < 7; $d++): 
                $cur_ts = strtotime("+{$d} days", $week_start_ts);
                $cur_date_str = date('Y-m-d', $cur_ts);
                $is_cur_today = ($cur_date_str === date('Y-m-d'));
                $cur_dow = (int)date('w', $cur_ts);
                $day_events = $events_by_date[$cur_date_str] ?? [];
                
                $dow_color = '#0f172a';
                if ($cur_dow === 0) $dow_color = '#dc2626'; // 日
                if ($cur_dow === 6) $dow_color = '#0284c7'; // 土
            ?>
                <div class="week-day-box <?= $is_cur_today ? 'is-today' : '' ?>">
                    <div class="week-day-header">
                        <span style="color:<?= $dow_color ?>;">
                            <?= date('n月j日', $cur_ts) ?> (<?= $week_names[$cur_dow] ?>)
                            <?= $is_cur_today ? '<span style="font-size:0.75rem; background:var(--primary); color:#fff; padding:1px 6px; border-radius:10px; margin-left:4px;">本日</span>' : '' ?>
                        </span>
                        <a href="doctor_schedule.php?view=today&date=<?= $cur_date_str ?>&doctor_id=<?= $selected_doctor_id ?>" style="font-size:0.75rem; color:var(--primary); text-decoration:none;">
                            詳細を見る →
                        </a>
                    </div>
                    <div class="week-day-body">
                        <?php if (!empty($day_events)): ?>
                            <?php foreach ($day_events as $ev): 
                                $doc = $ev['doctor'] ?? [];
                                $is_abs = (($ev['event_type'] ?? '') === 'absence');
                                $time_text = !empty($ev['is_all_day']) ? '終日' : ($ev['start_time'] ?? '');
                            ?>
                                <div style="display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px dashed #f1f5f9; font-size:0.85rem;">
                                    <span style="font-size:0.8rem;"><?= $is_abs ? '🔴' : '🩺' ?></span>
                                    <span class="doctor-badge" style="background:<?= htmlspecialchars($doc['department_color'] ?? '#475569') ?>; font-size:0.7rem; padding:2px 6px;">
                                        <?= htmlspecialchars($doc['short_name'] ?? $doc['name'] ?? '医師') ?>
                                    </span>
                                    <span style="font-weight:bold; color:<?= $is_abs ? '#b91c1c' : '#0f172a' ?>; flex:1;">
                                        <?= htmlspecialchars($ev['title']) ?>
                                    </span>
                                    <span style="font-size:0.78rem; color:#64748b;"><?= htmlspecialchars($time_text) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-hint" style="padding:6px; font-size:0.8rem;">予定なし</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>

        <!-- ========================================================= -->
        <!-- 3. 月間ビュー (Month View) -->
        <!-- ========================================================= -->
        <?php else: ?>
            <div class="month-grid">
                <?php 
                $dow_headers = ['月', '火', '水', '木', '金', '土', '日'];
                foreach ($dow_headers as $dh) {
                    $color = ($dh === '日') ? '#dc2626' : (($dh === '土') ? '#0284c7' : '#475569');
                    echo "<div class='month-dow-header' style='color:{$color}'>{$dh}</div>";
                }

                $cal_days = (int)round(($cal_end_ts - $cal_start_ts) / 86400) + 1;
                for ($i = 0; $i < $cal_days; $i++) {
                    $c_ts = strtotime("+{$i} days", $cal_start_ts);
                    $c_date = date('Y-m-d', $c_ts);
                    $c_month = (int)date('m', $c_ts);
                    $is_other_month = ($c_month !== $month);
                    $is_c_today = ($c_date === date('Y-m-d'));
                    $c_events = $events_by_date[$c_date] ?? [];
                ?>
                    <a href="doctor_schedule.php?view=today&date=<?= $c_date ?>&doctor_id=<?= $selected_doctor_id ?>"
                       class="month-cell <?= $is_other_month ? 'other-month' : '' ?> <?= $is_c_today ? 'is-today' : '' ?>">
                        <div class="cell-day-num" style="<?= date('w', $c_ts) == 0 ? 'color:#dc2626;' : (date('w', $c_ts) == 6 ? 'color:#0284c7;' : '') ?>">
                            <?= date('j', $c_ts) ?>
                        </div>
                        <?php foreach (array_slice($c_events, 0, 3) as $ev): 
                            $is_abs = (($ev['event_type'] ?? '') === 'absence');
                            $doc = $ev['doctor'] ?? [];
                            $bg = $is_abs ? '#dc2626' : ($doc['department_color'] ?? '#0284c7');
                        ?>
                            <span class="cell-event-pill" style="background:<?= htmlspecialchars($bg) ?>;" title="<?= htmlspecialchars($ev['title']) ?>">
                                <?= htmlspecialchars($doc['short_name'] ?? '') ?>: <?= htmlspecialchars($ev['title']) ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($c_events) > 3): ?>
                            <span style="font-size:0.6rem; color:#64748b;">+他<?= count($c_events) - 3 ?>件</span>
                        <?php endif; ?>
                    </a>
                <?php } ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- 📱 スマホ用下部固定ナビゲーションバー -->
    <div class="bottom-nav">
        <a href="doctor_schedule.php?view=today" class="bottom-nav-item <?= $view === 'today' ? 'active' : '' ?>">
            <span class="bottom-nav-icon">📌</span>
            <span>本日</span>
        </a>
        <a href="doctor_schedule.php?view=week" class="bottom-nav-item <?= $view === 'week' ? 'active' : '' ?>">
            <span class="bottom-nav-icon">🗓️</span>
            <span>週間</span>
        </a>
        <a href="doctor_schedule.php?view=month" class="bottom-nav-item <?= $view === 'month' ? 'active' : '' ?>">
            <span class="bottom-nav-icon">📆</span>
            <span>月間</span>
        </a>
        <a href="index.php" class="bottom-nav-item">
            <span class="bottom-nav-icon">📜</span>
            <span>かわら版</span>
        </a>
        <a href="safety_contacts.php" class="bottom-nav-item" style="color:#dc2626;">
            <span class="bottom-nav-icon">🚨</span>
            <span>安否確認</span>
        </a>
    </div>

</body>
</html>
