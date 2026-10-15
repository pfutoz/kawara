<?php
/**
 * 院内かわら版 - イベント・スケジュール一覧API
 * エンドポイント: /kawara/api/events.php
 * 
 * 指定期間内の行事・イベント・予定を日付ごとに展開して提供（カレンダー・サイネージ用）
 * 
 * パラメータ:
 *   start / start_date: 開始日 (YYYY-MM-DD, デフォルト: 今月1日)
 *   end   / end_date  : 終了日 (YYYY-MM-DD, デフォルト: 来月末日)
 *   category          : カテゴリーIDまたはコードによる絞り込み
 */

$_GET['action'] = 'events';
require __DIR__ . '/posts.php';
