CREATE TABLE IF NOT EXISTS houses_watch_notifications (
    watch_notification_id serial PRIMARY KEY,
    plog_door_open_id integer NOT NULL,
    event_date integer NOT NULL,
    ip character varying,
    sub_id character varying,
    door integer NOT NULL,
    event_type integer NOT NULL,
    event_detail character varying NOT NULL,
    status character varying NOT NULL DEFAULT 'pending',
    attempt_count integer NOT NULL DEFAULT 0,
    available_at integer NOT NULL DEFAULT 0,
    processing_started_at integer,
    sent_at integer,
    created_at integer NOT NULL DEFAULT 0,
    updated_at integer NOT NULL DEFAULT 0,
    last_error text
);

CREATE UNIQUE INDEX IF NOT EXISTS houses_watch_notifications_plog_door_open_id_uniq
    ON houses_watch_notifications (plog_door_open_id);

CREATE INDEX IF NOT EXISTS houses_watch_notifications_status_available_idx
    ON houses_watch_notifications (status, available_at);

CREATE INDEX IF NOT EXISTS houses_watch_notifications_event_idx
    ON houses_watch_notifications (event_type, event_detail);

CREATE TABLE IF NOT EXISTS houses_watch_notifications_deliveries (
    watch_notification_id integer NOT NULL,
    subscriber_device_id integer NOT NULL,
    sent_at integer NOT NULL DEFAULT 0,
    PRIMARY KEY (watch_notification_id, subscriber_device_id)
);

CREATE INDEX IF NOT EXISTS houses_watch_notifications_deliveries_device_idx
    ON houses_watch_notifications_deliveries (subscriber_device_id);

UPDATE core_vars SET var_value = '96' WHERE var_name = 'dbVersion';
