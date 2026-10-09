<?php
/**
 * ============================================================
 * ファイル名: index.php
 * システム名: 院内かわら版（医療法人小野会）
 * バージョン: v3.1（1024×768 コンパクト最適化メニュー）
 * ============================================================
 *
 * 【概要】
 * 院内かわら版のメインポータル・機能ランチャー画面
 * 1024×768端末（電子カルテ・院内PC）でもスクロールなしで
 * 全体が見渡せる縦方向超圧縮レイアウト
 */

require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/db.php';

if (file_exists(__DIR__ . '/includes/line_helper.php')) {
    require_once __DIR__ . '/includes/line_helper.php';
}

// 📱 端末固定Cookieがあれば自動復元！なければlogin.phpへ
$login_user = checkAuthOrAutoLogin($pdo, $_SERVER['REQUEST_URI'] ?? '');
$current_staff_id = (int)$login_user['staff_id'];
$is_admin = (bool)($login_user['is_admin'] ?? false);
$is_jimucho = ($current_staff_id === 15 || mb_strpos($login_user['staff_name'] ?? '', '山本') !== false || mb_strpos($login_user['role'] ?? '', '事務') !== false);
$has_line_id = !empty(trim($login_user['line_user_id'] ?? ''));

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

// 📊 統計情報（未読件数・本日の予定件数など）の取得
$today_str = date('Y-m-d');

// 1. 掲載中のお知らせ総件数
$stmt_total = $pdo->query("SELECT COUNT(*) FROM posts WHERE (display_until IS NULL OR display_until >= NOW())");
$total_active_posts = (int)$stmt_total->fetchColumn();

