<?php
/**
 * 院内かわら版 - Google Calendar API 連携モジュール
 * File: includes/google_calendar_helper.php
 * 
 * 機能:
 * 1. サービスアカウントJSONキーによるJWT署名＆Google OAuth2アクセストークン取得 (外部ライブラリ不要)
 * 2. Google Calendar API v3 からのイベント取得
 * 3. 複数アカウント・カレンダーの一括同期＆DBキャッシュ管理
 * 4. 接続テスト機能
 */

define('GCAL_SERVICE_ACCOUNT_FILE', __DIR__ . '/../config/google_service_account.json');

/**
 * サービスアカウントの配置状況および情報を取得
 * 
 * @return array ['is_installed' => bool, 'client_email' => string, 'project_id' => string, 'error' => string]
 */
function get_google_service_account_info(): array {
    if (!file_exists(GCAL_SERVICE_ACCOUNT_FILE)) {
        return [
            'is_installed' => false,
            'client_email' => '',
            'project_id'   => '',
            'error'        => '設定ファイル (config/google_service_account.json) が未配置です。'
        ];
    }

    $raw = @file_get_contents(GCAL_SERVICE_ACCOUNT_FILE);
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
        return [
            'is_installed' => false,
            'client_email' => '',
            'project_id'   => '',
            'error'        => 'JSONキーのフォーマットが正しくありません (client_email または private_key が不足)。'
        ];
    }

    return [
        'is_installed' => true,
        'client_email' => $data['client_email'],
        'project_id'   => $data['project_id'] ?? '',
        'error'        => ''
    ];
}

/**
 * URL-safe Base64 エンコード
 */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * サービスアカウントから Google OAuth2 アクセストークンを取得 (JWTアサーション方式)
 * 
 * @return array ['success' => bool, 'access_token' => string, 'error' => string]
 */
