<?php

    /**
     * @api {post} /api/tools/bulkImportSubscribers массовое добавление абонентов (телефонов) по квартирам
     *
     * @apiBody {Number} houseId
     * @apiBody {String} csv текст CSV: квартира;телефон
     */

    namespace api\tools {

        use api\api;

        class bulkImportSubscribers extends api {

            public static function POST($params) {
                require_once __DIR__ . "/../../utils/toolsImport.php";

                $houseId = (int) (@$params["houseId"] ?: 0);
                $csv = isset($params["csv"]) ? (string) $params["csv"] : "";
                if ($houseId <= 0 || $csv === "") {
                    return api::ANSWER(false, "badRequest");
                }

                $addresses = loadBackend("addresses");
                $households = loadBackend("households");
                if (!$addresses || !$households) {
                    return api::ANSWER(false, "badRequest");
                }

                $house = $addresses->getHouse($houseId);
                if (!$house) {
                    return api::ANSWER(false, "notFound");
                }

                $flats = $households->getFlats("houseId", $houseId);
                if ($flats === false || !is_array($flats)) {
                    return api::ANSWER(false, "badRequest");
                }

                $flatByNum = tools_import_flat_map_by_number($flats);
                $rows = tools_import_parse_csv_rows($csv);
                if (!count($rows)) {
                    return api::ANSWER(false, "badRequest");
                }

                $first = $rows[0]["cells"];
                $skipHeader = tools_import_is_header_subscribers($first);
                $imported = 0;
                $errors = [];

                foreach ($rows as $item) {
                    $line = $item["line"];
                    $cells = $item["cells"];
                    if ($skipHeader && $line === $rows[0]["line"]) {
                        continue;
                    }
                    if (count($cells) < 2) {
                        $errors[] = [ "line" => $line, "error" => "needTwoColumns" ];
                        continue;
                    }
                    $flatKey = tools_import_normalize_flat_key($cells[0]);
                    $phone = tools_import_normalize_phone($cells[1]);
                    if ($flatKey === "") {
                        $errors[] = [ "line" => $line, "error" => "emptyFlat" ];
                        continue;
                    }
                    if ($phone === false) {
                        $errors[] = [ "line" => $line, "error" => "badPhone" ];
                        continue;
                    }
                    if (!isset($flatByNum[$flatKey])) {
                        $errors[] = [ "line" => $line, "error" => "flatNotFound", "flat" => $flatKey ];
                        continue;
                    }
                    $flatId = $flatByNum[$flatKey];

                    $subscriberId = $households->addSubscriber($phone, "", "", "", $flatId, false);
                    if ($subscriberId === false) {
                        $err = getLastError();
                        $errors[] = [ "line" => $line, "error" => $err ? $err : "addSubscriberFailed", "flat" => $flatKey ];
                        continue;
                    }
                    $imported++;
                }

                return api::ANSWER([
                    "imported" => $imported,
                    "errors" => $errors,
                    "houseId" => $houseId,
                ], "toolsBulkSubscribers");
            }

            public static function index() {
                return [
                    "POST" => false,
                ];
            }
        }
    }
