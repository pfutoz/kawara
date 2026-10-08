<?php
// DB接続共通モジュール読み込み
require_once __DIR__ . '/includes/db.php';

$message = '';

// --------------------------------------------------
// 1. フォーム送信処理（登録・更新）
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $staff_id   = (int)$_POST['staff_id'];
        $staff_name = trim($_POST['staff_name']);
        $short_icon = trim($_POST['short_icon']);
        $kana       = trim($_POST['kana']);
        $kana_row   = trim($_POST['kana_row']);
        $role       = trim($_POST['role']);
        $dept_id    = (int)$_POST['dept_id'];
        $is_admin   = isset($_POST['is_admin']) ? 'true' : 'false';
        $is_deleted = isset($_POST['is_deleted']) ? 'true' : 'false';

        if ($staff_id > 0 && $staff_name !== '') {
            $sql = "INSERT INTO staff (staff_id, staff_name, short_icon, kana, kana_row, role, dept_id, is_admin, is_deleted, updated_at)
                    VALUES (:staff_id, :staff_name, :short_icon, :kana, :kana_row, :role, :dept_id, :is_admin, :is_deleted, NOW())
                    ON CONFLICT (staff_id) DO UPDATE SET
                        staff_name = EXCLUDED.staff_name,
                        short_icon = EXCLUDED.short_icon,
                        kana       = EXCLUDED.kana,
                        kana_row   = EXCLUDED.kana_row,
                        role       = EXCLUDED.role,
                        dept_id    = EXCLUDED.dept_id,
                        is_admin   = EXCLUDED.is_admin,
                        is_deleted = EXCLUDED.is_deleted,
                        updated_at = NOW()";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':staff_id'   => $staff_id,
                ':staff_name' => $staff_name,
                ':short_icon' => $short_icon,
                ':kana'       => $kana,
                ':kana_row'   => $kana_row,
                ':role'       => $role,
                ':dept_id'    => $dept_id,
                ':is_admin'   => $is_admin,
                ':is_deleted' => $is_deleted
            ]);
            $message = "スタッフ「{$staff_name}」の情報を保存しました。";
        }
    }
}

// --------------------------------------------------
// 2. 部署マスター & スタッフ一覧の取得
// --------------------------------------------------
$departments = $pdo->query("SELECT * FROM target_departments WHERE is_active = TRUE ORDER BY display_order")->fetchAll();

$sql_staff = "SELECT s.*, d.dept_name 
              FROM staff s 
              LEFT JOIN target_departments d ON s.dept_id = d.dept_id 
              ORDER BY s.is_deleted ASC, s.kana ASC";
