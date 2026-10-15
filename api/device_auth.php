<?php
/**
 * 院内かわら版 - 端末認証 ＆ LINEワンタイムコード API
 * File: api/device_auth.php
 * 
 * エンドポイント:
 *   - action=send_code   : 職員のLINE宛てに6桁認証コードを送信
 *   - action=verify_code : 6桁認証コードを検証して30日端末Cookieを発行
 *   - action=revoke      : 【管理者用】指定職員の端末連携を即時失効（スマホ紛失時）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_helper.php';

// POSTリクエストのみ受付
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = trim($input['action'] ?? '');

switch ($action) {
    // -------------------------------------------------------------
    // 1. 職員の登録LINE宛てに6桁ワンタイムコードを送信
    // -------------------------------------------------------------
    case 'send_code':
        $staff_id = (int)($input['staff_id'] ?? 0);
        if ($staff_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '職員が指定されていません。']);
            exit;
        }

        // 職員存在確認
        $stmt = $pdo->prepare("SELECT staff_id, staff_name, line_user_id FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt->execute([':id' => $staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '職員データが見つかりません。']);
            exit;
        }

        if (empty(trim($staff['line_user_id'] ?? ''))) {
            echo json_encode([
                'success' => false,
                'error'   => 'LINE IDが登録されていません。まずは院内PCからログインして「おの肛門科」公式LINEと友だち連携（4桁コード）を行ってください。',
                'no_line' => true
            ]);
            exit;
        }

        // LINEコード送信
        $res = sendStaffLineAuthCode($pdo, $staff_id);
        if (!$res['success']) {
            echo json_encode(['success' => false, 'error' => $res['error']]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => '「おの肛門科」公式LINE宛てに6桁の認証コードを送信しました。トーク画面をご確認ください。'
        ]);
        exit;

    // -------------------------------------------------------------
    // 2. 6桁コードを検証して30日端末Cookieを発行＆ログイン
    // -------------------------------------------------------------
    case 'verify_code':
        $staff_id = (int)($input['staff_id'] ?? 0);
        $code = trim((string)($input['code'] ?? ''));
        $input_pin = trim((string)($input['pin_code'] ?? ''));
        $redirect_param = trim((string)($input['redirect'] ?? ''));

        if ($staff_id <= 0 || empty($code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '職員と認証コードを指定してください。']);
            exit;
        }

        // 暗証番号（山本太・医師など）がある場合は事前にPINチェック
        $stmt_chk = $pdo->prepare("SELECT pin_code, staff_name, is_admin FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt_chk->execute([':id' => $staff_id]);
        $chk_staff = $stmt_chk->fetch(PDO::FETCH_ASSOC);

        if (!$chk_staff) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '職員データが見つかりません。']);
            exit;
        }

        $required_pin = trim($chk_staff['pin_code'] ?? '');
        if (!empty($required_pin)) {
            if ($input_pin !== $required_pin) {
                echo json_encode([
                    'success'   => false,
                    'error'     => '暗証番号が正しくありません。（' . htmlspecialchars($chk_staff['staff_name']) . ' さん）',
                    'pin_error' => true
                ]);
                exit;
            }
        }

        // LINE認証コード照合 ＆ 30日Cookie発行
        $res_auth = verifyStaffLineAuthCodeAndIssueCookie($pdo, $staff_id, $code);
        if (!$res_auth['success']) {
            echo json_encode(['success' => false, 'error' => $res_auth['error']]);
            exit;
        }

        $staff = $res_auth['staff'];
        $default_dest = ((int)$staff['staff_id'] === 15 || !empty($staff['is_admin'])) ? 'jimucho_dashboard.php' : 'index.php';
        $redirect_url = !empty($redirect_param) ? $redirect_param : $default_dest;

        echo json_encode([
            'success'  => true,
            'message'  => '端末認証に成功しました。このスマホを30日間記憶します。',
            'redirect' => $redirect_url
        ]);
        exit;

    // -------------------------------------------------------------
    // 3. 【管理者用】指定職員の端末連携を即時失効（スマホ紛失時など）
    // -------------------------------------------------------------
    case 'revoke':
        // 管理者権限チェック（セッション確認）
        $is_admin = (!empty($_SESSION['staff_id']) && ((int)$_SESSION['staff_id'] === 15 || !empty($_SESSION['is_admin'])));
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '権限がありません。管理者のみ実行可能です。']);
            exit;
        }

        $target_staff_id = (int)($input['staff_id'] ?? 0);
        if ($target_staff_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '対象職員が指定されていません。']);
            exit;
        }

        // 失効実行（バージョン+1）
        $ok = revokeStaffDeviceTokens($pdo, $target_staff_id);
        if ($ok) {
            // 対象職員の名前を取得
            $stmt_n = $pdo->prepare("SELECT staff_name FROM staff WHERE staff_id = :id");
            $stmt_n->execute([':id' => $target_staff_id]);
            $name = $stmt_n->fetchColumn() ?: '対象職員';

            echo json_encode([
                'success' => true,
                'message' => "{$name} さんのスマホ端末連携を即時リセット（失効）しました。落とした端末からのアクセスはすべて遮断されます。"
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => '失効処理に失敗しました。']);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '不正なアクションです。']);
        exit;
}
