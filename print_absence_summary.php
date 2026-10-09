<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/calendar_helper_jimucho.php';
require_once __DIR__ . '/includes/doctor_schedule_helper.php';

$dept_id = (int)($_GET['dept_id'] ?? 0);
$base_date = $_GET['date'] ?? date('Y-m-d');
$base_ts = strtotime($base_date);
$w = (int)date('w', $base_ts);

// 不在期間の自動算出
// 水曜日の場合: 翌木曜日(1日)
// 金曜日の場合: 土・日・月(3日間)
// その他の場合は指定日〜翌日
$start_date = $base_date;
$end_date   = $base_date;

if ($w === 3) { // 水曜日
    $start_date = date('Y-m-d', strtotime('+1 day', $base_ts)); // 木曜
    $end_date   = $start_date;
    $period_title = date('m/d(木)', strtotime($start_date)) . " 【木曜不在日】";
} elseif ($w === 5) { // 金曜日
    $start_date = date('Y-m-d', strtotime('+1 day', $base_ts)); // 土曜
    $end_date   = date('Y-m-d', strtotime('+3 days', $base_ts)); // 月曜
    $period_title = date('m/d(土)', strtotime($start_date)) . " 〜 " . date('m/d(月)', strtotime($end_date)) . " 【週末不在期間】";
} else {
    $start_date = date('Y-m-d', strtotime('+1 day', $base_ts));
    $end_date   = date('Y-m-d', strtotime('+2 days', $base_ts));
    $period_title = date('m/d', strtotime($start_date)) . " 〜 " . date('m/d', strtotime($end_date)) . " 【不在期間】";
}

// 部署・出力先名の取得
$dept_name = '全部署共通';
$printer_id = (int)($_GET['printer_id'] ?? 0);
if ($printer_id > 0) {
    $stmt_p = $pdo->prepare("SELECT dept_display_name FROM department_printers WHERE dept_printer_id = :pid");
    $stmt_p->execute([':pid' => $printer_id]);
    $dept_name = $stmt_p->fetchColumn() ?: '';
}
if (empty($dept_name) && $dept_id > 0) {
    $stmt_d = $pdo->prepare("SELECT dept_name FROM target_departments WHERE dept_id = :id");
    $stmt_d->execute([':id' => $dept_id]);
    $dept_name = $stmt_d->fetchColumn() ?: '全部署共通';
}

