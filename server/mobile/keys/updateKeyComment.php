<?php

    /**
     * @api {post} /mobile/keys/updateKeyComment изменить комментарий к ключу квартиры (жилец)
     *
     * @apiBody {Number} flatId
     * @apiBody {Number} keyId
     * @apiBody {String} comments
     */

    require_once __DIR__ . '/_keys_common.php';

    auth();

    $households = loadBackend("households");

    $flatId = (int) @$postdata['flatId'];
    $keyId = (int) @$postdata['keyId'];
    $comments = trim((string) @$postdata['comments']);

    if (!$flatId || !$keyId) {
        response(422, false, false, 'Укажите flatId и keyId');
    }

    if (strlen($comments) > 128) {
        response(422, false, false, 'Комментарий слишком длинный');
    }

    if (!keys_user_has_any_flat($subscriber)) {
        response(403, false, false, 'Нет привязанных квартир');
    }

    if (!keys_subscriber_has_flat($subscriber, $flatId)) {
        response(403, false, false, 'Нет доступа к этой квартире');
    }

    $keys = $households->getKeys("keyId", $keyId);
    if (!$keys || !count($keys)) {
        response(404, false, false, 'Ключ не найден');
    }

    $k = $keys[0];
    if ((int) $k['accessType'] !== 2 || (int) $k['accessTo'] !== $flatId) {
        response(403, false, false, 'Ключ не относится к этой квартире');
    }

    if (!$households->modifyKey($keyId, $comments)) {
        response(500, false, false, 'Не удалось сохранить');
    }

    response(200, [ "ok" => true ]);
