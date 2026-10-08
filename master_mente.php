<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$timeout_duration = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy(); header("Location: login.php?reason=timeout"); exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/google_calendar_helper.php';

// ログインユーザー＆管理者権限チェック
$current_staff_id = (int)$_SESSION['staff_id'];
$stmt_user = $pdo->prepare("SELECT staff_id, staff_name, is_admin FROM staff WHERE staff_id = :id");
$stmt_user->execute([':id' => $current_staff_id]);
$login_user = $stmt_user->fetch();

if (!$login_user || !$login_user['is_admin']) {
    exit('管理者権限が必要です。');
}

// Ajaxリクエストのハンドリング（接続テスト / 手動同期）
if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $ajax_action = $_REQUEST['action'] ?? '';
    if ($ajax_action === 'test_gcal_connection') {
        $cal_id = trim($_REQUEST['calendar_id'] ?? '');
        if ($cal_id === '') {
            echo json_encode(['success' => false, 'message' => 'カレンダーIDが空です']);
            exit;
        }
        $res = test_google_calendar_connection($cal_id);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($ajax_action === 'sync_gcal_all') {
        $res = sync_all_google_calendars($pdo, true);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit;
}

$msg = '';
$err_msg = '';
$active_tab = $_GET['tab'] ?? 'staff';

// POST処理（スタッフ登録・編集・削除 / Googleカレンダーチャンネル登録・編集・削除）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_staff') {
        $st_id        = (int)($_POST['staff_id'] ?? 0);
        $st_name      = trim($_POST['staff_name'] ?? '');
        $kana         = trim($_POST['kana'] ?? '');
        $kana_row     = trim($_POST['kana_row'] ?? 'あ');
        $role         = trim($_POST['role'] ?? '');
        $dept_id      = (int)($_POST['dept_id'] ?? 0);
        $is_admin_val = isset($_POST['is_admin']) ? 'TRUE' : 'FALSE';
        $line_user_id = trim($_POST['line_user_id'] ?? '');

        // LINE IDが空文字の場合は NULL として保存
        $line_user_id_val = ($line_user_id !== '') ? $line_user_id : null;

        if ($st_name !== '') {
            if ($st_id > 0) {
                // 更新処理
                $stmt_u = $pdo->prepare("UPDATE staff SET 
                    staff_name = :name, 
                    kana = :kana, 
                    kana_row = :krow, 
                    role = :role, 
                    dept_id = :dept_id, 
                    is_admin = {$is_admin_val}, 
                    line_user_id = :line_id 
                    WHERE staff_id = :id");
                $stmt_u->execute([
                    ':name'    => $st_name,
                    ':kana'    => $kana,
                    ':krow'    => $kana_row,
                    ':role'    => $role,
                    ':dept_id' => $dept_id,
                    ':line_id' => $line_user_id_val,
                    ':id'      => $st_id
                ]);
                $msg = 'スタッフ情報を更新しました。';
            } else {
                // 新規登録処理
                $stmt_i = $pdo->prepare("INSERT INTO staff (staff_name, kana, kana_row, role, dept_id, is_admin, line_user_id) VALUES (:name, :kana, :krow, :role, :dept_id, {$is_admin_val}, :line_id)");
                $stmt_i->execute([
                    ':name'    => $st_name,
                    ':kana'    => $kana,
                    ':krow'    => $kana_row,
                    ':role'    => $role,
                    ':dept_id' => $dept_id,
                    ':line_id' => $line_user_id_val
                ]);
                $msg = '新しいスタッフを登録しました。';
            }
        } else {
            $err_msg = '氏名は必須入力です。';
        }
        $active_tab = 'staff';
    }

    if ($action === 'delete_staff') {
        $st_id = (int)($_POST['staff_id'] ?? 0);
        if ($st_id > 0) {
            // 論理削除 (is_deleted = TRUE)
            $stmt_d = $pdo->prepare("UPDATE staff SET is_deleted = TRUE WHERE staff_id = :id");
            $stmt_d->execute([':id' => $st_id]);
            $msg = 'スタッフを削除（無効化）しました。';
        }
        $active_tab = 'staff';
    }

    if ($action === 'save_gcal_channel') {
        $ch_id        = (int)($_POST['channel_id'] ?? 0);
        $acct_name    = trim($_POST['account_name'] ?? '');
        $cal_id       = trim($_POST['calendar_id'] ?? '');
        $cal_name     = trim($_POST['calendar_name'] ?? '');
        $color_theme  = trim($_POST['color_theme'] ?? '#4285f4');
        $disp_order   = (int)($_POST['display_order'] ?? 10);
        $is_enabled   = isset($_POST['is_enabled']) ? 'TRUE' : 'FALSE';

        if ($cal_id !== '' && $cal_name !== '') {
            if ($ch_id > 0) {
                $stmt_up_ch = $pdo->prepare("UPDATE google_calendar_channels SET
                    account_name = :acct,
                    calendar_id = :cal_id,
                    calendar_name = :name,
                    color_theme = :color,
                    display_order = :ord,
                    is_enabled = {$is_enabled},
                    updated_at = NOW()
                    WHERE channel_id = :id");
                $stmt_up_ch->execute([
                    ':acct'  => $acct_name,
                    ':cal_id'=> $cal_id,
                    ':name'  => $cal_name,
                    ':color' => $color_theme,
                    ':ord'   => $disp_order,
                    ':id'    => $ch_id
                ]);
                $msg = "Googleカレンダー「{$cal_name}」の設定を更新しました。";
            } else {
                $stmt_in_ch = $pdo->prepare("INSERT INTO google_calendar_channels (
                    account_name, calendar_id, calendar_name, color_theme, is_enabled, display_order, created_at, updated_at
                ) VALUES (
                    :acct, :cal_id, :name, :color, {$is_enabled}, :ord, NOW(), NOW()
                )");
                $stmt_in_ch->execute([
                    ':acct'  => $acct_name,
                    ':cal_id'=> $cal_id,
                    ':name'  => $cal_name,
                    ':color' => $color_theme,
                    ':ord'   => $disp_order
                ]);
                $msg = "Googleカレンダー「{$cal_name}」を追加しました。";
            }
        } else {
            $err_msg = 'カレンダーIDと表示名は必須入力です。';
        }
        $active_tab = 'gcal';
    }

    if ($action === 'delete_gcal_channel') {
        $ch_id = (int)($_POST['channel_id'] ?? 0);
        if ($ch_id > 0) {
            $pdo->prepare("DELETE FROM google_calendar_events_cache WHERE channel_id = ?")->execute([$ch_id]);
            $pdo->prepare("DELETE FROM google_calendar_channels WHERE channel_id = ?")->execute([$ch_id]);
            $msg = 'Googleカレンダー設定を削除しました。';
        }
        $active_tab = 'gcal';
    }

    if ($action === 'upload_sa_json') {
        $active_tab = 'gcal';
        if (!isset($_FILES['sa_json_file']) || $_FILES['sa_json_file']['error'] !== UPLOAD_ERR_OK) {
            $err_msg = 'ファイルが選択されていないか、アップロードに失敗しました。';
        } else {
            $tmp_path = $_FILES['sa_json_file']['tmp_name'];
            $raw_json = @file_get_contents($tmp_path);
            $parsed = json_decode($raw_json, true);

            if (!is_array($parsed) || empty($parsed['client_email']) || empty($parsed['private_key'])) {
                $err_msg = '選択されたファイルは有効なGoogleサービスアカウントJSONキーではありません。(client_email または private_key が不足しています)';
            } else {
                $target_dir = __DIR__ . '/config';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }
                $dest_file = $target_dir . '/google_service_account.json';
                if (@file_put_contents($dest_file, $raw_json) !== false) {
                    $msg = "✅ サービスアカウントJSONキーを設定しました！（アカウント: {$parsed['client_email']}）";
                } else {
                    $err_msg = '設定ファイルの保存に失敗しました。configディレクトリの書き込み権限をご確認ください。';
                }
            }
        }
    }
}

