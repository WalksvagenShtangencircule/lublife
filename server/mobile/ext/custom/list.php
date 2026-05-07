<?php

    /**
     * @api {post} /mobile/ext/list список web-расширений (меню) — модуль «Ключи»
     */

    auth();

    $iconDir = __DIR__ . '/../../../../static/portal/keys/';
    $fallbackPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    $rawStart = @file_get_contents($iconDir . 'menu-icon-start.png');
    $rawKeys = @file_get_contents($iconDir . 'menu-icon-keys.png');

    $iconStart = ($rawStart !== false && strlen($rawStart) > 0)
        ? ('data:image/png;base64,' . base64_encode($rawStart))
        : $fallbackPng;

    $iconKeys = ($rawKeys !== false && strlen($rawKeys) > 0)
        ? ('data:image/png;base64,' . base64_encode($rawKeys))
        : $fallbackPng;

    response(200, [
        [
            "caption" => "Ключ на старт!!!",
            "icon" => $iconStart,
            "order" => 245,
            "extId" => "keys_join_001",
            "highlight" => "f",
        ],
        [
            "caption" => "Ключи квартиры",
            "icon" => $iconKeys,
            "order" => 246,
            "extId" => "keys_flat_002",
            "highlight" => "f",
        ],
    ]);
