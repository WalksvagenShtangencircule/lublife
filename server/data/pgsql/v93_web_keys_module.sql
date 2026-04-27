-- Модуль WebView «Ключи»: whitelist RFID и журнал принятия оферт
-- Версия схемы: 93

CREATE TABLE IF NOT EXISTS vendor_rfids_whitelist (
    rfid varchar(32) NOT NULL PRIMARY KEY,
    created_at integer NOT NULL DEFAULT (EXTRACT(EPOCH FROM NOW())::integer)
);

COMMENT ON TABLE vendor_rfids_whitelist IS 'RFID, разрешённые для добавления жильцом в квартиру (наши ключи)';

CREATE TABLE IF NOT EXISTS mobile_legal_acceptances (
    id bigserial PRIMARY KEY,
    house_subscriber_id bigint NOT NULL,
    house_flat_id bigint,
    action varchar(32) NOT NULL DEFAULT 'join_flat',
    terms_version varchar(16) NOT NULL,
    privacy_version varchar(16) NOT NULL,
    accepted_at integer NOT NULL
);

CREATE INDEX IF NOT EXISTS mobile_legal_acceptances_subscriber_idx
    ON mobile_legal_acceptances (house_subscriber_id);

COMMENT ON TABLE mobile_legal_acceptances IS 'Фиксация согласия с офертой при действиях из мобильного WebView';

UPDATE core_vars SET var_value = '93' WHERE var_name = 'dbVersion';
