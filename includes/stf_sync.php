<?php
/**
 * STF職員情報検索アプリとのマスター同期モジュール
 * (Single Source of Truth: c:/Apache24/htdocs/stf/staff_data.json / api.php)
 */

function syncStaffFromSTF($pdo) {
    // 1. STF API からデータ取得（ローカル直接読み込みまたはHTTP）
    $json_file = 'C:/Apache24/htdocs/stf/staff_data.json';
    $stf_list = [];

    if (file_exists($json_file)) {
        $raw = file_get_contents($json_file);
        $utf8 = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win, SJIS, UTF-8');
        $stf_list = json_decode($utf8, true);
    } else {
        // HTTP API経由
        $api_res = @file_get_contents('http://localhost/stf/api.php');
        if ($api_res) {
            $parsed = json_decode($api_res, true);
            $stf_list = $parsed['data'] ?? [];
        }
    }

    if (empty($stf_list) || !is_array($stf_list)) {
        return ['success' => false, 'message' => 'STFの職員データが取得できませんでした。', 'updated' => 0];
    }

    // 2. カラム確認と追加・自動採番シーケンス整備
    $pdo->exec("
        ALTER TABLE staff ADD COLUMN IF NOT EXISTS staff_code VARCHAR(20);
        ALTER TABLE staff ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30);
        ALTER TABLE staff ADD COLUMN IF NOT EXISTS ext_number VARCHAR(20);
        CREATE SEQUENCE IF NOT EXISTS staff_staff_id_seq;
        SELECT setval('staff_staff_id_seq', (SELECT COALESCE(MAX(staff_id), 0) FROM staff));
        ALTER TABLE staff ALTER COLUMN staff_id SET DEFAULT nextval('staff_staff_id_seq');
    ");

    // 3. 既存のkawaraスタッフ一覧取得
    $stmt = $pdo->query("SELECT * FROM staff");
    $db_staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 名寄せマップ（旧姓・異体字対応）
    $alias_map = [
        '坂本萌弓' => '生原萌弓',
        '川崎由紀' => '川﨑由紀',
        '濱田稚菜' => '濵田稚菜'
    ];

    $updated = 0;
    $matched_stf_codes = [];

    foreach ($db_staff as $db) {
        $db_clean = preg_replace('/\s+/u', '', $db['staff_name']);
        $search_name = $alias_map[$db_clean] ?? $db_clean;

        foreach ($stf_list as $stf) {
            $stf_clean = preg_replace('/\s+/u', '', $stf['name']);
            $code_match = (!empty($db['staff_code']) && (string)$db['staff_code'] === (string)$stf['staff_code']);

            if ($code_match || $search_name === $stf_clean) {
                $matched_stf_codes[] = $stf['staff_code'];

                $mobile = $stf['mobile_no'] ?: '';
                $home_phone = $stf['phone_no'] ?: '';
                $st_name = $stf['name']; // STFの正確な表記
                $st_kana = mb_convert_kana($stf['name_kana'] ?? '', 'KV', 'UTF-8'); // 半角カナを全角に変換
                $st_role = $stf['job_title'] ?: $db['role'];
                $address = $stf['address'] ?? '';
                $postal = $stf['postal_code'] ?? '';

                $u_stmt = $pdo->prepare("UPDATE staff SET 
                    staff_code   = :code,
                    staff_name   = :name,
                    kana         = COALESCE(NULLIF(:kana, ''), kana),
                    phone_number = COALESCE(NULLIF(:phone, ''), phone_number),
                    home_phone   = COALESCE(NULLIF(:hphone, ''), home_phone),
                    address      = COALESCE(NULLIF(:addr, ''), address),
                    postal_code  = COALESCE(NULLIF(:post, ''), postal_code),
                    role         = :role,
                    is_deleted   = FALSE,
                    updated_at   = NOW()
                    WHERE staff_id = :id");
                $u_stmt->execute([
                    ':code'   => $stf['staff_code'],
                    ':name'   => $st_name,
                    ':kana'   => $st_kana,
                    ':phone'  => $mobile,
                    ':hphone' => $home_phone,
                    ':addr'   => $address,
                    ':post'   => $postal,
                    ':role'   => $st_role,
                    ':id'     => $db['staff_id']
                ]);
                $updated++;
                break;
            }
        }
    }

    // 4. STFにあってkawaraに未登録の新規職員を自動INSERT
    $inserted = 0;
    foreach ($stf_list as $stf) {
        $stf_code = (string)($stf['staff_code'] ?? '');
        if (empty($stf_code) || in_array($stf_code, array_map('strval', $matched_stf_codes))) {
            continue;
        }

        $st_name = trim($stf['name'] ?? '');
        if (empty($st_name)) continue;

        // 部署（dept_id）の自動マッピング
        $group    = $stf['job_group'] ?? '';
        $dept_str = $stf['department'] ?? '';
        $title    = $stf['job_title'] ?? '';
        $combined = $group . ' ' . $dept_str . ' ' . $title;

        $dept_id = 5; // デフォルト: 事務・受付
        if (mb_strpos($combined, '医') !== false || mb_strpos($combined, 'ドクター') !== false) {
            $dept_id = 2; // 医師
        } elseif (mb_strpos($combined, '補助') !== false) {
            $dept_id = 4; // 補助看
        } elseif (mb_strpos($combined, '看') !== false) {
            $dept_id = 3; // 看護師
        } elseif (mb_strpos($combined, '薬') !== false) {
            $dept_id = 6; // 薬剤師
        } elseif (mb_strpos($combined, '厨') !== false || mb_strpos($combined, '栄養') !== false || mb_strpos($combined, '調理') !== false) {
            $dept_id = 7; // 厨房
        }

        $st_kana    = mb_convert_kana($stf['name_kana'] ?? '', 'KV', 'UTF-8');
        $mobile     = $stf['mobile_no'] ?: '';
        $home_phone = $stf['phone_no'] ?: '';
        $address    = $stf['address'] ?? '';
        $postal     = $stf['postal_code'] ?? '';
        $st_role    = $stf['job_title'] ?: ($stf['job_group'] ?: '職員');
        $st_short   = mb_substr($st_name, 0, 1, 'UTF-8');

        // 五十音行の判定
        $kana_row = '他';
        if (!empty($st_kana)) {
            $first = mb_substr($st_kana, 0, 1, 'UTF-8');
            if (preg_match('/^[アイウエオヴ]/u', $first)) $kana_row = 'ア';
            elseif (preg_match('/^[カキクケコガギグゲゴ]/u', $first)) $kana_row = 'カ';
            elseif (preg_match('/^[サシスセソザジズゼゾ]/u', $first)) $kana_row = 'サ';
            elseif (preg_match('/^[タチツテトダヂヅデド]/u', $first)) $kana_row = 'タ';
            elseif (preg_match('/^[ナニヌネノ]/u', $first)) $kana_row = 'ナ';
            elseif (preg_match('/^[ハヒフヘホバビブベボパピプペポ]/u', $first)) $kana_row = 'ハ';
            elseif (preg_match('/^[マミムメモ]/u', $first)) $kana_row = 'マ';
            elseif (preg_match('/^[ヤユヨ]/u', $first)) $kana_row = 'ヤ';
            elseif (preg_match('/^[ラリルレロ]/u', $first)) $kana_row = 'ラ';
            elseif (preg_match('/^[ワヲン]/u', $first)) $kana_row = 'ワ';
        }

        // INSERT
        $ins = $pdo->prepare("INSERT INTO staff (
            staff_code, staff_name, short_icon, kana, kana_row, dept_id, role, phone_number, home_phone, postal_code, address, is_deleted, updated_at
        ) VALUES (
            :code, :name, :icon, :kana, :row, :dept, :role, :phone, :hphone, :post, :addr, FALSE, NOW()
        )");
        $ins->execute([
            ':code'   => $stf_code,
            ':name'   => $st_name,
            ':icon'   => $st_short,
            ':kana'   => $st_kana,
            ':row'    => $kana_row,
            ':dept'   => $dept_id,
            ':role'   => $st_role,
            ':phone'  => $mobile,
            ':hphone' => $home_phone,
            ':post'   => $postal,
            ':addr'   => $address
        ]);
        $inserted++;
        $matched_stf_codes[] = $stf_code;
    }

    $msg = "STF（職員情報検索）から {$updated} 名の職員情報を同期しました。";
    if ($inserted > 0) {
        $msg .= " （新規職員 {$inserted} 名を追加登録）";
    }

    return [
        'success'  => true,
        'message'  => $msg,
        'updated'  => $updated,
        'inserted' => $inserted
    ];
}

