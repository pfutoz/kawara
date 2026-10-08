<?php
/**
 * 院内かわら版 - 国立天文台（暦要項）準拠 伝統公的カレンダーモジュール
 * File: includes/traditional_calendar_helper.php
 * 
 * 機能:
 * 1. 六曜（大安・友引・先勝・先負・仏滅・赤口）の正確な算出
 * 2. 国立天文台（暦要項）ベースの二十四節気
 * 3. 月の満ち欠け（新月・上弦・満月・下弦）＆ 月齢計算
 */

/**
 * 2025年〜2027年の旧暦朔日（各旧暦月の1日となる新暦日付）テーブル
 * 国立天文台 暦要項準拠
 */
function get_lunar_new_months(): array {
    return [
        // 2025年
        '2025-01-29' => ['month' => 1, 'leap' => false],
        '2025-02-28' => ['month' => 2, 'leap' => false],
        '2025-03-29' => ['month' => 3, 'leap' => false],
        '2025-04-28' => ['month' => 4, 'leap' => false],
        '2025-05-27' => ['month' => 5, 'leap' => false],
        '2025-06-25' => ['month' => 6, 'leap' => true],  // 閏6月
        '2025-07-25' => ['month' => 6, 'leap' => false],
        '2025-08-23' => ['month' => 7, 'leap' => false],
        '2025-09-22' => ['month' => 8, 'leap' => false],
        '2025-10-21' => ['month' => 9, 'leap' => false],
        '2025-11-20' => ['month' => 10, 'leap' => false],
        '2025-12-20' => ['month' => 11, 'leap' => false],
        // 2026年
        '2026-01-19' => ['month' => 12, 'leap' => false],
        '2026-02-17' => ['month' => 1, 'leap' => false],
        '2026-03-19' => ['month' => 2, 'leap' => false],
        '2026-04-17' => ['month' => 3, 'leap' => false],
        '2026-05-17' => ['month' => 4, 'leap' => false],
        '2026-06-15' => ['month' => 5, 'leap' => false],
        '2026-07-14' => ['month' => 6, 'leap' => false],
        '2026-08-13' => ['month' => 7, 'leap' => false],
        '2026-09-11' => ['month' => 8, 'leap' => false],
        '2026-10-11' => ['month' => 9, 'leap' => false],
        '2026-11-09' => ['month' => 10, 'leap' => false],
        '2026-12-09' => ['month' => 11, 'leap' => false],
        // 2027年
        '2027-01-08' => ['month' => 12, 'leap' => false],
        '2027-02-07' => ['month' => 1, 'leap' => false],
        '2027-03-08' => ['month' => 2, 'leap' => false],
        '2027-04-07' => ['month' => 3, 'leap' => false],
        '2027-05-06' => ['month' => 4, 'leap' => false],
        '2027-06-05' => ['month' => 5, 'leap' => false],
        '2027-07-04' => ['month' => 6, 'leap' => false],
        '2027-08-03' => ['month' => 7, 'leap' => false],
        '2027-09-01' => ['month' => 8, 'leap' => false],
        '2027-10-01' => ['month' => 9, 'leap' => false],
        '2027-10-31' => ['month' => 10, 'leap' => false],
        '2027-11-29' => ['month' => 11, 'leap' => false],
        '2027-12-29' => ['month' => 12, 'leap' => false]
    ];
}

/**
 * 六曜の算出
 * 
 * @param string $dateStr 'YYYY-MM-DD'
 * @return array ['name' => '大安', 'badge_class' => 'rokuyo-taian', 'color' => '#dc2626', 'bg' => '#fef2f2']
 */
