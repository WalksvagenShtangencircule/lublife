-- Ручная вставка (обычно достаточно php cli.php --reindex).
-- В интерфейсе «Права» (all=0) видна одна строка: diagnostics / run / GET — остальные методы модуля привязаны через permissions_same.
-- psql -f install/diagnostics_api.sql

INSERT INTO core_api_methods (aid, api, method, request_method, permissions_same) VALUES
('3000443aa69012bf3572e97f54761ced', 'diagnostics', 'run', 'GET', NULL),
('cfc5b81e7bc6d6dc60ace0bc82c5c662', 'diagnostics', 'summary', 'GET', '3000443aa69012bf3572e97f54761ced'),
('f014b55f08bf8ba783bffd58de03b86e', 'diagnostics', 'check', 'GET', '3000443aa69012bf3572e97f54761ced'),
('33322a7ce9b6f13d67c5b1023f581450', 'diagnostics', 'history', 'GET', '3000443aa69012bf3572e97f54761ced'),
('d8a50deb62e2e54ab4b6a61cf214cd97', 'diagnostics', 'action', 'POST', '3000443aa69012bf3572e97f54761ced'),
('a94eecefde6872e5562b140bd111beab', 'diagnostics', 'telegramWait', 'GET', '3000443aa69012bf3572e97f54761ced'),
('5b10a07c1fea9348aba3b7e83d4c8c5a', 'diagnostics', 'telegram', 'POST', '3000443aa69012bf3572e97f54761ced')
ON CONFLICT (aid) DO NOTHING;
