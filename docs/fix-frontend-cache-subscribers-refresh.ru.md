# Чеклист: устаревшие абоненты/ключи в UI после правок (Redis frontend-кэш)

## Симптомы

- После добавления или редактирования номеров абонентов, ключей (иногда камер) на карточке квартиры данные в таблицах не обновляются.
- Помогает нестабильно: полная перезагрузка (Ctrl+F5) — потому что проблема не в кэше браузера, а в **серверном** ответе из Redis.

## Причина

В `server/frontend.php` успешные **GET**-вызовы API кэшируются в Redis (`CACHE:FRONT:<md5>:<uid>`), TTL из `config.json` → `redis.frontend_cache_ttl` (часто 3600 с).

Обход кэша: заголовок **`X-Api-Refresh: 1`** или параметр `_refresh` (см. `$refresh` в `frontend.php`).

В клиенте `client/js/rest.js` функция **`QUERY(api, method, query, fresh)`** выставляет этот заголовок и query-параметр `_*` только если **`fresh === true`** (четвёртый аргумент).

Экран **«Суперключи»** (`client/modules/addresses/keys.js`) уже вызывал `QUERY(..., true)`. Экран **квартиры** (`subscribers.js`) после POST/PUT снова вызывал `route()` с **`QUERY("subscribers", "subscribers", {...})` без `true`** → сервер отдавал закэшированный JSON → UI рисовал старые строки.

## Исправление (патч клиента)

Добавить четвёртый аргумент **`true`** ко всем `QUERY(...)`, которые после мутаций должны показывать актуальные данные по абонентам/ключам/устройствам с тем же URL-параметром, что и при первом заходе.

### Файлы и места (эталон для копирования на другие серверы)

1. **`client/modules/addresses/subscribers.js`**  
   В `route()`, внутри цепочки после `QUERY("addresses", "addresses", ...)`, вызов:
   - `QUERY("subscribers", "subscribers", { by: "flatId", query: params.flatId }, true)`

2. **`client/modules/addresses/subscriberDevices.js`**
   - `QUERY("subscribers", "subscribers", { by: "subscriberId", query: device.subscriberId }, true)` в `modifyDevice`
   - `QUERY("subscribers", "devices", { by: "subscriber", query: subscriberId }, true)` в `renderSubscriberDevices`

3. **`client/modules/addresses/watchers.js`**
   - `QUERY("subscribers", "devices", { by: "flatId", query: params.flatId }, true)` в `renderWatchers`

Проверка в репозитории:

```bash
rg 'QUERY\("subscribers",' /opt/rbt/client/modules/addresses/
```

Убедиться, что перечисленные вызовы заканчиваются на `, true)` там, где данные меняются из UI того же сеанса.

## Деплой на сервере

- Если статика отдаётся напрямую из дерева репозитория (`/opt/rbt/client/...`) — достаточно сохранить файлы и сбросить браузерный кэш по необходимости.
- Если JS копируется в веб-корень (nginx/apache) — выполнить **ваш** штатный скрипт выкладки/сборки, чтобы обновились именно те файлы, которые отдаются пользователям.

## После правок (операционно)

1. **Reindex API и очистка Redis frontend-кэша** (встроено в одну команду):

   ```bash
   cd /opt/rbt/server && sudo php cli.php --reindex
   ```

   Это вызывает `clearCache(true)` и пересобирает `core_api_methods` (см. `server/cli/init.php`, метод `reindex`).

2. При необходимости только сброс кэша без полного reindex (если в вашей версии есть отдельная команда):

   ```bash
   cd /opt/rbt/server && sudo php cli.php --clear-cache
   ```

   (уточнить по `php cli.php` / справке в вашей сборке).

## Проверка после фикса

- Открыть карточку квартиры → добавить абонента или ключ → таблица обновляется без полной перезагрузки.
- В DevTools → Network → ответ GET `subscribers/subscribers` должен иметь заголовок **`X-Api-Data-Source: db`** при обновлении после правки (при `fresh: true`). Если **`cache`** — запрос ушёл без обхода кэша.

## Долгосрочно (по желанию, сервер)

Инвалидировать `CACHE:FRONT:*` для затронутого пользователя при POST/PUT/DELETE по `subscribers/*`, чтобы старые клиенты без патча не получали устаревшие GET — отдельная доработка `frontend.php` или бэкенда.

---

*Документ создан для повторения процедуры на аналогичных инсталляциях RBT.*
