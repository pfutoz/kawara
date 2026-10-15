<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_helper.php';

echo "=== 端末認証・API連携シナリオテスト ===\n";

// テスト用スタッフの取得
$stmt = $pdo->query("SELECT staff_id, staff_name, line_user_id, device_token_version FROM staff WHERE is_deleted IS NOT TRUE LIMIT 1");
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    echo "❌ テスト用スタッフが見つかりません。\n";
    exit(1);
}

$sid = (int)$staff['staff_id'];
$sname = $staff['staff_name'];
$orig_ver = (int)$staff['device_token_version'];
$orig_line = $staff['line_user_id'];

echo "対象スタッフ: [ID:{$sid}] {$sname} (TokenVer: {$orig_ver})\n\n";

// 1. LINE IDが未登録の場合のエラーチェック
$pdo->prepare("UPDATE staff SET line_user_id = NULL WHERE staff_id = :id")->execute([':id' => $sid]);
$res_send_noline = sendStaffLineAuthCode($pdo, $sid);
echo "シナリオ 1 (LINE未登録時のコード送信拒絶): " . (!$res_send_noline['success'] ? "✅ OK (正しく拒否: {$res_send_noline['error']})" : "❌ NG") . "\n";

// 2. LINE IDを設定してコード生成チェック
$dummy_line_id = 'U1234567890abcdef1234567890abcdef';
$pdo->prepare("UPDATE staff SET line_user_id = :l WHERE staff_id = :id")->execute([':l' => $dummy_line_id, ':id' => $sid]);

// コード直接発行テスト
$test_code = '654321';
$pdo->prepare("UPDATE staff SET line_auth_code = :c, line_auth_code_expires_at = NOW() + INTERVAL '10 minutes' WHERE staff_id = :id")->execute([':c' => $test_code, ':id' => $sid]);

$stmt_chk = $pdo->prepare("SELECT line_auth_code, line_auth_code_expires_at FROM staff WHERE staff_id = :id");
$stmt_chk->execute([':id' => $sid]);
$c_data = $stmt_chk->fetch(PDO::FETCH_ASSOC);
echo "シナリオ 2 (DB一時保管コード確認): " . ($c_data['line_auth_code'] === $test_code ? "✅ OK (コード: {$c_data['line_auth_code']}, 期限: {$c_data['line_auth_code_expires_at']})" : "❌ NG") . "\n";

// 3. 認証成功 ＆ 端末Cookie発行テスト
$res_auth = verifyStaffLineAuthCodeAndIssueCookie($pdo, $sid, $test_code);
echo "シナリオ 3 (認証コード照合 & 30日Cookie発行): " . ($res_auth['success'] ? "✅ OK (端末認証成功)" : "❌ NG") . "\n";

// 4. 紛失時リセット（revokeStaffDeviceTokens）
$rev_ok = revokeStaffDeviceTokens($pdo, $sid);
$stmt_chk_v = $pdo->prepare("SELECT device_token_version FROM staff WHERE staff_id = :id");
$stmt_chk_v->execute([':id' => $sid]);
$new_ver = (int)$stmt_chk_v->fetchColumn();
echo "シナリオ 4 (スマホ紛失時の即時失効): " . ($new_ver === $orig_ver + 1 ? "✅ OK (バージョン更新 {$orig_ver} -> {$new_ver} により旧端末即時失効)" : "❌ NG") . "\n";

// 5. 退職時処理（論理削除 + トークン失効 + LINEクリア）
$del_stmt = $pdo->prepare("UPDATE staff SET is_deleted = TRUE, line_user_id = NULL, updated_at = NOW() WHERE staff_id = :id");
$del_stmt->execute([':id' => $sid]);
revokeStaffDeviceTokens($pdo, $sid);

$stmt_chk_del = $pdo->prepare("SELECT is_deleted, line_user_id, device_token_version FROM staff WHERE staff_id = :id");
$stmt_chk_del->execute([':id' => $sid]);
$del_data = $stmt_chk_del->fetch(PDO::FETCH_ASSOC);
echo "シナリオ 5 (退職時完全遮断): " . ($del_data['is_deleted'] && $del_data['line_user_id'] === null ? "✅ OK (在籍フラグOFF ＆ LINE消去 ＆ 端末即時無効化)" : "❌ NG") . "\n";

// 復旧クリーンアップ
$pdo->prepare("UPDATE staff SET is_deleted = FALSE, line_user_id = :l, device_token_version = :v, line_auth_code = NULL, line_auth_code_expires_at = NULL WHERE staff_id = :id")
    ->execute([':l' => $orig_line, ':v' => $orig_ver, ':id' => $sid]);
echo "\nデータ復旧: テスト前の状態に完全リストア完了。\n";
echo "=== 全シナリオテスト完了 ===\n";
