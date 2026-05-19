CREATE TABLE IF NOT EXISTS houses_watch_notifications_inbox (
    watch_notification_id integer NOT NULL,
    house_subscriber_id integer NOT NULL,
    created_at integer NOT NULL DEFAULT 0,
    PRIMARY KEY (watch_notification_id, house_subscriber_id)
);

CREATE INDEX IF NOT EXISTS houses_watch_notifications_inbox_subscriber_idx
    ON houses_watch_notifications_inbox (house_subscriber_id);

UPDATE core_vars SET var_value = '97' WHERE var_name = 'dbVersion';
