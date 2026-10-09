<?php
// DB接続共通モジュール読み込み
require_once __DIR__ . '/includes/db.php';

// LINEヘルパーサブルーチンの読み込み
if (file_exists(__DIR__ . '/includes/line_helper.php')) {
    require_once __DIR__ . '/includes/line_helper.php';
}

$error = '';
$mode = $_POST['mode'] ?? 'create'; // create, update, delete
$post_id = (int)($_POST['post_id'] ?? 0);
$action = $_POST['action'] ?? 'confirm'; // confirm (表示), save (確定実行)
$send_line = isset($_POST['send_line']) && $_POST['send_line'] === '1';
$return_to = $_POST['return_to'] ?? '';

// 画像の一時保存ディレクトリ作成
$tmp_dir = __DIR__ . '/uploads/tmp/';
$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0777, true);
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

// --------------------------------------------------
// 1. 初回「確認画面」遷移時：アップロード画像を仮保存
// --------------------------------------------------
$tmp_images = [];

if (!empty($_POST['tmp_images_json'])) {
    $tmp_images = json_decode($_POST['tmp_images_json'], true) ?: [];
}

if ($action === 'confirm' && !empty($_FILES['images']['name'][0])) {
    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
            $orig_name = $_FILES['images']['name'][$key];
            $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
            $tmp_filename = 'tmp_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
            
            if (move_uploaded_file($tmp_name, $tmp_dir . $tmp_filename)) {
                $tmp_images[] = [
                    'tmp_name' => $tmp_filename,
                    'orig_name' => $orig_name,
                    'file_size' => $_FILES['images']['size'][$key]
                ];
            }
        }
    }
}

