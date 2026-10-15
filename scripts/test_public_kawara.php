<?php
$urls = [
    'https://kawara.ono.or.jp/kawara/login.php',
    'https://kawara.ono.or.jp/kawara/api/line_webhook.php'
];

foreach ($urls as $url) {
    echo "Testing: {$url} ... ";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $res = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    echo "HTTP Status: {$c} Length: " . strlen($res) . "\n";
    if ($err) echo "  Err: {$err}\n";
}