// 期間内の予定記事を取得
$stmt = $pdo->prepare("
    SELECT p.*, c.category_name, c.icon_emoji, s.staff_name 
    FROM posts p 
    LEFT JOIN post_categories c ON p.category_id = c.category_id 
    LEFT JOIN staff s ON p.author_id = s.staff_id 
    WHERE (
        (p.target_datetime >= :s_start AND p.target_datetime <= :s_end)
        OR (p.event_schedules IS NOT NULL AND p.event_schedules::text != '[]' AND p.event_schedules::text != 'null')
    )
    ORDER BY p.target_datetime ASC, p.post_id ASC
");
$stmt->execute([
    ':s_start' => $start_date . ' 00:00:00',
    ':s_end'   => $end_date . ' 23:59:59'
]);
$all_posts = $stmt->fetchAll();

// 期間に該当するスケジュールを抽出
$filtered_events = [];
$week_names = ['日', '月', '火', '水', '木', '金', '土'];

foreach ($all_posts as $post) {
    // 複数日程 JSONB のチェック
    $matched = false;
    if (!empty($post['event_schedules'])) {
        $slots = json_decode($post['event_schedules'], true);
        if (is_array($slots)) {
            foreach ($slots as $slot) {
                $slot_date = substr($slot['start_datetime'] ?? '', 0, 10);
                if ($slot_date >= $start_date && $slot_date <= $end_date) {
                    $filtered_events[] = [
                        'post'     => $post,
                        'start'    => $slot['start_datetime'],
                        'end'      => $slot['end_datetime'] ?? null,
                        'location' => $slot['location'] ?? '',
                        'memo'     => $slot['memo'] ?? ''
                    ];
                    $matched = true;
                }
            }
        }
    }
    // target_datetime 単一の場合
    if (!$matched && !empty($post['target_datetime'])) {
        $p_date = substr($post['target_datetime'], 0, 10);
        if ($p_date >= $start_date && $p_date <= $end_date) {
            $filtered_events[] = [
                'post'     => $post,
                'start'    => $post['target_datetime'],
                'end'      => $post['target_end_datetime'],
                'location' => '',
                'memo'     => ''
            ];
        }
    }
}

// 医師予定表 (yotei API) から対象期間の休診・不在予定を取得
$doctor_absence_events = fetch_doctor_events($start_date, $end_date, [
    'event_type'    => 'absence',
    'expand_period' => 1
]);

// 日付順・開始時間順にソート
usort($doctor_absence_events, function($a, $b) {
    $dA = $a['date'] ?? $a['start_date'] ?? '';
    $dB = $b['date'] ?? $b['start_date'] ?? '';
    if ($dA === $dB) {
        $tA = $a['is_all_day'] ? '00:00' : ($a['start_time'] ?? '99:99');
        $tB = $b['is_all_day'] ? '00:00' : ($b['start_time'] ?? '99:99');
        return strcmp($tA, $tB);
    }
    return strcmp($dA, $dB);
});

// 医師予定表の全体申し送りメモを取得
$doctor_memo = fetch_doctor_memo();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>事務長不在期間伝達シート【<?= htmlspecialchars($dept_name) ?>】</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif; color: #111; margin: 0; padding: 0; background: #fff; }
        .sheet-container { border: 2px solid #0f172a; padding: 18px 24px; min-height: 98vh; display: flex; flex-direction: column; justify-content: space-between; }
        .sheet-header { border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: flex-end; }
        .sheet-title { font-size: 18pt; font-weight: 900; color: #0f172a; }
        .sheet-target { font-size: 14pt; font-weight: bold; background: #1e3a8a; color: #fff; padding: 4px 14px; border-radius: 4px; }
        .period-box { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 14px; font-size: 12pt; font-weight: bold; color: #1e293b; margin-bottom: 12px; display: flex; justify-content: space-between; }
        
        .section-bar { display: flex; align-items: center; justify-content: space-between; padding: 5px 12px; border-radius: 4px; font-size: 10.5pt; font-weight: bold; margin: 12px 0 6px 0; color: #fff; }
        .section-bar.doctor { background: #b91c1c; }
        .section-bar.facility { background: #1e3a8a; }

        .events-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .events-table th { background: #e2e8f0; border: 1px solid #94a3b8; padding: 6px 10px; font-size: 9.5pt; text-align: left; }
        .events-table td { border: 1px solid #cbd5e1; padding: 7px 10px; font-size: 9.5pt; vertical-align: top; }
        .events-table tr:nth-child(even) { background: #f8fafc; }

        .item-time { font-weight: bold; color: #0284c7; white-space: nowrap; font-size: 10pt; }
        .item-title { font-weight: bold; font-size: 10.5pt; margin-bottom: 2px; color: #0f172a; }
        .item-content { font-size: 9pt; color: #334155; line-height: 1.35; }

        .badge-dept { display: inline-block; font-size: 8pt; font-weight: bold; padding: 2px 7px; border-radius: 3px; color: #fff; }
        .doctor-name { font-size: 11pt; font-weight: bold; color: #0f172a; }

        .memo-box { background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; }
        .memo-box-header { display: flex; justify-content: space-between; align-items: center; font-size: 9.5pt; font-weight: bold; color: #92400e; margin-bottom: 4px; border-bottom: 1px dashed #fcd34d; padding-bottom: 3px; }
        .memo-box-list { margin: 0; padding-left: 18px; font-size: 9pt; color: #78350f; line-height: 1.4; }

        .sign-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .sign-table td { border: 1px solid #64748b; padding: 6px; text-align: center; vertical-align: middle; }
        .sign-th { background: #f1f5f9; font-weight: bold; font-size: 8.5pt; color: #334155; width: 120px; }
        .sign-box { height: 42px; font-size: 8.5pt; color: #94a3b8; }

        @media print {
            .no-print { display: none !important; }
            .sheet-container { border: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body>

<div class="no-print" style="background:#1e293b; color:#fff; padding:8px 16px; display:flex; justify-content:space-between; align-items:center;">
    <span>📄 事務長不在期間まとめ伝達シート [<?= htmlspecialchars($dept_name) ?>] （医師予定・休診情報 連携版）</span>
    <button onclick="window.print()" style="background:#0284c7; color:#fff; font-weight:bold; border:none; padding:6px 16px; border-radius:4px; cursor:pointer;">
        🖨️ このシートを印刷
    </button>
</div>

<div class="sheet-container">
    <div>
        <div class="sheet-header">
            <div>
                <div style="font-size:9.5pt; color:#64748b;">医療法人小野会 事務部・医局業務伝達</div>
                <div class="sheet-title">📋 事務長不在期間 業務・予定伝達シート</div>
            </div>
            <div class="sheet-target">【<?= htmlspecialchars($dept_name) ?> 用】</div>
        </div>

        <div class="period-box">
            <span>対象期間: <?= htmlspecialchars($period_title) ?></span>
            <span style="font-size:9.5pt; color:#475569;">出力日: <?= date('Y/m/d H:i') ?></span>
        </div>

        <!-- 1. 🩺 医師休診・代診・不在予定セクション -->
        <div class="section-bar doctor">
            <span>🩺 医師休診・代診・不在予定（外来・診療体制）</span>
            <span style="font-size:8.5pt; font-weight:normal; opacity:0.9;">医師予定表システム連携</span>
        </div>
        <table class="events-table">
            <thead>
                <tr>
                    <th style="width: 125px;">日時・曜日</th>
                    <th style="width: 150px;">医師名・診療科</th>
                    <th>休診・不在内容</th>
                    <th style="width: 160px;">備考・特記事項</th>
                    <th style="width: 60px; text-align:center;">確認印</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($doctor_absence_events)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 12px; color: #059669; font-weight: bold; background: #ecfdf5;">
                            ✅ この不在期間中、登録されている医師の休診・不在予定はありません（通常診療体制）。
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($doctor_absence_events as $dev): 
                        $dev_date = $dev['date'] ?? $dev['start_date'] ?? '';
                        $dev_ts = strtotime($dev_date);
                        $w_idx = (int)date('w', $dev_ts);
                        $dev_time = $dev['is_all_day'] ? '終日' : (($dev['start_time'] ?? '') . (!empty($dev['end_time']) ? '〜' . $dev['end_time'] : ''));
                        $doc = $dev['doctor'] ?? [];
                        $dept_color = $doc['department_color'] ?? '#475569';
                    ?>
                        <tr>
                            <td class="item-time">
                                <div><?= date('m/d', $dev_ts) ?>(<?= $week_names[$w_idx] ?? '' ?>)</div>
                                <div style="font-size:8.5pt; color:#dc2626;"><?= htmlspecialchars($dev_time) ?></div>
                            </td>
                            <td>
                                <div class="doctor-name">
                                    <?= htmlspecialchars($doc['name'] ?? '医師') ?>
                                    <span style="font-size:8.5pt; color:#64748b;"><?= htmlspecialchars($doc['title'] ?? '') ?></span>
                                </div>
                                <div style="margin-top:2px;">
                                    <span class="badge-dept" style="background:<?= htmlspecialchars($dept_color) ?>;">
                                        <?= htmlspecialchars($doc['department_name'] ?? '診療科') ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="item-title" style="color:#b91c1c;">
                                    <?= htmlspecialchars($dev['event_icon'] ?? '🔴') ?> <?= htmlspecialchars($dev['title']) ?>
                                </div>
                                <?php if (!empty($dev['note'])): ?>
                                    <div class="item-content">
                                        <?= nl2br(htmlspecialchars($dev['note'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:8.5pt; color:#475569;">
                                <div>受付・外来対応確認</div>
                                <div style="color:#e11d48; font-weight:bold; margin-top:2px;">※カルテ確認要</div>
                            </td>
                            <td style="text-align:center; vertical-align:middle;">
                                <div style="width:32px; height:32px; border:1px dashed #94a3b8; border-radius:4px; margin:auto;"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 2. 📋 事務部 業務・工事・施設点検予定セクション -->
        <div class="section-bar facility">
            <span>📋 事務部 業務・工事・施設点検・全体予定</span>
            <span style="font-size:8.5pt; font-weight:normal; opacity:0.9;">院内かわら版連携</span>
        </div>
        <table class="events-table">
            <thead>
                <tr>
                    <th style="width: 125px;">日時・曜日</th>
                    <th>件名・作業内容・場所</th>
                    <th style="width: 150px;">現場担当・特記事項</th>
                    <th style="width: 60px; text-align:center;">確認印</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filtered_events)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 14px; color: #64748b;">
                            この不在期間中に予定されている工事・点検・設備作業はありません。
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filtered_events as $ev): 
                        $s_ts = strtotime($ev['start']);
                        $w_idx = (int)date('w', $s_ts);
                        $date_label = date('m/d', $s_ts) . '(' . ($week_names[$w_idx] ?? '') . ') ' . date('H:i', $s_ts);
                        $post = $ev['post'];
                    ?>
                        <tr>
                            <td class="item-time">
                                <div><?= $date_label ?></div>
                                <?php if (!empty($ev['end'])): ?>
                                    <div style="font-size:8pt; color:#64748b;">〜 <?= date('H:i', strtotime($ev['end'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="item-title">
                                    <?= htmlspecialchars($post['icon_emoji'] ?? '📌') ?> <?= htmlspecialchars($post['title']) ?>
                                </div>
                                <div class="item-content">
                                    <?= mb_strimwidth(strip_tags($post['content']), 0, 160, '…') ?>
                                </div>
                                <?php if (!empty($ev['location'])): ?>
                                    <div style="margin-top:3px; font-size:8.5pt; color:#0284c7; font-weight:bold;">
                                        📍 場所: <?= htmlspecialchars($ev['location']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:8.5pt;">
                                <div>発信: <?= htmlspecialchars($post['staff_name'] ?? '事務長') ?></div>
                                <div style="color:#e11d48; font-weight:bold; margin-top:3px;">
                                    ※不在時対応要
                                </div>
                            </td>
                            <td style="text-align:center; vertical-align:middle;">
                                <div style="width:32px; height:32px; border:1px dashed #94a3b8; border-radius:4px; margin:auto;"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 3. 📝 医師予定表 連絡・申し送りメモ（存在する場合のみ表示） -->
        <?php if (!empty($doctor_memo['lines'])): ?>
            <div class="memo-box">
                <div class="memo-box-header">
                    <span>📝 医師予定表 連絡・申し送りメモ（外来・診療体制の補足事項）</span>
                    <span style="font-size:8pt; font-weight:normal; color:#92400e;">
                        更新: <?= !empty($doctor_memo['updated_at']) ? date('Y/m/d H:i', strtotime($doctor_memo['updated_at'])) : '' ?>
                    </span>
                </div>
                <ul class="memo-box-list">
                    <?php foreach ($doctor_memo['lines'] as $line): ?>
                        <li><?= htmlspecialchars($line) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div style="font-size:8.5pt; color:#475569; margin-bottom:4px;">
            ※ 本シートは事務長不在期間の現場自立運用のためのものです。各部署の朝礼で伝達・周知し、下記署名欄にご記入のうえ保管してください。
        </div>
        <table class="sign-table">
            <tr>
                <td class="sign-th">朝礼伝達日</td>
                <td class="sign-box" style="width: 130px;">月　　日（　）</td>
                <td class="sign-th">伝達責任者（日直/リーダー）</td>
                <td class="sign-box"></td>
                <td class="sign-th">引き継ぎ確認印</td>
                <td class="sign-box" style="width: 85px;"></td>
            </tr>
        </table>
    </div>
</div>

</body>
</html>
