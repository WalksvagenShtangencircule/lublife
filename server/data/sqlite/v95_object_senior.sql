CREATE TABLE IF NOT EXISTS houses_object_seniors (
    house_object_senior_id INTEGER PRIMARY KEY AUTOINCREMENT,
    address_house_id INTEGER NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    login TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    can_view_events INTEGER NOT NULL DEFAULT 1,
    can_manage_subscribers INTEGER NOT NULL DEFAULT 1,
    can_manage_entrance_access INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0,
    UNIQUE (address_house_id, login)
);

CREATE TABLE IF NOT EXISTS houses_object_senior_flats (
    house_object_senior_id INTEGER NOT NULL,
    house_flat_id INTEGER NOT NULL,
    PRIMARY KEY (house_object_senior_id, house_flat_id)
);

CREATE INDEX IF NOT EXISTS houses_object_senior_flats_flat_idx ON houses_object_senior_flats (house_flat_id);

CREATE TABLE IF NOT EXISTS houses_flats_subscribers_entrances (
    house_flat_id INTEGER NOT NULL,
    house_subscriber_id INTEGER NOT NULL,
    house_entrance_id INTEGER NOT NULL,
    PRIMARY KEY (house_flat_id, house_subscriber_id, house_entrance_id)
);

CREATE INDEX IF NOT EXISTS houses_flats_subscribers_entrances_sub_flat_idx
    ON houses_flats_subscribers_entrances (house_subscriber_id, house_flat_id);

UPDATE core_vars SET var_value = '95' WHERE var_name = 'dbVersion';
