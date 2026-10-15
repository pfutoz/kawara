<?php
/**
 * Cloudflare 外部トンネル 緊急制御API
 * File: api/tunnel_control.php
 * 
 * 事務長・管理者専用:
 * Cloudflared サービス（外部トンネル）の稼働状態確認および ON / OFF（緊急停止・再開）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_helper.php';

// 1. 権限チェック（事務長 or システム管理者のみ）
$current_user = checkAuthOrAutoLogin($pdo, 'api/tunnel_control.php');
if (!$current_user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

$current_staff_id = (int)($current_user['staff_id'] ?? 0);
$is_admin = (!empty($current_user['is_admin']) 
    || $current_staff_id === 15 
    || mb_strpos($current_user['staff_name'] ?? '', '山本') !== false 
    || mb_strpos($current_user['role'] ?? '', '事務') !== false);

if (!$is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '権限がありません。事務長またはシステム管理者のみ実行可能です。']);
    exit;
}

// 2. 制御ターゲットサーバーの判定
function getTunnelCommandTarget(): string {
    // 開発機からリモート制御するか、本番機でローカル制御するかを判定
    $out = [];
    @exec('sc.exe query Cloudflared 2>&1', $out, $ret);
    $raw = implode("\n", $out);
    if (stripos($raw, 'STATE') !== false) {
        return ''; // 本番サーバー上（ローカル）
    }
    // 開発機からは本番機 (192.168.1.16) を指定
    return '\\\\192.168.1.16';
}

function getCloudflaredStatus(): array {
    $target = getTunnelCommandTarget();
    $cmd = 'sc.exe ' . ($target ? "{$target} " : '') . 'query Cloudflared 2>&1';
    $out = [];
    @exec($cmd, $out, $ret);
    $raw = implode("\n", $out);

    if (stripos($raw, 'STATE') !== false && stripos($raw, 'RUNNING') !== false) {
        return [
            'is_running' => true,
            'status'     => 'RUNNING',
            'label'      => '公開中 (ON)',
            'color'      => '#16a34a',
            'target'     => $target ?: 'local'
        ];
    } elseif (stripos($raw, 'STATE') !== false && stripos($raw, 'STOPPED') !== false) {
        return [
            'is_running' => false,
            'status'     => 'STOPPED',
            'label'      => '遮断中 (OFF)',
            'color'      => '#dc2626',
            'target'     => $target ?: 'local'
        ];
    } elseif (stripos($raw, 'STOP_PENDING') !== false) {
        return [
            'is_running' => false,
            'status'     => 'STOP_PENDING',
            'label'      => '停止処理中...',
            'color'      => '#ea580c',
            'target'     => $target ?: 'local'
        ];
    } elseif (stripos($raw, 'START_PENDING') !== false) {
        return [
            'is_running' => true,
            'status'     => 'START_PENDING',
            'label'      => '開始処理中...',
            'color'      => '#0284c7',
            'target'     => $target ?: 'local'
        ];
    }

    return [
        'is_running' => false,
        'status'     => 'UNKNOWN',
        'label'      => '状態不明',
        'color'      => '#64748b',
        'raw'        => $raw,
        'target'     => $target ?: 'local'
    ];
}

$action = $_REQUEST['action'] ?? 'status';

switch ($action) {
    // ---------------------------------------------------------
    // 状態取得
    // ---------------------------------------------------------
    case 'status':
        $st = getCloudflaredStatus();
        echo json_encode([
            'success'   => true,
            'status'    => $st['status'],
            'is_online' => $st['is_running'],
            'label'     => $st['label'],
            'color'     => $st['color'],
            'checked_at'=> date('H:i:s')
        ]);
        exit;

    // ---------------------------------------------------------
    // 停止（外部トンネル遮断）
    // ---------------------------------------------------------
    case 'stop':
        $target = getTunnelCommandTarget();
        $cmd = 'sc.exe ' . ($target ? "{$target} " : '') . 'stop Cloudflared 2>&1';
        $out = [];
        @exec($cmd, $out, $ret);

        // サービス停止の完了を最大3秒待機
        for ($i = 0; $i < 3; $i++) {
            sleep(1);
            $st = getCloudflaredStatus();
            if (!$st['is_running']) break;
        }

        // ログ記録
        $log_dir = __DIR__ . '/../logs';
        if (!is_dir($log_dir)) @mkdir($log_dir, 0777, true);
        @file_put_contents(
            $log_dir . '/security_audit.log',
            sprintf("[%s] [CLOUDFLARE_STOP] Staff: %s (ID: %d) shut down external tunnel.\n", date('Y-m-d H:i:s'), $current_user['staff_name'], $current_staff_id),
            FILE_APPEND
        );

        echo json_encode([
            'success'   => true,
            'status'    => $st['status'],
            'is_online' => $st['is_running'],
            'label'     => $st['label'],
            'color'     => $st['color'],
            'message'   => 'Cloudflare外部トンネルを正常に停止（遮断）しました。外部からの全アクセスが遮断されました。'
        ]);
        exit;

    // ---------------------------------------------------------
    // 開始（外部トンネル再開）
    // ---------------------------------------------------------
    case 'start':
        $target = getTunnelCommandTarget();
        $cmd = 'sc.exe ' . ($target ? "{$target} " : '') . 'start Cloudflared 2>&1';
        $out = [];
        @exec($cmd, $out, $ret);

        // サービス起動の完了を最大3秒待機
        for ($i = 0; $i < 3; $i++) {
            sleep(1);
            $st = getCloudflaredStatus();
            if ($st['is_running']) break;
        }

        // ログ記録
        $log_dir = __DIR__ . '/../logs';
        if (!is_dir($log_dir)) @mkdir($log_dir, 0777, true);
        @file_put_contents(
            $log_dir . '/security_audit.log',
            sprintf("[%s] [CLOUDFLARE_START] Staff: %s (ID: %d) started external tunnel.\n", date('Y-m-d H:i:s'), $current_user['staff_name'], $current_staff_id),
            FILE_APPEND
        );

        echo json_encode([
            'success'   => true,
            'status'    => $st['status'],
            'is_online' => $st['is_running'],
            'label'     => $st['label'],
            'color'     => $st['color'],
            'message'   => 'Cloudflare外部トンネルを開始しました。職員スマホからのアクセスが可能になりました。'
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '不明なアクションです。']);
        exit;
}
