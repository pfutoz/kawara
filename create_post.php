<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 未ログイン状態のチェック（認証ガード）
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php");
    exit;
}

// DB接続設定
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

// テンプレート保存処理 (AJAX/POST受信用)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'save_template') {
    $tpl_name = trim($_POST['tpl_name'] ?? '');
    $cat_id   = (int)($_POST['category_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $content  = trim($_POST['content'] ?? '');
    
    if ($tpl_name !== '' && $title !== '') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS post_templates (
            template_id SERIAL PRIMARY KEY,
            template_name VARCHAR(100) NOT NULL,
            category_id INT,
            title VARCHAR(255) NOT NULL,
            content TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        $stmt_t = $pdo->prepare("INSERT INTO post_templates (template_name, category_id, title, content) VALUES (:name, :cat, :title, :content)");
        $stmt_t->execute([':name' => $tpl_name, ':cat' => $cat_id, ':title' => $title, ':content' => $content]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => '入力情報が不足しています。']);
    }
    exit;
}

// 各種マスター ＆ スタッフ（削除済み除外） ＆ テンプレートの取得
$categories  = $pdo->query("SELECT * FROM post_categories WHERE is_active = TRUE ORDER BY display_order")->fetchAll();
$departments = $pdo->query("SELECT * FROM target_departments WHERE is_active = TRUE ORDER BY display_order")->fetchAll();
$staff_members = $pdo->query("SELECT staff_id, staff_name, role, kana, kana_row FROM staff WHERE is_deleted = FALSE ORDER BY kana ASC")->fetchAll();

$templates = [];
try {
    $templates = $pdo->query("SELECT * FROM post_templates ORDER BY template_id DESC")->fetchAll();
} catch (Exception $e) {
    // テーブルがまだ無い場合は無視
}

// 編集モード判定
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post_data = null;
$selected_depts = [];
$selected_staff = [];

if ($post_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE post_id = :post_id");
    $stmt->execute([':post_id' => $post_id]);
    $post_data = $stmt->fetch();

    if ($post_data) {
        $stmt_d = $pdo->prepare("SELECT dept_id FROM post_target_departments WHERE post_id = :post_id");
        $stmt_d->execute([':post_id' => $post_id]);
        $selected_depts = $stmt_d->fetchAll(PDO::FETCH_COLUMN);

        $stmt_s = $pdo->prepare("SELECT staff_id FROM post_target_staff WHERE post_id = :post_id");
        $stmt_s->execute([':post_id' => $post_id]);
        $selected_staff = $stmt_s->fetchAll(PDO::FETCH_COLUMN);
    }
}

$is_edit = ($post_data !== null);
$page_title = $is_edit ? '✏️ お知らせの編集' : '📝 新規お知らせ作成';

