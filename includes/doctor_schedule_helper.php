<?php
/**
 * 医療法人小野会 医師予定表連携ヘルパー
 * File: includes/doctor_schedule_helper.php
 * 
 * 医師予定表システム (yotei) の外部連携APIと通信し、
 * 本日サマリー、期間内の医師予定、休診情報、申し送りメモを取得します。
 */

/**
 * 医師予定表APIのベースURLを取得
 */
function get_doctor_api_base_url(): string {
    // 開発環境やサーバー構成に合わせてURLを柔軟に解決
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return "{$scheme}://{$host}/yotei/api";
}

/**
 * 医師予定表APIを呼び出す汎用関数
 *
 * @param string $endpoint APIファイル名（例: 'today.php', 'events.php'）
 * @param array $params クエリパラメータ
 * @param int $timeout タイムアウト秒数
 * @return array|null 成功時はレスポンスの 'data' 配列、失敗時は null
 */
function call_doctor_api(string $endpoint, array $params = [], int $timeout = 2): ?array {
    $baseUrl = get_doctor_api_base_url();
    $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    // cURLが使える場合はcURL、なければfile_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: Kawara-DoctorScheduleClient/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // curl_close is deprecated in PHP 8.5+ as curl handle is automatically closed

        if ($response === false || $httpCode !== 200) {
            error_log("[doctor_schedule_helper] API call failed: {$url} (HTTP {$httpCode}) Error: {$err}");
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header'  => "Accept: application/json\r\nUser-Agent: Kawara-DoctorScheduleClient/1.0\r\n"
            ]
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            error_log("[doctor_schedule_helper] file_get_contents failed: {$url}");
            return null;
        }
    }

    $data = json_decode($response, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        error_log("[doctor_schedule_helper] Invalid JSON or status: {$url}");
        return null;
    }

    return $data['data'] ?? null;
}

/**
 * 指定日の医師サマリー（休診・診療・全体会・メモ）を取得
 *
 * @param string|null $date 'YYYY-MM-DD' または null(当日)
 * @return array|null
 */
function fetch_doctor_today_summary(?string $date = null): ?array {
    $params = [];
    if (!empty($date)) {
        $params['date'] = $date;
    }
    return call_doctor_api('today.php', $params);
}

/**
 * 期間内の医師予定一覧を取得
 *
 * @param string $startDate 'YYYY-MM-DD'
 * @param string $endDate 'YYYY-MM-DD'
 * @param array $options フィルタオプション (event_type, doctor_id, department_id, expand_period)
 * @return array イベント配列
 */
function fetch_doctor_events(string $startDate, string $endDate, array $options = []): array {
    $params = array_merge([
        'start_date'    => $startDate,
        'end_date'      => $endDate,
        'expand_period' => 1
    ], $options);

    $res = call_doctor_api('events.php', $params);
    return is_array($res) ? $res : [];
}

/**
 * 医師予定表の最新申し送りメモを取得
 *
 * @return array|null
 */
function fetch_doctor_memo(): ?array {
    return call_doctor_api('memo.php', ['format' => 'json']);
}

/**
 * 医師予定イベント配列を日付キーの連想配列にマッピング
 *
 * @param array $events fetch_doctor_events の戻り値
 * @return array ['YYYY-MM-DD' => [event, ...]]
 */
function map_doctor_events_by_date(array $events): array {
    $map = [];
    foreach ($events as $ev) {
        $d = $ev['date'] ?? $ev['start_date'] ?? '';
        if ($d !== '') {
            if (!isset($map[$d])) {
                $map[$d] = [];
            }
            $map[$d][] = $ev;
        }
    }
    // 各日の中で時間順・ID順にソート
    foreach ($map as $d => &$list) {
        usort($list, function($a, $b) {
            $timeA = $a['start_time'] ?? ($a['is_all_day'] ? '00:00' : '99:99');
            $timeB = $b['start_time'] ?? ($b['is_all_day'] ? '00:00' : '99:99');
            if ($timeA === $timeB) {
                return ($a['doctor']['id'] ?? 0) <=> ($b['doctor']['id'] ?? 0);
            }
            return strcmp($timeA, $timeB);
        });
    }
    unset($list);

    return $map;
}
