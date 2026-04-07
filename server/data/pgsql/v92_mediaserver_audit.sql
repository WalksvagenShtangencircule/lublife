CREATE TABLE IF NOT EXISTS mediaserver_audit (
    id BIGSERIAL PRIMARY KEY,
    created_at BIGINT NOT NULL,
    login CHARACTER VARYING(255) NOT NULL,
    action CHARACTER VARYING(64) NOT NULL,
    stream_name CHARACTER VARYING(512),
    camera_id INTEGER,
    details TEXT
);

CREATE INDEX IF NOT EXISTS mediaserver_audit_created_idx ON mediaserver_audit (created_at DESC);
CREATE INDEX IF NOT EXISTS mediaserver_audit_login_idx ON mediaserver_audit (login);
