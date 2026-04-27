<?php

    /**
     * @api {post} /api/vendorKeys/remove удалить RFID из каталога
     */

    namespace api\vendorKeys {

        use api\api;

        class remove extends api {

            public static function POST($params) {
                require_once __DIR__ . "/../../utils/vendor_keys_catalog.php";

                /** @var \PDOExt $db */
                $db = $params["_db"];

                $rfid = vendor_keys_normalize_rfid(@$params["rfid"] ?? "");
                if ($rfid === false) {
                    return api::ANSWER(false, "badRequest");
                }

                $sth = $db->prepare("DELETE FROM vendor_rfids_whitelist WHERE rfid = :r");
                $sth->execute([ ":r" => $rfid ]);
                $n = $sth->rowCount();

                return api::ANSWER([ "removed" => (int) $n ], "vendorKeysRemove");
            }

            public static function index() {
                return [
                    "POST" => "#same(vendorKeys,catalog,GET)",
                ];
            }
        }
    }
