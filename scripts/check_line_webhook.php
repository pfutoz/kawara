<?php
require_once __DIR__ . '/../includes/line_helper.php';

echo "=== LINE Developers Webhook エンドポイント確認・更新 ===\n";

$target_webhook = 'https://kawara.ono.or.jp/kawara/api/line_webhook.php';

// 現在のWebhook Endpointを取得
$url = 'https://api.line.me/v2/bot/channel/webhook/endpoint';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
]);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "現在のLINE設定: HTTP {$http_code} => {$resp}\n";

// もし現在のURLと違っていれば更新する
$data = json_decode($resp, true);
$current_ep = $data['endpoint'] ?? '';

if ($current_ep !== $target_webhook) {
    echo "🔄 Webhook URLを {$target_webhook} に更新します...\n";
    $up_res = updateLineWebhookEndpoint($target_webhook);
    echo "更新結果: " . json_encode($up_res, JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "✅ 既に正しいURLが設定されています。\n";
}

// Webhookのテスト検証を実行
$test_url = 'https://api.line.me/v2/bot/channel/webhook/test';
$ch_t = curl_init($test_url);
curl_setopt($ch_t, CURLOPT_POST, true);
curl_setopt($ch_t, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_t, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch_t, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch_t, CURLOPT_POSTFIELDS, json_encode(['endpoint' => $target_webhook]));
curl_setopt($ch_t, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
]);
$test_resp = curl_exec($ch_t);
$test_code = curl_getinfo($ch_t, CURLINFO_HTTP_CODE);
curl_close($ch_t);

echo "Webhook接続性テスト (LINE社からの疎通テスト): HTTP {$test_code} => {$test_resp}\n";
