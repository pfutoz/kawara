<?php
// 1. セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. DB接続設定
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

// 3. ログイン中のセッションがあり、かつそのスタッフが削除済みになっていないかチェック
if (isset($_SESSION['staff_id'])) {
    $check_stmt = $pdo->prepare("SELECT is_deleted FROM staff WHERE staff_id = :id");
    $check_stmt->execute([':id' => $_SESSION['staff_id']]);
    $current_staff = $check_stmt->fetch();

    if (!$current_staff || $current_staff['is_deleted'] === true || $current_staff['is_deleted'] === 't' || $current_staff['is_deleted'] === 1) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}

// 4. ログアウト処理
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
    header("Location: login.php");
    exit;
}

// 5. スタッフ選択時（POST送信時）のログイン処理
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
            // ---------- セッションに「最近使ったスタッフ」を記録 ----------
            if (!isset($_SESSION['recent_staff_ids'])) {
                $_SESSION['recent_staff_ids'] = [];
            }
            $_SESSION['recent_staff_ids'] = array_diff($_SESSION['recent_staff_ids'], [$staff['staff_id']]);
            array_unshift($_SESSION['recent_staff_ids'], $staff['staff_id']);
            $_SESSION['recent_staff_ids'] = array_slice($_SESSION['recent_staff_ids'], 0, 10);
            // ----------------------------------------------------------------

            $_SESSION['staff_id'] = $staff['staff_id'];
            $_SESSION['staff_name'] = $staff['staff_name'];
            $_SESSION['role'] = $staff['role'];
            $_SESSION['is_admin'] = (bool)$staff['is_admin'];
            $_SESSION['can_toggle_disaster'] = (bool)($staff['can_toggle_disaster'] ?? false);
            $_SESSION['last_activity'] = time();

            $redirect_url = !empty($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
            header("Location: " . $redirect_url);
            exit;
        }
    } else {
        $error_msg = "選択されたスタッフが見つからないか、利用対象外です。";
    }
}