// 無期限フラグ（display_until が NULL または空文字なら無期限）
$is_unlimited = empty($post_data['display_until'] ?? '');
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | 院内かわら版</title>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 850px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h1 { font-size: 1.3rem; border-left: 5px solid #005a9c; padding-left: 10px; color: #005a9c; margin-top: 0; margin-bottom: 20px; }

        .form-section { background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 20px; }
        .section-label { font-weight: bold; font-size: 0.95rem; margin-bottom: 10px; color: #005a9c; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; font-size: 0.88rem; margin-bottom: 5px; color: #444; }
        .form-control { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.93rem; box-sizing: border-box; }
        
        .flex-row { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
        
        .template-bar { background: #eef6fc; border: 1px solid #b8daff; padding: 10px 14px; border-radius: 6px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .template-bar select { width: auto; flex: 1; min-width: 240px; background: #fff; }

        .btn-group-copy { display: flex; gap: 4px; flex-wrap: wrap; }
        .btn-copy { background: #e3f2fd; color: #0d6efd; border: 1px solid #90caf9; padding: 4px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; cursor: pointer; transition: all 0.15s ease-in-out; }
        .btn-copy:hover { background: #bbdefb; }

        .time-quick-btns { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; align-items: center; }
        .btn-time-quick { background: #fff; border: 1px solid #28a745; color: #28a745; padding: 2px 7px; border-radius: 10px; font-size: 0.72rem; font-weight: bold; cursor: pointer; }
        .btn-time-quick:hover { background: #28a745; color: #fff; }

        .period-container { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-top: 5px; }
        .period-btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-period {
            background-color: #ffffff !important; color: #495057 !important; border: 1px solid #ced4da !important;
            padding: 7px 15px !important; border-radius: 4px !important; font-size: 0.88rem !important;
            font-weight: bold !important; cursor: pointer !important; transition: all 0.15s ease-in-out !important; outline: none !important;
        }
        .btn-period:hover { background-color: #e9ecef !important; }
        .btn-period.active {
            background-color: #005a9c !important; color: #ffffff !important; border-color: #005a9c !important;
            box-shadow: 0 2px 5px rgba(0, 90, 156, 0.3) !important;
        }

        .period-preview { font-size: 0.88rem; color: #555; background: #ffffff; padding: 6px 12px; border: 1px solid #ccc; border-radius: 4px; font-weight: bold; }
        .period-preview span { color: #005a9c; margin-left: 5px; font-size: 0.95rem; }

        .filter-bar { display: flex; gap: 3px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; background: #eef2f5; padding: 6px; border-radius: 4px; }
        .btn-row-filter { background: #fff; border: 1px solid #ced4da; padding: 2px 8px; border-radius: 3px; font-size: 0.78rem; font-weight: bold; cursor: pointer; color: #495057; }
        .btn-row-filter.active { background: #005a9c; color: white; border-color: #005a9c; }

        #individual-staff-box { display: none; margin-top: 10px; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .staff-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 6px; max-height: 180px; overflow-y: auto; padding-top: 5px; }

        #editor { height: 220px; background: #fff; }

        .line-option-card {
            background: #eefbf4;
            border: 1px solid #a3e6cd;
            padding: 12px 16px;
            border-radius: 6px;
            margin-top: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .line-option-card label {
            cursor: pointer;
            font-weight: bold;
            font-size: 0.92rem;
            color: #0f5132;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .line-option-card input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #198754;
            cursor: pointer;
        }

        .tooltip-wrapper {
            position: relative;
            display: inline-block;
        }
        .badge-line-info {
            font-size: 0.78rem;
            color: #0f5132;
            background: #d1e7dd;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }
        .badge-line-info:hover {
            background: #a3e6cd;
            color: #052c16;
        }

        .tooltip-bubble {
            visibility: hidden;
            opacity: 0;
            width: 260px;
            background-color: #2c3e50;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 8px 12px;
            position: absolute;
            z-index: 10;
            bottom: 125%;
            right: 0;
            font-size: 0.78rem;
            line-height: 1.4;
            box-shadow: 0 4px 12px rgba(0,0,0,0.18);
            transition: opacity 0.2s ease-in-out, visibility 0.2s ease-in-out;
        }
        .tooltip-bubble::after {
            content: "";
            position: absolute;
            top: 100%;
            right: 20px;
            border-width: 6px;
            border-style: solid;
            border-color: #2c3e50 transparent transparent transparent;
        }
        .tooltip-wrapper:hover .tooltip-bubble,
        .tooltip-wrapper.is-open .tooltip-bubble {
            visibility: visible;
            opacity: 1;
        }

        .btn-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; }
        .btn-submit { background: #007bff; color: white; border: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; }
        .btn-delete { background: #dc3545; color: white; border: none; padding: 12px 20px; border-radius: 6px; font-weight: bold; font-size: 0.95rem; cursor: pointer; }
        
        .btn-sm { background: #fff; border: 1px solid #005a9c; color: #005a9c; padding: 5px 12px; border-radius: 4px; font-weight: bold; font-size: 0.82rem; cursor: pointer; }
        .btn-sm:hover { background: #005a9c; color: #fff; }
    </style>
</head>
<body>

<div class="container">
    <h1><?= $page_title ?></h1>

    <div class="template-bar">
        <div style="font-size:0.88rem; font-weight:bold; color:#004085;">📋 テンプレートを使う:</div>
        <select id="f_template" class="form-control" onchange="loadTemplate(this.value)">
            <option value="">-- 登録済みテンプレートを選択 --</option>
            <?php foreach ($templates as $tpl): ?>
                <option value="<?= $tpl['template_id'] ?>" 
                        data-category-id="<?= $tpl['category_id'] ?>" 
                        data-title="<?= htmlspecialchars($tpl['title']) ?>" 
                        data-content="<?= htmlspecialchars($tpl['content']) ?>">
                    <?= htmlspecialchars($tpl['template_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn-sm" onclick="saveAsTemplate()">＋ 入力内容をテンプレ保存</button>
    </div>

    <form action="confirm_post.php" method="POST" enctype="multipart/form-data" id="postForm" onkeydown="return preventEnterSubmit(event);">
        
        <input type="hidden" name="post_id" value="<?= $post_id ?>">
        <input type="hidden" name="mode" id="f_mode" value="<?= $is_edit ? 'update' : 'create' ?>">

        <div class="form-section">
            <div class="flex-row" style="justify-content: space-between; align-items: center;">
                <div style="flex: 1; min-width: 220px;">
                    <label>区分・カテゴリー</label>
                    <select name="category_id" id="f_category" class="form-control">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= ($is_edit && $post_data['category_id'] == $cat['category_id']) ? 'selected' : '' ?>>
                                <?= $cat['icon_emoji'] ?> <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top:20px;">
                    <label style="cursor:pointer; font-weight:bold;">
                        <input type="checkbox" name="is_pinned" value="1" <?= ($is_edit && $post_data['is_pinned']) ? 'checked' : '' ?>> 📌 最上部に固定（ピン留め）
                    </label>
                </div>
            </div>

            <div class="form-group" style="margin-top:12px;">
                <label>件名（タイトル） <span style="color:red;">*</span></label>
                <input type="text" name="title" id="f_title" class="form-control" required value="<?= htmlspecialchars($post_data['title'] ?? '') ?>" placeholder="例: 【本日実施】1Fジャグジーポンプ交換作業">
            </div>
        </div>

        <div class="form-section">
            <div class="section-label">🎯 通知対象の指定</div>
            <div class="flex-row" style="margin-bottom:10px; align-items: center;">
                <label style="cursor:pointer; font-weight:bold;">
                    <input type="radio" name="target_type" value="dept" <?= (!$is_edit || !empty($selected_depts)) ? 'checked' : '' ?> onclick="toggleTargetType('dept')"> 部署グループで指定
                </label>
                <label style="cursor:pointer; font-weight:bold; margin-left:15px;">
                    <input type="radio" name="target_type" value="individual" <?= ($is_edit && !empty($selected_staff)) ? 'checked' : '' ?> onclick="toggleTargetType('individual')"> 👤 特定の人だけに通知（指名）
                </label>
            </div>

            <div id="dept-selector" class="flex-row" style="align-items: center;">
                <?php foreach ($departments as $d): ?>
                    <label style="font-size:0.88rem; cursor:pointer;">
                        <input type="checkbox" name="depts[]" value="<?= $d['dept_id'] ?>" <?= (!$is_edit && $d['dept_code'] === 'all') || in_array($d['dept_id'], $selected_depts) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($d['dept_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div id="individual-staff-box">
                <div class="filter-bar">
                    <span style="font-weight:bold; font-size:0.78rem; color:#555; margin-right:5px;">50音絞り込み:</span>
                    <button type="button" class="btn-row-filter active" onclick="filterStaffKana('all', this)">全</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('あ', this)">あ</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('か', this)">か</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('さ', this)">さ</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('た', this)">た</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('な', this)">な</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('は', this)">は</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('ま', this)">ま</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('や', this)">や</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('ら', this)">ら</button>
                    <button type="button" class="btn-row-filter" onclick="filterStaffKana('わ', this)">わ</button>
                </div>
                <div class="staff-grid">
                    <?php foreach ($staff_members as $sm): 
                        $kana_trim = trim($sm['kana'] ?? '');
                        $first_char = mb_substr($kana_trim, 0, 1);
                    ?>
                        <label class="staff-item" 
                               data-kana="<?= htmlspecialchars($kana_trim) ?>"
                               data-first-char="<?= htmlspecialchars($first_char) ?>"
                               style="font-size:0.85rem; cursor:pointer;">
                            <input type="checkbox" name="target_staff_ids[]" value="<?= $sm['staff_id'] ?>" <?= in_array($sm['staff_id'], $selected_staff) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($sm['staff_name']) ?> <small style="color:#666;">(<?= htmlspecialchars($sm['role']) ?>)</small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-label">
                <span>🗓 イベント日時・掲載期間</span>
            </div>

            <?php
            $start_dt = $post_data['target_datetime'] ?? null;
            $end_dt   = $post_data['target_end_datetime'] ?? null;
            
            $ev_date  = $start_dt ? substr($start_dt, 0, 10) : '';
            $ev_end   = $end_dt ? substr($end_dt, 0, 10) : '';
            $st_time  = $start_dt ? substr($start_dt, 11, 5) : '09:00';
            $ed_time  = $end_dt ? substr($end_dt, 11, 5) : '10:00';
            $is_allday = $start_dt && (substr($start_dt, 11, 8) === '00:00:00') && ($end_dt && substr($end_dt, 11, 8) === '23:59:59');
            ?>

            <div class="flex-row" style="margin-bottom:15px;">
                <div>
                    <label id="lbl_start_date">開始日</label>
                    <input type="date" name="event_date" id="f_event_date" class="form-control" style="width:150px;" value="<?= $ev_date ?>" onchange="onEventDateChange()">
                </div>

                <div id="box_time_start" style="<?= $is_allday ? 'display:none;' : '' ?>">
                    <label>開始時間</label>
                    <input type="time" name="start_time" id="f_start_time" class="form-control" style="width:120px;" value="<?= $st_time ?>" onchange="autoSetEndTime()">
                </div>
                
                <div id="box_time_sep" style="margin-top: 28px; font-weight: bold; color: #666; <?= $is_allday ? 'display:none;' : '' ?>">〜</div>
                
                <div id="box_time_end" style="<?= $is_allday ? 'display:none;' : '' ?>">
                    <label>終了時間</label>
                    <input type="time" name="end_time" id="f_end_time" class="form-control" style="width:120px;" value="<?= $ed_time ?>">
                    <div class="time-quick-btns">
                        <span style="font-size:0.7rem; color:#666;">加算:</span>
                        <button type="button" class="btn-time-quick" onclick="addMinutesToEndTime(15)">+15分</button>
                        <button type="button" class="btn-time-quick" onclick="addMinutesToEndTime(30)">+30分</button>
                        <button type="button" class="btn-time-quick" onclick="addMinutesToEndTime(60)">+1h</button>
                        <button type="button" class="btn-time-quick" onclick="addMinutesToEndTime(120)">+2h</button>
                    </div>
                </div>

                <div id="box_end_date" style="<?= $is_allday ? '' : 'display:none;' ?>">
                    <label>〜 終了日</label>
                    <input type="date" name="event_end_date" id="f_event_end_date" class="form-control" style="width:150px;" value="<?= $ev_end ?>" onchange="onEventEndDateChange()">
                </div>

                <div style="margin-top: 28px; margin-left: 10px;">
                    <label style="cursor:pointer; font-size:0.85rem; font-weight:bold; color:#005a9c; display: inline-flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="is_all_day" id="f_all_day" value="1" <?= $is_allday ? 'checked' : '' ?> onchange="toggleAllDay(this.checked)"> 終日（日付ベース指定）
                    </label>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label>掲載期間（クイック選択）</label>
                <div class="period-container">
                    <div class="period-btn-group" id="periodBtnGroup">
                        <button type="button" class="btn-period <?= $is_unlimited ? 'active' : '' ?>" onclick="selectPeriod(0, this)">♾️ 無期限</button>
                        <button type="button" class="btn-period <?= (!$is_unlimited && ($post_data['display_until'] ?? '') !== '') ? 'active' : '' ?>" onclick="selectPeriod(1, this)">1日間</button>
                        <button type="button" class="btn-period" onclick="selectPeriod(3, this)">3日間</button>
                        <button type="button" class="btn-period" onclick="selectPeriod(7, this)">1週間</button>
                        <button type="button" class="btn-period" onclick="selectPeriod(14, this)">2週間</button>
                        <button type="button" class="btn-period" onclick="selectPeriod(30, this)">1ヶ月</button>
                    </div>

                    <div class="period-preview">
                        掲載終了予定: <span id="lbl_display_until_preview">
                            <?= $is_unlimited ? '♾️ 無期限' : date('Y/m/d 23:59', strtotime($post_data['display_until'] ?? '')) ?>
                        </span>
                    </div>

                    <input type="hidden" name="display_until" id="f_display_until" value="<?= htmlspecialchars($post_data['display_until'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-group">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; flex-wrap:wrap; gap:8px;">
                <label style="margin-bottom:0;">お知らせ本文 <span style="color:red;">*</span></label>
                
                <div class="btn-group-copy">
                    <span style="font-size:0.78rem; color:#666; align-self:center;">本文へ転記:</span>
                    <button type="button" class="btn-copy" onclick="copyToContent('all')">📦 全て転記</button>
                    <button type="button" class="btn-copy" onclick="copyToContent('title')">🏷️ 区分・件名</button>
                    <button type="button" class="btn-copy" onclick="copyToContent('target')">👥 通知対象</button>
                    <button type="button" class="btn-copy" onclick="copyToContent('date')">🗓️ 日時など</button>
                </div>
            </div>

            <input type="hidden" name="content" id="hiddenContent">
            <div id="editor"><?= $post_data['content'] ?? '' ?></div>
        </div>

        <div class="form-group">
            <label>写真・画像添付（複数可）</label>
            <input type="file" name="images[]" multiple accept="image/*" class="form-control">
        </div>

        <div class="line-option-card">
            <label>
                <input type="checkbox" name="send_line" value="1">
                <span>📲 対象スタッフのLINEへ通知を送信する（デフォルト: オフ）</span>
            </label>

            <div class="tooltip-wrapper" id="lineTooltipWrapper" onclick="toggleLineTooltip(event)">
                <span class="badge-line-info">LINE連携機能 ❓</span>
                <div class="tooltip-bubble">
                    🔒 記事の詳しい内容はセキュリティ上送信されません（タイトル通知のみ）
                </div>
            </div>
        </div>

        <div class="btn-bar">
            <div>
                <a href="/kawara/index.php" style="color:#666; text-decoration:none; margin-right:15px;">← キャンセル</a>
                <?php if ($is_edit): ?>
                    <button type="button" class="btn-delete" onclick="submitDelete()">🗑️ このお知らせを削除する</button>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-submit" onclick="submitQuill()">確認画面へ進む →</button>
        </div>

    </form>
</div>

<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
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

let selectedDays = 0;  // 0 = 無期限

var quill = new Quill('#editor', {
    theme: 'snow',
    placeholder: '本文を入力してください...',
    modules: {
        toolbar: [
            [{ 'header': [2, 3, false] }],
            ['bold', 'italic', 'underline', { 'color': ['#000000', '#e74c3c', '#005a9c', '#27ae60'] }],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link', 'clean']
        ]
    }
});

document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('f_event_date').value) {
        document.getElementById('f_event_date').valueAsDate = new Date();
    }
    toggleTargetType(document.querySelector('input[name="target_type"]:checked').value);
    updateDisplayUntil();

    // 編集時に「無期限」が選択されていたら復元
    const displayUntilVal = document.getElementById('f_display_until').value;
    if (!displayUntilVal || displayUntilVal === '') {
        const unlimitedBtn = document.querySelector('#periodBtnGroup .btn-period:first-child');
        if (unlimitedBtn) {
            document.querySelectorAll('#periodBtnGroup .btn-period').forEach(b => b.classList.remove('active'));
            unlimitedBtn.classList.add('active');
            selectedDays = 0;
            document.getElementById('lbl_display_until_preview').textContent = '♾️ 無期限';
        }
    } else {
        selectedDays = 1;
        const day1Btn = document.querySelector('#periodBtnGroup .btn-period:nth-child(2)');
        if (day1Btn) {
            document.querySelectorAll('#periodBtnGroup .btn-period').forEach(b => b.classList.remove('active'));
            day1Btn.classList.add('active');
        }
    }
});

function toggleLineTooltip(e) {
    e.stopPropagation();
    document.getElementById('lineTooltipWrapper').classList.toggle('is-open');
}

document.addEventListener('click', () => {
    const el = document.getElementById('lineTooltipWrapper');
    if (el) el.classList.remove('is-open');
});

function toggleTargetType(type) {
    document.getElementById('dept-selector').style.display = (type === 'dept') ? 'flex' : 'none';
    document.getElementById('individual-staff-box').style.display = (type === 'individual') ? 'block' : 'none';
}

function filterStaffKana(row, btn) {
    document.querySelectorAll('.btn-row-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const items = document.querySelectorAll('.staff-item');
    items.forEach(item => {
        const firstChar = item.getAttribute('data-first-char');
        
        if (row === 'all') {
            item.style.display = 'inline-flex';
        } else {
            const targetChars = kanaRowMap[row] || [];
            if (targetChars.includes(firstChar)) {
                item.style.display = 'inline-flex';
            } else {
                item.style.display = 'none';
            }
        }
    });
}

function autoSetEndTime() {
    const startTimeVal = document.getElementById('f_start_time').value;
    if (!startTimeVal) return;

    const [h, m] = startTimeVal.split(':').map(Number);
    let endH = (h + 1) % 24;
    
    const formattedEnd = String(endH).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    document.getElementById('f_end_time').value = formattedEnd;
}

function addMinutesToEndTime(mins) {
    const startTimeVal = document.getElementById('f_start_time').value;
    if (!startTimeVal) return;

    const [h, m] = startTimeVal.split(':').map(Number);
    const totalMins = (h * 60) + m + mins;

    let newH = Math.floor(totalMins / 60) % 24;
    let newM = totalMins % 60;

    const formattedEnd = String(newH).padStart(2, '0') + ':' + String(newM).padStart(2, '0');
    document.getElementById('f_end_time').value = formattedEnd;
}

function onEventDateChange() {
    const isAllDay = document.getElementById('f_all_day').checked;
    const startDate = document.getElementById('f_event_date').value;
    const endDateInput = document.getElementById('f_event_end_date');

    if (isAllDay) {
        if (!endDateInput.value || endDateInput.value < startDate) {
            endDateInput.value = startDate;
        }
    }
    updateDisplayUntil();
}

function onEventEndDateChange() {
    updateDisplayUntil();
}

function toggleAllDay(isAllDay) {
    document.getElementById('box_time_start').style.display = isAllDay ? 'none' : 'block';
    document.getElementById('box_time_sep').style.display   = isAllDay ? 'none' : 'block';
    document.getElementById('box_time_end').style.display     = isAllDay ? 'none' : 'block';
    
    document.getElementById('box_end_date').style.display     = isAllDay ? 'block' : 'none';
    document.getElementById('lbl_start_date').textContent     = isAllDay ? '開始日' : '実施日';

    onEventDateChange();
}

function selectPeriod(days, btn) {
    selectedDays = days;
    const buttons = document.querySelectorAll('#periodBtnGroup .btn-period');
    buttons.forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    updateDisplayUntil();
}

function updateDisplayUntil() {
    const isAllDay = document.getElementById('f_all_day').checked;
    const startDateVal = document.getElementById('f_event_date').value;
    const endDateVal   = document.getElementById('f_event_end_date').value;

    // 無期限（0日）の場合
    if (selectedDays === 0) {
        document.getElementById('f_display_until').value = '';
        document.getElementById('lbl_display_until_preview').textContent = '♾️ 無期限';
        return;
    }

    let baseDate = new Date();
    if (isAllDay && endDateVal) {
        baseDate = new Date(endDateVal);
    } else if (startDateVal) {
        baseDate = new Date(startDateVal);
    }

    baseDate.setDate(baseDate.getDate() + (selectedDays - 1));

    const yyyy = baseDate.getFullYear();
    const mm = String(baseDate.getMonth() + 1).padStart(2, '0');
    const dd = String(baseDate.getDate()).padStart(2, '0');

    document.getElementById('f_display_until').value = `${yyyy}-${mm}-${dd} 23:59:00`;
    document.getElementById('lbl_display_until_preview').textContent = `${yyyy}/${mm}/${dd} 23:59`;
}

function copyToContent(type) {
    const categorySelect = document.getElementById('f_category');
    const categoryText = categorySelect.options[categorySelect.selectedIndex].text.trim();
    const titleText = document.getElementById('f_title').value;

    const eventDate = document.getElementById('f_event_date').value;
    const eventEndDate = document.getElementById('f_event_end_date').value;
    const startTime = document.getElementById('f_start_time').value;
    const endTime = document.getElementById('f_end_time').value;
    const isAllDay = document.getElementById('f_all_day').checked;

    let targetText = '';
    const targetType = document.querySelector('input[name="target_type"]:checked').value;
    if (targetType === 'dept') {
        const checkedDepts = Array.from(document.querySelectorAll('input[name="depts[]"]:checked')).map(el => el.parentNode.innerText.trim());
        targetText = checkedDepts.join(', ');
    } else {
        const checkedStaff = Array.from(document.querySelectorAll('input[name="target_staff_ids[]"]:checked')).map(el => el.parentNode.innerText.trim());
        targetText = checkedStaff.join(', ');
    }

    let dateStr = eventDate ? eventDate : '未定';
    let timeStr = '';

    if (isAllDay) {
        if (eventEndDate && eventEndDate !== eventDate) {
            dateStr = `${eventDate} 〜 ${eventEndDate}`;
        }
        timeStr = '終日';
    } else {
        timeStr = `${startTime} 〜 ${endTime}`;
    }
    
    let htmlSnippet = '';

    if (type === 'all') {
        htmlSnippet = `
            <p><strong>【要項】</strong></p>
            <ul>
                <li><strong>📌 区分：</strong> ${categoryText}</li>
                <li><strong>📝 件名：</strong> ${titleText || '（未入力）'}</li>
                <li><strong>🎯 対象者：</strong> ${targetText || '全員'}</li>
                <li><strong>🗓 実施日時：</strong> ${dateStr} (${timeStr})</li>
            </ul>
            <hr><p></p>
        `;
    } else if (type === 'title') {
        htmlSnippet = `<p><strong>【区分・件名】</strong> ${categoryText} / ${titleText}</p>`;
    } else if (type === 'target') {
        htmlSnippet = `<p><strong>【対象者】</strong> ${targetText || '全員'}</p>`;
    } else if (type === 'date') {
        htmlSnippet = `<p><strong>【実施日時】</strong> ${dateStr} (${timeStr})</p>`;
    }

    if (htmlSnippet !== '') {
        quill.clipboard.dangerouslyPasteHTML(quill.getLength(), htmlSnippet);
    }
}

function loadTemplate(tplId) {
    if (!tplId) return;
    const select = document.getElementById('f_template');
    const option = select.options[select.selectedIndex];

    const catId   = option.getAttribute('data-category-id');
    const title   = option.getAttribute('data-title');
    const content = option.getAttribute('data-content');

    if (catId) document.getElementById('f_category').value = catId;
    if (title) document.getElementById('f_title').value = title;
    if (content) quill.clipboard.dangerouslyPasteHTML(0, content);
}

function saveAsTemplate() {
    const tplName = prompt("保存するテンプレート名を入力してください:", "例: 定例機器メンテナンス");
    if (!tplName) return;

    const catId   = document.getElementById('f_category').value;
    const title   = document.getElementById('f_title').value;
    const content = quill.root.innerHTML;

    if (!title || title.trim() === '') {
        alert('テンプレートに保存するための「件名（タイトル）」を入力してください。');
        return;
    }

    const formData = new FormData();
    formData.append('action_type', 'save_template');
    formData.append('tpl_name', tplName);
    formData.append('category_id', catId);
    formData.append('title', title);
    formData.append('content', content);

    fetch('create_post.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert(`「${tplName}」をテンプレートとして保存しました！`);
            location.reload();
        } else {
            alert('保存に失敗しました: ' + (data.message || ''));
        }
    })
    .catch(err => {
        alert('通信エラーが発生しました。');
    });
}

function submitQuill() {
    document.getElementById('hiddenContent').value = quill.root.innerHTML;
}

function submitDelete() {
    if (confirm('このお知らせを完全に削除してもよろしいですか？')) {
        document.getElementById('f_mode').value = 'delete';
        submitQuill();
        document.getElementById('postForm').submit();
    }
}

function preventEnterSubmit(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
        if (!e.isComposing && e.target.tagName !== 'TEXTAREA' && !e.target.classList.contains('ql-editor')) {
            e.preventDefault();
            return false;
        }
    }
}
</script>

</body>
</html>