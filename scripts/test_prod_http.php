<?php
$url = 'http://192.168.1.16/kawara/login.php';
echo "Testing HTTP GET {$url} ...\n";

$ctx = stream_context_create([
    'http' => [
        'timeout' => 4,
        'ignore_errors' => true
    ]
]);

$start = microtime(true);
$html = @file_get_contents($url, false, $ctx);
$dur = round((microtime(true) - $start) * 1000, 1);

if ($html !== false) {
    echo "✅ Success! HTTP Response received in {$dur}ms. Length: " . strlen($html) . " bytes\n";
    if (strpos($html, '院内ネットワーク接続中') !== false) {
        echo "✅ '院内ネットワーク接続中' badge found in response!\n";
    }
} else {
    $err = error_get_last();
    echo "⚠️ Failed after {$dur}ms: " . ($err['message'] ?? 'Unknown error') . "\n";
}
