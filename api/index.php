<?php
/**
 * 院内かわら版 - 外部連携API ドキュメント ＆ ライブテスター
 * エンドポイント: /kawara/api/
 * 
 * ブラウザアクセス時は美しいインタラクティブ仕様書＆テスターを表示し、
 * API/JSONクライアントアクセス時はルートAPIディスカバリーJSONを返却します。
 */

require_once __DIR__ . '/../includes/api_helper.php';

$base_url = get_api_base_url();
$api_base = $base_url . '/api';

// JSONリクエスト判定
$is_json_request = (isset($_GET['format']) && $_GET['format'] === 'json')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_json_request) {
    init_api_headers();
    send_api_response([
        'system'      => '院内かわら版 外部連携API (Kawara External API)',
        'version'     => '1.0.0',
        'status'      => 'online',
        'timestamp'   => date('c'),
        'base_url'    => $api_base,
        'endpoints'   => [
            'posts_list'    => [
                'method'      => 'GET',
                'path'        => '/posts.php',
                'description' => '記事一覧の取得、キーワード検索、カテゴリ・日付フィルタリング',
                'url'         => "{$api_base}/posts.php"
            ],
            'post_detail'   => [
                'method'      => 'GET',
                'path'        => '/posts.php?id={post_id}',
                'description' => '指定した記事の本文・画像・対象部署・既読リアクション集計の取得',
                'url'         => "{$api_base}/posts.php?id=21"
            ],
            'categories'    => [
                'method'      => 'GET',
                'path'        => '/categories.php',
                'description' => 'カテゴリー一覧（アイコン・テーマ色・掲載中件数）の取得',
                'url'         => "{$api_base}/categories.php"
            ],
            'events'        => [
                'method'      => 'GET',
                'path'        => '/events.php',
                'description' => 'カレンダー・サイネージ用イベント展開リストの取得',
                'url'         => "{$api_base}/events.php"
            ],
            'stats'         => [
                'method'      => 'GET',
                'path'        => '/posts.php?action=stats',
                'description' => 'システム統計・掲載中件数・本日の予定件数・未読件数の取得',
                'url'         => "{$api_base}/posts.php?action=stats"
            ],
            'disaster'      => [
                'method'      => 'GET',
                'path'        => '/disaster_status.php',
                'description' => '緊急災害モード発令ステータスおよび安否確認集計の取得',
                'url'         => "{$api_base}/disaster_status.php"
            ],
            'widget'        => [
                'method'      => 'GET',
                'path'        => '/widget.php',
                'description' => '外部システム埋め込み用 <iframe> ウィジェットUI',
                'url'         => "{$api_base}/widget.php"
            ],
            'embed_script'  => [
                'method'      => 'GET',
                'path'        => '/embed.js',
                'description' => '外部Webページ埋め込み用 Vanilla JavaScript ライブラリ',
                'url'         => "{$api_base}/embed.js"
            ]
        ]
    ]);
}

