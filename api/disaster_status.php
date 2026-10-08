<?php
/**
 * 院内かわら版 - 緊急災害モード判定API
 * エンドポイント: /kawara/api/disaster_status.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../includes/db.php';

try {

    // 1. アクティブな点呼イベント・災害モードの取得
    $stmt = $pdo->query("SELECT * FROM safety_events WHERE is_active = TRUE ORDER BY event_id DESC LIMIT 1");
    $event = $stmt->fetch();

    $is_disaster = false;
    $event_title = '通常運用';
    $event_desc = '';
    $event_id = 0;
    $created_at = null;

    if ($event) {
        $is_disaster = (bool)$event['is_disaster_mode'];
        $event_title = $event['title'];
        $event_desc  = $event['description'];
        $event_id    = (int)$event['event_id'];
        $created_at  = $event['created_at'];
    }

    // 2. 本日の生存確認・点呼サマリー集計
    $total_staff = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE (is_deleted IS NOT TRUE)")->fetchColumn();

    $today_midnight = date('Y-m-d 00:00:00');
    $stmt_summary = $pdo->prepare("
        WITH latest_checks AS (
            SELECT DISTINCT ON (staff_id) staff_id, status, reported_at
            FROM safety_checks
            WHERE reported_at >= :today
            ORDER BY staff_id, reported_at DESC
        )
        SELECT 
            COUNT(CASE WHEN status = 'safe' THEN 1 END) AS safe_cnt,
            COUNT(CASE WHEN status = 'caution' THEN 1 END) AS caution_cnt,
            COUNT(CASE WHEN status = 'danger' THEN 1 END) AS danger_cnt,
            COUNT(*) AS reported_cnt
        FROM latest_checks
    ");
    $stmt_summary->execute([':today' => $today_midnight]);
    $sum = $stmt_summary->fetch();

    $safe_count    = (int)($sum['safe_cnt'] ?? 0);
    $caution_count = (int)($sum['caution_cnt'] ?? 0);
    $danger_count  = (int)($sum['danger_cnt'] ?? 0);
    $reported_count = (int)($sum['reported_cnt'] ?? 0);
    $unreported_count = max(0, $total_staff - $reported_count);
    $report_rate = ($total_staff > 0) ? round(($reported_count / $total_staff) * 100) : 0;

    echo json_encode([
        'success'           => true,
        'is_disaster_mode'  => $is_disaster,
        'mode_code'         => $is_disaster ? 'disaster' : 'normal',
        'mode_label'        => $is_disaster ? '【🚨災害時緊急モード発令中】' : '【平時・訓練モード】',
        'alert_level'       => $is_disaster ? 'danger' : 'info',
        'event_id'          => $event_id,
        'event_title'       => $event_title,
        'event_desc'        => $event_desc,
        'created_at'        => $created_at,
        'checked_at'        => date('Y-m-d H:i:s'),
        'safety_summary'    => [
            'total_staff'      => $total_staff,
            'reported_count'   => $reported_count,
            'unreported_count' => $unreported_count,
            'safe_count'       => $safe_count,
            'caution_count'    => $caution_count,
            'danger_count'     => $danger_count,
            'report_rate'      => $report_rate
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success'          => false,
        'is_disaster_mode' => false,
        'error'            => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
