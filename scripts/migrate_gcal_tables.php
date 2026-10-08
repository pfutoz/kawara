<?php
require_once __DIR__ . '/../includes/db.php';

$pdo->exec("
CREATE TABLE IF NOT EXISTS google_calendar_channels (
    channel_id SERIAL PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    calendar_id VARCHAR(255) NOT NULL,
    calendar_name VARCHAR(100) NOT NULL,
    color_theme VARCHAR(30) DEFAULT '#1a73e8',
    is_enabled BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS google_calendar_events_cache (
    event_id VARCHAR(255) PRIMARY KEY,
    channel_id INT REFERENCES google_calendar_channels(channel_id) ON DELETE CASCADE,
    calendar_id VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(255),
    start_datetime TIMESTAMP NOT NULL,
    end_datetime TIMESTAMP NOT NULL,
    is_all_day BOOLEAN DEFAULT FALSE,
    html_link TEXT,
    status VARCHAR(50) DEFAULT 'confirmed',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_gcal_cache_time ON google_calendar_events_cache(start_datetime, end_datetime);
");

echo "Tables created successfully!\n";
