<?php
/**
 * 職員情報提供API (stf -> kawara 連携用)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$json_file = __DIR__ . '/staff_data.json';

if (!file_exists($json_file)) {
    http_response_code(404);
    echo json_encode(['error' => 'staff_data.json not found']);
    exit;
}

$raw = file_get_contents($json_file);
$utf8 = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win, SJIS, UTF-8');
$list = json_decode($utf8, true);

if (!is_array($list)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse staff_data.json']);
    exit;
}

// レスポンスの構築
$result = [];
foreach ($list as $s) {
    $result[] = [
        'staff_code'      => $s['staff_code'] ?? '',
        'name'            => $s['name'] ?? '',
        'name_kana'       => $s['name_kana'] ?? '',
        'department'      => $s['department'] ?? '',
        'job_title'       => $s['job_title'] ?? '',
        'job_group'       => $s['job_group'] ?? '',
        'employment_type' => $s['employment_type'] ?? '',
        'mobile_no'       => $s['mobile_no'] ?? '',
        'phone_no'        => $s['phone_no'] ?? '',
        'postal_code'     => $s['postal_code'] ?? '',
        'address'         => $s['address'] ?? '',
        'birth_date'      => $s['birth_date'] ?? '',
        'hire_date'       => $s['hire_date'] ?? ''
    ];
}

echo json_encode([
    'success' => true,
    'count'   => count($result),
    'data'    => $result
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
