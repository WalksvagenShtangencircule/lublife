-- Личные кабинеты «старшего по объекту» и ограничение подъездов для жильцов
-- Версия схемы: 95

CREATE TABLE IF NOT EXISTS houses_object_seniors (
    house_object_senior_id bigserial PRIMARY KEY,
    address_house_id bigint NOT NULL REFERENCES addresses_houses (address_house_id) ON DELETE CASCADE,
    slug varchar(64) NOT NULL,
    login varchar(128) NOT NULL,
    password_hash text NOT NULL,
    title varchar(255) NOT NULL DEFAULT '',
    can_view_events smallint NOT NULL DEFAULT 1,
    can_manage_subscribers smallint NOT NULL DEFAULT 1,
    can_manage_entrance_access smallint NOT NULL DEFAULT 1,
    created_at integer NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX IF NOT EXISTS houses_object_seniors_slug_uq ON houses_object_seniors (slug);
CREATE UNIQUE INDEX IF NOT EXISTS houses_object_seniors_house_login_uq ON houses_object_seniors (address_house_id, login);

CREATE TABLE IF NOT EXISTS houses_object_senior_flats (
    house_object_senior_id bigint NOT NULL REFERENCES houses_object_seniors (house_object_senior_id) ON DELETE CASCADE,
    house_flat_id bigint NOT NULL REFERENCES houses_flats (house_flat_id) ON DELETE CASCADE,
    PRIMARY KEY (house_object_senior_id, house_flat_id)
);

CREATE INDEX IF NOT EXISTS houses_object_senior_flats_flat_idx ON houses_object_senior_flats (house_flat_id);

CREATE TABLE IF NOT EXISTS houses_flats_subscribers_entrances (
    house_flat_id bigint NOT NULL REFERENCES houses_flats (house_flat_id) ON DELETE CASCADE,
    house_subscriber_id bigint NOT NULL REFERENCES houses_subscribers_mobile (house_subscriber_id) ON DELETE CASCADE,
    house_entrance_id bigint NOT NULL REFERENCES houses_entrances (house_entrance_id) ON DELETE CASCADE,
    PRIMARY KEY (house_flat_id, house_subscriber_id, house_entrance_id)
);

CREATE INDEX IF NOT EXISTS houses_flats_subscribers_entrances_sub_flat_idx
    ON houses_flats_subscribers_entrances (house_subscriber_id, house_flat_id);

COMMENT ON TABLE houses_object_seniors IS 'ЛК старшего по дому/СНТ: вход по slug+логин+пароль';
COMMENT ON TABLE houses_object_senior_flats IS 'Если пусто для старшего — доступ ко всем квартирам дома; иначе только перечисленные';
COMMENT ON TABLE houses_flats_subscribers_entrances IS 'Если для пары квартира-жилец нет строк — все подъезды квартиры; иначе только перечисленные';

UPDATE core_vars SET var_value = '95' WHERE var_name = 'dbVersion';
