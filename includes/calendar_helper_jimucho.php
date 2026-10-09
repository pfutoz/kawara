<?php
/**
 * 医療法人小野会 外来診療カレンダー＆休診判定ヘルパー
 * 小野会外来休診（日祝・年末年始）＋ 木曜・12/30午後休診判定
 * ※事務長個人の公休・出勤・不在前日バッジは非表示化
 */

// 1. 小野会基本カレンダーヘルパーの読み込み
$onokai_helper_path = __DIR__ . '/../../endoscope/calendar_helper_onokai.php';
if (file_exists($onokai_helper_path)) {
    require_once $onokai_helper_path;
}

// フォールバック用（小野会休診判定関数が未定義の場合）
if (!function_exists('check_onokai_calendar')) {
    function check_onokai_calendar($date_str, $waku_time = null) {
        $ts    = strtotime($date_str);
        $year  = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $day   = (int)date('j', $ts);
        $w     = (int)date('w', $ts); // 0:日, 1:月, ..., 4:木, 6:土
        
        $res = [
            'is_holiday' => false,
            'is_closed'  => false,
            'reason'     => ''
        ];

        // 1. 日曜日（外来休診）
        if ($w === 0) {
            $res['is_closed'] = true;
            $res['reason']    = '日祝休診';
            return $res;
        }

        // 2. 年末年始休診（12/31〜1/3）
        $m_d = date('m-d', $ts);
        if ($m_d === '12-31' || $m_d === '01-01' || $m_d === '01-02' || $m_d === '01-03') {
            $res['is_closed'] = true;
            $res['reason']    = '年末年始休診';
            return $res;
        }

        // 3. 日本の祝日判定
        $holiday_name = null;
        $nth_week = (int)ceil($day / 7);

        switch ($month) {
            case 1:
                if ($day === 1) $holiday_name = "元日";
                if ($w === 1 && $nth_week === 2) $holiday_name = "成人の日";
                break;
            case 2:
                if ($day === 11) $holiday_name = "建国記念の日";
                if ($day === 23) $holiday_name = "天皇誕生日";
                break;
            case 3:
                $shunbun = (int)(20.8431 + 0.242194 * ($year - 1980) - (int)(($year - 1980) / 4));
                if ($day === $shunbun) $holiday_name = "春分の日";
                break;
            case 4:
                if ($day === 29) $holiday_name = "昭和の日";
                break;
            case 5:
                if ($day === 3) $holiday_name = "憲法記念日";
                if ($day === 4) $holiday_name = "みどりの日";
                if ($day === 5) $holiday_name = "こどもの日";
                break;
            case 7:
                if ($w === 1 && $nth_week === 3) $holiday_name = "海の日";
                break;
            case 8:
                if ($day === 11) $holiday_name = "山の日";
                break;
            case 9:
                if ($w === 1 && $nth_week === 3) $holiday_name = "敬老の日";
                $shubun = (int)(23.2488 + 0.242194 * ($year - 1980) - (int)(($year - 1980) / 4));
                if ($day === $shubun) $holiday_name = "秋分の日";
                if (!$holiday_name && $w >= 2 && $w <= 4) {
                    $keiro_day = 0;
                    for ($d = 1; $d <= 7; $d++) {
                        if ((int)date('w', strtotime("$year-09-$d")) === 1) {
                            $keiro_day = $d + 14;
                            break;
                        }
                    }
                    if ($keiro_day > 0 && $day > $keiro_day && $day < $shubun) {
                        $holiday_name = "国民の休日";
                    }
                }
                break;
            case 10:
                if ($w === 1 && $nth_week === 2) $holiday_name = "スポーツの日";
                break;
            case 11:
                if ($day === 3) $holiday_name = "文化の日";
                if ($day === 23) $holiday_name = "勤労感謝の日";
                break;
        }

        if (!$holiday_name && $w === 1) {
            $prev_ts = strtotime("-1 day", $ts);
            $prev_res = check_onokai_calendar(date('Y-m-d', $prev_ts), null);
            if ($prev_res['is_holiday']) { $holiday_name = "振替休日"; }
        }
        if (!$holiday_name && $month === 5 && $day === 6 && ($w === 2 || $w === 3)) {
            $sun_check3 = (int)date('w', strtotime("$year-05-03"));
            $sun_check4 = (int)date('w', strtotime("$year-05-04"));
            if ($sun_check3 === 0 || $sun_check4 === 0) { $holiday_name = "振替休日"; }
        }

        if ($holiday_name) {
            $res['is_holiday'] = true;
            $res['is_closed']  = true;
            $res['reason']     = $holiday_name;
        }

        return $res;
    }
}

