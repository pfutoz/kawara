<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 権限チェック（事務長または管理者）
if (!isset($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未ログインです']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get_settings');

// 1. 設定一覧の取得
if ($action === 'get_settings') {
    $stmt = $pdo->query("
        SELECT dp.*, td.dept_name, td.display_order as dept_order 
        FROM department_printers dp
        JOIN target_departments td ON dp.dept_id = td.dept_id
        ORDER BY td.display_order ASC, dp.order_num ASC, dp.dept_printer_id ASC
    ");
    $settings = $stmt->fetchAll();

    // 部署一覧も同時に取得（新規追加ドロップダウン用）
    $deptStmt = $pdo->query("
        SELECT dept_id, dept_name 
        FROM target_departments 
        WHERE is_active = TRUE AND dept_id != 1
        ORDER BY display_order ASC, dept_id ASC
    ");
    $departments = $deptStmt->fetchAll();

    echo json_encode(['success' => true, 'settings' => $settings, 'departments' => $departments], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Windowsに実在するプリンタ一覧の取得（PowerShell連携）
if ($action === 'get_installed_printers') {
    $ps_cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; Get-Printer | Select-Object Name, PrinterStatus, PortName | ConvertTo-Json -Compress"';
    $output = shell_exec($ps_cmd);
    
    $printers = [];
    if (!empty($output)) {
        $decoded = json_decode($output, true);
        if ($decoded !== null) {
            $printers = isset($decoded['Name']) ? [$decoded] : $decoded;
        }
    }

    echo json_encode(['success' => true, 'printers' => $printers], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. 設定の保存（個別更新）
if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dept_printer_id = (int)($_POST['dept_printer_id'] ?? 0);
    $primary = trim($_POST['primary_printer'] ?? '');
    $fallback = trim($_POST['fallback_printer'] ?? '');
    $display = trim($_POST['dept_display_name'] ?? '');
    $is_active = isset($_POST['is_active']) ? filter_var($_POST['is_active'], FILTER_VALIDATE_BOOLEAN) : true;

    if ($dept_printer_id <= 0 || empty($primary)) {
        http_response_code(400);
        echo json_encode(['error' => '無効なパラメータです']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE department_printers 
        SET primary_printer_name = :primary,
            fallback_printer_name = :fallback,
            dept_display_name = CASE WHEN :display != '' THEN :display ELSE dept_display_name END,
            is_active = :is_active,
            updated_at = CURRENT_TIMESTAMP
        WHERE dept_printer_id = :dept_printer_id
    ");
    $stmt->execute([
        ':primary'  => $primary,
        ':fallback' => $fallback,
        ':display'  => $display,
        ':is_active' => $is_active ? 'true' : 'false',
        ':dept_printer_id' => $dept_printer_id
    ]);

    echo json_encode(['success' => true, 'message' => 'プリンタ設定を更新しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. 新規出力先プリンタの追加
if ($action === 'add_printer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dept_id = (int)($_POST['dept_id'] ?? 0);
    $display = trim($_POST['dept_display_name'] ?? '');
    $primary = trim($_POST['primary_printer'] ?? '');
    $fallback = trim($_POST['fallback_printer'] ?? '');

    if ($dept_id <= 0 || empty($primary)) {
        http_response_code(400);
        echo json_encode(['error' => '部署とメインプリンタを選択してください']);
        exit;
    }

    if (empty($display)) {
        $stmtD = $pdo->prepare("SELECT dept_name FROM target_departments WHERE dept_id = :id");
        $stmtD->execute([':id' => $dept_id]);
        $display = $stmtD->fetchColumn() ?: '出力先';
    }

    $stmt = $pdo->prepare("
        INSERT INTO department_printers 
            (dept_id, dept_display_name, primary_printer_name, fallback_printer_name, is_active, created_at, updated_at)
        VALUES 
            (:dept_id, :display, :primary, :fallback, true, NOW(), NOW())
        RETURNING dept_printer_id
    ");
    $stmt->execute([
        ':dept_id'  => $dept_id,
        ':display'  => $display,
        ':primary'  => $primary,
        ':fallback' => $fallback
    ]);
    $newId = $stmt->fetchColumn();

    echo json_encode(['success' => true, 'message' => '出力先プリンタを追加しました', 'new_id' => $newId], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. 出力先プリンタの削除
if ($action === 'delete_printer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dept_printer_id = (int)($_POST['dept_printer_id'] ?? 0);
    if ($dept_printer_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '無効なIDです']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM department_printers WHERE dept_printer_id = :id");
    $stmt->execute([':id' => $dept_printer_id]);

    echo json_encode(['success' => true, 'message' => '出力先を削除しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => '不明なアクションです']);
