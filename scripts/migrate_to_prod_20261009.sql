-- ====================================================================
-- 本番環境（192.168.1.16）マイグレーションSQL
-- 院内かわら版：Googleカレンダー個別かわら版表示トグル（show_in_kawara）
-- 作成日: 2026-10-09
-- ====================================================================

BEGIN;

-- 1. google_calendar_channels テーブルに show_in_kawara カラムを追加（未追加の場合のみ）
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'public' 
          AND table_name = 'google_calendar_channels' 
          AND column_name = 'show_in_kawara'
    ) THEN
        ALTER TABLE google_calendar_channels ADD COLUMN show_in_kawara BOOLEAN DEFAULT true;
    END IF;
END $$;

-- 2. 既存の「個人」カレンダーを初期状態でかわら版非表示に設定
UPDATE google_calendar_channels 
SET show_in_kawara = false 
WHERE (calendar_name LIKE '%個人%' OR account_name LIKE '%個人%')
  AND show_in_kawara IS NOT FALSE;

COMMIT;
