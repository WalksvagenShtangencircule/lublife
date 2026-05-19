<?php

    /**
     * @api {post} /mobile/keys/batchAdd добавление ключей к квартире
     *
     * Условия: RFID есть в каталоге «наших» ключей (vendor_rfids_whitelist).
     *
     * @apiBody {Number} flatId квартира
     * @apiBody {Object[]} keys массив { rfId, comments }
     */

    require_once __DIR__ . '/_keys_common.php';

    auth();

    $households = loadBackend("households");
    global $db;

    if (!keys_user_has_any_flat($subscriber)) {
        response(403, false, false, 'Нет привязанных квартир');
    }

    $flatId = (int) @$postdata['flatId'];
    $items = @$postdata['keys'];

    if (!$flatId || !is_array($items) || !count($items)) {
        response(422, false, false, 'Укажите flatId и массив keys');
    }

    if (!keys_subscriber_has_flat($subscriber, $flatId)) {
        response(403, false, false, 'Нет доступа к этой квартире');
    }

    $results = [];

    foreach ($items as $row) {
        if (!is_array($row)) {
            $results[] = [ "ok" => false, "error" => "bad_row" ];
            continue;
        }

        $rfid = keys_join_flat_normalize_rfid(@$row['rfId']);
        $comments = trim((string) @$row['comments']);
        if (strlen($comments) > 128) {
            $results[] = [ "rfId" => $rfid, "ok" => false, "error" => "comments_too_long" ];
            continue;
        }

        if ($rfid === false) {
            $results[] = [ "rfId" => $rfid, "ok" => false, "error" => "invalid_rfid" ];
            continue;
        }

        $wl = $db->get(
            "SELECT rfid FROM vendor_rfids_whitelist WHERE rfid = :r",
            [ "r" => $rfid ],
            [],
            [ "singlify" ]
        );

        if (!$wl) {
            $results[] = [ "rfId" => $rfid, "ok" => false, "error" => "not_in_catalog" ];
            continue;
        }

        $dup = $households->getKeys("rfId", $rfid);
        if ($dup && count($dup) > 0) {
            $results[] = [ "rfId" => $rfid, "ok" => false, "error" => "duplicate" ];
            continue;
        }

        $keyId = $households->addKey($rfid, 2, $flatId, $comments);
        if (!$keyId) {
            $results[] = [ "rfId" => $rfid, "ok" => false, "error" => "add_failed" ];
            continue;
        }

        $results[] = [ "rfId" => $rfid, "ok" => true, "keyId" => (int) $keyId ];
    }

    response(200, [ "results" => $results ]);
