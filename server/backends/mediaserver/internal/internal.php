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

            /** Повторный rawurldecode для сегментов пути (двойное кодирование %255F → _). */
            private function decodePathSegment(string $segment): string {
                $s = $segment;
                for ($i = 0; $i < 5; $i++) {
                    $next = rawurldecode($s);
                    if ($next === $s) {
                        return $s;
                    }
                    $s = $next;
                }

                return $s;
            }

            /**
             * Имя потока из пути URL: сегмент перед embed.html, перед index(.ll).m3u8, иначе первый сегмент.
             * Декодирует %XX (чтобы Lug%5F1%5F2 совпадал с Lug_1_2 на Flussonic).
             */
            private function streamNameFromPathUrl(string $url): ?string {
                $path = parse_url($url, PHP_URL_PATH);
                if (!is_string($path) || $path === "" || $path === "/") {
                    return null;
                }
                $trimmed = trim($path, "/");
                if ($trimmed === "") {
                    return null;
                }
                $parts = explode("/", $trimmed);
                foreach ($parts as $i => $p) {
                    if (strcasecmp($p, "embed.html") === 0 && $i > 0) {
                        $seg = $parts[$i - 1];
                        return $seg !== "" ? $this->decodePathSegment($seg) : null;
                    }
                }
                $n = count($parts);
                $last = $n > 0 ? $parts[$n - 1] : "";
                if ($last !== "" && preg_match('/^index(\.ll)?\.m3u8$/i', $last) && $n >= 2) {
                    $seg = $parts[$n - 2];
                    return $seg !== "" ? $this->decodePathSegment($seg) : null;
                }
                $first = $parts[0];
                return $first !== "" ? $this->decodePathSegment($first) : null;
            }

            /**
             * Подбор потока Flussonic по dvrStream: перебор сегментов пути справа налево + регистронезависимое сравнение.
             *
             * @param array<string,array> $byNameInsensitive ключ — strtolower(имя потока на сервере)
             * @return array{streamName:?string,meta:?array}
             */
            private function matchCameraDvrStreamToFlussonic(string $src, array $byNameInsensitive): array {
                $path = parse_url($src, PHP_URL_PATH);
                if (!is_string($path) || $path === "" || $path === "/") {
                    return ["streamName" => null, "meta" => null];
                }
                $trimmed = trim($path, "/");
                if ($trimmed === "") {
                    return ["streamName" => null, "meta" => null];
                }
                $parts = explode("/", $trimmed);
                $candidates = [];
                foreach ($parts as $p) {
                    if ($p === "") {
                        continue;
                    }
                    if (strcasecmp($p, "embed.html") === 0) {
                        continue;
                    }
                    if (preg_match('/^index(\.ll)?\.m3u8$/i', $p)) {
                        continue;
                    }
                    $candidates[] = $this->decodePathSegment($p);
                }
                for ($i = count($candidates) - 1; $i >= 0; $i--) {
                    $k = strtolower($candidates[$i]);
                    if ($k !== "" && isset($byNameInsensitive[$k])) {
                        $meta = $byNameInsensitive[$k];
                        $canonical = (string)($meta["name"] ?? $meta["stream"] ?? $candidates[$i]);
                        return ["streamName" => $canonical, "meta" => $meta];
                    }
                }
                $heuristic = $this->streamNameFromPathUrl($src);
                if ($heuristic !== null && $heuristic !== "") {
                    $k = strtolower($heuristic);
                    if (isset($byNameInsensitive[$k])) {
                        $meta = $byNameInsensitive[$k];
                        $canonical = (string)($meta["name"] ?? $meta["stream"] ?? $heuristic);
                        return ["streamName" => $canonical, "meta" => $meta];
                    }
                    return ["streamName" => $heuristic, "meta" => null];
                }
                return ["streamName" => null, "meta" => null];
            }

            /** Схемы «адреса потока на DVR» для списка и создания потока на Flussonic. */
            private function isAllowedDvrStreamScheme(string $scheme): bool {
                $s = strtolower($scheme);
                return $s === "http" || $s === "https" || $s === "rtsp" || $s === "rtsps";
            }

            /** Имя потока на Flussonic из карточки (ext.mediaserverStreamName), не из RTSP. */
            private function isValidFlussonicStreamName(string $name): bool {
                if (strlen($name) > 200) {
                    return false;
                }

                return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name);
            }

            private function rtspHostMatchesServer(string $rtspUrl, array $srv): bool {
                $u = parse_url($rtspUrl);
                if (!$u || empty($u["host"])) {
                    return false;
                }
                $su = parse_url($srv["url"]);
                if (!$su || empty($su["host"])) {
                    return false;
                }
                return strcasecmp($u["host"], $su["host"]) === 0;
            }

            /**
             * Если true — в списке только камеры, у которых хост ссылки dvrStream совпадает с хостом URL Flussonic.
             * По умолчанию false: все камеры с подходящей схемой в dvrStream и непустым первым сегментом пути.
             */
            private function listOnlyMatchingRtspHost(): bool {
                if (array_key_exists("list_only_matching_rtsp_host", $this->msConfig())) {
                    return (bool)$this->msConfig()["list_only_matching_rtsp_host"];
                }
                return false;
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

            /**
             * Приводит ответ API к списку потоков. Если потоки пришли объектом с именами в ключах
             * (типично: "streams": { "Lug_1_2": { ... } }), array_values теряет имя — подставляем в элемент поле name.
             *
             * @return list<array>
             */
            private function normalizeStreamsList($data): array {
                if (!is_array($data)) {
                    return [];
                }
                if (isset($data["streams"]) && is_array($data["streams"])) {
                    return $this->streamsAssocListWithNames($data["streams"]);
                }
                if ($data === []) {
                    return [];
                }
                $keys = array_keys($data);
                $numeric = $keys === range(0, count($data) - 1);
                if ($numeric) {
                    return $data;
                }

                return $this->streamsAssocListWithNames($data);
            }

            /**
             * @param array<string|int,mixed> $streams
             * @return list<array>
             */
            private function streamsAssocListWithNames(array $streams): array {
                $out = [];
                foreach ($streams as $key => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $hasName = isset($item["name"]) || isset($item["stream"]) || isset($item["title"]) || isset($item["_id"]);
                    if (!$hasName && is_string($key) && $key !== "") {
                        $item["name"] = $key;
                    }
                    $out[] = $item;
                }

                return $out;
            }

            /**
             * Один поток по имени (GET …/streams/{name}). Нужен, когда общий список усечён limit’ом или не содержит поток, хотя он есть на сервере.
             */
            private function getStreamMetaByName(string $streamName): ?array {
                $streamName = trim($streamName);
                if ($streamName === "") {
                    return null;
                }
                $prefix = $this->apiPrefix();
                $path = $prefix . "/streams/" . rawurlencode($streamName);
                $r = $this->apiRequest("GET", $path);
                if (!$r["ok"] || !is_array($r["data"])) {
                    return null;
                }
                $d = $r["data"];
                if (isset($d["stream"]) && is_array($d["stream"])) {
                    return $d["stream"];
                }

                return $d;
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
                $streamsPath = $prefix . "/streams";
                if (array_key_exists("streams_fetch_limit", $this->msConfig()) && (int)$this->msConfig()["streams_fetch_limit"] > 0) {
                    $streamsPath .= "?limit=" . (int)$this->msConfig()["streams_fetch_limit"];
                }
                $r = $this->apiRequest("GET", $streamsPath);
                $apiError = "";
                $byNameInsensitive = [];
                if ($r["ok"]) {
                    $rawList = $this->normalizeStreamsList($r["data"]);
                    foreach ($rawList as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $n = $item["name"] ?? $item["stream"] ?? $item["title"] ?? $item["_id"] ?? null;
                        if ($n === null || $n === "") {
                            continue;
                        }
                        $byNameInsensitive[strtolower((string)$n)] = $item;
                    }
                } else {
                    if ($r["code"] === 0) {
                        $apiError = $r["error"];
                    } else {
                        $apiError = $r["error"] . ($r["raw"] ? (": " . substr($r["raw"], 0, 200)) : "");
                    }
                }

                $cameras = loadBackend("cameras");
                $cams = $cameras ? $cameras->getCameras(false, false, false) : [];
                $hostFilter = $this->listOnlyMatchingRtspHost();
                $token = @$srv["token"];
                $out = [];
                $singleStreamCache = [];

                foreach ($cams as $cam) {
                    $rtsp = trim((string)($cam["stream"] ?? ""));
                    $dvr = trim((string)($cam["dvrStream"] ?? ""));
                    $extCam = $cam["ext"];
                    if (is_object($extCam)) {
                        $extCam = json_decode(json_encode($extCam), true);
                    }
                    if (!is_array($extCam)) {
                        $extCam = [];
                    }
                    $preferredSn = trim((string)(@$extCam["mediaserverStreamName"] ?: @$extCam["flussonicStreamName"] ?: ""));
                    $sn = null;
                    $meta = null;
                    $srcForHost = "";

                    if ($preferredSn !== "") {
                        $sn = $preferredSn;
                        $srcForHost = $dvr !== "" ? $dvr : $rtsp;
                        $k = strtolower($sn);
                        $meta = $byNameInsensitive[$k] ?? null;
                    } else {
                        // Старые карточки: имя только из URL DVR.
                        $srcForMatch = "";
                        if ($dvr !== "") {
                            $sch = strtolower((string)(parse_url($dvr, PHP_URL_SCHEME) ?: ""));
                            if ($this->isAllowedDvrStreamScheme($sch)) {
                                $srcForMatch = $dvr;
                            }
                        }
                        if ($srcForMatch === "") {
                            continue;
                        }
                        $matched = $this->matchCameraDvrStreamToFlussonic($srcForMatch, $byNameInsensitive);
                        $sn = $matched["streamName"];
                        if ($sn === null || $sn === "") {
                            continue;
                        }
                        $srcForHost = $srcForMatch;
                        $meta = $matched["meta"];
                    }

                    if ($hostFilter && $srcForHost !== "" && !$this->rtspHostMatchesServer($srcForHost, $srv)) {
                        continue;
                    }
                    if ($meta === null && $sn !== "") {
                        $cacheKey = strtolower($sn);
                        if (!array_key_exists($cacheKey, $singleStreamCache)) {
                            $singleStreamCache[$cacheKey] = $this->getStreamMetaByName($sn);
                        }
                        $meta = $singleStreamCache[$cacheKey];
                    }
                    $state = $meta === null ? "not_on_server" : $this->mapStreamState($meta);
                    $hls = $this->appendPlaybackToken($this->buildHlsUrl($sn), $token);
                    $embed = $this->appendPlaybackToken($this->buildEmbedUrl($sn), $token);
                    $embedStored = !empty($extCam["mediaserverEmbedUrl"])
                        ? (string)$extCam["mediaserverEmbedUrl"]
                        : "";
                    $out[] = [
                        "streamName" => $sn,
                        "state" => $state,
                        "cameraId" => (int)$cam["cameraId"],
                        "cameraName" => (string)($cam["name"] ?: ""),
                        "dvrStreamUrl" => $dvr,
                        "rtspUrl" => $rtsp,
                        "hlsUrl" => $hls,
                        "embedUrl" => $embed,
                        "embedUrlStored" => $embedStored,
                        "raw" => $meta,
                    ];
                }

                usort($out, function ($a, $b) {
                    return strcasecmp($a["cameraName"] ?: $a["streamName"], $b["cameraName"] ?: $b["streamName"]);
                });

                return [
                    "serverTitle" => (string)(@$srv["title"] ?: ""),
                    "apiError" => $apiError,
                    "streams" => $out,
                ];
            }

            public function publishStreamForCamera(int $cameraId): array {
                if ($cameraId <= 0) {
                    return ["ok" => false, "error" => "noCamera"];
                }
                $srv = $this->flussonicServer();
                if (!$srv) {
                    return ["ok" => false, "error" => "noFlussonicServer", "cameraId" => $cameraId];
                }
                $cameras = loadBackend("cameras");
                if (!$cameras) {
                    return ["ok" => false, "error" => "noCamerasBackend", "cameraId" => $cameraId];
                }
                $list = $cameras->getCameras("id", $cameraId, false);
                if (!is_array($list) || !count($list)) {
                    return ["ok" => false, "error" => "noCamera", "cameraId" => $cameraId];
                }
                $cam = $list[0];
                $ext = $cam["ext"];
                if (is_object($ext)) {
                    $ext = json_decode(json_encode($ext), true);
                }
                if (!is_array($ext)) {
                    $ext = [];
                }
                $dvrDays = (int)(@$ext["dvrRetentionDays"] ?: 0);
                if ($dvrDays < 1 || $dvrDays > 3660) {
                    return ["ok" => false, "error" => "badDvrRetentionDays", "cameraId" => $cameraId];
                }
                $pullUrl = trim((string)($cam["stream"] ?? ""));
                if ($pullUrl === "") {
                    return ["ok" => false, "error" => "noRtspStream", "cameraId" => $cameraId];
                }
                $scheme = strtolower((string)(parse_url($pullUrl, PHP_URL_SCHEME) ?: ""));
                if ($scheme !== "rtsp" && $scheme !== "rtsps") {
                    return ["ok" => false, "error" => "streamMustBeRtsp", "cameraId" => $cameraId];
                }
                $name = trim((string)(@$ext["mediaserverStreamName"] ?: ""));
                if ($name === "") {
                    $name = trim((string)(@$ext["flussonicStreamName"] ?: ""));
                }
                if ($name === "") {
                    return ["ok" => false, "error" => "noMediaserverStreamName", "cameraId" => $cameraId];
                }
                if (!$this->isValidFlussonicStreamName($name)) {
                    return ["ok" => false, "error" => "invalidMediaserverStreamName", "cameraId" => $cameraId];
                }
                if ($this->listOnlyMatchingRtspHost() && !$this->rtspHostMatchesServer($pullUrl, $srv)) {
                    return ["ok" => false, "error" => "rtspHostMismatch", "cameraId" => $cameraId];
                }
                $oldFlu = trim((string)(@$ext["flussonicStreamName"] ?: ""));

                $body = ["inputs" => [["url" => $pullUrl]]];
                $dvrRoot = trim((string)(@$this->msConfig()["dvr_root"] ?: ""));
                $dvrFromForm = [
                    "expiration" => $dvrDays * 86400,
                ];
                if ($dvrRoot !== "") {
                    $dvrFromForm["root"] = $dvrRoot;
                }
                $existingDvr = isset($body["dvr"]) && is_array($body["dvr"]) ? $body["dvr"] : [];
                $body["dvr"] = array_replace_recursive($existingDvr, $dvrFromForm);

                $fr = $this->upsertStream($name, $body, false);
                if (!$fr["ok"]) {
                    $this->auditWrite("create_camera_flussonic_failed", $name, $cameraId, [
                        "cameraId" => $cameraId,
                        "code" => $fr["code"],
                        "error" => $fr["error"],
                        "raw" => substr($fr["raw"], 0, 500),
                    ]);
                    return [
                        "ok" => false,
                        "cameraId" => $cameraId,
                        "streamName" => $name,
                        "flussonic" => $fr,
                    ];
                }

                if ($oldFlu !== "" && strcasecmp($oldFlu, $name) !== 0) {
                    $this->deleteStream($oldFlu);
                }

                $token = @$srv["token"];
                $hls = $this->appendPlaybackToken($this->buildHlsUrl($name), $token);
                $embed = $this->appendPlaybackToken($this->buildEmbedUrl($name), $token);

                if (!$this->applyUrlsToCamera($cameraId, $hls, $embed)) {
                    return ["ok" => false, "error" => "applyUrlsFailed", "cameraId" => $cameraId, "streamName" => $name, "flussonic" => $fr];
                }

                $listAfter = $cameras->getCameras("id", $cameraId, false);
                if (is_array($listAfter) && count($listAfter)) {
                    $ca = $listAfter[0];
                    $extA = $ca["ext"];
                    if (is_object($extA)) {
                        $extA = json_decode(json_encode($extA), true);
                    }
                    if (!is_array($extA)) {
                        $extA = [];
                    }
                    $extA["mediaserverStreamName"] = $name;
                    $extA["flussonicStreamName"] = $name;
                    $cameras->modifyCamera(
                        $cameraId,
                        (int)$ca["enabled"],
                        $ca["model"],
                        $ca["url"],
                        $ca["stream"],
                        $ca["credentials"],
                        $ca["name"],
                        $ca["dvrStream"],
                        $ca["timezone"],
                        $ca["lat"],
                        $ca["lon"],
                        $ca["direction"],
                        $ca["angle"],
                        $ca["distance"],
                        $ca["frs"],
                        $ca["frsMode"],
                        $ca["mdArea"],
                        $ca["rcArea"],
                        $ca["common"],
                        $ca["comments"],
                        (int)$ca["sound"],
                        (int)$ca["monitoring"],
                        (int)$ca["webrtc"],
                        $extA,
                        $ca["tree"] ?? ""
                    );
                }

                $this->auditWrite("create_camera_and_stream", $name, $cameraId, ["rtsp" => $pullUrl]);

                return [
                    "ok" => true,
                    "cameraId" => $cameraId,
                    "streamName" => $name,
                    "flussonic" => $fr,
                ];
            }

            public function updateCameraStreamSettings(int $cameraId, array $updates): array {
                if ($cameraId <= 0) {
                    return ["ok" => false, "error" => "noCamera"];
                }
                $cameras = loadBackend("cameras");
                if (!$cameras) {
                    return ["ok" => false, "error" => "noCamerasBackend", "cameraId" => $cameraId];
                }
                $list = $cameras->getCameras("id", $cameraId, false);
                if (!is_array($list) || !count($list)) {
                    return ["ok" => false, "error" => "noCamera", "cameraId" => $cameraId];
                }
                $cam = $list[0];
                $ext = $cam["ext"];
                if (is_object($ext)) {
                    $ext = json_decode(json_encode($ext), true);
                }
                if (!is_array($ext)) {
                    $ext = [];
                }
                $stream = (string)$cam["stream"];
                if (array_key_exists("stream", $updates)) {
                    $stream = trim((string)$updates["stream"]);
                    if ($stream === "") {
                        return ["ok" => false, "error" => "noRtspStream", "cameraId" => $cameraId];
                    }
                    $sch = strtolower((string)(parse_url($stream, PHP_URL_SCHEME) ?: ""));
                    if ($sch !== "rtsp" && $sch !== "rtsps") {
                        return ["ok" => false, "error" => "streamMustBeRtsp", "cameraId" => $cameraId];
                    }
                }
                if (array_key_exists("dvrRetentionDays", $updates)) {
                    $d = (int)$updates["dvrRetentionDays"];
                    if ($d < 1 || $d > 3660) {
                        return ["ok" => false, "error" => "badDvrRetentionDays", "cameraId" => $cameraId];
                    }
                    $ext["dvrRetentionDays"] = $d;
                }
                if (array_key_exists("mediaserverStreamName", $updates)) {
                    $msn = trim((string)$updates["mediaserverStreamName"]);
                    if ($msn === "") {
                        return ["ok" => false, "error" => "noMediaserverStreamName", "cameraId" => $cameraId];
                    }
                    if (!$this->isValidFlussonicStreamName($msn)) {
                        return ["ok" => false, "error" => "invalidMediaserverStreamName", "cameraId" => $cameraId];
                    }
                    $ext["mediaserverStreamName"] = $msn;
                }
                $ok = $cameras->modifyCamera(
                    $cameraId,
                    (int)$cam["enabled"],
                    $cam["model"],
                    $cam["url"],
                    $stream,
                    $cam["credentials"],
                    $cam["name"],
                    $cam["dvrStream"],
                    $cam["timezone"],
                    $cam["lat"],
                    $cam["lon"],
                    $cam["direction"],
                    $cam["angle"],
                    $cam["distance"],
                    $cam["frs"],
                    $cam["frsMode"],
                    $cam["mdArea"],
                    $cam["rcArea"],
                    $cam["common"],
                    $cam["comments"],
                    (int)$cam["sound"],
                    (int)$cam["monitoring"],
                    (int)$cam["webrtc"],
                    $ext,
                    $cam["tree"] ?? ""
                );
                if (!$ok) {
                    return ["ok" => false, "error" => "modifyCameraFailed", "cameraId" => $cameraId];
                }
                return $this->publishStreamForCamera($cameraId);
            }

            public function upsertStream(string $name, array $body = [], bool $writeAudit = true): array {
                $name = trim($name);
                if ($name === "") {
                    return ["ok" => false, "error" => "noStreamName"];
                }
                $prefix = $this->apiPrefix();
                $path = $prefix . "/streams/" . rawurlencode($name);
                $payload = count($body) ? $body : new \stdClass();
                $r = $this->apiRequest("PUT", $path, $payload);
                if ($writeAudit) {
                    if ($r["ok"]) {
                        $this->auditWrite("upsert_stream", $name, null, ["code" => $r["code"], "body" => $body]);
                    } else {
                        $this->auditWrite("upsert_stream_failed", $name, null, ["code" => $r["code"], "error" => $r["error"], "raw" => substr($r["raw"], 0, 500)]);
                    }
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

            private function cameraBelongsToStreamName(array $cam, string $streamName): bool {
                $streamName = trim($streamName);
                if ($streamName === "") {
                    return false;
                }
                $want = strtolower($streamName);
                $ext = $cam["ext"];
                if (is_object($ext)) {
                    $ext = json_decode(json_encode($ext), true);
                }
                if (!is_array($ext)) {
                    $ext = [];
                }
                foreach (["mediaserverStreamName", "flussonicStreamName"] as $k) {
                    $v = trim((string)(@$ext[$k] ?: ""));
                    if ($v !== "" && strtolower($v) === $want) {
                        return true;
                    }
                }
                $dvr = trim((string)(@$cam["dvrStream"] ?: ""));
                if ($dvr !== "") {
                    $sn = $this->streamNameFromPathUrl($dvr);
                    if ($sn !== null && strtolower($sn) === $want) {
                        return true;
                    }
                }
                $pull = trim((string)(@$cam["stream"] ?: ""));
                if ($pull !== "") {
                    $sn = $this->streamNameFromPathUrl($pull);
                    if ($sn !== null && strtolower($sn) === $want) {
                        return true;
                    }
                }

                return false;
            }

            public function deleteStreamAndCamera(string $streamName, int $cameraId): array {
                $streamName = trim($streamName);
                if ($streamName === "") {
                    return ["ok" => false, "error" => "noStreamName"];
                }
                if ($cameraId <= 0) {
                    return ["ok" => false, "error" => "noCamera", "cameraId" => 0];
                }
                $cameras = loadBackend("cameras");
                if (!$cameras) {
                    return ["ok" => false, "error" => "noCamerasBackend", "cameraId" => $cameraId];
                }
                $list = $cameras->getCameras("id", $cameraId, false);
                if (!is_array($list) || !count($list)) {
                    return ["ok" => false, "error" => "noCamera", "cameraId" => $cameraId];
                }
                if (!$this->cameraBelongsToStreamName($list[0], $streamName)) {
                    return ["ok" => false, "error" => "cameraStreamMismatch", "cameraId" => $cameraId];
                }
                $prefix = $this->apiPrefix();
                $r = $this->apiRequest("DELETE", $prefix . "/streams/" . rawurlencode($streamName));
                if (!$r["ok"] && $r["code"] !== 404) {
                    $this->auditWrite("delete_stream_failed", $streamName, $cameraId, [
                        "code" => $r["code"],
                        "error" => $r["error"],
                        "raw" => substr($r["raw"], 0, 500),
                        "withCamera" => true,
                    ]);
                    return [
                        "ok" => false,
                        "error" => "flussonicError",
                        "cameraId" => $cameraId,
                        "streamName" => $streamName,
                        "flussonic" => $r,
                    ];
                }
                if ($r["ok"]) {
                    $this->auditWrite("delete_stream", $streamName, $cameraId, ["code" => $r["code"], "withCamera" => true]);
                }
                if (!$cameras->deleteCamera($cameraId)) {
                    return ["ok" => false, "error" => "deleteCameraFailed", "cameraId" => $cameraId, "streamName" => $streamName];
                }
                $this->auditWrite("delete_camera_after_stream", $streamName, $cameraId, []);

                return ["ok" => true, "cameraId" => $cameraId, "streamName" => $streamName];
            }

            /**
             * Поле stream (RTSP) не трогаем. В dvrStream пишем HLS. Ссылку embed — в ext.mediaserverEmbedUrl (по желанию для копирования).
             */
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
                $ext = $c["ext"];
                if (is_object($ext)) {
                    $ext = json_decode(json_encode($ext), true);
                }
                if (!is_array($ext)) {
                    $ext = [];
                }
                $embedTrim = trim($embedUrl);
                if ($embedTrim !== "") {
                    $ext["mediaserverEmbedUrl"] = $embedTrim;
                }
                $hlsTrim = trim($hlsUrl);
                $ok = $cameras->modifyCamera(
                    $cameraId,
                    $c["enabled"],
                    $c["model"],
                    $c["url"],
                    $c["stream"],
                    $c["credentials"],
                    $c["name"],
                    $hlsTrim,
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
                    $ext,
                    $c["tree"] ?? ""
                );
                if ($ok) {
                    $sn = trim((string)(@$ext["mediaserverStreamName"] ?: @$ext["flussonicStreamName"] ?: ""));
                    if ($sn === "") {
                        $sn = $this->streamNameFromPathUrl($hlsTrim) ?: $this->streamNameFromPathUrl((string)$c["stream"]) ?: "";
                    }
                    $this->auditWrite("apply_camera_dvr_stream", $sn, $cameraId, [
                        "hlsUrl" => $hlsTrim,
                        "embedUrl" => $embedTrim,
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
