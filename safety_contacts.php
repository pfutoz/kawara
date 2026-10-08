<?php
// 1. セッション開始（未ログインでも利用可能とするためリダイレクトは行わない）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. DB接続
require_once __DIR__ . '/includes/db.php';

if (file_exists('includes/stf_sync.php')) {
    require_once 'includes/stf_sync.php';
}
if (file_exists('includes/line_helper.php')) {
    require_once 'includes/line_helper.php';
}
$tunnel_status = function_exists('getLineTunnelStatus') ? getLineTunnelStatus() : [
    'is_online'   => false,
    'url'         => '',
    'webhook_url' => '',
    'checked_at'  => ''
];

// ログイン中であればユーザー情報を取得（未ログインでもOK）
$current_staff_id = isset($_SESSION['staff_id']) ? (int)$_SESSION['staff_id'] : 0;
$login_user = null;
$is_admin = false;

if ($current_staff_id > 0) {
    $stmt_user = $pdo->prepare("SELECT s.*, d.dept_name FROM staff s LEFT JOIN target_departments d ON s.dept_id = d.dept_id WHERE s.staff_id = :id AND (s.is_deleted IS NOT TRUE)");
    $stmt_user->execute([':id' => $current_staff_id]);
    $login_user = $stmt_user->fetch();
    $is_admin = (bool)($login_user['is_admin'] ?? false);
    $is_can_toggle = (bool)($login_user['can_toggle_disaster'] ?? false);
} else {
    $is_can_toggle = false;
}

// 3. POST処理（生存報告・連絡先更新・新規点呼イベント発令）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 生存・安否報告（ログイン不要！staff_idを指定して誰でもワンタップ報告可能）
    if ($action === 'report_safety') {
        $target_sid = (int)($_POST['staff_id'] ?? 0);
        $status     = $_POST['status'] ?? 'safe';
        $message    = trim($_POST['message'] ?? '');
        $ip_addr    = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($target_sid > 0) {
            // スタッフ名の確認
            $stmt_st = $pdo->prepare("SELECT staff_name FROM staff WHERE staff_id = :id");
            $stmt_st->execute([':id' => $target_sid]);
            $st_info = $stmt_st->fetch();
            $s_name = $st_info ? $st_info['staff_name'] : 'スタッフ';

            $stmt = $pdo->prepare("INSERT INTO safety_checks (staff_id, status, message, reported_at, ip_address) VALUES (:sid, :st, :msg, NOW(), :ip)");
            $stmt->execute([
                ':sid' => $target_sid,
                ':st'  => $status,
                ':msg' => $message,
                ':ip'  => $ip_addr
            ]);

            $st_label = ($status === 'safe') ? '🟢 無事・出勤可' : (($status === 'caution') ? '🟡 自宅待機' : '🔴 被災・支援要');
            $_SESSION['notice_msg'] = "🎉 <b>{$s_name} さん</b>の生存確認（{$st_label}）を受付・記録しました！ (" . date('H:i') . ")";
            
            // 報告した人を次回自動選択できるようクッキーまたはセッションに軽く保存
            $_SESSION['last_reported_staff_id'] = $target_sid;
        }

        $dept_param = isset($_GET['dept']) ? '?dept=' . (int)$_GET['dept'] : '';
        header("Location: safety_contacts.php" . $dept_param);
        exit;
    }

    // スタッフの連絡先・LINE ID・自宅情報更新
    if ($action === 'update_contact') {
        $target_sid = (int)($_POST['target_staff_id'] ?? 0);
        if ($target_sid > 0) {
            $ext_number        = trim($_POST['ext_number'] ?? '');
            $phone_number      = trim($_POST['phone_number'] ?? '');
            $home_phone        = trim($_POST['home_phone'] ?? '');
            $postal_code       = trim($_POST['postal_code'] ?? '');
            $address           = trim($_POST['address'] ?? '');
            $emergency_contact = trim($_POST['emergency_contact'] ?? '');
            $line_user_id      = trim($_POST['line_user_id'] ?? '');

            $stmt = $pdo->prepare("UPDATE staff SET 
                ext_number = :ext, 
                phone_number = :phone, 
                home_phone = :home_phone,
                postal_code = :postal_code,
                address = :address,
                emergency_contact = :emg, 
                line_user_id = :line, 
                updated_at = NOW() 
                WHERE staff_id = :sid");
            $stmt->execute([
                ':ext'         => $ext_number,
                ':phone'       => $phone_number,
                ':home_phone'  => $home_phone,
                ':postal_code' => $postal_code,
                ':address'     => $address,
                ':emg'         => $emergency_contact,
                ':line'        => $line_user_id,
                ':sid'         => $target_sid
            ]);
            $_SESSION['notice_msg'] = "✓ 連絡先情報（携帯・自宅電話・住所）を更新しました。";
        }
        $dept_param = isset($_GET['dept']) ? '?dept=' . (int)$_GET['dept'] : '';
        header("Location: safety_contacts.php" . $dept_param);
        exit;
    }

    // 管理者用：新規点呼・安否確認イベント発令
    if ($action === 'create_safety_event') {
        $event_title    = trim($_POST['event_title'] ?? '【緊急点呼】一斉安否確認');
        $event_desc     = trim($_POST['event_desc'] ?? '');
        $chosen_mode    = trim($_POST['event_safety_mode'] ?? (isset($_POST['is_disaster_mode']) && $_POST['is_disaster_mode'] === '1' ? 'disaster' : 'drill'));
        if (!in_array($chosen_mode, ['normal', 'drill', 'disaster'])) {
            $chosen_mode = 'drill';
        }
        $is_disaster_ev = ($chosen_mode === 'disaster');

        // 過去のイベントをクローズ
        $pdo->exec("UPDATE safety_events SET is_active = FALSE, closed_at = NOW() WHERE is_active = TRUE");

        $stmt = $pdo->prepare("INSERT INTO safety_events (title, description, is_active, is_disaster_mode, safety_mode, created_by, created_at) VALUES (:title, :desc, TRUE, :dm, :sm, :cb, NOW())");
        $stmt->execute([
            ':title' => $event_title,
            ':desc'  => $event_desc,
            ':dm'    => $is_disaster_ev ? 'true' : 'false',
            ':sm'    => $chosen_mode,
            ':cb'    => $current_staff_id > 0 ? $current_staff_id : null
        ]);

        $line_broadcast_sent = 0;
        if (isset($_POST['send_line_broadcast']) && $_POST['send_line_broadcast'] === '1' && function_exists('sendLineNotification')) {
            $target_ids = $pdo->query("SELECT staff_id FROM staff WHERE line_user_id IS NOT NULL AND TRIM(line_user_id) != '' AND (is_deleted IS NOT TRUE)")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($target_ids)) {
                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host_url = $proto . $_SERVER['HTTP_HOST'];
                $safety_url = $host_url . '/kawara/safety_contacts.php';
                $b_msg = "🚨 【医療法人小野会 BCP安否確認発令】\n"
                       . "点呼：「{$event_title}」\n"
                       . ($event_desc ? "指示：{$event_desc}\n\n" : "\n")
                       . "全職員は速やかに下記のリンクを開き、1秒生存報告を行ってください：\n"
                       . $safety_url;
                $line_res = sendLineNotification($pdo, $target_ids, $b_msg);
                if ($line_res['success']) {
                    $line_broadcast_sent = $line_res['sent_count'];
                }
            }
        }

        $mode_labels = [
            'disaster' => '【🚨災害時緊急モード（個人情報全開示）】',
            'drill'    => '【🛡️訓練モード（かわら版バナー表示）】',
            'normal'   => '【🌿平常モード（日常運用・バナー非表示）】'
        ];
        $mode_text = $mode_labels[$chosen_mode] ?? '【🛡️訓練モード】';
        $line_text = $line_broadcast_sent > 0 ? " （💬 LINE一斉送信: {$line_broadcast_sent}名）" : "";
        $_SESSION['notice_msg'] = "🚨 新しい安否確認・生存点呼「{$event_title}」{$mode_text}を発令しました！{$line_text}全職員に報告を促してください。";
        header("Location: safety_contacts.php");
        exit;
    }

    // 💬 LINE安否確認メッセージ送信（一括または個別）
    if ($action === 'send_line_message') {
        $target_type = $_POST['target_type'] ?? 'all'; // 'all' or 'single'
        $single_sid  = (int)($_POST['single_staff_id'] ?? 0);
        $line_text   = trim($_POST['line_message'] ?? '');

        if (!empty($line_text) && function_exists('sendLineNotification')) {
            if ($target_type === 'single' && $single_sid > 0) {
                $target_ids = [$single_sid];
            } else {
                $target_ids = $pdo->query("SELECT staff_id FROM staff WHERE line_user_id IS NOT NULL AND TRIM(line_user_id) != '' AND (is_deleted IS NOT TRUE)")->fetchAll(PDO::FETCH_COLUMN);
            }

            if (!empty($target_ids)) {
                $res = sendLineNotification($pdo, $target_ids, $line_text);
                if ($res['success']) {
                    $_SESSION['notice_msg'] = "🎉 <b>LINEメッセージを送信しました！</b> （送信成功: {$res['sent_count']}件）";
                } else {
                    $err_str = implode('<br>', $res['errors'] ?? []);
                    $_SESSION['notice_msg'] = "⚠️ <b>LINE送信エラー:</b> {$err_str}";
                }
            } else {
                $_SESSION['notice_msg'] = "⚠️ LINE連携済みの職員が見つかりません。";
            }
        } else {
            $_SESSION['notice_msg'] = "⚠️ メッセージ本文が入力されていません。";
        }
        $dept_param = isset($_GET['dept']) ? '?dept=' . (int)$_GET['dept'] : '';
        header("Location: safety_contacts.php" . $dept_param);
        exit;
    }

    // STF（職員検索アプリ）からのマスター同期
    if ($action === 'sync_stf') {
        if (function_exists('syncStaffFromSTF')) {
            $sync_res = syncStaffFromSTF($pdo);
            $_SESSION['notice_msg'] = $sync_res['message'];
        }
        $dept_param = isset($_GET['dept']) ? '?dept=' . (int)$_GET['dept'] : '';
        header("Location: safety_contacts.php" . $dept_param);
        exit;
    }

    // 🚨 災害時緊急モード（個人情報全開示）、🛡️ 訓練モード、🌿 平常モード の切り替え
    if ($action === 'toggle_disaster_mode') {
        $raw_mode = trim($_POST['target_mode'] ?? '0');
        $auth_pin = trim($_POST['auth_pin'] ?? '');

        if ($raw_mode === '1' || $raw_mode === 'disaster') {
            $new_mode = 'disaster';
            $is_disaster_mode_val = true;
        } elseif ($raw_mode === 'drill') {
            $new_mode = 'drill';
            $is_disaster_mode_val = false;
        } else {
            $new_mode = 'normal';
            $is_disaster_mode_val = false;
        }

        // 権限チェック：
        // 1. ログイン中のユーザーが権限者（山本太・医師など）であるか
        // 2. または、送信された数字暗証番号が権限者の誰かの暗証番号と一致するか
        $can_toggle_now = false;
        $operator_name = '';

        if ($current_staff_id > 0 && !empty($login_user['can_toggle_disaster'])) {
            $can_toggle_now = true;
            $operator_name = $login_user['staff_name'];
        } elseif (!empty($auth_pin)) {
            // 暗証番号照合（山本太: 1232104、医師: 5500 など）
            $stmt_pin = $pdo->prepare("SELECT staff_name, role FROM staff WHERE pin_code = :pin AND can_toggle_disaster = TRUE AND (is_deleted IS NOT TRUE) LIMIT 1");
            $stmt_pin->execute([':pin' => $auth_pin]);
            $pin_match = $stmt_pin->fetch();
            if ($pin_match) {
                $can_toggle_now = true;
                $operator_name = $pin_match['staff_name'] . '（' . $pin_match['role'] . '）';
            }
        }

        if (!$can_toggle_now) {
            $_SESSION['notice_msg'] = "❌ <b>【権限エラー】</b> モードの切替は、原則「医師」または「管理者（事務長・山本）」のみ実行可能です。暗証番号が正しくありません。";
            $dept_param = isset($_GET['dept']) ? '?dept=' . (int)$_GET['dept'] : '';
            header("Location: safety_contacts.php" . $dept_param);
            exit;
        }
        
        // アクティブイベントの存在確認＆更新
        $cur_ev = $pdo->query("SELECT event_id FROM safety_events WHERE is_active = TRUE ORDER BY event_id DESC LIMIT 1")->fetch();
        if ($cur_ev) {
            $pdo->prepare("UPDATE safety_events SET is_disaster_mode = :m, safety_mode = :sm WHERE event_id = :id")
                ->execute([':m' => $is_disaster_mode_val ? 'true' : 'false', ':sm' => $new_mode, ':id' => $cur_ev['event_id']]);
        } else {
            $pdo->prepare("INSERT INTO safety_events (title, description, is_active, is_disaster_mode, safety_mode, created_at) VALUES ('【日常運用】BCP安否確認', '自動設定イベント', TRUE, :m, :sm, NOW())")
                ->execute([':m' => $is_disaster_mode_val ? 'true' : 'false', ':sm' => $new_mode]);
        }
        
        $op_text = $operator_name ? " （認証操作者: {$operator_name}）" : "";
        if ($new_mode === 'disaster') {
            $_SESSION['notice_msg'] = "🚨 <b>【災害時緊急モード発令】</b> 人命救助・緊急安否確認のため、全職員の個人情報（携帯・自宅電話・住所）を全開示しました。かわら版に緊急報告バナーが表示されます。{$op_text}";
        } elseif ($new_mode === 'drill') {
            $_SESSION['notice_msg'] = "🛡️ <b>【訓練モードへ切替】</b> 安否確認の点呼訓練を実施します。かわら版に訓練報告バナーが表示されます（個人情報は保護されます）。{$op_text}";
        } else {
            $_SESSION['notice_msg'] = "🌿 <b>【平常モードへ復帰】</b> 日常運用に戻しました。かわら版の安否確認催促バナーは非表示になります。{$op_text}";
        }
        $dept_param = isset($_GET['dept']) ? '?dept=' . (int)$_GET['dept'] : '';
        header("Location: safety_contacts.php" . $dept_param);
        exit;
    }
}

