<?php
/**
 * 院内かわら版 - ログイン画面
 * スマホ端末自動固定（Remember Device）＆ モバイル最適化
 */
require_once __DIR__ . '/includes/auth_helper.php';

// 1. DB接続設定
$host = 'localhost';
$dbname = 'kawara';
$user = 'postgres';
$password = 'postgres';

try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}

$is_switch_user = isset($_GET['switch_user']) && $_GET['switch_user'] === '1';

// 2. ログアウト処理（端末固定Cookieもクリア）
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    clearDeviceRememberCookie();
    header("Location: login.php");
    exit;
}

// 3. 端末自動固定チェック（switch_userでなければ自動ログインで直接アクセス）
$remembered_staff = verifyDeviceRememberCookie($pdo);
$redirect_param = $_GET['redirect'] ?? '';

if (!$is_switch_user) {
    // セッションまたは端末Cookieがあれば即座にリダイレクト（ログイン画面を完全スキップ！）
    if (!empty($_SESSION['staff_id'])) {
        $sid = (int)$_SESSION['staff_id'];
        $stmt_cur = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt_cur->execute([':id' => $sid]);
        $cur_staff = $stmt_cur->fetch();
        if ($cur_staff) {
            $default_dest = ($sid === 15 || !empty($cur_staff['is_admin'])) ? 'jimucho_dashboard.php' : 'index.php';
            header("Location: " . (!empty($redirect_param) ? $redirect_param : $default_dest));
            exit;
        }
    } elseif ($remembered_staff) {
        setupStaffSession($remembered_staff);
        issueDeviceRememberCookie($remembered_staff['staff_id'], 180);
        $default_dest = ((int)$remembered_staff['staff_id'] === 15 || !empty($remembered_staff['is_admin'])) ? 'jimucho_dashboard.php' : 'index.php';
        header("Location: " . (!empty($redirect_param) ? $redirect_param : $default_dest));
        exit;
    }
}

