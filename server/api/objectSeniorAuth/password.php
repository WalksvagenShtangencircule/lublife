<?php

/**
 * @api {put} /api/objectSeniorAuth/password смена пароля ЛК старшего
 */

namespace api\objectSeniorAuth {

    use api\api;

    class password extends api {

        public static function PUT($params) {
            global $db, $redis;

            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["seniorId"])) {
                return api::ERROR("accessDenied");
            }

            $old = (string)@$params["oldPassword"];
            $new = (string)@$params["newPassword"];
            if (strlen($new) < 6) {
                return api::ERROR("badRequest");
            }

            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $row = \ObjectSeniorService::rowById($db, (int)$om["seniorId"]);
            if (!$row) {
                return api::ERROR("notFound");
            }

            if (!password_verify($old, (string)$row["password_hash"])) {
                return api::ERROR("accessDenied");
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);
            $ok = $db->modify(
                "UPDATE houses_object_seniors SET password_hash = :h WHERE house_object_senior_id = :id",
                [ "h" => $hash, "id" => (int)$om["seniorId"] ]
            );
            if ($ok === false) {
                return api::ERROR("internal");
            }

            $tok = \ObjectSeniorAuth::bearerRawToken(@$_SERVER["HTTP_AUTHORIZATION"] ?? "");
            \ObjectSeniorAuth::deleteSession($redis, $tok);

            return api::ANSWER([ "ok" => true ], "objectSeniorAuth");
        }

        public static function index() {
            return [
                "PUT" => false,
            ];
        }
    }
}

