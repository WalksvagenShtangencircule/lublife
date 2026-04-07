CREATE TABLE IF NOT EXISTS mediaserver_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at INTEGER NOT NULL,
    login TEXT NOT NULL,
    action TEXT NOT NULL,
    stream_name TEXT,
    camera_id INTEGER,
    details TEXT
);

CREATE INDEX IF NOT EXISTS mediaserver_audit_created_idx ON mediaserver_audit (created_at DESC);
CREATE INDEX IF NOT EXISTS mediaserver_audit_login_idx ON mediaserver_audit (login);
