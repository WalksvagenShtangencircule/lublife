# MCP-сервер SmartAccess (оболочка)

Локальный MCP по stdio для Cursor и других клиентов. Вызывает **тот же HTTP API**, что и веб-клиент: ограничение только формой пути (`api/method` и опционально `/id`), без произвольных URL. Фактические **права** определяет Bearer-токен на сервере.

## Инструменты

| Инструмент | Назначение |
|------------|------------|
| `smapi_request` | Универсальный HTTP-вызов (по умолчанию **GET**). Параметры: `path`, опционально `method`, `body`. |
| `smartaccess_catalog` | Встроенный файл `entities-catalog.json`: полный перечень эндпоинтов, домены, узлы сущностей и **пересечения** (связи). |
| `smartaccess_capabilities` | `GET authorization/available` — какие методы API разрешены **текущему** токену. |
| `smapi_get` | Устаревший псевдоним `smapi_request` с `method=GET`. |

## Установка

```bash
cd server/services/mcp-assistant
npm install
```

## Переменные окружения

| Переменная | Описание |
|------------|----------|
| `SMARTACCESS_BASE_URL` | База API, например `https://хост/frontend` (как `defaultServer` в клиенте) |
| `SMARTACCESS_TOKEN` | Bearer-токен пользователя с нужными правами |
| `SMARTACCESS_MCP_HTTP_METHODS` | По умолчанию `GET`. Для чата и записи: `GET,POST` или `ALL` (GET, POST, PUT, DELETE, PATCH, HEAD). |
| `SMARTACCESS_MCP_MAX_RESPONSE_CHARS` | Лимит размера текста ответа инструментов (по умолчанию `600000`). |

## Запуск (stdio)

```bash
export SMARTACCESS_BASE_URL="https://example/frontend"
export SMARTACCESS_TOKEN="..."
# опционально: записи и POST assistant/chat
export SMARTACCESS_MCP_HTTP_METHODS="GET,POST"
node index.mjs
```

В Cursor: Settings → MCP → добавить команду с `node` и `cwd` на эту папку (и те же переменные окружения).

## Связь с веб-ассистентом

Чат в интерфейсе SmartAccess использует `POST assistant/chat` (PHP + DeepSeek + инструменты в [`../../utils/AssistantTools.php`](../../utils/AssistantTools.php)). MCP — **дополнительный** канал для IDE: для тех же данных можно дергать те же пути API.

## Конфигурация DeepSeek на сервере

Скопируйте [`../../config/assistant.example.json`](../../config/assistant.example.json) в основной `server/config/config.json` в объект `"assistant": { ... }` (файл `config.json` в репозиторий не коммитится).

## Обновление каталога эндпоинтов

После добавления новых `api/*/method.php` пересоберите список:

```bash
find ../../api -maxdepth 2 -name '*.php' ! -name 'api.php' \
  | sed 's|\.php$||' | sed 's|.*/api/||' | sort \
  | jq -R -s '{allEndpoints: split("\n") | map(select(length>0))}' > /tmp/ep.json
# затем вручную объедините с полями meta / domains / entityNodes / intersections в entities-catalog.json
```
