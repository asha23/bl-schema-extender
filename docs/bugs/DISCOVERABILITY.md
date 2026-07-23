# BrightLocal EntityMap — Discoverability Fixes (for the developer)

**Prepared for:** the web/dev team · **Date:** 23 July 2026
**Goal:** make the EntityMap (`/entitymap.html` + `/entitymap.json`) discoverable and correctly signposted to AI systems and search crawlers.
**Scope:** verified live against production on 23 July 2026 (Chrome). Findings below are current-state, not assumptions.
**Status update:** 23 July 2026 — much of this is now handled in the `bl-ai-tools` plugin (**v2.12.0**). See the Status column and per-item notes.

> **Access is already fine** — the AI crawlers that cite pages in answers (`OAI-SearchBot`, `PerplexityBot`, `Claude-SearchBot`, `ChatGPT-User`, `Claude-User`, `DuckAssistBot`, and `Googlebot` for AI Overviews) are all allowed in robots.txt. The problem is **discovery + correctness**, not permission. No robots.txt change is required to expose the map.

---

## Progress at a glance (plugin v2.12.0)

| # | Priority | Action | Status |
|---|---|---|---|
| 1 | P1 — bug | Fix the broken URL in the entity map's JSON-LD | ✅ **Done** (plugin, v2.9.0) — *needs config, see below* |
| 2 | P1 | Add `entitymap.html` + `entitymap.json` to the XML sitemap | ⏳ **Remaining** (plugin-scope, not yet built) |
| 3 | P1 | Add the entity map to `llms.txt` | ✅ **Done** (v2.11.0) — plugin now **generates the whole file** |
| 4 | P1 | Site-wide `<head>` alternate link to `entitymap.json` | ✅ **Done** (v2.9.0) |
| 5 | P2 | Canonical + self alternate link on `entitymap.html` | ✅ **Done** (v2.9.0) |
| 6 | P2 | Visible internal link to `/entitymap.html` (e.g. footer) | 🙅 **Web/theme team** (not the plugin) |
| 7 | P2 | Reconcile research naming across llms.txt / map / site | ⏳ **Partly auto-resolved** — see note |
| 8 | P3 | `Last-Modified` header (+ sitemap `<lastmod>`) | ✅ **Done** (v2.12.0) — sitemap half tied to #2 |
| 9 | P3 — enhancement | Expand the JSON-LD beyond a bare `WebPage` | ✅ **Done** (v2.12.0) |
| 10 | P3 | Tidy robots.txt (`User-agent: *` blocks) | 🙅 **Web/server team** (not the plugin) |
| 11 | P3 — decision | Training-crawler carve-out for the entity map | 🙅 **Web team + policy sign-off** |
| 12 | Final | Verify everything (see §12) | ⏳ **Pending** (after deploy + config) |

### ⚠️ Required configuration (or the plugin fixes do nothing)

The plugin work above only takes effect once these are set:

1. **Settings → Canonical base URL** = `https://www.brightlocal.com`, then **Regenerate**.
   This is what makes #1/#4/#5/#9 emit production URLs. All generated absolute URLs now derive from this value rather than the host that happens to regenerate the files — the root cause of the `corpblob-roots.try` placeholder in #1 was that the files were regenerated on a non-production host. Setting this + regenerating is mandatory.
2. **Settings → Publish EntityMap files** — on (default).
3. **Settings → Generate llms.txt** — on (off by default) for #3.
4. **Deploy note:** `entitymap.json`, `entitymap.html`, and `llms.txt` are regenerated at runtime and were being committed to the site repo, which baked a staging URL into production. They are now git-ignored in `corpblob-roots`, so the running copy is authoritative. Ensure a clean deploy still produces them (regenerate once the Canonical base URL is set).
5. **Release:** the plugin ships via Composer (`asha23/bl-ai-tools: dev-master`); merge the feature branch into `master` and push, then `composer update` on the site.

---

## Current state (as first audited, 23 Jul 2026)

*Historical snapshot — kept for reference. Items marked Done above are addressed in the plugin but pending the config/deploy steps.*

- `entitymap.html`: `<meta name="robots">` = `index, follow` ✓. **No `<link>` tags at all** — no canonical, no link to the JSON. Its JSON-LD (`@type: WebPage`) contained a **placeholder URL**: `https://corpblob-roots.try/entitymap.html`.
- Homepage: does **not** link to `entitymap.html` or `entitymap.json` (no `<head>` link, no body link).
- `sitemap_index.xml`: entity map is **not listed**.
- `llms.txt`: exists and is good, but **does not reference the entity map**; lists research as "Brand Review Index" while the entity map says "Brand Beacon Report".
- `robots.txt`: citation/real-time AI crawlers allowed; training crawlers (`GPTBot`, `Google-Extended`, `ClaudeBot`, `anthropic-ai`, `CCBot`, …) `Disallow: /`.

---

## Details

### 1. (P1 — bug) Fix the JSON-LD URL on `entitymap.html` — ✅ Done (v2.9.0)
The block shipped with a placeholder domain (`https://corpblob-roots.try/entitymap.html`) because the generator built absolute URLs from `home_url()` at generation time, so whichever host regenerated the file stamped its own domain in.

