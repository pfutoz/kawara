<?php
require_once __DIR__ . '/includes/auth_helper.php';

// 2. DB接続
require_once __DIR__ . '/includes/db.php';

// 📱 端末固定Cookieがあれば自動復元！なければlogin.phpへ
$login_user = checkAuthOrAutoLogin($pdo, $_SERVER['REQUEST_URI'] ?? '');
$current_staff_id = (int)$login_user['staff_id'];


// 休日判定ヘルパーの読み込み（事務長休日・小野会公休日の即時確認用）
if (file_exists(__DIR__ . '/includes/calendar_helper_jimucho.php')) {
    require_once __DIR__ . '/includes/calendar_helper_jimucho.php';
}

// 3. 各種マスター ＆ スタッフ情報の取得
$categories  = $pdo->query("SELECT * FROM post_categories WHERE is_active = TRUE ORDER BY display_order")->fetchAll();
$departments = $pdo->query("SELECT * FROM target_departments WHERE is_active = TRUE ORDER BY display_order")->fetchAll();
$staff_members = $pdo->query("SELECT staff_id, staff_name, role, kana, kana_row FROM staff WHERE is_deleted = FALSE ORDER BY kana ASC")->fetchAll();

// 4. 編集モード判定と既存データの読み込み (id または edit_id に対応)
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0);
$post_data = null;
$selected_depts = [];
$selected_staff = [];
$existing_schedules = [];

