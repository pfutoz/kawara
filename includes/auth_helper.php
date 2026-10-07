<?php
/**
 * 院内かわら版 - 共通認証 ＆ 端末自動固定（Remember Device）ヘルパー
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 認証署名用秘密鍵（外部改ざん防止）
if (!defined('KAWARA_AUTH_SECRET')) {
    define('KAWARA_AUTH_SECRET', 'kawara_onokai_secure_device_secret_2026_xyz');
}

/**
 * 端末記憶用Cookie（180日間有効）を発行する
 * 
 * @param int $staff_id
 * @param int $days 有効日数（デフォルト180日）
 */
function issueDeviceRememberCookie($staff_id, $days = 180) {
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0) return false;

    $expires_at = time() + ($days * 86400);
    $payload = "{$staff_id}:{$expires_at}";
    $signature = hash_hmac('sha256', $payload, KAWARA_AUTH_SECRET);
    $cookie_value = base64_encode("{$payload}:{$signature}");

    // 180日間保持するCookieを設定
    $cookie_options = [
        'expires'  => $expires_at,
        'path'     => '/kawara',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    setcookie('kawara_device_token', $cookie_value, $cookie_options);
    return true;
}

/**
 * 端末記憶用Cookieを削除する
 */
function clearDeviceRememberCookie() {
    $cookie_options = [
        'expires'  => time() - 86400,
        'path'     => '/kawara',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('kawara_device_token', '', $cookie_options);
    unset($_COOKIE['kawara_device_token']);
}

/**
 * 端末記憶Cookieからスタッフ情報を検証して取得する
 * 
 * @param PDO $pdo
 * @return array|null 認証成功時はスタッフ情報連想配列、失敗時はnull
 */
function verifyDeviceRememberCookie($pdo) {
    if (empty($_COOKIE['kawara_device_token'])) {
        return null;
    }

    $raw = base64_decode($_COOKIE['kawara_device_token'], true);
    if (!$raw) return null;

    $parts = explode(':', $raw);
    if (count($parts) !== 3) return null;

    list($staff_id_str, $expires_at_str, $signature) = $parts;
    $staff_id = (int)$staff_id_str;
    $expires_at = (int)$expires_at_str;

    // 期限切れチェック
    if ($expires_at < time()) {
        clearDeviceRememberCookie();
        return null;
    }

    // 署名検証
    $payload = "{$staff_id}:{$expires_at}";
    $expected_sig = hash_hmac('sha256', $payload, KAWARA_AUTH_SECRET);
    if (!hash_equals($expected_sig, $signature)) {
        clearDeviceRememberCookie();
        return null;
    }

    // DB存在確認
    try {
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt->execute([':id' => $staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($staff) {
            return $staff;
        } else {
            clearDeviceRememberCookie();
            return null;
        }
    } catch (Exception $e) {
        return null;
    }
}

/**
 * スタッフのログインセッションを確立する
 * 
 * @param array $staff
 */
function setupStaffSession($staff) {
    $_SESSION['staff_id'] = (int)$staff['staff_id'];
    $_SESSION['staff_name'] = $staff['staff_name'];
    $_SESSION['role'] = $staff['role'];
    $_SESSION['is_admin'] = (bool)($staff['is_admin'] ?? false);
    $_SESSION['can_toggle_disaster'] = (bool)($staff['can_toggle_disaster'] ?? false);
    $_SESSION['last_activity'] = time();

    // 最近使ったスタッフ記録
    if (!isset($_SESSION['recent_staff_ids'])) {
        $_SESSION['recent_staff_ids'] = [];
    }
    $_SESSION['recent_staff_ids'] = array_diff($_SESSION['recent_staff_ids'], [$staff['staff_id']]);
    array_unshift($_SESSION['recent_staff_ids'], (int)$staff['staff_id']);
    $_SESSION['recent_staff_ids'] = array_slice($_SESSION['recent_staff_ids'], 0, 10);
}

/**
 * ログイン状態を検証し、未ログインならCookieからの自動復元を試行する
 * それでも未ログインならログイン画面へリダイレクト
 * 
 * @param PDO $pdo
 * @param string $redirect_target リダイレクト後の戻り先URL（空なら現在のURL）
 * @return array ログイン中のスタッフ情報
 */
function checkAuthOrAutoLogin($pdo, $redirect_target = '') {
    // 1. セッションが存在する場合
    if (!empty($_SESSION['staff_id'])) {
        $sid = (int)$_SESSION['staff_id'];
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt->execute([':id' => $sid]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($staff) {
            $_SESSION['last_activity'] = time();
            return $staff;
        } else {
            // 削除されたなどの場合はセッション破棄
            $_SESSION = [];
        }
    }

    // 2. セッションが切れていても、端末記憶Cookieがあれば自動復元！
    $remembered_staff = verifyDeviceRememberCookie($pdo);
    if ($remembered_staff) {
        setupStaffSession($remembered_staff);
        // Cookieの有効期限をさらに180日延長（ローリング延長）
        issueDeviceRememberCookie($remembered_staff['staff_id'], 180);
        return $remembered_staff;
    }

    // 3. 認証不可なら login.php へリダイレクト
    if (empty($redirect_target)) {
        $redirect_target = $_SERVER['REQUEST_URI'] ?? 'index.php';
    }
    $login_url = '/kawara/login.php?redirect=' . urlencode($redirect_target);
    header("Location: {$login_url}");
    exit;
}
