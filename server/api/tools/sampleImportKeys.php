<?php

    /**
     * @api {get} /api/tools/sampleImportKeys образец CSV для импорта ключей
     */

    namespace api\tools {

        use api\api;

        class sampleImportKeys extends api {

            public static function GET($params) {
                /* BOM UTF-8 для Excel; таб перед RFID — чтобы не исказилось как число. */
                $content =
                    "\xEF\xBB\xBF" .
                    "# Формат: квартира;ключ (14 символов 0-9 A-F). Строки с # — комментарии.\r\n" .
                    "квартира;ключ\r\n" .
                    "101;\t00000000ABCDEF\r\n" .
                    "102;\t00000000FEDCBA\r\n";

                return api::ANSWER([
                    "fileName" => "sample_klyuchi.csv",
                    "content" => $content,
                ], "toolsSample");
            }

            public static function index() {
                return [
                    "GET" => "#same(tools,bulkImportKeys,POST)",
                ];
            }
        }
    }
