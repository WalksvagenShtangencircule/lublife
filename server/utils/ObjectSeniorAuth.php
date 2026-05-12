<?php

    /**
     * Сессии личного кабинета «старшего по объекту» в Redis (OM_AUTH:token).
     */

    class ObjectSeniorAuth {

        public const REDIS_PREFIX = "OM_AUTH:";

        public static function bearerRawToken(?string $httpAuthorization): string {
            if (!$httpAuthorization) {
                return "";
            }
            $h = trim($httpAuthorization);
            if (stripos($h, "Bearer ") === 0) {
                return trim(substr($h, 7));
            }
            return "";
        }

        /**
         * @return array|false
         */
        public static function loadSession($redis, string $token) {
            $token = trim($token);
            if ($token === "" || strlen($token) > 256) {
                return false;
            }
            try {
                $raw = $redis->get(self::REDIS_PREFIX . $token);
            } catch (\Throwable $e) {
                return false;
            }
            if (!$raw) {
                return false;
            }
            $j = json_decode($raw, true);
            return is_array($j) ? $j : false;
        }

        public static function saveSession($redis, string $token, array $payload, int $ttlSec = 604800): bool {
            try {
                $redis->setex(self::REDIS_PREFIX . $token, $ttlSec, json_encode($payload, JSON_UNESCAPED_UNICODE));
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        public static function deleteSession($redis, string $token): void {
            try {
                $redis->del(self::REDIS_PREFIX . trim($token));
            } catch (\Throwable $e) {
            }
        }

        public static function newToken(): string {
            try {
                return bin2hex(random_bytes(32));
            } catch (\Throwable $e) {
                return bin2hex(md5((string) microtime(true), true));
            }
        }

        public static function newSlug(): string {
            try {
                return bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                return bin2hex(md5((string) microtime(true), true));
            }
        }
    }