/**
 * 小野会外来診療カレンダー状態判定
 *
 * @param string $date_str YYYY-MM-DD
 * @return array
 */
function check_jimucho_calendar($date_str) {
    $ts  = strtotime($date_str);
    $w   = (int)date('w', $ts); // 0:日, 1:月, 2:火, 3:水, 4:木, 5:金, 6:土
    $m_d = date('m-d', $ts);
    
    // 小野会の基本休診判定（日祝・年末年始）
    $onokai_res = check_onokai_calendar($date_str);

    $res = [
        'date'           => $date_str,
        'day_of_week'    => $w,
        'is_closed'      => false,     // 外来休診（終日）
        'is_pm_closed'   => false,     // 午後休診
        'is_workday'     => true,      // 診療日
        'reason'         => '',        // 理由（祝日名など）
        'badge_label'    => '',        // 表示ラベル（外来休診, 午後休診）
        'badge_type'     => '',        // closed, pm_closed, ''
        'is_pre_off_day' => false,     // 不在前日フラグ（非表示）
        'pre_off_reason' => ''
    ];

    // 1. 小野会 外来休診（日曜日、国民の祝日、年末年始 12/31〜1/3）
    if ($onokai_res['is_closed'] || $onokai_res['is_holiday']) {
        $res['is_closed']   = true;
        $res['is_workday']  = false;
        $res['reason']      = $onokai_res['reason'] ?: '外来休診';
        $res['badge_label'] = '外来休診';
        $res['badge_type']  = 'closed';
        return $res;
    }

    // 2. 小野会 午後休診（木曜日、12月30日）
    // 午前は通常診療、午後のみ外来休診
    if ($w === 4 || $m_d === '12-30') {
        $res['is_closed']   = false;
        $res['is_pm_closed'] = true;
        $res['is_workday']  = true;
        $res['reason']      = '午後休診';
        $res['badge_label'] = '午後休診';
        $res['badge_type']  = 'pm_closed';
        return $res;
    }

    // 3. 通常の診療日（月・火・水・金・土）
    // ※土曜日は午前外来あり、平日は全日通常診療
    // ※事務長の個人公休（木曜公休、土曜公休、出勤日、不在前日）は一切表示しない
    $res['is_closed']   = false;
    $res['is_pm_closed'] = false;
    $res['is_workday']  = true;
    $res['reason']      = '';
    $res['badge_label'] = '';
    $res['badge_type']  = '';

    return $res;
}

/**
 * カレンダーのセルスタイル生成
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
    $fg = '#0f172a';
    $border = '1px solid #e2e8f0';

    if ($info['badge_type'] === 'closed') {
        // 外来休診（日祝・年末年始）
        $bg = '#fff1f2'; // 淡い赤
        $fg = '#be123c';
        $border = '1px solid #fecdd3';
    } elseif ($info['badge_type'] === 'pm_closed') {
        // 午後休診（木曜・12/30）
        $bg = '#f0f9ff'; // 淡い水色
        $fg = '#0369a1';
        $border = '1px solid #e0f2fe';
    } else {
        // 通常診療日（月火水金土）
        $bg = '#ffffff';
        $fg = '#0f172a';
        $border = '1px solid #e2e8f0';
    }

    if ($is_today) {
        $border = '2px solid #0284c7';
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
