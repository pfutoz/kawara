<?php
session_start();

// 30分タイムアウト
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset(); session_destroy();
    header("Location: login.php?reason=timeout"); exit;
}
$_SESSION['last_activity'] = time();

// DB接続
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

$message = '';
$error = '';

// 保存・更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 新規登録 or 編集更新
    if ($action === 'save') {
        $cat_id        = (int)($_POST['category_id'] ?? 0);
        $cat_code      = trim($_POST['category_code'] ?? '');
        $cat_name      = trim($_POST['category_name'] ?? '');
        $icon_emoji    = trim($_POST['icon_emoji'] ?? '💬');
        $color_code    = trim($_POST['color_code'] ?? '#005a9c');
        $display_order = (int)($_POST['display_order'] ?? 10);
        $is_active     = isset($_POST['is_active']) ? 'true' : 'false';

        if ($cat_code !== '' && $cat_name !== '') {
            try {
                if ($cat_id > 0) {
                    // UPDATE
                    $sql = "UPDATE post_categories SET 
                                category_code = :code, category_name = :name, 
                                icon_emoji = :icon, color_code = :color, 
                                display_order = :order, is_active = :active
                            WHERE category_id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':code' => $cat_code, ':name' => $cat_name, ':icon' => $icon_emoji,
                        ':color' => $color_code, ':order' => $display_order, ':active' => $is_active,
                        ':id' => $cat_id
                    ]);
                    $message = 'カテゴリー情報を更新しました。';
                } else {
                    // INSERT
                    $sql = "INSERT INTO post_categories (category_code, category_name, icon_emoji, color_code, display_order, is_active)
                            VALUES (:code, :name, :icon, :color, :order, :active)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':code' => $cat_code, ':name' => $cat_name, ':icon' => $icon_emoji,
                        ':color' => $color_code, ':order' => $display_order, ':active' => $is_active
                    ]);
                    $message = '新規カテゴリーを追加しました。';
                }
            } catch (Exception $e) {
                $error = '保存に失敗しました（コードの重複等の可能性があります）: ' . $e->getMessage();
            }
        } else {
            $error = '識別コードとカテゴリー名は必須入力です。';
        }
    }
}

// 編集データ取得（GETで edit_id が指定された場合）
$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_data = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM post_categories WHERE category_id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_data = $stmt->fetch();
}

