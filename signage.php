<?php
/**
 * 院内かわら版 - ナースステーション常設サイネージ
 * File: signage.php
 * 
 * ナースステーション（病棟詰所）の常設大画面モニター向け全画面スライドループシステム
 * - 医師勤務体制（yotei連携）
 * - 本日の院内予定（Googleカレンダー連携）
 * - 重点目標・日付メモ（dashboard_notes連携）
 * - 最新のかわら版ポスター・記事（posts/images連携）
 * - リアルタイム大時計・公的暦（六曜・二十四節気）
 * - 災害・安否確認の緊急全画面ジャック
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/traditional_calendar_helper.php';
require_once __DIR__ . '/includes/google_calendar_helper.php';
require_once __DIR__ . '/includes/doctor_schedule_helper.php';

// -------------------------------------------------------------
// 1. 認証チェック
// 院内LAN（192.168.*）からのアクセスは常時フリーパス（自動全画面起動）
// 院外からのアクセスの場合は端末認証またはログインを要求
// -------------------------------------------------------------
$is_internal = isHospitalInternalAccess();
if (!$is_internal) {
    $current_user = checkAuthOrAutoLogin($pdo, 'signage.php');
}

// -------------------------------------------------------------
// 2. サイネージ用データ集約関数
// -------------------------------------------------------------
function getSignageData(PDO $pdo): array {
    $today_str = date('Y-m-d');
    $cur_month_str = date('Y-m');
    $day_of_week_ja = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w')];

    // (1) 暦情報（六曜・二十四節気）
    $rokuyo = get_rokuyo($today_str);
    $solar_term = get_solar_term($today_str);

    // (2) 医師勤務体制（yotei連携）
    $doctor_summary = fetch_doctor_today_summary($today_str);
    $doctor_memo = fetch_doctor_memo();

    // (3) 本日のGoogleカレンダー予定（キャッシュより取得）
    $gcal_events = get_cached_google_events_for_range($pdo, $today_str, $today_str);

    // (4) 事務長重点目標 ＆ 今日の日付メモ
    $stmt_note = $pdo->prepare("SELECT target_type, content FROM dashboard_notes WHERE (target_type = 'month' AND target_key = :month) OR (target_type = 'date' AND target_key = :today)");
    $stmt_note->execute([':month' => $cur_month_str, ':today' => $today_str]);
    $notes_raw = $stmt_note->fetchAll(PDO::FETCH_ASSOC);
    $month_goal = '';
    $today_note = '';
    foreach ($notes_raw as $n) {
        if ($n['target_type'] === 'month') $month_goal = trim($n['content']);
        if ($n['target_type'] === 'date')  $today_note = trim($n['content']);
    }

    // (5) 緊急災害・安否確認のアクティブ発令チェック
    // ※ normal（日常運用・出勤チェック）は通常スライドを妨げないため除外。
    // 　 災害緊急時（disaster）または訓練発令（drill）の場合のみ全画面ジャックとする。
    $emergency = null;
    $stmt_emg = $pdo->query("
        SELECT * FROM safety_events 
        WHERE is_active = TRUE 
          AND (safety_mode IN ('disaster', 'drill') OR (safety_mode IS NULL AND is_disaster_mode = TRUE))
        ORDER BY event_id DESC 
        LIMIT 1
    ");
    $emg_raw = $stmt_emg->fetch(PDO::FETCH_ASSOC);
    if ($emg_raw) {
        $is_drill = ($emg_raw['safety_mode'] === 'drill');
        $emergency = [
            'id'          => $emg_raw['event_id'],
            'title'       => $emg_raw['title'],
            'description' => $emg_raw['description'],
            'mode'        => $emg_raw['safety_mode'] ?? ($emg_raw['is_disaster_mode'] ? 'disaster' : 'normal'),
            'is_drill'    => $is_drill,
            'severity'    => $emg_raw['severity'] ?? 'warning',
            'created_at'  => date('m/d H:i', strtotime($emg_raw['created_at']))
        ];
    }

    // (6) かわら版最新記事（直近8件、新着順・ピン留め優先）
    $stmt_posts = $pdo->query("
        SELECT 
            p.post_id, p.title, p.content, p.created_at, p.is_pinned,
            c.category_name, c.color_code,
            s.staff_name, s.role
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        LEFT JOIN staff s ON p.author_id = s.staff_id
        WHERE (p.display_until IS NULL OR p.display_until >= NOW())
          AND (s.is_deleted IS NOT TRUE)
        ORDER BY p.is_pinned DESC, p.created_at DESC
        LIMIT 8
    ");
    $posts_raw = $stmt_posts->fetchAll(PDO::FETCH_ASSOC);

    // 画像取得
    $post_ids = array_column($posts_raw, 'post_id');
    $images_map = [];
    if (!empty($post_ids)) {
        $in_clause = implode(',', array_map('intval', $post_ids));
        $stmt_imgs = $pdo->query("SELECT post_id, file_path FROM post_images WHERE post_id IN ({$in_clause}) ORDER BY image_id ASC");
        while ($row = $stmt_imgs->fetch(PDO::FETCH_ASSOC)) {
            $images_map[(int)$row['post_id']][] = $row['file_path'];
        }
    }

    $slides_posts = [];
    foreach ($posts_raw as $p) {
        $pid = (int)$p['post_id'];
        $clean_text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $p['content'])));
        
        $slides_posts[] = [
            'post_id'       => $pid,
            'title'         => $p['title'],
            'content_text'  => $clean_text,
            'category_name' => $p['category_name'] ?? 'お知らせ',
            'color_code'    => $p['color_code'] ?? '#0284c7',
            'author_name'   => $p['staff_name'] ?? '管理者',
            'is_pinned'     => (bool)$p['is_pinned'],
            'created_at'    => date('m/d H:i', strtotime($p['created_at'])),
            'images'        => $images_map[$pid] ?? []
        ];
    }

    return [
        'success'       => true,
        'date_str'      => date('Y年n月j日') . " ({$day_of_week_ja})",
        'rokuyo'        => $rokuyo['name'] ?? '',
        'solar_term'    => $solar_term ?: '',
        'doctor'        => [
            'summary' => $doctor_summary,
            'memo'    => $doctor_memo['content'] ?? ''
        ],
        'gcal_events'   => array_map(function($ev) {
            return [
                'title'       => $ev['title'],
                'start_time'  => !empty($ev['is_all_day']) ? '終日' : date('H:i', strtotime($ev['start_datetime'])),
                'is_all_day'  => (bool)$ev['is_all_day'],
                'color'       => $ev['color_code'] ?? '#0284c7',
                'description' => $ev['description'] ?? ''
            ];
        }, $gcal_events),
        'month_goal'    => $month_goal,
        'today_note'    => $today_note,
        'emergency'     => $emergency,
        'posts'         => $slides_posts,
        'fetched_at'    => date('H:i:s')
    ];
}

// -------------------------------------------------------------
// 3. Ajaxデータエンドポイント処理
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getSignageData($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

// 初回レンダリング用初期データ取得
$initial_data = getSignageData($pdo);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ナースステーション常設サイネージ | 医療法人小野会</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700;900&family=Outfit:wght@500;700;800;900&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #070d1e;
            --bg-gradient: linear-gradient(135deg, #070d1e 0%, #0f172a 60%, #1e1b4b 100%);
            --card-bg: rgba(30, 41, 59, 0.72);
            --card-border: rgba(255, 255, 255, 0.12);
            --card-hover: rgba(51, 65, 85, 0.85);
            --text-main: #f8fafc;
            --text-sub: #cbd5e1;
            --text-muted: #94a3b8;
            --accent-cyan: #38bdf8;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            --accent-indigo: #6366f1;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        html, body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background: var(--bg-deep);
            color: var(--text-main);
            font-family: 'Noto Sans JP', sans-serif;
            user-select: none;
        }

        /* 背景・全体ラッパー */
        .signage-container {
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--bg-gradient);
            position: relative;
        }

        /* 画面最上部 プログレスバー */
        .progress-bar-container {
            width: 100%;
            height: 5px;
            background: rgba(255, 255, 255, 0.08);
            position: relative;
            z-index: 100;
        }
        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent-cyan), var(--accent-emerald));
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.7);
            transition: width 0.1s linear;
        }

        /* ヘッダー */
        .signage-header {
            height: 80px;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            z-index: 50;
            flex-shrink: 0;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .brand-badge {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.4);
        }
        .brand-title {
            font-size: 1.45rem;
            font-weight: 900;
            letter-spacing: -0.01em;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-subtitle {
            font-size: 0.88rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-left: 6px;
        }

        /* ヘッダー右側：時計・日付・暦 */
        .header-clock-area {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .calendar-tag-group {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
        }
        .date-display {
            font-size: 1.15rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: 0.02em;
        }
        .badges-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge-rokuyo {
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid rgba(245, 158, 11, 0.5);
            color: #fbbf24;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 800;
        }
        .badge-solar {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid rgba(16, 185, 129, 0.5);
            color: #34d399;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 800;
        }
        .clock-display {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: 0.05em;
            line-height: 1;
            text-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
        }
        .clock-sec {
            font-size: 1.5rem;
            color: var(--accent-cyan);
            margin-left: 2px;
        }

        /* スライドコンテンツエリア */
        .signage-main {
            flex: 1;
            position: relative;
            overflow: hidden;
            padding: 1.8rem 2.2rem;
        }

        .slide-pane {
            position: absolute;
            top: 1.8rem;
            left: 2.2rem;
            right: 2.2rem;
            bottom: 1.8rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.8s ease-in-out, transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            transform: scale(0.98);
            display: flex;
            flex-direction: column;
        }
        .slide-pane.active {
            opacity: 1;
            pointer-events: auto;
            transform: scale(1);
        }

        /* スライド1：本日総合ダッシュボードのグリッド */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr 0.95fr;
            gap: 1.5rem;
            height: 100%;
        }
        .dash-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            backdrop-filter: blur(16px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .dash-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 0.9rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid rgba(255, 255, 255, 0.08);
            flex-shrink: 0;
        }
        .dash-card-title {
            font-size: 1.22rem;
            font-weight: 900;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dash-card-badge {
            font-size: 0.78rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 12px;
            text-transform: uppercase;
        }
        .dash-card-body {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* 医師勤務スタイル */
        .doctor-row {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .doctor-name-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .doctor-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #1e293b;
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 900;
            border: 2px solid rgba(56, 189, 248, 0.4);
        }
        .doctor-name {
            font-size: 1.15rem;
            font-weight: 900;
            color: #ffffff;
        }
        .doctor-role {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .doctor-status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 900;
        }
        .status-duty { background: rgba(16, 185, 129, 0.25); color: #34d399; border: 1px solid #10b981; }
        .status-absent { background: rgba(244, 63, 94, 0.25); color: #fb7185; border: 1px solid #f43f5e; }
        .status-am { background: rgba(56, 189, 248, 0.25); color: #38bdf8; border: 1px solid #0284c7; }

        .doctor-memo-box {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-left: 5px solid var(--accent-amber);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.88rem;
            color: #fef3c7;
            line-height: 1.5;
            margin-top: auto;
        }

        /* 予定リストスタイル */
        .event-item {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-left: 4px solid var(--accent-cyan);
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .event-time {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--accent-cyan);
            min-width: 65px;
        }
        .event-title {
            font-size: 1rem;
            font-weight: 800;
            color: #ffffff;
            flex: 1;
            margin-left: 10px;
        }

        /* 重点目標・メモカード */
        .goal-box {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .goal-label {
            font-size: 0.85rem;
            font-weight: 900;
            color: #34d399;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .goal-content {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.5;
        }

        .today-note-box {
            background: rgba(99, 102, 241, 0.12);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 12px;
            padding: 14px;
            flex: 1;
        }
        .today-note-label {
            font-size: 0.85rem;
            font-weight: 900;
            color: #a5b4fc;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .today-note-content {
            font-size: 1.02rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.5;
            white-space: pre-wrap;
        }

        /* スライド2〜N：かわら版記事ポスター（フル画面） */
        .post-poster-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            height: 100%;
            display: flex;
            overflow: hidden;
            backdrop-filter: blur(16px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.35);
        }
        .poster-image-side {
            flex: 1.15;
            background: #000000;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .poster-image-side img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: transform 0.5s ease;
        }
        .poster-info-side {
            flex: 1;
            padding: 2.5rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 16px;
            overflow-y: auto;
        }
        .post-meta-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .post-cat-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.95rem;
            font-weight: 900;
            color: #ffffff;
        }
        .post-pinned-badge {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .post-date-author {
            font-size: 0.95rem;
            color: var(--text-muted);
            font-weight: 600;
        }
        .post-poster-title {
            font-size: 2.3rem;
            font-weight: 900;
            color: #ffffff;
            line-height: 1.35;
            letter-spacing: -0.01em;
        }
        .post-poster-body {
            font-size: 1.25rem;
            color: #e2e8f0;
            line-height: 1.8;
            max-height: 380px;
            overflow-y: auto;
            white-space: pre-wrap;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 1rem;
        }

        /* 画像なし記事のレイアウト */
        .post-textonly-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3.5rem 5rem;
            backdrop-filter: blur(16px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.35);
        }

        /* 🚨 緊急安否確認 全画面ジャックモード */
        .emergency-fullscreen-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: radial-gradient(circle, #7f1d1d 0%, #450a0a 70%, #000000 100%);
            z-index: 1000;
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 3rem;
            animation: pulseWarning 2s infinite alternate;
        }
        .emergency-fullscreen-overlay.active {
            display: flex;
        }
        .emergency-fullscreen-overlay.drill-mode {
            background: radial-gradient(circle, #78350f 0%, #451a03 70%, #000000 100%);
        }
        .emergency-fullscreen-overlay.drill-mode .emg-header-badge {
            color: #b45309;
        }
        @keyframes pulseWarning {
            0% { box-shadow: inset 0 0 60px rgba(220, 38, 38, 0.6); }
            100% { box-shadow: inset 0 0 120px rgba(220, 38, 38, 1); }
        }
        .emg-header-badge {
            background: #ffffff;
            color: #b91c1c;
            padding: 8px 24px;
            border-radius: 30px;
            font-size: 1.4rem;
            font-weight: 900;
            letter-spacing: 0.1em;
            margin-bottom: 1.5rem;
            box-shadow: 0 0 25px rgba(255, 255, 255, 0.8);
        }
        .emg-title {
            font-size: 3.5rem;
            font-weight: 900;
            color: #ffffff;
            margin-bottom: 1.5rem;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.8);
        }
        .emg-desc {
            font-size: 1.8rem;
            color: #fecaca;
            max-width: 900px;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .emg-action-instruction {
            background: rgba(0, 0, 0, 0.5);
            border: 2px solid #ef4444;
            padding: 1.2rem 2.5rem;
            border-radius: 16px;
            font-size: 1.35rem;
            font-weight: 800;
            color: #ffffff;
        }

        /* 下部ナビゲーション・インジケーター（TVリモコン・タッチ・マウス操作用） */
        .signage-footer {
            height: 50px;
            padding: 0 2.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(15, 23, 42, 0.4);
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            z-index: 50;
            flex-shrink: 0;
        }
        .slide-counter {
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .slide-dots {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .slide-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transition: all 0.3s;
        }
        .slide-dot.active {
            width: 22px;
            border-radius: 4px;
            background: var(--accent-cyan);
            box-shadow: 0 0 8px rgba(56, 189, 248, 0.6);
        }

        .controls-group {
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0.4;
            transition: opacity 0.2s;
        }
        .signage-container:hover .controls-group {
            opacity: 1;
        }
        .btn-ctrl {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-ctrl:hover {
            background: #ffffff;
            color: #0f172a;
        }
    </style>
</head>
<body>

<div class="signage-container" id="signageContainer">

    <!-- 1. 最上部プログレスバー -->
    <div class="progress-bar-container">
        <div class="progress-bar-fill" id="progressBar"></div>
    </div>

    <!-- 2. ヘッダーバー -->
    <header class="signage-header">
        <div class="header-brand">
            <span class="brand-badge">ナースステーション常設</span>
            <div class="brand-title">
                <span>🏥 おの肛門科胃腸科内視鏡クリニック</span>
                <span class="brand-subtitle">院内かわら版 ＆ 診療情報サイネージ</span>
            </div>
        </div>

        <div class="header-clock-area">
            <div class="calendar-tag-group">
                <div class="date-display" id="dateDisplay"><?= htmlspecialchars($initial_data['date_str']) ?></div>
                <div class="badges-row">
                    <?php if (!empty($initial_data['rokuyo'])): ?>
                        <span class="badge-rokuyo"><?= htmlspecialchars($initial_data['rokuyo']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($initial_data['solar_term'])): ?>
                        <span class="badge-solar"><?= htmlspecialchars($initial_data['solar_term']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="clock-display">
                <span id="clockHM">--:--</span><span class="clock-sec" id="clockS">:--</span>
            </div>
        </div>
    </header>

    <!-- 3. メインスライドエリア（動的構築） -->
    <main class="signage-main" id="slidesContainer">
        <!-- JSによりスライド群がここに生成されます -->
    </main>

    <!-- 4. フッター・インジケーター -->
    <footer class="signage-footer">
        <div class="slide-counter">
            <div class="slide-dots" id="slideDots"></div>
            <span id="slideCounterText">1 / 1</span>
            <span id="pauseBadge" style="display:none; background:#ea580c; color:#fff; padding:2px 8px; border-radius:10px; font-size:0.75rem;">⏸ 一時停止中</span>
        </div>

        <div class="controls-group">
            <button type="button" class="btn-ctrl" onclick="prevSlide()" title="前のスライド (←)">◀ 前へ</button>
            <button type="button" class="btn-ctrl" id="btnPlayPause" onclick="togglePause()" title="再生/一時停止 (Space)">⏸ 停止</button>
            <button type="button" class="btn-ctrl" onclick="nextSlide()" title="次のスライド (→)">次へ ▶</button>
            <button type="button" class="btn-ctrl" onclick="toggleFullScreen()" title="全画面 (F)">⛶ 全画面</button>
        </div>
    </footer>

</div>

<!-- 5. 🚨 緊急安否確認 全画面ジャックオーバーレイ -->
<div class="emergency-fullscreen-overlay" id="emergencyOverlay">
    <div class="emg-header-badge" id="emgBadge">🚨 緊急安否確認 発令中</div>
    <div class="emg-title" id="emgTitle">大規模地震警戒アラート</div>
    <div class="emg-desc" id="emgDesc">
        全職員は安全を確保の上、スマートフォンより安否状況（無事／出勤可否）を至急報告してください。
    </div>
    <div class="emg-action-instruction">
        📱 スマホのLINEまたはかわら版より直ちに安否を登録してください（院内安否確認システム直結）
    </div>
</div>

<script>
// =========================================================================
// ナースステーション常設サイネージ コントローラー
// =========================================================================

// 初期データ
let signageData = <?= json_encode($initial_data, JSON_UNESCAPED_UNICODE) ?>;
let currentSlideIndex = 0;
let slidesList = [];
let slideTimer = null;
let progressTimer = null;
let isPaused = false;
let progressStartTime = 0;
let progressDuration = 12000; // デフォルト12秒

// スライド表示時間の定義
const DURATION_DASHBOARD = 16000; // 本日ダッシュボード: 16秒
const DURATION_POST = 13000;      // かわら版記事: 13秒

// 時計のリアルタイム更新
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    document.getElementById('clockHM').textContent = `${h}:${m}`;
    document.getElementById('clockS').textContent = `:${s}`;
}
setInterval(updateClock, 1000);
updateClock();

// スライドデータのDOM構築
function buildSlides() {
    const container = document.getElementById('slidesContainer');
    const dotsContainer = document.getElementById('slideDots');
    container.innerHTML = '';
    dotsContainer.innerHTML = '';
    slidesList = [];

    // -------------------------------------------------------------
    // スライド 1: 本日総合ダッシュボード
    // -------------------------------------------------------------
    const slideDash = document.createElement('div');
    slideDash.className = 'slide-pane';
    slideDash.id = 'slide-0';
    slideDash.dataset.duration = DURATION_DASHBOARD;

    // 医師勤務リストHTML生成
    let docListHtml = '';
    const docSummary = signageData.doctor?.summary;
    if (docSummary && docSummary.clinic_doctors && docSummary.clinic_doctors.length > 0) {
        docSummary.clinic_doctors.forEach(d => {
            docListHtml += `
                <div class="doctor-row">
                    <div class="doctor-name-box">
                        <div class="doctor-avatar">🩺</div>
                        <div>
                            <div class="doctor-name">${escapeHtml(d.doctor_name || d.name || '医師')}</div>
                            <div class="doctor-role">${escapeHtml(d.duty_text || d.role || '診療担当')}</div>
                        </div>
                    </div>
                    <span class="doctor-status-badge status-duty">出勤・診療</span>
                </div>
            `;
        });
    } else {
        docListHtml = `
            <div class="doctor-row">
                <div class="doctor-name-box">
                    <div class="doctor-avatar">👨‍⚕️</div>
                    <div class="doctor-name">本日の外来診療</div>
                </div>
                <span class="doctor-status-badge status-duty">通常体制</span>
            </div>
        `;
    }

    if (docSummary && docSummary.absent_doctors && docSummary.absent_doctors.length > 0) {
        docSummary.absent_doctors.forEach(d => {
            docListHtml += `
                <div class="doctor-row" style="border-left:4px solid var(--accent-rose);">
                    <div class="doctor-name-box">
                        <div class="doctor-avatar" style="color:var(--accent-rose); border-color:var(--accent-rose);">⚠️</div>
                        <div>
                            <div class="doctor-name">${escapeHtml(d.doctor_name || d.name)}</div>
                            <div class="doctor-role">${escapeHtml(d.reason || '休診')}</div>
                        </div>
                    </div>
                    <span class="doctor-status-badge status-absent">本日休診</span>
                </div>
            `;
        });
    }

    const docMemoHtml = signageData.doctor?.memo 
        ? `<div class="doctor-memo-box"><b>📝 医師申し送り:</b><br>${escapeHtml(signageData.doctor.memo)}</div>`
        : '';

    // 本日の予定リストHTML生成
    let eventsHtml = '';
    if (signageData.gcal_events && signageData.gcal_events.length > 0) {
        signageData.gcal_events.forEach(ev => {
            eventsHtml += `
                <div class="event-item" style="border-left-color:${ev.color};">
                    <span class="event-time">${escapeHtml(ev.start_time)}</span>
                    <span class="event-title">${escapeHtml(ev.title)}</span>
                </div>
            `;
        });
    } else {
        eventsHtml = `<div style="color:var(--text-muted); font-size:0.95rem; text-align:center; padding:2rem;">本日の特別予定はありません（通常業務）</div>`;
    }

    // 重点目標・日付メモHTML
    const goalHtml = signageData.month_goal 
        ? `<div class="goal-box"><div class="goal-label">📌 今月の重点目標</div><div class="goal-content">${escapeHtml(signageData.month_goal)}</div></div>`
        : `<div class="goal-box"><div class="goal-label">📌 今月の重点目標</div><div class="goal-content" style="color:var(--text-muted); font-size:0.9rem;">設定なし</div></div>`;

    const noteHtml = signageData.today_note
        ? `<div class="today-note-box"><div class="today-note-label">📝 今日の業務メモ</div><div class="today-note-content">${escapeHtml(signageData.today_note)}</div></div>`
        : `<div class="today-note-box"><div class="today-note-label">📝 今日の業務メモ</div><div class="today-note-content" style="color:var(--text-muted); font-size:0.9rem;">登録メモなし</div></div>`;

    slideDash.innerHTML = `
        <div class="dashboard-grid">
            <!-- 列1: 医師勤務体制 -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div class="dash-card-title">🩺 医師勤務・外来体制</div>
                    <span class="dash-card-badge" style="background:#0284c7; color:#fff;">yotei連携</span>
                </div>
                <div class="dash-card-body">
                    ${docListHtml}
                    ${docMemoHtml}
                </div>
            </div>

            <!-- 列2: 本日の院内予定 -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div class="dash-card-title">📅 本日の院内予定・行事</div>
                    <span class="dash-card-badge" style="background:#059669; color:#fff;">カレンダー</span>
                </div>
                <div class="dash-card-body">
                    ${eventsHtml}
                </div>
            </div>

            <!-- 列3: 重点目標・日付メモ -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div class="dash-card-title">📌 連絡・重点メモ</div>
                    <span class="dash-card-badge" style="background:#7c3aed; color:#fff;">事務長統制</span>
                </div>
                <div class="dash-card-body">
                    ${goalHtml}
                    ${noteHtml}
                </div>
            </div>
        </div>
    `;

    container.appendChild(slideDash);
    slidesList.push(slideDash);

    // -------------------------------------------------------------
    // スライド 2〜N: 最新かわら版記事
    // -------------------------------------------------------------
    if (signageData.posts && signageData.posts.length > 0) {
        signageData.posts.forEach((p, idx) => {
            const slidePost = document.createElement('div');
            slidePost.className = 'slide-pane';
            slidePost.id = `slide-${idx + 1}`;
            slidePost.dataset.duration = DURATION_POST;

            const hasImage = (p.images && p.images.length > 0);
            const pinnedBadge = p.is_pinned ? `<span class="post-pinned-badge">📌 重要周知</span>` : '';

            if (hasImage) {
                // 画像ありポスタースタイル
                const imgSrc = p.images[0];
                slidePost.innerHTML = `
                    <div class="post-poster-card">
                        <div class="poster-image-side">
                            <img src="${escapeHtml(imgSrc)}" alt="添付ポスター" onerror="this.style.display='none'">
                        </div>
                        <div class="poster-info-side">
                            <div class="post-meta-row">
                                <span class="post-cat-badge" style="background:${p.color_code};">${escapeHtml(p.category_name)}</span>
                                ${pinnedBadge}
                                <span class="post-date-author">🕒 ${escapeHtml(p.created_at)} • 👤 ${escapeHtml(p.author_name)}</span>
                            </div>
                            <div class="post-poster-title">${escapeHtml(p.title)}</div>
                            <div class="post-poster-body">${escapeHtml(p.content_text)}</div>
                        </div>
                    </div>
                `;
            } else {
                // テキストのみ特大スタイル
                slidePost.innerHTML = `
                    <div class="post-textonly-card">
                        <div class="post-meta-row" style="margin-bottom:1.5rem;">
                            <span class="post-cat-badge" style="background:${p.color_code}; font-size:1.15rem; padding:8px 22px;">${escapeHtml(p.category_name)}</span>
                            ${pinnedBadge}
                            <span class="post-date-author" style="font-size:1.1rem;">🕒 ${escapeHtml(p.created_at)} • 👤 ${escapeHtml(p.author_name)}</span>
                        </div>
                        <div class="post-poster-title" style="font-size:3rem; margin-bottom:1.8rem;">${escapeHtml(p.title)}</div>
                        <div class="post-poster-body" style="font-size:1.55rem; max-height:480px; border-top:2px solid rgba(255,255,255,0.15); padding-top:1.8rem;">${escapeHtml(p.content_text)}</div>
                    </div>
                `;
            }

            container.appendChild(slidePost);
            slidesList.push(slidePost);
        });
    }

    // ドットインジケーター生成
    slidesList.forEach((_, idx) => {
        const dot = document.createElement('div');
        dot.className = `slide-dot ${idx === 0 ? 'active' : ''}`;
        dot.onclick = () => goToSlide(idx);
        dotsContainer.appendChild(dot);
    });

    updateSlideUI();
}

// スライド切り替え実行
function showSlide(index) {
    if (slidesList.length === 0) return;
    if (index < 0) index = slidesList.length - 1;
    if (index >= slidesList.length) index = 0;

    currentSlideIndex = index;

    slidesList.forEach((slide, idx) => {
        slide.classList.toggle('active', idx === currentSlideIndex);
    });

    // ドットの同期
    const dots = document.querySelectorAll('.slide-dot');
    dots.forEach((dot, idx) => {
        dot.classList.toggle('active', idx === currentSlideIndex);
    });

    // カウンターテキスト
    document.getElementById('slideCounterText').textContent = `${currentSlideIndex + 1} / ${slidesList.length}`;

    // タイマーリセットとプログレス開始
    resetSlideTimer();
}

function updateSlideUI() {
    showSlide(currentSlideIndex);
}

function nextSlide() {
    showSlide(currentSlideIndex + 1);
}

function prevSlide() {
    showSlide(currentSlideIndex - 1);
}

function goToSlide(index) {
    showSlide(index);
}

// プログレスバーアニメーション＆次スライドタイマー
function resetSlideTimer() {
    clearTimeout(slideTimer);
    clearInterval(progressTimer);

    if (isPaused) return;

    const currentSlide = slidesList[currentSlideIndex];
    progressDuration = currentSlide ? parseInt(currentSlide.dataset.duration || 12000, 10) : 12000;
    progressStartTime = Date.now();

    const progressBar = document.getElementById('progressBar');
    progressBar.style.width = '0%';

    progressTimer = setInterval(() => {
        if (isPaused) return;
        const elapsed = Date.now() - progressStartTime;
        const percent = Math.min(100, (elapsed / progressDuration) * 100);
        progressBar.style.width = `${percent}%`;

        if (percent >= 100) {
            clearInterval(progressTimer);
        }
    }, 100);

    slideTimer = setTimeout(() => {
        if (!isPaused) {
            nextSlide();
            // 1周したら最新データをバックグラウンド同期
            if (currentSlideIndex === 0) {
                fetchLatestData();
            }
        }
    }, progressDuration);
}

// 一時停止／再開
function togglePause() {
    isPaused = !isPaused;
    const btn = document.getElementById('btnPlayPause');
    const badge = document.getElementById('pauseBadge');
    if (isPaused) {
        btn.textContent = '▶ 再開';
        badge.style.display = 'inline-block';
        clearTimeout(slideTimer);
        clearInterval(progressTimer);
    } else {
        btn.textContent = '⏸ 停止';
        badge.style.display = 'none';
        resetSlideTimer();
    }
}

// 全画面表示切り替え
function toggleFullScreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
}

// 最新データの非同期フェッチ（自動更新）
async function fetchLatestData() {
    try {
        const res = await fetch('signage.php?action=data');
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.success) {
            signageData = data;
            
            // 緊急発令チェック
            checkEmergencyStatus();

            // スライドの再構築（現在のスライド表示を邪魔しないよう次スライド以降で最新反映）
            buildSlides();
        }
    } catch (e) {
        console.warn('Background sync failed, retaining existing slide cache:', e);
    }
}

// 緊急安否確認の発令チェック
function checkEmergencyStatus() {
    const overlay = document.getElementById('emergencyOverlay');
    const badge = document.getElementById('emgBadge');
    if (signageData.emergency) {
        if (signageData.emergency.is_drill) {
            overlay.classList.add('drill-mode');
            if (badge) badge.textContent = '🛡️ 安否確認訓練 実施中';
        } else {
            overlay.classList.remove('drill-mode');
            if (badge) badge.textContent = '🚨 緊急安否確認 発令中';
        }
        document.getElementById('emgTitle').textContent = signageData.emergency.title || '安否確認アラート';
        document.getElementById('emgDesc').textContent = signageData.emergency.description || '安全を確保し安否を報告してください。';
        overlay.classList.add('active');
        isPaused = true;
        clearTimeout(slideTimer);
    } else {
        overlay.classList.remove('active');
        overlay.classList.remove('drill-mode');
        if (isPaused && document.getElementById('pauseBadge').style.display === 'none') {
            isPaused = false;
            resetSlideTimer();
        }
    }
}

// キーボード操作
document.addEventListener('keydown', (e) => {
    if (e.code === 'Space') {
        e.preventDefault();
        togglePause();
    } else if (e.code === 'ArrowRight' || e.code === 'KeyN') {
        nextSlide();
    } else if (e.code === 'ArrowLeft' || e.code === 'KeyP') {
        prevSlide();
    } else if (e.code === 'KeyF') {
        toggleFullScreen();
    }
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

// 起動時初期化
document.addEventListener('DOMContentLoaded', () => {
    buildSlides();
    checkEmergencyStatus();

    // 5分ごとにバックグラウンド同期
    setInterval(fetchLatestData, 300000);
});
</script>

</body>
</html>
