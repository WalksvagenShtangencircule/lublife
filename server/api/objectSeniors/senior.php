<?php

/**
 * @api {post} /api/objectSeniors/senior создать ЛК старшего
 * @api {put} /api/objectSeniors/senior/:id изменить
 * @api {delete} /api/objectSeniors/senior/:id удалить
 */

namespace api\objectSeniors {

    use api\api;

    class senior extends api {

        public static function POST($params) {
            global $db;
            require_once __DIR__ . "/../../utils/objectSeniorService.php";

            $houseId = (int)@$params["houseId"];
            $login = trim((string)@$params["login"]);
            $password = (string)@$params["password"];
            $title = trim((string)@$params["title"]);
            if ($houseId <= 0 || $login === "" || strlen($password) < 6) {
                return api::ERROR("badRequest");
            }

            $slug = \ObjectSeniorAuth::newSlug();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $db->insert(
                "INSERT INTO houses_object_seniors (address_house_id, slug, login, password_hash, title, can_view_events, can_manage_subscribers, can_manage_entrance_access, created_at) VALUES (:h, :slug, :login, :ph, :title, :ce, :cs, :ca, :ts)",
                [
                    "h" => $houseId,
                    "slug" => $slug,
                    "login" => $login,
                    "ph" => $hash,
                    "title" => $title,
                    "ce" => (int)!!@$params["can_view_events"],
                    "cs" => (int)!!@$params["can_manage_subscribers"],
                    "ca" => (int)!!@$params["can_manage_entrance_access"],
                    "ts" => time(),
                ]
            );
            if ($ins === false) {
                return api::ERROR("internal");
            }
            $sid = (int)$ins;

            $flatIds = @$params["scopedFlatIds"];
            if (is_array($flatIds) && count($flatIds)) {
                $clean = [];
                foreach ($flatIds as $fid) {
                    $fid = (int)$fid;
                    if ($fid > 0 && \ObjectSeniorService::flatBelongsToHouse($db, $houseId, $fid)) {
                        $clean[] = $fid;
                    }
                }
                if (count($clean) && !\ObjectSeniorService::setScopedFlats($db, $sid, $clean)) {
                    return api::ERROR("internal");
                }
            }

            return api::ANSWER([ "seniorId" => $sid, "slug" => $slug ], "objectSeniors");
        }

        public static function PUT($params) {
            global $db;
            require_once __DIR__ . "/../../utils/objectSeniorService.php";

            $id = (int)@$params["_id"];
            if ($id <= 0) {
                return api::ERROR("badRequest");
            }
            $row = \ObjectSeniorService::rowById($db, $id);
            if (!$row) {
                return api::ERROR("notFound");
            }

            $sets = [];
            $bind = [ "id" => $id ];

            if (array_key_exists("title", $params)) {
                $sets[] = "title = :title";
                $bind["title"] = trim((string)$params["title"]);
            }
            if (array_key_exists("login", $params)) {
                $sets[] = "login = :login";
                $bind["login"] = trim((string)$params["login"]);
            }
            if (!empty($params["password"])) {
                if (strlen((string)$params["password"]) < 6) {
                    return api::ERROR("badRequest");
                }
                $sets[] = "password_hash = :ph";
                $bind["ph"] = password_hash((string)$params["password"], PASSWORD_DEFAULT);
            }
            foreach (["can_view_events", "can_manage_subscribers", "can_manage_entrance_access"] as $k) {
                if (array_key_exists($k, $params)) {
                    $sets[] = "$k = :" . $k;
                    $bind[$k] = (int)!!$params[$k];
                }
            }

            if (count($sets)) {
                $sql = "UPDATE houses_object_seniors SET " . implode(", ", $sets) . " WHERE house_object_senior_id = :id";
                if ($db->modify($sql, $bind) === false) {
                    return api::ERROR("internal");
                }
            }

            if (array_key_exists("scopedFlatIds", $params)) {
                $houseId = (int)$row["address_house_id"];
                $flatIds = $params["scopedFlatIds"];
                if (!is_array($flatIds)) {
                    return api::ERROR("badRequest");
                }
                $clean = [];
                foreach ($flatIds as $fid) {
                    $fid = (int)$fid;
                    if ($fid > 0 && \ObjectSeniorService::flatBelongsToHouse($db, $houseId, $fid)) {
                        $clean[] = $fid;
                    }
                }
                if (!\ObjectSeniorService::setScopedFlats($db, $id, $clean)) {
                    return api::ERROR("internal");
                }
            }

            return api::ANSWER(true, "operationResult");
        }

        public static function DELETE($params) {
            global $db;
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $id = (int)@$params["_id"];
            if ($id <= 0) {
                return api::ERROR("badRequest");
            }
            if (!\ObjectSeniorService::rowById($db, $id)) {
                return api::ERROR("notFound");
            }
            if (!\ObjectSeniorService::deleteSenior($db, $id)) {
                return api::ERROR("internal");
            }
            return api::ANSWER(true, "operationResult");
        }

        public static function index() {
            return [
                "POST" => "#same(addresses,house,POST)",
                "PUT" => "#same(addresses,house,PUT)",
                /* Не house/DELETE (удаление здания): иначе у роли с созданием/редактированием дома нет права убрать ЛК старшего. */
                "DELETE" => "#same(addresses,house,POST)",
            ];
        }
    }
}

