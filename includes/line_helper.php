<?php
/**
 * 院内かわら版 - LINE送信共通ヘルパー（サブルーチン）
 */

// ★ 将来的にLINE Developer Consoleで取得した設定値をここに設定
define('LINE_CHANNEL_ACCESS_TOKEN', 'N9DG4MduhnsK47e20jk0vPMOtPsU3qGWDOKs6ZQ37EofWVglz8bxRRvcNr2P+l48OU5U4sLw5xG2VR8izTcWWU0NUgYog8/gDaflv+m67dkV41HhUM5nlLLYVHj6YlMe35TWKUJ80xNjN94dwYuEeAdB04t89/1O/w1cDnyilFU=');
define('LINE_CHANNEL_SECRET', '89d06cced6219e24e5176b938d4e2f6a');
define('LINE_BOT_BASIC_ID', '@tmw3446q'); // おの肛門科 公式アカウントのベーシックID

/**
 * 指定したスタッフ（単一または複数）へLINEメッセージを送信する関数
 * 
 * @param PDO       $pdo       DB接続オブジェクト
 * @param int|array $staff_ids 対象の staff_id（単一の数値、または数値の配列）
 * @param string    $message   送信する本文テキスト
 * @return array               送信結果 ['success' => bool, 'sent_count' => int, 'unregistered_count' => int, 'errors' => array]
 */
function sendLineNotification($pdo, $staff_ids, $message) {
    // 引数を配列形式に統一
    if (!is_array($staff_ids)) {
        $staff_ids = [$staff_ids];
    }

    // 重複除去 ＆ 整形
    $staff_ids = array_unique(array_map('intval', $staff_ids));
    if (empty($staff_ids)) {
        return ['success' => false, 'sent_count' => 0, 'unregistered_count' => 0, 'errors' => ['対象スタッフが指定されていません。']];
    }

    // 1. 対象スタッフの line_user_id をDBから取得
    $in_clause = implode(',', array_fill(0, count($staff_ids), '?'));
    $stmt = $pdo->prepare("SELECT staff_id, staff_name, line_user_id FROM staff WHERE staff_id IN ({$in_clause}) AND is_deleted = FALSE");
    $stmt->execute($staff_ids);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $target_tokens = [];
    $unregistered_count = 0;

    foreach ($members as $m) {
        // line_user_id がセットされており、かつ33桁のフォーマット（先頭U）を満たしているか判定
        $token = trim($m['line_user_id'] ?? '');
        if (!empty($token)) {
            $target_tokens[] = $token;
        } else {
            $unregistered_count++;
        }
    }

    // 送信対象のLINE IDが1件もない場合（全員未登録）
    if (empty($target_tokens)) {
        return [
            'success'            => false,
            'sent_count'         => 0,
            'unregistered_count' => $unregistered_count,
            'errors'             => ['対象者のLINE IDが登録されていません。']
        ];
    }

    // 2. LINE Messaging API (Multicast API) の呼び出し
    // ※ トークン未設定時はAPI通信を行わずに未登録カウントのみ返却（安全対策）
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_LINE_CHANNEL_ACCESS_TOKEN_HERE' || empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        return [
            'success'            => false,
            'sent_count'         => 0,
            'unregistered_count' => $unregistered_count,
            'errors'             => ['LINEアクセストークンが未設定のため送信をスキップしました。']
        ];
    }

    $url = 'https://api.line.me/v2/bot/message/multicast';
    
    // Multicast APIは一度に500人まで送信可能（500人ずつ分割送信）
    $chunked_tokens = array_chunk($target_tokens, 500);
    $sent_count = 0;
    $errors = [];

    foreach ($chunked_tokens as $tokens) {
        $post_data = [
            'to'       => $tokens,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $message
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        if ($http_code === 200) {
            $sent_count += count($tokens);
        } else {
            $errors[] = "LINE API Error (HTTP {$http_code}): {$response}";
        }
    }

    return [
        'success'            => ($sent_count > 0),
        'sent_count'         => $sent_count,
        'unregistered_count' => $unregistered_count,
        'errors'             => $errors
    ];
}

