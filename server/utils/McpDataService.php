<?php

/**
 * Внутренний read-only доступ для MCP/аналитики: проверка SQL, редактирование секретов в конфиге.
 */
class McpDataService {

    public const PG_MAX_ROWS = 500;
    public const CH_MAX_ROWS = 500;

    /**
     * @return string|null ошибка или null если ок
     */
    public static function validateSelectOnly(string $sql, string $dialect = "pg"): ?string {
        $s = trim($sql);
        if ($s === "") {
            return "empty_sql";
        }
        if (strlen($s) > 12000) {
            return "sql_too_long";
        }
        if (preg_match('/--|/\*|\*/', $s)) {
            return "comments_forbidden";
        }
        if (strpos($s, ';') !== false) {
            return "semicolon_forbidden";
        }
        if (!preg_match('/^select\b/is', $s) && !preg_match('/^with\b/is', $s)) {
            return "only_select_or_with";
        }
        $ban = [
            '\binsert\b',
            '\bupdate\b',
            '\bdelete\b',
            '\bdrop\b',
            '\btruncate\b',
            '\balter\b',
            '\bcreate\b',
            '\bgrant\b',
            '\brevoke\b',
            '\bcopy\b',
            '\bexecute\b',
            '\bcall\b',
            '\bdo\b',
            '\bprepare\b',
            '\binto\s+outfile\b',
            '\bfor\s+update\b',
            '\bfor\s+share\b',
            '\bpg_sleep\b',
            '\bpg_read_file\b',
            '\blo_import\b',
            '\blo_export\b',
        ];
        foreach ($ban as $p) {
            if (preg_match('/' . $p . '/is', $s)) {
                return "forbidden_keyword";
            }
        }
        if ($dialect === "ch") {
            if (preg_match('/\bsystem\.(tables|columns|databases|processes|mutations|parts)\b/is', $s)) {
                return "ch_system_tables_forbidden";
            }
        }
        return null;
    }

    /**
     * Обертка лимита строк (PostgreSQL).
     */
    public static function wrapPgLimit(string $sql): string {
        return 'SELECT * FROM (' . $sql . ') AS _mcp_limit_wrap LIMIT ' . (self::PG_MAX_ROWS + 1);
    }

    /**
     * @param mixed $cfg
     * @return mixed
     */
    public static function redactConfig($cfg, int $depth = 0) {
        if ($depth > 20) {
            return '[max_depth]';
        }
        if (!is_array($cfg)) {
            return $cfg;
        }
        $out = [];
        foreach ($cfg as $k => $v) {
            $key = (string) $k;
            if (self::isSecretKey($key)) {
                $out[$key] = is_string($v) && strlen($v) > 0 ? '<redacted:' . strlen($v) . '>' : '<redacted>';
                continue;
            }
            $out[$key] = self::redactConfig($v, $depth + 1);
        }
        return $out;
    }

    public static function isSecretKey(string $key): bool {
        $l = strtolower($key);
        foreach ([
            'password',
            'pass',
            'secret',
            'token',
            'apikey',
            'api_key',
            'private',
            'authorization',
            'auth',
            'credential',
            'dsn',
            'mysql_pwd',
            'zbx_token',
            'bot_token',
        ] as $frag) {
            if (strpos($l, $frag) !== false) {
                return true;
            }
        }
        return false;
    }
}
