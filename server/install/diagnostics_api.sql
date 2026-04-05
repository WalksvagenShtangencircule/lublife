-- Права как у analytics/stats (permissions_same = md5('analytics/stats/GET'))
-- psql -f install/diagnostics_api.sql

INSERT INTO core_api_methods (aid, api, method, request_method, permissions_same) VALUES
('3000443aa69012bf3572e97f54761ced', 'diagnostics', 'run', 'GET', 'f4407303fc4a356fb1eeff02f61cee26'),
('cfc5b81e7bc6d6dc60ace0bc82c5c662', 'diagnostics', 'summary', 'GET', 'f4407303fc4a356fb1eeff02f61cee26'),
('f014b55f08bf8ba783bffd58de03b86e', 'diagnostics', 'check', 'GET', 'f4407303fc4a356fb1eeff02f61cee26'),
('33322a7ce9b6f13d67c5b1023f581450', 'diagnostics', 'history', 'GET', 'f4407303fc4a356fb1eeff02f61cee26'),
('d8a50deb62e2e54ab4b6a61cf214cd97', 'diagnostics', 'action', 'POST', 'f4407303fc4a356fb1eeff02f61cee26'),
('a94eecefde6872e5562b140bd111beab', 'diagnostics', 'telegramWait', 'GET', 'f4407303fc4a356fb1eeff02f61cee26')
ON CONFLICT (aid) DO NOTHING;