// 全カテゴリーマスターデータ取得
$categories = $pdo->query("SELECT * FROM post_categories ORDER BY display_order ASC, category_id ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>区分・カテゴリーマスター管理 | 院内かわら版</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        
        .header-bar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #005a9c; padding-bottom: 10px; margin-bottom: 20px; }
        h1 { font-size: 1.25rem; color: #005a9c; margin: 0; }

        .msg-success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .msg-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }

        /* フォームエリア */
        .form-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin-bottom: 25px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 12px; }
        
        .form-group label { display: block; font-weight: bold; font-size: 0.82rem; color: #444; margin-bottom: 4px; }
        .form-control { width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; box-sizing: border-box; }
        
        .btn-submit { background: #28a745; color: white; border: none; padding: 8px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: #218838; }
        .btn-cancel { background: #6c757d; color: white; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-size: 0.85rem; font-weight: bold; }

        /* テーブル一覧 */
        .cat-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .cat-table th { background: #eef2f5; border: 1px solid #dee2e6; padding: 8px 10px; text-align: left; font-size: 0.85rem; color: #495057; }
        .cat-table td { border: 1px solid #dee2e6; padding: 8px 10px; font-size: 0.88rem; }
        .cat-table tr.disabled { background: #f1f1f1; color: #888; }

        .badge-color { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: middle; margin-right: 5px; }
        .btn-edit { color: #005a9c; text-decoration: none; font-weight: bold; }
        .btn-edit:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <h1>🏷 区分・カテゴリーマスター管理</h1>
        <a href="master_mente.php" style="color: #666; text-decoration: none; font-weight: bold;">← マスターメニューへ</a>
    </div>

    <?php if ($message): ?><div class="msg-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- 入力・編集フォーム -->
    <div class="form-card">
        <h3 style="margin-top:0; font-size:1rem; color:#005a9c;">
            <?= $edit_data ? '✏️ カテゴリーの編集' : '➕ 新規カテゴリーの追加' ?>
        </h3>
        
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="category_id" value="<?= $edit_data['category_id'] ?? 0 ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label>識別コード (英数)</label>
                    <input type="text" name="category_code" class="form-control" required placeholder="例: notice" value="<?= htmlspecialchars($edit_data['category_code'] ?? '') ?>">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>カテゴリー表示名</label>
                    <input type="text" name="category_name" class="form-control" required placeholder="例: 連絡・アナウンス" value="<?= htmlspecialchars($edit_data['category_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>アイコン (絵文字)</label>
                    <input type="text" name="icon_emoji" class="form-control" style="width:80px; text-align:center;" value="<?= htmlspecialchars($edit_data['icon_emoji'] ?? '💬') ?>">
                </div>

                <div class="form-group">
                    <label>テーマカラー</label>
                    <input type="color" name="color_code" class="form-control" style="height:35px; padding:2px;" value="<?= htmlspecialchars($edit_data['color_code'] ?? '#005a9c') ?>">
                </div>

                <div class="form-group">
                    <label>表示順 (昇順)</label>
                    <input type="number" name="display_order" class="form-control" value="<?= htmlspecialchars($edit_data['display_order'] ?? '10') ?>">
                </div>

                <div class="form-group" style="align-self: flex-end; padding-bottom: 8px;">
                    <label style="cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                        <input type="checkbox" name="is_active" value="1" <?= ($edit_data['is_active'] ?? true) ? 'checked' : '' ?>>
                        有効にする
                    </label>
                </div>
            </div>

            <div style="display:flex; gap:10px; align-items:center;">
                <button type="submit" class="btn-submit"><?= $edit_data ? '更新保存する' : '追加登録する' ?></button>
                <?php if ($edit_data): ?>
                    <a href="category_mente.php" class="btn-cancel">キャンセル</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- 既存カテゴリー一覧 -->
    <h3 style="font-size:1rem; color:#444; margin-bottom:8px;">登録済みカテゴリー一覧</h3>
    <table class="cat-table">
        <thead>
            <tr>
                <th style="width: 60px;">順序</th>
                <th style="width: 50px; text-align:center;">アイコン</th>
                <th>カテゴリー名</th>
                <th>識別コード</th>
                <th style="width: 90px;">カラー</th>
                <th style="width: 70px; text-align:center;">状態</th>
                <th style="width: 60px; text-align:center;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $cat): ?>
                <tr class="<?= $cat['is_active'] ? '' : 'disabled' ?>">
                    <td style="text-align:center; font-weight:bold;"><?= $cat['display_order'] ?></td>
                    <td style="text-align:center; font-size:1.2rem;"><?= $cat['icon_emoji'] ?></td>
                    <td style="font-weight:bold;"><?= htmlspecialchars($cat['category_name']) ?></td>
                    <td><code><?= htmlspecialchars($cat['category_code']) ?></code></td>
                    <td>
                        <span class="badge-color" style="background-color: <?= htmlspecialchars($cat['color_code']) ?>;"></span>
                        <small><?= htmlspecialchars($cat['color_code']) ?></small>
                    </td>
                    <td style="text-align:center;">
                        <?= $cat['is_active'] ? '<span style="color:#28a745; font-weight:bold;">有効</span>' : '<span style="color:#888;">無効</span>' ?>
                    </td>
                    <td style="text-align:center;">
                        <a href="category_mente.php?edit_id=<?= $cat['category_id'] ?>" class="btn-edit">編集</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>