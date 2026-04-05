## Legend

```diff
- bugfix
+ new
! important changes
# removed or deprecated
```

```diff
+ tt workflow new method api.call("GET|POST|PUT|DELETE", "api", "method", "refresh", "data")
+ expanded RODOS support, the entire line should work, from 1 to 16 outputs
+ markdown in comments and description in TT
+ dial to analog intercom by cms matrix (useAnalogNumber)
```

## 2026-04-03

```diff
+ install: readme — перечень скриптов Tor (snowflake, php-fpm/debian-tor, ControlPort) со ссылкой на 17.tor.md §1c
+ install: Tor — пул мостов /etc/tor/rbt-bridge-pool.txt; tor_bridge_pool_apply/rotate, tor_bridge_healthcheck, tor_bridge_monitor + systemd timer; 17.tor.md §11; tor_enable_snowflake.sh пишет пул и вызывает apply
+ diagnostics UI: «Ждать чат» — POST telegramArmWait без синхронного deleteWebhook (только markPending); опрос GET по очереди + timeout; i18n tgWaitWebhookDeferred / tgWaitBadResponse
```
+ diagnostics: telegramWait — _telegram_wait_ui_fast (короткие curl, без NEWNYM-цепочки), nginx example install/nginx/rbt-diagnostics-telegram-timeout.conf.example; подсказка при HTTP 504

## 2026-04-05

```diff
+ install: Tor (SOCKS5) for diagnostics Telegram — install/17.tor.md, ссылка в install/readme.md
+ diagnostics: Telegram через Tor, отложенный deleteWebhook, опрос «Ждать чат», cron-прогон, тест server/tests/
+ diagnostics: Tor — повтор запроса после NEWNYM при таймауте/обрыве (retry_newnym_on_transport_fail, нужен ControlPort)
+ diagnostics: Telegram через Tor — без CURLOPT_IPRESOLVE для SOCKS (резолв как aiohttp rdns), таймауты connect/read под медленный Tor; install: systemd/php-fpm, tor_append_control_port.sh
+ install: Tor — в 17.tor.md раздел про firewall: локальные 9050/9051, исходящие TCP для клиента, ufw
+ diagnostics: «Ждать чат» — не вызывать getUpdates пока webhook не снят; deleteWebhook сразу в POST arm; drop_pending_updates=1; UI если webhook блокирует /start
```

## 2026-01-14 0.0.20 hotfix 8

```diff
+ simple kanban
+ simple system info dashboard
! backend tt type "mongo" renamed to "internal" (need modify server/config/config.json)
+ camTree settings for webUI, camTree = false - off, "houses" - common for all houses, "perHouse" - per house
+ devices (cameras and domophones) tree
+ persistent tables filters in webUI
+ sudo-like administrative mode in webUI
+ fyeo in notes
! addHouseByMagic() now can accept both *_fias_id and *_uuid fields
! massive refactoring in server/utils/*.php
+ @api {get} /api/houses/flat:flatId get flat
- minor fixes in households->modifyFlat
+ bloking webUI interface when in maintenance mode
+ scroll to and hightlight issue when returning from issue to list
+ --force-expire for files backend
- fixed css loader
- fixed sip-ready events
+ added tmpfs, memfs and extfs backends
- fixed issueAdapter backend
+ added webrtc setting (on/off) for cameras
+ autocompact parameter for files backend
+ --mongodb-compact global cli command
```

## 2025-11-09 0.0.18 hotfix 7