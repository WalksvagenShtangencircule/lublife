## Install

1. [Ubuntu 24.04 (Noble Numbat)](01.nooble.md)
2. [NodeJS](02.nodejs.md)
3. [PostgreSQL](03.postgresql.md)
4. [PgBouncer](04.bouncer.md)
5. [Clickhouse](05.clickhouse.md)
6. [MongoDB](06.mongo.md)
7. [SmartYard-Server](07.install.md)
8. [Nginx](08.nginx.md)
9. [Acmesh](09.acme.md)
10. [Asterisk](10.asterisk.md)
11. [Event](11.event.md)
12. [FALPRS](12.falprs.md)
13. [Push service](13.push.md)
14. [Mosquitto](14.mosquitto.md)
15. [ONLYOFFICE Document Builder](15.onlyoffice.md)
16. [Post install](99.post_install.md)

___

## Optional feature

1. [Kamailio](98.kamailio.md)
2. [Tor (SOCKS) for diagnostics Telegram](17.tor.md) — если Telegram API недоступен без Tor  
3. [Виртуальная панель: DTMF → HTTP (чеклист развёртывания)](16.vdom_dtmf_checklist.md) — шлагбаум / `doorOpeningUrls`, AMI `rbtdom`, `rbt-vdom-ami-dtmf.service`  
   Скрипты в `install/scripts/`: `tor_enable_snowflake.sh` (пул мостов Snowflake), `tor_bridge_monitor_install.sh` (проверка SOCKS + ротация пула по таймеру), `tor_bridge_pool_apply.sh` / `tor_bridge_pool_rotate.sh`, `tor_php_debian_tor.sh` (php-fpm и группа `debian-tor`), `tor_append_control_port.sh` (TCP `127.0.0.1:9051`). Подробно — §11 в `17.tor.md`.

#### Monitoring

###### use Zabbix or Prometheus

1. [Zabbix](97.zabbix.md)
2. [Prometheus](95.prometheus.md)
