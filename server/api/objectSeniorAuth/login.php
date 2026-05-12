<?php

/**
 * @api {post} /api/objectSeniorAuth/login вход в ЛК старшего (slug + login + password)
 */

namespace api\objectSeniorAuth {

    use api\api;

    class login extends api {

        public static function POST($params) {
            global $db, $redis;

            $slug = trim((string)@$params["slug"]);
            $login = trim((string)@$params["login"]);
            $password = (string)@$params["password"];
            if ($slug === "" || $login === "" || $password === "") {
                return api::ERROR("badRequest");
            }

            require_once __DIR__ . "/../../utils/objectSeniorService.php";

            $row = \ObjectSeniorService::rowBySlug($db, $slug);
            if (!$row || strcasecmp(trim((string)$row["login"]), $login) !== 0) {
                return api::ERROR("accessDenied");
            }

            if (!password_verify($password, (string)$row["password_hash"])) {
                return api::ERROR("accessDenied");
            }

            $scoped = \ObjectSeniorService::scopedFlatIds($db, (int)$row["house_object_senior_id"]);

            $payload = [
                "seniorId" => (int)$row["house_object_senior_id"],
                "houseId" => (int)$row["address_house_id"],
                "slug" => (string)$row["slug"],
                "can_view_events" => (int)($row["can_view_events"] ?? 0),
                "can_manage_subscribers" => (int)($row["can_manage_subscribers"] ?? 0),
                "can_manage_entrance_access" => (int)($row["can_manage_entrance_access"] ?? 0),
                "flatIds" => $scoped,
                "title" => (string)($row["title"] ?? ""),
                "houseFull" => (string)($row["house_full"] ?? ""),
            ];

            $token = \ObjectSeniorAuth::newToken();
            if (!\ObjectSeniorAuth::saveSession($redis, $token, $payload)) {
                return api::ERROR("internal");
            }

            return api::ANSWER([
                "token" => $token,
                "senior" => [
                    "seniorId" => $payload["seniorId"],
                    "houseId" => $payload["houseId"],
                    "title" => $payload["title"],
                    "houseFull" => $payload["houseFull"],
                    "can_view_events" => $payload["can_view_events"],
                    "can_manage_subscribers" => $payload["can_manage_subscribers"],
                    "can_manage_entrance_access" => $payload["can_manage_entrance_access"],
                ],
            ], "objectSeniorAuth");
        }

        public static function index() {
            return [
                "POST" => false,
            ];
        }
    }
}

