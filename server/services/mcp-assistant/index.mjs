/**
 * MCP-сервер (stdio): инструмент smapi_get с allowlist путей к существующему frontend API.
 */
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const BASE = (process.env.SMARTACCESS_BASE_URL || "").replace(/\/$/, "");
const TOKEN = process.env.SMARTACCESS_TOKEN || "";

function allowedPath(path) {
  const p = String(path || "").split("?")[0].replace(/^\/+/, "");
  if (p === "addresses/addresses") {
    return true;
  }
  return /^houses\/house\/\d+$/.test(p);
}

async function smapiGet(path) {
  if (!BASE || !TOKEN) {
    return JSON.stringify({ error: "set SMARTACCESS_BASE_URL and SMARTACCESS_TOKEN" });
  }
  const clean = path.replace(/^\/+/, "");
  if (!allowedPath(clean)) {
    return JSON.stringify({ error: "path_not_allowed", path: clean });
  }
  const url = `${BASE}/${clean}`;
  const r = await fetch(url, {
    method: "GET",
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      Accept: "application/json",
      "Accept-Language": "ru",
    },
  });
  const text = await r.text();
  try {
    return JSON.stringify({ status: r.status, body: JSON.parse(text) });
  } catch {
    return JSON.stringify({ status: r.status, body: text.slice(0, 8000) });
  }
}

const server = new McpServer({
  name: "smartaccess-mcp",
  version: "1.0.0",
});

server.registerTool(
  "smapi_get",
  {
    description:
      "GET-запрос к SmartAccess API (только allowlist: addresses/addresses, houses/house/:id). path без ведущего слэша, например houses/house/123",
    inputSchema: {
      path: z
        .string()
        .describe("Путь API после базы, например addresses/addresses?include=houses"),
    },
  },
  async ({ path }) => {
    const out = await smapiGet(String(path || ""));
    return { content: [{ type: "text", text: out }] };
  }
);

const transport = new StdioServerTransport();
await server.connect(transport);
