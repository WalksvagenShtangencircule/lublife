<?php

    /**
     * @api {post} /mobile/keys/listForFlat список ключей квартиры (доступ: любой жилец квартиры)
     *
     * @apiBody {Number} flatId идентификатор квартиры
     */

    require_once __DIR__ . '/_keys_common.php';

    auth();

    $households = loadBackend("households");

    if (!keys_user_has_any_flat($subscriber)) {
        response(403, false, false, 'Нет привязанных квартир');
    }

    $flatId = (int) @$postdata['flatId'];
    if (!$flatId) {
        response(422, false, false, 'Укажите flatId');
    }

    if (!keys_subscriber_has_flat($subscriber, $flatId)) {
        response(404, false, false, 'Квартира не найдена в вашем списке');
    }

    $keys = $households->getKeys("flatId", $flatId);
    $watchers = $households->watchers($device["deviceId"], $flatId) ?: [];
    $flat = $households->getFlat($flatId);

    $watchIndex = [];
    foreach ($watchers as $watcher) {
        $eventType = (int) @$watcher["eventType"];
        $eventDetail = trim((string) (@$watcher["eventDetail"] ?: ""));
        if ($eventDetail === "") {
            continue;
        }

        if (!isset($watchIndex[$eventType])) {
            $watchIndex[$eventType] = [];
        }
        $watchIndex[$eventType][$eventDetail] = (int) @$watcher["houseWatcherId"];
    }

    $out = [];

    if ($keys) {
        foreach ($keys as $k) {
            if ((int) $k['accessType'] !== 2) {
                continue;
            }
            $rfId = (string) $k['rfId'];
            $watcherId = (int) (@$watchIndex[3][$rfId] ?: 0);

            $out[] = [
                "keyId" => (int) $k['keyId'],
                "rfId" => $rfId,
                "comments" => (string) (@$k['comments'] ?: ''),
                "watched" => $watcherId > 0,
                "watcherId" => $watcherId ?: null,
            ];
        }
    }

    $doorCode = trim((string) (@$flat["openCode"] ?: ""));
    if ($doorCode === "00000") {
        $doorCode = "";
    }

    $codeWatcherId = 0;
    if ($doorCode !== "") {
        $codeWatcherId = (int) (@$watchIndex[6][$doorCode] ?: 0);
    }

    response(200, [
        "keys" => $out,
        "doorCode" => $doorCode ?: null,
        "codeWatched" => ($doorCode !== "") ? ($codeWatcherId > 0) : false,
        "codeWatcherId" => $codeWatcherId ?: null,
    ]);