// 4. スタッフ選択時（POST送信時）のログイン処理
$error_msg = '';
$error_staff_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_id'])) {
    $selected_staff_id = (int)$_POST['staff_id'];
    $input_pin = trim($_POST['pin_code'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
    $stmt->execute([':id' => $selected_staff_id]);
    $staff = $stmt->fetch();

    if ($staff) {
        $required_pin = trim($staff['pin_code'] ?? '');

        // 暗証番号が設定されているスタッフ（山本太・医師など）の認証チェック
        if (!empty($required_pin)) {
            if ($input_pin !== $required_pin) {
                $error_msg = "⚠️ 暗証番号が正しくありません。（" . htmlspecialchars($staff['staff_name']) . " さん）";
                $error_staff_id = $selected_staff_id;
            }
        }

        if (empty($error_msg)) {
            // セッション確立
            setupStaffSession($staff);

            // 📱 このスマホ・ブラウザを180日間このスタッフとして自動固定！
            issueDeviceRememberCookie($staff['staff_id'], 180);

            $default_dest = ((int)$staff['staff_id'] === 15 || !empty($staff['is_admin'])) ? 'jimucho_dashboard.php' : 'index.php';
            $redirect_url = !empty($redirect_param) ? $redirect_param : $default_dest;
            header("Location: " . $redirect_url);
            exit;
        }
    } else {
        $error_msg = "選択されたスタッフが見つからないか、利用対象外です。";
    }
}

// 5. スタッフ一覧の取得 ＆ 最近使ったスタッフを取得
try {
    $stmt_all = $pdo->query("SELECT staff_id, staff_name, short_icon, role, kana, kana_row, line_user_id, pin_code, can_toggle_disaster FROM staff WHERE (is_deleted IS NOT TRUE) ORDER BY kana ASC");
    $staff_list = $stmt_all->fetchAll();

    // 最近使ったスタッフ
    $recent_staff_list = [];
    if (!empty($_SESSION['recent_staff_ids'])) {
        $ids = array_map('intval', $_SESSION['recent_staff_ids']);
        $in_ids = implode(',', $ids);
        $stmt_recent = $pdo->query("SELECT staff_id, staff_name, role, pin_code, can_toggle_disaster FROM staff WHERE staff_id IN ({$in_ids}) AND (is_deleted IS NOT TRUE)");
        $fetched = $stmt_recent->fetchAll();
        $order = array_flip($_SESSION['recent_staff_ids']);
        usort($fetched, function($a, $b) use ($order) {
            return ($order[$a['staff_id']] ?? 999) - ($order[$b['staff_id']] ?? 999);
        });
        $recent_staff_list = $fetched;
    } elseif ($remembered_staff) {
        $recent_staff_list = [$remembered_staff];
    }

    // 特別アカウント「管理者」の抽出
    $admin_staff = null;
    foreach ($staff_list as $st) {
        if ($st['staff_name'] === '管理者' || $st['staff_name'] === 'システム管理者') {
            $admin_staff = $st;
            break;
        }
    }
} catch (Exception $e) {
    $staff_list = [];
    $recent_staff_list = [];
    $admin_staff = null;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ログイン | 院内かわら版</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #004085;
            --bg-color: #f4f6f9;
            --border: #cbd5e1;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-color);
            color: #333;
            margin: 0;
            padding: 16px 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-card {
            background: #fff;
            width: 100%;
            max-width: 680px;
            padding: 24px 20px;
            border-radius: 14px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        h1 { font-size: 1.35rem; color: var(--primary); text-align: center; margin-top: 0; margin-bottom: 4px; font-weight: 900; }
        .subtitle { text-align: center; font-size: 0.82rem; color: #64748b; margin-bottom: 16px; }

        /* 📱 スマホ端末固定バナー（記憶中表示） */
        .remember-device-notice {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            font-size: 0.84rem;
        }
        
        .section-title { font-size: 0.84rem; font-weight: bold; color: #475569; margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
        
        /* 最近使ったスタッフ */
        .recent-grid { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 6px; margin-bottom: 16px; -webkit-overflow-scrolling: touch; }
        .recent-btn {
            background: #f0f7ff;
            border: 1.5px solid #93c5fd;
            padding: 9px 16px;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
            position: relative;
            flex-shrink: 0;
            min-height: 52px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .recent-btn:hover, .recent-btn:active { background: #dbeafe; border-color: var(--primary); }
        .recent-name { font-weight: bold; font-size: 0.92rem; color: #004085; }
        .recent-role { font-size: 0.7rem; color: #64748b; margin-top: 2px; }

        /* 50音フィルターバー */
        .filter-bar { display: flex; gap: 3px; justify-content: center; margin-bottom: 12px; flex-wrap: wrap; background: #e2e8f0; padding: 5px; border-radius: 8px; }
        .btn-filter { background: #fff; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 6px; font-size: 0.82rem; font-weight: bold; cursor: pointer; color: #475569; transition: all 0.15s; min-width: 32px; min-height: 34px; }
        .btn-filter.active { background: var(--primary); color: white; border-color: var(--primary); }

        /* スタッフグリッド（スマホで押しやすいサイズ） */
        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 8px;
            max-height: 340px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fafafa;
            -webkit-overflow-scrolling: touch;
        }
        .staff-btn {
            background: #fff;
            border: 1.5px solid #cbd5e1;
            padding: 10px 8px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            position: relative;
            min-height: 58px;
        }
        .staff-btn:hover, .staff-btn:active { background: #eff6ff; border-color: var(--primary); }
        .staff-name { font-weight: bold; font-size: 0.92rem; color: #1e293b; }
        .staff-role { font-size: 0.7rem; color: #64748b; background: #f1f5f9; padding: 1px 6px; border-radius: 10px; }
        
        .pin-badge {
            position: absolute;
            top: 4px;
            right: 5px;
            font-size: 0.75rem;
            line-height: 1;
        }

        .error-box { background: #fee2e2; color: #991b1b; border: 1.5px solid #f87171; padding: 10px 14px; border-radius: 8px; font-size: 0.88rem; margin-bottom: 14px; text-align: center; font-weight: bold; }

        /* 📱 テンキー暗証番号入力モーダル */
        .pin-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .pin-modal-overlay.active { display: flex; animation: fade-in 0.2s; }
        @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }

        .pin-card {
            background: #ffffff;
            width: 100%;
            max-width: 350px;
            border-radius: 16px;
            padding: 22px 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            text-align: center;
        }
        .pin-target-name { font-size: 1.15rem; font-weight: 900; color: #1e293b; }
        .pin-target-role { font-size: 0.75rem; color: #d97706; font-weight: bold; background: #fef3c7; display: inline-block; padding: 2px 8px; border-radius: 12px; margin-top: 3px; }
        .pin-instruction { font-size: 0.78rem; color: #64748b; margin-top: 6px; }

        .pin-display-wrap {
            margin: 14px 0;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pin-display-text { font-size: 1.6rem; letter-spacing: 6px; color: var(--primary); font-family: monospace; font-weight: bold; }
        .pin-placeholder { font-size: 0.8rem; color: #94a3b8; }

        .pin-keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .pin-key {
            height: 52px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .pin-key:active { transform: scale(0.95); background: #e2e8f0; }
        .pin-key.btn-clear { font-size: 0.95rem; color: #dc2626; background: #fef2f2; border-color: #fecaca; }
        .pin-key.btn-backspace { font-size: 1.2rem; color: #475569; background: #f8fafc; }

        .btn-pin-submit {
            width: 100%;
            height: 46px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(0,90,156,0.3);
        }
        .btn-pin-cancel {
            width: 100%;
            background: none;
            border: none;
            color: #64748b;
            font-size: 0.84rem;
            font-weight: bold;
            padding: 8px;
            margin-top: 6px;
            cursor: pointer;
        }

        /* 📱 モバイル画面最適化 (幅640px以下) */
        @media (max-width: 640px) {
            body { padding: 10px 8px; align-items: flex-start; }
            .login-card { padding: 18px 14px; border-radius: 12px; }
            h1 { font-size: 1.2rem; }
            .subtitle { font-size: 0.78rem; margin-bottom: 12px; }
            .staff-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 6px; max-height: 280px; }
            .staff-btn { min-height: 52px; padding: 8px 4px; }
            .staff-name { font-size: 0.86rem; }
            .btn-filter { padding: 5px 8px; font-size: 0.78rem; min-width: 28px; }
        }
    </style>
</head>
<body>

<div class="login-card">
    <h1>📜 院内かわら版</h1>
    <div class="subtitle">ご自身のお名前を選択してログインしてください</div>

    <?php if (!empty($error_msg)): ?>
        <div class="error-box"><?= $error_msg ?></div>
    <?php endif; ?>

    <!-- 📱 端末固定中の案内（switch_user時に表示） -->
    <?php if ($remembered_staff && $is_switch_user): ?>
        <div class="remember-device-notice">
            <div>
                📱 この端末は <b><?= htmlspecialchars($remembered_staff['staff_name']) ?> 様</b> として固定中
            </div>
            <a href="login.php" style="background:#0284c7; color:#fff; text-decoration:none; padding:4px 10px; border-radius:6px; font-size:0.78rem; font-weight:bold; white-space:nowrap;">
                そのまま進む →
            </a>
        </div>
    <?php endif; ?>

    <!-- ⏱️ 最近使ったスタッフ（一番上に優先配置！） -->
    <?php if (!empty($recent_staff_list)): ?>
        <div class="section-title">⏱️ 最近使ったスタッフ</div>
        <div class="recent-grid">
            <?php foreach ($recent_staff_list as $rst): 
                $has_pin = !empty(trim($rst['pin_code'] ?? ''));
            ?>
                <div class="recent-btn" onclick="handleClickStaff(<?= $rst['staff_id'] ?>, '<?= htmlspecialchars($rst['staff_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rst['role'], ENT_QUOTES) ?>', <?= $has_pin ? 'true' : 'false' ?>)">
                    <?php if ($has_pin): ?>
                        <span class="pin-badge" title="要暗証番号">🔒</span>
                    <?php endif; ?>
                    <div class="recent-name"><?= htmlspecialchars($rst['staff_name']) ?></div>
                    <div class="recent-role"><?= htmlspecialchars($rst['role']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section-title">🔍 50音から探す</div>
    <div class="filter-bar">
        <button type="button" class="btn-filter active" onclick="filterKana('all', this)">全</button>
        <button type="button" class="btn-filter" onclick="filterKana('あ', this)">あ</button>
        <button type="button" class="btn-filter" onclick="filterKana('か', this)">か</button>
        <button type="button" class="btn-filter" onclick="filterKana('さ', this)">さ</button>
        <button type="button" class="btn-filter" onclick="filterKana('た', this)">た</button>
        <button type="button" class="btn-filter" onclick="filterKana('な', this)">な</button>
        <button type="button" class="btn-filter" onclick="filterKana('は', this)">は</button>
        <button type="button" class="btn-filter" onclick="filterKana('ま', this)">ま</button>
        <button type="button" class="btn-filter" onclick="filterKana('や', this)">や</button>
        <button type="button" class="btn-filter" onclick="filterKana('ら', this)">ら</button>
        <button type="button" class="btn-filter" onclick="filterKana('わ', this)">わ</button>
    </div>

    <!-- ログインPOSTフォーム -->
    <form method="POST" id="loginForm">
        <input type="hidden" name="staff_id" id="selectedStaffId">
        <input type="hidden" name="pin_code" id="enteredPinCode">
        
        <div class="staff-grid">
            <?php foreach ($staff_list as $st): 
                // 管理者は一番下に移設したためグリッドからはスキップ
                if ($st['staff_name'] === '管理者' || $st['staff_name'] === 'システム管理者') continue;

                $kana_trim = trim($st['kana'] ?? '');
                $first_char = mb_substr($kana_trim, 0, 1);
                $has_pin = !empty(trim($st['pin_code'] ?? ''));
            ?>
                <div class="staff-btn" 
                     data-kana="<?= htmlspecialchars($kana_trim) ?>"
                     data-first-char="<?= htmlspecialchars($first_char) ?>"
                     onclick="handleClickStaff(<?= $st['staff_id'] ?>, '<?= htmlspecialchars($st['staff_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($st['role'], ENT_QUOTES) ?>', <?= $has_pin ? 'true' : 'false' ?>)">
                    <?php if ($has_pin): ?>
                        <span class="pin-badge" title="要暗証番号">🔒</span>
                    <?php endif; ?>
                    <div class="staff-name"><?= htmlspecialchars($st['staff_name']) ?></div>
                    <div class="staff-role"><?= htmlspecialchars($st['role']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- 👑 特別アカウント：管理者ログイン（フッターの控えめなアコーディオンに移設） -->
    <?php if ($admin_staff): 
        $a_has_pin = !empty(trim($admin_staff['pin_code'] ?? ''));
    ?>
        <details style="margin-top:20px; border-top:1px dashed #cbd5e1; padding-top:12px; font-size:0.8rem; color:#64748b;">
            <summary style="cursor:pointer; color:#0284c7; font-weight:bold; outline:none;">
                ⚙️ システム管理者・特別ログイン（引継・災害設定用）
            </summary>
            <div style="margin-top:8px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-weight:bold; color:#0369a1;">👑 <?= htmlspecialchars($admin_staff['staff_name']) ?></div>
                    <div style="font-size:0.72rem; color:#0284c7;">全権限・災害モード切替用</div>
                </div>
                <button type="button" onclick="handleClickStaff(<?= $admin_staff['staff_id'] ?>, '<?= htmlspecialchars($admin_staff['staff_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($admin_staff['role'], ENT_QUOTES) ?>', <?= $a_has_pin ? 'true' : 'false' ?>)" style="background:#0284c7; color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:0.78rem; font-weight:bold; cursor:pointer;">
                    🔒 暗証番号で入る
                </button>
            </div>
        </details>
    <?php endif; ?>

    <div style="margin-top:16px; text-align:center; font-size:0.74rem; color:#94a3b8;">
        📱 一度ログインすると、この端末（スマホ）が自動記憶され次回からそのまま開けます。
    </div>
</div>

<!-- 📱 テンキー暗証番号入力モーダル -->
<div id="pinModal" class="pin-modal-overlay">
    <div class="pin-card">
        <div style="margin-bottom:12px;">
            <div style="font-size:0.82rem; color:#64748b; font-weight:bold;">🔒 暗証番号入力</div>
            <div class="pin-target-name" id="pinTargetName">職員名</div>
            <div class="pin-target-role" id="pinTargetRole">役職</div>
            <div class="pin-instruction">下の数字キーを押して暗証番号を入力してください</div>
        </div>

        <!-- パスワード表示エリア -->
        <div class="pin-display-wrap">
            <div class="pin-display-text" id="pinDots"></div>
            <div class="pin-placeholder" id="pinPlaceholder">数字キーを押してください</div>
        </div>

        <!-- 3×4 テンキーボタン -->
        <div class="pin-keypad">
            <button type="button" class="pin-key" onclick="pressKey('1')">1</button>
            <button type="button" class="pin-key" onclick="pressKey('2')">2</button>
            <button type="button" class="pin-key" onclick="pressKey('3')">3</button>
            <button type="button" class="pin-key" onclick="pressKey('4')">4</button>
            <button type="button" class="pin-key" onclick="pressKey('5')">5</button>
            <button type="button" class="pin-key" onclick="pressKey('6')">6</button>
            <button type="button" class="pin-key" onclick="pressKey('7')">7</button>
            <button type="button" class="pin-key" onclick="pressKey('8')">8</button>
            <button type="button" class="pin-key" onclick="pressKey('9')">9</button>
            <button type="button" class="pin-key btn-clear" onclick="clearPin()" title="全消去">C</button>
            <button type="button" class="pin-key" onclick="pressKey('0')">0</button>
            <button type="button" class="pin-key btn-backspace" onclick="backspacePin()" title="1文字消去">⌫</button>
        </div>

        <button type="button" class="btn-pin-submit" onclick="submitWithPin()">
            ログイン ⏎
        </button>
        <button type="button" class="btn-pin-cancel" onclick="closePinModal()">
            ✕ キャンセル
        </button>
    </div>
</div>

<script>
const kanaRowMap = {
    'あ': ['ア', 'イ', 'ウ', 'エ', 'オ', 'ぁ', 'ぃ', 'ぅ', 'ぇ', 'ぉ'],
    'か': ['カ', 'キ', 'ク', 'ケ', 'コ', 'が', 'ぎ', 'ぐ', 'げ', 'ご', 'ガ', 'ギ', 'グ', 'ゲ', 'ゴ'],
    'さ': ['サ', 'シ', 'ス', 'セ', 'ソ', 'ざ', 'じ', 'ず', 'ぜ', 'ぞ', 'ザ', 'ジ', 'ズ', 'ゼ', 'ゾ'],
    'た': ['タ', 'チ', 'ツ', 'テ', 'ト', 'だ', 'ぢ', 'づ', 'で', 'ど', 'ダ', 'ヂ', 'ヅ', 'デ', 'ド', 'ッ'],
    'な': ['ナ', 'ニ', 'ヌ', 'ネ', 'ノ'],
    'は': ['ハ', 'ヒ', 'フ', 'ヘ', 'ホ', 'ば', 'び', 'ぶ', 'べ', 'ぼ', 'ぱ', 'ぴ', 'ぷ', 'ぺ', 'ぽ', 'バ', 'ビ', 'ブ', 'ベ', 'ボ', 'パ', 'ピ', 'プ', 'ペ', 'ポ'],
    'ま': ['マ', 'ミ', 'ム', 'メ', 'モ'],
    'や': ['ヤ', 'ユ', 'ヨ', 'ゃ', 'ゅ', 'ょ'],
    'ら': ['ラ', 'リ', 'ル', 'レ', 'ロ'],
    'わ': ['ワ', 'ヲ', 'ン', 'わ', 'を', 'ん']
};

function filterKana(row, btn) {
    document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const items = document.querySelectorAll('.staff-btn');
    items.forEach(item => {
        const firstChar = item.getAttribute('data-first-char');
        if (row === 'all') {
            item.style.display = 'flex';
        } else {
            const targetChars = kanaRowMap[row] || [];
            if (targetChars.includes(firstChar)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        }
    });
}

// 暗証番号入力ステート
let currentPin = '';
let currentStaffId = null;

function handleClickStaff(staffId, staffName, role, hasPin) {
    if (!hasPin) {
        // 一般スタッフ：パスワードなしでワンタップログイン＆端末固定！
        document.getElementById('selectedStaffId').value = staffId;
        document.getElementById('enteredPinCode').value = '';
        document.getElementById('loginForm').submit();
        return;
    }

    // 医師・山本太など：数字キータッチパッドモーダルを開く
    currentStaffId = staffId;
    currentPin = '';
    updatePinDisplay();

    document.getElementById('pinTargetName').textContent = staffName;
    document.getElementById('pinTargetRole').textContent = role;
    document.getElementById('pinModal').classList.add('active');
}

function closePinModal() {
    document.getElementById('pinModal').classList.remove('active');
    currentStaffId = null;
    currentPin = '';
}

function pressKey(num) {
    if (currentPin.length >= 10) return;
    currentPin += num;
    updatePinDisplay();
}

function backspacePin() {
    if (currentPin.length > 0) {
        currentPin = currentPin.slice(0, -1);
        updatePinDisplay();
    }
}

function clearPin() {
    currentPin = '';
    updatePinDisplay();
}

function updatePinDisplay() {
    const dotsEl = document.getElementById('pinDots');
    const placeholderEl = document.getElementById('pinPlaceholder');
    
    if (currentPin.length === 0) {
        dotsEl.textContent = '';
        placeholderEl.style.display = 'block';
    } else {
        placeholderEl.style.display = 'none';
        dotsEl.textContent = '●'.repeat(currentPin.length);
    }
}

function submitWithPin() {
    if (!currentStaffId) return;
    if (currentPin.length === 0) {
        alert('数字キーを押して暗証番号を入力してください！');
        return;
    }
    document.getElementById('selectedStaffId').value = currentStaffId;
    document.getElementById('enteredPinCode').value = currentPin;
    document.getElementById('loginForm').submit();
}

window.addEventListener('keydown', function(e) {
    const modal = document.getElementById('pinModal');
    if (!modal.classList.contains('active')) return;

    if (e.key >= '0' && e.key <= '9') {
        pressKey(e.key);
    } else if (e.key === 'Backspace') {
        backspacePin();
    } else if (e.key === 'Enter') {
        submitWithPin();
    } else if (e.key === 'Escape') {
        closePinModal();
    }
});
</script>

</body>
</html>