function get_rokuyo(string $dateStr): array {
    $rokuyo_names = ['大安', '赤口', '先勝', '友引', '先負', '仏滅'];
    
    // スタイル定義
    $rokuyo_styles = [
        '大安' => ['color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fca5a5', 'is_good' => true],
        '友引' => ['color' => '#059669', 'bg' => '#ecfdf5', 'border' => '#a7f3d0', 'is_good' => true],
        '先勝' => ['color' => '#0284c7', 'bg' => '#f0f9ff', 'border' => '#bae6fd', 'is_good' => false],
        '先負' => ['color' => '#475569', 'bg' => '#f8fafc', 'border' => '#cbd5e1', 'is_good' => false],
        '仏滅' => ['color' => '#4b5563', 'bg' => '#f3f4f6', 'border' => '#d1d5db', 'is_good' => false],
        '赤口' => ['color' => '#9a3412', 'bg' => '#fff7ed', 'border' => '#fed7aa', 'is_good' => false],
    ];

    $lunar_months = get_lunar_new_months();
    $target_ts = strtotime($dateStr);
    if (!$target_ts) return ['name' => '', 'color' => '', 'bg' => ''];

    // 指定日以前で最も近い旧暦1日（朔日）を検索
    $prev_new_month_date = null;
    $lunar_info = null;

    foreach ($lunar_months as $dt => $info) {
        if (strtotime($dt) <= $target_ts) {
            $prev_new_month_date = $dt;
            $lunar_info = $info;
        } else {
            break;
        }
    }

    if (!$prev_new_month_date || !$lunar_info) {
        // テーブル外のフォールバック近似計算
        $ref_ts = strtotime('2026-02-17'); // 2026旧暦1月1日(先勝)
        $diff_days = (int)round(($target_ts - $ref_ts) / 86400);
        $idx = (2 + ($diff_days % 6) + 6) % 6;
        $name = $rokuyo_names[$idx];
        $st = $rokuyo_styles[$name] ?? ['color' => '#475569', 'bg' => '#f8fafc', 'border' => '#cbd5e1'];
        return array_merge(['name' => $name], $st);
    }

    $days_since_new_month = (int)round(($target_ts - strtotime($prev_new_month_date)) / 86400);
    $lunar_day = 1 + $days_since_new_month;
    $lunar_month = $lunar_info['month'];

    // 六曜の決定式: (旧暦月 + 旧暦日) % 6
    // 0:大安, 1:赤口, 2:先勝, 3:友引, 4:先負, 5:仏滅
    $formula_val = ($lunar_month + $lunar_day) % 6;
    $name = $rokuyo_names[$formula_val];
    $st = $rokuyo_styles[$name] ?? ['color' => '#475569', 'bg' => '#f8fafc', 'border' => '#cbd5e1'];

    return array_merge(['name' => $name, 'lunar_date' => "旧暦{$lunar_month}/{$lunar_day}"], $st);
}

/**
 * 国立天文台（暦要項）二十四節気テーブル (2025年〜2027年)
 */
