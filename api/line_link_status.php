<?php
/**
 * 院内かわら版 - LINE連携状態・コード発行・ポーリングAPI
 * エンドポイント: /kawara/api/line_link_status.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 対象スタッフIDの取得（リクエストパラメータ優先、なければセッション）
$target_staff_id = isset($_REQUEST['staff_id']) ? (int)$_REQUEST['staff_id'] : (isset($_SESSION['staff_id']) ? (int)$_SESSION['staff_id'] : 0);
if ($target_staff_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '対象スタッフが指定されていません。']);
    exit;
}

require_once __DIR__ . '/../includes/line_helper.php';

$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB接続エラー']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

// A. 連携状態チェック & 設定情報取得
if ($action === 'status') {
    $stmt = $pdo->prepare("SELECT staff_id, staff_name, role, line_user_id FROM staff WHERE staff_id = :sid AND (is_deleted IS NOT TRUE)");
    $stmt->execute([':sid' => $target_staff_id]);
    $st = $stmt->fetch();

    if (!$st) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'スタッフ情報が見つかりません。']);
        exit;
    }

    $raw_line_id = trim($st['line_user_id'] ?? '');
    $is_linked = !empty($raw_line_id);
    $masked_id = '';
    if ($is_linked) {
        $len = strlen($raw_line_id);
        if ($len > 8) {
            $masked_id = substr($raw_line_id, 0, 4) . '...' . substr($raw_line_id, -4);
        } else {
            $masked_id = $raw_line_id;
        }
    }

    // 現在有効な連携コードがあるか確認
    $stmt_code = $pdo->prepare("
        SELECT link_code, expires_at, EXTRACT(EPOCH FROM (expires_at - NOW()))::int AS remaining_sec 
        FROM line_link_codes 
        WHERE staff_id = :sid AND expires_at > NOW() AND is_used = FALSE 
        ORDER BY code_id DESC LIMIT 1
    ");
    $stmt_code->execute([':sid' => $target_staff_id]);
    $active_code = $stmt_code->fetch();

    $bot_id = defined('LINE_BOT_BASIC_ID') ? LINE_BOT_BASIC_ID : '@tmw3446q';
    $bot_url = 'https://line.me/R/ti/p/' . urlencode($bot_id);

    echo json_encode([
        'success'           => true,
        'staff_id'          => $target_staff_id,
        'staff_name'        => $st['staff_name'],
        'role'              => $st['role'],
        'is_linked'         => $is_linked,
        'line_user_id_mask' => $masked_id,
        'raw_line_user_id'  => $raw_line_id,
        'bot_basic_id'      => $bot_id,
        'bot_add_url'       => $bot_url,
        'active_code'       => $active_code ? $active_code['link_code'] : null,
        'remaining_sec'     => $active_code ? max(0, (int)$active_code['remaining_sec']) : 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// B. 新しいワンタイム連携コードの発行
if ($action === 'generate_code') {
    $code_data = generateLineLinkCode($pdo, $target_staff_id);
    
    $bot_id = defined('LINE_BOT_BASIC_ID') ? LINE_BOT_BASIC_ID : '@tmw3446q';
    $bot_url = 'https://line.me/R/ti/p/' . urlencode($bot_id);

    echo json_encode([
        'success'        => true,
        'link_code'      => $code_data['link_code'],
        'expires_at'     => $code_data['expires_at'],
        'remaining_sec'  => 1200,
        'bot_basic_id'   => $bot_id,
        'bot_add_url'    => $bot_url
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// C. 手動入力またはテストシミュレーション連携
if ($action === 'manual_link') {
    $input_id = trim($_POST['line_user_id'] ?? '');
    if (empty($input_id)) {
        // 空の場合は模擬IDを生成してテスト連携
        $input_id = 'U' . bin2hex(random_bytes(16));
    }

    $pdo->prepare("UPDATE staff SET line_user_id = :uid, updated_at = NOW() WHERE staff_id = :sid")
        ->execute([':uid' => $input_id, ':sid' => $target_staff_id]);

    echo json_encode([
        'success'          => true,
        'message'          => 'LINE IDを登録しました。',
        'line_user_id'     => $input_id
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// D. 連携解除
if ($action === 'unlink') {
    unlinkStaffLine($pdo, $target_staff_id);
    echo json_encode([
        'success' => true,
        'message' => 'LINE連携を解除しました。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => '不正なリクエストです。']);