// 6. スタッフ一覧の取得 ＆ セッションから最近使ったスタッフを取得
try {
    // 全スタッフ（50音順）
    $stmt_all = $pdo->query("SELECT staff_id, staff_name, short_icon, role, kana, kana_row, line_user_id, pin_code, can_toggle_disaster FROM staff WHERE (is_deleted IS NOT TRUE) ORDER BY kana ASC");
    $staff_list = $stmt_all->fetchAll();

    // 最近使ったスタッフ（セッションから取得）
    $recent_staff_list = [];
    if (!empty($_SESSION['recent_staff_ids'])) {
        $ids = array_map('intval', $_SESSION['recent_staff_ids']);
        $in_ids = implode(',', $ids);
        $stmt_recent = $pdo->query("
            SELECT staff_id, staff_name, role, pin_code, can_toggle_disaster 
            FROM staff 
            WHERE staff_id IN ({$in_ids}) AND (is_deleted IS NOT TRUE)
        ");
        $fetched = $stmt_recent->fetchAll();

        // セッションの順序（最新順）に並べ替え
        $order = array_flip($_SESSION['recent_staff_ids']);
        usort($fetched, function($a, $b) use ($order) {
            return $order[$a['staff_id']] - $order[$b['staff_id']];
        });
        $recent_staff_list = $fetched;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-card {
            background: #fff;
            width: 100%;
            max-width: 680px;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        h1 { font-size: 1.4rem; color: var(--primary); text-align: center; margin-top: 0; margin-bottom: 5px; font-weight: 900; }
        .subtitle { text-align: center; font-size: 0.85rem; color: #64748b; margin-bottom: 20px; }
        
        .section-title { font-size: 0.84rem; font-weight: bold; color: #475569; margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
        
        .recent-grid { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; margin-bottom: 16px; }
        .recent-btn {
            background: #f0f7ff;
            border: 1.5px solid #b8daff;
            padding: 8px 14px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
            position: relative;
        }
        .recent-btn:hover { background: #dbeafe; border-color: var(--primary); transform: translateY(-1px); }
        .recent-name { font-weight: bold; font-size: 0.88rem; color: #004085; }
        .recent-role { font-size: 0.7rem; color: #64748b; margin-top: 2px; }

        .filter-bar { display: flex; gap: 4px; justify-content: center; margin-bottom: 15px; flex-wrap: wrap; background: #e2e8f0; padding: 6px; border-radius: 8px; }
        .btn-filter { background: #fff; border: 1px solid #cbd5e1; padding: 5px 11px; border-radius: 6px; font-size: 0.82rem; font-weight: bold; cursor: pointer; color: #475569; transition: all 0.15s; }
        .btn-filter.active { background: var(--primary); color: white; border-color: var(--primary); }

        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
            gap: 10px;
            max-height: 320px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fafafa;
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
            gap: 4px;
            position: relative;
        }
        .staff-btn:hover { background: #eff6ff; border-color: var(--primary); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,90,156,0.12); }
        .staff-name { font-weight: bold; font-size: 0.92rem; color: #1e293b; }
        .staff-role { font-size: 0.72rem; color: #64748b; background: #f1f5f9; padding: 1px 6px; border-radius: 10px; }
        .staff-btn.admin-user-btn { border-color: #38bdf8; background: #f0f9ff; }
        .staff-btn.admin-user-btn:hover { border-color: #0284c7; background: #e0f2fe; }
        
        .pin-badge {
            position: absolute;
            top: 4px;
            right: 5px;
            font-size: 0.8rem;
            line-height: 1;
        }

        .error-box { background: #fee2e2; color: #991b1b; border: 1.5px solid #f87171; padding: 12px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 16px; text-align: center; font-weight: bold; animation: shake 0.3s; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        /* 📱 数字キータッチパッド（PINモーダル） */
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
            max-width: 360px;
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            text-align: center;
        }
        .pin-card-header {
            margin-bottom: 14px;
        }
        .pin-target-name {
            font-size: 1.15rem;
            font-weight: 900;
            color: #1e293b;
        }
        .pin-target-role {
            font-size: 0.78rem;
            color: #d97706;
            font-weight: bold;
            background: #fef3c7;
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            margin-top: 4px;
        }
        .pin-instruction {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 8px;
        }

        /* 入力ディスプレイ */
        .pin-display-wrap {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            height: 52px;
            margin: 12px 0 18px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Outfit', monospace;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.04);
        }
        .pin-display-text {
            font-size: 1.6rem;
            letter-spacing: 6px;
            color: #0f172a;
            font-weight: 900;
        }
        .pin-placeholder {
            color: #94a3b8;
            font-size: 0.85rem;
            font-family: sans-serif;
            letter-spacing: normal;
        }

        /* テンキーグリッド */
        .pin-keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }
        .pin-key {
            height: 56px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 1.55rem;
            font-weight: 700;
            color: #1e293b;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            transition: all 0.1s;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
            -webkit-user-select: none;
        }
        .pin-key:hover { background: #f1f5f9; border-color: #94a3b8; }
        .pin-key:active { transform: scale(0.94); background: #e2e8f0; }

        .pin-key.btn-clear {
            font-size: 0.95rem;
            font-weight: bold;
            color: #dc2626;
            background: #fef2f2;
            border-color: #fecaca;
        }
        .pin-key.btn-clear:hover { background: #fee2e2; }

        .pin-key.btn-backspace {
            font-size: 1.2rem;
            color: #475569;
            background: #f8fafc;
        }

        .btn-pin-submit {
            width: 100%;
            height: 48px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(0,90,156,0.3);
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-pin-submit:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-pin-submit:active { transform: scale(0.98); }

        .btn-pin-cancel {
            width: 100%;
            background: none;
            border: none;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: bold;
            padding: 8px;
            margin-top: 6px;
            cursor: pointer;
        }
        .btn-pin-cancel:hover { text-decoration: underline; color: #334155; }

        /* 👑 管理者（特別ユーザー）バナー */
        .admin-special-banner {
            background: linear-gradient(135deg, #f0f7ff 0%, #e0f2fe 100%);
            border: 2px solid #38bdf8;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.12);
            transition: all 0.2s;
        }
        .admin-special-banner:hover {
            transform: translateY(-2px);
            border-color: #0284c7;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.25);
        }
        .admin-special-banner:active { transform: scale(0.99); }
        .admin-special-left { display: flex; align-items: center; gap: 12px; }
        .admin-special-icon { font-size: 1.8rem; line-height: 1; }
        .admin-special-name { font-size: 1.05rem; font-weight: 900; color: #0369a1; }
        .admin-special-desc { font-size: 0.76rem; color: #0284c7; font-weight: 500; margin-top: 2px; }
        .admin-special-btn {
            background: #0284c7;
            color: #fff;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 900;
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.3);
            white-space: nowrap;
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

    <!-- 👑 特別アカウント：管理者ログイン（退職・引継対応） -->
    <?php if ($admin_staff): 
        $a_has_pin = !empty(trim($admin_staff['pin_code'] ?? ''));
    ?>
        <div class="admin-special-banner" onclick="handleClickStaff(<?= $admin_staff['staff_id'] ?>, '<?= htmlspecialchars($admin_staff['staff_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($admin_staff['role'], ENT_QUOTES) ?>', <?= $a_has_pin ? 'true' : 'false' ?>)">
            <div class="admin-special-left">
                <span class="admin-special-icon">👑</span>
                <div>
                    <div class="admin-special-name"><?= htmlspecialchars($admin_staff['staff_name']) ?>（特別アカウント）</div>
                    <div class="admin-special-desc">退職・引継対応 / システム管理・災害モード切替権限</div>
                </div>
            </div>
            <div class="admin-special-btn">
                🔒 暗証番号でログイン
            </div>
        </div>
    <?php endif; ?>

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
                $kana_trim = trim($st['kana'] ?? '');
                $first_char = mb_substr($kana_trim, 0, 1);
                $has_pin = !empty(trim($st['pin_code'] ?? ''));
                $is_admin_user = ($st['staff_name'] === '管理者' || $st['staff_name'] === 'システム管理者');
            ?>
                <div class="staff-btn <?= $is_admin_user ? 'admin-user-btn' : '' ?>" 
                     data-kana="<?= htmlspecialchars($kana_trim) ?>"
                     data-first-char="<?= htmlspecialchars($first_char) ?>"
                     onclick="handleClickStaff(<?= $st['staff_id'] ?>, '<?= htmlspecialchars($st['staff_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($st['role'], ENT_QUOTES) ?>', <?= $has_pin ? 'true' : 'false' ?>)">
                    <?php if ($has_pin): ?>
                        <span class="pin-badge" title="要暗証番号">🔒</span>
                    <?php endif; ?>
                    <div class="staff-name"><?= $is_admin_user ? '👑 ' : '' ?><?= htmlspecialchars($st['staff_name']) ?></div>
                    <div class="staff-role"><?= htmlspecialchars($st['role']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<!-- 📱 テンキー暗証番号入力モーダル（バカでも押せる大型数字キー！） -->
<div id="pinModal" class="pin-modal-overlay">
    <div class="pin-card">
        <div class="pin-card-header">
            <div style="font-size:0.85rem; color:#64748b; font-weight:bold;">🔒 認証が必要です</div>
            <div class="pin-target-name" id="pinTargetName">職員名</div>
            <div class="pin-target-role" id="pinTargetRole">役職</div>
            <div class="pin-instruction">下の数字キーを押して暗証番号を入力してください</div>
        </div>

        <!-- パスワード表示エリア（ドットで表示） -->
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
        // 一般スタッフ：パスワードなしでワンタップログイン！
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
    if (currentPin.length >= 10) return; // 最大10桁
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
        // ドット表示（例: ● ● ● ●）
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

// 物理キーボードの数字・テンキー入力にも対応
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