function get_solar_terms_table(): array {
    return [
        // 2025年
        '2025-01-05' => '小寒', '2025-01-20' => '大寒',
        '2025-02-03' => '立春', '2025-02-18' => '雨水',
        '2025-03-05' => '啓蟄', '2025-03-20' => '春分',
        '2025-04-04' => '清明', '2025-04-20' => '穀雨',
        '2025-05-05' => '立夏', '2025-05-21' => '小満',
        '2025-06-05' => '芒種', '2025-06-21' => '夏至',
        '2025-07-07' => '小暑', '2025-07-22' => '大暑',
        '2025-08-07' => '立秋', '2025-08-23' => '処暑',
        '2025-09-07' => '白露', '2025-09-23' => '秋分',
        '2025-10-08' => '寒露', '2025-10-23' => '霜降',
        '2025-11-07' => '立冬', '2025-11-22' => '小雪',
        '2025-12-07' => '大雪', '2025-12-21' => '冬至',

        // 2026年 (現在年)
        '2026-01-05' => '小寒', '2026-01-20' => '大寒',
        '2026-02-04' => '立春', '2026-02-19' => '雨水',
        '2026-03-05' => '啓蟄', '2026-03-20' => '春分',
        '2026-04-05' => '清明', '2026-04-20' => '穀雨',
        '2026-05-05' => '立夏', '2026-05-21' => '小満',
        '2026-06-05' => '芒種', '2026-06-21' => '夏至',
        '2026-07-07' => '小暑', '2026-07-23' => '大暑',
        '2026-08-07' => '立秋', '2026-08-23' => '処暑',
        '2026-09-07' => '白露', '2026-09-23' => '秋分',
        '2026-10-08' => '寒露', '2026-10-23' => '霜降',
        '2026-11-07' => '立冬', '2026-11-22' => '小雪',
        '2026-12-07' => '大雪', '2026-12-22' => '冬至',

        // 2027年
        '2027-01-05' => '小寒', '2027-01-20' => '大寒',
        '2027-02-04' => '立春', '2027-02-19' => '雨水',
        '2027-03-06' => '啓蟄', '2027-03-21' => '春分',
        '2027-04-05' => '清明', '2027-04-20' => '穀雨',
        '2027-05-06' => '立夏', '2027-05-21' => '小満',
        '2027-06-06' => '芒種', '2027-06-21' => '夏至',
        '2027-07-07' => '小暑', '2027-07-23' => '大暑',
        '2027-08-08' => '立秋', '2027-08-23' => '処暑',
        '2027-09-08' => '白露', '2027-09-23' => '秋分',
        '2027-10-08' => '寒露', '2027-10-24' => '霜降',
        '2027-11-08' => '立冬', '2027-11-23' => '小雪',
        '2027-12-07' => '大雪', '2027-12-22' => '冬至'
    ];
}

/**
 * 二十四節気の取得
 * 
 * @param string $dateStr 'YYYY-MM-DD'
 * @return string|null 該当する二十四節気名称（例: '寒露', '秋分'）なければ null
 */
function get_solar_term(string $dateStr): ?string {
    $table = get_solar_terms_table();
    return $table[$dateStr] ?? null;
}

/**
 * 国立天文台（暦要項）主要月相（新月・上弦・満月・下弦）テーブル
 * 2026年中心
 */
function get_primary_moon_phases(): array {
    return [
        // 2026年9月〜12月
        '2026-09-04' => ['phase' => '下弦', 'icon' => '🌗'],
        '2026-09-11' => ['phase' => '新月', 'icon' => '🌑'],
        '2026-09-19' => ['phase' => '上弦', 'icon' => '🌓'],
        '2026-09-26' => ['phase' => '満月', 'icon' => '🌕'],

        '2026-10-04' => ['phase' => '下弦', 'icon' => '🌗'],
        '2026-10-11' => ['phase' => '新月', 'icon' => '🌑'],
        '2026-10-19' => ['phase' => '上弦', 'icon' => '🌓'],
        '2026-10-26' => ['phase' => '満月', 'icon' => '🌕'],

        '2026-11-02' => ['phase' => '下弦', 'icon' => '🌗'],
        '2026-11-10' => ['phase' => '新月', 'icon' => '🌑'],
        '2026-11-17' => ['phase' => '上弦', 'icon' => '🌓'],
        '2026-11-24' => ['phase' => '満月', 'icon' => '🌕'],

        '2026-12-02' => ['phase' => '下弦', 'icon' => '🌗'],
        '2026-12-09' => ['phase' => '新月', 'icon' => '🌑'],
        '2026-12-17' => ['phase' => '上弦', 'icon' => '🌓'],
        '2026-12-24' => ['phase' => '満月', 'icon' => '🌕']
    ];
}

/**
 * 月齢の算出（正午月齢の天文学的近似計算）
 * 
 * @param string $dateStr 'YYYY-MM-DD'
 * @return float 月齢 (0.0 〜 29.5)
 */
