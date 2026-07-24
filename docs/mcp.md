# BrightLocal MCP

> **Currently disabled.** The tool is code-complete but off via the kill-switch
> `BL_AI_Tool_MCP::ENABLED` (`false`) — it isn't registered or booted, so there's
> no menu, no abilities, no MCP server, and no `brightlocal` category registered
> by this plugin. Only Entity Maps ships for now. Flip the constant to `true` to
> deploy it; the rest of this document describes how it works when enabled.

The second BL AI Tools module (`BL_AI_Tool_MCP`, label **BrightLocal MCP**). It
exposes BrightLocal's published content to AI assistants over the
[Model Context Protocol](https://modelcontextprotocol.io) so an LLM can search
and read the site directly.

Built on the sanctioned WordPress stack — the **Abilities API** (register
functions) + the **MCP Adapter** (speak the MCP wire protocol). We only write
the abilities; the adapter handles the JSON-RPC handshake, `tools/list` /
`tools/call`, validation, sessions, transport, and errors.

## Classes (`includes/tools/mcp/`)

| Class | File | Role |
|-------|------|------|
| `BL_AI_Tool_MCP` | `class-bl-ai-tool-mcp.php` | The module. Wires the pieces into the BL AI Tools menu; adds the **BrightLocal MCP** admin page. |
| `BL_MCP_Abilities` | `class-bl-mcp-abilities.php` | Registers the ability category + the two abilities on `wp_abilities_api_init`, with their execute/permission callbacks. |
| `BL_MCP_Server` | `class-bl-mcp-server.php` | Creates the dedicated MCP server via `McpAdapter::create_server()` on `mcp_adapter_init` (HTTP transport). |
| `BL_MCP_Admin` | `class-bl-mcp-admin.php` | Status / endpoint / tools / auth reference screen. |

## Tools (abilities)

All read-only, and hard-limited to **published, public** content (never drafts
or private posts):

**Content (always):**
- **`brightlocal/search_content`** — input `{ query, type?, limit? (1–25) }` →
  `{ results: [{ title, url, excerpt, type, date }] }`. Backed by `WP_Query`.
- **`brightlocal/get_content`** — input `{ url | id }` →
  `{ title, url, type, content }` (full readable text; shortcodes stripped,
  capped at `bl_mcp_max_content_chars`, default 20000).

**EntityMap (only when the Entity Maps tool is present):** reads the same curated
map you manage — decoupled via `class_exists('BL_EntityMap_Store')`, so MCP still
works without it.
- **`brightlocal/search_entities`** — input `{ query?, type?, limit? (1–50) }` →
  `{ results: [{ entityId, name, type, description, sameAs, url }] }`. Omit the
  query to list all (optionally by type).
- **`brightlocal/get_entity`** — input `{ id | name }` → the full entity
  (description, evidence chunks, relations, sameAs, url).

## The server

`create_server()` mounts a dedicated server at **`/wp-json/brightlocal/mcp`**
(namespace `brightlocal`, route `mcp`) with `HttpTransport`. It exposes only the
two tools above — it does **not** use the adapter's default server (so BrightLocal
tools stay isolated from any other abilities on the site).

## Authentication

Transport-level permission requires an authenticated user with a capability
(default `read`, filter `bl_mcp_capability`). The intended method is a WordPress
**Application Password** (Users → Profile), sent as HTTP Basic over HTTPS. Use a
dedicated low-privilege user for MCP access.

## Dependency (feature-level)

Requires the **MCP Adapter** plugin active and **WordPress 6.9+** (Abilities API).
If either is missing, `BL_MCP_Server::available()` is false: no server is
registered (no fatal), and the admin screen says so. The rest of BL AI Tools —
Entity Maps, the files, llms.txt — works without it. Do **not** add a plugin-wide
`Requires Plugins` header; this is gated at runtime, like Yoast for the sitemap.

## Filters

- `bl_mcp_capability` — capability required to use the tools (default `read`).
- `bl_mcp_searchable_types` — post types `search_content` covers (default all public types minus attachments).
- `bl_mcp_max_content_chars` — max characters returned by `get_content` (default 20000).

## Verifying

Real verification needs an MCP client (e.g. a Claude custom connector) pointed at
`/wp-json/brightlocal/mcp` with an application password. Locally you can confirm
the abilities register (`wp_has_ability('brightlocal/search_content')`) and that
`BL_MCP_Server::available()` is true when the adapter is active.
