<?php

    namespace backends\isdn
    {
        require_once __DIR__ . "/../.traits/incoming.php";

        class custom extends isdn
        {
            use incoming;

            private function normalizeMobile($id): string
            {
                $mobile = preg_replace('/\D+/', '', (string)$id);
                if (!$mobile) {
                    return '';
                }
                if (strlen($mobile) === 11 && $mobile[0] === '8') {
                    $mobile[0] = '7';
                } elseif (strlen($mobile) === 10) {
                    $mobile = '7' . $mobile;
                }
                return $mobile;
            }

            function confirmNumbers()
            {
                $n = @$this->config["backends"]["isdn"]["confirm_number"];
                return $n ? [ $n ] : [];
            }

            function push($push)
            {
                $query = "";
                foreach ($push as $param => $value) {
                    if ($param != "action" && $param != "secret" && $param != "video") {
                        $query = $query . $param . "=" . urlencode($value) . "&";
                    }
                    if ($param == "action") {
                        $query = $query . "pushAction=" . urlencode($value) . "&";
                    }
                    if ($param == "video") {
                        $query = $query . "video=" . urlencode(json_encode($value)) . "&";
                    }
                }
                if ($query) {
                    $query = substr($query, 0, -1);
                }

                $result = trim(file_get_contents("http://127.0.0.1:8080/push?" . $query));

                if (strtolower(explode(":", $result)[0]) !== "ok") {
                    error_log("isdn push send error:\n query = $query\n result = $result\n");
                    if (strtolower($result) === "err:broken") {
                        loadBackend("households")->dismissToken($push["token"]);
                    }
                }

                return $result;
            }

            function sendCode($id)
            {
                $api_host = $this->config["backends"]["isdn"]["api_host"];
                $api_login = $this->config["backends"]["isdn"]["api_login"];
                $api_password = $this->config["backends"]["isdn"]["api_password"];
                $api_sender = $this->config["backends"]["isdn"]["api_sender"];

                $pin = sprintf("%04d", rand(0, 9999));
                $message = "Ваш код подтверждения: $pin";

                $url = $api_host . '/outbox/send';
                $url .= '?login=' . urlencode($api_login);
                $url .= '&password=' . urlencode($api_password);
                $url .= '&target=' . urlencode($id);
                $url .= '&sender=' . urlencode($api_sender);
                $url .= '&message=' . urlencode($message);

                $response = file_get_contents($url);

                if ($response) {
                    $response = trim($response);
                    if (is_numeric($response) && (int)$response > 0) {
                        return $pin;
                    }
                    error_log("Error send SMS to " . $id . " error code: " . $response);
                    response(503, null, null, "SMS service error");
                    exit(1);
                }
                error_log("Error send SMS to " . $id . " service unavailable");
                response(503, null, null, "SMS Service Unavailable");
                exit(1);
            }

            function checkIncoming($id)
            {
                $mobile = $this->normalizeMobile($id);
                if (!$mobile) {
                    return 0;
                }
                if ($this->redis->get("isdn_incoming_+$mobile")) {
                    return 1;
                }
                if ($this->redis->get("isdn_incoming_$mobile")) {
                    return 1;
                }
                if ($mobile[0] === '7') {
                    $mobile8 = '8' . substr($mobile, 1);
                    if ($this->redis->get("isdn_incoming_$mobile8")) {
                        return 1;
                    }
                }
                return 0;
            }
        }
    }
