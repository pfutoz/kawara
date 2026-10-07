<?php
/**
 * 院内かわら版 - LINE Messaging API Webhook
 * エンドポイント: /kawara/api/line_webhook.php
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../includes/line_helper.php';

$input = file_get_contents('php://input');

// 署名検証（LINE_CHANNEL_SECRETが設定されている場合）
if (defined('LINE_CHANNEL_SECRET') && LINE_CHANNEL_SECRET !== 'YOUR_LINE_CHANNEL_SECRET_HERE' && !empty(LINE_CHANNEL_SECRET)) {
    $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    $hash = hash_hmac('sha256', $input, LINE_CHANNEL_SECRET, true);
    if (!hash_equals(base64_encode($hash), $signature)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$data = json_decode($input, true);
$events = $data['events'] ?? [];

$host = 'localhost'; $dbname = 'kawara'; $user = 'postgres'; $password = 'postgres';
try {
    $pdo = new PDO("pgsql:host={$host};dbname={$dbname}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

foreach ($events as $event) {
    $type = $event['type'] ?? '';
    $reply_token = $event['replyToken'] ?? '';
    $user_id = $event['source']['userId'] ?? '';

    if ($type === 'message' && ($event['message']['type'] ?? '') === 'text') {
        $text = trim($event['message']['text'] ?? '');
        
        // 4桁〜6桁の数字コードか判定
        if (preg_match('/^\d{4,6}$/', $text)) {
            $link_res = verifyAndLinkStaffByCode($pdo, $user_id, $text);
            if ($link_res['success']) {
                $reply_msg = "🎉 【医療法人小野会 院内連絡網】\n"
                           . "{$link_res['staff_name']} 様（{$link_res['role']}）のLINE連携が完了しました！\n\n"
                           . "今後、緊急時の安否確認やBCP点呼、重要なお知らせがこのトークに届きます。\n"
                           . "かわら版の画面に戻ると自動的に「連携完了」となります。";
                sendLineReply($reply_token, $reply_msg);
            } else {
                $reply_msg = "⚠️ " . $link_res['message'] . "\n\n"
                           . "かわら版画面に表示された有効な数字コードを送信してください。";
                sendLineReply($reply_token, $reply_msg);
            }
        } else {
            // すでに連携済みか確認
            $stmt_check = $pdo->prepare("SELECT staff_name, role FROM staff WHERE line_user_id = :uid AND (is_deleted IS NOT TRUE) LIMIT 1");
            $stmt_check->execute([':uid' => $user_id]);
            $linked_staff = $stmt_check->fetch();

            if ($linked_staff) {
                $reply_msg = "🏥 医療法人小野会 院内連絡網\n"
                           . "【連携中】{$linked_staff['staff_name']} 様\n\n"
                           . "緊急安否確認や重要なお知らせを受信可能な状態です。\n"
                           . "平常時は特に返信の必要はありません。";
                sendLineReply($reply_token, $reply_msg);
            } else {
                $reply_msg = "🏥 医療法人小野会 院内かわら版・連絡網です。\n\n"
                           . "かわら版画面の「LINE連携」を開き、表示されている【数字4桁のコード】をこのトークに送信してください。自動で連携されます。";
                sendLineReply($reply_token, $reply_msg);
            }
        }
    } elseif ($type === 'postback') {
        // Flex Message のボタンタップ（1タップ意思表示）
        $postback_data = $event['postback']['data'] ?? '';
        parse_str($postback_data, $params);
        $action = $params['action'] ?? '';

        if ($action === 'ack') {
            $post_id = (int)($params['post_id'] ?? 0);
            $status = $params['status'] ?? 'ok'; // ok, question, absence

            // 1. 送信元の line_user_id からスタッフを特定
            $stmt_st = $pdo->prepare("SELECT staff_id, staff_name, role FROM staff WHERE line_user_id = :uid AND (is_deleted IS NOT TRUE) LIMIT 1");
            $stmt_st->execute([':uid' => $user_id]);
            $staff = $stmt_st->fetch();

            if ($staff && $post_id > 0) {
                $sid = (int)$staff['staff_id'];

                // 2. 記事タイトルを取得
                $stmt_p = $pdo->prepare("SELECT title FROM posts WHERE post_id = :pid");
                $stmt_p->execute([':pid' => $post_id]);
                $post_title = $stmt_p->fetchColumn() ?: 'お知らせ';

                // 3. post_reads テーブルに記録（未読なら新規INSERT、既読ならUPDATE）
                $stmt_upsert = $pdo->prepare("
                    INSERT INTO post_reads (post_id, staff_id, read_at, response_status, response_at)
                    VALUES (:pid, :sid, NOW(), :status, NOW())
                    ON CONFLICT (post_id, staff_id) 
                    DO UPDATE SET response_status = :status, response_at = NOW()
                ");
                $stmt_upsert->execute([
                    ':pid'    => $post_id,
                    ':sid'    => $sid,
                    ':status' => $status
                ]);

                // 4. ステータスに応じた丁寧な自動返信
                $status_labels = [
                    'ok'       => '👍 了解（対応可能）',
                    'question' => '❓ 質問・確認あり',
                    'absence'  => '⚠️ 不在・対応不可'
                ];
                $status_text = $status_labels[$status] ?? '確認済み';

                $reply_msg = "✅ 【意思表示を受け付けました】\n"
                           . "伝達: {$post_title}\n"
                           . "返答: {$status_text}\n"
                           . "職員: {$staff['staff_name']} 様\n\n";

                if ($status === 'ok') {
                    $reply_msg .= "「了解」として登録しました。かわら版管理画面（事務長ダッシュボード）にリアルタイム反映されました。ご協力ありがとうございます！";
                } elseif ($status === 'question') {
                    $reply_msg .= "「質問・確認あり」として登録しました。確認事項はかわら版コメント欄または事務部・担当者へ直接お伝えください。";
                } elseif ($status === 'absence') {
                    $reply_msg .= "「不在・対応不可」として登録しました。当日の出勤者・担当者側で代替対応を調整いたします。";
                }

                sendLineReply($reply_token, $reply_msg);
            }
        }
    } elseif ($type === 'follow') {
        // 友達追加時の自動あいさつメッセージ
        $reply_msg = "🏥 医療法人小野会 院内かわら版・BCP連絡網へようこそ！\n\n"
                   . "パソコンのかわら版画面に表示されている【数字4桁のコード】をこのトークに送信してください。職員アカウントと自動連携されます。";
        sendLineReply($reply_token, $reply_msg);
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
