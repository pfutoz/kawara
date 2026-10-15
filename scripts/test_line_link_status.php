<?php
$url = 'http://192.168.1.16/kawara/api/line_link_status.php?action=status&staff_id=15';
echo "Testing: {$url}\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);

echo "HTTP Code: {$code}\n";
echo "Response: " . substr($resp, 0, 500) . "\n";
if ($err) echo "Curl Err: {$err}\n";
