<?php

    /**
     * @api {post} /mobile/address/watch watch for event
     * @apiVersion 1.0.0
     * @apiDescription **должен работать**
     *
     * @apiGroup Address
     *
     * @apiHeader {string} authorization токен авторизации
     *
     * @apiBody {integer} flatId идентификатор квартиры
     * @apiBody {integer="3 - открытие ключом","4 - открытие приложением","5 - открытие по морде лица","6 - открытие кодом открытия","9 - открытие по номеру машины"} -.eventType тип события
     * @apiBody {string} eventDetail детали события (ключ, номер телефона, идентификатор лица, номер машины)
     * @apiBody {string} comments комментарий наблюдения
     *
     * @apiErrorExample Ошибки
     * 403 требуется авторизация
     * 422 неверный формат данных
     * 404 пользователь не найден
     * 410 авторизация отозвана
     * 424 неверный токен
     */

    auth();

    $households = loadBackend("households");

    $flat_id = (int)@$postdata['flatId'];
    if (!$flat_id) {
        response(422);
    }

    $eventType = (string) @$postdata["eventType"];
    $eventDetail = trim((string) @$postdata["eventDetail"]);
    $comments = (string) @$postdata["comments"];

    $watcherId = $households->watch($device["deviceId"], $flat_id, $eventType, $eventDetail, $comments);
    if (!$watcherId) {
        $watchers = $households->watchers($device["deviceId"], $flat_id) ?: [];
        foreach ($watchers as $watcher) {
            if ((string)$watcher["eventType"] === $eventType && (string)$watcher["eventDetail"] === $eventDetail) {
                $watcherId = (int)$watcher["houseWatcherId"];
                break;
            }
        }
    }

    response(200, [
        "watcherId" => $watcherId ? (int)$watcherId : null,
    ]);
