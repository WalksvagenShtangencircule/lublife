<?php

    /**
     * @api {get} /api/houses/virtualDomophones список виртуальных панелей (model virtual.json)
     *
     * @apiHeader {String} Authorization authentication token
     */

    namespace api\houses {

        use api\api;

        class virtualDomophones extends api {

            public static function GET($params) {
                $households = loadBackend("households");
                $configs = loadBackend("configs");
                $sip = loadBackend("sip");

                if (!$households) {
                    return api::ERROR();
                }

                $all = $households->getDomophones("all", -1, false);
                $virtual = [];
                if (is_array($all)) {
                    foreach ($all as $d) {
                        if (($d["model"] ?? "") === "virtual.json") {
                            $virtual[] = $d;
                        }
                    }
                }

                $response = [
                    "domophones" => $virtual,
                    "models" => $configs->getDomophonesModels(),
                    "servers" => $sip->server("all"),
                ];

                return api::ANSWER($response, "virtualDomophones");
            }

            public static function index() {
                return [
                    "GET" => false,
                ];
            }
        }
    }
