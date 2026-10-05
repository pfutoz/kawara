<?php
// 1. セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. DB接続
$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}

$current_staff_id = isset($_SESSION['staff_id']) ? (int)$_SESSION['staff_id'] : 0;
$can_toggle = isset($_SESSION['can_toggle_disaster']) && $_SESSION['can_toggle_disaster'] === true;
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// 権限チェック：一般ユーザーおよび未ログイン者のアクセス制限
if (!$can_toggle && !$is_admin) {
    $_SESSION['error_msg'] = "❌ 権限設定画面へのアクセス権限がありません。管理者または医師アカウントでログインしてください。";
    header("Location: login.php?redirect=admin_disaster_staff.php");
    exit;
}

$notice_msg = $_SESSION['notice_msg'] ?? '';
$error_msg  = $_SESSION['error_msg'] ?? '';
unset($_SESSION['notice_msg'], $_SESSION['error_msg']);

// 3. POST処理：特定スタッフの権限・暗証番号の一括更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 認証チェック（ログイン中かつ権限者、またはマスター暗証番号の入力）
    $auth_ok = ($can_toggle || $is_admin);
    if (!$auth_ok && !empty($_POST['admin_auth_pin'])) {
        $entered_admin_pin = trim($_POST['admin_auth_pin']);
        // 山本太(1232104)または医師(5500)の暗証番号であればOK
        $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE pin_code = :pin AND can_toggle_disaster = TRUE");
        $stmt_chk->execute([':pin' => $entered_admin_pin]);
        if ($stmt_chk->fetchColumn() > 0) {
            $auth_ok = true;
        }
    }

    if (!$auth_ok) {
        $_SESSION['error_msg'] = "❌ 設定変更権限がありません。医師または事務長（山本）の暗証番号が必要です。";
        header("Location: admin_disaster_staff.php");
        exit;
    }

    // A. 個別・一括保存
    if ($action === 'save_permissions') {
        $allowed_staff_ids = isset($_POST['allowed_staff']) && is_array($_POST['allowed_staff']) 
            ? array_map('intval', $_POST['allowed_staff']) 
            : [];
        $pins = isset($_POST['pins']) && is_array($_POST['pins']) ? $_POST['pins'] : [];

        // 全スタッフの権限を一旦全取得
        $all_staff = $pdo->query("SELECT staff_id FROM staff WHERE (is_deleted IS NOT TRUE)")->fetchAll();

        $stmt_update = $pdo->prepare("UPDATE staff SET can_toggle_disaster = :can, pin_code = :pin, updated_at = NOW() WHERE staff_id = :id");

        foreach ($all_staff as $st) {
            $sid = (int)$st['staff_id'];
            $is_allowed = in_array($sid, $allowed_staff_ids, true);
            $pin_val = isset($pins[$sid]) ? trim($pins[$sid]) : '';
            
            // 数字のみ抽出（安全対策）
            $clean_pin = preg_replace('/[^\d]/', '', $pin_val);

            $stmt_update->execute([
                ':can' => $is_allowed ? 'true' : 'false',
                ':pin' => !empty($clean_pin) ? $clean_pin : null,
                ':id'  => $sid
            ]);
        }

        $_SESSION['notice_msg'] = "✓ 災害緊急モード変更権限および暗証番号の設定を更新しました。";
        header("Location: admin_disaster_staff.php");
        exit;
    }

    // B. 初期プリセット復元（管理者・山本太: 1232104 / 医師全員: 5500）
    if ($action === 'reset_presets') {
        // 管理者 ＆ 山本太
        $pdo->exec("UPDATE staff SET pin_code = '1232104', can_toggle_disaster = TRUE WHERE staff_name IN ('管理者', 'システム管理者') OR staff_name LIKE '%山本%太%'");
        // 医師全員
        $pdo->exec("UPDATE staff SET pin_code = '5500', can_toggle_disaster = TRUE WHERE dept_id = 2 OR role LIKE '%医師%' OR role LIKE '%院長%'");

        $_SESSION['notice_msg'] = "✓ 規定の権限者（管理者・山本太: 1232104 / 医師全員: 5500）へリセット設定しました。";
        header("Location: admin_disaster_staff.php");
        exit;
    }
}

// 4. スタッフ一覧の取得
$sql = "SELECT s.staff_id, s.staff_name, s.role, s.kana, s.pin_code, s.can_toggle_disaster, d.dept_name 
        FROM staff s 
        LEFT JOIN target_departments d ON s.dept_id = d.dept_id 
        WHERE (s.is_deleted IS NOT TRUE)
        ORDER BY s.can_toggle_disaster DESC, d.display_order ASC, s.kana ASC";
