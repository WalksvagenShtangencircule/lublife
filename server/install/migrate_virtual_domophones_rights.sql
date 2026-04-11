-- После снятия #same у houses/virtualDomophone* нужно выдать новые aid в правах.
-- Порядок:
--   1) php server/cli.php --reindex
--   2) этот SQL (копирует allow=1 с прежних «родителей» addresses/house)
--   3) при необходимости php server/cli.php --clear-cache
--
-- md5('addresses/house/GET')  = 4e0233b96b9fc5c19effae7000fcc0b9
-- md5('addresses/house/PUT') = 2d4011222efb4d273193c8f72594600c
--
-- md5('houses/virtualDomophones/GET')            = 4f6351b8fc3438130e647357f41faff3
-- md5('houses/virtualDomophone/GET')             = a97d8f5282abde363cf675bd0831af5c
-- md5('houses/virtualDomophone/POST')            = e9fce5f376a5649b8f6e385f8f945c28
-- md5('houses/virtualDomophone/PUT')             = cd15b82fa7228abb05b2cf8acd90a999
-- md5('houses/virtualDomophone/DELETE')         = cf910dc6e20d1fcc50bdaf282f3638e2
-- md5('houses/virtualDomophoneRotateToken/PUT') = 2f9d2e725074411514de66ff3e804550

INSERT INTO core_groups_rights (gid, aid, allow)
SELECT g.gid, v.target_aid, g.allow
FROM core_groups_rights g
CROSS JOIN (VALUES
    ('4f6351b8fc3438130e647357f41faff3', '4e0233b96b9fc5c19effae7000fcc0b9'),
    ('a97d8f5282abde363cf675bd0831af5c', '4e0233b96b9fc5c19effae7000fcc0b9'),
    ('e9fce5f376a5649b8f6e385f8f945c28', '2d4011222efb4d273193c8f72594600c'),
    ('cd15b82fa7228abb05b2cf8acd90a999', '2d4011222efb4d273193c8f72594600c'),
    ('cf910dc6e20d1fcc50bdaf282f3638e2', '2d4011222efb4d273193c8f72594600c'),
    ('2f9d2e725074411514de66ff3e804550', '2d4011222efb4d273193c8f72594600c')
) AS m(target_aid, source_aid)
WHERE g.aid = m.source_aid AND g.allow = 1
ON CONFLICT (gid, aid) DO NOTHING;

INSERT INTO core_users_rights (uid, aid, allow)
SELECT u.uid, v.target_aid, u.allow
FROM core_users_rights u
CROSS JOIN (VALUES
    ('4f6351b8fc3438130e647357f41faff3', '4e0233b96b9fc5c19effae7000fcc0b9'),
    ('a97d8f5282abde363cf675bd0831af5c', '4e0233b96b9fc5c19effae7000fcc0b9'),
    ('e9fce5f376a5649b8f6e385f8f945c28', '2d4011222efb4d273193c8f72594600c'),
    ('cd15b82fa7228abb05b2cf8acd90a999', '2d4011222efb4d273193c8f72594600c'),
    ('cf910dc6e20d1fcc50bdaf282f3638e2', '2d4011222efb4d273193c8f72594600c'),
    ('2f9d2e725074411514de66ff3e804550', '2d4011222efb4d273193c8f72594600c')
) AS m(target_aid, source_aid)
WHERE u.aid = m.source_aid AND u.allow = 1
ON CONFLICT (uid, aid) DO NOTHING;
