<?php

    /**
     * @api {post} /api/vendorKeys/importCsv загрузка списка RFID в каталог (CSV: один RFID на строку или первая колонка)
     */

    namespace api\vendorKeys {

        use api\api;

        class importCsv extends api {

            public static function POST($params) {
                require_once __DIR__ . "/../../utils/vendor_keys_catalog.php";
                require_once __DIR__ . "/../../utils/toolsImport.php";

                /** @var \PDOExt $db */
                $db = $params["_db"];

                $csv = isset($params["csv"]) ? (string) $params["csv"] : "";
                if ($csv === "") {
                    return api::ANSWER(false, "badRequest");
                }

                $parsed = tools_import_parse_csv_rows($csv);
                if (!count($parsed)) {
                    return api::ANSWER(false, "badRequest");
                }

                $firstCells = $parsed[0]["cells"];
                $skipHeader = false;
                if (count($firstCells) >= 1) {
                    $h = mb_strtolower(trim((string) $firstCells[0]));
                    if (mb_strpos($h, "rfid") !== false || $h === "ключ" || $h === "key") {
                        $skipHeader = true;
                    }
                }

                $imported = 0;
                $duplicates = 0;
                $errors = [];

                $ins = $db->prepare(
                    "INSERT INTO vendor_rfids_whitelist (rfid) VALUES (:r) ON CONFLICT (rfid) DO NOTHING"
                );

                foreach ($parsed as $item) {
                    $line = $item["line"];
                    $cells = $item["cells"];
                    if ($skipHeader && $line === $parsed[0]["line"]) {
                        continue;
                    }
                    if (!count($cells)) {
                        continue;
                    }
                    $raw = trim((string) $cells[0]);
                    if ($raw === "" || (isset($raw[0]) && $raw[0] === "#")) {
                        continue;
                    }

                    $rfid = vendor_keys_normalize_rfid($raw);
                    if ($rfid === false) {
                        $errors[] = [ "line" => $line, "error" => "badRfid" ];
                        continue;
                    }

                    try {
                        $ins->execute([ ":r" => $rfid ]);
                        $n = $ins->rowCount();
                        if ($n > 0) {
                            $imported++;
                        } else {
                            $duplicates++;
                        }
                    } catch (\Throwable $e) {
                        $errors[] = [ "line" => $line, "error" => "insertFailed" ];
                    }
                }

                return api::ANSWER([
                    "imported" => $imported,
                    "duplicates" => $duplicates,
                    "errors" => $errors,
                ], "vendorKeysImport");
            }

            public static function index() {
                return [
                    "POST" => "#same(vendorKeys,catalog,GET)",
                ];
            }
        }
    }