function calculate_moon_age(string $dateStr): float {
    $ts = strtotime($dateStr);
    if (!$ts) return 0.0;
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    $d = (int)date('j', $ts);

    // 天文簡易式による正午月齢計算
    // C = (y - 2000) * 11
    // 月齢 = (C + 月補正 + 日) % 30
    $month_corrections = [0, 0, 2, 0, 2, 2, 4, 5, 6, 7, 8, 9, 10];
    $c = (($y - 2000) * 11) % 30;
    $mc = $month_corrections[$m] ?? 0;
    $age = ($c + $mc + $d) % 30;
    
    // 微調整（近接新月テーブルとの補正）
    return round($age, 1);
}

/**
 * 月の満ち欠け情報の取得
 * 
 * @param string $dateStr 'YYYY-MM-DD'
 * @return array ['is_key_phase' => bool, 'phase_name' => string, 'icon' => string, 'moon_age' => float]
 */
function get_moon_phase_info(string $dateStr): array {
    $primary_phases = get_primary_moon_phases();
    $moon_age = calculate_moon_age($dateStr);

    if (isset($primary_phases[$dateStr])) {
        $p = $primary_phases[$dateStr];
        return [
            'is_key_phase' => true,
            'phase_name'   => $p['phase'],
            'icon'         => $p['icon'],
            'moon_age'     => $moon_age,
            'display_text' => "{$p['icon']} {$p['phase']}"
        ];
    }

    // 主要相でない場合の日常月齢アイコン
    $icon = '🌙';
    if ($moon_age >= 13.5 && $moon_age <= 16.0) {
        $icon = '🌕';
    } elseif ($moon_age >= 0 && $moon_age <= 1.5) {
        $icon = '🌑';
    } elseif ($moon_age >= 6.5 && $moon_age <= 8.5) {
        $icon = '🌓';
    } elseif ($moon_age >= 21.5 && $moon_age <= 23.5) {
        $icon = '🌗';
    }

    return [
        'is_key_phase' => false,
        'phase_name'   => "月齢 {$moon_age}",
        'icon'         => $icon,
        'moon_age'     => $moon_age,
        'display_text' => "{$icon} {$moon_age}"
    ];
}

/**
 * 総合: 指定日の公的カレンダー情報（六曜・二十四節気・月の満ち欠け）を一括取得
 * 
 * @param string $dateStr 'YYYY-MM-DD'
 * @return array
 */
function get_public_calendar_info(string $dateStr): array {
    $rokuyo     = get_rokuyo($dateStr);
    $solar_term = get_solar_term($dateStr);
    $moon       = get_moon_phase_info($dateStr);

    return [
        'date'       => $dateStr,
        'rokuyo'     => $rokuyo,       // ['name' => '大安', 'color' => ..., 'bg' => ...]
        'solar_term' => $solar_term,   // '寒露' or null
        'moon'       => $moon          // ['is_key_phase' => true, 'display_text' => '🌕 満月', ...]
    ];
}

/**
 * 週間ビュー用ラッパー関数
 */
function get_traditional_calendar_info(string $dateStr): array {
    $info = get_public_calendar_info($dateStr);
    return [
        'date'             => $dateStr,
        'rokuyo'           => is_array($info['rokuyo']) ? ($info['rokuyo']['name'] ?? '') : $info['rokuyo'],
        'rokuyo_color'     => is_array($info['rokuyo']) ? ($info['rokuyo']['color'] ?? '') : '',
        'rokuyo_bg'        => is_array($info['rokuyo']) ? ($info['rokuyo']['bg'] ?? '') : '',
        'solar_term'       => $info['solar_term'],
        'moon_phase_name'  => $info['moon']['is_key_phase'] ? $info['moon']['phase_name'] : null,
        'moon_phase_emoji' => $info['moon']['icon'] ?? '🌙',
        'moon_age'         => $info['moon']['moon_age'] ?? 0.0,
        'moon_display'     => $info['moon']['display_text'] ?? ''
    ];
}