function get_google_access_token(): array {
    static $cached_token = null;
    static $token_expires_at = 0;

    // メモリキャッシュがあれば再利用
    if ($cached_token !== null && time() < ($token_expires_at - 60)) {
        return ['success' => true, 'access_token' => $cached_token, 'error' => ''];
    }

    $sa_info = get_google_service_account_info();
    if (!$sa_info['is_installed']) {
        return ['success' => false, 'access_token' => '', 'error' => $sa_info['error']];
    }

    $raw = file_get_contents(GCAL_SERVICE_ACCOUNT_FILE);
    $key_data = json_decode($raw, true);

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $key_data['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now
    ];

    $encoded_header = base64url_encode(json_encode($header));
    $encoded_claims = base64url_encode(json_encode($claims));
    $signature_input = "{$encoded_header}.{$encoded_claims}";

    $private_key = $key_data['private_key'];
    $signature = '';

    $success = @openssl_sign($signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
    if (!$success) {
        return [
            'success'      => false,
            'access_token' => '',
            'error'        => '秘密鍵の署名生成に失敗しました: ' . openssl_error_string()
        ];
    }

    $jwt = "{$signature_input}." . base64url_encode($signature);

    // Google OAuth2 トークンエンドポイントへリクエスト
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    if ($curl_error) {
        return ['success' => false, 'access_token' => '', 'error' => "cURLエラー: {$curl_error}"];
    }

    $res_json = json_decode($response, true);
    if ($http_code !== 200 || empty($res_json['access_token'])) {
        $err_msg = $res_json['error_description'] ?? ($res_json['error'] ?? 'OAuth認証エラー');
        return ['success' => false, 'access_token' => '', 'error' => "Google OAuth認証失敗 (HTTP {$http_code}): {$err_msg}"];
    }

    $cached_token = $res_json['access_token'];
    $token_expires_at = $now + (int)($res_json['expires_in'] ?? 3600);

    return ['success' => true, 'access_token' => $cached_token, 'error' => ''];
}

/**
 * 特定のGoogleカレンダーへの接続テストを実施
 * 
 * @param string $calendarId Google Calendar ID (例: 'xxx@gmail.com')
 * @return array ['success' => bool, 'message' => string, 'calendar_name' => string, 'event_count' => int]
 */
function test_google_calendar_connection(string $calendarId): array {
    $token_res = get_google_access_token();
    if (!$token_res['success']) {
        return [
            'success' => false,
            'message' => $token_res['error'],
            'calendar_name' => '',
            'event_count' => 0
        ];
    }

    $token = $token_res['access_token'];
    $encoded_cal_id = urlencode($calendarId);

    // カレンダーメタデータの取得テスト
    $url = "https://www.googleapis.com/calendar/v3/calendars/{$encoded_cal_id}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $cal_data = json_decode($response, true);

    if ($http_code === 404) {
        return [
            'success' => false,
            'message' => 'カレンダーが見つかりません。カレンダーIDが正しいか、またはサービスアカウントに共有権限が付与されているか確認してください。',
            'calendar_name' => '',
            'event_count' => 0
        ];
    } elseif ($http_code === 403) {
        return [
            'success' => false,
            'message' => 'アクセス権限がありません。Googleカレンダーの「設定と共有」→「特定のユーザーとの共有」にサービスアカウントのメールアドレスを追加してください。',
            'calendar_name' => '',
            'event_count' => 0
        ];
    } elseif ($http_code !== 200) {
        $err = $cal_data['error']['message'] ?? "HTTP {$http_code}";
        return [
            'success' => false,
            'message' => "カレンダー取得エラー: {$err}",
            'calendar_name' => '',
            'event_count' => 0
        ];
    }

    $cal_name = $cal_data['summary'] ?? $calendarId;

    // 直近イベントの取得テスト
    $now_iso = date('c', strtotime('-7 days'));
    $future_iso = date('c', strtotime('+30 days'));
    $ev_url = "https://www.googleapis.com/calendar/v3/calendars/{$encoded_cal_id}/events?timeMin=" . urlencode($now_iso) . "&timeMax=" . urlencode($future_iso) . "&singleEvents=true&maxResults=10";

    $ch = curl_init($ev_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $ev_res = curl_exec($ch);
    $ev_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $ev_data = json_decode($ev_res, true);
    $ev_count = ($ev_code === 200 && !empty($ev_data['items'])) ? count($ev_data['items']) : 0;

    return [
        'success'       => true,
        'message'       => "接続成功！カレンダー「{$cal_name}」へのアクセスが確認できました。（直近イベント: {$ev_count}件取得）",
        'calendar_name' => $cal_name,
        'event_count'   => $ev_count
    ];
}

/**
 * 登録されている全有効カレンダーからイベントを取得し、DBキャッシュを更新する
 * 
 * @param PDO  $pdo
 * @param bool $force 強制更新フラグ
 * @return array ['success' => bool, 'synced_count' => int, 'errors' => array]
 */
function sync_all_google_calendars(PDO $pdo, bool $force = false): array {
    // 最後に同期してから3分以内かつ非強制ならスキップ
    if (!$force) {
        $last_sync = $pdo->query("SELECT MAX(synced_at) FROM google_calendar_events_cache")->fetchColumn();
        if ($last_sync && (time() - strtotime($last_sync)) < 180) {
            return ['success' => true, 'synced_count' => 0, 'skipped' => true, 'message' => '直近で同期済みのためスキップしました'];
        }
    }

    $token_res = get_google_access_token();
    if (!$token_res['success']) {
        return ['success' => false, 'synced_count' => 0, 'errors' => [$token_res['error']]];
    }

    $token = $token_res['access_token'];

    // 有効なカレンダーチャンネル一覧を取得
    $channels = $pdo->query("SELECT * FROM google_calendar_channels WHERE is_enabled = TRUE ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($channels)) {
        return ['success' => true, 'synced_count' => 0, 'message' => '有効なカレンダーが登録されていません'];
    }

    // 取得範囲（過去30日〜未来90日）
    $time_min = date('c', strtotime('-30 days'));
    $time_max = date('c', strtotime('+90 days'));

    $total_synced = 0;
    $errors = [];

    $stmt_upsert = $pdo->prepare("
        INSERT INTO google_calendar_events_cache (
            event_id, channel_id, calendar_id, title, description, location,
            start_datetime, end_datetime, is_all_day, html_link, status, synced_at
        ) VALUES (
            :event_id, :channel_id, :calendar_id, :title, :description, :location,
            :start_datetime, :end_datetime, :is_all_day, :html_link, :status, NOW()
        )
        ON CONFLICT (event_id) DO UPDATE SET
            channel_id     = EXCLUDED.channel_id,
            calendar_id    = EXCLUDED.calendar_id,
            title          = EXCLUDED.title,
            description    = EXCLUDED.description,
            location       = EXCLUDED.location,
            start_datetime = EXCLUDED.start_datetime,
            end_datetime   = EXCLUDED.end_datetime,
            is_all_day     = EXCLUDED.is_all_day,
            html_link      = EXCLUDED.html_link,
            status         = EXCLUDED.status,
            synced_at      = NOW()
    ");

    foreach ($channels as $ch_row) {
        $channel_id = (int)$ch_row['channel_id'];
        $cal_id     = $ch_row['calendar_id'];
        $enc_cal    = urlencode($cal_id);

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$enc_cal}/events?"
             . "timeMin=" . urlencode($time_min)
             . "&timeMax=" . urlencode($time_max)
             . "&singleEvents=true"
             . "&orderBy=startTime"
             . "&maxResults=250";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode($res, true);

        if ($code !== 200 || !isset($data['items'])) {
            $err_msg = $data['error']['message'] ?? "HTTP {$code}";
            $errors[] = "カレンダー [{$ch_row['calendar_name']}]: {$err_msg}";
            continue;
        }

        $active_event_ids = [];

        foreach ($data['items'] as $item) {
            $status = $item['status'] ?? 'confirmed';
            if ($status === 'cancelled') {
                continue; // 削除されたイベントはスキップ
            }

            $eid = $item['id'];
            $active_event_ids[] = $eid;
            $summary = trim($item['summary'] ?? '(無題の予定)');
            $desc = $item['description'] ?? '';
            $loc = $item['location'] ?? '';
            $link = $item['htmlLink'] ?? '';

            $is_all_day = false;
            $start_dt = '';
            $end_dt = '';

            if (!empty($item['start']['dateTime'])) {
                $start_dt = date('Y-m-d H:i:s', strtotime($item['start']['dateTime']));
                $end_dt   = date('Y-m-d H:i:s', strtotime($item['end']['dateTime'] ?? $item['start']['dateTime']));
            } elseif (!empty($item['start']['date'])) {
                // 終日イベント (Googleの終日イベントの終了日は翌日0:00)
                $is_all_day = true;
                $start_dt = $item['start']['date'] . ' 00:00:00';
                $end_raw  = $item['end']['date'] ?? $item['start']['date'];
                // 終日の終了日調整（Googleの仕様で終了日は翌日になるため、23:59:59にする）
                $end_dt   = date('Y-m-d 23:59:59', strtotime($end_raw . ' -1 day'));
                if (strtotime($end_dt) < strtotime($start_dt)) {
                    $end_dt = $item['start']['date'] . ' 23:59:59';
                }
            } else {
                continue;
            }

            $stmt_upsert->execute([
                ':event_id'       => $eid,
                ':channel_id'     => $channel_id,
                ':calendar_id'    => $cal_id,
                ':title'          => $summary,
                ':description'    => $desc,
                ':location'       => $loc,
                ':start_datetime' => $start_dt,
                ':end_datetime'   => $end_dt,
                ':is_all_day'     => $is_all_day ? 'true' : 'false',
                ':html_link'      => $link,
                ':status'         => $status
            ]);
            $total_synced++;
        }

        // Google側で削除されたイベントをキャッシュから削除
        if (!empty($active_event_ids)) {
            $in_clause = implode(',', array_fill(0, count($active_event_ids), '?'));
            $params = array_merge([$channel_id], $active_event_ids);
            $stmt_del = $pdo->prepare("DELETE FROM google_calendar_events_cache WHERE channel_id = ? AND event_id NOT IN ({$in_clause})");
            $stmt_del->execute($params);
        } else {
            $pdo->prepare("DELETE FROM google_calendar_events_cache WHERE channel_id = ?")->execute([$channel_id]);
        }
    }

    return [
        'success'      => empty($errors),
        'synced_count' => $total_synced,
        'errors'       => $errors,
        'message'      => "{$total_synced} 件のGoogleカレンダー予定を同期しました。"
    ];
}

/**
 * キャッシュから指定期間のGoogleカレンダーイベントを取得
 * 
 * @param PDO         $pdo
 * @param string      $startDate 'YYYY-MM-DD'
 * @param string      $endDate   'YYYY-MM-DD'
 * @param array|null  $enabledChannelIds 表示対象のチャンネルID配列（nullなら全有効チャンネル）
 * @return array
 */
function get_cached_google_events_for_range(PDO $pdo, string $startDate, string $endDate, ?array $enabledChannelIds = null): array {
    $start_dt = "{$startDate} 00:00:00";
    $end_dt   = "{$endDate} 23:59:59";

    $sql = "
        SELECT e.*, c.calendar_name, c.color_theme, c.account_name
        FROM google_calendar_events_cache e
        JOIN google_calendar_channels c ON e.channel_id = c.channel_id
        WHERE c.is_enabled = TRUE
          AND e.start_datetime <= :end_dt
          AND e.end_datetime >= :start_dt
    ";

    $params = [
        ':start_dt' => $start_dt,
        ':end_dt'   => $end_dt
    ];

    if ($enabledChannelIds !== null && count($enabledChannelIds) > 0) {
        $in_list = implode(',', array_map('intval', $enabledChannelIds));
        $sql .= " AND e.channel_id IN ({$in_list})";
    }

    $sql .= " ORDER BY e.is_all_day DESC, e.start_datetime ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 📅 自分のGoogleカレンダーに登録するためのURL（Web Intent）を生成
 * 
 * @param string          $title        予定のタイトル
 * @param string|int      $start        開始日時（'YYYY-MM-DD', 'YYYY-MM-DD HH:MM:SS', またはUnixタイムスタンプ）
 * @param string|int|null $end          終了日時（省略時は開始から1時間後、終日なら当日中）
 * @param bool            $is_all_day   終日フラグ
 * @param string          $details      詳細メモ・URLなど
 * @param string          $location     場所
 * @return string Googleカレンダー登録用URL
 */
function build_google_calendar_add_url(
    string $title,
    $start,
    $end = null,
    bool $is_all_day = false,
    string $details = '',
    string $location = ''
): string {
    $s_ts = is_numeric($start) ? (int)$start : strtotime((string)$start);
    if (!$s_ts) return '#';

    if ($is_all_day) {
        $s_str = date('Ymd', $s_ts);
        if ($end) {
            $e_ts = is_numeric($end) ? (int)$end : strtotime((string)$end);
            $e_str = date('Ymd', strtotime('+1 day', $e_ts));
        } else {
            $e_str = date('Ymd', strtotime('+1 day', $s_ts));
        }
        $dates = "{$s_str}/{$e_str}";
    } else {
        $s_str = date('Ymd\THis', $s_ts);
        if ($end) {
            $e_ts = is_numeric($end) ? (int)$end : strtotime((string)$end);
            $e_str = date('Ymd\THis', $e_ts);
        } else {
            $e_str = date('Ymd\THis', strtotime('+1 hour', $s_ts));
        }
        $dates = "{$s_str}/{$e_str}";
    }

    $params = [
        'action' => 'TEMPLATE',
        'text'   => $title,
        'dates'  => $dates,
        'ctz'    => 'Asia/Tokyo'
    ];
    if ($details !== '') {
        $params['details'] = $details;
    }
    if ($location !== '') {
        $params['location'] = $location;
    }

    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}

/**
 * 📅 Googleカレンダーに予定を新規作成（API書き込み）
 * 
 * @param string $calendarId GoogleカレンダーID
 * @param array  $eventData 予定情報
 *   - summary: (string) タイトル
 *   - start: (string|array) 開始日時 ('YYYY-MM-DD', 'YYYY-MM-DD HH:MM:SS', またはGoogle形式 ['dateTime'=>..., 'timeZone'=>...])
 *   - end: (string|array|null) 終了日時
 *   - is_all_day: (bool) 終日フラグ
 *   - description: (string) 本文・メモ
 *   - location: (string) 場所
 * @return array ['success' => bool, 'event_id' => string, 'html_link' => string, 'message' => string, 'data' => array, 'error' => string]
 */
function create_google_calendar_event(string $calendarId, array $eventData): array {
    $token_res = get_google_access_token();
    if (!$token_res['success']) {
        return ['success' => false, 'event_id' => '', 'html_link' => '', 'error' => $token_res['error']];
    }

    $payload = build_gcal_api_payload($eventData);
    if (empty($payload['summary'])) {
        return ['success' => false, 'event_id' => '', 'html_link' => '', 'error' => '予定タイトル(summary)が指定されていません'];
    }

    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token_res['access_token']}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    if ($curl_error) {
        return ['success' => false, 'event_id' => '', 'html_link' => '', 'error' => "通信エラー: {$curl_error}"];
    }

    $res_data = json_decode($response, true);
    if ($http_code !== 200 && $http_code !== 201) {
        $err_msg = $res_data['error']['message'] ?? "HTTP {$http_code}";
        return ['success' => false, 'event_id' => '', 'html_link' => '', 'error' => "Google APIエラー ({$http_code}): {$err_msg}", 'data' => $res_data];
    }

    return [
        'success'   => true,
        'event_id'  => $res_data['id'] ?? '',
        'html_link' => $res_data['htmlLink'] ?? '',
        'message'   => 'Googleカレンダーに予定を登録しました',
        'data'      => $res_data
    ];
}

/**
 * 📅 Googleカレンダーの予定を更新（API書き込み・PATCH）
 * 
 * @param string $calendarId GoogleカレンダーID
 * @param string $eventId    GoogleイベントID
 * @param array  $eventData  更新するフィールド情報
 * @return array ['success' => bool, 'event_id' => string, 'html_link' => string, 'message' => string, 'data' => array, 'error' => string]
 */
function update_google_calendar_event(string $calendarId, string $eventId, array $eventData): array {
    $token_res = get_google_access_token();
    if (!$token_res['success']) {
        return ['success' => false, 'event_id' => $eventId, 'html_link' => '', 'error' => $token_res['error']];
    }

    $payload = build_gcal_api_payload($eventData, true);

    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events/" . urlencode($eventId);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token_res['access_token']}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    if ($curl_error) {
        return ['success' => false, 'event_id' => $eventId, 'html_link' => '', 'error' => "通信エラー: {$curl_error}"];
    }

    $res_data = json_decode($response, true);
    if ($http_code !== 200) {
        $err_msg = $res_data['error']['message'] ?? "HTTP {$http_code}";
        return ['success' => false, 'event_id' => $eventId, 'html_link' => '', 'error' => "Google API更新エラー ({$http_code}): {$err_msg}", 'data' => $res_data];
    }

    return [
        'success'   => true,
        'event_id'  => $res_data['id'] ?? $eventId,
        'html_link' => $res_data['htmlLink'] ?? '',
        'message'   => 'Googleカレンダーの予定を更新しました',
        'data'      => $res_data
    ];
}

/**
 * 🗑️ Googleカレンダーの予定を削除（API書き込み・DELETE）
 * 
 * @param string $calendarId GoogleカレンダーID
 * @param string $eventId    GoogleイベントID
 * @return array ['success' => bool, 'message' => string, 'error' => string]
 */
function delete_google_calendar_event(string $calendarId, string $eventId): array {
    $token_res = get_google_access_token();
    if (!$token_res['success']) {
        return ['success' => false, 'error' => $token_res['error']];
    }

    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events/" . urlencode($eventId);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token_res['access_token']}"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => "通信エラー: {$curl_error}"];
    }

    if ($http_code !== 204 && $http_code !== 200 && $http_code !== 410 && $http_code !== 404) {
        $res_data = json_decode($response, true);
        $err_msg = $res_data['error']['message'] ?? "HTTP {$http_code}";
        return ['success' => false, 'error' => "Google API削除エラー ({$http_code}): {$err_msg}"];
    }

    return ['success' => true, 'message' => 'Googleカレンダーから予定を削除しました'];
}

