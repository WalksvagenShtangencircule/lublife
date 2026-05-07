# Migration data export/import

Каталог предназначен для служебных скриптов миграции между серверами SmartAccess.

## Экспорт с сервера-источника

```bash
cd /opt/rbt/server
php cli.php --backup-db
php db/migration/rbt_export_migration_json.php db/migration/rbt_migration_export.json
mongodump --db=rbt --out=/opt/rbt/server/db/migration/mongo_dump_rbt_$(date +%Y%m%d_%H%M)
```

## Важно

- Не восстанавливайте полный SQL-дамп поверх рабочей базы другого сервера без отдельного плана.
- Для частичного переноса используйте JSON-экспорт и импортёр с пересборкой `id`/FK.
- Артефакты (`*.json`, `*.tar.gz`, `mongo_dump_*`) не коммитятся в git.

## Что коммитится в git

- `rbt_export_migration_json.php`
- `README.md`
- инструкции/шаблоны процесса миграции без секретов и тяжёлых дампов.
