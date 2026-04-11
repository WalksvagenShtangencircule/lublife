<?php

    require_once 'vendor/autoload.php';

    $real_ip_header = 'HTTP_X_FORWARDED_FOR';

    // frontend client API support

    $cli = false;
    $cli_error = false;

    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

    if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
        header("Content-Type: text/html;charset=ISO-8859-1");
        http_response_code(204);
        return;
    }

    require_once "utils/functions.php";
    require_once "utils/polyfills.php";
    require_once "utils/error.php";
    require_once "utils/response.php";
    require_once "utils/loader.php";
    require_once "utils/email.php";
    require_once "utils/forgot.php";
    require_once "utils/clearCache.php";
    require_once "utils/purifier.php";
    require_once "utils/PDOExt.php";
    require_once "utils/debug.php";
    require_once "utils/i18n.php";

    require_once "backends/backend.php";

    require_once "api/api.php";

    $required_backends = [
        "authentication",
        "authorization",
        "accounting",
        "users",
    ];

    $config = false;
    $db = false;
    $redis = false;

    $http_authorization = @$_SERVER['HTTP_AUTHORIZATION'];
    $refresh = array_key_exists('X-Api-Refresh', apache_request_headers());

    try {
        mb_internal_encoding("UTF-8");
    } catch (Throwable $e) {
        error_log(print_r($e, true));
        response(555, [
            "error" => "mbstring",
        ]);
    }

    try {
        $config = @json_decode(file_get_contents(__DIR__ . "/config/config.json"), true);
    } catch (Throwable $e) {
        $config = false;
    }

    if (!$config) {
        error_log("noConfig");
        response(555, [
            "error" => "noConfig",
        ]);
    }

    if (@!$config["backends"]) {
        error_log("noBackends");
        response(555, [
            "error" => "noBackends",
        ]);
    }

    $ip = false;
    if (isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    if (!$ip) {
        if (isset($_SERVER[$real_ip_header])) {
            $ip = $_SERVER[$real_ip_header];
        }
    }

    if (!$ip) {
        error_log("noIp");
        response(555, [
            "error" => "noIp",
        ]);
    }

    $redis_cache_ttl = @$config["redis"]["frontend_cache_ttl"] ? : 3600;

    try {
        $redis = new Redis();
        $redis->connect($config["redis"]["host"], $config["redis"]["port"]);
        if (@$config["redis"]["password"]) {
            $redis->auth($config["redis"]["password"]);
        }
        $redis->setex("iAmOk", 1, "1");
    } catch (Throwable $e) {
        error_log(print_r($e, true));
        response(555, [
            "error" => "redis",
        ]);
    }

    try {
        $db = new PDOExt(@$config["db"]["dsn"], @$config["db"]["username"], @$config["db"]["password"], @$config["db"]["options"]);
    } catch (Throwable $e) {
        error_log(print_r($e, true));
        response(555, [
            "error" => "PDO",
        ]);
    }

    if (@$config["db"]["schema"]) {
        $db->exec("SET search_path TO " . $config["db"]["schema"] . ", public");
    }

    $maintenance = (int)$db->get("select count(*) as maintenance from core_vars where var_name = 'maintenance'", [], false, [ 'fieldlify', 'silent' ]);

    if ($maintenance) {
        header("X-Maintenance: yes");
        response(503);
    }

    $request = explode("?", $_SERVER["REQUEST_URI"])[0];

    // Раньше в QR попадал путь …/frontend/virtual-intercom/ — он матчится location /frontend и попадает сюда же.
    // Без отдачи статики ниже путь парсится как «API» (api=virtual-intercom) и сессия без Bearer → {"error":"noToken"}.
    if (strncmp($request, "/frontend/virtual-intercom/", strlen("/frontend/virtual-intercom/")) === 0) {
        $tail = substr($request, strlen("/frontend/virtual-intercom/"));
        if ($tail === "" || $tail === "index.html") {
            $vdi = __DIR__ . "/../client/virtual-intercom/index.html";
            if (is_readable($vdi)) {
                header("Content-Type: text/html; charset=utf-8");
                readfile($vdi);
                exit;
            }
        }
    }

    // QR из guestManifest ведёт на …/virtual-intercom/ (корень сайта), если nginx проксирует сюда весь хост.
    if (strncmp($request, "/virtual-intercom/", strlen("/virtual-intercom/")) === 0) {
        $tail = substr($request, strlen("/virtual-intercom/"));
        if ($tail === "" || $tail === "index.html") {
            $vdi = __DIR__ . "/../client/virtual-intercom/index.html";
            if (is_readable($vdi)) {
                header("Content-Type: text/html; charset=utf-8");
                readfile($vdi);
                exit;
            }
        }
    }

    $frontend = parse_url(@$config["api"]["frontend"]);
    $api = parse_url(@$config["api"]["api"]);

    $path = "";

    if ($frontend && $frontend['path'] && strpos($request, $frontend["path"]) === 0) {
        $path = substr($request, strlen($frontend['path']));
    }

    if ($api && $api['path'] && strpos($request, $api["path"]) === 0) {
        $path = substr($request, strlen($api['path']));
    }

    if ($path && $path[0] == '/') {
        $path = substr($path, 1);
    }

    if (!$path) {
        response(403);
    }

    $m = explode('/', $path);

    $api = @$m[0];
    $method = @$m[1];

    // Публичный guestManifest: nginx/прокси иногда приводят сегмент пути к нижнему регистру — иначе не срабатывает bypass Bearer и отдаётся noToken.
    if (strcasecmp((string)$api, "vdom") === 0) {
        if (strcasecmp((string)$method, "guestManifest") === 0) {
            $method = "guestManifest";
        } elseif (strcasecmp((string)$method, "issueDoorTokens") === 0) {
            $method = "issueDoorTokens";
        } elseif (strcasecmp((string)$method, "openDoorOnce") === 0) {
            $method = "openDoorOnce";
        } elseif (strcasecmp((string)$method, "doorTestHook") === 0) {
            $method = "doorTestHook";
        }
        $api = "vdom";
        $m[0] = $api;
        $m[1] = $method;
    }

    $params = [];

    if (count($m) >= 3 && $m[2]) {
        $params["_id"] = urldecode($m[2]);
    }

    $params["_path"] = [
        "api" => $api,
        "method" => $method,
    ];

    $params["_request_method"] = @$_SERVER['REQUEST_METHOD'];
    $params["_ua"] = @$_SERVER["HTTP_USER_AGENT"];

    $clearCache = false;

    if (count($_GET)) {
        foreach ($_GET as $key => $value) {
            if ($key == "_token") {
                $http_authorization = "Bearer " . urldecode($value);
            } else
            if ($key == "_refresh") {
                $refresh = true;
            } else
            if ($key == "_clearCache") {
                $clearCache = true;
            } else
            if ($key == "_http_authorization") {
                $http_authorization = $value;
            } else
            if ($key === "_") {
                // prevents timestamps
            } else {
                if (gettype($value) == "string") {
                    $params[$key] = urldecode($value);
                } else {
                    $params[$key] = $value;
                }
            }
        }
    }

    if (count($_POST)) {
        foreach ($_POST as $key => $value) {
            if ($key == '_token') {
                $http_authorization = "Bearer " . urldecode($value);
            } else
            if ($key == "_refresh") {
                $refresh = true;
            } else
            if ($key == "_clearCache") {
                $clearCache = true;
            } else {
                if (gettype($value) == "string") {
                    $params[$key] = urldecode($value);
                } else {
                    $params[$key] = $value;
                }
            }
        }
    }

    $_RAW = json_decode(file_get_contents("php://input"), true);

    if ($_RAW && count($_RAW)) {
        foreach ($_RAW as $key => $value) {
            if ($key == '_token') {
                $http_authorization = "Bearer " . $value;
            } else
            if ($key == "_refresh") {
                $refresh = true;
            } else
            if ($key == "_clearCache") {
                $clearCache = true;
            } else {
                $params[$key] = $value;
            }
        }
    }

    $backends = [];
    foreach ($required_backends as $backend) {
        if (loadBackend($backend) === false) {
            error_log("noRequiredBackend");
            response(555, [
                "error" => "noRequiredBackend",
            ]);
        }
    }

    $auth = false;
    if ($api == "accounts" && $method == "forgot") {
        // do nothing
    } else
    if ($api == "server" && $method == "ping") {
        $params["_login"] = @$params["login"] ? : "-";
        $params["_ip"] = $ip;
        response(204);
    } else
    if ($api == "vdom" && $method == "guestManifest") {
        $params["_login"] = "guest";
        $params["_uid"] = 0;
        $params["_realUid"] = 0;
        $params["_ip"] = $ip;
    } else
    if ($api == "vdom" && $method == "issueDoorTokens" && $params["_request_method"] === "POST") {
        $params["_login"] = "guest";
        $params["_uid"] = 0;
        $params["_realUid"] = 0;
        $params["_ip"] = $ip;
    } else
    if ($api == "vdom" && $method == "openDoorOnce" && $params["_request_method"] === "GET") {
        $params["_login"] = "guest";
        $params["_uid"] = 0;
        $params["_realUid"] = 0;
        $params["_ip"] = $ip;
    } else
    if ($api == "vdom" && $method == "doorTestHook" && $params["_request_method"] === "GET") {
        $params["_login"] = "guest";
        $params["_uid"] = 0;
        $params["_realUid"] = 0;
        $params["_ip"] = $ip;
    } else
    if ($api == "authentication" && $method == "login") {
        if  (!@$params["login"] || !@$params["password"]) {
            $params["_login"] = @$params["login"] ? : "-";
            $params["_ip"] = $ip;
            response(403, [
                "error" => "noCredentials",
            ]);
        }
    } else {
        if ($http_authorization) {
            $auth = $backends["authentication"]->auth($http_authorization, @$_SERVER["HTTP_USER_AGENT"], $ip);
            if (!$auth) {
                $params["_ip"] = $ip;
                $params["_login"] = '-';
                response(403, [
                    "error" => "tokenNotFound",
                ]);
            }
        } else {
            $params["_ip"] = $ip;
            $params["_login"] = '-';
            response(403, [
                "error" => "noToken",
            ]);
        }
    }

    if ($http_authorization && $auth) {
        $params["_uid"] = $auth["uid"];
        $params["_realUid"] = @$auth["realUid"] ?: $auth["uid"];
        $params["_login"] = $auth["login"];
        $params["_token"] = $auth["token"];

        foreach ($backends as $backend) {
            $backend->setCreds($auth["uid"], $auth["login"]);
        }
    }

    $authUid = (is_array($auth) && isset($auth["uid"])) ? (int) $auth["uid"] : 0;

    $params["_md5"] = md5(print_r($params, true));

    $params["_config"] = $config;
    $params["_redis"] = $redis;
    $params["_db"] = $db;

    $params["_backends"] = $backends;

    $params["_ip"] = $ip;

    if (@$params["_login"]) {
        $redis->set("LAST:ACTION:" . md5($params["_login"]), time());
    }

    if ($api == "accounts" && $method == "forgot") {
        forgot($params);
    } else
    if (file_exists(__DIR__ . "/api/$api/$method.php")) {
        if ($backends["authorization"]->allow($params)) {
            /* Матрица прав не должна жить в FRONT-кэше: смена групп не меняет URL/_md5 */
            $skipFrontCache = ($api === "authorization" && $method === "available");

            $cache = false;
            if ($params["_request_method"] === "GET" && !$skipFrontCache && $authUid > 0) {
                try {
                    $cache = json_decode($redis->get("CACHE:FRONT:" . strtoupper($params["_md5"]) . ":" . $authUid), true);
                } catch (Throwable $e) {
                    error_log(print_r($e, true));
                }
            }
            if ($cache && !$refresh) {
                header("X-Api-Data-Source: cache");
                $code = array_key_first($cache);
                response($code, $cache[$code]);
            } else {
                header("X-Api-Data-Source: db");
                if ($clearCache && $authUid > 0) {
                    clearCache($authUid);
                }
                if (file_exists(__DIR__ . "/api/$api/custom/$method.php")) {
                    $file = __DIR__ . "/api/$api/custom/$method.php";
                    $class = "\\api\\$api\\custom\\$method";
                } else {
                    $file = __DIR__ . "/api/$api/$method.php";
                    $class = "\\api\\$api\\$method";
                }
                require_once $file;
                if (class_exists($class)) {
                    try {
                        $result = call_user_func([$class, $params["_request_method"]], $params);
                        $code = array_key_first($result);
                        if ((int)$code) {
                            if ($params["_request_method"] == "GET" && (int)$code === 200 && !$skipFrontCache) {
                                $ttl = (array_key_exists("cache", $result)) ? ((int)$cache) : $redis_cache_ttl;
                                if ($authUid > 0) {
                                    $redis->setex("CACHE:FRONT:" . strtoupper($params["_md5"]) . ":" . $authUid, $ttl, json_encode($result));
                                }
                            }
                            response($code, $result[$code]);
                        } else {
                            error_log("resultCode");
                            response(555, [
                                "error" => "resultCode",
                            ]);
                        }
                    } catch (Throwable $e) {
                        error_log(print_r($e, true));
                        response(555, [
                            "error" => "internal",
                        ]);
                    }
                } else {
                    response(405, [
                        "error" => "methodNotFound",
                    ]);
                }
            }
        } else {
            response(403, [
                "error" => "accessDenied",
            ]);
        }
    } else {
        response(404, [
            "error" => "methodNotFound",
        ]);
    }

    response(400, [
        "error" => "badRequest",
    ]);