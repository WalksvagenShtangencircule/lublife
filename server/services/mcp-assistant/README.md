# MCP-сервер CityHome (оболочка)

Локальный MCP по stdio для Cursor и других клиентов. Вызывает **тот же HTTP API**, что и веб-клиент: путь вида `api/method` (включая **camelCase**, напр. `mcp_data/pgSelect`). Права определяет Bearer-токен.

## Инструменты (кратко)

| Инструмент | Назначение |
|------------|------------|
| `smapi_request` | Универсальный HTTP: `path`, опционально `method`, `body`. |
| `cityhome_catalog` | `entities-catalog.json`: эндпоинты, домены, сущности, пересечения. |
| `cityhome_capabilities` | `GET authorization/available` — матрица для текущего токена. |
| `cityhome_suggested_questions` | `suggested-questions-ru.json` — примеры формулировок для полных ответов. |
| `smapi_get` | Псевдоним GET-only. |
| **`mcp_deep_schema`** | `GET mcp_data/schema` — таблицы/колонки PostgreSQL (`information_schema`). |
| **`mcp_deep_pg_select`** | `POST mcp_data/pgSelect` — один `SELECT`/`WITH`, read-only, лимит строк. |
| **`mcp_deep_ch_select`** | `POST mcp_data/clickhouseSelect` — ClickHouse `SELECT`/`WITH`. |
| **`mcp_deep_config`** | `GET mcp_data/configSnapshot` — `config.json` с **`<redacted>`** вместо секретов. |
| **`mcp_deep_runtime`** | `GET mcp_data/runtime` — PHP, расширения, Redis ping. |
| **`mcp_deep_api_index`** | `GET mcp_data/apiIndex` — содержимое `core_api_methods`. |
| **`mcp_deep_redis`** | `GET mcp_data/redisInfo` — `INFO` Redis. |

Серверная реализация: каталог [`../../api/mcp_data/`](../api/mcp_data/) и [`../../utils/McpDataService.php`](../utils/McpDataService.php).

### Права на «глубокие» методы

Эндпоинты `mcp_data/*` привязаны к **диагностике** (как и задумано для внутреннего администрирования):

- чтение (`schema`, `configSnapshot`, `runtime`, `apiIndex`, `redisInfo`) — как **`GET diagnostics/check`**;
- запись SQL (`pgSelect`, `clickhouseSelect`) — как **`POST diagnostics/action`**.

Выдайте пользователю, чей токен использует MCP, соответствующие права в матрице (или группе admins с полным доступом к диагностике). После смены прав: `php server/cli.php --reindex` при необходимости и сброс кэша.

## Установка

```bash
cd server/services/mcp-assistant
npm install
```

## Переменные окружения

| Переменная | Описание |
|------------|----------|
| `SMARTACCESS_BASE_URL` | База API, например `https://хост/frontend` |
| `SMARTACCESS_TOKEN` | Bearer-токен |
| `SMARTACCESS_MCP_HTTP_METHODS` | По умолчанию **`GET,POST`** (нужно для `mcp_deep_*` POST). Ужесточить: `GET`. Расширить: `ALL`. |
| `SMARTACCESS_MCP_MAX_RESPONSE_CHARS` | Лимит текста ответа (по умолчанию `600000`). |

## Запуск (stdio)

```bash
export SMARTACCESS_BASE_URL="https://example/frontend"
export SMARTACCESS_TOKEN="..."
node index.mjs
```

В Cursor: MCP → команда `node …/index.mjs`, `cwd` на эту папку, те же env.

## Связь с веб-ассистентом

Веб-чат: `POST assistant/chat`. MCP может дублировать данные через обычные пути API и при необходимости — **прямое чтение БД** через `mcp_data` (в границах валидации SQL и прав токена).

## Конфигурация DeepSeek на сервере

См. [`../../config/assistant.example.json`](../../config/assistant.example.json) → блок `assistant` в `server/config/config.json`.

## Обновление каталога эндпоинтов

После добавления новых `api/*/*.php`:

```bash
find ../../api -maxdepth 2 -name '*.php' ! -name 'api.php' \
  | sed 's|\.php$||' | sed 's|.*/api/||' | sort \
  | jq -R -s '{allEndpoints: split("\n") | map(select(length>0))}' > /tmp/ep.json
# вручную объедините новые allEndpoints с полями meta/domains/entityNodes/intersections в entities-catalog.json
```
