-- Ключи, проданные абоненту (подтверждение покупки у оператора)
-- Версия схемы: 94

CREATE TABLE IF NOT EXISTS subscriber_purchased_rfids (
    house_subscriber_id bigint NOT NULL,
    rfid varchar(32) NOT NULL,
    purchased_at integer NOT NULL DEFAULT (EXTRACT(EPOCH FROM NOW())::integer),
    PRIMARY KEY (house_subscriber_id, rfid)
);

CREATE INDEX IF NOT EXISTS subscriber_purchased_rfids_rfid_idx ON subscriber_purchased_rfids (rfid);

COMMENT ON TABLE subscriber_purchased_rfids IS 'RFID, проданные абоненту; привязка к квартире только если ключ есть в vendor_rfids_whitelist и здесь';

UPDATE core_vars SET var_value = '94' WHERE var_name = 'dbVersion';
