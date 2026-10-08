<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 権限チェック
if (!isset($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未ログインです']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$action = $_POST['action'] ?? '';

if ($action === 'dispatch_print') {
    $printer_ids = $_POST['printer_ids'] ?? [];
    if (!is_array($printer_ids)) {
        $printer_ids = array_filter(array_map('intval', explode(',', (string)$printer_ids)));
    } else {
        $printer_ids = array_filter(array_map('intval', $printer_ids));
    }

    $dept_ids = $_POST['dept_ids'] ?? [];
    if (!is_array($dept_ids)) {
        $dept_ids = array_filter(array_map('intval', explode(',', (string)$dept_ids)));
    } else {
        $dept_ids = array_filter(array_map('intval', $dept_ids));
    }

    $post_id = (int)($_POST['post_id'] ?? 0);
    $print_type = $_POST['print_type'] ?? 'poster'; // poster or absence_summary
    $target_date = $_POST['target_date'] ?? date('Y-m-d');

    if (empty($printer_ids) && empty($dept_ids)) {
        http_response_code(400);
        echo json_encode(['error' => '排紙対象の出力先または部署が選択されていません']);
        exit;
    }

    // クエリ構築: printer_ids 指定があれば優先、なければ dept_ids
    if (!empty($printer_ids)) {
        $in_placeholders = implode(',', array_fill(0, count($printer_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT dp.*, td.dept_name 
            FROM department_printers dp
            JOIN target_departments td ON dp.dept_id = td.dept_id
            WHERE dp.dept_printer_id IN ({$in_placeholders}) AND dp.is_active = TRUE
            ORDER BY td.display_order ASC, dp.order_num ASC, dp.dept_printer_id ASC
        ");
        $stmt->execute($printer_ids);
    } else {
        $in_placeholders = implode(',', array_fill(0, count($dept_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT dp.*, td.dept_name 
            FROM department_printers dp
            JOIN target_departments td ON dp.dept_id = td.dept_id
            WHERE dp.dept_id IN ({$in_placeholders}) AND dp.is_active = TRUE
            ORDER BY td.display_order ASC, dp.order_num ASC, dp.dept_printer_id ASC
        ");
        $stmt->execute($dept_ids);
    }

    $printers = $stmt->fetchAll();

    if (empty($printers)) {
        http_response_code(400);
        echo json_encode(['error' => '有効なプリンタ設定が見つかりませんでした']);
        exit;
    }

    $results = [];
    $script_path = realpath(__DIR__ . '/../scripts/print_dispatcher.ps1') ?: 'C:\Apache24\htdocs\kawara\scripts\print_dispatcher.ps1';
    $base_url = 'http://localhost/kawara';

    foreach ($printers as $p) {
        $dept_id = (int)$p['dept_id'];
        $printer_id = (int)$p['dept_printer_id'];
        $dest_label = $p['dept_display_name'] ?: $p['dept_name'];
        $primary = $p['primary_printer_name'];
        $fallback = $p['fallback_printer_name'] ?? '';
        // 部署専用ポスター（A4縦）またはまとめ印刷（A4横）のURLと用紙向き
        if ($print_type === 'absence_summary') {
            $default_orientation = 'landscape';
            $html_url = "{$base_url}/print_absence_summary.php?dept_id={$dept_id}&printer_id={$printer_id}&date=" . urlencode($target_date);
        } else {
            $default_orientation = 'portrait';
            $html_url = "{$base_url}/print_dept_poster.php?id={$post_id}&dept_id={$dept_id}&printer_id={$printer_id}&date=" . urlencode($target_date);
        }
        $orientation = !empty($p['orientation']) ? $p['orientation'] : $default_orientation;

        // PowerShell スクリプトの実行（Base64 EncodedCommand により文字コード・特殊文字・クォート崩れを完全防止）
        $psCode = sprintf(
            "\$ProgressPreference = 'SilentlyContinue'; [Console]::OutputEncoding = [System.Text.Encoding]::UTF8; & '%s' -TargetUrl '%s' -PrimaryPrinter '%s' -FallbackPrinter '%s' -DeptName '%s' -PaperOrientation '%s'",
            str_replace("'", "''", $script_path),
            str_replace("'", "''", $html_url),
            str_replace("'", "''", $primary),
            str_replace("'", "''", $fallback),
            str_replace("'", "''", $dest_label),
            str_replace("'", "''", $orientation)
        );

        $encodedCommand = base64_encode(mb_convert_encoding($psCode, 'UTF-16LE', 'UTF-8'));
        $cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -EncodedCommand {$encodedCommand} 2>&1";

        $output = shell_exec($cmd) ?? '';
        $res_data = null;
        $json_start = strpos($output, '{');
        $json_end = strrpos($output, '}');
        if ($json_start !== false && $json_end !== false && $json_end >= $json_start) {
            $clean_json = substr($output, $json_start, $json_end - $json_start + 1);
            $res_data = json_decode($clean_json, true);
        }

        if (!$res_data) {
            $results[] = [
                'dept_printer_id' => $printer_id,
                'dept_id'   => $dept_id,
                'dept_name' => $dest_label,
                'status'    => 'failed',
                'message'   => 'PowerShellの実行結果を取得できませんでした',
                'raw'       => mb_convert_encoding($output, 'UTF-8', 'SJIS-win, UTF-8, CP932')
            ];
        } else {
            $res_data['dept_printer_id'] = $printer_id;
            $res_data['dept_name'] = $dest_label;
            $results[] = $res_data;
        }
    }

    echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => '無効なリクエストです']);