/**
 * GoogleカレンダーAPI用ペイロード生成ヘルパー
 */
function build_gcal_api_payload(array $data, bool $is_patch = false): array {
    $payload = [];

    if (isset($data['summary'])) {
        $payload['summary'] = trim($data['summary']);
    }
    if (isset($data['description'])) {
        $payload['description'] = (string)$data['description'];
    }
    if (isset($data['location'])) {
        $payload['location'] = (string)$data['location'];
    }

    $is_all_day = !empty($data['is_all_day']);

    if (isset($data['start'])) {
        if (is_array($data['start'])) {
            $payload['start'] = $data['start'];
        } elseif ($is_all_day) {
            $s_date = date('Y-m-d', is_numeric($data['start']) ? (int)$data['start'] : strtotime((string)$data['start']));
            $payload['start'] = ['date' => $s_date];
        } else {
            $s_iso = date('c', is_numeric($data['start']) ? (int)$data['start'] : strtotime((string)$data['start']));
            $payload['start'] = ['dateTime' => $s_iso, 'timeZone' => 'Asia/Tokyo'];
        }
    }

    if (isset($data['end'])) {
        if (is_array($data['end'])) {
            $payload['end'] = $data['end'];
        } elseif ($is_all_day) {
            $e_ts = is_numeric($data['end']) ? (int)$data['end'] : strtotime((string)$data['end']);
            // 終日の終了日は翌日（Google仕様）
            $e_date = date('Y-m-d', strtotime('+1 day', $e_ts));
            $payload['end'] = ['date' => $e_date];
        } else {
            $e_iso = date('c', is_numeric($data['end']) ? (int)$data['end'] : strtotime((string)$data['end']));
            $payload['end'] = ['dateTime' => $e_iso, 'timeZone' => 'Asia/Tokyo'];
        }
    } elseif (isset($data['start']) && !$is_patch) {
        // endが省略された場合、自動設定
        if ($is_all_day) {
            $s_ts = is_numeric($data['start']) ? (int)$data['start'] : strtotime((string)$data['start']);
            $e_date = date('Y-m-d', strtotime('+1 day', $s_ts));
            $payload['end'] = ['date' => $e_date];
        } else {
            $s_ts = is_numeric($data['start']) ? (int)$data['start'] : strtotime((string)$data['start']);
            $e_iso = date('c', strtotime('+1 hour', $s_ts));
            $payload['end'] = ['dateTime' => $e_iso, 'timeZone' => 'Asia/Tokyo'];
        }
    }

    return $payload;
}