$staff_list = $pdo->query($sql_staff)->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仮スタッフマスタ管理 | 院内かわら版</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { font-size: 1.3rem; border-left: 5px solid #005a9c; padding-left: 10px; color: #005a9c; margin-top: 0; }
        .msg { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        
        /* フォームレイアウト */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #ddd; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 0.8rem; font-weight: bold; margin-bottom: 4px; color: #555; }
        .form-group input, .form-group select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; }
        .btn-submit { background: #28a745; color: white; border: none; padding: 8px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; align-self: flex-end; height: 35px; }
        .btn-submit:hover { background: #218838; }
        
        /* 50音絞り込みバー */
        .filter-bar { display: flex; gap: 4px; align-items: center; margin: 20px 0 10px 0; flex-wrap: wrap; background: #eef2f5; padding: 8px 12px; border-radius: 6px; }
        .btn-row-filter {
            background: #fff; border: 1px solid #ced4da; padding: 4px 10px;
            border-radius: 4px; font-weight: bold; font-size: 0.85rem; cursor: pointer; color: #495057;
        }
        .btn-row-filter:hover { background: #e2e6ea; }
        .btn-row-filter.active { background: #005a9c; color: white; border-color: #005a9c; }

        /* テーブルスタイル */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.88rem; }
        th, td { border: 1px solid #dee2e6; padding: 8px 10px; text-align: left; }
        th { background: #e9ecef; color: #495057; }
        tr.deleted { background: #f8d7da; color: #721c24; }
        .badge-admin { font-size: 0.75rem; padding: 2px 6px; border-radius: 3px; background: #17a2b8; color: white; font-weight: bold; }
        .badge-user { font-size: 0.75rem; padding: 2px 6px; border-radius: 3px; background: #6c757d; color: white; }
        .btn-edit { background: #007bff; color: white; border: none; padding: 4px 10px; border-radius: 3px; cursor: pointer; font-size: 0.78rem; font-weight: bold; }
        .btn-edit:hover { background: #0069d9; }
    </style>
</head>
<body>

<div class="container">
    <h1>👥 仮スタッフマスタ管理（MALL連携代替）</h1>
    
    <?php if ($message): ?>
        <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- 編集・新規登録フォーム（Enter爆発防止スクリプト適用） -->
    <form method="POST" id="staffForm" onkeydown="return preventEnterSubmit(event);">
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="form-group">
                <label>ID (カルテID)</label>
                <input type="number" name="staff_id" id="f_staff_id" required placeholder="例: 15">
            </div>
            <div class="form-group">
                <label>氏名</label>
                <input type="text" name="staff_name" id="f_staff_name" required placeholder="山本 太">
            </div>
            <div class="form-group">
                <label>略称1文字</label>
                <input type="text" name="short_icon" id="f_short_icon" maxlength="2" required placeholder="太">
            </div>
            <div class="form-group">
                <label>フリガナ</label>
                <input type="text" name="kana" id="f_kana" placeholder="ヤマモト フトシ">
            </div>
            <div class="form-group">
                <label>50音行</label>
                <select name="kana_row" id="f_kana_row">
                    <option value="あ">あ行</option>
                    <option value="か">か行</option>
                    <option value="さ">さ行</option>
                    <option value="た">た行</option>
                    <option value="な">な行</option>
                    <option value="は">は行</option>
                    <option value="ま">ま行</option>
                    <option value="や">や行</option>
                    <option value="ら">ら行</option>
                    <option value="わ">わ行</option>
                </select>
            </div>
            <div class="form-group">
                <label>役職名</label>
                <input type="text" name="role" id="f_role" placeholder="事務">
            </div>
            <div class="form-group">
                <label>対象部署</label>
                <select name="dept_id" id="f_dept_id">
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['dept_id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex-direction:row; gap:12px; align-items:center; margin-top:18px;">
                <label style="cursor:pointer;"><input type="checkbox" name="is_admin" id="f_is_admin" value="1"> 管理者</label>
                <label style="cursor:pointer; color:#d9534f;"><input type="checkbox" name="is_deleted" id="f_is_deleted" value="1"> 無効/退職</label>
            </div>
            <button type="submit" class="btn-submit">保存する</button>
        </div>
    </form>

    <!-- 50音絞り込みボタン -->
    <div class="filter-bar">
        <span style="font-weight:bold; font-size:0.85rem; margin-right:8px; color:#333;">50音絞り込み:</span>
        <button type="button" class="btn-row-filter active" onclick="filterKanaRow('all')">全</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('あ')">あ</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('か')">か</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('さ')">さ</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('た')">た</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('な')">な</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('は')">は</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('ま')">ま</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('や')">や</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('ら')">ら</button>
        <button type="button" class="btn-row-filter" onclick="filterKanaRow('わ')">わ</button>
    </div>

    <!-- スタッフ一覧 -->
    <h3 style="margin-bottom:8px; font-size:1.05rem;">登録済みスタッフ一覧 (<?= count($staff_list) ?>名)</h3>
    <table>
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>氏名 (略称)</th>
                <th>フリガナ</th>
                <th>役職 / 対象部署</th>
                <th style="width:70px;">権限</th>
                <th style="width:80px;">状態</th>
                <th style="width:60px;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($staff_list as $s): ?>
                <tr class="<?= $s['is_deleted'] ? 'deleted' : '' ?>" data-kana-row="<?= htmlspecialchars($s['kana_row']) ?>">
                    <td><?= $s['staff_id'] ?></td>
                    <td><b><?= htmlspecialchars($s['staff_name']) ?></b> (<?= htmlspecialchars($s['short_icon']) ?>)</td>
                    <td><?= htmlspecialchars($s['kana']) ?></td>
                    <td>
                        <?= htmlspecialchars($s['role']) ?>
                        <div style="font-size:0.75rem; color:#666;">[<?= htmlspecialchars($s['dept_name'] ?? '未設定') ?>]</div>
                    </td>
                    <td><?= $s['is_admin'] ? '<span class="badge-admin">管理者</span>' : '<span class="badge-user">一般</span>' ?></td>
                    <td><?= $s['is_deleted'] ? '❌ 無効' : '🟢 有効' ?></td>
                    <td>
                        <button type="button" class="btn-edit" onclick="editStaff(<?= htmlspecialchars(json_encode($s)) ?>)">編集</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
// 1. Enterキーによる誤送信を全入力項目でブロック（日本語変換時のEnterは許可）
function preventEnterSubmit(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
        if (!e.isComposing) {
            e.preventDefault();
            return false;
        }
    }
}

// 2. 「あかさたな」行絞り込み機能
function filterKanaRow(row) {
    document.querySelectorAll('.btn-row-filter').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(tr => {
        const kanaRow = tr.getAttribute('data-kana-row');
        if (row === 'all' || kanaRow === row) {
            tr.style.display = '';
        } else {
            tr.style.display = 'none';
        }
    });
}

// 3. 一覧の「編集」ボタンを押したときにフォームへ値をセットする関数
function editStaff(s) {
    document.getElementById('f_staff_id').value = s.staff_id;
    document.getElementById('f_staff_name').value = s.staff_name;
    document.getElementById('f_short_icon').value = s.short_icon;
    document.getElementById('f_kana').value = s.kana;
    document.getElementById('f_kana_row').value = s.kana_row || 'あ';
    document.getElementById('f_role').value = s.role;
    document.getElementById('f_dept_id').value = s.dept_id || 1;
    document.getElementById('f_is_admin').checked = s.is_admin;
    document.getElementById('f_is_deleted').checked = s.is_deleted;
    
    // フォームへスムーズスクロール
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

</body>
</html>