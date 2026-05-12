<?php

/**
 * @api {put} /api/objectSeniors/rotateSlug/:id новый slug для ссылки ЛК
 */

namespace api\objectSeniors {

    use api\api;

    class rotateSlug extends api {

        public static function PUT($params) {
            global $db;
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $id = (int)@$params["_id"];
            if ($id <= 0) {
                return api::ERROR("badRequest");
            }
            if (!\ObjectSeniorService::rowById($db, $id)) {
                return api::ERROR("notFound");
            }
            $slug = \ObjectSeniorAuth::newSlug();
            if ($db->modify("UPDATE houses_object_seniors SET slug = :s WHERE house_object_senior_id = :id", [ "s" => $slug, "id" => $id ]) === false) {
                return api::ERROR("internal");
            }
            return api::ANSWER([ "slug" => $slug ], "objectSeniors");
        }

        public static function index() {
            return [
                "PUT" => "#same(addresses,house,PUT)",
            ];
        }
    }
}

