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

    // 2. カラム確認と追加
    $pdo->exec("
        ALTER TABLE staff ADD COLUMN IF NOT EXISTS staff_code VARCHAR(20);
        ALTER TABLE staff ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30);
        ALTER TABLE staff ADD COLUMN IF NOT EXISTS ext_number VARCHAR(20);
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

    return [
        'success' => true,
        'message' => "STF（職員情報検索）から {$updated} 名の職員情報（氏名・カナ・携帯番号・役職）を同期しました。",
        'updated' => $updated
    ];
}
