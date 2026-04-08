/**
 * MCP-сервер (stdio): обёртка над SmartAccess frontend API.
 * Права — как у Bearer-токена; путь валидируется по шаблону (без open proxy за пределы API).
 */
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const __dirname = dirname(fileURLToPath(import.meta.url));

const BASE = (process.env.SMARTACCESS_BASE_URL || "").replace(/\/$/, "");
const TOKEN = process.env.SMARTACCESS_TOKEN || "";

const RAW_METHODS = (process.env.SMARTACCESS_MCP_HTTP_METHODS || "GET").trim();
/** @type {Set<string>} */
let ALLOWED_METHODS;
if (RAW_METHODS.toUpperCase() === "ALL") {
  ALLOWED_METHODS = new Set(["GET", "POST", "PUT", "DELETE", "PATCH", "HEAD"]);
} else {
  ALLOWED_METHODS = new Set(
    RAW_METHODS.split(",")
      .map((s) => s.trim().toUpperCase())
      .filter(Boolean)
  );
}

const MAX_CHARS = parseInt(process.env.SMARTACCESS_MCP_MAX_RESPONSE_CHARS || "600000", 10);

/** Два сегмента api/method, опционально /id, опционально ?query */
const PATH_LINE_RE = /^[a-z][a-z0-9_]*\/[a-z][a-z0-9_]*(?:\/[^/?#]*)?(?:\?[^#]*)?$/;

function normalizePath(path) {
  let s = String(path || "").trim();
  if (!s) {
    return "";
  }
  if (/^https?:\/\//i.test(s)) {
    try {
      const u = new URL(s);
      let pathPart = u.pathname + u.search;
      const baseUrl = new URL(BASE);
      const basePath = baseUrl.pathname.replace(/\/$/, "");
      if (basePath && pathPart.startsWith(basePath)) {
        pathPart = pathPart.slice(basePath.length);
      }
      s = pathPart.replace(/^\//, "") + (pathPart.includes("?") ? "" : u.search);
    } catch {
      /* оставляем как есть */
    }
  }
  return s.replace(/^\/+/, "");
}

function allowedPath(path) {
  const n = normalizePath(path);
  if (!n || n.includes("..")) {
    return false;
  }
  const noQuery = n.split("?")[0];
  if (noQuery === "server/ping") {
    return true;
  }
  return PATH_LINE_RE.test(n);
}

function truncate(text) {
  if (text.length <= MAX_CHARS) {
    return text;
  }
  const cut = text.length - MAX_CHARS;
  return `${text.slice(0, MAX_CHARS)}\n...[обрезано ${cut} символов, см. SMARTACCESS_MCP_MAX_RESPONSE_CHARS]`;
}

async function smapiRequest(method, path, body) {
  if (!BASE || !TOKEN) {
    return JSON.stringify({ error: "Задайте SMARTACCESS_BASE_URL и SMARTACCESS_TOKEN" });
  }
  const m = String(method || "GET").toUpperCase();
  if (!ALLOWED_METHODS.has(m)) {
    return JSON.stringify({
      error: "method_not_allowed",
      method: m,
      hint: "По умолчанию только GET. Для POST/PUT/DELETE задайте SMARTACCESS_MCP_HTTP_METHODS=GET,POST или ALL.",
    });
  }
  const clean = normalizePath(path);
  if (!allowedPath(clean)) {
    return JSON.stringify({
      error: "path_not_allowed",
      path: clean,
      hint: "Ожидается api/method или api/method/id[?query], латиница и цифры в сегментах. Пример: addresses/addresses?include=houses",
    });
  }
  const url = `${BASE}/${clean}`;
  /** @type {RequestInit} */
  const init = {
    method: m,
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      Accept: "application/json",
      "Accept-Language": "ru",
    },
  };
  if (body != null && body !== "" && (m === "POST" || m === "PUT" || m === "PATCH")) {
    init.headers["Content-Type"] = "application/json";
    init.body = typeof body === "string" ? body : JSON.stringify(body);
  }
  let r;
  try {
    r = await fetch(url, init);
  } catch (e) {
    return JSON.stringify({ error: "fetch_failed", message: String(e && e.message ? e.message : e) });
  }
  const text = await r.text();
  let payload;
  try {
    payload = { status: r.status, body: JSON.parse(text) };
  } catch {
    payload = { status: r.status, body: text.slice(0, Math.min(text.length, 8000)) };
  }
  return truncate(JSON.stringify(payload));
}

function loadCatalog() {
  try {
    const raw = readFileSync(join(__dirname, "entities-catalog.json"), "utf8");
    return truncate(raw);
  } catch (e) {
    return JSON.stringify({ error: "catalog_read_failed", message: String(e) });
  }
}

const server = new McpServer({
  name: "smartaccess-mcp",
  version: "1.1.0",
});

server.registerTool(
  "smapi_request",
  {
    description:
      "Вызов SmartAccess HTTP API: путь относительно базы (как в клиенте), та же схема {api}/{method}[/{id}][?query]. " +
      "Права ограничены токеном SMARTACCESS_TOKEN. По умолчанию разрешён только GET; для записи задайте переменную окружения SMARTACCESS_MCP_HTTP_METHODS (например GET,POST или ALL).",
    inputSchema: {
      path: z
        .string()
        .describe(
          "Путь без домена: addresses/addresses?include=houses, houses/house/123, authorization/available, server/ping"
        ),
      method: z
        .enum(["GET", "POST", "PUT", "DELETE", "PATCH"])
        .optional()
        .describe("HTTP-метод, по умолчанию GET"),
      body: z
        .any()
        .optional()
        .describe("JSON-тело для POST/PUT/PATCH (объект; сериализуется в строку)"),
    },
  },
  async ({ path, method, body }) => {
    const out = await smapiRequest(method || "GET", String(path || ""), body);
    return { content: [{ type: "text", text: out }] };
  }
);

server.registerTool(
  "smartaccess_catalog",
  {
    description:
      "Полный справочник: все известные пары api/method, описание доменов SmartAccess, узлы сущностей и таблица пересечений (связей).",
    inputSchema: z.object({}),
  },
  async () => ({
    content: [{ type: "text", text: loadCatalog() }],
  })
);

server.registerTool(
  "smartaccess_capabilities",
  {
    description:
      "Текущие разрешённые для токена вызовы API (то же, что GET authorization/available в веб-клиенте).",
    inputSchema: z.object({}),
  },
  async () => {
    const out = await smapiRequest("GET", "authorization/available");
    return { content: [{ type: "text", text: out }] };
  }
);

/* Совместимость со старыми конфигами MCP */
server.registerTool(
  "smapi_get",
  {
    description: "Устаревшее имя: то же, что smapi_request с method=GET.",
    inputSchema: {
      path: z.string().describe("Путь API после базы"),
    },
  },
  async ({ path }) => {
    const out = await smapiRequest("GET", String(path || ""));
    return { content: [{ type: "text", text: out }] };
  }
);

const transport = new StdioServerTransport();
await server.connect(transport);