/**
 * 💾 作成・更新したGoogleイベントをローカルキャッシュテーブルに即時保存
 */
function save_gcal_event_to_cache(PDO $pdo, int $channelId, string $calendarId, array $item): bool {
    if (empty($item['id'])) return false;

    $eid     = $item['id'];
    $summary = trim($item['summary'] ?? '(無題の予定)');
    $desc    = $item['description'] ?? '';
    $loc     = $item['location'] ?? '';
    $link    = $item['htmlLink'] ?? '';
    $status  = $item['status'] ?? 'confirmed';

    $is_all_day = false;
    $start_dt = '';
    $end_dt   = '';

    if (!empty($item['start']['dateTime'])) {
        $start_dt = date('Y-m-d H:i:s', strtotime($item['start']['dateTime']));
        $end_dt   = date('Y-m-d H:i:s', strtotime($item['end']['dateTime'] ?? $item['start']['dateTime']));
    } elseif (!empty($item['start']['date'])) {
        $is_all_day = true;
        $start_dt = $item['start']['date'] . ' 00:00:00';
        $end_raw  = $item['end']['date'] ?? $item['start']['date'];
        $end_dt   = date('Y-m-d 23:59:59', strtotime($end_raw . ' -1 day'));
        if (strtotime($end_dt) < strtotime($start_dt)) {
            $end_dt = $item['start']['date'] . ' 23:59:59';
        }
    } else {
        return false;
    }

    $stmt = $pdo->prepare("
        INSERT INTO google_calendar_events_cache (
            event_id, channel_id, calendar_id, title, description, location,
            start_datetime, end_datetime, is_all_day, html_link, status, synced_at
        ) VALUES (
            :event_id, :channel_id, :calendar_id, :title, :description, :location,
            :start_datetime, :end_datetime, :is_all_day, :html_link, :status, NOW()
        )
        ON CONFLICT (event_id) DO UPDATE SET
            channel_id     = EXCLUDED.channel_id,
            calendar_id    = EXCLUDED.calendar_id,
            title          = EXCLUDED.title,
            description    = EXCLUDED.description,
            location       = EXCLUDED.location,
            start_datetime = EXCLUDED.start_datetime,
            end_datetime   = EXCLUDED.end_datetime,
            is_all_day     = EXCLUDED.is_all_day,
            html_link      = EXCLUDED.html_link,
            status         = EXCLUDED.status,
            synced_at      = NOW()
    ");

    return $stmt->execute([
        ':event_id'       => $eid,
        ':channel_id'     => $channelId,
        ':calendar_id'    => $calendarId,
        ':title'          => $summary,
        ':description'    => $desc,
        ':location'       => $loc,
        ':start_datetime' => $start_dt,
        ':end_datetime'   => $end_dt,
        ':is_all_day'     => $is_all_day ? 'true' : 'false',
        ':html_link'      => $link,
        ':status'         => $status
    ]);
}

/**
 * 🗑️ 削除されたGoogleイベントをローカルキャッシュテーブルから即時削除
 */
function delete_gcal_event_from_cache(PDO $pdo, string $eventId): bool {
    $stmt = $pdo->prepare("DELETE FROM google_calendar_events_cache WHERE event_id = :eid");
    return $stmt->execute([':eid' => $eventId]);
}