**Fix:** all absolute self-URLs (JSON-LD `url`, publisher URL, the links in #4/#5/#9) now derive from `BL_EntityMap_Store::base_url()` — the pinned **Canonical base URL** setting. Regenerating anywhere now produces production URLs.
**Action required:** set the Canonical base URL to `https://www.brightlocal.com` and Regenerate (see config box).

### 2. (P1) Add the entity map to the XML sitemap — ⏳ Remaining
Still to do, and the last plugin-scope item. The files are static and sit outside WordPress, and Yoast (active here) disables core sitemaps and has a fussy API for injecting extra URLs, so it needs real integration work (a Yoast sitemap filter registering the two URLs). Target:
```xml
<url>
  <loc>https://www.brightlocal.com/entitymap.html</loc>
  <lastmod>2026-07-23T00:00:00+00:00</lastmod>
</url>
```

### 3. (P1) Add the entity map to `llms.txt` — ✅ Done (v2.11.0), went further
Rather than just adding a pointer, the plugin now **generates the entire `llms.txt` from the EntityMap**: title + summary (from the Organization entity), a machine-readable index linking `entitymap.html`/`.json`, and the entities grouped by kind and linked to their source pages. Behind the **Generate llms.txt** setting (off by default); writes `/llms.txt` on every regenerate.
**Action required:** enable the setting. **Note:** it overwrites any existing `llms.txt`, so the EntityMap becomes the source of truth for that file.

### 4. (P1) Site-wide `<head>` alternate link — ✅ Done (v2.9.0)
A `wp_head` hook emits on every front-end page:
```html
<link rel="alternate" type="application/json" href="https://www.brightlocal.com/entitymap.json" title="BrightLocal EntityMap">
```
Suppressed when publishing is off, and on the map's own endpoints. URL uses the Canonical base URL.

### 5. (P2) Canonical + self alternate link on `entitymap.html` — ✅ Done (v2.9.0)
The generated `<head>` now includes:
```html
<link rel="canonical" href="https://www.brightlocal.com/entitymap.html">
<link rel="alternate" type="application/json" href="https://www.brightlocal.com/entitymap.json" title="BrightLocal EntityMap">
```

### 6. (P2) Add a visible internal link — 🙅 Web/theme team
Add a footer (or About/Learning-Hub) link to `/entitymap.html` so it sits in the normal crawl/link graph. Anchor text e.g. "EntityMap (for AI systems)". This lives in the theme, not the plugin.

### 7. (P2) Reconcile research naming — ⏳ Partly auto-resolved
`llms.txt` said **"Brand Review Index"**; the entity map says **"Brand Beacon Report"**. Now that the plugin generates `llms.txt` from the EntityMap (#3), that file will automatically use the map's names — so `llms.txt` and the map agree once generation is enabled. **Still manual:** decide the canonical name, and make the **site page(s)** and any other references agree with the map.

### 8. (P3) Freshness signals — ✅ Done (v2.12.0)
The dynamic endpoints now send a real `Last-Modified` (from a recorded change time, stamped on any entity/settings change), `Cache-Control: public, max-age=0, must-revalidate`, and answer conditional `If-Modified-Since` requests with **304 Not Modified**. (Static files already get `Last-Modified` from the filesystem; this covered the dynamic fallback, which previously sent `nocache_headers()`.) The **sitemap `<lastmod>`** half depends on #2.

### 9. (P3 — enhancement) Expand the JSON-LD — ✅ Done (v2.12.0)
Replaced the bare `WebPage` with an `@graph`:
- an **Organization** node (`@id`, name, url, + `sameAs` when set), and
- a **CollectionPage** referencing the Organization via `@id` (`publisher` + `about`) that indexes every entity as a **`DefinedTerm`** with a stable `@id`, `termCode`, description, verified `sameAs`, and a link to its source page.

**Highest-value follow-up:** set the **Publisher `sameAs`** (Settings) and/or a `sameAs` on the Organization entity `e_001` — currently empty, so the Organization node ships without one.

### 10. (P3) Tidy robots.txt — 🙅 Web/server team
Two `User-agent: *` groups (top block + Yoast block) — consolidate into one. Optional harmless pointer:
```
# EntityMap: https://www.brightlocal.com/entitymap.json
```

### 11. (P3 — decision, needs AI-policy sign-off) Training-crawler carve-out — 🙅 Web team + policy
AI crawlers do two jobs:
- **Retrieval / citation** (query-time answers) — `OAI-SearchBot`, `PerplexityBot`, `Claude-SearchBot`, `ChatGPT-User`, `Claude-User`, Googlebot. **Allowed today** — the map's primary purpose, not blocked.
- **Training** (into model weights) — `GPTBot`, `Google-Extended`, `ClaudeBot`, `CCBot`, etc. **Blocked today** (`Disallow: /`).

Only the training path is blocked. **Optional middle ground** — expose only the map to training crawlers without changing the site-wide stance (a specific `Allow` overrides `Disallow: /`):
```
User-agent: GPTBot
Allow: /entitymap.html
Allow: /entitymap.json
Disallow: /
```
Repeat per training crawler. Low-risk (the map is purpose-built to be machine-ingested), but **do not implement without sign-off from whoever owns the AI-crawler policy.**

### 12. (Final) Verify — ⏳ Pending
After deploy + config (Canonical base URL set, regenerated, llms.txt enabled):
- Fetch `entitymap.json` → confirm `Content-Type: application/json` and an accurate `Last-Modified`.
- Validate the JSON-LD (schema validator / Google Rich Results Test) — confirm production URLs and the `@graph` (Organization + CollectionPage).
- Confirm `entitymap.html` has canonical + alternate links and the correct JSON-LD URL.
- Confirm the `<head>` alternate link renders on the homepage.
- Confirm `/llms.txt` is generated and points at the map (once enabled).
- Confirm `entitymap.html` appears in `sitemap_index.xml` and returns `200` (**after #2**).
- Re-run the AI citation test queries at 30 / 60 / 90 days.

---

*Items 1, 3, 4, 5, 8, 9 are done in the plugin (v2.12.0) pending the config/deploy steps. #2 is the remaining plugin task; #6, #10, #11 are web/team work; #7 is partly automatic and partly editorial.*
