-- Порядок выката при смене #same у analytics/* на stats:
-- 1) php server/cli.php --reindex   (обновить core_api_methods.permissions_same)
-- 2) выполнить этот SQL (скопировать права с addresses на analytics/stats для существующих групп/пользователей)
-- 3) php server/cli.php --clear-cache
--
-- Раньше analytics/stats/events/camshot были привязаны к addresses/addresses/GET.
-- После reindex с корнем analytics/stats/GET выдайте модуль тем же группам/пользователям, у кого был доступ к адресам.
-- md5('analytics/stats/GET') = f4407303fc4a356fb1eeff02f61cee26
-- md5('addresses/addresses/GET') = c7762e81900840a0d92a20ceda0a0df3

INSERT INTO core_groups_rights (gid, aid, allow)
SELECT g.gid, 'f4407303fc4a356fb1eeff02f61cee26', g.allow
FROM core_groups_rights g
WHERE g.aid = 'c7762e81900840a0d92a20ceda0a0df3' AND g.allow = 1
ON CONFLICT (gid, aid) DO NOTHING;

INSERT INTO core_users_rights (uid, aid, allow)
SELECT u.uid, 'f4407303fc4a356fb1eeff02f61cee26', u.allow
FROM core_users_rights u
WHERE u.aid = 'c7762e81900840a0d92a20ceda0a0df3' AND u.allow = 1
ON CONFLICT (uid, aid) DO NOTHING;
