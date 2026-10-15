<?php
/**
 * 院内かわら版 - 共通認証 ＆ 端末自動固定（Remember Device）ヘルパー
 * 
 * 機能:
 *  - 端末記憶Cookie管理（30日間有効）
 *  - 端末トークン世代番号（device_token_version）による紛失時・退職時の即時失効
 *  - 院内LAN/院外アクセスの自動判定（Cloudflare CF-Connecting-IP対応）
 *  - LINEワンタイム認証コード（6桁・10分間有効）の発行・送信・検証
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 認証署名用秘密鍵（外部改ざん防止）
if (!defined('KAWARA_AUTH_SECRET')) {
    define('KAWARA_AUTH_SECRET', 'kawara_onokai_secure_device_secret_2026_xyz');
}

// 端末記憶Cookieの有効期間（日数）
if (!defined('KAWARA_DEVICE_COOKIE_DAYS')) {
    define('KAWARA_DEVICE_COOKIE_DAYS', 30);
}

/**
 * クライアントの送信元IPアドレスを取得（Cloudflareヘッダー優先）
 * 
 * @return string
 */
function getClientIpAddress(): string {
    // Cloudflare経由の場合は CF-Connecting-IP を最優先
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    // プロキシ経由の場合
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * 院内LAN（有線LAN・院内Wi-Fi）またはローカル環境からのアクセスか判定
 * 
 * @return bool
 */
function isHospitalInternalAccess(): bool {
    $ip = getClientIpAddress();

    // 院内ローカルプライベートIP帯（192.168.*, 10.*, 172.16-31.*, 127.0.0.1, ::1）
    $local_prefixes = [
        '192.168.',
        '10.',
        '172.16.', '172.17.', '172.18.', '172.19.',
        '172.20.', '172.21.', '172.22.', '172.23.',
        '172.24.', '172.25.', '172.26.', '172.27.',
        '172.28.', '172.29.', '172.30.', '172.31.',
        '127.0.0.1',
        '::1'
    ];

    foreach ($local_prefixes as $prefix) {
        if (strpos($ip, $prefix) === 0) {
            return true;
        }
    }

    // 病院の固定グローバルIPが定義されている場合の合致判定
    if (defined('HOSPITAL_GLOBAL_IP') && !empty(HOSPITAL_GLOBAL_IP)) {
        $allowed = is_array(HOSPITAL_GLOBAL_IP) ? HOSPITAL_GLOBAL_IP : [HOSPITAL_GLOBAL_IP];
        if (in_array($ip, $allowed, true)) {
            return true;
        }
    }

    return false;
}

/**
 * 端末記憶用Cookie（30日間有効）を発行する
 * 
 * @param int      $staff_id
 * @param int      $days    有効日数（デフォルト30日）
 * @param int|null $version トークンバージョン（nullならDBまたは初期値1）
 * @param PDO|null $pdo     DB接続（バージョン取得用）
 * @return bool
 */
function issueDeviceRememberCookie($staff_id, $days = KAWARA_DEVICE_COOKIE_DAYS, $version = null, $pdo = null) {
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0) return false;

    // バージョン未指定の場合、可能ならDBから取得
    if ($version === null) {
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT device_token_version FROM staff WHERE staff_id = :id");
            $stmt->execute([':id' => $staff_id]);
            $version = (int)$stmt->fetchColumn() ?: 1;
        } else {
            $version = 1;
        }
    } else {
        $version = (int)$version;
    }

    $expires_at = time() + ($days * 86400);
    $payload = "{$staff_id}:{$version}:{$expires_at}";
    $signature = hash_hmac('sha256', $payload, KAWARA_AUTH_SECRET);
    $cookie_value = base64_encode("{$payload}:{$signature}");

    // 30日間保持するHttpOnly Cookieを設定
    if (!headers_sent()) {
        $cookie_options = [
            'expires'  => $expires_at,
            'path'     => '/kawara',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        setcookie('kawara_device_token', $cookie_value, $cookie_options);
    }
    return true;
}