/**
 * 院内伝達記事から LINE Flex Message（Bubble Container）を自動生成する関数
 * 
 * @param array  $post               記事データ連想配列（title, category_name, icon_emoji, target_datetime, event_schedules等）
 * @param string $custom_notice      LINE通知用のひとこと注意事項・要約（空なら本文から抽出）
 * @param string $target_dept_names  対象部署名（例: "看護部 / 事務部"）
 * @param string $base_url           かわら版のベースURL（空なら自動補完）
 * @return array                     LINE Flex Message の bubble 配列
 */
function buildPostFlexBubble($post, $custom_notice = '', $target_dept_names = '', $base_url = '') {
    $post_id = (int)($post['post_id'] ?? 0);
    $title = (string)($post['title'] ?? '無題のお知らせ');
    $cat_name = (string)($post['category_name'] ?? 'お知らせ');
    $icon = (string)($post['icon_emoji'] ?? '📋');
    $author_name = (string)($post['staff_name'] ?? ($post['author_name'] ?? ''));
    $author_dept = (string)($post['author_dept'] ?? '');

    if (empty($base_url)) {
        $tunnel = getLineTunnelStatus();
        if (!empty($tunnel['url'])) {
            $base_url = rtrim($tunnel['url'], '/') . '/kawara';
        } else {
            $base_url = 'http://192.168.1.16/kawara';
        }
    }
    $view_url = "{$base_url}/view_post.php?id={$post_id}";

    // カテゴリ別のヘッダー背景色マップ
    $cat_colors = [
        '設備・営繕'   => '#d97706',
        '研修・勉強会' => '#0284c7',
        '会議・委員会' => '#4f46e5',
        '緊急・重要'   => '#dc2626',
        '安全・感染'   => '#16a34a',
        '総務・人事'   => '#0d9488',
        '連絡事項'     => '#475569'
    ];
    $header_bg = '#005a9c'; // デフォルト小野会ブルー
    foreach ($cat_colors as $key => $color) {
        if (mb_strpos($cat_name, $key) !== false) {
            $header_bg = $color;
            break;
        }
    }

    // 実施日時の組み立て
    $week_names = ['日', '月', '火', '水', '木', '金', '土'];
    $date_str = '指定なし';
    $multi_schedules = [];
    if (!empty($post['event_schedules'])) {
        $dec = is_array($post['event_schedules']) ? $post['event_schedules'] : json_decode($post['event_schedules'], true);
        if (is_array($dec) && count($dec) > 0) {
            $multi_schedules = $dec;
        }
    }

    if (!empty($multi_schedules)) {
        $count = count($multi_schedules);
        $first = $multi_schedules[0];
        $f_ts = !empty($first['date']) ? strtotime($first['date']) : (!empty($first['start_datetime']) ? strtotime($first['start_datetime']) : null);
        $w = $f_ts ? $week_names[(int)date('w', $f_ts)] : '';
        $f_d = $f_ts ? date('n/j', $f_ts) . "({$w})" : ($first['date'] ?? '');
        $f_t = !empty($first['is_all_day']) ? '終日' : ($first['start_time'] ?? '');
        $date_str = "全{$count}回 / 初回: {$f_d} {$f_t}";
    } elseif (!empty($post['target_datetime'])) {
        $s_ts = strtotime($post['target_datetime']);
        $w = $week_names[(int)date('w', $s_ts)];
        $s_d = date('n/j', $s_ts) . "({$w})";
        $e_ts = !empty($post['target_end_datetime']) ? strtotime($post['target_end_datetime']) : null;
        if (!empty($post['is_all_day'])) {
            $date_str = "{$s_d} 終日";
        } elseif ($e_ts) {
            $t_start = date('H:i', $s_ts);
            $t_end = date('H:i', $e_ts);
            $date_str = "{$s_d} {$t_start}〜{$t_end}";
        } else {
            $date_str = "{$s_d} " . date('H:i', $s_ts) . "〜";
        }
    }

    // LINE用ひとこと注意事項・影響（空なら本文のテキストを平文化して先頭100文字）
    $notice_text = trim($custom_notice);
    if (empty($notice_text)) {
        $raw_content = strip_tags($post['content'] ?? '');
        $raw_content = preg_replace('/\s+/u', ' ', $raw_content);
        $notice_text = mb_substr($raw_content, 0, 80);
        if (mb_strlen($raw_content) > 80) $notice_text .= '...';
    }

    // 対象部署
    $dept_display = !empty($target_dept_names) ? $target_dept_names : '全館共通';

    // Bubble構造の構築
    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type'            => 'box',
            'layout'          => 'horizontal',
            'backgroundColor' => $header_bg,
            'paddingAll'      => '12px',
            'contents'        => [
                [
                    'type'   => 'text',
                    'text'   => "{$icon} {$cat_name}",
                    'color'  => '#ffffff',
                    'weight' => 'bold',
                    'size'   => 'sm',
                    'flex'   => 4
                ],
                [
                    'type'    => 'text',
                    'text'    => '院内伝達',
                    'color'   => '#ffffff',
                    'size'    => 'xxs',
                    'align'   => 'end',
                    'gravity' => 'center',
                    'flex'    => 2
                ]
            ]
        ],
        'body' => [
            'type'       => 'box',
            'layout'     => 'vertical',
            'spacing'    => 'md',
            'paddingAll' => '16px',
            'contents'   => [
                [
                    'type'   => 'text',
                    'text'   => $title,
                    'weight' => 'bold',
                    'size'   => 'md',
                    'wrap'   => true,
                    'color'  => '#0f172a'
                ],
                [
                    'type'  => 'separator',
                    'color' => '#e2e8f0'
                ],
                [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'spacing'  => 'sm',
                    'contents' => [
                        [
                            'type'     => 'box',
                            'layout'   => 'baseline',
                            'spacing'  => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '🗓️ 日程', 'color' => '#64748b', 'size' => 'xs', 'flex' => 2],
                                ['type' => 'text', 'text' => $date_str, 'color' => '#1e293b', 'size' => 'xs', 'flex' => 6, 'weight' => 'bold', 'wrap' => true]
                            ]
                        ],
                        [
                            'type'     => 'box',
                            'layout'   => 'baseline',
                            'spacing'  => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '👥 対象', 'color' => '#64748b', 'size' => 'xs', 'flex' => 2],
                                ['type' => 'text', 'text' => $dept_display, 'color' => '#1e293b', 'size' => 'xs', 'flex' => 6, 'wrap' => true]
                            ]
                        ]
                    ]
                ],
                [
                    'type'            => 'box',
                    'layout'          => 'vertical',
                    'backgroundColor' => '#fef2f2',
                    'cornerRadius'    => '8px',
                    'paddingAll'      => '10px',
                    'borderWidth'     => '1px',
                    'borderColor'     => '#fecdd3',
                    'contents'        => [
                        [
                            'type'   => 'text',
                            'text'   => '⚠️ 概要・注意事項',
                            'color'  => '#dc2626',
                            'size'   => 'xxs',
                            'weight' => 'bold'
                        ],
                        [
                            'type'   => 'text',
                            'text'   => $notice_text,
                            'color'  => '#991b1b',
                            'size'   => 'xs',
                            'wrap'   => true,
                            'weight' => 'bold',
                            'margin' => 'xs'
                        ]
                    ]
                ]
            ]
        ],
        'footer' => [
            'type'            => 'box',
            'layout'          => 'vertical',
            'spacing'         => 'sm',
            'paddingAll'      => '14px',
            'backgroundColor' => '#f8fafc',
            'contents'        => [
                [
                    'type'   => 'button',
                    'style'  => 'primary',
                    'color'  => '#16a34a',
                    'height' => 'sm',
                    'action' => [
                        'type'        => 'postback',
                        'label'       => '👍 了解しました',
                        'data'        => "action=ack&post_id={$post_id}&status=ok",
                        'displayText' => '👍 了解しました'
                    ]
                ],
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'spacing'  => 'sm',
                    'contents' => [
                        [
                            'type'   => 'button',
                            'style'  => 'secondary',
                            'height' => 'sm',
                            'action' => [
                                'type'        => 'postback',
                                'label'       => '❓ 質問・確認',
                                'data'        => "action=ack&post_id={$post_id}&status=question",
                                'displayText' => '❓ 質問・確認があります'
                            ]
                        ],
                        [
                            'type'   => 'button',
                            'style'  => 'secondary',
                            'height' => 'sm',
                            'action' => [
                                'type'        => 'postback',
                                'label'       => '⚠️ 不在・不可',
                                'data'        => "action=ack&post_id={$post_id}&status=absence",
                                'displayText' => '⚠️ 当日は不在・対応できません'
                            ]
                        ]
                    ]
                ],
                [
                    'type'   => 'button',
                    'style'  => 'link',
                    'height' => 'sm',
                    'action' => [
                        'type'  => 'uri',
                        'label' => '📄 かわら版で詳細を開く',
                        'uri'   => $view_url
                    ]
                ]
            ]
        ]
    ];

    return $bubble;
}

