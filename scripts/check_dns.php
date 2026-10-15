<?php
$hosts = [
    'ono.or.jp',
    'www.ono.or.jp',
    'kawara.ono.or.jp',
    'bcp.ono.or.jp',
    'app.ono.or.jp',
    'kawaraban.ono.or.jp'
];

foreach ($hosts as $h) {
    $ip = @gethostbyname($h);
    if ($ip !== $h) {
        echo "{$h} => {$ip}\n";
    } else {
        echo "{$h} => Not Found (NXDOMAIN)\n";
    }
}
