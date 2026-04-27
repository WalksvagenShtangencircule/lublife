<?php

    /**
     * @api {post} /mobile/keys/joinFlat добавление в квартиру по номеру квартиры и RFID (без houseId)
     *
     * @apiBody {String} flatNumber номер квартиры (как в houses_flats.flat)
     * @apiBody {String} rfId номер ключа (RFID), должен совпадать с ключом квартиры
     * @apiBody {Boolean} acceptTerms принятие пользовательского соглашения
     * @apiBody {Boolean} acceptPrivacy принятие политики конфиденциальности
     */

    require_once __DIR__ . '/_keys_common.php';

    auth();

    $households = loadBackend("households");
    global $db;

    $post = $postdata ?: [];

    if (empty($post['acceptTerms']) || empty($post['acceptPrivacy'])) {
        response(422, false, false, 'Необходимо принять пользовательское соглашение и политику конфиденциальности');
    }

    $flatNumber = trim((string) @$post['flatNumber']);
    $rfid = keys_join_flat_normalize_rfid(@$post['rfId']);

    if ($flatNumber === '' || $rfid === false) {
        response(422, false, false, 'Укажите номер квартиры и RFID: 1–14 hex-цифр (0–9, A–F), как в RBT; короткий номер дополняется нулями слева до 14 символов.');
    }

    // Однозначная пара: номер квартиры + ключ уже привязан к этой квартире (access_type=2)
    $candidates = $db->get(
        "SELECT DISTINCT hf.house_flat_id
         FROM houses_flats hf
         INNER JOIN houses_rfids r ON r.access_type = 2 AND r.access_to = hf.house_flat_id
         WHERE trim(hf.flat) = trim(:flat)
           AND upper(trim(r.rfid)) = :rfid",
        [
            "flat" => $flatNumber,
            "rfid" => $rfid,
        ]
    );

    if ($candidates === false) {
        response(500, false, false, 'Ошибка БД');
    }

    if (count($candidates) === 0) {
        response(404, false, false, 'Квартира с таким номером и ключом не найдены. Проверьте номер квартиры и RFID.');
    }

    if (count($candidates) > 1) {
        response(409, false, false, 'Несколько совпадений по номеру квартиры и ключу. Обратитесь в управляющую компанию.');
    }

    $flatId = (int) $candidates[0]['house_flat_id'];

    $subscriberId = (int) $subscriber['subscriberId'];

    $already = $db->get(
        "SELECT 1 AS x FROM houses_flats_subscribers WHERE house_subscriber_id = :s AND house_flat_id = :f LIMIT 1",
        [
            "s" => $subscriberId,
            "f" => $flatId,
        ]
    );
    if ($already && count($already) > 0) {
        response(200, [ "alreadyMember" => true, "flatId" => $flatId ], 'OK', 'Вы уже добавлены в эту квартиру');
    }

    $_flat = $households->getFlat($flatId);
    if ((int) $_flat['subscribersLimit'] > 0) {
        $alreadyCount = (int) $db->get(
            "SELECT count(*)::int AS c FROM houses_flats_subscribers WHERE house_flat_id = :f",
            [ "f" => $flatId ],
            [ "c" => "c" ],
            [ "fieldlify" ]
        );
        if ($alreadyCount >= (int) $_flat['subscribersLimit']) {
            response(409, false, false, 'Достигнут лимит жильцов для этой квартиры');
        }
    }

    $ins = $db->insert(
        "INSERT INTO houses_flats_subscribers (house_subscriber_id, house_flat_id, role) VALUES (:s, :f, 1)",
        [
            "s" => $subscriberId,
            "f" => $flatId,
        ]
    );

    if ($ins === false) {
        response(500, false, false, 'Не удалось добавить в квартиру');
    }

    $devices = $households->getDevices("subscriber", $subscriberId);
    foreach ($devices as $device) {
        $households->setDeviceFlat($device["deviceId"], $flatId, 1);
    }

    $termsVersion = '2026-04-15';
    $privacyVersion = '2026-04-15';
    $now = time();

    $db->insert(
        "INSERT INTO mobile_legal_acceptances (house_subscriber_id, house_flat_id, action, terms_version, privacy_version, accepted_at) VALUES (:s, :f, 'join_flat', :tv, :pv, :ts)",
        [
            "s" => $subscriberId,
            "f" => $flatId,
            "tv" => $termsVersion,
            "pv" => $privacyVersion,
            "ts" => $now,
        ]
    );

    $queue = loadBackend("queue");
    if ($queue) {
        $queue->changed("subscriber", $subscriberId);
        $queue->changed("flat", $flatId);
    }

    response(200, [ "flatId" => $flatId ], 'OK', 'Вы добавлены в квартиру');
