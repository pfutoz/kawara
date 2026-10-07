<?php
/**
 * 院内かわら版 - LINE Developers Webhook URL 自動同期スクリプト
 */
require_once __DIR__ . '/../includes/line_helper.php';

echo "=== LINE Webhook Endpoint Auto-Sync ===\n";

$status = getLineTunnelStatus();
echo "Tunnel Online: " . ($status['is_online'] ? 'YES' : 'NO') . "\n";
echo "Tunnel Base URL: " . ($status['url'] ?: '(none)') . "\n";
echo "Webhook Target URL: " . ($status['webhook_url'] ?: '(none)') . "\n\n";

if (!$status['is_online'] || empty($status['webhook_url'])) {
    echo "❌ トンネルがオフラインのため更新を中止しました。\n";
    exit(1);
}

$target_webhook = $status['webhook_url'];

echo "🔄 Updating LINE Developers Webhook Endpoint to: {$target_webhook}\n";
$update_res = updateLineWebhookEndpoint($target_webhook);

if ($update_res['success']) {
    echo "✅ Webhook URL successfully updated! (HTTP {$update_res['http_code']})\n\n";
    
    echo "🔍 Testing Webhook Endpoint reachability...\n";
    $test_res = testLineWebhookEndpoint($target_webhook);
    echo "Test Result (HTTP {$test_res['http_code']}):\n";
    echo $test_res['response'] . "\n";
} else {
    echo "❌ Failed to update webhook URL (HTTP {$update_res['http_code']}):\n";
    echo ($update_res['response'] ?? $update_res['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

echo "=== Done ===\n";
