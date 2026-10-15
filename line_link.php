<?php
/**
 * ============================================================
 * ファイル名: line_link.php
 * システム名: 院内かわら版（医療法人小野会）
 * 概要: 公式LINE連携・アカウント設定画面（独立統一ページ）
 * ============================================================
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
$can_manage_others = ($is_admin || $is_jimucho);

// 戻り先URLのサニタイズ（かわら版内の相対パスのみ許可）
$return_url = $_GET['return_url'] ?? 'kawara_list.php';
if (empty($return_url) || preg_match('/^https?:\/\//i', $return_url) || strpos($return_url, '//') === 0) {
    $return_url = 'kawara_list.php';
}

// 対象スタッフIDの決定
$target_staff_id = $current_staff_id;
$is_other_staff = false;

if ($can_manage_others && isset($_GET['staff_id']) && (int)$_GET['staff_id'] > 0) {
    $req_sid = (int)$_GET['staff_id'];
    if ($req_sid !== $current_staff_id) {
        $target_staff_id = $req_sid;
        $is_other_staff = true;
    }
}

// 対象スタッフ情報の取得
$stmt = $pdo->prepare("SELECT staff_id, staff_name, role, line_user_id FROM staff WHERE staff_id = :sid AND (is_deleted IS NOT TRUE)");
$stmt->execute([':sid' => $target_staff_id]);
$target_staff = $stmt->fetch();

if (!$target_staff) {
    die("指定された職員情報が見つかりませんでした。<br><a href='index.php'>ポータルへ戻る</a>");
}

$target_has_line = !empty(trim($target_staff['line_user_id'] ?? ''));
$bot_id = defined('LINE_BOT_BASIC_ID') ? LINE_BOT_BASIC_ID : '@tmw3446q';
$bot_add_url = 'https://line.me/R/ti/p/' . urlencode($bot_id);
$qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($bot_add_url);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>公式LINE連携設定 | 医療法人小野会 院内かわら版</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@500;700&family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #003d6b;
            --primary-light: #eff6ff;
            --line-green: #06c755;
            --line-dark: #05a045;
            --line-bg: #e8f9ee;
            --border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --card-bg: #ffffff;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 14px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.12);
            --radius-md: 10px;
            --radius-lg: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: var(--text-main);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* 統一ヘッダー */
        header {
            background: linear-gradient(135deg, #003d6b 0%, #005a9c 100%);
            color: #ffffff;
            padding: 0.75rem 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }
        .header-inner {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-back {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.84rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-2px);
        }
        .header-title {
            font-size: 1.05rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .user-tag {
            font-size: 0.78rem;
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 12px;
        }

        /* メインコンテンツ */
        main {
            max-width: 720px;
            width: 100%;
            margin: 1.5rem auto 3rem;
            padding: 0 1rem;
            flex: 1;
        }

        /* 対象職員バー */
        .staff-strip {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .staff-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .staff-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
        }
        .staff-name {
            font-size: 1.15rem;
            font-weight: 900;
            color: #0f172a;
        }
        .staff-dept {
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .status-badge {
            font-size: 0.82rem;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-badge.linked {
            background: var(--line-bg);
            color: var(--line-dark);
            border: 1px solid #bbf7d0;
        }
        .status-badge.unlinked {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        /* メインカード */
        .link-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-md);
            margin-bottom: 1.25rem;
        }

        /* ステップ案内 */
        .step-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: 18px;
            margin-bottom: 18px;
            position: relative;
        }
        .step-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .step-badge {
            background: var(--line-green);
            color: #ffffff;
            font-size: 0.76rem;
            font-weight: 800;
            padding: 2px 9px;
            border-radius: 12px;
        }
        .step-title {
            font-size: 0.98rem;
            font-weight: 800;
            color: #0f172a;
        }

        /* Step 1: QR & リンク */
        .qr-section {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .qr-frame {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px;
            box-shadow: var(--shadow-sm);
            text-align: center;
        }
        .qr-frame img {
            width: 140px;
            height: 140px;
            display: block;
        }
        .qr-subtext {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .qr-desc {
            flex: 1;
            min-width: 220px;
        }
        .qr-desc p {
            font-size: 0.88rem;
            color: #334155;
            margin-bottom: 12px;
            line-height: 1.5;
        }
        .btn-line-add {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--line-green);
            color: #ffffff;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.88rem;
            box-shadow: 0 2px 8px rgba(6, 199, 85, 0.35);
            transition: all 0.15s;
        }
        .btn-line-add:hover {
            background: var(--line-dark);
            transform: translateY(-1px);
        }

        /* Step 2: 4桁コード */
        .code-display-wrap {
            text-align: center;
            background: #ffffff;
            border: 2px dashed var(--line-green);
            border-radius: 12px;
            padding: 16px 20px;
            margin: 12px 0 16px;
        }
        .code-display {
            font-family: 'Fira Code', monospace;
            font-size: 2.8rem;
            font-weight: 900;
            letter-spacing: 12px;
            color: #065f46;
            margin: 4px 0;
            line-height: 1.1;
        }
        .code-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn-copy {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .btn-copy:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .countdown-wrap {
            font-size: 0.82rem;
            color: var(--text-muted);
        }
        .countdown-time {
            font-family: 'Fira Code', monospace;
            font-weight: bold;
            color: #d97706;
            font-size: 0.95rem;
        }

        /* 待機ポーリングインジケータ */
        .waiting-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 12px;
            color: #059669;
            font-size: 0.88rem;
            font-weight: bold;
            animation: pulse-bg 2s infinite;
        }
        .spinner {
            width: 18px;
            height: 18px;
            border: 2.5px solid #86efac;
            border-top-color: #059669;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pulse-bg { 0%, 100% { background: #f0fdf4; } 50% { background: #dcfce7; } }

        /* 連携完了アニメーションカード */
        .success-box {
            display: none;
            text-align: center;
            padding: 30px 20px;
        }
        .success-icon {
            font-size: 3.5rem;
            margin-bottom: 12px;
            animation: pop-in 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .success-title {
            font-size: 1.4rem;
            font-weight: 900;
            color: #059669;
            margin-bottom: 8px;
        }
        .redirect-notice {
            font-size: 0.9rem;
            color: #475569;
            margin-top: 14px;
        }
        @keyframes pop-in {
            0% { transform: scale(0.3); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* 連携中ビュー */
        .linked-view {
            text-align: center;
            padding: 24px 16px;
        }
        .linked-icon {
            font-size: 3.2rem;
            margin-bottom: 10px;
        }
        .linked-title {
            font-size: 1.3rem;
            font-weight: 900;
            color: #059669;
            margin-bottom: 10px;
        }
        .linked-desc {
            font-size: 0.88rem;
            color: #475569;
            max-width: 520px;
            margin: 0 auto 20px;
            line-height: 1.6;
        }
        .linked-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-action {
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 0.84rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.15s;
            border: 1px solid transparent;
        }
        .btn-relink {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #bfdbfe;
        }
        .btn-relink:hover {
            background: #dbeafe;
        }
        .btn-unlink {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
        }
        .btn-unlink:hover {
            background: #fecaca;
        }

        /* 管理者手動欄 */
        .admin-section {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
            text-align: left;
        }
        .admin-section summary {
            cursor: pointer;
            font-size: 0.78rem;
            font-weight: bold;
            color: #64748b;
        }

        @media (max-width: 600px) {
            .code-display { font-size: 2.2rem; letter-spacing: 8px; }
            .qr-section { justify-content: center; text-align: center; }
            .header-inner { flex-direction: column; gap: 8px; align-items: flex-start; }
            .staff-strip { flex-direction: column; align-items: flex-start; gap: 10px; }
        }
    </style>
</head>
<body>

    <header>
        <div class="header-inner">
            <div class="header-left">
                <a href="<?= htmlspecialchars($return_url) ?>" class="btn-back">
                    ← かわら版に戻る
                </a>
                <div class="header-title">
                    <span>📱</span>
                    <span>公式LINE 連携設定</span>
                </div>
            </div>
            <div style="font-size:0.82rem; display:flex; align-items:center; gap:10px;">
                <a href="line_guide.php" class="btn-back" style="background:#06c755; border-color:#05a045;" target="_blank">
                    📖 登録手順書
                </a>
                <span>ログイン中: <b><?= htmlspecialchars($login_user['staff_name']) ?></b></span>
                <span class="user-tag"><?= htmlspecialchars($login_user['role'] ?? '職員') ?></span>
            </div>
        </div>
    </header>

    <main>
        <!-- 対象職員表示バー -->
        <div class="staff-strip">
            <div class="staff-info">
                <div class="staff-avatar">👤</div>
                <div>
                    <div class="staff-name">
                        <?= htmlspecialchars($target_staff['staff_name']) ?> 様
                        <?php if ($is_other_staff): ?>
                            <span style="font-size:0.75rem; background:#eff6ff; color:#0284c7; padding:2px 8px; border-radius:4px; margin-left:6px; border:1px solid #bfdbfe;">
                                管理者代理設定
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="staff-dept">
                        区分・役職: <?= htmlspecialchars($target_staff['role'] ?? '職員') ?>
                    </div>
                </div>
            </div>
            <div>
                <span id="headerStatusBadge" class="status-badge <?= $target_has_line ? 'linked' : 'unlinked' ?>">
                    <?= $target_has_line ? '🟢 連携中' : '⚪ 未連携' ?>
                </span>
            </div>
        </div>

        <!-- メイン設定カード -->
        <div class="link-card">

            <!-- A. 未連携ビュー（QR & 4桁コード発行） -->
            <div id="unlinkedView" style="<?= $target_has_line ? 'display:none;' : '' ?>">
                
                <!-- Step 1: 友だち追加 -->
                <div class="step-block">
                    <div class="step-header">
                        <span class="step-badge">Step 1</span>
                        <span class="step-title">医療法人小野会 公式LINEを友だち追加</span>
                    </div>
                    <div class="qr-section">
                        <div class="qr-frame">
                            <img src="<?= htmlspecialchars($qr_code_url) ?>" alt="LINE友だち追加QRコード">
                            <div class="qr-subtext">スマホカメラで読取</div>
                        </div>
                        <div class="qr-desc">
                            <p>
                                スマートフォンのLINEアプリまたはカメラでQRコードを読み取り、<strong>「おの肛門科」公式アカウント</strong>を友だち追加してください。
                            </p>
                            <a href="<?= htmlspecialchars($bot_add_url) ?>" target="_blank" rel="noopener" class="btn-line-add">
                                💬 スマホのLINEで直接開く →
                            </a>
                            <div style="font-size:0.75rem; color:#64748b; margin-top:8px;">
                                公式アカウントID: <b><?= htmlspecialchars($bot_id) ?></b>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: 4桁コード送信 -->
                <div class="step-block">
                    <div class="step-header">
                        <span class="step-badge">Step 2</span>
                        <span class="step-title">トーク画面でこの数字（4桁）を送信</span>
                    </div>
                    <p style="font-size:0.84rem; color:#475569;">
                        公式LINEのトーク画面を開き、下記の【数字4桁】をそのままメッセージとして送信してください。
                    </p>

                    <div class="code-display-wrap">
                        <div class="code-display" id="linkCodeDisplay">----</div>
                        <div style="font-size:0.76rem; color:#059669; font-weight:bold;">
                            ▲ この4桁の数字をLINEに送信してください
                        </div>
                    </div>

                    <div class="code-meta-row">
                        <button type="button" class="btn-copy" onclick="copyLinkCode()">
                            📋 4桁コードをコピー
                        </button>
                        <div class="countdown-wrap">
                            有効期限: <span class="countdown-time" id="countdownDisplay">--:--</span>
                            <button type="button" onclick="generateNewCode()" style="background:none; border:none; color:#0284c7; cursor:pointer; font-size:0.8rem; text-decoration:underline; margin-left:6px;">
                                コード再発行
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 待機ポーリングインジケータ -->
                <div class="waiting-bar" id="waitingBar">
                    <div class="spinner"></div>
                    <div>LINEでコード送信待ち…（受信すると自動的に完了します）</div>
                </div>

            </div>

            <!-- B. 連携完了祝賀ビュー（自動リダイレクト） -->
            <div id="successView" class="success-box">
                <div class="success-icon">🎉</div>
                <h3 class="success-title">公式LINEとの連携が完了しました！</h3>
                <p style="font-size:0.92rem; color:#334155; margin-top:6px;">
                    これより、有事のBCP生存点呼や緊急アナウンスがあなたのLINEへ届きます。
                </p>
                <div class="redirect-notice">
                    <span id="redirectCountdown" style="font-weight:bold; color:var(--primary); font-size:1.1rem;">3</span> 秒後に元の画面へ戻ります…
                </div>
                <div style="margin-top:16px;">
                    <a href="<?= htmlspecialchars($return_url) ?>" class="btn-action btn-relink" style="display:inline-block; text-decoration:none;">
                        今すぐ元の画面へ戻る →
                    </a>
                </div>
            </div>

            <!-- C. 連携済みビュー -->
            <div id="linkedView" class="linked-view" style="<?= $target_has_line ? '' : 'display:none;' ?>">
                <div class="linked-icon">🟢</div>
                <h3 class="linked-title">公式LINEと正常に連携中です</h3>
                <p class="linked-desc">
                    災害発生時のBCP生存点呼通知や、病院からの重要なお知らせ・既読メモがご自身のスマートフォンLINEへ確実に届く状態になっています。
                </p>
                <div class="linked-actions">
                    <a href="<?= htmlspecialchars($return_url) ?>" class="btn-action" style="background:#06c755; color:#ffffff; text-decoration:none;">
                        ✓ 確認完了（かわら版へ戻る）
                    </a>
                    <button type="button" class="btn-action btn-relink" onclick="startRelink()">
                        🔄 別のLINEで再連携（コード発行）
                    </button>
                    <button type="button" class="btn-action btn-unlink" onclick="unlinkLine()">
                        連携を解除する
                    </button>
                </div>
            </div>

            <!-- D. 管理者向け直接登録（任意） -->
            <?php if ($can_manage_others): ?>
                <div class="admin-section">
                    <details>
                        <summary>⚙️ 管理者専用オプション: LINE User ID 直接登録・手動解除</summary>
                        <div style="margin-top:12px; font-size:0.8rem; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                            <label style="display:block; font-weight:bold; margin-bottom:4px;">
                                LINE User ID (Uから始まる33桁):
                            </label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="manualLineIdInput" value="<?= htmlspecialchars($target_staff['line_user_id'] ?? '') ?>" placeholder="例: Ue48308ee44459c8298e48fd8c53a0c3b" style="flex:1; padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px; font-family:monospace; font-size:0.8rem;">
                                <button type="button" onclick="submitManualLineId()" style="background:#005a9c; color:#fff; border:none; padding:6px 12px; border-radius:4px; font-weight:bold; cursor:pointer;">
                                    保存
                                </button>
                            </div>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        const targetStaffId = <?= (int)$target_staff_id ?>;
        const returnUrl = <?= json_encode($return_url) ?>;
        let pollTimer = null;
        let countdownTimer = null;
        let remainingSeconds = 0;

        // 初期化
        document.addEventListener('DOMContentLoaded', () => {
            const isCurrentlyLinked = <?= $target_has_line ? 'true' : 'false' ?>;
            if (!isCurrentlyLinked) {
                generateNewCode();
            }
        });

        // 4桁コード発行
        async function generateNewCode() {
            try {
                document.getElementById('linkCodeDisplay').textContent = '----';
                const res = await fetch(`api/line_link_status.php?action=generate_code&staff_id=${targetStaffId}`, { cache: 'no-store' });
                const data = await res.json();
                if (data.success && data.link_code) {
                    document.getElementById('linkCodeDisplay').textContent = data.link_code;
                    remainingSeconds = data.remaining_sec || 1200;
                    startCountdown();
                    startPolling();
                } else {
                    alert('コードの発行に失敗しました: ' + (data.error || '不明なエラー'));
                }
            } catch (err) {
                console.error(err);
                alert('通信エラーが発生しました。ネットワークをご確認ください。');
            }
        }

        // 有効期限カウントダウン
        function startCountdown() {
            if (countdownTimer) clearInterval(countdownTimer);
            updateCountdownText();
            countdownTimer = setInterval(() => {
                remainingSeconds--;
                if (remainingSeconds <= 0) {
                    clearInterval(countdownTimer);
                    document.getElementById('countdownDisplay').textContent = '期限切れ';
                    document.getElementById('linkCodeDisplay').textContent = '期限切';
                } else {
                    updateCountdownText();
                }
            }, 1000);
        }

        function updateCountdownText() {
            const m = Math.floor(remainingSeconds / 60);
            const s = remainingSeconds % 60;
            document.getElementById('countdownDisplay').textContent = `${m}:${s < 10 ? '0' : ''}${s}`;
        }

        // LINE送信検知ポーリング（3秒ごと）
        function startPolling() {
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(async () => {
                try {
                    const res = await fetch(`api/line_link_status.php?action=status&staff_id=${targetStaffId}`, { cache: 'no-store' });
                    const data = await res.json();
                    if (data.success && data.is_linked) {
                        // 連携成功！
                        clearInterval(pollTimer);
                        if (countdownTimer) clearInterval(countdownTimer);
                        onLinkSuccess();
                    }
                } catch (e) {}
            }, 3000);
        }

        // 連携成功演出＆自動リダイレクト
        function onLinkSuccess() {
            document.getElementById('unlinkedView').style.display = 'none';
            document.getElementById('linkedView').style.display = 'none';
            document.getElementById('successView').style.display = 'block';

            const badge = document.getElementById('headerStatusBadge');
            badge.className = 'status-badge linked';
            badge.textContent = '🟢 連携中';

            let count = 3;
            const countEl = document.getElementById('redirectCountdown');
            const redirTimer = setInterval(() => {
                count--;
                if (countEl) countEl.textContent = count;
                if (count <= 0) {
                    clearInterval(redirTimer);
                    window.location.href = returnUrl;
                }
            }, 1000);
        }

        // コードコピー
        function copyLinkCode() {
            const code = document.getElementById('linkCodeDisplay').textContent.trim();
            if (!code || code === '----' || code === '期限切') {
                alert('有効なコードが表示されていません');
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(() => {
                    alert(`4桁コード【 ${code} 】をコピーしました！\nLINE公式アカウントのトーク画面に貼り付けて送信してください。`);
                });
            } else {
                alert(`コード: ${code}`);
            }
        }

        // 再連携開始
        function startRelink() {
            if (!confirm('新しいLINEアカウントで連携し直しますか？\n（新しい4桁コードを発行します）')) return;
            document.getElementById('linkedView').style.display = 'none';
            document.getElementById('unlinkedView').style.display = 'block';
            generateNewCode();
        }

        // 連携解除
        async function unlinkLine() {
            if (!confirm('公式LINEとの連携を解除しますか？\n（解除すると緊急連絡や安否点呼がスマホLINEに届かなくなります）')) return;
            try {
                const res = await fetch(`api/line_link_status.php?action=unlink&staff_id=${targetStaffId}`, { method: 'POST', cache: 'no-store' });
                const data = await res.json();
                if (data.success) {
                    alert('連携を解除しました。');
                    window.location.reload();
                } else {
                    alert('解除に失敗しました: ' + (data.error || ''));
                }
            } catch (err) {
                alert('通信エラーが発生しました。');
            }
        }

        // 管理者手動保存
        async function submitManualLineId() {
            const val = document.getElementById('manualLineIdInput').value.trim();
            const fd = new FormData();
            fd.append('action', 'manual_link');
            fd.append('staff_id', targetStaffId);
            fd.append('line_user_id', val);
            try {
                const res = await fetch('api/line_link_status.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert('保存しました。');
                    window.location.reload();
                } else {
                    alert('保存エラー: ' + (data.error || ''));
                }
            } catch (e) {
                alert('通信エラーが発生しました。');
            }
        }
    </script>
</body>
</html>
