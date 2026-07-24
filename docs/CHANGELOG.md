# Changelog

All notable changes to **BrightLocal – AI Tools** (`bl-ai-tools`). Versions track
the plugin header `Version:` / `BL_AI_VERSION` (kept in sync). Keep this current
whenever behaviour changes.

## 2.27.2
- Backups now record whether the write succeeded (`bl_em_backup_ok`) and the
  Files tab shows a clear error when the backups directory isn't writable — so a
  failed backup is visible instead of silent. (Fixes the "changes made but no
  backups appear" confusion, which is a writable-directory / old-version issue,
  not a logic bug.)

## 2.27.0
- **BrightLocal MCP disabled for now** via a kill-switch (`BL_AI_Tool_MCP::ENABLED
  = false`). The tool is not registered/booted — no menu, no abilities, no MCP
  server, and this plugin no longer registers the shared `brightlocal` ability
  category. Code retained; flip the constant to `true` to deploy it. Only the
  Entity Maps tool ships for now.

## 2.26.1
- BrightLocal MCP: the `brightlocal` ability-category registration is now
  idempotent (`wp_has_ability_category()` guard), so it's collision-proof if
  another plugin (e.g. a shared mu-plugin) registers the same category —
  regardless of load order.

## 2.26.0
- BrightLocal MCP: added **`search_entities`** and **`get_entity`** tools that
  expose the curated EntityMap (products, services, concepts, research) over MCP
  — name, type, description, evidence, relationships, and verified sameAs links.
  Registered only when the Entity Maps tool is present.

## 2.25.0
- New tool: **BrightLocal MCP** — a dedicated, authenticated Model Context
  Protocol server (`/wp-json/brightlocal/mcp`) that lets AI assistants search
  (`search_content`) and read (`get_content`) BrightLocal's published content.
  Built on the WordPress Abilities API + MCP Adapter; read-only, published
  content only, Application Password auth. Feature-level dependency on the MCP
  Adapter plugin (degrades gracefully when absent). See `docs/mcp.md`.

## 2.24.0
- Brought generated `llms.txt` in line with the llms.txt spec: H1 is now the
  first line (provenance moved to a trailing comment), list items use the
  `[name](url): notes` colon format, and every item is a link (entities without a
  source page link to their anchor in `entitymap.html`).

## 2.23.0
- Help tab redesigned to use native WordPress admin **cards** per section, with
  cleaner typography.
- Fixed the Markdown renderer so wrapped/multi-line list items stay on one line
  (the "Latest changes" list no longer breaks mid-sentence); added italics.

## 2.22.0
- **`llms.txt` now has a dynamic fallback endpoint**, like `entitymap.json`/`.html`.
  Previously it was static-file-only, so it 404'd whenever the webroot wasn't
  writable (files served dynamically). It's now served by WordPress when the
  static file isn't on disk (still behind the "Generate llms.txt" toggle).
  *Requires a rewrite flush on deploy (reactivate or `wp rewrite flush`).*

## 2.21.0
- `entitymap.html` now shows each evidence chunk's **content type** and
  **audience** as small labels, so the human page reflects those fields (they
  were only in `entitymap.json` before).

## 2.20.0
- **Reliable backups/restore.** Snapshots are now taken from the database (the
  live document), not by copying the published file — so a restore point is
  always created even when the webroot isn't writable or no static file exists
  (previously backups silently produced nothing in that case). Added a **Create
  backup now** button on the Files tab.

## 2.19.0
- Help doc now shows a **Latest changes** section pulled live from this changelog
  (top 5 versions), so it stays current automatically.
- Brought `docs/admin-guide.md` and `docs/architecture.md` fully up to date with
  the current tool.

## 2.18.0
- Help tab now renders from `docs/help.md` (single source of truth) via a small
  dependency-free Markdown renderer (`BL_AI_Markdown`); rewritten to cover the
  current tool. Added this changelog.

## 2.17.0
- Added `BL_EntityMap_Sitemap`: a dedicated `/entitymap-sitemap.xml` registered
  with Yoast and listed in `sitemap_index.xml` (lists `entitymap.html`). Yoast is
  a feature-level dependency — no-ops without it; the Files tab shows status.
  Completes the sitemap `<lastmod>` freshness signal.

## 2.16.0 – 2.16.2
- Brandable top-level menu icon from `assets/icon.svg` (base64 SVG), with a
  Dashicon fallback and the `bl_ai_menu_icon` filter.
- Moved the **BL AI Tools** menu below **Tools** in the admin sidebar.

## 2.15.0
- Hid the Yoast Schema.org mapping feature behind a master kill-switch
  (`BL_EntityMap_Schema::FEATURE_ENABLED`). Code retained for future use;
  settings, help, and integrity checks for it are hidden while off.

## 2.14.0
- Added **Settings → Vocabulary**: add custom entity types and relation
  predicates without code, backed by the `bl_em_entity_types` /
  `bl_em_predicates` filters. Additive only (built-ins can't be removed).

## 2.13.0
- Extended the recognised vocabulary: added the `Person` entity type and 13
  relation predicates so enriched imports validate with zero warnings.

## 2.12.0
- Dynamic endpoints send a real `Last-Modified` + `Cache-Control` and answer
  `If-Modified-Since` with `304`.
- Richer `entitymap.html` JSON-LD: an `@graph` with an Organization node and a
  CollectionPage indexing every entity as a `DefinedTerm` (with `@id`, `sameAs`,
  and a link to its source page).

## 2.11.0 – 2.11.1
- `llms.txt` is now generated in full from the EntityMap (title, summary,
  machine-readable index, entities grouped by kind), behind the "Generate
  llms.txt" setting. Added an `llms.txt` card to the Files tab.

## 2.9.0
- Discoverability fixes: absolute URLs in the published output now derive from
  the canonical base URL (environment-independent); a sitewide `<head>`
  `rel="alternate"` link to `entitymap.json`; canonical + alternate links in the
  `entitymap.html` head.

## 2.8.0
- Manage Entities polish: **Find on Wikidata** lookup for `sameAs`, a page-search
  picker for "Attach to page", a saving overlay, a sticky save bar, and an
  automatic backup before every edit/delete.

## 2.7.0
- New **Manage Entities** master–detail screen — the primary way to curate the
  map (add/edit/delete inline, auto-regenerate). The classic per-entity CPT
  editor is hidden from the menu (kept as an internal fallback). Entity IDs are
  now allocated from a monotonic counter, so an ID is never reused.
