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

$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) { exit('DB接続エラー: ' . $e->getMessage()); }

// ログインユーザー＆管理者権限チェック
$current_staff_id = (int)$_SESSION['staff_id'];
$stmt_user = $pdo->prepare("SELECT staff_id, staff_name, is_admin FROM staff WHERE staff_id = :id");
$stmt_user->execute([':id' => $current_staff_id]);
$login_user = $stmt_user->fetch();

if (!$login_user || !$login_user['is_admin']) {
    exit('管理者権限が必要です。');
}

$msg = '';
$err_msg = '';

// POST処理（スタッフ登録・編集・削除）
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
    }

    if ($action === 'delete_staff') {
        $st_id = (int)($_POST['staff_id'] ?? 0);
        if ($st_id > 0) {
            // 論理削除 (is_deleted = TRUE)
            $stmt_d = $pdo->prepare("UPDATE staff SET is_deleted = TRUE WHERE staff_id = :id");
            $stmt_d->execute([':id' => $st_id]);
            $msg = 'スタッフを削除（無効化）しました。';
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
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>マスタメンテナンス | 院内かわら版</title>
    <style>
        :root { --primary-color: #005a9c; --bg-color: #f4f6f9; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-color); color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 20px 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 20px; }
        h1 { font-size: 1.3rem; color: var(--primary-color); margin: 0; }
        .btn-back { background: #6c757d; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-weight: bold; font-size: 0.85rem; }

        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 0.9rem; font-weight: bold; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 0.9rem; font-weight: bold; }

        /* フォームエリア */
        .form-card { background: #eef6fc; border: 1px solid #b8daff; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .form-title { font-weight: bold; color: #004085; font-size: 1rem; margin-bottom: 10px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: bold; color: #444; margin-bottom: 3px; }
        .form-control { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; box-sizing: border-box; }
        
        .btn-submit { background: var(--primary-color); color: white; border: none; padding: 8px 18px; border-radius: 4px; font-weight: bold; font-size: 0.9rem; cursor: pointer; }
        .btn-cancel { background: #6c757d; color: white; border: none; padding: 8px 14px; border-radius: 4px; font-weight: bold; font-size: 0.9rem; cursor: pointer; margin-left: 8px; }

        /* スタッフテーブル */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.88rem; }
        th, td { border: 1px solid #dee2e6; padding: 8px 10px; text-align: left; }
        th { background: #f1f3f5; color: #495057; font-weight: bold; }
        tr:nth-child(even) { background: #f8f9fa; }

        .badge-line { padding: 2px 6px; border-radius: 4px; font-size: 0.72rem; font-weight: bold; }
        .badge-line.is-set { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .badge-line.is-empty { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }

        .btn-edit { background: #e67e22; color: #fff; border: none; padding: 3px 8px; border-radius: 3px; font-weight: bold; font-size: 0.78rem; cursor: pointer; }
        .btn-del { background: #dc3545; color: #fff; border: none; padding: 3px 8px; border-radius: 3px; font-weight: bold; font-size: 0.78rem; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>⚙️ スタッフマスタ管理</h1>
        <a href="index.php" class="btn-back">← かわら版へ戻る</a>
    </header>

    <?php if ($msg): ?><div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err_msg): ?><div class="alert-error"><?= htmlspecialchars($err_msg) ?></div><?php endif; ?>

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

<script>
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
</script>

</body>
</html>