$staff_list = $pdo->query($sql)->fetchAll();

// 権限者数の集計
$auth_count = 0;
foreach ($staff_list as $st) {
    if (!empty($st['can_toggle_disaster'])) $auth_count++;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ 災害モード特定権限スタッフ登録 | 医療法人小野会</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #004085;
            --border: #cbd5e1;
            --bg-color: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Noto Sans JP', sans-serif; background: var(--bg-color); color: #1e293b; line-height: 1.5; padding-bottom: 40px; }

        header { background: #1e293b; color: #fff; padding: 12px 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header-wrap { max-width: 1100px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header-title h1 { font-size: 1.15rem; font-weight: 900; display: flex; align-items: center; gap: 6px; }
        .header-nav { display: flex; gap: 8px; }
        .btn-nav { background: rgba(255,255,255,0.15); color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 0.82rem; font-weight: bold; transition: all 0.15s; }
        .btn-nav:hover { background: rgba(255,255,255,0.3); }

        main { max-width: 1100px; margin: 20px auto; padding: 0 16px; }

        .alert-box { padding: 12px 16px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; margin-bottom: 16px; }
        .alert-box.success { background: #dcfce7; color: #15803d; border: 1.5px solid #86efac; }
        .alert-box.error { background: #fee2e2; color: #991b1b; border: 1.5px solid #f87171; }

        .info-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-left: 5px solid var(--primary);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }
        .info-title { font-size: 1.05rem; font-weight: 900; color: #0f172a; margin-bottom: 6px; }
        .info-text { font-size: 0.85rem; color: #475569; line-height: 1.6; }
        .badge-count { background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 12px; font-weight: 900; font-size: 0.85rem; }

        .table-wrap {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        table.perm-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        table.perm-table th { background: #f1f5f9; color: #475569; font-weight: 700; padding: 12px 14px; border-bottom: 2px solid var(--border); text-align: left; }
        table.perm-table td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        table.perm-table tr:hover { background: #f8fafc; }
        table.perm-table tr.row-authorized { background: #f0fdf4; }

        .switch-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-weight: 900;
            font-size: 0.84rem;
        }
        .input-pin {
            font-family: 'Outfit', monospace;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 2px;
            padding: 6px 10px;
            width: 140px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
        }
        .input-pin:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,90,156,0.15);
        }

        .auth-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 900;
        }
        .auth-badge.yes { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .auth-badge.no { background: #f1f5f9; color: #64748b; }

        .action-bar {
            position: sticky;
            bottom: 16px;
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            padding: 12px 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            z-index: 100;
        }
        .btn-save {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,90,156,0.25);
            transition: all 0.15s;
        }
        .btn-save:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-reset {
            background: #f1f5f9;
            color: #475569;
            border: 1.5px solid #cbd5e1;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-reset:hover { background: #e2e8f0; }
    </style>
</head>
<body>

    <header>
        <div class="header-wrap">
            <div class="header-title">
                <h1>⚙️ 災害モード特定スタッフ登録・暗証番号管理</h1>
            </div>
            <div class="header-nav">
                <a href="safety_contacts.php" class="btn-nav">🛡️ 連絡網・安否確認</a>
                <a href="index.php" class="btn-nav">📜 かわら版</a>
            </div>
        </div>
    </header>

    <main>
        <?php if (!empty($notice_msg)): ?>
            <div class="alert-box success"><?= $notice_msg ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert-box error"><?= $error_msg ?></div>
        <?php endif; ?>

        <div class="info-card">
            <div class="info-title">
                🔐 災害緊急モード変更権限（エントリーポイント制限）
            </div>
            <div class="info-text">
                災害時緊急モード（職員の自宅電話番号・住所・携帯番号の全開示）は、誤操作や不正開示を防ぐため、<strong>原則「医師」および「管理者（事務長・山本 太）」のみ</strong>が変更可能となっています。<br>
                ・変更権限を付与されたスタッフは、ログイン時に<strong>数字キータッチパッド</strong>で暗証番号を入力します。<br>
                ・現在の発令権限者数: <span class="badge-count"><?= $auth_count ?> 名</span>（管理者・山本太: <code>1232104</code> / 医師全員: <code>5500</code>）
            </div>
        </div>

        <form method="POST" id="permForm">
            <input type="hidden" name="action" value="save_permissions">

            <div class="table-wrap">
                <table class="perm-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">状態</th>
                            <th style="width:160px;">氏名 (かな)</th>
                            <th style="width:120px;">所属・役職</th>
                            <th style="width:140px;">🚨 災害モード発令権限</th>
                            <th style="width:180px;">🔢 暗証番号 (数字PIN)</th>
                            <th>備考・運用メモ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff_list as $st): 
                            $is_allowed = !empty($st['can_toggle_disaster']);
                            $is_admin_special = ($st['staff_name'] === '管理者' || $st['staff_name'] === 'システム管理者');
                            $is_yamamoto = (strpos($st['staff_name'], '山本') !== false && strpos($st['staff_name'], '太') !== false);
                            $is_doctor = ($st['dept_name'] === '医師' || strpos($st['role'], '医師') !== false || strpos($st['role'], '院長') !== false);
                        ?>
                            <tr class="<?= $is_allowed ? 'row-authorized' : '' ?>">
                                <td>
                                    <?php if ($is_allowed): ?>
                                        <span class="auth-badge yes">🟢 許可</span>
                                    <?php else: ?>
                                        <span class="auth-badge no">⚪ なし</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="font-size:0.95rem; color:#0f172a;">
                                        <?= $is_admin_special ? '👑 ' : '' ?><?= htmlspecialchars($st['staff_name']) ?>
                                    </strong>
                                    <?php if ($st['kana']): ?>
                                        <div style="font-size:0.7rem; color:#64748b;"><?= htmlspecialchars($st['kana']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight:bold; font-size:0.82rem; color:#475569;"><?= htmlspecialchars($st['dept_name'] ?? '未所属') ?></span>
                                    <div style="font-size:0.72rem; color:#64748b;"><?= htmlspecialchars($st['role']) ?></div>
                                </td>
                                <td>
                                    <label class="switch-label" style="color: <?= $is_allowed ? '#166534' : '#64748b' ?>;">
                                        <input type="checkbox" name="allowed_staff[]" value="<?= $st['staff_id'] ?>" <?= $is_allowed ? 'checked' : '' ?> style="width:18px; height:18px;" onchange="toggleRowHighlight(this)">
                                        <?= $is_allowed ? '発令・解除 許可' : '権限なし' ?>
                                    </label>
                                </td>
                                <td>
                                    <input type="text" name="pins[<?= $st['staff_id'] ?>]" value="<?= htmlspecialchars($st['pin_code'] ?? '') ?>" class="input-pin" placeholder="数字4〜10桁" pattern="\d*" maxlength="10">
                                </td>
                                <td style="font-size:0.8rem; color:#64748b;">
                                    <?php if ($is_admin_special): ?>
                                        <span style="color:#7c3aed; font-weight:bold;">👑 特別アカウント（退職・引継対応／初期PIN: 1232104）</span>
                                    <?php elseif ($is_yamamoto): ?>
                                        <span style="color:#0369a1; font-weight:bold;">★ 事務長（初期PIN: 1232104）</span>
                                    <?php elseif ($is_doctor): ?>
                                        <span style="color:#059669; font-weight:bold;">★ 医師（初期PIN: 5500）</span>
                                    <?php else: ?>
                                        一般職員
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 下部固定保存バー -->
            <div class="action-bar">
                <div style="display:flex; align-items:center; gap:10px;">
                    <button type="submit" class="btn-save">💾 設定を保存する</button>
                    <span style="font-size:0.82rem; color:#64748b;">※ チェックを入れたスタッフのみ災害モードの切替が有効になります</span>
                </div>

                <div>
                    <button type="button" class="btn-reset" onclick="confirmResetPresets()">
                        🔄 規定値（管理者・山本:1232104 / 医師:5500）に復元
                    </button>
                </div>
            </div>
        </form>

        <form method="POST" id="resetPresetForm" style="display:none;">
            <input type="hidden" name="action" value="reset_presets">
        </form>
    </main>

    <script>
    function toggleRowHighlight(cb) {
        const row = cb.closest('tr');
        const label = cb.closest('label');
        if (cb.checked) {
            row.classList.add('row-authorized');
            label.style.color = '#166534';
            label.lastChild.textContent = ' 発令・解除 許可';
        } else {
            row.classList.remove('row-authorized');
            label.style.color = '#64748b';
            label.lastChild.textContent = ' 権限なし';
        }
    }

    function confirmResetPresets() {
        if (confirm('【確認】規定の初期設定（管理者・山本太: 1232104、医師全員: 5500）に復元しますか？')) {
            document.getElementById('resetPresetForm').submit();
        }
    }
    </script>
</body>
</html>
