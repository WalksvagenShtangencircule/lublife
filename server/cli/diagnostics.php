<?php

    namespace cli {

        class diagnostics {

            function __construct(&$global_cli) {
                $global_cli["#"]["diagnostics"]["diagnostics-run"] = [
                    "description" => "Один прогон диагностики (кеш, история, уведомления Telegram по правилам)",
                    "params" => [
                        [
                            "heavy" => [
                                "optional" => true,
                            ],
                        ],
                    ],
                    "exec" => [ $this, "runOnce" ],
                ];
            }

            function runOnce($args) {
                global $config, $redis;

                require_once __DIR__ . '/../utils/DiagnosticsCron.php';

                $heavy = isset($args['--heavy']);
                \DiagnosticsCron::runOnceManual($config, $redis, $heavy);

                echo "diagnostics-run: ok\n\n";
                exit(0);
            }
        }
    }
