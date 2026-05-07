<?php

    /**
     * @api {post} /mobile/ext/ext выдача HTML web-расширений «Ключи»
     */

    auth();

    global $config;

    $extId = @$postdata["extId"];

    $webBase = @$config["mobile"]["web_server_base_path"];
    if (!$webBase) {
        $webBase = "https://localhost/static/";
    }
    $basePath = rtrim($webBase, "/") . "/portal/keys/";

    $staticDir = __DIR__ . "/../../../../static/portal/keys/";

    $map = [
        "keys_join_001" => "join-flat.html",
        "keys_flat_002" => "flat-keys.html",
    ];

    if (!isset($map[$extId])) {
        response(404, false, false, "Расширение не найдено");
    }

    $file = $staticDir . $map[$extId];
    $html = @file_get_contents($file);

    if ($html === false || $html === "") {
        response(500, false, false, "Файл расширения недоступен: " . $map[$extId]);
    }

    response(200, [
        "basePath" => $basePath,
        "code" => $html,
        "version" => 2,
    ]);
