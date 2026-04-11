<?php

    /**
     * @api {put} /api/houses/virtualDomophoneRotateToken/:domophoneId сменить slug QR
     *
     * @apiSuccess {String} guestAccessSlug
     */

    namespace api\houses {

        use api\api;

        class virtualDomophoneRotateToken extends api {

            public static function PUT($params) {
                $households = loadBackend("households");
                if (!$households) {
                    return api::ERROR();
                }

                $slug = $households->rotateVirtualDomophoneGuestSlug((int)@$params["_id"]);
                if ($slug === false) {
                    return api::ANSWER(false, "notFound");
                }

                return api::ANSWER([ "guestAccessSlug" => $slug ], "rotation");
            }

            public static function index() {
                return [
                    "PUT" => false,
                ];
            }
        }
    }
