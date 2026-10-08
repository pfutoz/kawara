-- ====================================================================
-- 本番環境（192.168.1.16）マイグレーションSQL
-- 院内かわら版：Googleカレンダーマルチ連携 ＆ ダッシュボードメモ ＆ 安否区分
-- 作成日: 2026-10-08
-- ====================================================================

BEGIN;

-- 1. safety_events テーブルに safety_mode カラムを追加（未追加の場合のみ）
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'public' 
          AND table_name = 'safety_events' 
          AND column_name = 'safety_mode'
    ) THEN
        ALTER TABLE safety_events ADD COLUMN safety_mode VARCHAR(20) DEFAULT 'normal';
    END IF;
END $$;

-- 2. dashboard_notes テーブルの作成（重点目標・日付メモ機能）
CREATE TABLE IF NOT EXISTS dashboard_notes (
    note_id SERIAL PRIMARY KEY,
    target_type VARCHAR(10) NOT NULL, -- 'month' または 'date'
    target_key VARCHAR(20) NOT NULL,  -- 'YYYY-MM' または 'YYYY-MM-DD'
    content TEXT NOT NULL,
    color_theme VARCHAR(20) DEFAULT '#fef08a',
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    CONSTRAINT uq_target UNIQUE (target_type, target_key)
);
CREATE INDEX IF NOT EXISTS idx_dashboard_notes_target ON dashboard_notes (target_type, target_key);

-- 3. google_calendar_channels テーブルの作成（Googleカレンダー連携チャンネル）
CREATE TABLE IF NOT EXISTS google_calendar_channels (
    channel_id SERIAL PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    calendar_id VARCHAR(255) NOT NULL,
    calendar_name VARCHAR(100) NOT NULL,
    color_theme VARCHAR(30) DEFAULT '#1a73e8',
    is_enabled BOOLEAN DEFAULT true,
    display_order INTEGER DEFAULT 1,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. google_calendar_events_cache テーブルの作成（Google予定キャッシュ）
CREATE TABLE IF NOT EXISTS google_calendar_events_cache (
    event_id VARCHAR(255) PRIMARY KEY,
    channel_id INTEGER REFERENCES google_calendar_channels(channel_id) ON DELETE CASCADE,
    calendar_id VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(255),
    start_datetime TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    end_datetime TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    is_all_day BOOLEAN DEFAULT false,
    html_link TEXT,
    status VARCHAR(50) DEFAULT 'confirmed',
    synced_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_gcal_cache_time ON google_calendar_events_cache (start_datetime, end_datetime);

COMMIT;