$notice_msg = $_SESSION['notice_msg'] ?? '';
unset($_SESSION['notice_msg']);

// 4. 現在アクティブな安否確認イベント ＆ モード判定
$active_event = $pdo->query("SELECT * FROM safety_events WHERE is_active = TRUE ORDER BY event_id DESC LIMIT 1")->fetch();
$safety_mode = $active_event['safety_mode'] ?? (!empty($active_event['is_disaster_mode']) ? 'disaster' : 'normal');
$is_disaster_mode = ($safety_mode === 'disaster' || !empty($active_event['is_disaster_mode']));

// 📱 電話番号の表示判定（災害時：本物＆ワンタップ架電 / 訓練時：嘘電話番号＆架電防止）
if (!function_exists('get_phone_info')) {
    function get_phone_info($phone, $is_disaster, $phone_type = 'mobile') {
        if (empty($phone)) return null;
        $raw_digits = preg_replace('/[^\d]/', '', $phone);
        
        // 災害時：生番号をそのまま公開し、家に電話・携帯へ電話できるようにする
        if ($is_disaster) {
            return [
                'display'   => $phone,
                'tel_link'  => 'tel:' . $raw_digits,
                'is_real'   => true,
                'type_icon' => ($phone_type === 'home') ? '🏠' : '📱',
                'type_name' => ($phone_type === 'home') ? '自宅電話' : '携帯'
            ];
        }
        
        // 訓練時：嘘電話番号（ダミー番号）を表示。誤架電事故を完全に防ぐため tel: リンクは付与しない
        if ($phone_type === 'mobile') {
            // 携帯 090-XXXX-5678 -> 090-0000-5678 (訓練嘘番号)
            if (strlen($raw_digits) === 11) {
                $dummy = substr($raw_digits, 0, 3) . '-0000-' . substr($raw_digits, -4);
            } else {
                $dummy = '090-0000-****';
            }
        } else {
            // 自宅固定 088-XXX-7369 -> 088-000-7369 (訓練嘘番号)
            if (strlen($raw_digits) === 10) {
                $dummy = substr($raw_digits, 0, 3) . '-000-' . substr($raw_digits, -4);
            } else {
                $dummy = '088-000-****';
            }
        }
        
        return [
            'display'   => $dummy,
            'tel_link'  => null,
            'is_real'   => false,
            'type_icon' => ($phone_type === 'home') ? '🏠' : '📱',
            'type_name' => ($phone_type === 'home') ? '自宅(ダミー)' : '携帯(ダミー)'
        ];
    }
}

// 📍 住所の表示判定（災害時：番地・建物名まで全開示＋GoogleMapルート / 訓練時：市区町村まで表示＆非表示マスク）
if (!function_exists('get_address_info')) {
    function get_address_info($address, $postal_code, $is_disaster) {
        if (empty($address)) return null;
        
        // 災害時：全住所・郵便番号を完全公開し、Googleマップ駆けつけルートを開けるようにする
        if ($is_disaster) {
            $post_str = !empty($postal_code) ? "〒{$postal_code} " : "";
            return [
                'display'  => $post_str . $address,
                'map_url'  => 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address),
                'is_real'  => true
            ];
        }
        
        // 訓練時：市区町村までを表示し、番地・建物名は「***（訓練時非表示）」として保護
        $masked = preg_replace('/^([^市区町村]+[市区町村])(.*)$/u', '$1***（訓練時非表示）', $address);
        if ($masked === $address) {
            $masked = mb_substr($address, 0, 4) . '***（訓練時非表示）';
        }
        $post_masked = !empty($postal_code) ? "〒" . substr($postal_code, 0, 3) . "-**** " : "";
        
        return [
            'display'  => $post_masked . $masked,
            'map_url'  => null,
            'is_real'  => false
        ];
    }
}

// 5. 部署一覧＆絞り込み
// ※ dept_code = 'all' (全員（全スタッフ）) はかわら版の投稿対象フラグであり、実在する部署ではないため連絡網からは除外
$selected_dept = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
if ($selected_dept === 1) {
    // 過去のリンクや直打ち等で dept=1（全員）が渡された場合は全職員（0）として正規化
    $selected_dept = 0;
}

// 全職員数（在籍中）
$total_all_staff = (int)$pdo->query("SELECT count(*) FROM staff WHERE is_deleted IS NOT TRUE")->fetchColumn();

