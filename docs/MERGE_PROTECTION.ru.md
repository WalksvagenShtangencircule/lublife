# Защита от порчи деплоя при обновлении с основной ветки

В корне репозитория заданы правила в `.gitattributes`: для перечисленных файлов при слиянии с `origin/main` сохраняется **текущая версия ветки** (серверный слой: Asterisk, nginx, кастомный API, правки медиасервера и т.д.).

## Однократная настройка после `git clone`

```bash
chmod +x scripts/configure-merge-protection.sh
./scripts/configure-merge-protection.sh
```

Это выполняет `git config merge.ours.driver true` локально для данного репозитория.

## Перед каждым слиянием с апстримом

Убедитесь, что драйвер всё ещё включён:

```bash
git config --get merge.ours.driver   # должно быть: true
```

Затем обычное обновление:

```bash
git fetch origin
git merge origin/main
```

Если нужно вручную подтянуть изменения апстрима в конкретный файл — временно уберите для него строку из `.gitattributes`, выполните merge, разрешите конфликт и верните правило.

## Рабочий `client/config/config.json`

Файл в `.gitignore` (содержит URL и хост). Порядок модулей в меню дублируется в `client/config/config.example.json` и `client/config/config.sample.json5` — при смене инсталляции скопируйте фрагмент `modules` в свой `config.json`.
