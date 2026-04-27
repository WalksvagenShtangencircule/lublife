<?php

    /**
     * Общие функции для эндпоинтов /mobile/keys/*
     */

    if (!function_exists('keys_normalize_rfid')) {
        function keys_normalize_rfid($s) {
            $s = strtoupper(preg_replace('/\s+/', '', (string) $s));
            return $s;
        }
    }

    /**
     * RFID для «Ключ на старт»: как в RBT (импорт ключей) — ровно 14 hex, слева дополняется нулями.
     *
     * @return string|false
     */
    if (!function_exists('keys_join_flat_normalize_rfid')) {
        function keys_join_flat_normalize_rfid($s) {
            $x = strtoupper(preg_replace('/[^0-9A-F]/i', "", (string) $s));
            $len = strlen($x);
            if ($len < 1 || $len > 14) {
                return false;
            }

            return str_pad($x, 14, "0", STR_PAD_LEFT);
        }
    }

    if (!function_exists('keys_subscriber_has_flat')) {
        function keys_subscriber_has_flat($subscriber, $flatId) {
            $flatId = (int) $flatId;
            foreach ($subscriber['flats'] as $flat) {
                if ((int) $flat['flatId'] === $flatId) {
                    return true;
                }
            }
            return false;
        }
    }

    if (!function_exists('keys_subscriber_is_owner')) {
        function keys_subscriber_is_owner($subscriber, $flatId) {
            $flatId = (int) $flatId;
            foreach ($subscriber['flats'] as $flat) {
                if ((int) $flat['flatId'] === $flatId && (int) $flat['role'] === 0) {
                    return true;
                }
            }
            return false;
        }
    }

    if (!function_exists('keys_user_has_any_flat')) {
        function keys_user_has_any_flat($subscriber) {
            return $subscriber['flats'] && count($subscriber['flats']) > 0;
        }
    }
