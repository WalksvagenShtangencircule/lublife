<?php

    /**
     * @api {get} /api/tools/sampleImportSubscribers образец CSV для импорта абонентов
     */

    namespace api\tools {

        use api\api;

        class sampleImportSubscribers extends api {

            public static function GET($params) {
                /* BOM UTF-8 — Excel в Windows иначе ломает кириллицу. Таб перед номером — чтобы не было 7,9E+10. */
                $content =
                    "\xEF\xBB\xBF" .
                    "# Формат: квартира;телефон (11 цифр, с 7). Строки с # — комментарии.\r\n" .
                    "квартира;телефон\r\n" .
                    "101;\t79001234567\r\n" .
                    "102;\t79007654321\r\n";

                return api::ANSWER([
                    "fileName" => "sample_abonenty.csv",
                    "content" => $content,
                ], "toolsSample");
            }

            public static function index() {
                return [
                    "GET" => "#same(tools,bulkImportSubscribers,POST)",
                ];
            }
        }
    }
