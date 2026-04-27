<?php

    /**
     * @api {get} /api/vendorKeys/catalog список RFID в каталоге «наших» ключей (vendor_rfids_whitelist)
     */

    namespace api\vendorKeys {

        use api\api;

        class catalog extends api {

            public static function GET($params) {
                /** @var \PDOExt $db */
                $db = $params["_db"];

                $limit = isset($params["limit"]) ? (int) $params["limit"] : 5000;
                $offset = isset($params["offset"]) ? (int) $params["offset"] : 0;
                if ($limit < 1) {
                    $limit = 5000;
                }
                if ($limit > 20000) {
                    $limit = 20000;
                }
                if ($offset < 0) {
                    $offset = 0;
                }

                $totalRow = $db->get(
                    "SELECT COUNT(*)::bigint AS c FROM vendor_rfids_whitelist",
                    [],
                    [],
                    [ "singlify" ]
                );
                $total = $totalRow && isset($totalRow["c"]) ? (int) $totalRow["c"] : 0;

                $sth = $db->prepare(
                    "SELECT rfid, created_at FROM vendor_rfids_whitelist ORDER BY rfid ASC LIMIT :lim OFFSET :off"
                );
                $sth->bindValue(":lim", $limit, \PDO::PARAM_INT);
                $sth->bindValue(":off", $offset, \PDO::PARAM_INT);
                $sth->execute();
                $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

                return api::ANSWER([
                    "rows" => $rows ?: [],
                    "total" => $total,
                    "limit" => $limit,
                    "offset" => $offset,
                ], "vendorKeysCatalog");
            }

            public static function index() {
                /* Как у subscribers/subscribers GET: те же права, что на карточку дома (просмотр/работа с домами). */
                return [
                    "GET" => "#same(addresses,house,GET)",
                ];
            }
        }
    }
