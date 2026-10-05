<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>かわら版 かんたん使い方ガイド</title>
    <style>
        :root { --primary-color: #005a9c; --bg-color: #f4f6f9; }
        body { font-family: "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif; background: var(--bg-color); color: #333; margin: 0; padding: 20px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--primary-color); padding-bottom: 15px; margin-bottom: 25px; }
        h1 { font-size: 1.4rem; color: var(--primary-color); margin: 0; }
        .btn-back { background: #6c757d; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: bold; font-size: 0.9rem; }
        .btn-print { background: #005a9c; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-weight: bold; font-size: 0.9rem; cursor: pointer; margin-right: 8px; }

        /* ガイドカード */
        .guide-box { background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 10px; padding: 20px; margin-bottom: 25px; }
        .guide-box.green { border-color: #a3e6cd; background: #f0fdf4; }
        .guide-box.sub { background: #fff; border-color: #dee2e6; }
        
        .box-title { font-size: 1.15rem; font-weight: bold; color: #005a9c; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

        /* 模擬画面イメージの枠 */
        .mock-screen {
            background: #fff;
            border: 2px dashed #bbb;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            font-size: 0.9rem;
        }
        .mock-title { font-weight: bold; color: #2c3e50; margin-bottom: 8px; font-size: 0.95rem; }
        .mock-text { color: #555; font-size: 0.85rem; margin-bottom: 10px; background: #fafafa; padding: 8px; border-radius: 4px; }

        /* バッジの模倣 */
        .badge { display: inline-flex; align-items: center; gap: 2px; padding: 2px 7px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; line-height: 1.2; }
        .badge-urgent { background-color: #dc3545; color: #ffffff; }
        .badge-24h { background-color: #fff9db; color: #856404; border: 1px solid #f1c40f; }
        .badge-unread { background-color: #f3e8ff; color: #6b21a8; border: 1px solid #9333ea; }
        .badge-read { background-color: #e0f2fe; color: #0369a1; border: 1px solid #0284c7; }
        .badge-new { background-color: #e74c3c; color: #ffffff; padding: 1px 5px; border-radius: 3px; font-size: 0.68rem; }
        .badge-pinned { background-color: #343a40; color: #ffffff; }

        /* ボタンモック */
        .btn-mock-read { background: #28a745; color: white; padding: 8px 16px; border-radius: 4px; font-weight: bold; font-size: 0.85rem; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn-mock-unread { background: #6c757d; color: white; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 0.78rem; display: inline-block; }
        
        .btn-tag { display: inline-block; background: #eef2f5; border: 1px solid #ced4da; padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; color: #495057; }
        .btn-tag.active { background: #005a9c; color: #fff; border-color: #005a9c; }

        .point-text { font-size: 0.88rem; color: #444; margin-top: 8px; }

        @media print {
            body { background: #fff; padding: 0; }
            .container { box-shadow: none; border: none; max-width: 100%; }
            .btn-back, .btn-print { display: none; }
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>📖 かわら版 かんたん使い方ガイド</h1>
        <div>
            <button type="button" class="btn-print" onclick="window.print()">🖨️ ガイドを印刷する</button>
            <a href="index.php" class="btn-back">← かわら版に戻る</a>
        </div>
    </header>

    <p style="font-size:0.95rem; color:#555; margin-bottom:25px;">
        院内かわら版は、病院からの連絡事項を確認し、読んだらボタンを押すだけのシンプルな掲示板です。操作はたったの2ステップです。
    </p>

    <!-- 基本の使い方 -->
    <div class="guide-box green">
        <div class="box-title">
            <span>✅ 基本の操作：お便りを読んでボタンを押すだけ</span>
        </div>
        
        <p style="margin:0 0 10px 0;">画面に流れてくる連絡事項を確認したら、記事の下にあるボタンを押してください。</p>

        <!-- 画面イメージ：未読状態 -->
        <div class="mock-screen">
            <div style="font-size:0.75rem; color:#666; margin-bottom:6px; display:flex; gap:6px; align-items:center;">
                <span class="badge badge-unread">🟣 未読</span>
                <span class="badge badge-new">NEW</span>
                <span style="background:#005a9c; color:#fff; padding:2px 6px; border-radius:4px; font-size:0.75rem;">🔧 工事・点検・作業</span>
            </div>
            <div class="mock-title">📌 1F内視鏡室ネットワークメンテナンスのお知らせ</div>
            <div class="mock-text">明日の朝9時より1F内視鏡室のLANスイッチ交換作業を実施します...</div>

            <div style="background:#f8f9fa; border:1px solid #e9ecef; padding:10px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span class="btn-mock-read">内容を確認しました（既読を付ける）</span>
                <span style="font-size:0.78rem; color:#0f5132;">☑ 自分のLINEにメモを送る（任意）</span>
            </div>
        </div>

        <div class="point-text">
            👉 <strong>「内容を確認しました」ボタンを押すと、既読（完了）になります。</strong><br>
            ※右上のチェックが入っていると、スマホのLINEにも控えのメモが届きます（不要な場合はチェックを外してもOKです）。
        </div>
    </div>

    <!-- 🟢 記事の前につくバッジ（マーク）の意味 -->
    <div class="guide-box sub">
        <div class="box-title" style="font-size: 1rem; color: #495057;">
            <span>🏷️ 記事の頭についているマーク（バッジ）の意味</span>
        </div>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 12px;">記事の左上に表示される色付きラベルの解説です。</p>

        <ul style="font-size: 0.88rem; color: #444; padding-left: 20px; line-height: 1.8;">
            <li>
                <span class="badge badge-unread">🟣 未読</span> / <span class="badge badge-read">🩵 既読</span>：<br>
                <span style="color:#666;">あなたがまだ確認していない記事は「🟣未読」、確認済みの記事は「🩵既読」になります。</span>
            </li>
            <li style="margin-top: 6px;">
                <span class="badge badge-new">NEW</span>：<br>
                <span style="color:#666;">新しく投稿されてから24時間以内の記事に赤色でつきます。</span>
            </li>
            <li style="margin-top: 6px;">
                <span class="badge badge-urgent">🚨 直近/緊急</span> / <span class="badge badge-24h">⏰ 24時間以内</span>：<br>
                <span style="color:#666;">すぐに対応や確認が必要な重要・緊急の予定や、明日までに迫った予定に表示されます。</span>
            </li>
            <li style="margin-top: 6px;">
                <span class="badge badge-pinned">📌 固定</span>：<br>
                <span style="color:#666;">常に一番上に目立つようにピン留めされている重要なお知らせです。</span>
            </li>
            <li style="margin-top: 6px;">
                <span style="background:#005a9c; color:#fff; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:bold;">🔧 工事・点検・作業</span> などのカテゴリタグ：<br>
                <span style="color:#666;">その連絡が何の話題（工事、一般連絡、研修、緊急など）に属しているかを示しています。</span>
            </li>
        </ul>
    </div>

    <!-- 間違えて押したとき / よくある質問 -->
    <div class="guide-box">
        <div class="box-title">
            <span>❓ よくあるご質問</span>
        </div>

        <div style="margin-bottom: 15px;">
            <strong>Q. 間違えて「確認しました」を押してしまいました</strong>
            <div style="font-size: 0.88rem; color: #555; margin-top: 3px;">
                押したあとに記事の下へ現れる <span class="btn-mock-unread">↩️ 未読に戻す</span> ボタンを押せば、元の未読状態に戻せますのでご安心ください。
            </div>
        </div>

        <div>
            <strong>Q. ログイン画面に戻ってしまいました</strong>
            <div style="font-size: 0.88rem; color: #555; margin-top: 3px;">
                セキュリティのため、一定時間操作しないと自動でログアウトします。お手数ですが、再度お名前を選んでログインしてください。
            </div>
        </div>
    </div>

    <!-- トップ画面のボタン解説 -->
    <div class="guide-box sub">
        <div class="box-title" style="font-size: 1rem; color: #495057;">
            <span>💡 画面の上にあるボタンの見方（おまけ）</span>
        </div>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 15px;">トップ画面の上部にあるボタンで、表示を切り替えたり絞り込んだりできます。</p>

        <ul style="font-size: 0.88rem; color: #444; padding-left: 20px; line-height: 1.8;">
            <li>
                <span class="btn-tag active">今日</span> <span class="btn-tag">明日</span> <span class="btn-tag">+7日</span> 等のボタン：<br>
                <span style="color:#666;">その期間に予定されているイベントや連絡だけに絞って表示します（「全期間」に戻せば全部見られます）。</span>
            </li>
            <li style="margin-top: 8px;">
                <span style="background:#005a9c; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.78rem; font-weight:bold;">📄 1行コンパクト表示</span> ボタン：<br>
                <span style="color:#666;">記事をギュッと縮めて、タイトルだけの一覧にして素早く見たいときに使います。もう一度押すと元の表示に戻ります。</span>
            </li>
            <li style="margin-top: 8px;">
                <span style="background:#6c757d; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.78rem; font-weight:bold;">🖨️ 一覧印刷</span> ボタン：<br>
                <span style="color:#666;">今画面に出ているお知らせ一覧を、紙にそのまま印刷できます。</span>
            </li>
            <li style="margin-top: 8px;">
                <span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.78rem; font-weight:bold;">🛡️ 連絡網・安否</span> ボタン：<br>
                <span style="color:#666;">職員連絡網の確認や、本日の生存・安否チェック（無事・出勤可など）を1クリックで報告・確認できるページを開きます。</span>
            </li>
        </ul>
    </div>

</div>

</body>
</html>