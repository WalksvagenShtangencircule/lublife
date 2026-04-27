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
    $out = [];

    if ($keys) {
        foreach ($keys as $k) {
            if ((int) $k['accessType'] !== 2) {
                continue;
            }
            $out[] = [
                "keyId" => (int) $k['keyId'],
                "rfId" => $k['rfId'],
                "comments" => (string) (@$k['comments'] ?: ''),
            ];
        }
    }

    response(200, $out);