/**
 * 指定スタッフへ LINE Flex Message を送信する関数
 * 
 * @param PDO       $pdo              DB接続
 * @param int|array $staff_ids        対象の staff_id（単一または配列）
 * @param string    $alt_text         通知バーに表示される代替テキスト（例: "【伝達】新館2F トイレ換気扇交換"）
 * @param array     $bubble_container Flex Message の bubble 配列
 * @return array                      ['success' => bool, 'sent_count' => int, 'unregistered_count' => int, 'errors' => array]
 */
function sendLineFlexMessage($pdo, $staff_ids, $alt_text, $bubble_container) {
    if (!is_array($staff_ids)) {
        $staff_ids = [$staff_ids];
    }
    $staff_ids = array_unique(array_map('intval', $staff_ids));
    if (empty($staff_ids)) {
        return ['success' => false, 'sent_count' => 0, 'unregistered_count' => 0, 'errors' => ['送信対象が指定されていません']];
    }

    // 1. 対象スタッフの line_user_id を取得
    $in_clause = implode(',', array_fill(0, count($staff_ids), '?'));
    $stmt = $pdo->prepare("SELECT staff_id, staff_name, line_user_id FROM staff WHERE staff_id IN ({$in_clause}) AND is_deleted = FALSE");
    $stmt->execute($staff_ids);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $target_tokens = [];
    $unregistered_count = 0;

    foreach ($members as $m) {
        $token = trim($m['line_user_id'] ?? '');
        if (!empty($token)) {
            $target_tokens[] = $token;
        } else {
            $unregistered_count++;
        }
    }

    if (empty($target_tokens)) {
        return [
            'success'            => false,
            'sent_count'         => 0,
            'unregistered_count' => $unregistered_count,
            'errors'             => ['対象スタッフのLINE連携が完了していません。']
        ];
    }

    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_LINE_CHANNEL_ACCESS_TOKEN_HERE' || empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        return [
            'success'            => false,
            'sent_count'         => 0,
            'unregistered_count' => $unregistered_count,
            'errors'             => ['LINEアクセストークンが未設定です。']
        ];
    }

    $url = 'https://api.line.me/v2/bot/message/multicast';
    $chunked_tokens = array_chunk($target_tokens, 500);
    $sent_count = 0;
    $errors = [];

    foreach ($chunked_tokens as $tokens) {
        $post_data = [
            'to'       => $tokens,
            'messages' => [
                [
                    'type'     => 'flex',
                    'altText'  => mb_substr($alt_text, 0, 400),
                    'contents' => $bubble_container
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        if ($http_code === 200) {
            $sent_count += count($tokens);
        } else {
            $errors[] = "LINE Flex API Error (HTTP {$http_code}): {$response}";
        }
    }

    return [
        'success'            => ($sent_count > 0),
        'sent_count'         => $sent_count,
        'unregistered_count' => $unregistered_count,
        'errors'             => $errors
    ];
}

/**
 * LINE Messaging API の Reply API を呼び出して返信する関数
 */
function sendLineReply($reply_token, $message_text) {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_LINE_CHANNEL_ACCESS_TOKEN_HERE' || empty(LINE_CHANNEL_ACCESS_TOKEN) || empty($reply_token)) {
        return false;
    }
    $url = 'https://api.line.me/v2/bot/message/reply';
    $post_data = [
        'replyToken' => $reply_token,
        'messages'   => [
            [
                'type' => 'text',
                'text' => $message_text
            ]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);
    return ($http_code === 200);
}

/**
 * スタッフのLINE連携用ワンタイム数字コード（4桁）を発行
 */
function generateLineLinkCode($pdo, $staff_id) {
    $staff_id = (int)$staff_id;
    // 過去の未使用コードを無効化
    $pdo->prepare("UPDATE line_link_codes SET is_used = TRUE WHERE staff_id = :sid AND is_used = FALSE")
        ->execute([':sid' => $staff_id]);

    // 重複しない4桁の数字コードを生成
    do {
        $code = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM line_link_codes WHERE link_code = :c AND expires_at > NOW() AND is_used = FALSE");
        $stmt_check->execute([':c' => $code]);
    } while ($stmt_check->fetchColumn() > 0);

    $stmt_insert = $pdo->prepare("
        INSERT INTO line_link_codes (staff_id, link_code, created_at, expires_at, is_used)
        VALUES (:sid, :code, NOW(), NOW() + INTERVAL '20 minutes', FALSE)
        RETURNING code_id, link_code, expires_at
    ");
    $stmt_insert->execute([':sid' => $staff_id, ':code' => $code]);
    return $stmt_insert->fetch(PDO::FETCH_ASSOC);
}

/**
 * トークから受信したコードを照合し、スタッフとLINEユーザーIDを紐付け
 */
function verifyAndLinkStaffByCode($pdo, $line_user_id, $code) {
    $code = trim($code);
    $line_user_id = trim($line_user_id);
    if (empty($code) || empty($line_user_id)) {
        return ['success' => false, 'message' => 'コードまたはLINEユーザーIDが不正です。'];
    }

    $stmt = $pdo->prepare("
        SELECT c.*, s.staff_name, s.role 
        FROM line_link_codes c
        JOIN staff s ON c.staff_id = s.staff_id
        WHERE c.link_code = :c AND c.expires_at > NOW() AND c.is_used = FALSE
        ORDER BY c.code_id DESC LIMIT 1
    ");
    $stmt->execute([':c' => $code]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        return ['success' => false, 'message' => '入力された連携コードが見つからないか、有効期限（20分）が切れています。かわら版画面で再発行してください。'];
    }

    $sid = (int)$rec['staff_id'];
    // 他のスタッフに同じLINE IDが設定されていればクリア（重複防止）
    $pdo->prepare("UPDATE staff SET line_user_id = NULL WHERE line_user_id = :uid AND staff_id != :sid")
        ->execute([':uid' => $line_user_id, ':sid' => $sid]);

    // スタッフのline_user_idを更新
    $pdo->prepare("UPDATE staff SET line_user_id = :uid, updated_at = NOW() WHERE staff_id = :sid")
        ->execute([':uid' => $line_user_id, ':sid' => $sid]);

    // コードを使用済みに更新
    $pdo->prepare("UPDATE line_link_codes SET is_used = TRUE WHERE code_id = :cid")
        ->execute([':cid' => $rec['code_id']]);

    return [
        'success'    => true,
        'staff_id'   => $sid,
        'staff_name' => $rec['staff_name'],
        'role'       => $rec['role']
    ];
}

/**
 * スタッフのLINE連携を解除
 */
function unlinkStaffLine($pdo, $staff_id) {
    $stmt = $pdo->prepare("UPDATE staff SET line_user_id = NULL, updated_at = NOW() WHERE staff_id = :sid");
    $stmt->execute([':sid' => (int)$staff_id]);
    return true;
}

/**
 * Cloudflareトンネルの稼働状況およびURLを高速取得する
 * 
 * @return array ['is_online' => bool, 'url' => string, 'webhook_url' => string, 'checked_at' => string]
 */
function getLineTunnelStatus() {
    $is_online = false;
    $url = '';
    
    // cloudflaredのメトリクスポート（127.0.0.1:20241）をタイムアウト0.15秒で高速チェック
    $fp = @fsockopen('127.0.0.1', 20241, $errno, $errstr, 0.15);
    if ($fp) {
        $is_online = true;
        fclose($fp);
    }

    if ($is_online) {
        // ログファイルから直近のtrycloudflare URLを探索
        $possible_logs = [
            'C:/Users/Owner/.gemini/antigravity-ide/brain/2a6cb00d-b8f4-4fcf-8abe-5c28581fc417/scratch/tunnel_quick.log',
            'C:/Users/Owner/.gemini/antigravity-ide/brain/2a6cb00d-b8f4-4fcf-8abe-5c28581fc417/scratch/tunnel.log'
        ];
        foreach ($possible_logs as $log_path) {
            if (file_exists($log_path)) {
                $content = @file_get_contents($log_path);
                if ($content && preg_match_all('/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/', $content, $matches)) {
                    $urls = $matches[0];
                    $url = end($urls); // 最も最新のURLを取得
                    break;
                }
            }
        }
    }

    return [
        'is_online'   => $is_online,
        'url'         => $url,
        'webhook_url' => $url ? $url . '/kawara/api/line_webhook.php' : '',
        'checked_at'  => date('H:i:s')
    ];
}