// --------------------------------------------------
// 2. 確定保存処理 (save)
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    try {
        $pdo->beginTransaction();

        // 削除処理
        if ($mode === 'delete' && $post_id > 0) {
            $pdo->prepare("DELETE FROM posts WHERE post_id = :post_id")->execute([':post_id' => $post_id]);
            $pdo->commit();
            $del_dest = ($return_to === 'jimucho' || $return_to === 'jimucho_dashboard.php') ? 'jimucho_dashboard.php?msg=deleted' : 'kawara_list.php?msg=deleted';
            header("Location: {$del_dest}");
            exit;
        }

        $title           = trim($_POST['title'] ?? '');
        $content         = trim($_POST['content'] ?? '');
        $category_id     = (int)($_POST['category_id'] ?? 1);
        $is_pinned       = ($_POST['is_pinned'] === '1') ? 'true' : 'false';
        
        // 🆕 display_until が空なら NULL をセット
        $display_until = (!empty($_POST['display_until'])) ? $_POST['display_until'] : null;
        
        $is_all_day      = isset($_POST['is_all_day']) && $_POST['is_all_day'] === '1';
        $event_date      = $_POST['event_date'] ?? null;
        $event_end_date  = $_POST['event_end_date'] ?? null;
        $start_time      = $_POST['start_time'] ?? '09:00';
        $end_time        = $_POST['end_time'] ?? '17:00';

        $target_datetime = null;
        $target_end_datetime = null;

        if ($event_date) {
            if ($is_all_day) {
                $target_datetime = $event_date . ' 00:00:00';
                $target_end_datetime = ($event_end_date ? $event_end_date : $event_date) . ' 23:59:59';
            } else {
                $target_datetime = $event_date . ' ' . $start_time . ':00';
                $target_end_datetime = $event_date . ' ' . $end_time . ':00';
            }
        }

        $event_schedules = $_POST['event_schedules'] ?? '[]';
        if (empty($event_schedules) || $event_schedules === 'null') {
            $event_schedules = '[]';
        }

        $author_id = 15; // 山本さん
        $author_dept = '事務';

        if ($mode === 'update' && $post_id > 0) {
            $sql = "UPDATE posts SET
                        title = :title, content = :content, category_id = :category_id,
                        target_datetime = :target_datetime, target_end_datetime = :target_end_datetime,
                        event_schedules = :event_schedules,
                        display_until = :display_until, is_pinned = :is_pinned, updated_at = NOW()
                    WHERE post_id = :post_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':title' => $title, ':content' => $content, ':category_id' => $category_id,
                ':target_datetime' => $target_datetime, ':target_end_datetime' => $target_end_datetime,
                ':event_schedules' => $event_schedules,
                ':display_until' => $display_until, ':is_pinned' => $is_pinned, ':post_id' => $post_id
            ]);

            $pdo->prepare("DELETE FROM post_target_departments WHERE post_id = :post_id")->execute([':post_id' => $post_id]);
            $pdo->prepare("DELETE FROM post_target_staff WHERE post_id = :post_id")->execute([':post_id' => $post_id]);
        } else {
            $sql = "INSERT INTO posts 
                (title, content, category_id, target_datetime, target_end_datetime, event_schedules, display_until, is_pinned, author_id, author_dept, created_at, updated_at)
                VALUES (:title, :content, :category_id, :target_datetime, :target_end_datetime, :event_schedules, :display_until, :is_pinned, :author_id, :author_dept, NOW(), NOW())
                RETURNING post_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':title' => $title, ':content' => $content, ':category_id' => $category_id,
                ':target_datetime' => $target_datetime, ':target_end_datetime' => $target_end_datetime,
                ':event_schedules' => $event_schedules,
                ':display_until' => $display_until, ':is_pinned' => $is_pinned,
                ':author_id' => $author_id, ':author_dept' => $author_dept
            ]);
            $post_id = $stmt->fetchColumn();
        }

        // 対象先の登録 ＆ 送信対象スタッフIDの収集
        $target_type = $_POST['target_type'] ?? 'dept';
        $target_staff_ids = [];

        if ($target_type === 'dept') {
            $selected_depts = $_POST['depts'] ?? [1];
            $stmt_dept = $pdo->prepare("INSERT INTO post_target_departments (post_id, dept_id) VALUES (:post_id, :dept_id)");
            foreach ($selected_depts as $dept_id) {
                $stmt_dept->execute([':post_id' => $post_id, ':dept_id' => $dept_id]);
            }

            // 部署に所属する全アクティブスタッフの取得
            $in_d = implode(',', array_map('intval', $selected_depts));
            $target_staff_ids = $pdo->query("SELECT staff_id FROM staff WHERE dept_id IN ({$in_d}) AND is_deleted = FALSE")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $selected_staff = $_POST['target_staff_ids'] ?? [];
            $stmt_staff = $pdo->prepare("INSERT INTO post_target_staff (post_id, staff_id) VALUES (:post_id, :staff_id)");
            foreach ($selected_staff as $st_id) {
                $stmt_staff->execute([':post_id' => $post_id, ':staff_id' => $st_id]);
            }
            $target_staff_ids = array_map('intval', $selected_staff);
        }

        // 一時画像を本番ディレクトリへ移動 ＆ DB登録
        $deleted_tmp_files = $_POST['delete_tmp_images'] ?? [];
        if (!empty($tmp_images)) {
            $sql_img = "INSERT INTO post_images (post_id, file_path, file_name, file_size) VALUES (:post_id, :file_path, :file_name, :file_size)";
            $stmt_img = $pdo->prepare($sql_img);

            foreach ($tmp_images as $img) {
                if (!in_array($img['tmp_name'], $deleted_tmp_files)) {
                    $src_path = $tmp_dir . $img['tmp_name'];
                    $final_filename = str_replace('tmp_', '', $img['tmp_name']);
                    $dest_path = $upload_dir . $final_filename;

                    if (file_exists($src_path)) {
                        rename($src_path, $dest_path);
                        $stmt_img->execute([
                            ':post_id' => $post_id,
                            ':file_path' => 'uploads/' . $final_filename,
                            ':file_name' => $img['orig_name'],
                            ':file_size' => $img['file_size']
                        ]);
                    }
                } else {
                    if (file_exists($tmp_dir . $img['tmp_name'])) {
                        unlink($tmp_dir . $img['tmp_name']);
                    }
                }
            }
        }

        $pdo->commit();

        // 🟢 LINE送信機能（チェックボックスONの時のみ実行）
        if (session_status() === PHP_SESSION_NONE) session_start();

        if ($send_line && function_exists('sendLineNotification') && !empty($target_staff_ids)) {
            $tag = ($mode === 'update') ? '【お知らせ更新】' : '【新着お知らせ】';
            $line_msg = "{$tag}\n件名：{$title}\n\n※詳細は院内かわら版を確認してください。";
            
            $line_res = sendLineNotification($pdo, $target_staff_ids, $line_msg);

            if ($line_res['unregistered_count'] > 0) {
                $_SESSION['notice_msg'] = "投稿を保存しました。（※対象者のうち {$line_res['unregistered_count']} 名はLINE ID未登録のためLINE通知は送信されませんでした）";
            } else {
                $_SESSION['notice_msg'] = "投稿を保存し、対象者へLINE通知を送信しました。";
            }
        } else {
            $_SESSION['notice_msg'] = "投稿を保存しました。（※LINE通知は送信されませんでした）";
        }

        if ($return_to === 'jimucho' || $return_to === 'jimucho_dashboard.php') {
            $dest = 'jimucho_dashboard.php?msg=saved';
        } elseif (($return_to === 'view' || strpos($return_to, 'view_post.php') !== false) && $post_id > 0) {
            $dest = "view_post.php?id={$post_id}&msg=saved";
        } elseif ($return_to === 'menu' || $return_to === 'index.php') {
            $dest = 'index.php?msg=saved';
        } else {
            $dest = 'kawara_list.php?msg=saved';
        }
        header("Location: {$dest}");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = '処理に失敗しました: ' . $e->getMessage();
    }
}