if ($post_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE post_id = :post_id");
    $stmt->execute([':post_id' => $post_id]);
    $post_data = $stmt->fetch();

    if ($post_data) {
        $stmt_d = $pdo->prepare("SELECT dept_id FROM post_target_departments WHERE post_id = :post_id");
        $stmt_d->execute([':post_id' => $post_id]);
        $selected_depts = $stmt_d->fetchAll(PDO::FETCH_COLUMN);

        $stmt_s = $pdo->prepare("SELECT staff_id FROM post_target_staff WHERE post_id = :post_id");
        $stmt_s->execute([':post_id' => $post_id]);
        $selected_staff = $stmt_s->fetchAll(PDO::FETCH_COLUMN);

        // 既存の複数日程 JSONB の復元
        if (!empty($post_data['event_schedules'])) {
            $decoded = json_decode($post_data['event_schedules'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $existing_schedules = $decoded;
            }
        }

        // target_datetime からのフォールバック復元
        if (empty($existing_schedules) && !empty($post_data['target_datetime'])) {
            $st = $post_data['target_datetime'];
            $ed = $post_data['target_end_datetime'];
            $is_allday = (substr($st, 11, 8) === '00:00:00' && substr($ed, 11, 8) === '23:59:59');
            $existing_schedules[] = [
                'schedule_id'    => 'slot_1',
                'date'           => substr($st, 0, 10),
                'end_date'       => $ed ? substr($ed, 0, 10) : substr($st, 0, 10),
                'start_time'     => substr($st, 11, 5),
                'end_time'       => $ed ? substr($ed, 11, 5) : '10:00',
                'is_all_day'     => $is_allday,
                'location'       => '',
                'memo'           => ''
            ];
        }
    }
}

$is_edit = ($post_data !== null);
$page_title = $is_edit ? '✏️ お知らせ・予定の編集（修正モード）' : '📝 新規お知らせ・予定の作成';
$is_unlimited = empty($post_data['display_until'] ?? '');

// 📋 登録済みイベント・お知らせの取得（完全新規作成時のみ取得して誤操作を防止）
$recent_source_posts = [];
if (!$is_edit) {
    $recent_source_posts = $pdo->query("
        SELECT p.post_id, p.title, p.category_id, p.content, p.event_schedules, p.target_datetime, p.target_end_datetime,
               c.category_name, c.icon_emoji, p.created_at,
               (SELECT array_to_json(array_agg(dept_id)) FROM post_target_departments WHERE post_id = p.post_id) AS depts_json,
               (SELECT array_to_json(array_agg(staff_id)) FROM post_target_staff WHERE post_id = p.post_id) AS staff_json
        FROM posts p
        LEFT JOIN post_categories c ON p.category_id = c.category_id
        ORDER BY p.post_id DESC
        LIMIT 40
    ")->fetchAll();
}

// 🔙 呼び出し元（return_to）の判定と適切な戻り先・ラベルの決定
$return_to = $_GET['return_to'] ?? '';
if (empty($return_to) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    if (strpos($ref, 'jimucho_dashboard.php') !== false) {
        $return_to = 'jimucho';
    } elseif (strpos($ref, 'view_post.php') !== false) {
        $return_to = 'view';
    } elseif (strpos($ref, 'index.php') !== false) {
        $return_to = 'index';
    }
}

if ($return_to === 'jimucho' || $return_to === 'jimucho_dashboard.php') {
    $back_url = 'jimucho_dashboard.php';
    $back_label = '👔 事務長ダッシュボードへ戻る';
} elseif (($return_to === 'view' || strpos($return_to, 'view_post.php') !== false) && $post_id > 0) {
    $back_url = "view_post.php?id={$post_id}";
    $back_label = '🔍 記事詳細へ戻る';
} else {
    $back_url = 'index.php';
    $back_label = '🏠 かわら版一覧へ戻る';
}

// 掲載終了予定日の初期プレビュー表示（曜日付き）
$until_preview = '♾️ 無期限';
if (!$is_unlimited && !empty($post_data['display_until'])) {
    $u_ts = strtotime($post_data['display_until']);
    if ($u_ts) {
        $dows = ['日', '月', '火', '水', '木', '金', '土'];
        $until_preview = date('Y/m/d', $u_ts) . '(' . $dows[(int)date('w', $u_ts)] . ') ' . date('H:i', $u_ts);
    } else {
        $until_preview = htmlspecialchars($post_data['display_until']);
    }
}

// デフォルトの日程が空の場合
if (empty($existing_schedules)) {
    $initial_date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
    $existing_schedules[] = [
        'schedule_id' => 'slot_1',
        'date'        => $initial_date,
        'end_date'    => $initial_date,
        'start_time'  => '09:00',
        'end_time'    => '10:00',
        'is_all_day'  => false,
        'location'    => '',
        'memo'        => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | 院内かわら版</title>
    <!-- Quill.js リッチテキストエディタ -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
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
            --danger: #dc2626;
            --warning: #f59e0b;
            --success: #16a34a;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.04);
        }

        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
            background: var(--bg-base);
            margin: 0;
            padding: 24px;
            color: var(--text-main);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 28px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 14px;
        }
        .page-header h1 {
            font-size: 1.35rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* 📋 過去イベントからコピー・流用バー */
        .copy-source-bar {
            background: linear-gradient(135deg, #f0fdf4, #e0f2fe);
            border: 2px solid #38bdf8;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .copy-source-label {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0369a1;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .btn-copy-apply {
            background: #0284c7;
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .btn-copy-apply:hover {
            background: #0369a1;
            transform: translateY(-1px);
        }

        /* 📝 修正モード：変更検知差分パネル */
        .diff-monitor-card {
            background: #fffbeb;
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 22px;
            display: none;
        }
        .diff-monitor-card.has-diff {
            display: block;
            animation: pulse-diff 2s infinite;
        }
        @keyframes pulse-diff {
            0%, 100% { border-color: #f59e0b; }
            50% { border-color: #dc2626; }
        }
        .diff-monitor-title {
            font-weight: 800;
            font-size: 0.95rem;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .diff-items-list {
            font-size: 0.88rem;
            color: #78350f;
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding-left: 20px;
        }
        .diff-badge {
            background: #fef08a;
            color: #854d0e;
            font-size: 0.75rem;
            font-weight: bold;
            padding: 2px 7px;
            border-radius: 4px;
            margin-left: 8px;
            display: none;
        }
        .diff-badge.show { display: inline-block; }

        /* フォームセクション */
        .form-section {
            background: #f8fafc;
            padding: 18px 20px;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-bottom: 22px;
        }
        .section-label {
            font-weight: 800;
            font-size: 1rem;
            margin-bottom: 12px;
            color: #1e293b;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px dashed var(--border-dark);
            padding-bottom: 8px;
        }

        .form-group { margin-bottom: 16px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-weight: 700;
            font-size: 0.88rem;
            margin-bottom: 6px;
            color: var(--text-main);
        }
        .form-control {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border-dark);
            border-radius: 6px;
            font-size: 0.95rem;
            background: #fff;
            transition: border-color 0.15s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        /* 🗓️ 複数日程スロットマネージャー */
        .schedule-slots-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 12px;
        }
        .slot-card {
            background: #ffffff;
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 14px 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            transition: all 0.15s;
            position: relative;
        }
        .slot-card:hover {
            border-color: #94a3b8;
        }
        .slot-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--primary-dark);
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 6px;
        }
        .slot-pill {
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .slot-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn-slot-action {
            background: #f8fafc;
            border: 1px solid var(--border-dark);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-sub);
            transition: all 0.15s;
        }
        .btn-slot-action:hover { background: #e2e8f0; color: #0f172a; }
        .btn-slot-delete {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c;
        }
        .btn-slot-delete:hover { background: #fecdd3; color: #991b1b; }

        .slot-grid-row {
            display: grid;
            grid-template-columns: 180px 140px auto 140px 140px;
            gap: 12px;
            align-items: center;
            margin-bottom: 10px;
        }
        @media (max-width: 768px) {
            .slot-grid-row { grid-template-columns: 1fr; gap: 8px; }
        }

        .slot-sub-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 6px;
        }
        @media (max-width: 768px) {
            .slot-sub-row { grid-template-columns: 1fr; }
        }

        .time-quick-btns {
            display: flex;
            gap: 4px;
            margin-top: 4px;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn-time-quick {
            background: #fff;
            border: 1px solid #16a34a;
            color: #16a34a;
            padding: 2px 7px;
            border-radius: 8px;
            font-size: 0.72rem;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-time-quick:hover { background: #16a34a; color: #fff; }

        .btn-add-slot {
            background: #ffffff;
            border: 2px dashed #0284c7;
            color: #0284c7;
            padding: 10px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .btn-add-slot:hover {
            background: #e0f2fe;
            border-color: #0369a1;
        }

        /* 掲載期間ボタン群 */
        .period-container { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-top: 5px; }
        .period-btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-period {
            background: #ffffff;
            color: var(--text-main);
            border: 1px solid var(--border-dark);
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-period:hover { background: #e2e8f0; }
        .btn-period.active {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 2px 5px rgba(2, 132, 199, 0.3);
        }

        /* エディタ装飾アシスタントバー */
        .editor-helper-bar {
            background: #f1f5f9;
            border: 1px solid var(--border-dark);
            border-bottom: none;
            padding: 6px 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            border-radius: 6px 6px 0 0;
        }
        .btn-helper-tag {
            background: #fff;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-helper-tag:hover { background: #e2e8f0; }
        #editor { min-height: 240px; background: #fff; font-size: 1rem; border-radius: 0 0 6px 6px; }

        /* LINE通知オプションカード */
        .line-option-card {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            padding: 12px 16px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .line-option-card label {
            cursor: pointer;
            font-weight: bold;
            font-size: 0.92rem;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        /* ボタングループ */
        .btn-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 2px solid var(--border);
        }
        .btn-submit {
            background: linear-gradient(135deg, #0284c7, #2563eb);
            color: white;
            border: none;
            padding: 12px 32px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 1.05rem;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
            transition: all 0.15s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(37, 99, 235, 0.35);
        }
        .btn-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 11px 22px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 0.95rem;
            cursor: pointer;
        }
        .btn-delete:hover { background: #dc2626; }

        /* トースト通知 */
        #toast-box {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 6px 16px rgba(0,0,0,0.25);
            display: none;
            z-index: 9999;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h1>
            <span><?= $is_edit ? '✏️' : '📝' ?></span>
            <span><?= $page_title ?></span>
        </h1>
        <a href="<?= htmlspecialchars($back_url) ?>" style="color:#0284c7; text-decoration:none; font-weight:bold; font-size:0.9rem;">
            <?= htmlspecialchars($back_label) ?>
        </a>
    </div>

    <!-- 1. 📋 過去のお知らせ・イベントからコピーして作成バー（完全新規作成時のみ表示） -->
    <?php if (!$is_edit): ?>
    <div class="copy-source-bar">
        <div class="copy-source-label">
            <span>📋 登録済みのイベント・お知らせをコピーして作成:</span>
            <small>過去の工事・定例会・点検などを選んで「コピー」を押すと、件名・通知先・本文・時間帯を一括流用して日程だけ調整できます。</small>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:8px;">
            <select id="source_post_selector" class="form-control" style="flex:1; min-width:320px;">
                <option value="">-- コピー元のお知らせ・イベントを選択してください --</option>
                <?php foreach ($recent_source_posts as $sp): ?>
                    <option value="<?= $sp['post_id'] ?>" data-json="<?= htmlspecialchars(json_encode($sp), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($sp['icon_emoji'] ?? '📄') ?> <?= htmlspecialchars($sp['title']) ?> (<?= date('Y/m/d', strtotime($sp['created_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-copy-apply" onclick="applyCopiedPost()">
                📋 この内容をフォームへコピー
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. 📝 修正モード専用：変更検知差分パネル（リアルタイム監視） -->
    <?php if ($is_edit): ?>
        <div id="diff-monitor-card" class="diff-monitor-card">
            <div class="diff-monitor-title">
                <span>📝 変更検知ハイライト（修正前の内容からの変更点）:</span>
            </div>
            <ul id="diff-items-list" class="diff-items-list">
                <!-- JSで動的差分生成 -->
            </ul>
        </div>
    <?php endif; ?>

    <form action="confirm_post.php" method="POST" enctype="multipart/form-data" id="postForm" onkeydown="return preventEnterSubmit(event);">
        <input type="hidden" name="post_id" value="<?= $post_id ?>">
        <input type="hidden" name="mode" id="f_mode" value="<?= $is_edit ? 'update' : 'create' ?>">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">
        
        <!-- 複数日程 JSON データ格納用 -->
        <input type="hidden" name="event_schedules" id="f_event_schedules" value="<?= htmlspecialchars(json_encode($existing_schedules)) ?>">
        
        <!-- 後方互換用パラメータ（第1日程と同期） -->
        <input type="hidden" name="event_date" id="f_legacy_event_date" value="">
        <input type="hidden" name="event_end_date" id="f_legacy_event_end_date" value="">
        <input type="hidden" name="start_time" id="f_legacy_start_time" value="">
        <input type="hidden" name="end_time" id="f_legacy_end_time" value="">
        <input type="hidden" name="is_all_day" id="f_legacy_is_all_day" value="0">

        <!-- 基本情報セクション -->
        <div class="form-section">
            <div class="section-label">
                <span>🏷️ 基本情報</span>
                <span id="diff-badge-basic" class="diff-badge">[基本情報変更あり]</span>
            </div>
            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
                <div style="flex:1; min-width:220px;">
                    <label>区分・カテゴリー</label>
                    <select name="category_id" id="f_category" class="form-control" onchange="onFieldChange()">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= ($is_edit && $post_data['category_id'] == $cat['category_id']) ? 'selected' : '' ?>>
                                <?= $cat['icon_emoji'] ?> <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top:24px;">
                    <label style="cursor:pointer; font-weight:bold; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="is_pinned" id="f_is_pinned" value="1" <?= ($is_edit && $post_data['is_pinned']) ? 'checked' : '' ?> onchange="onFieldChange()"> 
                        📌 画面最上部に固定（ピン留め）
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label>
                    件名（タイトル） <span style="color:red;">*</span>
                    <span id="diff-badge-title" class="diff-badge">[件名変更あり]</span>
                </label>
                <input type="text" name="title" id="f_title" class="form-control" required value="<?= htmlspecialchars($post_data['title'] ?? '') ?>" placeholder="例: 【本日実施】窓リフォームおよび換気扇交換作業" oninput="onFieldChange()">
            </div>
        </div>

        <!-- 🎯 通知対象セクション -->
        <div class="form-section">
            <div class="section-label">
                <span>🎯 通知対象の指定</span>
                <span id="diff-badge-target" class="diff-badge">[対象変更あり]</span>
            </div>
            <div style="display:flex; gap:16px; margin-bottom:12px; align-items:center;">
                <label style="cursor:pointer; font-weight:bold; display:flex; align-items:center; gap:6px;">
                    <input type="radio" name="target_type" value="dept" <?= (!$is_edit || !empty($selected_depts)) ? 'checked' : '' ?> onclick="toggleTargetType('dept')"> 部署グループで指定
                </label>
                <label style="cursor:pointer; font-weight:bold; display:flex; align-items:center; gap:6px;">
                    <input type="radio" name="target_type" value="individual" <?= ($is_edit && !empty($selected_staff)) ? 'checked' : '' ?> onclick="toggleTargetType('individual')"> 👤 特定の人だけに通知（指名）
                </label>
            </div>

            <div id="dept-selector" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <?php foreach ($departments as $d): ?>
                    <label style="font-size:0.9rem; cursor:pointer; display:inline-flex; align-items:center; gap:4px; background:#fff; padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                        <input type="checkbox" name="depts[]" value="<?= $d['dept_id'] ?>" <?= (!$is_edit && $d['dept_code'] === 'all') || in_array($d['dept_id'], $selected_depts) ? 'checked' : '' ?> onchange="onFieldChange()">
                        <?= htmlspecialchars($d['dept_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div id="individual-staff-box" style="display:none; margin-top:10px; background:#fff; padding:12px; border:1px solid #cbd5e1; border-radius:6px;">
                <div style="display:flex; gap:4px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                    <span style="font-weight:bold; font-size:0.8rem; color:#555; margin-right:6px;">50音フィルター:</span>
                    <?php foreach (['all'=>'全','あ'=>'あ','か'=>'か','さ'=>'さ','た'=>'た','な'=>'な','は'=>'は','ま'=>'ま','や'=>'や','ら'=>'ら','わ'=>'わ'] as $rk => $rv): ?>
                        <button type="button" class="btn-time-quick" style="padding:2px 8px; border-radius:4px;" onclick="filterStaffKana('<?= $rk ?>', this)"><?= $rv ?></button>
                    <?php endforeach; ?>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:6px; max-height:180px; overflow-y:auto; padding:4px;">
                    <?php foreach ($staff_members as $sm): 
                        $kana_trim = trim($sm['kana'] ?? '');
                        $first_char = mb_substr($kana_trim, 0, 1);
                    ?>
                        <label class="staff-item" data-first-char="<?= htmlspecialchars($first_char) ?>" style="font-size:0.85rem; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                            <input type="checkbox" name="target_staff_ids[]" value="<?= $sm['staff_id'] ?>" <?= in_array($sm['staff_id'], $selected_staff) ? 'checked' : '' ?> onchange="onFieldChange()">
                            <?= htmlspecialchars($sm['staff_name']) ?> <small style="color:#64748b;">(<?= htmlspecialchars($sm['role']) ?>)</small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 🗓️ イベント日程マネージャー（複数日程・時刻・場所対応） -->
        <div class="form-section" id="section-schedules">
            <div class="section-label">
                <span>🗓️ イベント・工事・点検の日程設定（複数日程対応）</span>
                <span id="diff-badge-schedules" class="diff-badge">[日程変更あり]</span>
            </div>
            <p style="font-size:0.85rem; color:#64748b; margin-bottom:12px;">
                1つの記事に対して、複数の日付・時間帯・場所を登録できます。登録した日程はカレンダーや部署別ポスターに自動連動します。
            </p>

            <div id="schedule-slots-container" class="schedule-slots-container">
                <!-- JSで動的にスロットカードを生成 -->
            </div>

            <button type="button" class="btn-add-slot" onclick="addNewScheduleSlot()">
                ➕ 新しい日程を追加する
            </button>
        </div>

        <!-- 掲載期間セクション -->
        <div class="form-section">
            <div class="section-label">
                <span>⏰ 掲示期間（掲載終了日）</span>
                <span id="diff-badge-period" class="diff-badge">[掲載期間変更あり]</span>
            </div>
            <div class="period-container">
                <div class="period-btn-group" id="periodBtnGroup">
                    <button type="button" class="btn-period <?= $is_unlimited ? 'active' : '' ?>" onclick="selectPeriod(0, this)">♾️ 無期限</button>
                    <button type="button" class="btn-period" onclick="selectPeriod(1, this)">1日間</button>
                    <button type="button" class="btn-period" onclick="selectPeriod(3, this)">3日間</button>
                    <button type="button" class="btn-period" onclick="selectPeriod(7, this)">1週間</button>
                    <button type="button" class="btn-period" onclick="selectPeriod(14, this)">2週間</button>
                    <button type="button" class="btn-period" onclick="selectPeriod(30, this)">1ヶ月</button>
                </div>
                <div style="font-size:0.9rem; font-weight:bold; color:#475569; background:#fff; padding:6px 14px; border:1px solid #cbd5e1; border-radius:6px;">
                    掲載終了予定: <span id="lbl_display_until_preview" style="color:#0284c7;"><?= $until_preview ?></span>
                </div>
                <input type="hidden" name="display_until" id="f_display_until" value="<?= htmlspecialchars($post_data['display_until'] ?? '') ?>">
            </div>
        </div>

        <!-- 記事本文エディタ（カラー＆網掛けハイライト対応） -->
        <div class="form-group">
            <div class="section-label" style="border-bottom:none; margin-bottom:4px;">
                <span>📝 お知らせ本文 <span style="color:red;">*</span></span>
                <span id="diff-badge-content" class="diff-badge">[本文変更あり]</span>
            </div>

            <!-- エディタクイック装飾アシスタントバー -->
            <div class="editor-helper-bar">
                <span style="font-size:0.78rem; font-weight:bold; color:#475569;">クイック装飾:</span>
                <button type="button" class="btn-helper-tag" onclick="applyHighlight('#fef08a')">🟡 蛍光黄マーカー</button>
                <button type="button" class="btn-helper-tag" onclick="applyHighlight('#fecdd3')">🔴 薄赤マーカー</button>
                <button type="button" class="btn-helper-tag" onclick="applyHighlight('#bbf7d0')">🟢 薄緑マーカー</button>
                <button type="button" class="btn-helper-tag" onclick="applyTextColor('#dc2626')">🔴 赤文字太字</button>
                <button type="button" class="btn-helper-tag" onclick="insertCallout('warning')">⚠️ 警告枠を挿入</button>
                <button type="button" class="btn-helper-tag" onclick="insertCallout('notice')">📢 案内枠を挿入</button>
                <button type="button" class="btn-helper-tag" onclick="copySchedulesToEditor()">🗓 日程表を本文に自動挿入</button>
            </div>

            <input type="hidden" name="content" id="hiddenContent">
            <div id="editor"><?= $post_data['content'] ?? '' ?></div>
        </div>

        <!-- 写真・画像添付 -->
        <div class="form-group" style="margin-top:20px;">
            <label>🖼️ 写真・画像添付（複数可）</label>
            <input type="file" name="images[]" multiple accept="image/*" class="form-control">
        </div>

        <!-- LINE通知オプション -->
        <div class="line-option-card" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <label>
                    <input type="checkbox" name="send_line" value="1">
                    <span>📲 対象スタッフのLINEへ更新通知を送信する（デフォルト: オフ）</span>
                </label>
                <div style="font-size:0.8rem; color:#065f46; font-weight:bold; margin-top:2px;">
                    ※有事・重要連絡時のみチェック
                </div>
            </div>
            <?php if ($is_edit && !empty($post_id)): ?>
                <button type="button" onclick="openLineNotifyModal(<?= (int)$post_id ?>)" style="background:#16a34a; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; font-size:0.85rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 6px rgba(22,163,74,0.25);">
                    💬 LINE Flex通知 プレビュー＆送信
                </button>
            <?php endif; ?>
        </div>

        <!-- アクションボタンバー -->
        <div class="btn-bar">
            <div>
                <a href="<?= htmlspecialchars($back_url) ?>" style="color:#64748b; text-decoration:none; margin-right:15px; font-weight:bold;">
                    ← キャンセル
                </a>
                <?php if ($is_edit): ?>
                    <button type="button" class="btn-delete" onclick="submitDelete()">🗑️ このお知らせを削除する</button>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-submit" onclick="submitQuill()">
                <?= $is_edit ? '確認画面で修正内容をチェック →' : '確認画面へ進む →' ?>
            </button>
        </div>
    </form>
</div>

<!-- トースト通知 -->
<div id="toast-box"></div>

<?php require_once __DIR__ . '/includes/line_notify_modal.php'; ?>


<!-- Quill.js 本体 -->
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
// 1. 元データ（修正前差分比較用）
const isEditMode = <?= $is_edit ? 'true' : 'false' ?>;
let originalData = {
    title: <?= json_encode($post_data['title'] ?? '') ?>,
    category_id: <?= json_encode((string)($post_data['category_id'] ?? '1')) ?>,
    is_pinned: <?= json_encode(!empty($post_data['is_pinned'])) ?>,
    target_type: <?= json_encode(!empty($selected_staff) ? 'individual' : 'dept') ?>,
    depts: <?= json_encode(array_map('strval', $selected_depts)) ?>,
    staff: <?= json_encode(array_map('strval', $selected_staff)) ?>,
    schedules: <?= json_encode($existing_schedules) ?>,
    display_until: <?= json_encode($post_data['display_until'] ?? '') ?>,
    content: <?= json_encode($post_data['content'] ?? '') ?>
};

// 2. 現在の複数日程スロット配列
let currentSchedules = <?= json_encode($existing_schedules) ?>;
if (!Array.isArray(currentSchedules) || currentSchedules.length === 0) {
    currentSchedules = [{
        schedule_id: 'slot_1',
        date: new Date().toISOString().substring(0, 10),
        end_date: new Date().toISOString().substring(0, 10),
        start_time: '09:00',
        end_time: '10:00',
        is_all_day: false,
        location: '',
        memo: ''
    }];
}

// 3. Quill エディタ初期化（拡張カラーパレット ＆ 網掛けハイライト）
var quill = new Quill('#editor', {
    theme: 'snow',
    placeholder: '本文を入力してください...',
    modules: {
        toolbar: [
            [{ 'header': [2, 3, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [
                // 🎨 拡張文字色
                { 'color': [
                    '#000000', '#ffffff', '#475569', '#dc2626', '#b91c1c', 
                    '#ea580c', '#d97706', '#16a34a', '#065f46', '#0284c7', 
                    '#0369a1', '#7c3aed', '#db2777'
                ]},
                // 🟡 拡張背景色・網掛けハイライト
                { 'background': [
                    'transparent', '#fef08a', '#fecdd3', '#bae6fd', '#bbf7d0', 
                    '#fed7aa', '#e9d5ff', '#f1f5f9', '#cbd5e1'
                ]}
            ],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link', 'blockquote', 'clean']
        ]
    }
});

quill.on('text-change', () => {
    onFieldChange();
});

// 4. DOM初期化
document.addEventListener('DOMContentLoaded', () => {
    renderScheduleSlots();
    onFieldChange();
});

// トースト通知関数
function showToast(msg) {
    const box = document.getElementById('toast-box');
    box.textContent = msg;
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 4000);
}

// -------------------------------------------------------------
// 🗓️ 複数日程スロットのレンダリング ＆ 操作（曜日表示付き）
// -------------------------------------------------------------
function formatDowBadge(dateStr) {
    if (!dateStr) return '<span style="color:#94a3b8; font-size:0.78rem; font-weight:normal;">(日付未指定)</span>';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return '';
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10) - 1;
    const d = parseInt(parts[2], 10);
    const dt = new Date(y, m, d);
    if (isNaN(dt.getTime())) return '';

    const dows = ['日', '月', '火', '水', '木', '金', '土'];
    const w = dt.getDay();
    const dowText = dows[w];

    let style = 'background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;';
    let extra = '';
    if (w === 0) {
        style = 'background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;';
        extra = ' (休診)';
    } else if (w === 6) {
        style = 'background:#dbeafe; color:#1d4ed8; border:1px solid #93c5fd;';
    } else if (w === 3) {
        style = 'background:#fef3c7; color:#b45309; border:1px solid #fde68a;';
        extra = ' (水曜)';
    }

    return `<span style="${style} font-size:0.78rem; font-weight:800; padding:2px 7px; border-radius:5px; display:inline-flex; align-items:center; gap:2px;">📅 (${dowText})${extra}</span>`;
}

function updateSlotDate(index, value) {
    updateSlot(index, 'date', value);
    const badge = document.getElementById(`slot-dow-badge-${index}`);
    if (badge) {
        badge.innerHTML = formatDowBadge(value);
    }
}

function renderScheduleSlots() {
    const container = document.getElementById('schedule-slots-container');
    container.innerHTML = '';

    currentSchedules.forEach((slot, index) => {
        const card = document.createElement('div');
        card.className = 'slot-card';
        card.id = `slot-card-${index}`;

        const isAllDay = !!slot.is_all_day;

        card.innerHTML = `
            <div class="slot-card-header">
                <span class="slot-pill">🗓️ 日程 #${index + 1}</span>
                <div class="slot-actions">
                    <button type="button" class="btn-slot-action" onclick="duplicateScheduleSlot(${index})">📋 この日程を複製</button>
                    ${currentSchedules.length > 1 ? `<button type="button" class="btn-slot-action btn-slot-delete" onclick="removeScheduleSlot(${index})">🗑️ 削除</button>` : ''}
                </div>
            </div>

            <div class="slot-grid-row">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px; gap:6px;">
                        <label style="font-size:0.8rem; font-weight:bold; margin-bottom:0;">実施日 <span style="color:red;">*</span></label>
                        <span id="slot-dow-badge-${index}">${formatDowBadge(slot.date)}</span>
                    </div>
                    <input type="date" class="form-control slot-input-date" value="${slot.date || ''}" onchange="updateSlotDate(${index}, this.value)" oninput="updateSlotDate(${index}, this.value)">
                </div>

                <div class="slot-time-start-box" style="${isAllDay ? 'display:none;' : ''}">
                    <label style="font-size:0.8rem; font-weight:bold; margin-bottom:3px; display:block;">開始時刻</label>
                    <input type="time" class="form-control" value="${slot.start_time || '09:00'}" onchange="updateSlotTimeStart(${index}, this.value)">
                </div>

                <div style="text-align:center; font-weight:bold; color:#64748b; margin-top:20px; ${isAllDay ? 'display:none;' : ''}">
                    〜
                </div>

                <div class="slot-time-end-box" style="${isAllDay ? 'display:none;' : ''}">
                    <label style="font-size:0.8rem; font-weight:bold; margin-bottom:3px; display:block;">終了時刻</label>
                    <input type="time" class="form-control slot-time-end-input" value="${slot.end_time || '10:00'}" onchange="updateSlot(${index}, 'end_time', this.value)">
                    <div class="time-quick-btns">
                        <button type="button" class="btn-time-quick" onclick="addMinutesToSlot(${index}, 15)">+15分</button>
                        <button type="button" class="btn-time-quick" onclick="addMinutesToSlot(${index}, 30)">+30分</button>
                        <button type="button" class="btn-time-quick" onclick="addMinutesToSlot(${index}, 60)">+1h</button>
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <label style="font-size:0.82rem; font-weight:bold; cursor:pointer; color:#0284c7; display:flex; align-items:center; gap:4px;">
                        <input type="checkbox" ${isAllDay ? 'checked' : ''} onchange="toggleSlotAllDay(${index}, this.checked)"> 終日
                    </label>
                </div>
            </div>

            <div class="slot-sub-row">
                <div>
                    <label style="font-size:0.8rem; font-weight:bold; margin-bottom:3px; display:block;">📍 実施場所・対象設備</label>
                    <input type="text" class="form-control" placeholder="例: 新館2F マイトイレ / 旧館1F廊下" value="${slot.location || ''}" oninput="updateSlot(${index}, 'location', this.value)">
                </div>
                <div>
                    <label style="font-size:0.8rem; font-weight:bold; margin-bottom:3px; display:block;">📝 作業・注意事項メモ</label>
                    <input type="text" class="form-control" placeholder="例: 換気扇交換・使用禁止 / 施工: 〇〇サッシ" value="${slot.memo || ''}" oninput="updateSlot(${index}, 'memo', this.value)">
                </div>
            </div>
        `;

        container.appendChild(card);
    });

    syncLegacyInputs();
    onFieldChange();
}

function addNewScheduleSlot() {
    // 直前の日程をベースに翌日を初期値とする
    let nextDate = new Date();
    if (currentSchedules.length > 0 && currentSchedules[currentSchedules.length - 1].date) {
        nextDate = new Date(currentSchedules[currentSchedules.length - 1].date);
        nextDate.setDate(nextDate.getDate() + 1);
    }
    const yyyy = nextDate.getFullYear();
    const mm = String(nextDate.getMonth() + 1).padStart(2, '0');
    const dd = String(nextDate.getDate()).padStart(2, '0');

    currentSchedules.push({
        schedule_id: 'slot_' + (currentSchedules.length + 1),
        date: `${yyyy}-${mm}-${dd}`,
        end_date: `${yyyy}-${mm}-${dd}`,
        start_time: '09:00',
        end_time: '10:00',
        is_all_day: false,
        location: currentSchedules.length > 0 ? currentSchedules[currentSchedules.length - 1].location : '',
        memo: ''
    });

    renderScheduleSlots();
    showToast(`✓ 日程 #${currentSchedules.length} を追加しました`);
}

function duplicateScheduleSlot(index) {
    const src = currentSchedules[index];
    currentSchedules.splice(index + 1, 0, {
        schedule_id: 'slot_' + (currentSchedules.length + 1),
        date: src.date,
        end_date: src.end_date,
        start_time: src.start_time,
        end_time: src.end_time,
        is_all_day: src.is_all_day,
        location: src.location,
        memo: src.memo
    });
    renderScheduleSlots();
    showToast(`✓ 日程 #${index + 1} を複製しました`);
}

function removeScheduleSlot(index) {
    if (currentSchedules.length <= 1) return;
    currentSchedules.splice(index, 1);
    renderScheduleSlots();
}

function updateSlot(index, field, value) {
    currentSchedules[index][field] = value;
    syncLegacyInputs();
    onFieldChange();
}

function updateSlotTimeStart(index, val) {
    currentSchedules[index].start_time = val;
    if (val) {
        const [h, m] = val.split(':').map(Number);
        const endH = (h + 1) % 24;
        currentSchedules[index].end_time = String(endH).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }
    renderScheduleSlots();
}

function addMinutesToSlot(index, mins) {
    const st = currentSchedules[index].start_time || '09:00';
    const [h, m] = st.split(':').map(Number);
    const total = (h * 60) + m + mins;
    const endH = Math.floor(total / 60) % 24;
    const endM = total % 60;
    currentSchedules[index].end_time = String(endH).padStart(2, '0') + ':' + String(endM).padStart(2, '0');
    renderScheduleSlots();
}

function toggleSlotAllDay(index, isAllDay) {
    currentSchedules[index].is_all_day = isAllDay;
    renderScheduleSlots();
}

function syncLegacyInputs() {
    if (currentSchedules.length > 0) {
        const first = currentSchedules[0];
        document.getElementById('f_legacy_event_date').value = first.date || '';
        document.getElementById('f_legacy_event_end_date').value = first.date || '';
        document.getElementById('f_legacy_start_time').value = first.start_time || '09:00';
        document.getElementById('f_legacy_end_time').value = first.end_time || '10:00';
        document.getElementById('f_legacy_is_all_day').value = first.is_all_day ? '1' : '0';
    }
    document.getElementById('f_event_schedules').value = JSON.stringify(currentSchedules);
}

// -------------------------------------------------------------
// 📋 登録済みイベントからコピーして作成
// -------------------------------------------------------------
function applyCopiedPost() {
    const sel = document.getElementById('source_post_selector');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        alert('コピー元のお知らせ・イベントを選択してください。');
        return;
    }

    const jsonStr = opt.getAttribute('data-json');
    if (!jsonStr) return;

    try {
        const sp = JSON.parse(jsonStr);

        // 基本情報コピー
        if (sp.category_id) document.getElementById('f_category').value = sp.category_id;
        document.getElementById('f_title').value = sp.title || '';

        // 本文コピー
        if (sp.content) {
            quill.clipboard.dangerouslyPasteHTML(0, sp.content);
        }

        // 通知先コピー
        if (sp.depts_json) {
            const depts = typeof sp.depts_json === 'string' ? JSON.parse(sp.depts_json) : sp.depts_json;
            document.querySelectorAll('input[name="depts[]"]').forEach(cb => {
                cb.checked = depts.includes(parseInt(cb.value));
            });
            toggleTargetType('dept');
            document.querySelector('input[name="target_type"][value="dept"]').checked = true;
        }

        // 日程スロットのスマートコピー（日付は今日にリセット、時間・場所・メモは引き継ぎ）
        if (sp.event_schedules) {
            const decoded = typeof sp.event_schedules === 'string' ? JSON.parse(sp.event_schedules) : sp.event_schedules;
            if (Array.isArray(decoded) && decoded.length > 0) {
                const today = new Date().toISOString().substring(0, 10);
                currentSchedules = decoded.map((s, idx) => ({
                    schedule_id: 'slot_' + (idx + 1),
                    date: today,
                    end_date: today,
                    start_time: s.start_datetime ? s.start_datetime.substring(11, 16) : (s.start_time || '09:00'),
                    end_time: s.end_datetime ? s.end_datetime.substring(11, 16) : (s.end_time || '10:00'),
                    is_all_day: !!s.is_all_day,
                    location: s.location || '',
                    memo: s.memo || ''
                }));
            }
        } else if (sp.target_datetime) {
            const today = new Date().toISOString().substring(0, 10);
            currentSchedules = [{
                schedule_id: 'slot_1',
                date: today,
                end_date: today,
                start_time: sp.target_datetime.substring(11, 16),
                end_time: sp.target_end_datetime ? sp.target_end_datetime.substring(11, 16) : '10:00',
                is_all_day: false,
                location: '',
                memo: ''
            }];
        }

        renderScheduleSlots();
        showToast(`✓ 「${sp.title}」の内容をコピーしました。日付や時刻を調整してください。`);

        // 日程セクションへスムーズスクロール
        document.getElementById('section-schedules').scrollIntoView({ behavior: 'smooth' });

    } catch (e) {
        alert('コピー処理中にエラーが発生しました: ' + e.message);
    }
}

// -------------------------------------------------------------
// 📝 修正モード：変更検知差分ハイライト
// -------------------------------------------------------------
function onFieldChange() {
    if (!isEditMode) return;

    const diffs = [];

    // 件名
    const curTitle = document.getElementById('f_title').value.trim();
    const titleBadge = document.getElementById('diff-badge-title');
    if (curTitle !== originalData.title) {
        diffs.push(`<b>件名</b>: 「${originalData.title}」 ➔ 「${curTitle}」`);
        titleBadge.classList.add('show');
    } else {
        titleBadge.classList.remove('show');
    }

    // カテゴリー
    const curCat = document.getElementById('f_category').value;
    const catBadge = document.getElementById('diff-badge-basic');
    if (curCat !== originalData.category_id) {
        diffs.push(`<b>カテゴリー</b>が変更されました`);
        catBadge.classList.add('show');
    } else {
        catBadge.classList.remove('show');
    }

    // 日程スロット
    const curSchedJson = JSON.stringify(currentSchedules);
    const origSchedJson = JSON.stringify(originalData.schedules);
    const schedBadge = document.getElementById('diff-badge-schedules');
    if (curSchedJson !== origSchedJson) {
        diffs.push(`<b>イベント日程・時間帯・場所</b>に変更あり (${currentSchedules.length}件の日程)`);
        schedBadge.classList.add('show');
    } else {
        schedBadge.classList.remove('show');
    }

    // 本文
    const curContent = quill.root.innerHTML.trim();
    const contentBadge = document.getElementById('diff-badge-content');
    if (curContent !== (originalData.content || '').trim()) {
        diffs.push(`<b>お知らせ本文</b>が修正されました`);
        contentBadge.classList.add('show');
    } else {
        contentBadge.classList.remove('show');
    }

    // 差分カードの更新
    const diffCard = document.getElementById('diff-monitor-card');
    const diffList = document.getElementById('diff-items-list');

    if (diffs.length > 0) {
        diffCard.classList.add('has-diff');
        diffList.innerHTML = diffs.map(d => `<li>${d}</li>`).join('');
    } else {
        diffCard.classList.remove('has-diff');
        diffList.innerHTML = '';
    }
}

// -------------------------------------------------------------
// 🎨 リッチテキストエディタ補助機能（色付け・網掛け・枠挿入）
// -------------------------------------------------------------
function applyHighlight(color) {
    const range = quill.getSelection();
    if (range && range.length > 0) {
        quill.format('background', color);
    } else {
        alert('網掛けを適用するテキストをマウスで選択してください。');
    }
}

function applyTextColor(color) {
    const range = quill.getSelection();
    if (range && range.length > 0) {
        quill.format('color', color);
        quill.format('bold', true);
    } else {
        alert('文字色を適用するテキストをマウスで選択してください。');
    }
}

function insertCallout(type) {
    const range = quill.getSelection(true);
    let html = '';
    if (type === 'warning') {
        html = '<p style="background:#fff1f2; border-left:6px solid #e11d48; padding:10px 14px; color:#991b1b; font-weight:bold;">⚠️ 【重要注意事項】ここに注意内容を入力してください。</p><p></p>';
    } else {
        html = '<p style="background:#e0f2fe; border-left:6px solid #0284c7; padding:10px 14px; color:#0369a1; font-weight:bold;">📢 【お知らせ案内】ここに案内内容を入力してください。</p><p></p>';
    }
    quill.clipboard.dangerouslyPasteHTML(range.index, html);
}

function copySchedulesToEditor() {
    if (currentSchedules.length === 0) return;
    let html = '<p><strong>【実施日程・場所】</strong></p><ul>';
    currentSchedules.forEach((s, idx) => {
        const timeStr = s.is_all_day ? '終日' : `${s.start_time} 〜 ${s.end_time}`;
        const locStr = s.location ? ` (場所: ${s.location})` : '';
        const memoStr = s.memo ? ` - ※${s.memo}` : '';
        html += `<li><strong>日程 #${idx + 1}:</strong> ${s.date} ${timeStr}${locStr}${memoStr}</li>`;
    });
    html += '</ul><p></p>';
    quill.clipboard.dangerouslyPasteHTML(quill.getLength(), html);
    showToast('✓ 日程一覧を本文に挿入しました');
}

// -------------------------------------------------------------
// フォーム送信制御
// -------------------------------------------------------------
function toggleTargetType(type) {
    document.getElementById('dept-selector').style.display = (type === 'dept') ? 'flex' : 'none';
    document.getElementById('individual-staff-box').style.display = (type === 'individual') ? 'block' : 'none';
    onFieldChange();
}

function filterStaffKana(row, btn) {
    const kanaRowMap = {
        'あ': ['ア', 'イ', 'ウ', 'エ', 'オ', 'ぁ', 'ぃ', 'ぅ', 'ぇ', 'ぉ'],
        'か': ['カ', 'キ', 'ク', 'ケ', 'コ', 'が', 'ぎ', 'ぐ', 'げ', 'ご'],
        'さ': ['サ', 'シ', 'ス', 'セ', 'ソ', 'ざ', 'じ', 'ず', 'ぜ', 'ぞ'],
        'た': ['タ', 'チ', 'ツ', 'テ', 'ト', 'だ', 'ぢ', 'づ', 'で', 'ど'],
        'な': ['ナ', 'ニ', 'ヌ', 'ネ', 'ノ'],
        'は': ['ハ', 'ヒ', 'フ', 'ヘ', 'ホ', 'ば', 'び', 'ぶ', 'べ', 'ぼ'],
        'ま': ['マ', 'ミ', 'ム', 'メ', 'モ'],
        'や': ['ヤ', 'ユ', 'ヨ', 'ゃ', 'ゅ', 'ょ'],
        'ら': ['ラ', 'リ', 'ル', 'レ', 'ロ'],
        'わ': ['ワ', 'ヲ', 'ン']
    };

    const items = document.querySelectorAll('.staff-item');
    items.forEach(item => {
        const fc = item.getAttribute('data-first-char');
        if (row === 'all') {
            item.style.display = 'inline-flex';
        } else {
            const chars = kanaRowMap[row] || [];
            item.style.display = chars.includes(fc) ? 'inline-flex' : 'none';
        }
    });
}

function selectPeriod(days, btn) {
    const buttons = document.querySelectorAll('#periodBtnGroup .btn-period');
    buttons.forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    if (days === 0) {
        document.getElementById('f_display_until').value = '';
        document.getElementById('lbl_display_until_preview').textContent = '♾️ 無期限';
    } else {
        const d = new Date();
        d.setDate(d.getDate() + days);
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dows = ['日', '月', '火', '水', '木', '金', '土'];
        const dow = dows[d.getDay()];
        document.getElementById('f_display_until').value = `${yyyy}-${mm}-${dd} 23:59:00`;
        document.getElementById('lbl_display_until_preview').textContent = `${yyyy}/${mm}/${dd}(${dow}) 23:59`;
    }
    onFieldChange();
}

function submitQuill() {
    syncLegacyInputs();
    document.getElementById('hiddenContent').value = quill.root.innerHTML;
}

function submitDelete() {
    if (confirm('このお知らせ・予定を完全に削除してもよろしいですか？')) {
        document.getElementById('f_mode').value = 'delete';
        submitQuill();
        document.getElementById('postForm').submit();
    }
}

function preventEnterSubmit(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
        if (!e.isComposing && e.target.tagName !== 'TEXTAREA' && !e.target.classList.contains('ql-editor')) {
            e.preventDefault();
            return false;
        }
    }
}
</script>

</body>
</html>