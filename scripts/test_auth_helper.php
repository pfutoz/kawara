<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_helper.php';

echo "=== auth_helper.php 機能テスト ===\n";

// 1. IP判定テスト
$_SERVER['REMOTE_ADDR'] = '192.168.1.50';
echo "Test 1 (Internal IP 192.168.1.50): " . (isHospitalInternalAccess() ? "✅ OK (Internal)" : "❌ NG") . "\n";

$_SERVER['REMOTE_ADDR'] = '203.0.113.195'; // 外部IP
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
echo "Test 2 (External IP 203.0.113.195): " . (!isHospitalInternalAccess() ? "✅ OK (External)" : "❌ NG") . "\n";

$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.168.1.10'; // CFヘッダー
echo "Test 3 (CF-Connecting-IP 192.168.1.10): " . (isHospitalInternalAccess() ? "✅ OK (Internal)" : "❌ NG") . "\n";

// 2. Cookie生成 & 検証テスト
// テスト対象スタッフ（ID 15: 山本太 など）を取得
$stmt = $pdo->query("SELECT staff_id, staff_name, line_user_id, device_token_version FROM staff WHERE is_deleted IS NOT TRUE LIMIT 1");
$test_staff = $stmt->fetch(PDO::FETCH_ASSOC);

if ($test_staff) {
    $sid = (int)$test_staff['staff_id'];
    echo "\nTest Staff: ID={$sid} Name={$test_staff['staff_name']} Version={$test_staff['device_token_version']}\n";

    // Cookie発行シミュレーション
    $expires_at = time() + (30 * 86400);
    $ver = (int)$test_staff['device_token_version'];
    $payload = "{$sid}:{$ver}:{$expires_at}";
    $signature = hash_hmac('sha256', $payload, KAWARA_AUTH_SECRET);
    $_COOKIE['kawara_device_token'] = base64_encode("{$payload}:{$signature}");

    // 検証
    $verified = verifyDeviceRememberCookie($pdo);
    echo "Test 4 (Cookie Verify): " . ($verified && $verified['staff_id'] == $sid ? "✅ OK (Verified successfully)" : "❌ NG") . "\n";

    // 紛失・失効テスト（バージョンをインクリメント）
    revokeStaffDeviceTokens($pdo, $sid);
    $verified_after_revoke = verifyDeviceRememberCookie($pdo);
    echo "Test 5 (Revoke Test): " . ($verified_after_revoke === null ? "✅ OK (Correctly rejected after revoke)" : "❌ NG") . "\n";

    // 正常復旧（元に戻す）
    $pdo->prepare("UPDATE staff SET device_token_version = :v WHERE staff_id = :id")->execute([':v' => $ver, ':id' => $sid]);
    echo "Test 5 cleanup: version restored to {$ver}\n";

    // 6. LINE認証コード照合テスト
    $test_code = '123456';
    $pdo->prepare("UPDATE staff SET line_auth_code = :c, line_auth_code_expires_at = NOW() + INTERVAL '10 minutes' WHERE staff_id = :id")
        ->execute([':c' => $test_code, ':id' => $sid]);

    // 間違ったコード
    $res_wrong = verifyStaffLineAuthCodeAndIssueCookie($pdo, $sid, '999999');
    echo "Test 6-1 (Wrong Code): " . (!$res_wrong['success'] ? "✅ OK (Rejected wrong code)" : "❌ NG") . "\n";

    // 正しいコード
    $res_ok = verifyStaffLineAuthCodeAndIssueCookie($pdo, $sid, $test_code);
    echo "Test 6-2 (Correct Code): " . ($res_ok['success'] ? "✅ OK (Verified & issued cookie)" : "❌ NG") . "\n";

    // 二重使用防止（1回使ったら消費されて消えているか）
    $res_reuse = verifyStaffLineAuthCodeAndIssueCookie($pdo, $sid, $test_code);
    echo "Test 6-3 (Code Replay Prevention): " . (!$res_reuse['success'] ? "✅ OK (Cannot reuse consumed code)" : "❌ NG") . "\n";
} else {
    echo "❌ No staff found for test.\n";
}

echo "\n=== 全テスト完了 ===\n";
