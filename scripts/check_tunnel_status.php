<?php
$targets = [
    'https://ono.or.jp',
    'https://ono.or.jp/kawara/login.php'
];

foreach ($targets as $url) {
    echo "Checking {$url} ... ";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code > 0) {
        echo "HTTP Status: {$code}\n";
    } else {
        echo "Failed: {$err}\n";
    }
}
