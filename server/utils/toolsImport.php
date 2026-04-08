<?php

/**
 * Разбор CSV и нормализация полей для модулей tools (массовый импорт).
 */

if (!function_exists("tools_import_normalize_flat_key")) {

    function tools_import_normalize_flat_key($s) {
        return trim(preg_replace('/\s+/u', " ", (string) $s));
    }

    /**
     * @return string|false 11 цифр, начинается с 7
     */
    function tools_import_normalize_phone($s) {
        $d = preg_replace('/\D+/', "", (string) $s);
        if (strlen($d) === 11 && $d[0] === "8") {
            $d = "7" . substr($d, 1);
        }
        if (strlen($d) === 10) {
            $d = "7" . $d;
        }
        if (strlen($d) === 11 && $d[0] === "7") {
            return $d;
        }
        return false;
    }

    /**
     * @return string|false 14 hex
     */
    function tools_import_normalize_rfid($s) {
        $x = strtoupper(preg_replace('/[^0-9A-F]/i', "", (string) $s));
        return strlen($x) === 14 ? $x : false;
    }

    /**
     * @return array<int, array{line:int, cells:string[]}>
     */
    function tools_import_parse_csv_rows($csv) {
        $csv = str_replace("\r\n", "\n", str_replace("\r", "\n", $csv));
        if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) {
            $csv = substr($csv, 3);
        }
        $lines = explode("\n", $csv);
        $rows = [];
        $delim = null;
        foreach ($lines as $lineNum => $line) {
            if (trim($line) === "") {
                continue;
            }
            if (isset($line[0]) && $line[0] === "#") {
                continue;
            }
            if ($delim === null) {
                $delim = (substr_count($line, ";") >= substr_count($line, ",")) ? ";" : ",";
            }
            $row = str_getcsv($line, $delim);
            $rows[] = [
                "line" => $lineNum + 1,
                "cells" => $row,
            ];
        }
        return $rows;
    }

    function tools_import_is_header_subscribers($cells) {
        if (count($cells) < 2) {
            return false;
        }
        $a = mb_strtolower(trim((string) $cells[0]));
        $b = mb_strtolower(trim((string) $cells[1]));
        $okA = (mb_strpos($a, "квартир") !== false || $a === "flat" || $a === "квартира");
        $okB = (mb_strpos($b, "телефон") !== false || mb_strpos($b, "phone") !== false || $b === "mobile" || $b === "телефон");
        return $okA && $okB;
    }

    function tools_import_is_header_keys($cells) {
        if (count($cells) < 2) {
            return false;
        }
        $a = mb_strtolower(trim((string) $cells[0]));
        $b = mb_strtolower(trim((string) $cells[1]));
        $okA = (mb_strpos($a, "квартир") !== false || $a === "flat" || $a === "квартира");
        $okB = (mb_strpos($b, "ключ") !== false || mb_strpos($b, "rfid") !== false || mb_strpos($b, "key") !== false);
        return $okA && $okB;
    }

    /**
     * @param array<int, array{flatId:mixed, flat:mixed}> $flats
     * @return array<string, int> ключ — нормализованный номер квартиры
     */
    function tools_import_flat_map_by_number($flats) {
        $map = [];
        foreach ($flats as $flat) {
            if (!isset($flat["flatId"], $flat["flat"])) {
                continue;
            }
            $k = tools_import_normalize_flat_key($flat["flat"]);
            if ($k !== "") {
                $map[$k] = (int) $flat["flatId"];
            }
        }
        return $map;
    }
}