// データ取得（部署マスタ・スタッフ一覧）
$departments = $pdo->query("SELECT * FROM target_departments WHERE is_active = TRUE ORDER BY display_order")->fetchAll();

$sql_staff = "SELECT s.*, d.dept_name 
              FROM staff s 
              LEFT JOIN target_departments d ON s.dept_id = d.dept_id 
              WHERE s.is_deleted = FALSE 
              ORDER BY s.kana ASC";
$staff_list = $pdo->query($sql_staff)->fetchAll();

// Googleカレンダー設定情報・チャンネル一覧
$sa_info = get_google_service_account_info();
$gcal_channels = $pdo->query("SELECT * FROM google_calendar_channels ORDER BY display_order ASC, channel_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$last_gcal_sync = $pdo->query("SELECT MAX(synced_at) FROM google_calendar_events_cache")->fetchColumn();
$gcal_event_count = $pdo->query("SELECT COUNT(*) FROM google_calendar_events_cache")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>マスタメンテナンス | 院内かわら版</title>
    <style>
        :root {
            --primary-color: #005a9c;
            --primary-light: #e6f0fa;
            --gcal-blue: #1a73e8;
            --bg-color: #f4f6f9;
            --text-color: #333;
            --border-color: #dee2e6;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 20px; }
        .container { max-width: 1060px; margin: 0 auto; background: #fff; padding: 24px 28px; border-radius: 10px; box-shadow: 0 3px 12px rgba(0,0,0,0.07); }
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary-color); padding-bottom: 12px; margin-bottom: 18px; }
        h1 { font-size: 1.35rem; color: var(--primary-color); margin: 0; display: flex; align-items: center; gap: 8px; }
        .btn-back { background: #6c757d; color: #fff; text-decoration: none; padding: 7px 15px; border-radius: 5px; font-weight: bold; font-size: 0.85rem; transition: background 0.2s; }
        .btn-back:hover { background: #5a6268; }

        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.92rem; font-weight: bold; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.92rem; font-weight: bold; }

        /* タブナビゲーション */
        .tab-nav { display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; }
        .tab-btn {
            background: none; border: none; padding: 10px 20px; font-size: 0.95rem; font-weight: bold; color: #64748b;
            cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 6px;
        }
        .tab-btn:hover { color: var(--primary-color); background: #f8fafc; border-radius: 6px 6px 0 0; }
        .tab-btn.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.2s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        /* フォームエリア */
        .form-card { background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px 20px; border-radius: 8px; margin-bottom: 24px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02); }
        .form-title { font-weight: bold; color: #1e293b; font-size: 1.05rem; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: bold; color: #475569; margin-bottom: 4px; }
        .form-control { width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 5px; font-size: 0.9rem; box-sizing: border-box; transition: border-color 0.15s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(0, 90, 156, 0.15); }
        
        .btn-submit { background: var(--primary-color); color: white; border: none; padding: 8px 20px; border-radius: 5px; font-weight: bold; font-size: 0.9rem; cursor: pointer; transition: opacity 0.2s; }
        .btn-submit:hover { opacity: 0.9; }
        .btn-cancel { background: #64748b; color: white; border: none; padding: 8px 16px; border-radius: 5px; font-weight: bold; font-size: 0.9rem; cursor: pointer; margin-left: 8px; }
        .btn-cancel:hover { background: #475569; }

        /* テーブル共通 */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.88rem; }
        th, td { border: 1px solid var(--border-color); padding: 9px 12px; text-align: left; vertical-align: middle; }
        th { background: #f8fafc; color: #475569; font-weight: bold; font-size: 0.82rem; }
        tr:nth-child(even) { background: #fcfcfc; }
        tr:hover { background: #f1f5f9; }

        .badge-line { padding: 3px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: bold; }
        .badge-line.is-set { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .badge-line.is-empty { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }

        .btn-edit { background: #e67e22; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 0.78rem; cursor: pointer; }
        .btn-edit:hover { background: #d35400; }
        .btn-del { background: #dc3545; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 0.78rem; cursor: pointer; }
        .btn-del:hover { background: #c82333; }
        .btn-test { background: #0d6efd; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 0.78rem; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-test:hover { background: #0b5ed7; }

        /* Googleカレンダー設定専用スタイル */
        .sa-status-card {
            background: #f0f7ff; border: 1px solid #b8daff; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px;
        }
        .sa-status-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .sa-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; }
        .sa-badge.ok { background: #d1e7dd; color: #0f5132; }
        .sa-badge.ng { background: #f8d7da; color: #842029; }
        .sa-email-box {
            display: flex; align-items: center; gap: 10px; background: #fff; border: 1px dashed #90caf9; padding: 8px 12px; border-radius: 6px; font-family: monospace; font-size: 0.9rem; word-break: break-all;
        }
        .btn-copy { background: #1a73e8; color: #fff; border: none; padding: 5px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; cursor: pointer; white-space: nowrap; }
        .btn-copy:hover { background: #1557b0; }
        .sa-help-text { font-size: 0.82rem; color: #475569; margin-top: 8px; line-height: 1.5; }
        .sa-help-text ol { margin: 6px 0 0 18px; padding: 0; }

        .color-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
        .sync-bar {
            display: flex; justify-content: space-between; align-items: center; background: #e8f0fe; border: 1px solid #d2e3fc; padding: 12px 18px; border-radius: 8px; margin-bottom: 18px;
        }
        .btn-sync-all {
            background: #1a73e8; color: #fff; border: none; padding: 8px 18px; border-radius: 5px; font-weight: bold; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-sync-all:hover { background: #1557b0; }
        .test-result-box { display: none; margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 0.85rem; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>⚙️ システムマスタ管理</h1>
        <a href="index.php" class="btn-back">← かわら版へ戻る</a>
    </header>

    <?php if ($msg): ?><div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err_msg): ?><div class="alert-error"><?= htmlspecialchars($err_msg) ?></div><?php endif; ?>

    <!-- タブ切り替えボタン -->
    <div class="tab-nav">
        <button type="button" class="tab-btn <?= $active_tab === 'staff' ? 'active' : '' ?>" onclick="switchTab('staff')">
            👥 スタッフマスタ管理
        </button>
        <button type="button" class="tab-btn <?= $active_tab === 'gcal' ? 'active' : '' ?>" onclick="switchTab('gcal')">
            📅 事務長Googleカレンダー連携設定
            <?php if (!empty($gcal_channels)): ?>
                <span style="background:#1a73e8; color:#fff; font-size:0.72rem; padding:1px 6px; border-radius:10px;"><?= count($gcal_channels) ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ==================== タブ1: スタッフマスタ管理 ==================== -->
    <div id="tab-staff" class="tab-content <?= $active_tab === 'staff' ? 'active' : '' ?>">
        <!-- スタッフ登録・編集フォーム -->
        <div class="form-card">
            <div class="form-title" id="formTitle">👤 スタッフの新規登録</div>
            <form method="POST" id="staffForm">
                <input type="hidden" name="action" value="save_staff">
                <input type="hidden" name="staff_id" id="f_staff_id" value="0">

                <div class="form-grid">
                    <div class="form-group">
                        <label>氏名 <span style="color:red;">*</span></label>
                        <input type="text" name="staff_name" id="f_staff_name" class="form-control" required placeholder="例: 山本 太郎">
                    </div>
                    <div class="form-group">
                        <label>ふりがな</label>
                        <input type="text" name="kana" id="f_kana" class="form-control" placeholder="例: やまもと たろう">
                    </div>
                    <div class="form-group">
                        <label>50音行</label>
                        <select name="kana_row" id="f_kana_row" class="form-control">
                            <?php foreach (['あ','か','さ','た','な','は','ま','や','ら','わ'] as $row): ?>
                                <option value="<?= $row ?>"><?= $row ?>行</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>所属部署</label>
                        <select name="dept_id" id="f_dept_id" class="form-control">
                            <?php foreach ($departments as $d): if ($d['dept_code'] === 'all') continue; ?>
                                <option value="<?= $d['dept_id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>職種・役職</label>
                        <input type="text" name="role" id="f_role" class="form-control" placeholder="例: 事務員, 医師, 看護師">
                    </div>
                    <div class="form-group">
                        <label>LINE ユーザーID（Messaging API用）</label>
                        <input type="text" name="line_user_id" id="f_line_user_id" class="form-control" placeholder="例: U1234567890abcdef1234567890abcdef" maxlength="50">
                        <small style="font-size:0.7rem; color:#666;">※先頭Uの33桁文字列</small>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label style="cursor:pointer; font-weight:bold; font-size:0.88rem; color:#004085;">
                        <input type="checkbox" name="is_admin" id="f_is_admin" value="1"> 🛡️ システム管理者権限を付与する
                    </label>
                    <div>
                        <button type="submit" class="btn-submit" id="btnSubmit">登録する</button>
                        <button type="button" class="btn-cancel" id="btnReset" onclick="resetForm()" style="display:none;">キャンセル</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- スタッフ一覧テーブル -->
        <div style="font-weight:bold; margin-bottom:8px; color:#005a9c;">📋 登録済みスタッフ一覧 (<?= count($staff_list) ?>名)</div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>氏名</th>
                    <th>所属部署</th>
                    <th>職種</th>
                    <th>LINE ID 設定状況</th>
                    <th>権限</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff_list as $st): 
                    $has_line = !empty($st['line_user_id']);
                ?>
                    <tr>
                        <td><?= $st['staff_id'] ?></td>
                        <td><b><?= htmlspecialchars($st['staff_name']) ?></b> <small style="color:#777;">(<?= htmlspecialchars($st['kana'] ?? '') ?>)</small></td>
                        <td><?= htmlspecialchars($st['dept_name'] ?? '未設定') ?></td>
                        <td><?= htmlspecialchars($st['role'] ?? '-') ?></td>
                        <td>
                            <?php if ($has_line): ?>
                                <span class="badge-line is-set" title="<?= htmlspecialchars($st['line_user_id']) ?>">🟢 登録済</span>
                                <small style="font-size:0.7rem; color:#666; display:block; font-family:monospace;"><?= htmlspecialchars(substr($st['line_user_id'], 0, 10)) ?>...</small>
                            <?php else: ?>
                                <span class="badge-line is-empty">🔴 未登録</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $st['is_admin'] ? '🛡️ 管理者' : '一般' ?></td>
                        <td>
                            <button type="button" class="btn-edit" onclick='editStaff(<?= json_encode($st, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>✏️ 編集</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= htmlspecialchars($st['staff_name']) ?> さんを削除（無効化）してもよろしいですか？');">
                                <input type="hidden" name="action" value="delete_staff">
                                <input type="hidden" name="staff_id" value="<?= $st['staff_id'] ?>">
                                <button type="submit" class="btn-del">🗑️ 削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ==================== タブ2: Googleカレンダー連携設定 ==================== -->
    <div id="tab-gcal" class="tab-content <?= $active_tab === 'gcal' ? 'active' : '' ?>">
        
        <!-- サービスアカウント接続状況カード -->
        <div class="sa-status-card">
            <div class="sa-status-header">
                <span style="font-weight:bold; color:#1a73e8; font-size:1rem; display:flex; align-items:center; gap:6px;">
                    🔑 Googleサービスアカウント認証状態
                </span>
                <?php if ($sa_info['is_installed']): ?>
                    <span class="sa-badge ok">🟢 JSONキー設定済み</span>
                <?php else: ?>
                    <span class="sa-badge ng">🔴 未設定 / エラー</span>
                <?php endif; ?>
            </div>

            <?php if ($sa_info['is_installed']): ?>
                <div style="font-size:0.85rem; font-weight:bold; color:#475569; margin-bottom:4px;">
                    共有用サービスアカウント メールアドレス:
                </div>
                <div class="sa-email-box">
                    <span id="saEmailText" style="flex:1;"><?= htmlspecialchars($sa_info['client_email']) ?></span>
                    <button type="button" class="btn-copy" onclick="copySaEmail()">📋 コピー</button>
                </div>
                <div class="sa-help-text">
                    <b>💡 Googleカレンダー共有手順:</b>
                    <ol>
                        <li>Googleカレンダー（PC）を開き、連携したいカレンダーの「︙」メニュー →「設定と共有」を開きます。</li>
                        <li>「特定のユーザーまたはグループとの共有」で「＋ユーザーを追加」をクリックします。</li>
                        <li>上のメールアドレスを貼り付け、権限「<b>予定の表示（すべての予定の詳細）</b>」を選択して送信します。</li>
                        <li>下のフォームにそのカレンダーID（通常はご自身のGmailアドレス）を登録し、「🔌 接続テスト」を押して確認完了です。</li>
                    </ol>
                </div>
            <?php else: ?>
                <div style="color:#d9534f; font-size:0.88rem; font-weight:bold; margin-top:6px;">
                    <?= htmlspecialchars($sa_info['error']) ?>
                </div>
                <div class="sa-help-text">
                    Google Cloud Console でサービスアカウントを作成し、JSONキーファイルを下のフォームからアップロードしてください。
                </div>
            <?php endif; ?>

            <!-- JSONキーのアップロードフォーム -->
            <div style="margin-top:14px; padding-top:12px; border-top:1px dashed #b8daff;">
                <form method="POST" enctype="multipart/form-data" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="upload_sa_json">
                    <span style="font-size:0.85rem; font-weight:bold; color:#1a73e8;">📥 JSONキーの登録・更新:</span>
                    <input type="file" name="sa_json_file" accept=".json" required style="font-size:0.82rem;">
                    <button type="submit" class="btn-submit" style="background:#1a73e8; padding:5px 14px; font-size:0.82rem;">アップロードして設定</button>
                </form>
                <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">
                    ※Google Cloud Consoleからダウンロードした秘密鍵JSONファイルをそのまま選択してアップロードできます（自動的に <code>config/google_service_account.json</code> として安全に配置されます）。
                </div>
            </div>
        </div>

        <!-- 全体手動同期バー -->
        <div class="sync-bar">
            <div>
                <span style="font-weight:bold; color:#1a73e8;">🔄 キャッシュ同期状態:</span>
                <span style="font-size:0.88rem; color:#475569; margin-left:8px;">
                    最終同期: <?= $last_gcal_sync ? htmlspecialchars(date('Y/m/d H:i:s', strtotime($last_gcal_sync))) : '未実行' ?>
                    （保持イベント: <?= (int)$gcal_event_count ?>件）
                </span>
            </div>
            <button type="button" class="btn-sync-all" id="btnSyncAll" onclick="syncAllCalendars()">
                <span>🔄</span> 今すぐGoogleと同期
            </button>
        </div>

        <!-- カレンダー登録・編集フォーム -->
        <div class="form-card">
            <div class="form-title" id="gcalFormTitle">➕ Googleカレンダーの追加</div>
            <form method="POST" id="gcalForm">
                <input type="hidden" name="action" value="save_gcal_channel">
                <input type="hidden" name="channel_id" id="f_gcal_channel_id" value="0">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Googleアカウント識別名</label>
                        <input type="text" name="account_name" id="f_gcal_account_name" class="form-control" placeholder="例: 事務長（個人用）" required>
                    </div>
                    <div class="form-group">
                        <label>Google カレンダーID <span style="color:red;">*</span></label>
                        <input type="text" name="calendar_id" id="f_gcal_calendar_id" class="form-control" placeholder="例: yamamoto@gmail.com または primary" required>
                        <small style="font-size:0.7rem; color:#666;">※メインカレンダーはGmailアドレス、共有カレンダーはカレンダーID</small>
                    </div>
                    <div class="form-group">
                        <label>表示名 <span style="color:red;">*</span></label>
                        <input type="text" name="calendar_name" id="f_gcal_calendar_name" class="form-control" placeholder="例: 事務長 予定" required>
                    </div>
                    <div class="form-group">
                        <label>テーマカラー</label>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="color" name="color_theme" id="f_gcal_color_theme" value="#1a73e8" style="width:40px; height:34px; padding:2px; border:1px solid #ccc; border-radius:4px; cursor:pointer;">
                            <select id="f_gcal_color_preset" class="form-control" style="flex:1;" onchange="document.getElementById('f_gcal_color_theme').value = this.value;">
                                <option value="#1a73e8">Googleブルー (#1a73e8)</option>
                                <option value="#0f9d58">エメラルドグリーン (#0f9d58)</option>
                                <option value="#ea4335">バーミリオンレッド (#ea4335)</option>
                                <option value="#f29900">アンバーオレンジ (#f29900)</option>
                                <option value="#8e24aa">アメジストパープル (#8e24aa)</option>
                                <option value="#00897b">ティールブルー (#00897b)</option>
                                <option value="#e91e63">ローズピンク (#e91e63)</option>
                                <option value="#546e7a">スレートグレー (#546e7a)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>表示順</label>
                        <input type="number" name="display_order" id="f_gcal_display_order" class="form-control" value="10" min="1" max="999">
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label style="cursor:pointer; font-weight:bold; font-size:0.88rem; color:#1a73e8;">
                        <input type="checkbox" name="is_enabled" id="f_gcal_is_enabled" value="1" checked> 🟢 カレンダー連携を有効にする
                    </label>
                    <div>
                        <button type="submit" class="btn-submit" id="btnGcalSubmit" style="background:#1a73e8;">カレンダーを登録する</button>
                        <button type="button" class="btn-cancel" id="btnGcalReset" onclick="resetGcalForm()" style="display:none;">キャンセル</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- 登録済みカレンダー一覧 -->
        <div style="font-weight:bold; margin-bottom:8px; color:#1a73e8;">📋 登録済みGoogleカレンダー一覧 (<?= count($gcal_channels) ?>件)</div>
        <div id="testResultBox" class="test-result-box"></div>
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">順序</th>
                    <th>アカウント名</th>
                    <th>表示名 (カラー)</th>
                    <th>カレンダーID</th>
                    <th style="width:80px; text-align:center;">状態</th>
                    <th style="width:230px; text-align:center;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($gcal_channels)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:#888; padding:20px;">
                            登録されているGoogleカレンダーはありません。上のフォームから追加してください。
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gcal_channels as $ch): ?>
                        <tr>
                            <td style="text-align:center; font-weight:bold; color:#666;"><?= (int)$ch['display_order'] ?></td>
                            <td><b><?= htmlspecialchars($ch['account_name'] ?: '（共通）') ?></b></td>
                            <td>
                                <span class="color-dot" style="background:<?= htmlspecialchars($ch['color_theme']) ?>;"></span>
                                <b><?= htmlspecialchars($ch['calendar_name']) ?></b>
                            </td>
                            <td style="font-family:monospace; font-size:0.84rem;"><?= htmlspecialchars($ch['calendar_id']) ?></td>
                            <td style="text-align:center;">
                                <?php if ($ch['is_enabled']): ?>
                                    <span style="color:#0f5132; background:#d1e7dd; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:bold;">有効</span>
                                <?php else: ?>
                                    <span style="color:#666; background:#e2e8f0; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:bold;">無効</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="btn-test" id="btnTest_<?= $ch['channel_id'] ?>" onclick="testConnection('<?= htmlspecialchars(addslashes($ch['calendar_id'])) ?>', <?= $ch['channel_id'] ?>)">
                                    🔌 接続テスト
                                </button>
                                <button type="button" class="btn-edit" onclick='editGcalChannel(<?= json_encode($ch, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    ✏️ 編集
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Googleカレンダー「<?= htmlspecialchars($ch['calendar_name']) ?>」の登録を削除してもよろしいですか？（キャッシュ予定も削除されます）');">
                                    <input type="hidden" name="action" value="delete_gcal_channel">
                                    <input type="hidden" name="channel_id" value="<?= $ch['channel_id'] ?>">
                                    <button type="submit" class="btn-del">🗑️ 削除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
// タブ切り替え
function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    if (tabId === 'gcal') {
        document.querySelectorAll('.tab-btn')[1].classList.add('active');
        document.getElementById('tab-gcal').classList.add('active');
        history.replaceState(null, '', '?tab=gcal');
    } else {
        document.querySelectorAll('.tab-btn')[0].classList.add('active');
        document.getElementById('tab-staff').classList.add('active');
        history.replaceState(null, '', '?tab=staff');
    }
}

// スタッフ編集
function editStaff(st) {
    document.getElementById('formTitle').textContent = `✏️ スタッフ情報の編集 (ID: ${st.staff_id})`;
    document.getElementById('f_staff_id').value = st.staff_id;
    document.getElementById('f_staff_name').value = st.staff_name || '';
    document.getElementById('f_kana').value = st.kana || '';
    document.getElementById('f_kana_row').value = st.kana_row || 'あ';
    document.getElementById('f_dept_id').value = st.dept_id || '';
    document.getElementById('f_role').value = st.role || '';
    document.getElementById('f_line_user_id').value = st.line_user_id || '';
    document.getElementById('f_is_admin').checked = (st.is_admin == true || st.is_admin == "1");

    document.getElementById('btnSubmit').textContent = '更新保存する';
    document.getElementById('btnReset').style.display = 'inline-block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('formTitle').textContent = '👤 スタッフの新規登録';
    document.getElementById('f_staff_id').value = '0';
    document.getElementById('staffForm').reset();
    document.getElementById('btnSubmit').textContent = '登録する';
    document.getElementById('btnReset').style.display = 'none';
}

// Googleカレンダー編集
function editGcalChannel(ch) {
    document.getElementById('gcalFormTitle').textContent = `✏️ Googleカレンダー設定の編集: ${ch.calendar_name}`;
    document.getElementById('f_gcal_channel_id').value = ch.channel_id;
    document.getElementById('f_gcal_account_name').value = ch.account_name || '';
    document.getElementById('f_gcal_calendar_id').value = ch.calendar_id || '';
    document.getElementById('f_gcal_calendar_name').value = ch.calendar_name || '';
    document.getElementById('f_gcal_color_theme').value = ch.color_theme || '#1a73e8';
    document.getElementById('f_gcal_display_order').value = ch.display_order || 10;
    document.getElementById('f_gcal_is_enabled').checked = (ch.is_enabled == true || ch.is_enabled == "1");

    document.getElementById('btnGcalSubmit').textContent = '更新保存する';
    document.getElementById('btnGcalReset').style.display = 'inline-block';

    const card = document.querySelector('#tab-gcal .form-card');
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetGcalForm() {
    document.getElementById('gcalFormTitle').textContent = '➕ Googleカレンダーの追加';
    document.getElementById('f_gcal_channel_id').value = '0';
    document.getElementById('gcalForm').reset();
    document.getElementById('f_gcal_color_theme').value = '#1a73e8';
    document.getElementById('btnGcalSubmit').textContent = 'カレンダーを登録する';
    document.getElementById('btnGcalReset').style.display = 'none';
}

// サービスアカウントメアドのコピー
function copySaEmail() {
    const email = document.getElementById('saEmailText').textContent.trim();
    if (!email) return;
    navigator.clipboard.writeText(email).then(() => {
        alert('📋 サービスアカウントのメールアドレスをコピーしました！\nGoogleカレンダーの「特定のユーザーとの共有」に貼り付けてください。');
    }).catch(() => {
        prompt('以下のメールアドレスをコピーしてください:', email);
    });
}

// 接続テスト
function testConnection(calendarId, channelId) {
    const btn = document.getElementById(`btnTest_${channelId}`);
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ テスト中...';
    btn.disabled = true;

    const resBox = document.getElementById('testResultBox');
    resBox.style.display = 'none';

    fetch(`master_mente.php?ajax=1&action=test_gcal_connection&calendar_id=${encodeURIComponent(calendarId)}`)
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            resBox.style.display = 'block';
            if (data.success) {
                resBox.style.background = '#d1e7dd';
                resBox.style.color = '#0f5132';
                resBox.style.border = '1px solid #badbcc';
                resBox.innerHTML = `✅ ${data.message}`;
            } else {
                resBox.style.background = '#f8d7da';
                resBox.style.color = '#842029';
                resBox.style.border = '1px solid #f5c2c7';
                resBox.innerHTML = `❌ 接続失敗: ${data.message}`;
            }
            resBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })
        .catch(err => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            resBox.style.display = 'block';
            resBox.style.background = '#f8d7da';
            resBox.style.color = '#842029';
            resBox.style.border = '1px solid #f5c2c7';
            resBox.innerHTML = `❌ 通信エラーが発生しました: ${err.message}`;
        });
}

// 全カレンダー同期
function syncAllCalendars() {
    const btn = document.getElementById('btnSyncAll');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Googleと同期中...';
    btn.disabled = true;

    fetch(`master_mente.php?ajax=1&action=sync_gcal_all`)
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            if (data.success) {
                alert(`✅ 同期完了: ${data.message}`);
                location.reload();
            } else {
                const errs = data.errors ? data.errors.join('\n') : (data.message || 'エラーが発生しました');
                alert(`⚠️ 一部または全ての同期に失敗しました:\n${errs}`);
            }
        })
        .catch(err => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            alert(`❌ 通信エラー: ${err.message}`);
        });
}
</script>

</body>
</html>