// 画面表示用データ準備 ＆ LINE未登録人数の算出
$category_id = (int)($_POST['category_id'] ?? 1);
$category_info = $pdo->query("SELECT * FROM post_categories WHERE category_id = {$category_id}")->fetch();

$target_names = [];
$target_staff_check_ids = [];

if (($_POST['target_type'] ?? 'dept') === 'dept') {
    $depts = $_POST['depts'] ?? [];
    if (!empty($depts)) {
        $in = implode(',', array_map('intval', $depts));
        $rows = $pdo->query("SELECT dept_name FROM target_departments WHERE dept_id IN ({$in})")->fetchAll();
        foreach ($rows as $r) $target_names[] = $r['dept_name'];

        $target_staff_check_ids = $pdo->query("SELECT staff_id FROM staff WHERE dept_id IN ({$in}) AND is_deleted = FALSE")->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    $staffs = $_POST['target_staff_ids'] ?? [];
    if (!empty($staffs)) {
        $in = implode(',', array_map('intval', $staffs));
        $rows = $pdo->query("SELECT staff_name FROM staff WHERE staff_id IN ({$in})")->fetchAll();
        foreach ($rows as $r) $target_names[] = $r['staff_name'];

        $target_staff_check_ids = array_map('intval', $staffs);
    }
}

$total_targets = count($target_staff_check_ids);
$unregistered_line_count = 0;
if ($total_targets > 0) {
    $in_chk = implode(',', array_map('intval', $target_staff_check_ids));
    $unregistered_line_count = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE staff_id IN ({$in_chk}) AND (line_user_id IS NULL OR line_user_id = '') AND is_deleted = FALSE")->fetchColumn();
}

$mode_title = ($mode === 'update') ? '更新内容の確認' : (($mode === 'delete') ? '🚨 削除の確認' : '新規投稿の確認');
$btn_label = ($mode === 'update') ? 'この内容で更新保存する 🔄' : (($mode === 'delete') ? 'このお知らせを完全に削除する 🗑️' : 'この内容で確定投稿する 🚀');
$btn_class = ($mode === 'delete') ? 'btn-delete-submit' : 'btn-save';
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title><?= $mode_title ?> | 院内かわら版</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h1 { font-size: 1.3rem; border-left: 5px solid #005a9c; padding-left: 10px; color: #005a9c; margin-top: 0; }
        
        .confirm-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .confirm-table th { width: 22%; background: #f8f9fa; border: 1px solid #dee2e6; padding: 10px; text-align: left; font-size: 0.9rem; }
        .confirm-table td { border: 1px solid #dee2e6; padding: 10px; font-size: 0.95rem; }
        
        .content-preview { background: #fafafa; border: 1px solid #eee; padding: 15px; border-radius: 4px; min-height: 100px; }
        
        .img-grid { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px; }
        .img-card { border: 1px solid #ddd; border-radius: 6px; padding: 6px; background: #fff; text-align: center; max-width: 150px; }
        .img-card img { width: 100%; height: 100px; object-fit: cover; border-radius: 4px; }
        .img-card label { display: block; font-size: 0.75rem; color: #d9534f; margin-top: 4px; cursor: pointer; }

        .alert-line-info { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; padding: 10px 14px; border-radius: 6px; font-size: 0.88rem; font-weight: bold; margin-bottom: 15px; }
        .alert-line-off { background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; padding: 10px 14px; border-radius: 6px; font-size: 0.88rem; font-weight: bold; margin-bottom: 15px; }

        .btn-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; }
        .btn-save { background: #28a745; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; }
        .btn-delete-submit { background: #dc3545; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; }
        .btn-back { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; }
    </style>
</head>
<body>

<div class="container">
    <h1><?= $mode_title ?></h1>
    <p style="font-size:0.9rem; color:#666;">
        <?= $mode === 'delete' ? '以下の内容をデータベースから完全に削除します。よろしいですか？' : '以下の内容で公開・保存します。確定前に内容をご確認ください。' ?>
    </p>

    <?php if ($error): ?>
        <div style="background:#f8d7da; color:#721c24; padding:10px; border-radius:4px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- LINE送信の確認バナー -->
    <?php if ($mode !== 'delete'): ?>
        <?php if ($send_line): ?>
            <div class="alert-line-info">
                📲 LINE通知: <b>送信あり</b> (対象: 全 <?= $total_targets ?> 名 / ID登録済: <?= $total_targets - $unregistered_line_count ?> 名 / 未登録: <?= $unregistered_line_count ?> 名)
                <?php if ($unregistered_line_count > 0): ?>
                    <span style="display:block; font-size:0.78rem; font-weight:normal; margin-top:2px; color:#0369a1;">
                        ※未登録の <?= $unregistered_line_count ?> 名にはLINE通知は送信されません（掲示板上では閲覧可能です）。
                    </span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="alert-line-off">
                🔕 LINE通知: <b>送信なし</b>（掲示板への掲載のみ行われます）
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 📝 修正モード：変更箇所ハイライトサマリー -->
    <?php
    $diff_items = [];
    if ($mode === 'update' && $post_id > 0) {
        $stmt_orig = $pdo->prepare("SELECT * FROM posts WHERE post_id = :id");
        $stmt_orig->execute([':id' => $post_id]);
        $orig_post = $stmt_orig->fetch();

        if ($orig_post) {
            $cur_title = trim($_POST['title'] ?? '');
            if ($cur_title !== trim($orig_post['title'] ?? '')) {
                $diff_items[] = '<b>件名</b>: 「' . htmlspecialchars($orig_post['title']) . '」 ➔ 「' . htmlspecialchars($cur_title) . '」';
            }
            if ((int)($_POST['category_id'] ?? 1) !== (int)$orig_post['category_id']) {
                $diff_items[] = '<b>カテゴリー</b>が変更されました';
            }
            if (trim($_POST['content'] ?? '') !== trim($orig_post['content'] ?? '')) {
                $diff_items[] = '<b>お知らせ本文</b>が修正されました';
            }
            if (($_POST['event_schedules'] ?? '') !== ($orig_post['event_schedules'] ?? '')) {
                $diff_items[] = '<b>イベント日程・時間帯・場所</b>が更新されました';
            }
        }
    }
    ?>

    <?php if ($mode === 'update' && !empty($diff_items)): ?>
        <div style="background:#fffbeb; border:2px solid #f59e0b; border-radius:8px; padding:12px 18px; margin-bottom:18px;">
            <div style="font-weight:bold; color:#92400e; font-size:0.95rem; margin-bottom:6px;">
                📝 修正された箇所（変更点ハイライト）:
            </div>
            <ul style="color:#78350f; font-size:0.88rem; padding-left:20px; line-height:1.5;">
                <?php foreach ($diff_items as $di): ?>
                    <li><?= $di ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <table class="confirm-table">
        <tr>
            <th>区分・カテゴリ</th>
            <td>
                <span style="font-weight:bold; color:<?= $category_info['color_code'] ?? '#333' ?>;">
                    <?= $category_info['icon_emoji'] ?? '' ?> <?= htmlspecialchars($category_info['category_name'] ?? '') ?>
                </span>
                <?= ($_POST['is_pinned'] ?? '') === '1' ? ' <span style="background:#e74c3c; color:white; padding:1px 6px; border-radius:3px; font-size:0.75rem;">📌 画面最上部固定</span>' : '' ?>
            </td>
        </tr>
        <tr>
            <th>件名（タイトル）</th>
            <td><b><?= htmlspecialchars($_POST['title'] ?? '') ?></b></td>
        </tr>
        <tr>
            <th>通知対象</th>
            <td><?= implode(', ', array_map('htmlspecialchars', $target_names)) ?: '指定なし' ?></td>
        </tr>
        <tr>
            <th>イベント日程・場所</th>
            <td>
                <?php
                $schedules_decoded = [];
                if (!empty($_POST['event_schedules'])) {
                    $schedules_decoded = json_decode($_POST['event_schedules'], true) ?: [];
                }

                if (!empty($schedules_decoded)): ?>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <?php foreach ($schedules_decoded as $idx => $sch): 
                            $time_disp = !empty($sch['is_all_day']) ? '終日' : htmlspecialchars($sch['start_time'] ?? '') . ' 〜 ' . htmlspecialchars($sch['end_time'] ?? '');
                            $loc_disp  = !empty($sch['location']) ? '（場所: ' . htmlspecialchars($sch['location']) . '）' : '';
                            $memo_disp = !empty($sch['memo']) ? ' - ※' . htmlspecialchars($sch['memo']) : '';
                        ?>
                            <div style="font-size:0.9rem;">
                                <b>日程 #<?= $idx + 1 ?>:</b> <?= htmlspecialchars($sch['date'] ?? '') ?> <?= $time_disp ?> <?= $loc_disp ?> <span style="color:#0284c7;"><?= $memo_disp ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php
                    $is_all_day = isset($_POST['is_all_day']) && $_POST['is_all_day'] === '1';
                    $ev_date = $_POST['event_date'] ?? '';
                    $ev_end  = $_POST['event_end_date'] ?? '';
                    if ($ev_date) {
                        if ($is_all_day) {
                            echo htmlspecialchars($ev_date) . ($ev_end && $ev_end !== $ev_date ? ' 〜 ' . htmlspecialchars($ev_end) : '') . ' (終日)';
                        } else {
                            echo htmlspecialchars($ev_date) . ' ' . htmlspecialchars($_POST['start_time']) . ' 〜 ' . htmlspecialchars($_POST['end_time']);
                        }
                    } else {
                        echo '指定なし';
                    }
                    ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>掲載終了期限</th>
            <td>
                <b>
                    <?php 
                    $display_until = $_POST['display_until'] ?? '';
                    echo empty($display_until) ? '♾️ 無期限' : htmlspecialchars($display_until);
                    ?>
                </b>
            </td>
        </tr>
        <tr>
            <th>本文内容</th>
            <td>
                <div class="content-preview">
                    <?= $_POST['content'] ?? '' ?>
                </div>
            </td>
        </tr>
        <tr>
            <th>添付写真・画像</th>
            <td>
                <?php if (!empty($tmp_images)): ?>
                    <div class="img-grid">
                        <?php foreach ($tmp_images as $img): ?>
                            <div class="img-card">
                                <img src="uploads/tmp/<?= htmlspecialchars($img['tmp_name']) ?>" alt="添付画像">
                                <div style="font-size:0.75rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px;">
                                    <?= htmlspecialchars($img['orig_name']) ?>
                                </div>
                                <label>
                                    <input type="checkbox" name="delete_tmp_images[]" value="<?= htmlspecialchars($img['tmp_name']) ?>"> 添付除外
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span style="color:#888;">添付画像はありません</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <form method="POST" action="confirm_post.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
        <input type="hidden" name="post_id" value="<?= htmlspecialchars($post_id) ?>">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">
        <input type="hidden" name="send_line" value="<?= $send_line ? '1' : '0' ?>">
        <input type="hidden" name="category_id" value="<?= htmlspecialchars($_POST['category_id'] ?? '1') ?>">
        <input type="hidden" name="is_pinned" value="<?= htmlspecialchars($_POST['is_pinned'] ?? '0') ?>">
        <input type="hidden" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
        <input type="hidden" name="target_type" value="<?= htmlspecialchars($_POST['target_type'] ?? 'dept') ?>">
        
        <?php if (!empty($_POST['depts'])): foreach ($_POST['depts'] as $d): ?>
            <input type="hidden" name="depts[]" value="<?= htmlspecialchars($d) ?>">
        <?php endforeach; endif; ?>

        <?php if (!empty($_POST['target_staff_ids'])): foreach ($_POST['target_staff_ids'] as $s): ?>
            <input type="hidden" name="target_staff_ids[]" value="<?= htmlspecialchars($s) ?>">
        <?php endforeach; endif; ?>

        <input type="hidden" name="event_date" value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>">
        <input type="hidden" name="event_end_date" value="<?= htmlspecialchars($_POST['event_end_date'] ?? '') ?>">
        <input type="hidden" name="start_time" value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>">
        <input type="hidden" name="end_time" value="<?= htmlspecialchars($_POST['end_time'] ?? '') ?>">
        <input type="hidden" name="is_all_day" value="<?= htmlspecialchars($_POST['is_all_day'] ?? '0') ?>">
        <input type="hidden" name="event_schedules" value="<?= htmlspecialchars($_POST['event_schedules'] ?? '[]') ?>">
        
        <!-- 🆕 display_until が空なら空文字を送信（NULLにするため） -->
        <input type="hidden" name="display_until" value="<?= htmlspecialchars($_POST['display_until'] ?? '') ?>">
        
        <input type="hidden" name="content" value="<?= htmlspecialchars($_POST['content'] ?? '') ?>">

        <input type="hidden" name="tmp_images_json" value="<?= htmlspecialchars(json_encode($tmp_images)) ?>">

        <div class="btn-bar">
            <button type="button" class="btn-back" onclick="history.back()">← 修正する</button>
            <button type="submit" class="<?= $btn_class ?>"><?= $btn_label ?></button>
        </div>
    </form>
</div>

</body>
</html>