<?php
/**
 * 院内かわら版 - 記事LINE通知（プレビュー・自分宛テスト・本番一斉配信）API
 * エンドポイント: /kawara/api/line_post_notify_api.php
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 権限チェック
if (!isset($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です']);
    exit;
}

require_once __DIR__ . '/../includes/line_helper.php';

require_once __DIR__ . '/../includes/db.php';

$current_staff_id = (int)$_SESSION['staff_id'];

// ログイン中の自身の情報を取得
$stmt_me = $pdo->prepare("SELECT staff_id, staff_name, role, is_admin, line_user_id FROM staff WHERE staff_id = :id");
$stmt_me->execute([':id' => $current_staff_id]);
$me = $stmt_me->fetch();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$post_id = (int)($_POST['post_id'] ?? ($_GET['post_id'] ?? 0));

if ($post_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '記事IDが不正です']);
    exit;
}

// 記事情報取得
$stmt_post = $pdo->prepare("
    SELECT p.*, c.category_name, c.icon_emoji, s.staff_name as author_name 
    FROM posts p
    LEFT JOIN post_categories c ON p.category_id = c.category_id
    LEFT JOIN staff s ON p.author_id = s.staff_id
    WHERE p.post_id = :id
");
$stmt_post->execute([':id' => $post_id]);
$post = $stmt_post->fetch();

if (!$post) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '記事が見つかりません']);
    exit;
}

// 対象部署の取得
$stmt_depts = $pdo->prepare("
    SELECT td.dept_id, td.dept_name 
    FROM post_target_departments ptd
    JOIN target_departments td ON ptd.dept_id = td.dept_id
    WHERE ptd.post_id = :pid
    ORDER BY td.display_order ASC
");
$stmt_depts->execute([':pid' => $post_id]);
$target_depts = $stmt_depts->fetchAll();

$is_all_hospital = false;
$dept_ids = [];
$dept_names = [];
foreach ($target_depts as $d) {
    if ((int)$d['dept_id'] === 1 || $d['dept_name'] === '全職員' || $d['dept_name'] === '全館共通') {
        $is_all_hospital = true;
    }
    $dept_ids[] = (int)$d['dept_id'];
    $dept_names[] = $d['dept_name'];
}

$dept_display = $is_all_hospital || empty($dept_names) ? '全館共通（全職員）' : implode(' / ', $dept_names);

// 対象スタッフ一覧（LINE連携済みかどうかも含む）
if ($is_all_hospital || empty($dept_ids)) {
    $stmt_targets = $pdo->query("SELECT staff_id, staff_name, role, dept_id, line_user_id FROM staff WHERE is_deleted IS NOT TRUE");
} else {
    $in_d = implode(',', array_fill(0, count($dept_ids), '?'));
    $stmt_targets = $pdo->prepare("SELECT staff_id, staff_name, role, dept_id, line_user_id FROM staff WHERE dept_id IN ({$in_d}) AND is_deleted IS NOT TRUE");
    $stmt_targets->execute($dept_ids);
}
$target_staff_list = $stmt_targets->fetchAll();

$total_targets = count($target_staff_list);
$linked_count = 0;
$linked_staff_ids = [];
foreach ($target_staff_list as $st) {
    if (!empty($st['line_user_id'])) {
        $linked_count++;
        $linked_staff_ids[] = (int)$st['staff_id'];
    }
}

// -------------------------------------------------------------
// 1. プレビュー用データ取得
// -------------------------------------------------------------
if ($action === 'get_preview') {
    $custom_notice = $_POST['custom_notice'] ?? '';
    $bubble = buildPostFlexBubble($post, $custom_notice, $dept_display);

    echo json_encode([
        'success'            => true,
        'post'               => [
            'post_id'       => $post_id,
            'title'         => $post['title'],
            'category_name' => $post['category_name'],
            'icon_emoji'    => $post['icon_emoji']
        ],
        'dept_display'       => $dept_display,
        'total_targets'      => $total_targets,
        'linked_count'       => $linked_count,
        'my_line_linked'     => !empty($me['line_user_id']),
        'my_name'            => $me['staff_name'] ?? '',
        'bubble'             => $bubble
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// 2. 自分宛テスト送信（実機確認）
// -------------------------------------------------------------
if ($action === 'send_test_me') {
    if (empty($me['line_user_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => "あなた（{$me['staff_name']} 様）のLINEアカウントがまだ連携されていません。「安否連絡網」画面からLINE連携（4桁コード）を行ってからお試しください。"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $custom_notice = trim($_POST['custom_notice'] ?? '');
    $bubble = buildPostFlexBubble($post, $custom_notice, $dept_display);
    $alt_text = "【テスト通知】{$post['title']}";

    $res = sendLineFlexMessage($pdo, [$current_staff_id], $alt_text, $bubble);

    if ($res['success']) {
        echo json_encode([
            'success' => true,
            'message' => "あなた（{$me['staff_name']} 様）のLINEへテストカードを送信しました！ スマホのLINEをご確認ください。"
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'LINE送信に失敗しました: ' . implode(', ', $res['errors'])
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// -------------------------------------------------------------
// 3. 対象者全員へ本番一斉送信
// -------------------------------------------------------------
if ($action === 'send_broadcast') {
    // 権限チェック: 管理者、事務長、または記事作成者
    $is_admin = (bool)($me['is_admin'] ?? false);
    $is_jimucho = ($current_staff_id === 15 || mb_strpos($me['staff_name'], '山本') !== false);
    $is_author = ($current_staff_id === (int)$post['author_id']);

    if (!$is_admin && !$is_jimucho && !$is_author) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '送信権限がありません（管理者・事務長・記事作成者のみ実行可能）']);
        exit;
    }

    if (empty($linked_staff_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '対象部署のスタッフにLINE連携済みの職員がいません']);
        exit;
    }

    $target_mode = $_POST['target_mode'] ?? 'all'; // all or unread_only
    $send_ids = $linked_staff_ids;

    // 未読・未返答者のみに絞り込む場合
    if ($target_mode === 'unread_only') {
        $stmt_reads = $pdo->prepare("SELECT staff_id FROM post_reads WHERE post_id = :pid");
        $stmt_reads->execute([':pid' => $post_id]);
        $read_staff_ids = array_flip($stmt_reads->fetchAll(PDO::FETCH_COLUMN));

        $send_ids = array_values(array_filter($linked_staff_ids, function($sid) use ($read_staff_ids) {
            return !isset($read_staff_ids[$sid]);
        }));

        if (empty($send_ids)) {
            echo json_encode(['success' => true, 'sent_count' => 0, 'message' => '対象者は全員すでに既読・了解済みです。']);
            exit;
        }
    }

    $custom_notice = trim($_POST['custom_notice'] ?? '');
    $bubble = buildPostFlexBubble($post, $custom_notice, $dept_display);
    $alt_text = "【院内伝達】{$post['title']}";

    $res = sendLineFlexMessage($pdo, $send_ids, $alt_text, $bubble);

    if ($res['success']) {
        echo json_encode([
            'success'            => true,
            'sent_count'         => $res['sent_count'],
            'unregistered_count' => $res['unregistered_count'],
            'message'            => "LINE連携済みの対象スタッフ {$res['sent_count']} 名へカード型伝達を送信しました！"
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'LINE送信エラー: ' . implode(', ', $res['errors'])
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => '無効なリクエストです']);