// 2. 自分の未読件数の集計（掲載中かつ未読の投稿）
$stmt_unread = $pdo->query("
    SELECT COUNT(*) 
    FROM posts p
    WHERE (p.display_until IS NULL OR p.display_until >= NOW())
      AND NOT EXISTS (
          SELECT 1 FROM post_reads rd 
          WHERE rd.post_id = p.post_id AND rd.staff_id = {$current_staff_id}
      )
");
$unread_count = (int)$stmt_unread->fetchColumn();

// 3. 直近・緊急のお知らせ（最新3件）
$stmt_urgent = $pdo->query("
    SELECT p.post_id, p.title, p.category_id, p.target_datetime, p.created_at,
           c.category_name, c.icon_emoji, c.color_code, c.category_code,
           s.staff_name AS author_name,
           (SELECT COUNT(*) FROM post_reads rd WHERE rd.post_id = p.post_id AND rd.staff_id = {$current_staff_id}) AS is_my_read
    FROM posts p
    LEFT JOIN post_categories c ON p.category_id = c.category_id
    LEFT JOIN staff s ON p.author_id = s.staff_id
    WHERE (p.display_until IS NULL OR p.display_until >= NOW())
      AND (
          p.is_pinned = TRUE 
          OR c.category_code IN ('urgent', 'important')
          OR (p.target_datetime IS NOT NULL AND DATE(p.target_datetime) = '{$today_str}')
      )
    ORDER BY p.is_pinned DESC, p.created_at DESC
    LIMIT 3
");
$urgent_posts = $stmt_urgent->fetchAll();

// 曜日配列
$week_names = ['日', '月', '火', '水', '木', '金', '土'];
$today_w = (int)date('w');
$today_display = date('Y/n/j') . '(' . $week_names[$today_w] . ')';

$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>院内かわら版 - メニュー | 医療法人小野会</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #004085;
            --primary-light: #eff6ff;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 3px 8px rgba(0,0,0,0.06);
            --radius-sm: 6px;
            --radius-md: 8px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        /* 💻 ヘッダー（超薄型・44px） */
        header {
            background: var(--primary);
            color: #ffffff;
            padding: 6px 14px;
            box-shadow: 0 1px 4px rgba(0, 90, 156, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-container {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .header-title-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header-icon {
            font-size: 1.3rem;
            line-height: 1;
        }
        .header-title h1 {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.2px;
            line-height: 1.1;
            display: inline-block;
        }
        .header-title span {
            font-size: 0.72rem;
            opacity: 0.88;
            margin-left: 6px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .user-info {
            font-size: 0.78rem;
            background: rgba(255, 255, 255, 0.18);
            padding: 3px 8px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            color: #fff;
        }
        .user-info:hover { background: rgba(255, 255, 255, 0.3); }
        .user-tag {
            font-size: 0.68rem;
            background: rgba(255, 255, 255, 0.3);
            padding: 1px 4px;
            border-radius: 3px;
        }

        .btn-line-header {
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-line-header.is-linked {
            background: #e8f9ee;
            color: #06c755;
            border: 1px solid #b2e8c4;
        }
        .btn-line-header.is-unlinked {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .btn-portal {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 3px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.76rem;
            font-weight: bold;
        }
        .btn-portal:hover { background: rgba(255, 255, 255, 0.35); }

        /* メインコンテナ（縦マージン縮小） */
        main {
            max-width: 1000px;
            margin: 0.6rem auto;
            padding: 0 0.8rem 1rem;
        }

        /* アラートメッセージ */
        .alert-msg {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 0.82rem;
            margin-bottom: 0.6rem;
        }

        /* BCPバナー（スリム化） */
        .bcp-banner {
            border-radius: 6px;
            padding: 6px 12px;
            margin-bottom: 0.6rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: bold;
        }
        .bcp-banner.disaster {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #dc2626;
            color: #b91c1c;
        }
        .bcp-banner.drill {
            background: #fff8ee;
            border: 1px solid #fde68a;
            border-left: 4px solid #e67e22;
            color: #b45309;
        }

        /* 🌟 スマート・ステータスバー（ウェルカム＋日付＋未読を1行に集約！） */
        .status-strip {
            background: #ffffff;
            border: 1px solid var(--border);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-md);
            padding: 7px 12px;
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            box-shadow: var(--shadow-sm);
        }
        .status-strip-left {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.84rem;
            color: #334155;
            flex-wrap: wrap;
        }
        .status-strip-left strong {
            color: #0f172a;
        }
        .status-divider {
            color: #cbd5e1;
        }
        .status-strip-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pill-unread {
            background: #f3e8ff;
            color: #6b21a8;
            border: 1px solid #c4b5fd;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .pill-unread:hover {
            background: #8b5cf6;
            color: #fff;
            border-color: #8b5cf6;
        }
        .pill-all-read {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.75rem;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 12px;
        }

        /* セクション見出し（超スリム） */
        .section-header {
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* 🚀 メインメニューグリッド（3列配置 × 薄型カード） */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 0.85rem;
        }
        .menu-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.15s ease;
            position: relative;
            box-shadow: var(--shadow-sm);
        }
        .menu-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
        }
        .menu-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        /* 🔍 クイックキーワード検索バー（メニュー化） */
        .portal-search-strip {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 5px 10px;
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            box-shadow: var(--shadow-sm);
        }
        .portal-search-form {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 260px;
            max-width: 480px;
        }
        .portal-search-input-wrap {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: var(--radius-sm);
            padding: 3px 8px;
            flex: 1;
            transition: all 0.2s;
        }
        .portal-search-input-wrap:focus-within {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(0, 90, 156, 0.12);
        }
        .portal-search-icon {
            font-size: 0.9rem;
            margin-right: 6px;
            opacity: 0.7;
        }
        .portal-search-input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 0.82rem;
            width: 100%;
            color: #1e293b;
            font-family: inherit;
        }
        .portal-search-input::placeholder { color: #94a3b8; }
        .btn-portal-search-submit {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 5px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .btn-portal-search-submit:hover { background: var(--primary-dark); }
        .portal-quick-tags {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        .portal-tag-label {
            font-size: 0.74rem;
            color: var(--text-muted);
            margin-right: 2px;
        }
        .portal-tag-link {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 0.74rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .portal-tag-link:hover {
            background: var(--primary-light);
            border-color: #bfdbfe;
            color: var(--primary);
        }

        .icon-list    { background: #eff6ff; color: #0284c7; border: 1px solid #bfdbfe; }
        .icon-create  { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .icon-search  { background: #fefce8; color: #ca8a04; border: 1px solid #fef08a; }
        .icon-jimucho { background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; }
        .icon-safety  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .icon-mente   { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
        .icon-help    { background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; }
        .icon-portal  { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

        .menu-info { flex: 1; min-width: 0; }
        .menu-title {
            font-size: 0.94rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .menu-arrow {
            color: #94a3b8;
            font-size: 0.8rem;
            transition: transform 0.15s, color 0.15s;
        }
        .menu-card:hover .menu-arrow {
            transform: translateX(2px);
            color: var(--primary);
        }
        .menu-desc {
            font-size: 0.74rem;
            color: var(--text-muted);
            line-height: 1.35;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 🚨 直近・重要なお知らせ（1行超コンパクト化） */
        .urgent-card-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .urgent-item {
            background: #ffffff;
            border: 1px solid var(--border);
            border-left: 4px solid #dc2626;
            border-radius: 6px;
            padding: 6px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: inherit;
            transition: all 0.12s;
            box-shadow: var(--shadow-sm);
        }
        .urgent-item:hover {
            transform: translateX(3px);
            border-color: #cbd5e1;
            border-left-color: #dc2626;
        }
        .urgent-title-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            overflow: hidden;
            white-space: nowrap;
        }
        .urgent-item-title {
            font-weight: bold;
            font-size: 0.86rem;
            color: #1e293b;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .badge {
            font-size: 0.68rem;
            padding: 1px 5px;
            border-radius: 3px;
            font-weight: bold;
            white-space: nowrap;
        }
        .badge-urgent { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .badge-pinned { background: #334155; color: #fff; }
        .badge-unread { background: #f3e8ff; color: #7c3aed; border: 1px solid #c4b5fd; }
        .urgent-meta {
            font-size: 0.74rem;
            color: var(--text-muted);
            white-space: nowrap;
            margin-left: 8px;
        }

        /* 📱 スマホ・極小解像度対応 */
        @media (max-width: 820px) {
            .menu-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 540px) {
            .menu-grid { grid-template-columns: 1fr; }
            .status-strip { flex-direction: column; align-items: flex-start; }
            .portal-search-strip { flex-direction: column; align-items: stretch; }
            .portal-search-form { max-width: 100%; }
            .header-title span { display: none; }
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
            max-width: 460px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        .line-modal-header {
            background: #06c755;
            color: #ffffff;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .line-modal-header h3 { font-size: 0.95rem; font-weight: bold; margin: 0; }
        .line-modal-close {
            background: none; border: none; color: #fff; font-size: 1.4rem; line-height: 1; cursor: pointer;
        }
        .line-modal-body { padding: 14px; overflow-y: auto; font-size: 0.85rem; }
        .line-step-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .line-step-num {
            background: #06c755;
            color: #fff;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: bold;
            margin-right: 5px;
        }
        .line-step-title { font-weight: bold; color: #1e293b; font-size: 0.82rem; }
        .link-code-digit {
            font-size: 1.8rem;
            font-weight: 900;
            letter-spacing: 8px;
            color: #065f46;
            background: #ecfdf5;
            border: 2px dashed #06c755;
            border-radius: 6px;
            padding: 6px 10px;
            text-align: center;
            margin: 8px 0;
        }
        .btn-copy-code {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: bold;
            cursor: pointer;
        }
        .line-waiting-box {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.76rem;
            color: #059669;
            background: #f0fdf4;
            padding: 6px 10px;
            border-radius: 6px;
            margin-top: 6px;
            border: 1px solid #bbf7d0;
        }
        .line-spinner {
            width: 14px;
            height: 14px;
            border: 2px solid #86efac;
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
            <div class="header-title-box">
                <span class="header-icon">📜</span>
                <div class="header-title">
                    <h1>院内かわら版</h1>
                    <span>医療法人小野会 ポータル</span>
                </div>
            </div>
            <div class="header-right">
                <a href="login.php?switch_user=1" class="user-info" title="クリックしてユーザーを切り替え">
                    👤 <b><?= htmlspecialchars($login_user['staff_name']) ?></b>
                    <span class="user-tag"><?= htmlspecialchars($login_user['role'] ?? '職員') ?></span>
                    <span style="font-size:0.68rem; opacity:0.85;">切替</span>
                </a>
                <button type="button" class="btn-line-header <?= $has_line_id ? 'is-linked' : 'is-unlinked' ?>" onclick="openLineLinkModal()" title="LINE連携設定">
                    <?= $has_line_id ? '🟢 LINE連携済' : '📱 LINE未登録' ?>
                </button>
                <a href="/index.php" class="btn-portal">🏠 総合ポータル</a>
            </div>
        </div>
    </header>

    <main>
        <?php if ($msg === 'saved'): ?>
            <div class="alert-msg">✅ 記事を保存・更新しました。</div>
        <?php elseif ($msg === 'deleted'): ?>
            <div class="alert-msg">🗑️ 記事を削除しました。</div>
        <?php endif; ?>

        <!-- 🛡️ BCP安否確認バナー（災害・訓練時で未報告の場合のみ） -->
        <?php if ($safety_mode !== 'normal' && !$my_safety_reported_today): ?>
            <div class="bcp-banner <?= $safety_mode === 'disaster' ? 'disaster' : 'drill' ?>">
                <div>
                    <?= $safety_mode === 'disaster' ? '🚨 【災害時緊急モード】安否確認・生存点呼が未報告です' : '🛡️ 【安否確認訓練】本日の生存チェックが未報告です' ?>
                </div>
                <a href="safety_contacts.php" style="background:#dc2626; color:#fff; font-size:0.76rem; font-weight:bold; padding:3px 10px; border-radius:4px; text-decoration:none;">
                    1クリック安否報告 →
                </a>
            </div>
        <?php endif; ?>

        <!-- 🌟 スマート・ステータスバー（薄型1行） -->
        <div class="status-strip">
            <div class="status-strip-left">
                <span>👤 <strong><?= htmlspecialchars($login_user['staff_name']) ?></strong> さん</span>
                <span class="status-divider">|</span>
                <span>📅 <strong><?= $today_display ?></strong></span>
                <span class="status-divider">|</span>
                <span style="color:#64748b;">掲載中: <strong><?= $total_active_posts ?></strong> 件</span>
            </div>
            <div class="status-strip-right">
                <?php if ($unread_count > 0): ?>
                    <a href="kawara_list.php" class="pill-unread" title="未読お知らせを確認する">
                        📢 <strong>未読 <?= $unread_count ?> 件</strong> を確認 →
                    </a>
                <?php else: ?>
                    <span class="pill-all-read">✓ すべて確認済</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 🔍 クイックキーワード検索バー（メニュー化） -->
        <div class="portal-search-strip">
            <form method="GET" action="kawara_list.php" class="portal-search-form">
                <div class="portal-search-input-wrap">
                    <span class="portal-search-icon">🔍</span>
                    <input type="text" name="q" placeholder="キーワードでお知らせ・過去履歴・予定を検索..." class="portal-search-input">
                </div>
                <button type="submit" class="btn-portal-search-submit">検索</button>
            </form>
            <div class="portal-quick-tags">
                <span class="portal-tag-label">クイック:</span>
                <a href="kawara_list.php?date_filter=today" class="portal-tag-link">📅 今日の予定</a>
                <a href="kawara_list.php?date_filter=plus7" class="portal-tag-link">⏰ 直近+7日</a>
                <a href="kawara_list.php?date_filter=past" class="portal-tag-link">📁 過去ログ</a>
                <a href="kawara_list.php?date_filter=all_history" class="portal-tag-link" style="color:#0284c7; border-color:#bae6fd; background:#f0f9ff;">🌐 全履歴</a>
            </div>
        </div>

        <!-- 📋 メインメニュー見出し -->
        <div class="section-header">
            <span>📋 メインメニュー</span>
        </div>

        <!-- 🚀 メインメニューグリッド（3列コンパクト配置） -->
        <div class="menu-grid">
            <!-- 1. かわら版 一覧 -->
            <a href="kawara_list.php" class="menu-card">
                <div class="menu-icon-box icon-list">📜</div>
                <div class="menu-info">
                    <div class="menu-title">
                        <span>かわら版 一覧</span>
                        <span class="menu-arrow">→</span>
                    </div>
                    <div class="menu-desc">病院お知らせ・行事予定・既読確認</div>
                </div>
            </a>

            <!-- 2. 新規投稿作成 -->
            <a href="create_post.php" class="menu-card">
                <div class="menu-icon-box icon-create">✏️</div>
                <div class="menu-info">
                    <div class="menu-title">
                        <span>新規投稿・予定作成</span>
                        <span class="menu-arrow">→</span>
                    </div>
                    <div class="menu-desc">お知らせ・日程・特定部署への告知</div>
                </div>
            </a>

            <!-- 3. 過去ログ・全履歴検索 -->
            <a href="kawara_list.php?date_filter=all_history" class="menu-card">
                <div class="menu-icon-box icon-search">🔍</div>
                <div class="menu-info">
                    <div class="menu-title">
                        <span>過去ログ・全履歴検索</span>
                        <span class="menu-arrow">→</span>
                    </div>
                    <div class="menu-desc">キーワード検索・過去の通知や全履歴の閲覧</div>
                </div>
            </a>

            <!-- 3. 事務長ダッシュボード -->
            <?php if ($is_admin || $is_jimucho): ?>
                <a href="jimucho_dashboard.php" class="menu-card">
                    <div class="menu-icon-box icon-jimucho">👔</div>
                    <div class="menu-info">
                        <div class="menu-title">
                            <span>事務長モード</span>
                            <span class="menu-arrow">→</span>
                        </div>
                        <div class="menu-desc">医師公休・出勤・Google同期・既読集計</div>
                    </div>
                </a>
            <?php endif; ?>

            <!-- 4. 連絡網・安否確認 -->
            <a href="safety_contacts.php" class="menu-card">
                <div class="menu-icon-box icon-safety">🛡️</div>
                <div class="menu-info">
                    <div class="menu-title">
                        <span>BCP連絡網・安否点呼</span>
                        <span class="menu-arrow">→</span>
                    </div>
                    <div class="menu-desc">災害時安否点呼・緊急連絡網・LINE点呼</div>
                </div>
            </a>

            <!-- 5. システムマスタ管理（管理者のみ） -->
            <?php if ($is_admin): ?>
                <a href="master_mente.php" class="menu-card">
                    <div class="menu-icon-box icon-mente">⚙️</div>
                    <div class="menu-info">
                        <div class="menu-title">
                            <span>マスタ管理</span>
                            <span class="menu-arrow">→</span>
                        </div>
                        <div class="menu-desc">職員マスタ・カテゴリ・カレンダー連携</div>
                    </div>
                </a>
            <?php endif; ?>

            <!-- 6. かんたん使い方ガイド -->
            <a href="help.php" class="menu-card">
                <div class="menu-icon-box icon-help">❓</div>
                <div class="menu-info">
                    <div class="menu-title">
                        <span>使い方ガイド</span>
                        <span class="menu-arrow">→</span>
                    </div>
                    <div class="menu-desc">既読操作・LINE通知・投稿マニュアル</div>
                </div>
            </a>

            <!-- 7. 院内総合ポータル -->
            <a href="/index.php" class="menu-card">
                <div class="menu-icon-box icon-portal">🏠</div>
                <div class="menu-info">
                    <div class="menu-title">
                        <span>院内総合ポータル</span>
                        <span class="menu-arrow">→</span>
                    </div>
                    <div class="menu-desc">カルテ・予定表・ヒヤリハット等トップへ</div>
                </div>
            </a>
        </div>

        <!-- ⚡ 直近・重要なお知らせ（最新3件） -->
        <?php if (!empty($urgent_posts)): ?>
            <div class="section-header">
                <span>⚡ 直近・重要なお知らせ</span>
                <a href="kawara_list.php" style="font-size:0.76rem; font-weight:normal; color:var(--primary); text-decoration:none;">すべて見る →</a>
            </div>

            <div class="urgent-card-list">
                <?php foreach ($urgent_posts as $up): ?>
                    <a href="view_post.php?id=<?= $up['post_id'] ?>" class="urgent-item">
                        <div class="urgent-title-wrap">
                            <?php if ($up['category_code'] === 'urgent'): ?>
                                <span class="badge badge-urgent">🚨 緊急</span>
                            <?php elseif (!empty($up['is_pinned'])): ?>
                                <span class="badge badge-pinned">📌 固定</span>
                            <?php endif; ?>

                            <?php if (!$up['is_my_read']): ?>
                                <span class="badge badge-unread">未読</span>
                            <?php endif; ?>

                            <span class="urgent-item-title"><?= htmlspecialchars($up['title']) ?></span>
                        </div>
                        <div class="urgent-meta">
                            <?= date('n/j H:i', strtotime($up['created_at'])) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- 📱 LINE公式アカウント連携モーダル -->
    <div id="lineLinkModal" class="line-modal-overlay" onclick="if(event.target===this) closeLineLinkModal()">
        <div class="line-modal-card">
            <div class="line-modal-header">
                <h3>📱 公式LINE 連携設定</h3>
                <button type="button" class="line-modal-close" onclick="closeLineLinkModal()">&times;</button>
            </div>
            <div class="line-modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; background:#f1f5f9; padding:6px 10px; border-radius:6px; margin-bottom:10px;">
                    <div style="font-size:0.82rem; font-weight:bold; color:#1e293b;">
                        👤 <?= htmlspecialchars($login_user['staff_name']) ?> 様 (<?= htmlspecialchars($login_user['role']) ?>)
                    </div>
                    <div id="modalLineBadge" style="font-size:0.72rem; font-weight:bold; padding:2px 6px; border-radius:10px;">
                        確認中...
                    </div>
                </div>

                <div id="modalUnlinkedView">
                    <div class="line-step-box">
                        <div style="display:flex; align-items:center; margin-bottom:6px;">
                            <span class="line-step-num">Step 1</span>
                            <span class="line-step-title">公式LINEを友だち追加</span>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <div style="text-align:center;">
                                <img id="lineQrImg" src="https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=https%3A%2F%2Fline.me%2FR%2Fti%2Fp%2F%40tmw3446q" alt="LINE友だち追加QR" style="width:85px; height:85px; border:1px solid #cbd5e1; border-radius:4px; padding:2px; background:#fff;">
                                <div style="font-size:0.62rem; color:#64748b; margin-top:2px;">QRスキャン</div>
                            </div>
                            <div style="flex:1; min-width:160px;">
                                <div style="font-size:0.76rem; color:#334155; margin-bottom:4px;">
                                    スマホカメラでQR読取、または友だち追加を開く：
                                </div>
                                <a id="btnLineAddFriend" href="https://line.me/R/ti/p/@tmw3446q" target="_blank" style="display:inline-flex; align-items:center; gap:4px; background:#06c755; color:#fff; padding:4px 10px; border-radius:4px; font-size:0.74rem; font-weight:bold; text-decoration:none;">
                                    💬 LINEで開く
                                </a>
                                <div style="font-size:0.68rem; color:#64748b; margin-top:3px;">
                                    ID: <span id="modalBotId" style="font-weight:bold; color:#0f172a;">@tmw3446q</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="line-step-box">
                        <div style="display:flex; align-items:center; margin-bottom:4px;">
                            <span class="line-step-num">Step 2</span>
                            <span class="line-step-title">トーク画面でこのコードを送信</span>
                        </div>
                        <div style="font-size:0.74rem; color:#475569;">
                            公式LINEのトークに、下記の【数字4桁】をそのまま送信：
                        </div>
                        <div class="link-code-digit" id="lineLinkCode">----</div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <button type="button" class="btn-copy-code" onclick="copyLinkCode()">📋 コードをコピー</button>
                            <div style="font-size:0.72rem; color:#64748b;">
                                有効期限: <span id="lineCodeCountdown" style="font-weight:bold; color:#d97706;">--:--</span>
                                <button type="button" onclick="generateNewLinkCode()" style="background:none; border:none; color:#0284c7; cursor:pointer; font-size:0.7rem; text-decoration:underline; margin-left:3px;">再発行</button>
                            </div>
                        </div>
                    </div>

                    <div class="line-waiting-box">
                        <div class="line-spinner"></div>
                        <div>LINEでコード送信待ち… (受信すると自動完了します)</div>
                    </div>
                </div>

                <div id="modalLinkedView" style="display:none; text-align:center; padding:12px 6px;">
                    <div style="font-size:2.4rem; margin-bottom:6px;">🟢</div>
                    <h4 style="color:#059669; font-weight:800; font-size:1rem; margin-bottom:4px;">公式LINEと連携中です</h4>
                    <p style="font-size:0.78rem; color:#475569; margin-bottom:12px;">
                        有事のBCP生存点呼や、重要アナウンスがあなたのLINEへ届きます。
                    </p>
                    <button type="button" onclick="unlinkLine()" style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; padding:5px 12px; border-radius:4px; font-size:0.76rem; font-weight:bold; cursor:pointer;">
                        連携を解除する
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let pollTimer = null;
        let expireTimer = null;
        let expiresAtTs = 0;

        function openLineLinkModal() {
            document.getElementById('lineLinkModal').classList.add('active');
            checkCurrentLineStatus();
        }

        function closeLineLinkModal() {
            document.getElementById('lineLinkModal').classList.remove('active');
            if (pollTimer) clearInterval(pollTimer);
            if (expireTimer) clearInterval(expireTimer);
        }

        async function checkCurrentLineStatus() {
            try {
                const res = await fetch('api/line_link_api.php?action=status');
                const data = await res.json();
                const badge = document.getElementById('modalLineBadge');
                
                if (data.is_linked) {
                    badge.textContent = '🟢 連携済み';
                    badge.style.background = '#e8f9ee';
                    badge.style.color = '#06c755';
                    document.getElementById('modalUnlinkedView').style.display = 'none';
                    document.getElementById('modalLinkedView').style.display = 'block';
                } else {
                    badge.textContent = '⚪ 未連携';
                    badge.style.background = '#fef3c7';
                    badge.style.color = '#b45309';
                    document.getElementById('modalUnlinkedView').style.display = 'block';
                    document.getElementById('modalLinkedView').style.display = 'none';
                    generateNewLinkCode();
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function generateNewLinkCode() {
            try {
                const res = await fetch('api/line_link_api.php?action=generate_code');
                const data = await res.json();
                if (data.success) {
                    document.getElementById('lineLinkCode').textContent = data.code;
                    expiresAtTs = Date.now() + (data.expires_in * 1000);
                    startCountdown();
                    startPolling();
                }
            } catch (err) {
                console.error(err);
            }
        }

        function startCountdown() {
            if (expireTimer) clearInterval(expireTimer);
            const cdEl = document.getElementById('lineCodeCountdown');
            expireTimer = setInterval(() => {
                const diff = Math.max(0, Math.floor((expiresAtTs - Date.now()) / 1000));
                const m = Math.floor(diff / 60);
                const s = diff % 60;
                cdEl.textContent = `${m}:${s < 10 ? '0' : ''}${s}`;
                if (diff <= 0) {
                    clearInterval(expireTimer);
                    document.getElementById('lineLinkCode').textContent = '期限切';
                }
            }, 1000);
        }

        function startPolling() {
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(async () => {
                try {
                    const res = await fetch('api/line_link_api.php?action=status');
                    const data = await res.json();
                    if (data.is_linked) {
                        clearInterval(pollTimer);
                        if (expireTimer) clearInterval(expireTimer);
                        checkCurrentLineStatus();
                        location.reload();
                    }
                } catch (e) {}
            }, 3000);
        }

        function copyLinkCode() {
            const code = document.getElementById('lineLinkCode').textContent.trim();
            navigator.clipboard.writeText(code).then(() => {
                alert('連携コード ' + code + ' をコピーしました！LINEトーク画面に貼り付けて送信してください。');
            });
        }

        async function unlinkLine() {
            if (!confirm('公式LINEとの連携を解除しますか？\n（解除すると緊急連絡や安否点呼がスマホLINEに届かなくなります）')) return;
            try {
                const res = await fetch('api/line_link_api.php?action=unlink', { method: 'POST' });
                const data = await res.json();
                if (data.success) {
                    alert('連携を解除しました。');
                    location.reload();
                }
            } catch (err) {
                alert('解除に失敗しました');
            }
        }
    </script>
</body>
</html>