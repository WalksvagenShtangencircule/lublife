<?php

    namespace cli {

        class init {

            private function latest($pre = false) {
                $dir = __DIR__ . "/../..";
                $raw = trim((string)`git -C $dir tag --sort=-creatordate`);
                if (!$raw) {
                    return false;
                }

                $tags = explode("\n", $raw);

                foreach ($tags as $tag) {
                    $tag = trim($tag);
                    if ($tag === "") {
                        continue;
                    }

                    $isPre = (bool)preg_match('/(?:-|\.)(alpha|beta|rc|pre)/i', $tag);
                    if (($pre && $isPre) || (!$pre && !$isPre)) {
                        return $tag;
                    }
                }

                // Если в репозитории нет разделения по pre/stable — берём самый свежий тег.
                return trim($tags[0]);
            }

            private function defaultBranch() {
                $dir = __DIR__ . "/../..";
                $branch = trim((string)`git -C $dir symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null`);
                if ($branch !== "" && strpos($branch, "origin/") === 0) {
                    return substr($branch, strlen("origin/"));
                }
                return "main";
            }

            function __construct(&$global_cli) {
                $global_cli["#"]["initialization and update"]["admin-password"] = [
                    "value" => "string",
                    "placeholder" => "password",
                    "description" => "Set (update) admin password",
                    "exec" => [ $this, "password" ],
                ];

                $global_cli["#"]["initialization and update"]["reindex"] = [
                    "description" => "Reindex access to API",
                    "exec" => [ $this, "reindex" ],
                ];

                $global_cli["#"]["initialization and update"]["enter-maintenance-mode"] = [
                    "stage" => "pre",
                    "description" => "Enter to maintenance mode",
                    "exec" => [ $this, "maintenanceOn" ],
                ];

                $global_cli["#"]["initialization and update"]["exit-maintenance-mode"] = [
                    "stage" => "pre",
                    "description" => "Exit from maintenance mode",
                    "exec" => [ $this, "maintenanceOff" ],
                ];

                $global_cli["#"]["initialization and update"]["clear-cache"] = [
                    "description" => "Clear redis cache items",
                    "exec" => [ $this, "cache" ],
                ];

                $global_cli["#"]["initialization and update"]["cleanup"] = [
                    "description" => "Run DB cleanup",
                    "exec" => [ $this, "cleanup" ],
                ];

                $global_cli["#"]["initialization and update"]["update"] = [
                    "description" => "Update client and server from git",
                    "params" => [
                        [
                            "force" => [
                                "optional" => true,
                            ],
                        ],
                        [
                            "devel" => [
                                "optional" => true,
                            ],
                            "force" => [
                                "optional" => true,
                            ],
                        ],
                        [
                            "pre" => [
                                "optional" => true,
                            ],
                            "force" => [
                                "optional" => true,
                            ],
                        ],
                        [
                            "version" => [
                                "value" => "string",
                                "placeholder" => "version",
                                "optional" => true,
                            ],
                            "force" => [
                                "optional" => true,
                            ],
                        ],
                    ],
                    "exec" => [ $this, "update" ],
                ];

                $global_cli["#"]["initialization and update"]["version-local"] = [
                    "description" => "Update version to local",
                    "exec" => [ $this, "local" ],
                ];
            }

            function password($args) {
                global $db;

                //TODO: rewrite to insert method
                try {
                    $db->exec("insert into core_users (uid, login, password) values (0, 'admin', 'admin')");
                } catch (\Exception $e) {
                    //
                }

                //TODO: rewrite to modify method
                try {
                    $sth = $db->prepare("update core_users set password = :password, login = 'admin', enabled = 1 where uid = 0");
                    $sth->execute([ ":password" => password_hash($args["--admin-password"], PASSWORD_DEFAULT) ]);
                    echo "admin account updated\n\n";
                } catch (\Exception $e) {
                    die("admin account update failed\n\n");
                }

                exit(0);
            }

            function reindex() {
                $n = clearCache(true);
                echo "$n cache entries cleared\n\n";
                reindex();
                echo "\n";

                exit(0);
            }

            function maintenanceOn() {
                maintenance(true);

                exit(0);
            }

            function maintenanceOff() {
                maintenance(false);

                exit(0);
            }

            function cache() {
                $n = clearCache(true);
                echo "$n cache entries cleared\n\n";

                exit(0);
            }

            function cleanup() {
                cleanup();

                exit(0);
            }

            function update($args) {
                global $config;

                $dir = __DIR__;

                $pre = array_key_exists("--pre", $args);
                $devel = array_key_exists("--devel", $args);
                $force = array_key_exists("--force", $args);

                if (($devel && @$args["--version"]) || ($devel && $pre) || ($pre && @$args["--version"])) {
                    \cliUsage();
                }

                chdir("$dir/../..");
                exec("git fetch --tags origin 2>&1");

                $defaultBranch = $this->defaultBranch();
                $targetRef = false;
                $version = false;
                $version_date = "";

                if ($devel) {
                    $targetRef = "origin/" . $defaultBranch;
                    $version = trim((string)`git rev-parse --short $targetRef`);
                    $version_date = " (" . date("Y-m-d") . ")";
                } elseif (@$args["--version"]) {
                    $targetRef = $args["--version"];
                    $version = $args["--version"];
                } else {
                    $version = $this->latest($pre);
                    if ($version) {
                        $targetRef = $version;
                    } else {
                        $targetRef = "origin/" . $defaultBranch;
                        $version = trim((string)`git rev-parse --short $targetRef`);
                        $version_date = " (" . date("Y-m-d") . ")";
                    }
                }

                $currentVersion = @explode(" ", file_get_contents("version"))[0];
                $currentHash = trim((string)`git rev-parse --short HEAD`);
                $targetHash = trim((string)`git rev-parse --short $targetRef 2>/dev/null`);

                if (!$targetHash) {
                    echo "Target reference not found: $targetRef\n";
                    exit(2);
                }

                if (($version == $currentVersion || $currentHash == $targetHash) && !$force) {
                    echo "No new releases found\n";
                    exit(2);
                }

                maintenance(true);
                waitAll();

                backupDB();
                echo "\n";

                $code = false;
                $out = [];

                if ($devel) {
                    $escapedBranch = escapeshellarg($defaultBranch);
                    exec("git checkout $escapedBranch 2>&1 && git pull --ff-only origin $escapedBranch 2>&1", $out, $code);
                } else {
                    $escapedTargetRef = escapeshellarg($targetRef);
                    exec("git -c advice.detachedHead=false checkout $escapedTargetRef 2>&1", $out, $code);
                }

                if ($code !== 0) {
                    echo implode("\n", $out);
                    echo "\n";
                    exit($code);
                }

                file_put_contents("version", $version . $version_date);

                initDB();
                echo "\n";

                $clickhouse_config = @$config['clickhouse'];

                $clickhouse = new \clickhouse(
                    @$clickhouse_config['host'] ?? '127.0.0.1',
                    @$clickhouse_config['port'] ?? 8123,
                    @$clickhouse_config['username'] ?? 'default',
                    @$clickhouse_config['password'] ?? 'qqq',
                );

                initClickhouseDB($clickhouse);

                echo "\n";

                $n = clearCache(true);
                echo "$n cache entries cleared\n\n";

                reindex();
                echo "\n";

                maintenance(false);

                echo "SmartYard: $currentVersion -> $version\n\n";

                exit(0);
            }

            function local() {
                $dir = __DIR__;

                $currentVersion = @explode(" ", file_get_contents("$dir/../../version"))[0];
                $version = substr(trim(`git -C $dir rev-parse --short HEAD`), 0, 7);

                file_put_contents("$dir/../../version", $version . " (" . date("Y-m-d") . ")");

                echo "SmartYard: $currentVersion -> $version\n\n";

                exit(0);
            }
        }
    }