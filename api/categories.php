<?php
/**
 * 院内かわら版 - カテゴリー一覧API
 * エンドポイント: /kawara/api/categories.php
 * 
 * 記事カテゴリー一覧、表示順、アイコン、テーマ色、掲載中記事件数を提供
 */

$_GET['action'] = 'categories';
require __DIR__ . '/posts.php';