// カテゴリーと最新記事サンプルの取得（ドキュメント画面用）
$categories = $pdo->query("SELECT * FROM post_categories WHERE is_active = TRUE ORDER BY display_order")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>院内かわら版 外部連携API 仕様書 ＆ ライブテスター</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #003d6b;
            --primary-light: #e0f2fe;
            --accent: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-hover: #334155;
            --border: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --code-bg: #090d16;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ヘッダー */
        header {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.95) 100%);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .header-icon {
            font-size: 1.8rem;
            filter: drop-shadow(0 2px 8px rgba(14, 165, 233, 0.4));
        }

        .header-title-box h1 {
            font-family: 'Outfit', 'Noto Sans JP', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            background: linear-gradient(90deg, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-title-box p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .header-badge {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .header-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px #10b981;
        }

        .header-nav {
            display: flex;
            gap: 10px;
        }
        .header-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 14px;
            border-radius: 6px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .header-nav a:hover {
            color: var(--text-main);
            background: var(--surface);
            border-color: var(--border);
        }

        /* コンテナ */
        .layout {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
        }

        /* サイドバー */
        .sidebar {
            position: sticky;
            top: 5.5rem;
            max-height: calc(100vh - 7rem);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .side-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
        }

        .side-card-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .side-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .side-menu a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 0.85rem;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .side-menu a:hover {
            background: var(--surface-hover);
        }
        .side-menu a.active {
            background: rgba(14, 165, 233, 0.15);
            color: #38bdf8;
            border-left: 3px solid #38bdf8;
        }

        .method-badge {
            font-family: 'Outfit', sans-serif;
            font-size: 0.68rem;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            letter-spacing: 0.03em;
        }
        .method-get { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .method-js { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .method-ui { background: rgba(139, 92, 246, 0.2); color: #c084fc; }

        .base-url-box {
            background: var(--code-bg);
            border: 1px solid var(--border);
            padding: 8px 12px;
            border-radius: 6px;
            font-family: 'Fira Code', monospace;
            font-size: 0.78rem;
            color: #38bdf8;
            word-break: break-all;
            margin-top: 6px;
        }

        /* メインエリア */
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        /* ヒーローセクション */
        .hero {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.1) 0%, rgba(99, 102, 241, 0.1) 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 16px;
            padding: 1.75rem 2rem;
            position: relative;
            overflow: hidden;
        }
        .hero h2 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: #fff;
        }
        .hero p {
            color: var(--text-muted);
            font-size: 0.92rem;
            max-width: 800px;
            line-height: 1.7;
        }
        .hero-features {
            display: flex;
            gap: 1.5rem;
            margin-top: 1.25rem;
            flex-wrap: wrap;
        }
        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 0.8rem;
            color: #e2e8f0;
        }

        /* セクションカード */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.75rem;
            scroll-margin-top: 6rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .endpoint-pill {
            font-family: 'Fira Code', monospace;
            background: var(--code-bg);
            border: 1px solid var(--border);
            color: #38bdf8;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
        }

        .section-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1.25rem;
            line-height: 1.6;
        }

        /* パラメータテーブル */
        .param-table-wrap {
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

        .param-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
            text-align: left;
        }

        .param-table th {
            background: rgba(15, 23, 42, 0.7);
            color: var(--text-muted);
            padding: 10px 14px;
            font-weight: 600;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .param-table td {
            padding: 10px 14px;
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
            vertical-align: top;
        }

        .param-name {
            font-family: 'Fira Code', monospace;
            color: #38bdf8;
            font-weight: 600;
        }

        .param-type {
            font-family: 'Fira Code', monospace;
            color: #a78bfa;
            font-size: 0.76rem;
        }

        .param-badge {
            display: inline-block;
            font-size: 0.68rem;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 600;
        }
        .param-opt { background: rgba(100, 116, 139, 0.2); color: #94a3b8; }
        .param-req { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        /* ライブテスターボックス */
        .tester-box {
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            margin-top: 1.25rem;
        }

        .tester-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .tester-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tester-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-group label {
            font-size: 0.74rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        .form-control {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-family: inherit;
            outline: none;
            transition: border 0.15s;
        }
        .form-control:focus {
            border-color: #38bdf8;
        }

        .btn-run {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.84rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
            box-shadow: 0 2px 8px rgba(14, 165, 233, 0.3);
        }
        .btn-run:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .btn-copy {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.72rem;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-copy:hover {
            color: var(--text-main);
            border-color: #38bdf8;
        }

        /* レスポンスプレビュー */
        .response-container {
            margin-top: 1rem;
            background: #040711;
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 8px;
            padding: 12px;
            position: relative;
        }

        .response-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        .status-badge {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .status-200 { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .status-err { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        pre.code-block {
            font-family: 'Fira Code', monospace;
            font-size: 0.78rem;
            line-height: 1.5;
            color: #cbd5e1;
            max-height: 380px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* 埋め込みプレビュー枠 */
        .preview-frame-wrap {
            margin-top: 1rem;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .preview-frame {
            width: 100%;
            height: 360px;
            border: none;
            display: block;
        }

        /* タブ */
        .code-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 8px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 6px;
        }
        .code-tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.76rem;
            cursor: pointer;
            font-weight: 600;
        }
        .code-tab-btn.active {
            background: var(--surface);
            color: #38bdf8;
        }

        .copy-toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: #10b981;
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 0.85rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.2s ease;
            pointer-events: none;
            z-index: 1000;
        }
        .copy-toast.show {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>

<header>
    <a href="#" class="header-logo">
        <span class="header-icon">📰</span>
        <div class="header-title-box">
            <h1>院内かわら版 外部連携API</h1>
            <p>Onokai Kawara-ban External Integration API v1.0</p>
        </div>
    </a>
    <div style="display: flex; align-items: center; gap: 15px;">
        <div class="header-badge">API Online / CORS Ready</div>
        <nav class="header-nav">
            <a href="<?= htmlspecialchars($base_url) ?>/kawara_list.php" target="_blank">かわら版本体へ &rarr;</a>
        </nav>
    </div>
</header>

<div class="layout">
    <!-- サイドバー -->
    <aside class="sidebar">
        <div class="side-card">
            <div class="side-card-title">🌐 API ベースURL</div>
            <div class="base-url-box"><?= htmlspecialchars($api_base) ?></div>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">
                全エンドポイント共通。CORS全開放（Origin: *）対応。
            </p>
        </div>

        <div class="side-card">
            <div class="side-card-title">📑 エンドポイント一覧</div>
            <ul class="side-menu">
                <li><a href="#sec-posts"><span class="method-badge method-get">GET</span> /posts.php</a></li>
                <li><a href="#sec-post-detail"><span class="method-badge method-get">GET</span> /posts.php?id={id}</a></li>
                <li><a href="#sec-categories"><span class="method-badge method-get">GET</span> /categories.php</a></li>
                <li><a href="#sec-events"><span class="method-badge method-get">GET</span> /events.php</a></li>
                <li><a href="#sec-stats"><span class="method-badge method-get">GET</span> /posts.php?action=stats</a></li>
                <li><a href="#sec-disaster"><span class="method-badge method-get">GET</span> /disaster_status.php</a></li>
                <li><a href="#sec-widget"><span class="method-badge method-ui">UI</span> /widget.php</a></li>
                <li><a href="#sec-embed"><span class="method-badge method-js">JS</span> /embed.js</a></li>
            </ul>
        </div>

        <div class="side-card">
            <div class="side-card-title">💡 クイック連携</div>
            <p style="font-size: 0.78rem; color: var(--text-muted); line-height: 1.5;">
                社内ポータルや電子カルテ端末、サイネージモニターに数行で組み込めるUIウィジェット・JSライブラリも完備しています。
            </p>
        </div>
    </aside>

    <!-- メインコンテンツ -->
    <main class="main-content">
        <!-- ヒーロー -->
        <section class="hero">
            <h2>院内情報を自在に外部表示・システム統合</h2>
            <p>
                院内かわら版に投稿されたお知らせ・重要伝達・設備点検・院内研修・緊急災害情報を、他システム（院内ポータルサイト、電子カルテ端末、医局・ナースステーションのサイネージモニター、グループウェア等）へ安全かつリアルタイムに提供するためのRESTful JSON API基盤です。
            </p>
            <div class="hero-features">
                <div class="hero-pill">⚡ リアルタイム即時同期</div>
                <div class="hero-pill">🔓 CORS完全対応 (クロスオリジン)</div>
                <div class="hero-pill">🎨 埋め込みウィジェット完備</div>
                <div class="hero-pill">📅 カレンダー用イベント展開</div>
                <div class="hero-pill">🚨 災害モード即時判定</div>
            </div>
        </section>

        <!-- 1. 記事一覧API -->
        <section id="sec-posts" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-get">GET</span>
                    <span>記事一覧・検索・フィルタリング</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/posts.php</span>
            </div>
            <p class="section-desc">
                掲載中のお知らせ一覧を取得します。カテゴリー、キーワード検索、ピン留め、特定日・期間指定、未読判定（スタッフID指定）など、柔軟なフィルタリングが可能です。
            </p>

            <div class="param-table-wrap">
                <table class="param-table">
                    <thead>
                        <tr>
                            <th>パラメータ</th>
                            <th>型</th>
                            <th>必須</th>
                            <th>説明・設定例</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="param-name">limit</span></td>
                            <td><span class="param-type">integer</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td>取得件数（1〜100、デフォルト: <code>10</code>）</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">page</span></td>
                            <td><span class="param-type">integer</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td>ページ番号（デフォルト: <code>1</code>）</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">filter</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td>
                                <code>active</code> (デフォルト: 掲載中のみ)<br>
                                <code>urgent</code> (超緊急・重要・本日予定)<br>
                                <code>pinned</code> (ピン留め注目記事)<br>
                                <code>today</code> (本日の予定・対象投稿)<br>
                                <code>upcoming</code> (本日以降の投稿)<br>
                                <code>unread</code> (指定スタッフの未読記事)<br>
                                <code>all</code> (過去ログ含む全件)
                            </td>
                        </tr>
                        <tr>
                            <td><span class="param-name">category</span></td>
                            <td><span class="param-type">string/int</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td>カテゴリーID（数値）またはコード（<code>urgent</code>, <code>important</code>, <code>facility</code>, <code>general</code> 等）</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">q / search</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td>タイトル・本文・投稿者名のキーワードあいまい検索</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">staff_id</span></td>
                            <td><span class="param-type">integer</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td>指定したスタッフの既読状況（<code>is_read</code>, <code>response_status</code>）をレスポンスに付加</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">fields</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge param-opt">任意</span></td>
                            <td><code>summary</code> (一覧・テロップ向け軽量) / <code>full</code> (本文全文・対象部署・既読集計含む)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ライブテスター -->
            <div class="tester-box">
                <div class="tester-header">
                    <div class="tester-title">🧪 ライブテスター</div>
                    <button class="btn-copy" onclick="copySnippet('posts-curl')">cURLコピー</button>
                </div>
                <div class="tester-form">
                    <div class="form-group">
                        <label>limit (件数)</label>
                        <input type="number" id="test-posts-limit" class="form-control" value="5" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label>filter (状態)</label>
                        <select id="test-posts-filter" class="form-control">
                            <option value="active" selected>active (掲載中)</option>
                            <option value="urgent">urgent (緊急・重要)</option>
                            <option value="pinned">pinned (ピン留め)</option>
                            <option value="today">today (本日の予定)</option>
                            <option value="all">all (全件)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>category (カテゴリー)</label>
                        <select id="test-posts-cat" class="form-control">
                            <option value="">すべて</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= htmlspecialchars($c['category_code']) ?>"><?= htmlspecialchars($c['icon_emoji']) ?> <?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>q (検索キーワード)</label>
                        <input type="text" id="test-posts-q" class="form-control" placeholder="例: 点検、給湯器...">
                    </div>
                </div>
                <button class="btn-run" onclick="runPostsTest()">
                    <span>▶</span> APIテスト実行
                </button>

                <div id="posts-response-box" class="response-container" style="display: none;">
                    <div class="response-meta">
                        <span>Status: <b id="posts-status" class="status-badge status-200">200 OK</b></span>
                        <span id="posts-time">0 ms</span>
                    </div>
                    <pre id="posts-response-json" class="code-block"></pre>
                </div>
            </div>
        </section>

        <!-- 2. 記事詳細API -->
        <section id="sec-post-detail" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-get">GET</span>
                    <span>記事詳細の取得</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/posts.php?id={id}</span>
            </div>
            <p class="section-desc">
                指定した記事の本文（全文・プレーン・HTMLセーフ）、添付画像一覧、複数日日程（event_schedules）、対象部署、既読・意思表示リアクション（👍了解/❓質問/⚠️不在）集計を取得します。
            </p>

            <div class="tester-box">
                <div class="tester-header">
                    <div class="tester-title">🧪 ライブテスター</div>
                </div>
                <div class="tester-form">
                    <div class="form-group">
                        <label>post_id (記事ID)</label>
                        <input type="number" id="test-detail-id" class="form-control" value="21">
                    </div>
                </div>
                <button class="btn-run" onclick="runDetailTest()">
                    <span>▶</span> 詳細取得テスト
                </button>
                <div id="detail-response-box" class="response-container" style="display: none;">
                    <div class="response-meta">
                        <span>Status: <b id="detail-status" class="status-badge status-200">200 OK</b></span>
                        <span id="detail-time">0 ms</span>
                    </div>
                    <pre id="detail-response-json" class="code-block"></pre>
                </div>
            </div>
        </section>

        <!-- 3. カテゴリー一覧API -->
        <section id="sec-categories" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-get">GET</span>
                    <span>カテゴリー一覧の取得</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/categories.php</span>
            </div>
            <p class="section-desc">
                有効なカテゴリー一覧、表示順、アイコン絵文字、テーマカラーコード、および各カテゴリーの現在掲載中記事件数を取得します。
            </p>
            <div class="tester-box">
                <button class="btn-run" onclick="runCategoriesTest()">
                    <span>▶</span> カテゴリー一覧取得テスト
                </button>
                <div id="cat-response-box" class="response-container" style="display: none;">
                    <div class="response-meta">
                        <span>Status: <b id="cat-status" class="status-badge status-200">200 OK</b></span>
                        <span id="cat-time">0 ms</span>
                    </div>
                    <pre id="cat-response-json" class="code-block"></pre>
                </div>
            </div>
        </section>

        <!-- 4. カレンダー・サイネージ用イベントAPI -->
        <section id="sec-events" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-get">GET</span>
                    <span>カレンダー・サイネージ用イベント展開</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/events.php</span>
            </div>
            <p class="section-desc">
                指定期間内の行事・イベント・点検予定を取得します。複数日にまたがる予定や「第1回、第2回...」などの複数スロットも日付ごとにフラットに展開され、FullCalendarや詰所常設モニター・サイネージですぐに描画できる構造で返却されます。
            </p>

            <div class="tester-box">
                <div class="tester-header">
                    <div class="tester-title">🧪 ライブテスター</div>
                </div>
                <div class="tester-form">
                    <div class="form-group">
                        <label>start / start_date (開始日)</label>
                        <input type="date" id="test-events-start" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="form-group">
                        <label>end / end_date (終了日)</label>
                        <input type="date" id="test-events-end" class="form-control" value="<?= date('Y-m-t', strtotime('+1 month')) ?>">
                    </div>
                </div>
                <button class="btn-run" onclick="runEventsTest()">
                    <span>▶</span> イベント展開テスト
                </button>
                <div id="events-response-box" class="response-container" style="display: none;">
                    <div class="response-meta">
                        <span>Status: <b id="events-status" class="status-badge status-200">200 OK</b></span>
                        <span id="events-time">0 ms</span>
                    </div>
                    <pre id="events-response-json" class="code-block"></pre>
                </div>
            </div>
        </section>

        <!-- 5. 統計API -->
        <section id="sec-stats" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-get">GET</span>
                    <span>ダッシュボード・システム統計</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/posts.php?action=stats</span>
            </div>
            <p class="section-desc">
                掲載中記事総数、緊急・重要記事数、本日の予定数、災害モード状態を取得します。ポータルのヘッダーバッジや通知カウンターに最適です。
            </p>
            <div class="tester-box">
                <button class="btn-run" onclick="runStatsTest()">
                    <span>▶</span> 統計取得テスト
                </button>
                <div id="stats-response-box" class="response-container" style="display: none;">
                    <div class="response-meta">
                        <span>Status: <b id="stats-status" class="status-badge status-200">200 OK</b></span>
                        <span id="stats-time">0 ms</span>
                    </div>
                    <pre id="stats-response-json" class="code-block"></pre>
                </div>
            </div>
        </section>

        <!-- 6. 災害モード判定API -->
        <section id="sec-disaster" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-get">GET</span>
                    <span>緊急災害モード・安否集計ステータス</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/disaster_status.php</span>
            </div>
            <p class="section-desc">
                災害モードの発令状態、イベントタイトル、および本日の全職員生存確認・点呼報告集計（無事/注意/危険/報告率）を取得します。他システムでの非常時バナー表示や安否監視に利用できます。
            </p>
            <div class="tester-box">
                <button class="btn-run" onclick="runDisasterTest()">
                    <span>▶</span> 災害ステータステスト
                </button>
                <div id="disaster-response-box" class="response-container" style="display: none;">
                    <div class="response-meta">
                        <span>Status: <b id="disaster-status" class="status-badge status-200">200 OK</b></span>
                        <span id="disaster-time">0 ms</span>
                    </div>
                    <pre id="disaster-response-json" class="code-block"></pre>
                </div>
            </div>
        </section>

        <!-- 7. 埋め込みウィジェット (UI) -->
        <section id="sec-widget" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-ui">UI</span>
                    <span>iframe埋め込み用ウィジェット</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/widget.php</span>
            </div>
            <p class="section-desc">
                外部のWebシステムやイントラネットポータルに、<code>&lt;iframe&gt;</code> タグ1行でかわら版の最新お知らせを美しく表示できます。
            </p>

            <div class="code-tabs">
                <button class="code-tab-btn active" onclick="switchEmbedCode('iframe')">iframe埋め込みコード</button>
            </div>
            <div style="background: var(--code-bg); padding: 12px; border-radius: 8px; margin-bottom: 1rem; position: relative;">
                <button class="btn-copy" style="position: absolute; right: 10px; top: 10px;" onclick="copyCode('iframe-code')">コピー</button>
                <pre id="iframe-code" class="code-block" style="color: #38bdf8;">&lt;iframe src="<?= htmlspecialchars($api_base) ?>/widget.php?theme=light&amp;limit=5" width="100%" height="360" frameborder="0" style="border: 1px solid #cbd5e1; border-radius: 8px;"&gt;&lt;/iframe&gt;</pre>
            </div>

            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 8px; color: #38bdf8;">
                🖥️ ライブ埋め込みプレビュー (実機表示)
            </div>
            <div class="preview-frame-wrap">
                <iframe src="<?= htmlspecialchars($api_base) ?>/widget.php?theme=light&limit=4" class="preview-frame"></iframe>
            </div>
        </section>

        <!-- 8. JavaScript埋め込み (JS) -->
        <section id="sec-embed" class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <span class="method-badge method-js">JS</span>
                    <span>JavaScript埋め込みライブラリ (embed.js)</span>
                </div>
                <span class="endpoint-pill"><?= htmlspecialchars($api_base) ?>/embed.js</span>
            </div>
            <p class="section-desc">
                外部Webページ内の任意の <code>&lt;div&gt;</code> に最新記事を自動フェッチしてインライン描画する軽量スクリプトです。他サイトのデザインを壊さず自然に溶け込みます。
            </p>

            <div style="background: var(--code-bg); padding: 12px; border-radius: 8px; margin-bottom: 1rem; position: relative;">
                <button class="btn-copy" style="position: absolute; right: 10px; top: 10px;" onclick="copyCode('js-code')">コピー</button>
                <pre id="js-code" class="code-block" style="color: #38bdf8;">&lt;!-- 表示させたい場所に配置 --&gt;
&lt;div id="kawara-news-box"&gt;&lt;/div&gt;

&lt;!-- スクリプトの読み込み（自動描画） --&gt;
&lt;script src="<?= htmlspecialchars($api_base) ?>/embed.js" 
        data-container="#kawara-news-box" 
        data-limit="5" 
        data-filter="active"
        data-theme="light"&gt;&lt;/script&gt;</pre>
            </div>
        </section>
    </main>
</div>

<div id="copy-toast" class="copy-toast">✓ クリップボードにコピーしました</div>

<script>
const API_BASE = <?= json_encode($api_base) ?>;

function showToast(msg) {
    const t = document.getElementById('copy-toast');
    if (msg) t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2000);
}

function copyCode(id) {
    const el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim()).then(() => showToast('✓ コードをコピーしました'));
}

function copySnippet(type) {
    let cmd = '';
    if (type === 'posts-curl') {
        cmd = `curl -X GET "${API_BASE}/posts.php?limit=5&filter=active"`;
    }
    navigator.clipboard.writeText(cmd).then(() => showToast('✓ cURLコマンドをコピーしました'));
}

async function executeApi(url, boxId, statusId, timeId, jsonId) {
    const box = document.getElementById(boxId);
    const statusEl = document.getElementById(statusId);
    const timeEl = document.getElementById(timeId);
    const jsonEl = document.getElementById(jsonId);

    box.style.display = 'block';
    jsonEl.textContent = '読込中...';

    const t0 = performance.now();
    try {
        const res = await fetch(url);
        const t1 = performance.now();
        const duration = Math.round(t1 - t0);

        timeEl.textContent = `${duration} ms`;
        statusEl.textContent = `${res.status} ${res.statusText || 'OK'}`;
        statusEl.className = 'status-badge ' + (res.ok ? 'status-200' : 'status-err');

        const data = await res.json();
        jsonEl.textContent = JSON.stringify(data, null, 2);
    } catch (err) {
        statusEl.textContent = 'FETCH ERROR';
        statusEl.className = 'status-badge status-err';
        jsonEl.textContent = String(err);
    }
}

function runPostsTest() {
    const limit = document.getElementById('test-posts-limit').value || 5;
    const filter = document.getElementById('test-posts-filter').value;
    const cat = document.getElementById('test-posts-cat').value;
    const q = document.getElementById('test-posts-q').value;

    const params = new URLSearchParams();
    params.set('limit', limit);
    if (filter) params.set('filter', filter);
    if (cat) params.set('category', cat);
    if (q) params.set('q', q);

    const url = `${API_BASE}/posts.php?${params.toString()}`;
    executeApi(url, 'posts-response-box', 'posts-status', 'posts-time', 'posts-response-json');
}

function runDetailTest() {
    const id = document.getElementById('test-detail-id').value || 21;
    const url = `${API_BASE}/posts.php?id=${id}&include_comments=1`;
    executeApi(url, 'detail-response-box', 'detail-status', 'detail-time', 'detail-response-json');
}

function runCategoriesTest() {
    const url = `${API_BASE}/categories.php`;
    executeApi(url, 'cat-response-box', 'cat-status', 'cat-time', 'cat-response-json');
}

function runEventsTest() {
    const s = document.getElementById('test-events-start').value;
    const e = document.getElementById('test-events-end').value;
    const url = `${API_BASE}/events.php?start=${s}&end=${e}`;
    executeApi(url, 'events-response-box', 'events-status', 'events-time', 'events-response-json');
}

function runStatsTest() {
    const url = `${API_BASE}/posts.php?action=stats`;
    executeApi(url, 'stats-response-box', 'stats-status', 'stats-time', 'stats-response-json');
}

function runDisasterTest() {
    const url = `${API_BASE}/disaster_status.php`;
    executeApi(url, 'disaster-response-box', 'disaster-status', 'disaster-time', 'disaster-response-json');
}

// 初回自動実行
document.addEventListener('DOMContentLoaded', () => {
    runPostsTest();
});
</script>

</body>
</html>