/**
 * 端末記憶用Cookieを削除する
 */
function clearDeviceRememberCookie() {
    if (!headers_sent()) {
        $cookie_options = [
            'expires'  => time() - 86400,
            'path'     => '/kawara',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        setcookie('kawara_device_token', '', $cookie_options);
    }
    unset($_COOKIE['kawara_device_token']);
}

/**
 * 端末記憶Cookieからスタッフ情報を検証して取得する
 * （有効期限・改ざん署名・在職中フラグ・トークン世代番号を厳密に照合）
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
    $count = count($parts);

    if ($count === 4) {
        // 新形式: staff_id:version:expires_at:signature
        list($staff_id_str, $version_str, $expires_at_str, $signature) = $parts;
        $staff_id = (int)$staff_id_str;
        $version = (int)$version_str;
        $expires_at = (int)$expires_at_str;
        $payload = "{$staff_id}:{$version}:{$expires_at}";
    } elseif ($count === 3) {
        // 旧形式互換: staff_id:expires_at:signature
        list($staff_id_str, $expires_at_str, $signature) = $parts;
        $staff_id = (int)$staff_id_str;
        $version = 1;
        $expires_at = (int)$expires_at_str;
        $payload = "{$staff_id}:{$expires_at}";
    } else {
        return null;
    }

    // 1. 期限切れチェック
    if ($expires_at < time()) {
        clearDeviceRememberCookie();
        return null;
    }

    // 2. 署名検証
    $expected_sig = hash_hmac('sha256', $payload, KAWARA_AUTH_SECRET);
    if (!hash_equals($expected_sig, $signature)) {
        clearDeviceRememberCookie();
        return null;
    }

    // 3. DB存在確認 ＆ 在籍チェック ＆ トークンバージョン照合
    try {
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt->execute([':id' => $staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            // 退職等で削除された場合はCookie消去
            clearDeviceRememberCookie();
            return null;
        }

        // スマホ紛失等による強制失効チェック
        $current_version = (int)($staff['device_token_version'] ?? 1);
        if ($version !== $current_version) {
            // バージョン不一致（リセット済み）のためCookie削除
            clearDeviceRememberCookie();
            return null;
        }

        return $staff;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * スタッフの全端末トークンを即時失効させる（スマホ紛失時・退職時用）
 * 
 * @param PDO $pdo
 * @param int $staff_id
 * @return bool
 */
function revokeStaffDeviceTokens($pdo, $staff_id) {
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0) return false;

    $stmt = $pdo->prepare("UPDATE staff SET device_token_version = COALESCE(device_token_version, 1) + 1, updated_at = NOW() WHERE staff_id = :id");
    return $stmt->execute([':id' => $staff_id]);
}

/**
 * スタッフの登録LINE宛てにワンタイム認証コード（6桁）をプッシュ送信する
 * 
 * @param PDO $pdo
 * @param int $staff_id
 * @return array ['success' => bool, 'message' => string, 'error' => string]
 */
