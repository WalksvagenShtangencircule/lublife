<?php

    /**
     * Нормализация RFID для каталога vendor_rfids_whitelist (импорт в панели).
     *
     * @return string|false
     */
    if (!function_exists('vendor_keys_normalize_rfid')) {
        function vendor_keys_normalize_rfid($s) {
            $s = strtoupper(preg_replace('/\s+/', '', (string) $s));
            if (strlen($s) < 6 || strlen($s) > 32) {
                return false;
            }

            return $s;
        }
    }
