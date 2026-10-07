<?php
/**
 * 医療法人小野会 事務長カレンダー＆出勤・公休判定ヘルパー
 * 小野会公休日（日祝・年末年始）＋ 木曜日・土曜日の公休判定
 */

// 1. 小野会基本カレンダーヘルパーの読み込み
$onokai_helper_path = __DIR__ . '/../../endoscope/calendar_helper_onokai.php';
if (file_exists($onokai_helper_path)) {
    require_once $onokai_helper_path;
} else {
    // フォールバック（ファイルが見つからない場合の簡易判定）
    if (!function_exists('check_onokai_calendar')) {
        function check_onokai_calendar($date_str, $waku_time = null) {
            $ts = strtotime($date_str);
            $w = (int)date('w', $ts);
            $m_d = date('m-d', $ts);
            $is_closed = ($w === 0 || in_array($m_d, ['12-31', '01-01', '01-02', '01-03']));
            return [
                'is_holiday' => ($w === 0),
                'is_closed'  => $is_closed,
                'reason'     => $is_closed ? ($w === 0 ? '日祝休診' : '年末年始休診') : ''
            ];
        }
    }
}

/**
 * 事務長のカレンダー状態判定
 *
 * @param string $date_str YYYY-MM-DD
 * @return array
 */
function check_jimucho_calendar($date_str) {
    $ts = strtotime($date_str);
    $w  = (int)date('w', $ts); // 0:日, 1:月, 2:火, 3:水, 4:木, 5:金, 6:土
    
    // 小野会の基本休診判定（日祝・年末年始）
    $onokai_res = check_onokai_calendar($date_str);

    $res = [
        'date'           => $date_str,
        'day_of_week'    => $w,
        'is_closed'      => false,     // 事務長公休（不在）
        'is_workday'     => false,     // 事務長出勤日
        'reason'         => '',        // 理由表示
        'badge_label'    => '',        // ラベル
        'badge_type'     => '',        // work, thu_off, sat_off, holiday
        'is_pre_off_day' => false,     // 不在前日アラートフラグ
        'pre_off_reason' => ''
    ];

    // 1. 小野会の公休日（日祝・年末年始）
    if ($onokai_res['is_closed'] || $onokai_res['is_holiday']) {
        $res['is_closed']   = true;
        $res['is_workday']  = false;
        $res['reason']      = $onokai_res['reason'] ?: '小野会公休日';
        $res['badge_label'] = $res['reason'];
        $res['badge_type']  = 'holiday';
        return $res;
    }

    // 2. 木曜日：事務長公休
    if ($w === 4) {
        $res['is_closed']   = true;
        $res['is_workday']  = false;
        $res['reason']      = '木曜公休';
        $res['badge_label'] = '木曜公休';
        $res['badge_type']  = 'thu_off';
        return $res;
    }

    // 3. 土曜日：事務長公休
    if ($w === 6) {
        $res['is_closed']   = true;
        $res['is_workday']  = false;
        $res['reason']      = '土曜公休';
        $res['badge_label'] = '土曜公休';
        $res['badge_type']  = 'sat_off';
        return $res;
    }

    // 4. 月・火・水・金：事務長出勤日
    $res['is_closed']   = false;
    $res['is_workday']  = true;
    $res['reason']      = '出勤日';
    $res['badge_label'] = '出勤日';
    $res['badge_type']  = 'work';

    // 5. 不在前日判定
    // 水曜日: 翌日木曜が不在
    if ($w === 3) {
        $res['is_pre_off_day'] = true;
        $res['pre_off_reason'] = '明日(木)は不在日です。現場引継ぎ・ポスター印刷の準備日';
    }
    // 金曜日: 週末（土・日）が不在
    elseif ($w === 5) {
        $res['is_pre_off_day'] = true;
        $res['pre_off_reason'] = '明日より週末(土・日)不在です。まとめ印刷・重要伝達の準備日';
    } else {
        // 翌日が祝日などで休みになる場合の汎用判定
        $next_day = date('Y-m-d', strtotime('+1 day', $ts));
        $next_res = check_jimucho_calendar($next_day);
        if ($next_res['is_closed']) {
            $res['is_pre_off_day'] = true;
            $res['pre_off_reason'] = "明日({$next_res['reason']})は不在日です。事前引継ぎ・ポスター印刷の準備日";
        }
    }

    return $res;
}

/**
 * 事務長カレンダーのセルスタイル生成
 *
 * @param string $date_str YYYY-MM-DD
 * @return array
 */
function get_jimucho_cell_style($date_str) {
    $info = check_jimucho_calendar($date_str);
    $today_str = date('Y-m-d');
    $is_past  = ($date_str < $today_str);
    $is_today = ($date_str === $today_str);

    $bg = '#ffffff';
    $fg = '#2c3e50';
    $border = '1px solid #e2e8f0';

    if ($info['badge_type'] === 'holiday') {
        $bg = '#fff1f2'; // 薄赤
        $fg = '#be123c';
    } elseif ($info['badge_type'] === 'thu_off') {
        $bg = '#eef6fc'; // 薄水色
        $fg = '#0369a1';
    } elseif ($info['badge_type'] === 'sat_off') {
        $bg = '#f5f3ff'; // 薄紫
        $fg = '#6d28d9';
    } else {
        // 出勤日
        $bg = '#ffffff';
        $fg = '#0f172a';
    }

    if ($is_today) {
        $border = '2px solid #2563eb';
    }

    return [
        'bg'       => $bg,
        'fg'       => $fg,
        'border'   => $border,
        'is_today' => $is_today,
        'is_past'  => $is_past,
        'info'     => $info
    ];
}