function sendStaffLineAuthCode($pdo, $staff_id) {
    require_once __DIR__ . '/line_helper.php';

    $staff_id = (int)$staff_id;
    $stmt = $pdo->prepare("SELECT staff_id, staff_name, line_user_id FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
    $stmt->execute([':id' => $staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$staff) {
        return ['success' => false, 'error' => '職員データが見つかりません。'];
    }

    if (empty(trim($staff['line_user_id'] ?? ''))) {
        return ['success' => false, 'error' => 'LINE IDが登録されていません。院内PCから「おの肛門科」公式LINEと連携してください。'];
    }

    // 6桁の数字コードを生成
    try {
        $code = sprintf('%06d', random_int(100000, 999999));
    } catch (Exception $e) {
        $code = sprintf('%06d', mt_rand(100000, 999999));
    }

    // 有効期限: 10分後（PostgreSQL INTERVAL）
    $stmt_up = $pdo->prepare("UPDATE staff SET line_auth_code = :code, line_auth_code_expires_at = NOW() + INTERVAL '10 minutes', updated_at = NOW() WHERE staff_id = :id");
    $stmt_up->execute([
        ':code' => $code,
        ':id'   => $staff_id
    ]);

    // LINEプッシュメッセージ本文
    $name = htmlspecialchars($staff['staff_name']);
    $message = "【院内かわら版】スマホ接続 認証コード\n\n"
             . "{$name} さんのスマホ接続用認証コードです。\n\n"
             . "🔑 認証コード: {$code}\n\n"
             . "※ 有効期限は10分間です。\n"
             . "※ かわら版画面のコード入力欄に入力してください。\n"
             . "※ 心当たりがない場合は入力を中止し、事務長までお知らせください。";

    // LINE送信（Multicast / Push API）
    $res = sendLineNotification($pdo, $staff_id, $message);
    if (!$res['success']) {
        $err = !empty($res['errors']) ? implode(', ', $res['errors']) : 'LINE送信に失敗しました。';
        return ['success' => false, 'error' => $err];
    }

    return [
        'success' => true,
        'message' => 'ご自身のLINE（おの肛門科）宛てに6桁の認証コードを送信しました。'
    ];
}

/**
 * LINE認証コードを照合し、正しければ30日間の端末記憶Cookieを発行する
 * 
 * @param PDO    $pdo
 * @param int    $staff_id
 * @param string $input_code
 * @return array ['success' => bool, 'staff' => array|null, 'error' => string]
 */
function verifyStaffLineAuthCodeAndIssueCookie($pdo, $staff_id, $input_code) {
    $staff_id = (int)$staff_id;
    $input_code = trim((string)$input_code);

    if ($staff_id <= 0 || empty($input_code)) {
        return ['success' => false, 'error' => '認証コードを入力してください。'];
    }

    $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
    $stmt->execute([':id' => $staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$staff) {
        return ['success' => false, 'error' => '職員データが見つかりません。'];
    }

    $stored_code = trim($staff['line_auth_code'] ?? '');
    $expires_at = !empty($staff['line_auth_code_expires_at']) ? strtotime($staff['line_auth_code_expires_at']) : 0;

    if (empty($stored_code)) {
        return ['success' => false, 'error' => '認証コードが発行されていないか、既に使用済みです。もう一度送信してください。'];
    }

    if ($expires_at < time()) {
        return ['success' => false, 'error' => '認証コードの有効期限（10分）が切れています。再送信してください。'];
    }

    if ($stored_code !== $input_code) {
        return ['success' => false, 'error' => '認証コードが一致しません。もう一度お確かめください。'];
    }

    // 認証成功！ワンタイムコードをクリア ＆ 最終端末認証日時を更新
    $stmt_cl = $pdo->prepare("UPDATE staff SET line_auth_code = NULL, line_auth_code_expires_at = NULL, last_device_authenticated_at = NOW(), updated_at = NOW() WHERE staff_id = :id");
    $stmt_cl->execute([':id' => $staff_id]);

    // 30日間の端末記憶Cookieを発行
    $version = (int)($staff['device_token_version'] ?? 1);
    issueDeviceRememberCookie($staff_id, KAWARA_DEVICE_COOKIE_DAYS, $version, $pdo);

    // セッション確立
    setupStaffSession($staff);

    return [
        'success' => true,
        'staff'   => $staff
    ];
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
        // Cookieの有効期限をさらに30日ローリング延長
        $version = (int)($remembered_staff['device_token_version'] ?? 1);
        issueDeviceRememberCookie($remembered_staff['staff_id'], KAWARA_DEVICE_COOKIE_DAYS, $version, $pdo);
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
