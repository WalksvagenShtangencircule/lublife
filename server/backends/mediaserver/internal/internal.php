<?php

    /**
     * Flussonic Media Server: REST /streamer/api/v3, учётные данные из dvr.servers + опции mediaserver.
     */

    namespace backends\mediaserver {

        class internal extends mediaserver {

            private function msConfig(): array {
                return is_array(@$this->config["backends"]["mediaserver"]) ? $this->config["backends"]["mediaserver"] : [];
            }

            /**
             * Первый сервер Flussonic из dvr.servers или по индексу mediaserver.dvr_server_index.
             */
            private function flussonicServer(): ?array {
                $dvr = loadBackend("dvr");
                if (!$dvr) {
                    return null;
                }
                $servers = $dvr->getDVRServers();
                if (!is_array($servers) || !count($servers)) {
                    return null;
                }
                $idx = (int)(@$this->msConfig()["dvr_server_index"] ?: 0);
                if (isset($servers[$idx]) && (@$servers[$idx]["type"] === "flussonic")) {
                    return $servers[$idx];
                }
                foreach ($servers as $s) {
                    if (@$s["type"] === "flussonic") {
                        return $s;
                    }
                }
                return null;
            }

            private function apiBaseAndHost(): array {
                $srv = $this->flussonicServer();
                if (!$srv || empty($srv["url"])) {
                    return ["", "", ""];
                }
                $override = trim((string)(@$this->msConfig()["api_base"] ?: ""));
                $u = parse_url($override !== "" ? $override : $srv["url"]);
                if (!$u || empty($u["scheme"]) || empty($u["host"])) {
                    return ["", "", ""];
                }
                $port = isset($u["port"]) ? (int)$u["port"] : ($u["scheme"] === "https" ? 443 : 80);
                $base = $u["scheme"] . "://" . $u["host"] . (isset($u["port"]) ? (":" . $u["port"]) : "");
                return [$base, $u["host"], (string)$port];
            }

            private function authHeader(): ?string {
                $srv = $this->flussonicServer();
                if (!$srv) {
                    return null;
                }
                $user = trim((string)(@$srv["api_user"] ?: @$this->msConfig()["api_user"] ?: ""));
                $pass = (string)(@$srv["api_password"] ?: @$this->msConfig()["api_password"] ?: "");
                $mgmt = trim((string)(@$srv["management_token"] ?: ""));
                $placeholder = (strpos($mgmt, "<!--") !== false || $mgmt === "");
                if ($user !== "") {
                    return "Basic " . base64_encode($user . ":" . $pass);
                }
                if (!$placeholder && $mgmt !== "") {
                    return "Bearer " . $mgmt;
                }
                return null;
            }

            private function verifySsl(): bool {
                if (array_key_exists("verify_ssl", $this->msConfig())) {
                    return (bool)$this->msConfig()["verify_ssl"];
                }
                return true;
            }

            private function apiPrefix(): string {
                $p = trim((string)(@$this->msConfig()["api_prefix"] ?: "/streamer/api/v3"));
                return $p === "" ? "/streamer/api/v3" : $p;
            }

            /**
             * @return array{ok:bool,code:int,data:mixed,raw:string,error:string}
             */
            private function apiRequest(string $method, string $path, $jsonBody = null): array {
                [$base] = $this->apiBaseAndHost();
                $auth = $this->authHeader();
                if ($base === "" || $auth === null) {
                    return ["ok" => false, "code" => 0, "data" => null, "raw" => "", "error" => "mediaserverNotConfigured"];
                }
                $url = rtrim($base, "/") . "/" . ltrim($path, "/");
                $ch = curl_init($url);
                $headers = [
                    "Accept: application/json",
                ];
                if ($jsonBody !== null) {
                    $headers[] = "Content-Type: application/json";
                }
                $headers[] = "Authorization: " . $auth;
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => (int)(@$this->msConfig()["http_timeout"] ?: 25),
                    CURLOPT_SSL_VERIFYPEER => $this->verifySsl(),
                    CURLOPT_SSL_VERIFYHOST => $this->verifySsl() ? 2 : 0,
                ]);
                if ($jsonBody !== null) {
                    $payload = $jsonBody === [] ? "{}" : json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);
                if ($raw === false) {
                    return ["ok" => false, "code" => $code, "data" => null, "raw" => "", "error" => $cerr ?: "curlError"];
                }
                $data = json_decode($raw, true);
                if ($data === null && $raw !== "" && $raw !== "null") {
                    $data = $raw;
                }
                $ok = $code >= 200 && $code < 300;
                return ["ok" => $ok, "code" => $code, "data" => $data, "raw" => $raw, "error" => $ok ? "" : "http" . $code];
            }

            private function streamNameFromDvrStream(?string $dvrStream): ?string {
                if (!$dvrStream) {
                    return null;
                }
                $path = parse_url($dvrStream, PHP_URL_PATH);
                if (!$path) {
                    return null;
                }
                $path = ltrim($path, "/");
                if ($path === "") {
                    return null;
                }
                $parts = explode("/", $path);
                return $parts[0] !== "" ? $parts[0] : null;
            }

            private function serverMatchesCamera(array $server, string $dvrStream): bool {
                $url = parse_url($dvrStream);
                if (!$url || empty($url["host"])) {
                    return false;
                }
                $su = parse_url($server["url"]);
                if (!$su || empty($su["host"])) {
                    return false;
                }
                $port = isset($url["port"]) ? (int)$url["port"] : ($url["scheme"] === "https" ? 443 : 80);
                $sp = isset($su["port"]) ? (int)$su["port"] : ($su["scheme"] === "https" ? 443 : 80);
                return $su["host"] === $url["host"] && $sp === $port
                    && (!isset($su["user"]) || !isset($url["user"]) || $su["user"] === $url["user"]);
            }

            private function buildHlsUrl(string $streamName): string {
                [$base, $host, $port] = $this->apiBaseAndHost();
                $srv = $this->flussonicServer();
                $hlsMode = strtolower(trim((string)(@$srv["hlsMode"] ?: "mpegts")));
                $suffix = ($hlsMode === "fmp4" || $hlsMode === "ll-hls") ? "index.ll.m3u8" : "index.m3u8";
                $tpl = trim((string)(@$this->msConfig()["hls_url_template"] ?: ""));
                if ($tpl !== "") {
                    return str_replace(["{base}", "{stream}", "{suffix}"], [rtrim($base, "/"), rawurlencode($streamName), $suffix], $tpl);
                }
                return rtrim($base, "/") . "/" . rawurlencode($streamName) . "/" . $suffix;
            }

            private function buildEmbedUrl(string $streamName): string {
                [$base] = $this->apiBaseAndHost();
                $tpl = trim((string)(@$this->msConfig()["embed_url_template"] ?: ""));
                if ($tpl !== "") {
                    return str_replace(["{base}", "{stream}"], [rtrim($base, "/"), rawurlencode($streamName)], $tpl);
                }
                return rtrim($base, "/") . "/" . rawurlencode($streamName) . "/embed.html";
            }

            private function appendPlaybackToken(string $url, ?string $tokenFromConfig): string {
                $t = trim((string)$tokenFromConfig);
                if ($t === "" || strpos($t, "<!--") !== false) {
                    return $url;
                }
                if (preg_match('/^token\s*=\s*(.+)\s*$/i', $t, $m)) {
                    $t = $m[1];
                }
                $glue = strpos($url, "?") !== false ? "&" : "?";
                return $url . $glue . "token=" . rawurlencode($t);
            }

            private function mapStreamState($stream): string {
                if (!is_array($stream)) {
                    return "unknown";
                }
                if (!empty($stream["error"]) || !empty($stream["disabled_reason"])) {
                    return "error";
                }
                if (isset($stream["running"]) && $stream["running"]) {
                    return "on";
                }
                if (isset($stream["alive"]) && $stream["alive"]) {
                    return "on";
                }
                if (isset($stream["enabled"]) && !$stream["enabled"]) {
                    return "off";
                }
                if (isset($stream["stats"]["alive"])) {
                    return $stream["stats"]["alive"] ? "on" : "off";
                }
                return "off";
            }

            private function normalizeStreamsList($data): array {
                if (!is_array($data)) {
                    return [];
                }
                if (isset($data["streams"]) && is_array($data["streams"])) {
                    return array_values($data["streams"]);
                }
                if ($data === []) {
                    return [];
                }
                $keys = array_keys($data);
                $numeric = $keys === range(0, count($data) - 1);
                return $numeric ? $data : array_values($data);
            }

            private function auditWrite(string $action, ?string $streamName, ?int $cameraId, array $details): void {
                try {
                    $this->db->insert(
                        "insert into mediaserver_audit (created_at, login, action, stream_name, camera_id, details) values (:created_at, :login, :action, :stream_name, :camera_id, :details)",
                        [
                            "created_at" => time(),
                            "login" => (string)$this->login,
                            "action" => $action,
                            "stream_name" => $streamName,
                            "camera_id" => $cameraId,
                            "details" => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]
                    );
                } catch (\Throwable $e) {
                    error_log("mediaserver audit: " . $e->getMessage());
                }
            }

            public function getStreamsOverview() {
                $srv = $this->flussonicServer();
                if (!$srv) {
                    return false;
                }
                $prefix = $this->apiPrefix();
                $r = $this->apiRequest("GET", $prefix . "/streams");
                if (!$r["ok"] && $r["code"] === 0) {
                    return [
                        "serverTitle" => (string)(@$srv["title"] ?: ""),
                        "apiError" => $r["error"],
                        "streams" => [],
                    ];
                }
                if (!$r["ok"]) {
                    return [
                        "serverTitle" => (string)(@$srv["title"] ?: ""),
                        "apiError" => $r["error"] . ($r["raw"] ? (": " . substr($r["raw"], 0, 200)) : ""),
                        "streams" => [],
                    ];
                }
                $rawList = $this->normalizeStreamsList($r["data"]);
                $byName = [];
                foreach ($rawList as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $n = $item["name"] ?? $item["stream"] ?? null;
                    if (!$n) {
                        continue;
                    }
                    $byName[(string)$n] = $item;
                }

                $cameras = loadBackend("cameras");
                $cams = $cameras ? $cameras->getCameras(false, false, false) : [];
                $camByStream = [];
                foreach ($cams as $cam) {
                    $ds = $cam["dvrStream"] ?? "";
                    if ($ds === "" || !$this->serverMatchesCamera($srv, $ds)) {
                        continue;
                    }
                    $sn = $this->streamNameFromDvrStream($ds);
                    if ($sn) {
                        $camByStream[$sn] = $cam;
                    }
                }

                $token = @$srv["token"];
                $out = [];
                foreach ($byName as $name => $meta) {
                    $cam = $camByStream[$name] ?? null;
                    $hls = $this->appendPlaybackToken($this->buildHlsUrl($name), $token);
                    $embed = $this->appendPlaybackToken($this->buildEmbedUrl($name), $token);
                    $out[] = [
                        "streamName" => $name,
                        "state" => $this->mapStreamState($meta),
                        "cameraId" => $cam ? (int)$cam["cameraId"] : null,
                        "cameraName" => $cam ? (string)($cam["name"] ?: "") : "",
                        "hlsUrl" => $hls,
                        "embedUrl" => $embed,
                        "raw" => $meta,
                    ];
                }

                return [
                    "serverTitle" => (string)(@$srv["title"] ?: ""),
                    "streams" => $out,
                ];
            }

            public function upsertStream(string $name, array $body = []): array {
                $name = trim($name);
                if ($name === "") {
                    return ["ok" => false, "error" => "noStreamName"];
                }
                $prefix = $this->apiPrefix();
                $path = $prefix . "/streams/" . rawurlencode($name);
                $payload = count($body) ? $body : new \stdClass();
                $r = $this->apiRequest("PUT", $path, $payload);
                if ($r["ok"]) {
                    $this->auditWrite("upsert_stream", $name, null, ["code" => $r["code"], "body" => $body]);
                } else {
                    $this->auditWrite("upsert_stream_failed", $name, null, ["code" => $r["code"], "error" => $r["error"], "raw" => substr($r["raw"], 0, 500)]);
                }
                return $r;
            }

            public function deleteStream(string $name): array {
                $name = trim($name);
                if ($name === "") {
                    return ["ok" => false, "error" => "noStreamName"];
                }
                $prefix = $this->apiPrefix();
                $r = $this->apiRequest("DELETE", $prefix . "/streams/" . rawurlencode($name));
                if ($r["ok"]) {
                    $this->auditWrite("delete_stream", $name, null, ["code" => $r["code"]]);
                } else {
                    $this->auditWrite("delete_stream_failed", $name, null, ["code" => $r["code"], "error" => $r["error"], "raw" => substr($r["raw"], 0, 500)]);
                }
                return $r;
            }

            public function applyUrlsToCamera(int $cameraId, string $hlsUrl, string $embedUrl): bool {
                $cameras = loadBackend("cameras");
                if (!$cameras) {
                    return false;
                }
                $list = $cameras->getCameras("id", $cameraId, false);
                if (!is_array($list) || !count($list)) {
                    setLastError("noCamera");
                    return false;
                }
                $c = $list[0];
                $ok = $cameras->modifyCamera(
                    $cameraId,
                    $c["enabled"],
                    $c["model"],
                    $c["url"],
                    $hlsUrl,
                    $c["credentials"],
                    $c["name"],
                    $embedUrl,
                    $c["timezone"],
                    $c["lat"],
                    $c["lon"],
                    $c["direction"],
                    $c["angle"],
                    $c["distance"],
                    $c["frs"],
                    $c["frsMode"],
                    $c["mdArea"],
                    $c["rcArea"],
                    $c["common"],
                    $c["comments"],
                    $c["sound"],
                    $c["monitoring"],
                    $c["webrtc"],
                    $c["ext"],
                    $c["tree"] ?? ""
                );
                if ($ok) {
                    $this->auditWrite("apply_camera_urls", $this->streamNameFromDvrStream($embedUrl), $cameraId, [
                        "hlsUrl" => $hlsUrl,
                        "embedUrl" => $embedUrl,
                    ]);
                }
                return (bool)$ok;
            }

            public function getAuditLog(int $limit = 200, int $offset = 0) {
                $limit = max(1, min(500, $limit));
                $offset = max(0, $offset);
                $rows = $this->db->get(
                    "select id, created_at, login, action, stream_name, camera_id, details from mediaserver_audit order by id desc limit $limit offset $offset",
                    [],
                    [
                        "id" => "id",
                        "created_at" => "createdAt",
                        "login" => "login",
                        "action" => "action",
                        "stream_name" => "streamName",
                        "camera_id" => "cameraId",
                        "details" => "details",
                    ]
                );
                if (!is_array($rows)) {
                    return [];
                }
                foreach ($rows as &$row) {
                    $d = $row["details"];
                    if (is_string($d)) {
                        $dec = json_decode($d, true);
                        $row["details"] = $dec !== null ? $dec : $d;
                    }
                }
                return $rows;
            }
        }
    }
