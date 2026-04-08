# MCP-сервер SmartAccess (оболочка)

Локальный MCP по stdio для Cursor и других клиентов. Вызывает **тот же HTTP API**, что и веб-клиент (allowlist путей), без прямого доступа к БД в процессе Node.

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

## Запуск (stdio)

```bash
export SMARTACCESS_BASE_URL="https://example/frontend"
export SMARTACCESS_TOKEN="..."
node index.mjs
```

В Cursor: Settings → MCP → добавить команду с `node` и `cwd` на эту папку.

## Связь с веб-ассистентом

Чат в интерфейсе SmartAccess использует `POST /assistant/chat` (PHP + DeepSeek + инструменты в [`../../utils/AssistantTools.php`](../../utils/AssistantTools.php)). MCP здесь — **дополнительный** канал для IDE, не заменяет веб-модуль.

## Конфигурация DeepSeek на сервере

Скопируйте [`../../config/assistant.example.json`](../../config/assistant.example.json) в основной `server/config/config.json` в объект `"assistant": { ... }` (файл `config.json` в репозиторий не коммитится).