// 各実部署一覧（所属人数も集計）
$departments = $pdo->query("SELECT 
    d.*, 
    COUNT(s.staff_id) AS staff_count 
    FROM target_departments d 
    LEFT JOIN staff s ON d.dept_id = s.dept_id AND (s.is_deleted IS NOT TRUE)
    WHERE d.is_active = TRUE AND d.dept_code != 'all' 
    GROUP BY d.dept_id 
    ORDER BY d.display_order ASC")->fetchAll();

// 6. 各スタッフの最新安否報告と連絡先情報の取得
$sql = "SELECT 
            s.*,
            d.dept_name,
            sc.status AS latest_status,
            sc.message AS latest_message,
            sc.reported_at AS latest_reported_at
        FROM staff s
        LEFT JOIN target_departments d ON s.dept_id = d.dept_id
        LEFT JOIN LATERAL (
            SELECT status, message, reported_at 
            FROM safety_checks 
            WHERE staff_id = s.staff_id 
            ORDER BY reported_at DESC 
            LIMIT 1
        ) sc ON TRUE
        WHERE (s.is_deleted IS NOT TRUE)";

if ($selected_dept > 0) {
    $sql .= " AND s.dept_id = " . $selected_dept;
}
$sql .= " ORDER BY d.display_order ASC, s.kana ASC";

$staff_list = $pdo->query($sql)->fetchAll();

// 7. 全体統計の集計
$total_staff = count($staff_list);
$safe_count = 0;
$caution_count = 0;
$danger_count = 0;
$unreported_count = 0;

$now_ts = time();
$today_start_ts = strtotime('today midnight');

foreach ($staff_list as $st) {
    $rep_at = $st['latest_reported_at'] ? strtotime($st['latest_reported_at']) : null;
    $is_reported_today = ($rep_at && $rep_at >= $today_start_ts);

    if (!$is_reported_today || empty($st['latest_status'])) {
        $unreported_count++;
    } else {
        if ($st['latest_status'] === 'safe') $safe_count++;
        elseif ($st['latest_status'] === 'caution') $caution_count++;
        elseif ($st['latest_status'] === 'danger') $danger_count++;
        else $safe_count++;
    }
}

$reported_count = $total_staff - $unreported_count;
$report_rate = ($total_staff > 0) ? round(($reported_count / $total_staff) * 100) : 0;

// 上部クイック報告用のデフォルト選択スタッフID
$default_select_sid = $current_staff_id > 0 ? $current_staff_id : ($_SESSION['last_reported_staff_id'] ?? 0);

// 全スタッフ（名前選択用）
$all_staff_for_select = $pdo->query("SELECT staff_id, staff_name, dept_id, role, kana FROM staff WHERE is_deleted = FALSE ORDER BY kana ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🛡️ 職員連絡網・BCP安否生存確認 | 医療法人小野会</title>
    <!-- 60秒ごとに自動リロード（共用PC・常時表示用） -->
    <meta http-equiv="refresh" content="60">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #004085;
            --primary-light: #eef6fc;
            --safe-color: #28a745;
            --safe-bg: #e8f9ee;
            --caution-color: #e67e22;
            --caution-bg: #fff8ee;
            --danger-color: #dc3545;
            --danger-bg: #fdf2f2;
            --unreported-color: #6c757d;
            --unreported-bg: #f8f9fa;
            --border: #e2e8f0;
            --card-bg: #ffffff;
            --text-main: #333333;
            --text-sub: #666666;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; color: var(--text-main); line-height: 1.5; }

        header { background: var(--primary); color: #fff; padding: 0.8rem 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background 0.3s; }
        header.disaster-mode { background: #991b1b; border-bottom: 3.5px solid #ef4444; }
        .header-container { max-width: 1250px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header-title h1 { font-size: 1.25rem; font-weight: bold; display: flex; align-items: center; gap: 6px; }
        .header-title span { font-size: 0.8rem; opacity: 0.9; margin-left: 6px; }
        .header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .btn-header { background: rgba(255,255,255,0.2); color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.82rem; font-weight: bold; transition: all 0.2s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-header:hover { background: rgba(255,255,255,0.35); transform: translateY(-1px); }

        main { max-width: 1250px; margin: 1.2rem auto; padding: 0 1rem; }

        .alert-notice { background: #dcfce7; color: #15803d; border: 1.5px solid #86efac; padding: 12px 16px; border-radius: 8px; font-size: 0.95rem; font-weight: bold; margin-bottom: 1.2rem; box-shadow: 0 2px 8px rgba(34,197,94,0.15); animation: fade-in 0.3s; }
        @keyframes fade-in { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        /* 🚨 災害時緊急モードバナー */
        .banner-disaster {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 50%, #b91c1c 100%);
            color: #fff;
            border: 2.5px solid #f87171;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 1.3rem;
            box-shadow: 0 4px 18px rgba(185, 28, 28, 0.35);
            animation: pulse-disaster 2s infinite ease-in-out;
        }
        @keyframes pulse-disaster {
            0%, 100% { box-shadow: 0 4px 18px rgba(185, 28, 28, 0.35); }
            50% { box-shadow: 0 4px 28px rgba(239, 68, 68, 0.65); }
        }
        .banner-disaster-title {
            font-size: 1.18rem;
            font-weight: 900;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fef08a;
        }
        .banner-disaster-desc {
            font-size: 0.88rem;
            line-height: 1.55;
            color: #f8fafc;
        }
        .banner-disaster-desc strong {
            color: #fef08a;
            text-decoration: underline;
        }

        /* 🛡️ 訓練・平時モードバナー */
        .banner-training {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-left: 5px solid #3b82f6;
            border-radius: 8px;
            padding: 10px 16px;
            margin-bottom: 1.2rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .banner-training-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .badge-training {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-size: 0.78rem;
            font-weight: 900;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .banner-training-text {
            font-size: 0.84rem;
            color: #334155;
            margin-left: 6px;
        }
        .banner-training-note {
            font-size: 0.78rem;
            color: #64748b;
        }

        /* 電話番号＆住所の表示スタイル */
        .btn-call-home {
            background: #0284c7;
            color: #fff;
            padding: 4px 8px;
            border-radius: 5px;
            font-weight: 900;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.82rem;
            box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
            white-space: nowrap;
            transition: all 0.15s;
        }
        .btn-call-home:hover {
            background: #0369a1;
            transform: scale(1.03);
            color: #fff;
        }

        .btn-call-mobile {
            background: #059669;
            color: #fff;
            padding: 4px 8px;
            border-radius: 5px;
            font-weight: 900;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.82rem;
            box-shadow: 0 1px 3px rgba(5, 150, 105, 0.3);
            white-space: nowrap;
            transition: all 0.15s;
        }
        .btn-call-mobile:hover {
            background: #047857;
            transform: scale(1.03);
            color: #fff;
        }

        .dummy-phone {
            color: #64748b;
            font-family: 'Outfit', monospace;
            font-size: 0.84rem;
            background: #f1f5f9;
            padding: 3px 6px;
            border-radius: 4px;
            border: 1px dashed #cbd5e1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
        }
        .dummy-phone:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
        }

        .tag-dummy {
            font-size: 0.68rem;
            color: #b45309;
            background: #fef3c7;
            border: 1px solid #fde68a;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: bold;
        }

        .real-address {
            font-size: 0.82rem;
            color: #1e293b;
            line-height: 1.4;
            font-weight: 500;
            margin-bottom: 3px;
        }

        .btn-map-link {
            font-size: 0.72rem;
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #86efac;
            padding: 2px 6px;
            border-radius: 3px;
            text-decoration: none;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            transition: all 0.15s;
        }
        .btn-map-link:hover {
            background: #dcfce7;
            border-color: #4ade80;
        }

        .masked-address {
            font-size: 0.8rem;
            color: #64748b;
            font-style: italic;
            line-height: 1.35;
        }

        /* 超かんたんクイック生存チェックカード（ログイン不要・タイムカード感覚） */
        .quick-punch-card {
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
            border: 2.5px solid #22c55e;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.4rem;
            box-shadow: 0 4px 14px rgba(34,197,94,0.12);
        }
        .punch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }
        .punch-title {
            font-size: 1.15rem;
            font-weight: 900;
            color: #166534;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .punch-subtitle {
            font-size: 0.82rem;
            color: #15803d;
            font-weight: bold;
        }

        .punch-form-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .staff-selector-wrap {
            flex: 1;
            min-width: 240px;
        }
        .select-large {
            width: 100%;
            padding: 10px 12px;
            font-size: 1.05rem;
            font-weight: bold;
            border: 2px solid #86efac;
            border-radius: 8px;
            background: #fff;
            color: #1e293b;
            cursor: pointer;
        }
        .select-large:focus {
            outline: none;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34,197,94,0.25);
        }

        .punch-buttons-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            flex: 2;
            min-width: 320px;
        }
        .btn-punch {
            flex: 1;
            min-width: 100px;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-punch:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .btn-punch.safe { background: #16a34a; color: #fff; }
        .btn-punch.caution { background: #ea580c; color: #fff; }
        .btn-punch.danger { background: #dc2626; color: #fff; }

        /* 全体集計インジケーター */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 1.2rem;
        }
        .summary-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .summary-num {
            font-family: 'Outfit', sans-serif;
            font-size: 1.7rem;
            font-weight: 700;
            line-height: 1.1;
            margin: 3px 0;
        }
        .summary-label {
            font-size: 0.78rem;
            font-weight: bold;
            color: var(--text-sub);
        }

        /* 確認進捗バー */
        .progress-container {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 1.2rem;
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.82rem;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .progress-bar-outer {
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            display: flex;
        }
        .p-bar-safe { background: var(--safe-color); }
        .p-bar-caution { background: var(--caution-color); }
        .p-bar-danger { background: var(--danger-color); }

        /* ツールバー＆タブ */
        .toolbar-section {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 1.2rem;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .dept-tabs {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .dept-tab {
            background: #f8f9fa;
            border: 1px solid #ced4da;
            padding: 4px 10px;
            border-radius: 6px;
            text-decoration: none;
            color: #555;
            font-size: 0.8rem;
            font-weight: bold;
            transition: all 0.15s;
        }
        .dept-tab:hover { background: #eef6fc; border-color: var(--primary); }
        .dept-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }

        /* スタッフ連絡網テーブル */
        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow-x: auto;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        table.contact-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            text-align: left;
            min-width: 850px;
        }
        table.contact-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: bold;
            padding: 10px 12px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        table.contact-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        table.contact-table tr:hover { background: #f8fafc; }

        /* 行内のワンタップダイレクトボタン */
        .row-action-form {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .btn-inline-punch {
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.1s;
            white-space: nowrap;
        }
        .btn-inline-punch:hover { opacity: 0.85; transform: scale(1.05); }
        .btn-inline-punch.safe { background: #22c55e; color: #fff; }
        .btn-inline-punch.caution { background: #f97316; color: #fff; }
        .btn-inline-punch.danger { background: #ef4444; color: #fff; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: bold;
            white-space: nowrap;
        }
        .status-badge.safe { background: var(--safe-bg); color: #15803d; border: 1px solid #86efac; }
        .status-badge.caution { background: var(--caution-bg); color: #c2410c; border: 1px solid #fed7aa; }
        .status-badge.danger { background: var(--danger-bg); color: #b91c1c; border: 1px solid #fca5a5; }
        .status-badge.unreported { background: var(--unreported-bg); color: var(--unreported-color); border: 1px solid #cbd5e1; }

        .line-badge {
            display: inline-block;
            font-size: 0.72rem;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: bold;
        }
        .line-badge.active { background: #e8f9ee; color: #06c755; border: 1px solid #b2e8c4; }
        .line-badge.inactive { background: #f1f5f9; color: #94a3b8; }

        /* トンネルステータスバッジ（ヘッダー用） */
        .btn-tunnel-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            font-family: inherit;
        }
        .btn-tunnel-status.online {
            background: #dcfce7;
            color: #15803d;
            border-color: #86efac;
            box-shadow: 0 1px 4px rgba(22, 163, 74, 0.2);
        }
        .btn-tunnel-status.online:hover {
            background: #bbf7d0;
            transform: translateY(-1px);
        }
        .btn-tunnel-status.offline {
            background: rgba(255, 255, 255, 0.15);
            color: #cbd5e1;
            border-color: rgba(255, 255, 255, 0.25);
        }
        .btn-tunnel-status.offline:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        .pulse-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
        }
        .pulse-dot.online {
            background: #16a34a;
            box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7);
            animation: pulse-green 2s infinite;
        }
        .pulse-dot.offline {
            background: #94a3b8;
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7); }
            70% { transform: scale(1.15); box-shadow: 0 0 0 7px rgba(22, 163, 74, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); }
        }

        /* 📱 スタッフLINE連携モーダル用スタイル */
        .line-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 10001;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .line-modal-overlay.active { display: flex; }
        .line-modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 480px;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        .line-modal-header {
            background: #06c755;
            color: #ffffff;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .line-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .line-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
            opacity: 0.85;
        }
        .line-modal-close:hover { opacity: 1; }
        .line-modal-body {
            padding: 18px 20px;
            overflow-y: auto;
        }
        .line-step-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }
        .line-step-num {
            display: inline-block;
            background: #06c755;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
            margin-right: 5px;
        }
        .line-step-title {
            font-size: 0.88rem;
            font-weight: 800;
            color: #1e293b;
        }
        .link-code-digit {
            font-family: 'Outfit', monospace;
            font-size: 2.2rem;
            font-weight: 900;
            letter-spacing: 10px;
            color: #065f46;
            background: #ecfdf5;
            border: 2px dashed #06c755;
            border-radius: 8px;
            padding: 8px 14px;
            text-align: center;
            margin: 10px 0;
        }
        .btn-copy-code {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-copy-code:hover { background: #f1f5f9; color: #1e293b; }
        .line-waiting-box {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.8rem;
            color: #059669;
            background: #f0fdf4;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
            border: 1px solid #bbf7d0;
        }
        .line-spinner {
            width: 16px;
            height: 16px;
            border: 2.5px solid #86efac;
            border-top-color: #059669;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .phone-link { color: var(--primary); text-decoration: none; font-weight: bold; }
        .phone-link:hover { text-decoration: underline; }

        /* モーダル */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: #fff;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        /* 📇 スタッフ連絡網カードグリッド（スマホ完全対応！） */
        .staff-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 640px) {
            .staff-card-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .dept-tabs {
                flex-wrap: nowrap !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                padding-bottom: 6px;
            }
            .dept-tab {
                white-space: nowrap !important;
                flex-shrink: 0 !important;
            }
            .summary-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        .staff-contact-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.15s;
            position: relative;
        }
        .staff-contact-card:hover {
            border-color: #94a3b8;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        .staff-contact-card.status-safe { border-left: 6px solid #22c55e; }
        .staff-contact-card.status-caution { border-left: 6px solid #f97316; }
        .staff-contact-card.status-danger { border-left: 6px solid #ef4444; background: #fffdfd; }
        .staff-contact-card.status-unreported { border-left: 6px solid #cbd5e1; }

        .scard-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 8px;
        }
        .scard-name-wrap { flex: 1; }
        .scard-name { font-size: 1.25rem; font-weight: 900; color: #0f172a; line-height: 1.25; }
        .scard-kana { font-size: 0.74rem; color: #64748b; margin-top: 2px; }
        .scard-role { font-size: 0.78rem; font-weight: bold; color: #475569; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; display: inline-block; margin-top: 4px; }

        .scard-safety-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
        }
        .scard-punch-btns {
            display: flex;
            gap: 6px;
            margin-top: 8px;
        }
        .btn-card-punch {
            flex: 1;
            min-height: 46px;
            padding: 10px 4px;
            border: none;
            border-radius: 8px;
            font-size: 0.92rem;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            transition: all 0.1s;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-card-punch:active { transform: scale(0.96); }
        .btn-card-punch.safe { background: #16a34a; color: #fff; }
        .btn-card-punch.caution { background: #ea580c; color: #fff; }
        .btn-card-punch.danger { background: #dc2626; color: #fff; }

        .scard-contacts {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .scard-field {
            background: #fafafa;
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 8px 10px;
        }
        .scard-field-label {
            font-size: 0.75rem;
            font-weight: bold;
            color: #64748b;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-call-card-home {
            width: 100%;
            min-height: 48px;
            background: #0284c7;
            color: #fff;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 900;
            font-size: 0.96rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
            transition: all 0.15s;
        }
        .btn-call-card-home:hover { background: #0369a1; }
        .btn-call-card-mobile {
            width: 100%;
            min-height: 48px;
            background: #059669;
            color: #fff;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 900;
            font-size: 0.96rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
            transition: all 0.15s;
        }
        .btn-call-card-mobile:hover { background: #047857; }
        .card-dummy-phone {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            padding: 10px 12px;
            min-height: 44px;
            border-radius: 8px;
            font-family: 'Outfit', monospace;
            font-size: 0.92rem;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .btn-view-toggle {
            background: #fff;
            border: 1.5px solid #cbd5e1;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.84rem;
            font-weight: bold;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-view-toggle.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* 📱 災害モード切替用 数字キータッチパッド（PINモーダル） */
        .pin-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .pin-modal-overlay.active { display: flex; animation: fade-in 0.2s; }
        .pin-card {
            background: #ffffff;
            width: 100%;
            max-width: 360px;
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            text-align: center;
        }
        .pin-card-header { margin-bottom: 12px; }
        .pin-title { font-size: 1.15rem; font-weight: 900; color: #1e293b; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .pin-subtitle { font-size: 0.8rem; color: #b91c1c; font-weight: bold; margin-top: 4px; }
        .pin-desc { font-size: 0.78rem; color: #64748b; margin-top: 6px; }

        .pin-display-wrap {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            height: 52px;
            margin: 12px 0 16px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.04);
        }
        .pin-dots { font-size: 1.6rem; letter-spacing: 6px; color: #0f172a; font-weight: 900; font-family: 'Outfit', monospace; }
        .pin-placeholder { color: #94a3b8; font-size: 0.85rem; }

        .pin-keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }
        .pin-key {
            height: 54px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.1s;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
        }
        .pin-key:hover { background: #f1f5f9; }
        .pin-key:active { transform: scale(0.94); background: #e2e8f0; }
        .pin-key.btn-clear { font-size: 0.95rem; font-weight: bold; color: #dc2626; background: #fef2f2; border-color: #fecaca; }
        .pin-key.btn-backspace { font-size: 1.2rem; color: #475569; background: #f8fafc; }

        .btn-pin-submit {
            width: 100%;
            height: 48px;
            background: #dc2626;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(220,38,38,0.3);
            transition: all 0.15s;
        }
        .btn-pin-submit:hover { background: #b91c1c; transform: translateY(-1px); }
        .btn-pin-cancel {
            width: 100%;
            background: none;
            border: none;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: bold;
            padding: 8px;
            margin-top: 6px;
            cursor: pointer;
        }
        .btn-pin-cancel:hover { text-decoration: underline; color: #334155; }

        @media print {
            header, .quick-punch-card, .toolbar-section, .btn-print, form, .btn-header, .row-action-form, .pin-modal-overlay { display: none !important; }
            body { background: #fff !important; }
            main { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .table-card { border: none !important; box-shadow: none !important; overflow: visible !important; }
            table.contact-table th, table.contact-table td { border: 1px solid #ccc !important; padding: 5px 6px !important; font-size: 8pt !important; }
        }
    </style>
</head>
<body>

    <header class="<?= $is_disaster_mode ? 'disaster-mode' : '' ?>">
        <div class="header-container">
            <div class="header-title">
                <?php if ($is_disaster_mode): ?>
                    <h1 style="color:#fef08a;">🚨 【災害緊急モード発令中】 職員連絡網・人命安否確認</h1>
                <?php else: ?>
                    <h1>🛡️ 職員連絡網・BCP安否確認</h1>
                <?php endif; ?>
            </div>
            <div class="header-actions">
                <!-- 🚨 災害時緊急 / 🛡️ 訓練 / 🌿 平常 3モード切替ボタン -->
                <?php if ($safety_mode === 'disaster'): ?>
                    <button type="button" class="btn-header" style="background:#16a34a; border:2px solid #bbf7d0; font-weight:900;" onclick="handleToggleDisasterMode('normal', <?= $is_can_toggle ? 'true' : 'false' ?>)" title="平常モードへ戻す">
                        🌿 平常モードへ戻す
                    </button>
                <?php elseif ($safety_mode === 'drill'): ?>
                    <button type="button" class="btn-header" style="background:#15803d; border:2px solid #bbf7d0; font-weight:bold;" onclick="handleToggleDisasterMode('normal', <?= $is_can_toggle ? 'true' : 'false' ?>)" title="平常モードへ戻す">
                        🌿 訓練終了（平常へ）
                    </button>
                    <button type="button" class="btn-header" style="background:#dc2626; border:2px solid #fecaca; font-weight:900;" onclick="handleToggleDisasterMode('disaster', <?= $is_can_toggle ? 'true' : 'false' ?>)" title="全個人情報を開示して自宅架電可能にする">
                        🚨 災害緊急モード
                    </button>
                <?php else: /* normal */ ?>
                    <button type="button" class="btn-header" style="background:#f59e0b; border:2px solid #fde68a; color:#78350f; font-weight:bold;" onclick="handleToggleDisasterMode('drill', <?= $is_can_toggle ? 'true' : 'false' ?>)" title="訓練モードに切り替えてかわら版にバナーを表示">
                        🛡️ 訓練モード開始
                    </button>
                    <button type="button" class="btn-header" style="background:#dc2626; border:2px solid #fecaca; font-weight:900;" onclick="handleToggleDisasterMode('disaster', <?= $is_can_toggle ? 'true' : 'false' ?>)" title="全個人情報を開示して自宅架電可能にする">
                        🚨 災害緊急モード
                    </button>
                <?php endif; ?>

                <?php if ($login_user): ?>
                    <a href="login.php?redirect=safety_contacts.php" class="btn-header" style="background:rgba(255,255,255,0.2); text-decoration:none;" title="クリックしてユーザー切替">
                        👤 <?= htmlspecialchars($login_user['staff_name']) ?>
                        <?= $is_can_toggle ? ' <span style="color:#fef08a; font-weight:bold;">★権限者</span>' : '' ?>
                    </a>
                <?php else: ?>
                    <a href="login.php?redirect=safety_contacts.php" class="btn-header" style="background:#e67e22;">🔑 ログイン切替</a>
                <?php endif; ?>
                <?php if ($is_admin || $is_can_toggle): ?>
                    <a href="admin_disaster_staff.php" class="btn-header" style="background:#475569;" title="災害モード特定スタッフと暗証番号の管理">⚙️ 権限設定</a>
                <?php endif; ?>
                <a href="index.php" class="btn-header">📜 かわら版</a>
                <button type="button" class="btn-header" onclick="window.print()" style="background:#495057;">🖨️ A4印刷</button>
                <form method="POST" style="margin:0; display:inline;">
                    <input type="hidden" name="action" value="sync_stf">
                    <button type="submit" class="btn-header" style="background:#0284c7;" onclick="return confirm('STF（職員検索アプリ）から最新の職員情報（氏名・携帯番号など）を同期しますか？');">
                        🔄 STF同期
                    </button>
                </form>
                <button type="button" class="btn-tunnel-status <?= $tunnel_status['is_online'] ? 'online' : 'offline' ?>" onclick="openTunnelStatusModal()" title="クリックしてLINE新規受付トンネルの稼働状況を確認">
                    <span class="pulse-dot <?= $tunnel_status['is_online'] ? 'online' : 'offline' ?>"></span>
                    LINE新規受付: <?= $tunnel_status['is_online'] ? '🟢 稼働中' : '⚪ 停止中' ?>
                </button>
                <button type="button" class="btn-header" onclick="openLineSendModal()" style="background:#06c755; font-weight:bold;">💬 LINE安否連絡</button>
                <button type="button" class="btn-header" onclick="openEventModal()" style="background:#b91c1c;">📢 点呼発令</button>
            </div>
        </div>
    </header>

    <!-- モード切替POST送信用非表示フォーム -->
    <form method="POST" id="toggleDisasterForm" style="display:none;">
        <input type="hidden" name="action" value="toggle_disaster_mode">
        <input type="hidden" name="target_mode" id="toggleTargetMode" value="0">
        <input type="hidden" name="auth_pin" id="toggleAuthPin" value="">
    </form>

    <main>
        <?php if ($notice_msg): ?>
            <div class="alert-notice"><?= $notice_msg ?></div>
        <?php endif; ?>

        <!-- モード案内バナー（災害時 vs 訓練時 vs 平常時） -->
        <?php if ($safety_mode === 'disaster'): ?>
            <div class="banner-disaster">
                <div class="banner-disaster-title">
                    🚨 【災害時緊急モード発令中】 全職員の個人情報を全開示しています
                </div>
                <div class="banner-disaster-desc">
                    人命救助・緊急安否確認のため、全職員の<strong>【自宅電話（固定電話）】</strong><strong>【携帯電話番号】</strong><strong>【自宅住所】</strong>を公開中。<br>
                    連絡がつかない職員の自宅へ直接電話（「<strong>🏠 家に電話</strong>」ボタン）や、地図（「<strong>🗺️ 地図・ルート</strong>」ボタン）での安否確認・駆けつけを行ってください。
                </div>
            </div>
        <?php elseif ($safety_mode === 'drill'): ?>
            <div class="banner-training" style="border-left-color:#e67e22; background:#fffbf0;">
                <div class="banner-training-flex">
                    <div>
                        <span class="badge-training" style="background:#fef3c7; color:#b45309; border-color:#fde68a;">🛡️ 訓練モード実施中（かわら版に安否報告バナー表示中）</span>
                        <span class="banner-training-text">
                            電話番号は<strong>嘘電話番号（訓練ダミー）</strong>、住所は<strong>非表示マスキング</strong>されています。
                        </span>
                    </div>
                    <div class="banner-training-note">
                        ※ かわら版トップに「安否訓練が未報告です」バナーが表示されています。訓練終了時は「🌿 訓練終了（平常へ）」を押してください。
                    </div>
                </div>
            </div>
        <?php else: /* normal */ ?>
            <div class="banner-training">
                <div class="banner-training-flex">
                    <div>
                        <span class="badge-training" style="background:#ecfdf5; color:#065f46; border-color:#a7f3d0;">🌿 平常運用モード（日常連絡・個人情報保護）</span>
                        <span class="banner-training-text">
                            電話番号は<strong>訓練用ダミー（誤架電防止）</strong>、住所は<strong>非表示</strong>です。かわら版の安否確認催促バナーは非表示になっています。
                        </span>
                    </div>
                    <div class="banner-training-note">
                        ※ 有事の際は右上の「<strong>🚨 災害時緊急モード</strong>」を押すと全開示されます。点呼訓練の際は「<strong>🛡️ 訓練モード開始</strong>」を押してください。
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 超かんたん！ログイン不要クイック生存チェック窓口（タイムカード方式） -->
        <div class="quick-punch-card">
            <div class="punch-header">
                <div class="punch-title">
                    ⏱️ 【誰でも1秒報告】本日の生存チェック・安否報告
                </div>
                <div class="punch-subtitle">
                    ※ ログイン不要！名前を選んでボタンを押すだけで即記録されます
                </div>
            </div>

            <form method="POST" onsubmit="return validatePunchForm(this);">
                <input type="hidden" name="action" value="report_safety">
                
                <div class="punch-form-row">
                    <div class="staff-selector-wrap">
                        <select name="staff_id" id="quickStaffSelect" class="select-large" required>
                            <option value="">▼ あなたのお名前を選択してください</option>
                            <?php foreach ($all_staff_for_select as $st): ?>
                                <option value="<?= $st['staff_id'] ?>" <?= $st['staff_id'] == $default_select_sid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($st['staff_name']) ?> (<?= htmlspecialchars($st['role']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="punch-buttons-group">
                        <button type="submit" name="status" value="safe" class="btn-punch safe">
                            🟢 【無事】出勤可
                        </button>
                        <button type="submit" name="status" value="caution" class="btn-punch caution">
                            🟡 【待機】自宅待機
                        </button>
                        <button type="submit" name="status" value="danger" class="btn-punch danger">
                            🔴 【被災】支援要
                        </button>
                    </div>
                </div>

                <div style="margin-top: 8px; display:flex; gap:8px; align-items:center;">
                    <input type="text" name="message" class="select-large" style="font-size:0.85rem; padding:6px 10px; border-width:1px;" placeholder="状況メモ（例: 本日当番OK、自宅周辺停電中、無事です など任意入力）">
                </div>
            </form>
        </div>

        <!-- 全体集計インジケーター -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">👥 職員総数</div>
                <div class="summary-num" style="color:#005a9c;"><?= $total_staff ?></div>
                <div style="font-size:0.72rem; color:#888;">医療法人小野会</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">🟢 無事・出勤可</div>
                <div class="summary-num" style="color:#16a34a;"><?= $safe_count ?></div>
                <div style="font-size:0.72rem; color:#16a34a; font-weight:bold;"><?= $total_staff > 0 ? round(($safe_count/$total_staff)*100) : 0 ?>%</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">🟡 自宅待機</div>
                <div class="summary-num" style="color:#ea580c;"><?= $caution_count ?></div>
                <div style="font-size:0.72rem; color:#888;">要経過観察</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">🔴 被災・支援要</div>
                <div class="summary-num" style="color:#dc2626;"><?= $danger_count ?></div>
                <div style="font-size:0.72rem; color:#dc2626; font-weight:bold;">至急対応</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">⚪ 未確認・未報告</div>
                <div class="summary-num" style="color:#64748b;"><?= $unreported_count ?></div>
                <div style="font-size:0.72rem; color:#888;">点呼中</div>
            </div>
        </div>

        <!-- 確認進捗バー -->
        <div class="progress-container">
            <div class="progress-header">
                <span>📊 本日の生存確認率: <strong style="color:var(--primary); font-size:1rem;"><?= $report_rate ?>%</strong> (確認済: <?= $reported_count ?> / <?= $total_staff ?>名)</span>
                <span style="font-size:0.75rem; color:#888;">イベント: <?= htmlspecialchars($active_event['title'] ?? '通常運用') ?> (60秒毎に自動更新)</span>
            </div>
            <div class="progress-bar-outer">
                <div class="p-bar-safe" style="width: <?= ($safe_count / max(1, $total_staff)) * 100 ?>%;"></div>
                <div class="p-bar-caution" style="width: <?= ($caution_count / max(1, $total_staff)) * 100 ?>%;"></div>
                <div class="p-bar-danger" style="width: <?= ($danger_count / max(1, $total_staff)) * 100 ?>%;"></div>
            </div>
        </div>

        <!-- ツールバー（部署フィルタ ＆ 表示切替 ＆ 印刷） -->
        <div class="toolbar-section">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div class="dept-tabs">
                    <a href="safety_contacts.php" class="dept-tab <?= $selected_dept === 0 ? 'active' : '' ?>">全職員 (<?= $total_all_staff ?>名)</a>
                    <?php foreach ($departments as $d): ?>
                        <a href="safety_contacts.php?dept=<?= $d['dept_id'] ?>" class="dept-tab <?= $selected_dept === (int)$d['dept_id'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($d['dept_name']) ?> (<?= (int)$d['staff_count'] ?>名)
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- 📇 カード表示 ⇔ 📑 一覧表 表示切替 -->
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="btn-view-toggle active" id="btnViewCard" onclick="switchView('card')">
                        📇 カード表示
                    </button>
                    <button type="button" class="btn-view-toggle" id="btnViewTable" onclick="switchView('table')">
                        📑 一覧表表示
                    </button>
                </div>
            </div>
        </div>

        <!-- 📇 カード型表示（スマホ対応・押しやすいデカボタン！） -->
        <div id="cardViewWrap" class="staff-card-grid">
            <?php foreach ($staff_list as $st): 
                $rep_ts = $st['latest_reported_at'] ? strtotime($st['latest_reported_at']) : null;
                $is_today = ($rep_ts && $rep_ts >= $today_start_ts);
                $st_status = $is_today ? ($st['latest_status'] ?? 'unreported') : 'unreported';
                $has_line = !empty(trim($st['line_user_id'] ?? ''));

                $m_phone   = get_phone_info($st['phone_number'], $is_disaster_mode, 'mobile');
                $h_phone   = get_phone_info($st['home_phone'], $is_disaster_mode, 'home');
                $addr_info = get_address_info($st['address'], $st['postal_code'], $is_disaster_mode);
            ?>
                <div class="staff-contact-card status-<?= $st_status ?>">
                    <!-- カードヘッダー -->
                    <div class="scard-header">
                        <div class="scard-name-wrap">
                            <div class="scard-name"><?= htmlspecialchars($st['staff_name']) ?></div>
                            <?php if ($st['kana']): ?>
                                <div class="scard-kana"><?= htmlspecialchars($st['kana']) ?></div>
                            <?php endif; ?>
                            <span class="scard-role"><?= htmlspecialchars($st['dept_name'] ?? '未設定') ?> / <?= htmlspecialchars($st['role']) ?></span>
                            <?php if ($has_line): ?>
                                <button type="button" onclick="openSingleLineModal(<?= $st['staff_id'] ?>, '<?= htmlspecialchars($st['staff_name']) ?>')" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:12px; font-size:0.72rem; font-weight:bold; padding:2px 8px; cursor:pointer; display:inline-flex; align-items:center; gap:3px; margin-top:4px;" title="この職員のLINEへ個別連絡">
                                    💬 LINE送信
                                </button>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($st_status === 'safe'): ?>
                                <span class="status-badge safe">🟢 無事・出勤可</span>
                            <?php elseif ($st_status === 'caution'): ?>
                                <span class="status-badge caution">🟡 自宅待機</span>
                            <?php elseif ($st_status === 'danger'): ?>
                                <span class="status-badge danger">🔴 被災・支援要</span>
                            <?php else: ?>
                                <span class="status-badge unreported">⚪ 未報告</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 安否報告ブロック（ワンタップ直接報告） -->
                    <div class="scard-safety-box">
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#64748b;">
                            <span>⏱️ 1秒直接安否報告:</span>
                            <span><?= $rep_ts ? ($is_today ? date('H:i 確認', $rep_ts) : date('m/d H:i', $rep_ts)) : '未確認' ?></span>
                        </div>
                        <form method="POST" class="scard-punch-btns" onsubmit="return confirm('<?= htmlspecialchars($st['staff_name']) ?> さんの生存・安否を報告しますか？');">
                            <input type="hidden" name="action" value="report_safety">
                            <input type="hidden" name="staff_id" value="<?= $st['staff_id'] ?>">
                            <button type="submit" name="status" value="safe" class="btn-card-punch safe">🟢 無事</button>
                            <button type="submit" name="status" value="caution" class="btn-card-punch caution">🟡 待機</button>
                            <button type="submit" name="status" value="danger" class="btn-card-punch danger">🔴 被災</button>
                        </form>
                        <?php if ($is_today && !empty($st['latest_message'])): ?>
                            <div style="font-size:0.8rem; color:#1e293b; background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:6px 8px; margin-top:6px;">
                                💬 <?= htmlspecialchars($st['latest_message']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 連絡先ブロック -->
                    <div class="scard-contacts">
                        <!-- 内線 -->
                        <?php if (!empty($st['ext_number'])): ?>
                            <div style="font-size:0.84rem; color:#475569; font-weight:bold;">
                                📞 内線: <span style="font-family:'Outfit', monospace; color:#0f172a;"><?= htmlspecialchars($st['ext_number']) ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- 📱 携帯電話（災害時：デカ発信ボタン / 訓練時：嘘番号） -->
                        <div class="scard-field">
                            <div class="scard-field-label">
                                <span>📱 携帯電話番号</span>
                                <?php if (!$is_disaster_mode && $m_phone): ?><span class="tag-dummy">訓練嘘番号</span><?php endif; ?>
                            </div>
                            <?php if ($m_phone): ?>
                                <?php if ($is_disaster_mode): ?>
                                    <a href="<?= htmlspecialchars($m_phone['tel_link']) ?>" class="btn-call-card-mobile">
                                        📱 携帯へ電話: <?= htmlspecialchars($m_phone['display']) ?>
                                    </a>
                                <?php else: ?>
                                    <div class="card-dummy-phone" onclick="alertTrainingPhone()">
                                        <span>📱 <?= htmlspecialchars($m_phone['display']) ?></span>
                                        <span style="font-size:0.75rem; color:#94a3b8;">(訓練保護中)</span>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:0.8rem; color:#94a3b8;">(携帯未登録)</span>
                            <?php endif; ?>
                        </div>

                        <!-- 🏠 自宅固定電話（災害時：家に電話デカボタン！ / 訓練時：嘘番号） -->
                        <div class="scard-field">
                            <div class="scard-field-label">
                                <span>🏠 自宅固定電話（家の電話）</span>
                                <?php if (!$is_disaster_mode && $h_phone): ?><span class="tag-dummy">訓練嘘番号</span><?php endif; ?>
                            </div>
                            <?php if ($h_phone): ?>
                                <?php if ($is_disaster_mode): ?>
                                    <a href="<?= htmlspecialchars($h_phone['tel_link']) ?>" class="btn-call-card-home">
                                        🏠 家に電話: <?= htmlspecialchars($h_phone['display']) ?>
                                    </a>
                                <?php else: ?>
                                    <div class="card-dummy-phone" onclick="alertTrainingPhone()">
                                        <span>🏠 <?= htmlspecialchars($h_phone['display']) ?></span>
                                        <span style="font-size:0.75rem; color:#94a3b8;">(訓練保護中)</span>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:0.8rem; color:#94a3b8;">(固定電話なし)</span>
                            <?php endif; ?>
                        </div>

                        <!-- 📍 自宅住所（災害時：全開示＋地図 / 訓練時：マスク） -->
                        <div class="scard-field">
                            <div class="scard-field-label">
                                <span>📍 自宅住所</span>
                                <?php if ($is_disaster_mode): ?>
                                    <span style="color:#b91c1c; font-weight:900;">🚨全開示中</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">(訓練マスク)</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($addr_info): ?>
                                <?php if ($is_disaster_mode): ?>
                                    <div class="real-address"><?= htmlspecialchars($addr_info['display']) ?></div>
                                    <?php if ($addr_info['map_url']): ?>
                                        <a href="<?= htmlspecialchars($addr_info['map_url']) ?>" target="_blank" rel="noopener" class="btn-map-link" style="padding:6px 12px; font-size:0.82rem; margin-top:4px; display:inline-flex;">
                                            🗺️ Googleマップで開く（ルート確認）
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="masked-address"><?= htmlspecialchars($addr_info['display']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:0.8rem; color:#94a3b8;">(住所未登録)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- カードフッター -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:auto; padding-top:10px; border-top:1px solid #f1f5f9; font-size:0.78rem;">
                        <button type="button" class="line-badge <?= $has_line ? 'active' : 'inactive' ?>" onclick="openStaffLineModal(<?= (int)$st['staff_id'] ?>, '<?= htmlspecialchars(addslashes($st['staff_name'])) ?>', <?= $has_line ? 'true' : 'false' ?>)" title="クリックしてこの職員のLINE連携QR・4桁コードを発行・確認" style="border:none; cursor:pointer; font-family:inherit;">
                            LINE: <?= $has_line ? '🟢 登録済' : '📱 未登録' ?>
                        </button>
                        <button type="button" onclick='openEditContactModal(<?= json_encode($st) ?>)' style="background:#f1f5f9; border:1px solid #cbd5e1; padding:4px 10px; border-radius:4px; color:#005a9c; cursor:pointer; font-weight:bold; font-size:0.8rem;">
                            ✏️ 連絡先編集
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 📑 一覧表形式（PC・印刷用テーブルビュー：切り替え可能） -->
        <div id="tableViewWrap" class="table-card" style="display:none;">
            <table class="contact-table">
                <thead>
                    <tr>
                        <th style="width:125px;">氏名 (かな)</th>
                        <th style="width:100px;">所属・役職</th>
                        <th style="width:160px;">本日安否 / 1秒直接報告</th>
                        <th style="width:75px;">確認時刻</th>
                        <th>状況メモ・連絡事項</th>
                        <th style="width:65px;">内線</th>
                        <th style="width:130px;">📱 携帯電話</th>
                        <th style="width:145px;">🏠 自宅電話 (固定)</th>
                        <th style="width:200px;">📍 自宅住所</th>
                        <th style="width:45px;">LINE</th>
                        <th style="width:40px;">編集</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_list as $st): 
                        $rep_ts = $st['latest_reported_at'] ? strtotime($st['latest_reported_at']) : null;
                        $is_today = ($rep_ts && $rep_ts >= $today_start_ts);
                        $st_status = $is_today ? ($st['latest_status'] ?? 'unreported') : 'unreported';
                        $has_line = !empty(trim($st['line_user_id'] ?? ''));

                        $m_phone   = get_phone_info($st['phone_number'], $is_disaster_mode, 'mobile');
                        $h_phone   = get_phone_info($st['home_phone'], $is_disaster_mode, 'home');
                        $addr_info = get_address_info($st['address'], $st['postal_code'], $is_disaster_mode);
                    ?>
                        <tr>
                            <td>
                                <strong style="color:#1e293b; font-size:0.95rem;"><?= htmlspecialchars($st['staff_name']) ?></strong>
                                <?php if ($st['kana']): ?>
                                    <div style="font-size:0.68rem; color:#888;"><?= htmlspecialchars($st['kana']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size:0.8rem; color:#475569; font-weight:bold;"><?= htmlspecialchars($st['dept_name'] ?? '未設定') ?></span>
                                <div style="font-size:0.72rem; color:#64748b;"><?= htmlspecialchars($st['role']) ?></div>
                            </td>
                            <td>
                                <div style="display:flex; flex-direction:column; gap:4px;">
                                    <div>
                                        <?php if ($st_status === 'safe'): ?>
                                            <span class="status-badge safe">🟢 無事・出勤可</span>
                                        <?php elseif ($st_status === 'caution'): ?>
                                            <span class="status-badge caution">🟡 自宅待機</span>
                                        <?php elseif ($st_status === 'danger'): ?>
                                            <span class="status-badge danger">🔴 被災・支援要</span>
                                        <?php else: ?>
                                            <span class="status-badge unreported">⚪ 未報告</span>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" class="row-action-form" onsubmit="return confirm('<?= htmlspecialchars($st['staff_name']) ?> さんの生存・安否を報告しますか？');">
                                        <input type="hidden" name="action" value="report_safety">
                                        <input type="hidden" name="staff_id" value="<?= $st['staff_id'] ?>">
                                        <button type="submit" name="status" value="safe" class="btn-inline-punch safe" title="無事を報告">🟢無事</button>
                                        <button type="submit" name="status" value="caution" class="btn-inline-punch caution" title="待機を報告">🟡待機</button>
                                        <button type="submit" name="status" value="danger" class="btn-inline-punch danger" title="被災を報告">🔴被災</button>
                                    </form>
                                </div>
                            </td>
                            <td style="font-size:0.78rem; color:#64748b;">
                                <?php if ($rep_ts): ?>
                                    <?= $is_today ? date('H:i', $rep_ts) : date('m/d H:i', $rep_ts) ?>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem; color:#334155;">
                                <?= $is_today && $st['latest_message'] ? htmlspecialchars($st['latest_message']) : '<span style="color:#cbd5e1;">-</span>' ?>
                            </td>
                            <td>
                                <?= !empty($st['ext_number']) ? '📞 ' . htmlspecialchars($st['ext_number']) : '<span style="color:#cbd5e1;">-</span>' ?>
                            </td>
                            <td>
                                <?php if ($m_phone): ?>
                                    <?php if ($is_disaster_mode): ?>
                                        <a href="<?= htmlspecialchars($m_phone['tel_link']) ?>" class="btn-call-mobile">
                                            📱 <?= htmlspecialchars($m_phone['display']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="dummy-phone" onclick="alertTrainingPhone()">
                                            📱 <?= htmlspecialchars($m_phone['display']) ?> <span class="tag-dummy">嘘番号</span>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($h_phone): ?>
                                    <?php if ($is_disaster_mode): ?>
                                        <a href="<?= htmlspecialchars($h_phone['tel_link']) ?>" class="btn-call-home">
                                            🏠 家に電話: <?= htmlspecialchars($h_phone['display']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="dummy-phone" onclick="alertTrainingPhone()">
                                            🏠 <?= htmlspecialchars($h_phone['display']) ?> <span class="tag-dummy">嘘番号</span>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#cbd5e1; font-size:0.75rem;">(固定なし)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($addr_info): ?>
                                    <?php if ($is_disaster_mode): ?>
                                        <div class="real-address"><?= htmlspecialchars($addr_info['display']) ?></div>
                                        <?php if ($addr_info['map_url']): ?>
                                            <a href="<?= htmlspecialchars($addr_info['map_url']) ?>" target="_blank" rel="noopener" class="btn-map-link">
                                                🗺️ 地図・ルート
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="masked-address"><?= htmlspecialchars($addr_info['display']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#cbd5e1; font-size:0.75rem;">(未登録)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($has_line): ?>
                                    <button type="button" onclick="openSingleLineModal(<?= $st['staff_id'] ?>, '<?= htmlspecialchars($st['staff_name']) ?>')" class="line-badge active" style="cursor:pointer; border:none;" title="この職員へLINEメッセージ送信">
                                        💬 済
                                    </button>
                                <?php else: ?>
                                    <button type="button" onclick="openStaffLineModal(<?= (int)$st['staff_id'] ?>, '<?= htmlspecialchars(addslashes($st['staff_name'])) ?>', false)" class="line-badge inactive" style="cursor:pointer; border:none; font-family:inherit;" title="クリックしてこの職員のLINE連携QR・4桁コードを発行">📱 未</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" onclick='openEditContactModal(<?= json_encode($st) ?>)' style="background:none; border:none; color:#005a9c; cursor:pointer; font-size:0.85rem; font-weight:bold;">
                                    ✏️
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- 連絡先編集モーダル（携帯・自宅固定電話・住所に対応） -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <h3 style="font-size:1.05rem; color:var(--primary); margin-bottom:12px;" id="modalStaffTitle">連絡先情報の編集</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_contact">
                <input type="hidden" name="target_staff_id" id="m_staff_id">

                <div style="margin-bottom:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">内線番号</label>
                    <input type="text" name="ext_number" id="m_ext_number" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" placeholder="例: 101">
                </div>

                <div style="margin-bottom:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">📱 携帯電話番号（個人のスマホ・緊急連絡先）</label>
                    <input type="text" name="phone_number" id="m_phone_number" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" placeholder="例: 090-1234-5678">
                </div>

                <div style="margin-bottom:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">🏠 自宅固定電話番号（家の電話）</label>
                    <input type="text" name="home_phone" id="m_home_phone" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" placeholder="例: 088-822-7369 （なければ空欄）">
                </div>

                <div style="display:flex; gap:8px; margin-bottom:10px;">
                    <div style="width:120px;">
                        <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">郵便番号</label>
                        <input type="text" name="postal_code" id="m_postal_code" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" placeholder="780-0862">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">📍 自宅住所（災害時駆けつけ用）</label>
                        <input type="text" name="address" id="m_address" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" placeholder="高知市鷹匠町1丁目3-1...">
                    </div>
                </div>

                <div style="margin-bottom:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">LINE User ID（Uから始まる33桁）</label>
                    <input type="text" name="line_user_id" id="m_line_user_id" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" placeholder="例: U1234567890abcdef...">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                    <button type="button" onclick="closeContactModal()" style="background:#e2e8f0; border:none; padding:6px 14px; border-radius:4px; cursor:pointer;">キャンセル</button>
                    <button type="submit" style="background:var(--primary); color:#fff; border:none; padding:6px 16px; border-radius:4px; font-weight:bold; cursor:pointer;">保存する</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 点呼イベント発令モーダル（災害緊急モード選択対応） -->
    <div id="eventModal" class="modal">
        <div class="modal-content">
            <h3 style="font-size:1.05rem; color:#dc2626; margin-bottom:12px;">🚨 点呼・安否確認イベントの発令</h3>
            <p style="font-size:0.82rem; color:#666; margin-bottom:12px;">
                発令すると、全職員の安否が一旦「未確認」にリセットされ、新たな点呼集計が開始されます。
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="create_safety_event">
                
                <div style="margin-bottom:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">点呼タイトル</label>
                    <input type="text" name="event_title" class="select-large" style="font-size:0.9rem; padding:6px 10px; border-width:1px;" value="【緊急点呼】南海トラフ地震 安否確認訓練" required>
                </div>

                <div style="margin-bottom:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px;">説明・指示事項</label>
                    <textarea name="event_desc" class="select-large" style="font-size:0.85rem; padding:6px 10px; border-width:1px; height:60px;" placeholder="全職員は速やかに生存状況と出勤可否を報告してください。"></textarea>
                </div>

                <div style="margin-bottom:14px; background:#f8fafc; border:1.5px solid #cbd5e1; padding:10px; border-radius:6px;">
                    <label style="display:block; font-weight:bold; font-size:0.84rem; margin-bottom:6px; color:#1e293b;">モード種別の選択</label>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; font-weight:bold; color:#b45309;">
                            <input type="radio" name="event_safety_mode" value="drill" checked style="width:16px; height:16px;">
                            🛡️ 訓練モード（かわら版に安否報告バナーを表示、個人情報は保護）
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; font-weight:bold; color:#dc2626;">
                            <input type="radio" name="event_safety_mode" value="disaster" style="width:16px; height:16px;">
                            🚨 【本物の災害時】緊急モード（全個人情報を即座に開示）
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; font-weight:bold; color:#166534;">
                            <input type="radio" name="event_safety_mode" value="normal" style="width:16px; height:16px;">
                            🌿 平常モード（日常点呼、かわら版の催促バナーは非表示）
                        </label>
                    </div>
                </div>

                <div style="margin-bottom:14px; background:#f0fdf4; border:1.5px solid #bbf7d0; padding:10px; border-radius:6px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:bold; color:#15803d; font-size:0.88rem;">
                        <input type="checkbox" name="send_line_broadcast" value="1" checked style="width:18px; height:18px;">
                        💬 LINE連携済みの全職員へ安否確認メッセージを一斉送信する
                    </label>
                    <div style="font-size:0.75rem; color:#166534; margin-top:4px; margin-left:26px;">
                        ※ 点呼発令と同時に、公式LINEから職員のスマホへ報告URL付きの安否確認が届きます。
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                    <button type="button" onclick="closeEventModal()" style="background:#e2e8f0; border:none; padding:6px 14px; border-radius:4px; cursor:pointer;">キャンセル</button>
                    <button type="submit" style="background:#dc2626; color:#fff; border:none; padding:6px 16px; border-radius:4px; font-weight:bold; cursor:pointer;">発令する</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 💬 LINEメッセージ送信モーダル -->
    <div id="lineSendModal" class="modal">
        <div class="modal-content" style="max-width:540px; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
                <h3 style="margin:0; font-size:1.15rem; color:#15803d; display:flex; align-items:center; gap:6px;">
                    💬 LINE安否連絡の送信
                </h3>
                <button type="button" onclick="closeLineSendModal()" style="background:none; border:none; font-size:1.3rem; cursor:pointer; color:#94a3b8;">✕</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="send_line_message">
                
                <div style="margin-bottom:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:6px; color:#334155;">宛先・送信対象</label>
                    <div style="display:flex; gap:16px; margin-bottom:6px;">
                        <label style="display:flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer; font-weight:bold; color:#1e293b;">
                            <input type="radio" name="target_type" value="all" id="targetTypeAll" checked onchange="toggleLineTarget()">
                            <span>全連携職員（一括送信）</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer; font-weight:bold; color:#1e293b;">
                            <input type="radio" name="target_type" value="single" id="targetTypeSingle" onchange="toggleLineTarget()">
                            <span>特定の職員（個別指定）</span>
                        </label>
                    </div>
                    <div id="singleTargetWrap" style="display:none; margin-top:8px;">
                        <select name="single_staff_id" id="singleStaffSelect" class="select-large" style="font-size:0.85rem; padding:6px 10px; width:100%;">
                            <?php foreach ($staff_list as $st): ?>
                                <?php if (!empty(trim($st['line_user_id'] ?? ''))): ?>
                                    <option value="<?= $st['staff_id'] ?>">
                                        <?= htmlspecialchars($st['staff_name']) ?> 様 (<?= htmlspecialchars($st['role']) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:6px; color:#334155;">文面テンプレート（クリックで入力）</label>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button type="button" class="dept-tab" onclick="applyLineTemplate('safety')" style="cursor:pointer; background:#f0fdf4; border-color:#86efac; color:#166534;">🚨 安否確認・出勤可否</button>
                        <button type="button" class="dept-tab" onclick="applyLineTemplate('drill')" style="cursor:pointer; background:#eff6ff; border-color:#93c5fd; color:#1e40af;">🛡️ 点呼訓練テスト</button>
                        <button type="button" class="dept-tab" onclick="applyLineTemplate('urgent')" style="cursor:pointer; background:#fffbeb; border-color:#fde68a; color:#92400e;">⚠️ 緊急招集・連絡</button>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px; color:#334155;">メッセージ本文</label>
                    <textarea name="line_message" id="lineMessageText" class="select-large" style="font-size:0.88rem; padding:10px; height:120px; line-height:1.45; width:100%; border:1px solid #cbd5e1; border-radius:6px;" required placeholder="送信するメッセージ内容を入力してください"></textarea>
                    <div style="font-size:0.72rem; color:#64748b; margin-top:4px;">
                        ※ 公式LINE「おの肛門科」のトーク画面にメッセージが即座に配信されます。
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                    <button type="button" onclick="closeLineSendModal()" style="background:#e2e8f0; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">キャンセル</button>
                    <button type="submit" style="background:#06c755; color:#fff; border:none; padding:8px 22px; border-radius:6px; font-weight:bold; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                        💬 LINEへ送信する
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 🌐 LINE連携トンネル状況モーダル -->
    <div id="tunnelStatusModal" class="modal">
        <div class="modal-content" style="max-width:540px; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
                <h3 style="margin:0; font-size:1.15rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    🌐 LINE新規連携トンネルの接続状況
                </h3>
                <button type="button" onclick="closeTunnelStatusModal()" style="background:none; border:none; font-size:1.3rem; cursor:pointer; color:#94a3b8;">✕</button>
            </div>

            <div style="padding:4px 0 12px 0;">
                <?php if ($tunnel_status['is_online']): ?>
                    <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:14px; margin-bottom:14px;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                            <span class="pulse-dot online"></span>
                            <strong style="color:#065f46; font-size:1.05rem;">🟢 現在トンネルは「稼働中（ONLINE）」です</strong>
                        </div>
                        <p style="font-size:0.88rem; color:#047857; margin:0; line-height:1.6;">
                            スタッフがLINE公式アカウント「おの肛門科」に<strong>4桁連携コードを送信すると、即座に自動紐付け</strong>されます。<br>
                            ※ 新規登録テストやスタッフ登録会をそのまま実施いただけます。
                        </p>
                    </div>

                    <?php if (!empty($tunnel_status['webhook_url'])): ?>
                        <div style="margin-bottom:14px;">
                            <label style="display:block; font-weight:bold; font-size:0.82rem; margin-bottom:4px; color:#334155;">現在の Webhook URL（LINE Developers登録用）</label>
                            <div style="display:flex; gap:6px;">
                                <input type="text" id="tunnelWebhookUrlInput" readonly value="<?= htmlspecialchars($tunnel_status['webhook_url']) ?>" style="flex:1; font-size:0.82rem; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc; font-family:monospace; color:#0f172a;">
                                <button type="button" onclick="copyTunnelWebhookUrl()" style="background:#0284c7; color:#fff; border:none; padding:8px 14px; border-radius:6px; font-weight:bold; font-size:0.82rem; cursor:pointer; white-space:nowrap;">📋 コピー</button>
                            </div>
                            <div id="copySuccessMsg" style="display:none; font-size:0.75rem; color:#16a34a; font-weight:bold; margin-top:4px;">✓ クリップボードにコピーしました！</div>
                            <div style="font-size:0.75rem; color:#64748b; margin-top:6px; line-height:1.5;">
                                ※ LINE Developersの「Messaging API設定」→「Webhook URL」に上記を貼り付けて「検証」してください。
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:14px;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                            <span class="pulse-dot offline"></span>
                            <strong style="color:#475569; font-size:1.05rem;">⚪ 現在トンネルは「停止中（OFFLINE）」です</strong>
                        </div>
                        <p style="font-size:0.88rem; color:#475569; margin:0; line-height:1.6;">
                            外部からのWebhook受信（4桁コードの新規自動紐付け）は現在停止しています。
                        </p>
                    </div>

                    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; margin-bottom:14px; font-size:0.86rem; color:#1e40af; line-height:1.6;">
                        💡 <strong>重要（メッセージ送信は可能）:</strong><br>
                        すでに連携済みのスタッフ（山本 太 様など）への安否連絡送信は、トンネル停止中でも<strong>何の問題もなく通常通り配信可能</strong>です。新規スタッフの登録を行う日のみトンネルを起動してください。
                    </div>
                <?php endif; ?>

                <!-- 📱 スタッフ4桁コード発行への直通ナビゲーション -->
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px; margin-bottom:14px;">
                    <div style="font-weight:bold; font-size:0.88rem; color:#166534; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                        <span>📱 職員のスマホを今すぐLINE連携する</span>
                    </div>
                    <div style="font-size:0.8rem; color:#15803d; margin-bottom:10px;">
                        一覧の各職員の<strong>「📱 LINE未登録」</strong>ボタンをクリックするか、下の職員選択から直接QRコードと4桁コードを発行できます。
                    </div>
                    <div style="display:flex; gap:8px;">
                        <select id="quickStaffSelect" style="flex:1; font-size:0.85rem; padding:7px; border:1px solid #86efac; border-radius:6px; background:#fff;">
                            <option value="">-- LINE連携する職員を選択 --</option>
                            <?php foreach ($all_staff as $st): ?>
                                <?php $is_l = !empty(trim($st['line_user_id'] ?? '')); ?>
                                <option value="<?= $st['staff_id'] ?>" data-name="<?= htmlspecialchars($st['staff_name']) ?>" data-linked="<?= $is_l ? '1' : '0' ?>">
                                    <?= $is_l ? '🟢' : '⚪' ?> <?= htmlspecialchars($st['staff_name']) ?> 様 (<?= htmlspecialchars($st['role']) ?>) <?= $is_l ? '【連携済】' : '【未連携】' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="startQuickStaffLineLink()" style="background:#06c755; color:#fff; border:none; padding:7px 16px; border-radius:6px; font-weight:bold; font-size:0.82rem; cursor:pointer; white-space:nowrap;">
                            👉 コード発行
                        </button>
                    </div>
                </div>

                <div style="background:#f1f5f9; border-radius:6px; padding:10px 12px; font-size:0.78rem; color:#475569;">
                    <div><strong>判定時刻:</strong> <?= htmlspecialchars($tunnel_status['checked_at']) ?> （画面再読み込みで最新状態を再判定）</div>
                    <div style="margin-top:2px;"><strong>判定方式:</strong> ローカルメトリクスポート（127.0.0.1:20241）超高速ヘルスチェック</div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px; border-top:1px solid #f1f5f9; padding-top:10px;">
                <button type="button" onclick="closeTunnelStatusModal()" style="background:#e2e8f0; border:none; padding:8px 18px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:0.88rem;">閉じる</button>
            </div>
        </div>
    </div>

    <!-- 📱 スタッフ専用 LINE連携QR ＆ 4桁コード発行モーダル -->
    <div id="staffLineLinkModal" class="line-modal-overlay" onclick="if(event.target===this) closeStaffLineModal();">
        <div class="line-modal-card">
            <div class="line-modal-header">
                <h3>📱 公式LINE 連携設定</h3>
                <button type="button" class="line-modal-close" onclick="closeStaffLineModal()">&times;</button>
            </div>
            
            <div class="line-modal-body">
                <!-- 対象スタッフ表示 -->
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px;">
                    <div>
                        <div style="font-size:0.75rem; color:#64748b; font-weight:bold;">対象職員</div>
                        <div style="font-size:1.15rem; font-weight:900; color:#0f172a;" id="sLinkStaffName">-</div>
                    </div>
                    <div id="sLinkStatusBadge" style="font-size:0.78rem; font-weight:bold; padding:4px 10px; border-radius:12px; background:#f1f5f9; color:#64748b;">
                        状態確認中...
                    </div>
                </div>

                <!-- 未連携時のステップ案内 -->
                <div id="sLinkStepArea">
                    <!-- Step 1: 友だち追加 -->
                    <div class="line-step-box">
                        <span class="line-step-num">Step 1</span>
                        <span class="line-step-title">公式LINEを友だち追加</span>
                        <div style="display:flex; align-items:center; gap:16px; margin-top:8px;">
                            <img id="sLineQrImg" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https%3A%2F%2Fline.me%2FR%2Fti%2Fp%2F%40tmw3446q" alt="LINE友だち追加QR" style="width:90px; height:90px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; padding:4px;">
                            <div style="font-size:0.82rem; color:#475569; line-height:1.5;">
                                対象スタッフのスマホの<strong>LINEカメラでこのQRを読み取り</strong>、「おの肛門科」を友だち追加してください。<br>
                                <a id="sBtnAddFriend" href="https://line.me/R/ti/p/%40tmw3446q" target="_blank" rel="noopener" style="color:#06c755; font-weight:bold; text-decoration:none; display:inline-block; margin-top:4px;">
                                    💬 スマホから直接追加リンク →
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: 4桁コード送信 -->
                    <div class="line-step-box">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <span class="line-step-num">Step 2</span>
                                <span class="line-step-title">トーク画面でこの4桁コードを送信</span>
                            </div>
                            <button type="button" class="btn-copy-code" onclick="copyStaffLinkCode()">📋 コピー</button>
                        </div>

                        <div class="link-code-digit" id="sLinkCodeDigit">----</div>

                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.78rem; color:#64748b;">
                            <span>有効期限: <strong id="sLinkCountdown" style="color:#dc2626;">--分--秒</strong></span>
                            <button type="button" onclick="regenerateStaffLinkCode()" style="background:none; border:none; color:#0284c7; text-decoration:underline; cursor:pointer; font-size:0.78rem;">
                                🔄 再発行
                            </button>
                        </div>

                        <div class="line-waiting-box">
                            <div class="line-spinner"></div>
                            <span>スマホからの送信を待機中...（送信されると自動で完了します）</span>
                        </div>
                    </div>

                    <!-- テスト用シミュレーション -->
                    <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #cbd5e1; text-align:center;">
                        <button type="button" onclick="simulateStaffLineLink()" style="background:#f8fafc; border:1px solid #cbd5e1; color:#64748b; font-size:0.75rem; padding:4px 10px; border-radius:4px; cursor:pointer;">
                            ⚡ スマホがない場合の動作確認用（模擬LINE IDで即時テスト連携）
                        </button>
                    </div>
                </div>

                <!-- 連携済み時の表示エリア -->
                <div id="sLinkCompleteArea" style="display:none; text-align:center; padding:16px 0;">
                    <div style="font-size:3rem; margin-bottom:8px;">🎉</div>
                    <h4 style="margin:0 0 6px 0; color:#065f46; font-size:1.15rem;">LINE公式アカウントと連携完了！</h4>
                    <p style="font-size:0.85rem; color:#475569; margin:0 0 14px 0; line-height:1.6;">
                        有事のBCP安否確認や一斉点呼メッセージが、<br>
                        このスタッフのLINEへ確実に届くようになりました。
                    </p>
                    <div style="background:#f1f5f9; border-radius:8px; padding:10px; font-size:0.8rem; color:#334155; margin-bottom:16px; word-break:break-all;">
                        <strong>登録LINE User ID:</strong> <span id="sLinkMaskedId" style="font-family:monospace;">-</span>
                    </div>
                    <div style="display:flex; justify-content:center; gap:10px;">
                        <button type="button" onclick="openSingleLineModalFromLink()" style="background:#06c755; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; font-size:0.85rem; cursor:pointer;">
                            💬 テストメッセージを送信してみる
                        </button>
                        <button type="button" onclick="unlinkStaffLineDirect()" style="background:#fef2f2; border:1px solid #fecdd3; color:#dc2626; padding:8px 14px; border-radius:6px; font-size:0.82rem; cursor:pointer;">
                            連携解除
                        </button>
                    </div>
                </div>

            </div>

            <div style="background:#f8fafc; padding:12px 18px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
                <button type="button" onclick="closeStaffLineModal()" style="background:#e2e8f0; border:none; padding:8px 18px; border-radius:6px; font-weight:bold; font-size:0.85rem; cursor:pointer; color:#334155;">閉じる</button>
            </div>
        </div>
    </div>

    <!-- 📱 災害モード切替用 数字キータッチパッドモーダル（医師・管理者 認証） -->
    <div id="disasterPinModal" class="pin-modal-overlay">
        <div class="pin-card">
            <div class="pin-card-header">
                <div class="pin-title" id="dPinTitle">🚨 災害時緊急モード発令</div>
                <div class="pin-subtitle">🔒 権限者の暗証番号が必要です</div>
                <div class="pin-desc">
                    災害モードの変更は、医師または管理者（山本・後任事務長）のみ許可されています。<br>
                    下の数字キーで暗証番号を入力してください。
                </div>
            </div>

            <!-- 入力ディスプレイ -->
            <div class="pin-display-wrap">
                <div class="pin-dots" id="dPinDots"></div>
                <div class="pin-placeholder" id="dPinPlaceholder">数字キーを押してください</div>
            </div>

            <!-- テンキーパッド -->
            <div class="pin-keypad">
                <button type="button" class="pin-key" onclick="pressDisasterKey('1')">1</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('2')">2</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('3')">3</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('4')">4</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('5')">5</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('6')">6</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('7')">7</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('8')">8</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('9')">9</button>
                <button type="button" class="pin-key btn-clear" onclick="clearDisasterPin()" title="全消去">C</button>
                <button type="button" class="pin-key" onclick="pressDisasterKey('0')">0</button>
                <button type="button" class="pin-key btn-backspace" onclick="backspaceDisasterPin()" title="1文字消去">⌫</button>
            </div>

            <button type="button" class="btn-pin-submit" id="btnDisasterSubmit" onclick="submitDisasterPin()">
                認証して実行 ⏎
            </button>
            <button type="button" class="btn-pin-cancel" onclick="closeDisasterPinModal()">
                ✕ キャンセル
            </button>
        </div>
    </div>

    <script>
    // 📇 表示モード切替（カード表示 ⇔ 一覧表表示）
    function switchView(mode) {
        const cardWrap = document.getElementById('cardViewWrap');
        const tableWrap = document.getElementById('tableViewWrap');
        const btnCard = document.getElementById('btnViewCard');
        const btnTable = document.getElementById('btnViewTable');

        if (!cardWrap || !tableWrap) return;

        if (mode === 'table') {
            cardWrap.style.display = 'none';
            tableWrap.style.display = 'block';
            if (btnCard) btnCard.classList.remove('active');
            if (btnTable) btnTable.classList.add('active');
            try { localStorage.setItem('kawara_safety_view', 'table'); } catch(e){}
        } else {
            cardWrap.style.display = 'grid';
            tableWrap.style.display = 'none';
            if (btnCard) btnCard.classList.add('active');
            if (btnTable) btnTable.classList.remove('active');
            try { localStorage.setItem('kawara_safety_view', 'card'); } catch(e){}
        }
    }

    // 起動時に前回の選択またはスマホ最適のデフォルト（カード型）を適用
    document.addEventListener('DOMContentLoaded', function() {
        let savedView = 'card';
        try {
            savedView = localStorage.getItem('kawara_safety_view') || 'card';
        } catch(e){}
        switchView(savedView);
    });

    function validatePunchForm(form) {
        const sel = document.getElementById('quickStaffSelect');
        if (!sel.value) {
            alert('お名前を選択してください！');
            sel.focus();
            return false;
        }
        return true;
    }

    function alertTrainingPhone() {
        alert("🛡️ 現在は【訓練・平時モード】です。\n\n個人情報保護のため、電話番号は誤架電防止用の【嘘電話番号（ダミー）】を表示しており、架電できません。\n\n本当の災害時には、画面上部の「🚨 災害時緊急モード発令」を押すことで、全職員の本物の携帯・自宅固定電話番号・住所が開示され、【家に電話】ができるようになります。");
    }

    let pendingTargetMode = 'normal';
    let disasterPin = '';

    function handleToggleDisasterMode(targetMode, isAuthorized) {
        pendingTargetMode = targetMode;
        if (isAuthorized) {
            let confirmMsg = '';
            if (targetMode === 'disaster' || targetMode === 1) {
                confirmMsg = '🚨【緊急確認】本当に災害が発生しましたか？\n\n発令すると全職員の【自宅固定電話・住所・携帯電話】が完全開示され、かわら版にも緊急安否確認バナーが表示されます。';
            } else if (targetMode === 'drill') {
                confirmMsg = '🛡️【確認】訓練モードを開始しますか？\n\nかわら版トップに安否確認訓練の報告バナーが表示されます（個人情報は保護されます）。';
            } else {
                confirmMsg = '🌿【確認】平常モードに戻しますか？\n\n個人情報保護状態を維持し、かわら版の安否確認催促バナーが非表示になります。';
            }
            if (confirm(confirmMsg)) {
                document.getElementById('toggleTargetMode').value = targetMode;
                document.getElementById('toggleAuthPin').value = '';
                document.getElementById('toggleDisasterForm').submit();
            }
        } else {
            openDisasterPinModal(targetMode);
        }
    }

    function openDisasterPinModal(targetMode) {
        pendingTargetMode = targetMode;
        disasterPin = '';
        updateDisasterPinDisplay();

        const titleEl = document.getElementById('dPinTitle');
        const submitBtn = document.getElementById('btnDisasterSubmit');
        if (targetMode === 'disaster' || targetMode === 1) {
            titleEl.innerHTML = '🚨 災害時緊急モード発令';
            submitBtn.textContent = '🚨 発令する（全開示） ⏎';
            submitBtn.style.background = '#dc2626';
        } else if (targetMode === 'drill') {
            titleEl.innerHTML = '🛡️ 訓練モード切替';
            submitBtn.textContent = '🛡️ 訓練モードへ切替 ⏎';
            submitBtn.style.background = '#f59e0b';
        } else {
            titleEl.innerHTML = '🌿 平常モード復帰';
            submitBtn.textContent = '🌿 平常モードに戻す ⏎';
            submitBtn.style.background = '#16a34a';
        }
        document.getElementById('disasterPinModal').classList.add('active');
    }

    function closeDisasterPinModal() {
        document.getElementById('disasterPinModal').classList.remove('active');
        disasterPin = '';
    }

    function pressDisasterKey(num) {
        if (disasterPin.length >= 10) return;
        disasterPin += num;
        updateDisasterPinDisplay();
    }

    function backspaceDisasterPin() {
        if (disasterPin.length > 0) {
            disasterPin = disasterPin.slice(0, -1);
            updateDisasterPinDisplay();
        }
    }

    function clearDisasterPin() {
        disasterPin = '';
        updateDisasterPinDisplay();
    }

    function updateDisasterPinDisplay() {
        const dotsEl = document.getElementById('dPinDots');
        const placeholderEl = document.getElementById('dPinPlaceholder');
        if (disasterPin.length === 0) {
            dotsEl.textContent = '';
            placeholderEl.style.display = 'block';
        } else {
            placeholderEl.style.display = 'none';
            dotsEl.textContent = '●'.repeat(disasterPin.length);
        }
    }

    function submitDisasterPin() {
        if (disasterPin.length === 0) {
            alert('数字キーを押して暗証番号を入力してください！');
            return;
        }
        document.getElementById('toggleTargetMode').value = pendingTargetMode;
        document.getElementById('toggleAuthPin').value = disasterPin;
        document.getElementById('toggleDisasterForm').submit();
    }

    // 物理キーボード連動
    window.addEventListener('keydown', function(e) {
        const modal = document.getElementById('disasterPinModal');
        if (!modal || !modal.classList.contains('active')) return;

        if (e.key >= '0' && e.key <= '9') {
            pressDisasterKey(e.key);
        } else if (e.key === 'Backspace') {
            backspaceDisasterPin();
        } else if (e.key === 'Enter') {
            submitDisasterPin();
        } else if (e.key === 'Escape') {
            closeDisasterPinModal();
        }
    });

    function openEditContactModal(st) {
        document.getElementById('modalStaffTitle').textContent = st.staff_name + ' さんの連絡先編集';
        document.getElementById('m_staff_id').value = st.staff_id;
        document.getElementById('m_ext_number').value = st.ext_number || '';
        document.getElementById('m_phone_number').value = st.phone_number || '';
        document.getElementById('m_home_phone').value = st.home_phone || '';
        document.getElementById('m_postal_code').value = st.postal_code || '';
        document.getElementById('m_address').value = st.address || '';
        document.getElementById('m_line_user_id').value = st.line_user_id || '';
        document.getElementById('contactModal').classList.add('active');
    }
    function closeContactModal() {
        document.getElementById('contactModal').classList.remove('active');
    }

    function openEventModal() {
        document.getElementById('eventModal').classList.add('active');
    }
    function closeEventModal() {
        document.getElementById('eventModal').classList.remove('active');
    }

    function openLineSendModal() {
        document.getElementById('targetTypeAll').checked = true;
        toggleLineTarget();
        applyLineTemplate('safety');
        document.getElementById('lineSendModal').classList.add('active');
    }
    function closeLineSendModal() {
        document.getElementById('lineSendModal').classList.remove('active');
    }
    function openSingleLineModal(staffId, staffName) {
        document.getElementById('targetTypeSingle').checked = true;
        toggleLineTarget();
        const sel = document.getElementById('singleStaffSelect');
        if (sel) sel.value = staffId;
        const safetyUrl = window.location.origin + '/kawara/safety_contacts.php';
        document.getElementById('lineMessageText').value = "🚨 【医療法人小野会 安否確認】\n" + staffName + " 様、ご自身の安全と出勤可否について以下のURLより報告をお願いします：\n" + safetyUrl;
        document.getElementById('lineSendModal').classList.add('active');
    }
    function toggleLineTarget() {
        const isSingle = document.getElementById('targetTypeSingle').checked;
        document.getElementById('singleTargetWrap').style.display = isSingle ? 'block' : 'none';
    }
    function applyLineTemplate(type) {
        const txt = document.getElementById('lineMessageText');
        const safetyUrl = window.location.origin + '/kawara/safety_contacts.php';
        if (type === 'safety') {
            txt.value = "🚨 【医療法人小野会 BCP安否確認】\n職員の皆様は速やかに以下のリンクを開き、安否・出勤状況をご報告ください：\n" + safetyUrl;
        } else if (type === 'drill') {
            txt.value = "🛡️ 【医療法人小野会 安否確認訓練テスト】\n本日は避難・安否点呼の訓練日です。下記リンクより1秒生存報告の動作確認をお願いします：\n" + safetyUrl;
        } else if (type === 'urgent') {
            txt.value = "⚠️ 【医療法人小野会 緊急招集・連絡】\n急遽伝達事項があります。職員連絡網を確認し、指定の部署または対策本部へ出勤・連絡をお願いします：\n" + safetyUrl;
        }
    }

    function openTunnelStatusModal() {
        document.getElementById('tunnelStatusModal').classList.add('active');
    }
    function closeTunnelStatusModal() {
        document.getElementById('tunnelStatusModal').classList.remove('active');
        const msg = document.getElementById('copySuccessMsg');
        if (msg) msg.style.display = 'none';
    }
    function copyTunnelWebhookUrl() {
        const input = document.getElementById('tunnelWebhookUrlInput');
        if (input) {
            input.select();
            input.setSelectionRange(0, 99999);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(() => {
                    const msg = document.getElementById('copySuccessMsg');
                    if (msg) msg.style.display = 'block';
                }).catch(() => {
                    document.execCommand('copy');
                    const msg = document.getElementById('copySuccessMsg');
                    if (msg) msg.style.display = 'block';
                });
            } else {
                document.execCommand('copy');
                const msg = document.getElementById('copySuccessMsg');
                if (msg) msg.style.display = 'block';
            }
        }
    }

    // 📱 スタッフ専用LINE連携モーダル制御
    let currentLinkingStaffId = 0;
    let currentLinkingStaffName = '';
    let staffPollingTimer = null;
    let staffCountdownTimer = null;
    let staffRemainingSec = 0;

    function openStaffLineModal(staffId, staffName, isLinked) {
        currentLinkingStaffId = staffId;
        currentLinkingStaffName = staffName;
        document.getElementById('sLinkStaffName').textContent = staffName + ' 様';
        document.getElementById('staffLineLinkModal').classList.add('active');
        refreshStaffLineStatus(true);
    }

    function closeStaffLineModal() {
        document.getElementById('staffLineLinkModal').classList.remove('active');
        stopStaffPolling();
        stopStaffCountdown();
    }

    async function refreshStaffLineStatus(autoGenerate = false) {
        if (!currentLinkingStaffId) return;
        try {
            const res = await fetch('api/line_link_status.php?action=status&staff_id=' + currentLinkingStaffId, { cache: 'no-cache' });
            const data = await res.json();
            if (!data.success) {
                alert('ステータス取得エラー: ' + (data.error || '不明なエラー'));
                return;
            }

            const badge = document.getElementById('sLinkStatusBadge');
            const stepArea = document.getElementById('sLinkStepArea');
            const compArea = document.getElementById('sLinkCompleteArea');

            if (data.is_linked) {
                badge.style.background = '#dcfce7';
                badge.style.color = '#15803d';
                badge.textContent = '🟢 連携完了';
                stepArea.style.display = 'none';
                compArea.style.display = 'block';
                document.getElementById('sLinkMaskedId').textContent = data.line_user_id_mask || data.raw_line_user_id;
                stopStaffPolling();
                stopStaffCountdown();
            } else {
                badge.style.background = '#fee2e2';
                badge.style.color = '#b91c1c';
                badge.textContent = '📱 未連携';
                stepArea.style.display = 'block';
                compArea.style.display = 'none';

                if (data.active_code && data.remaining_sec > 0) {
                    setStaffLinkCode(data.active_code, data.remaining_sec);
                    startStaffPolling();
                } else if (autoGenerate) {
                    generateNewStaffLinkCode();
                }
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function generateNewStaffLinkCode() {
        if (!currentLinkingStaffId) return;
        try {
            const res = await fetch('api/line_link_status.php?action=generate_code&staff_id=' + currentLinkingStaffId, { cache: 'no-cache' });
            const data = await res.json();
            if (data.success && data.link_code) {
                setStaffLinkCode(data.link_code, data.remaining_sec || 1200);
                startStaffPolling();
            } else {
                alert('連携コードの発行に失敗しました。');
            }
        } catch (e) {
            console.error(e);
        }
    }

    function regenerateStaffLinkCode() {
        generateNewStaffLinkCode();
    }

    function setStaffLinkCode(code, sec) {
        document.getElementById('sLinkCodeDigit').textContent = code;
        staffRemainingSec = sec;
        startStaffCountdown();
    }

    function startStaffCountdown() {
        stopStaffCountdown();
        updateStaffCountdownText();
        staffCountdownTimer = setInterval(() => {
            staffRemainingSec--;
            if (staffRemainingSec <= 0) {
                stopStaffCountdown();
                document.getElementById('sLinkCodeDigit').textContent = '期限切れ';
                document.getElementById('sLinkCountdown').textContent = '0分0秒';
                return;
            }
            updateStaffCountdownText();
        }, 1000);
    }

    function updateStaffCountdownText() {
        const m = Math.floor(staffRemainingSec / 60);
        const s = staffRemainingSec % 60;
        document.getElementById('sLinkCountdown').textContent = m + '分' + (s < 10 ? '0' : '') + s + '秒';
    }

    function stopStaffCountdown() {
        if (staffCountdownTimer) clearInterval(staffCountdownTimer);
        staffCountdownTimer = null;
    }

    function startStaffPolling() {
        stopStaffPolling();
        staffPollingTimer = setInterval(async () => {
            if (!currentLinkingStaffId) return;
            try {
                const res = await fetch('api/line_link_status.php?action=status&staff_id=' + currentLinkingStaffId, { cache: 'no-cache' });
                const data = await res.json();
                if (data.success && data.is_linked) {
                    refreshStaffLineStatus(false);
                }
            } catch (e) {}
        }, 2500); // 2.5秒ごとに自動チェック
    }

    function stopStaffPolling() {
        if (staffPollingTimer) clearInterval(staffPollingTimer);
        staffPollingTimer = null;
    }

    function copyStaffLinkCode() {
        const code = document.getElementById('sLinkCodeDigit').textContent.trim();
        if (code && code !== '----' && code !== '期限切れ') {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(() => {
                    alert('連携コード【' + code + '】をコピーしました！');
                }).catch(() => {
                    alert('連携コード: ' + code);
                });
            } else {
                alert('連携コード: ' + code);
            }
        }
    }

    async function simulateStaffLineLink() {
        if (!currentLinkingStaffId) return;
        if (!confirm(currentLinkingStaffName + ' 様をテスト用模擬LINE IDで即座に連携しますか？\n（PC上での動作確認テストを行えます）')) return;
        try {
            const fd = new FormData();
            fd.append('action', 'manual_link');
            fd.append('staff_id', currentLinkingStaffId);
            const res = await fetch('api/line_link_status.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                refreshStaffLineStatus(false);
            } else {
                alert('登録エラー: ' + (data.error || ''));
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function unlinkStaffLineDirect() {
        if (!currentLinkingStaffId) return;
        if (!confirm(currentLinkingStaffName + ' 様のLINE連携を解除しますか？')) return;
        try {
            const res = await fetch('api/line_link_status.php?action=unlink&staff_id=' + currentLinkingStaffId, { cache: 'no-cache' });
            const data = await res.json();
            if (data.success) {
                refreshStaffLineStatus(true);
            }
        } catch (e) {
            console.error(e);
        }
    }

    function openSingleLineModalFromLink() {
        closeStaffLineModal();
        openSingleLineModal(currentLinkingStaffId, currentLinkingStaffName);
    }

    function startQuickStaffLineLink() {
        const sel = document.getElementById('quickStaffSelect');
        if (!sel || !sel.value) {
            alert('連携コードを発行する職員を選択してください。');
            return;
        }
        const opt = sel.options[sel.selectedIndex];
        const sId = parseInt(sel.value);
        const sName = opt.getAttribute('data-name');
        const isL = opt.getAttribute('data-linked') === '1';
        closeTunnelStatusModal();
        openStaffLineModal(sId, sName, isL);
    }
    </script>
</body>
</html>
