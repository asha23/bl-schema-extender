# EntityMap — remaining work for the web team

**Context:** The `bl-ai-tools` plugin (v2.17.0) now handles all the *plugin-scope* EntityMap discoverability work — the `entitymap.json` / `entitymap.html` / `llms.txt` files, the `<head>` alternate link, canonical/alternate links, richer JSON-LD, freshness headers, and a dedicated `entitymap-sitemap.xml`. Full detail: [../bugs/DISCOVERABILITY.md](../bugs/DISCOVERABILITY.md).

Everything below is **outside the plugin** — theme, content, server, or policy. None of it needs a plugin change.

---

## 0. Prerequisite (dev/release — not the web team, but blocks verification)

Before any of the below can be verified live, the plugin must be **released** (merge to `master` → `composer update`) and configured: **Settings → Entity Maps → Canonical base URL = `https://www.brightlocal.com`**, then **Regenerate**. Until then the files carry the wrong host.

---

## 1. Confirm production is NOT `noindex` — 🔴 P1 (infra)

**Finding:** on the dev box (`env-…kinsta.cloud`) `/entitymap.html` and `/entitymap.json` return:
```
x-robots-tag: noindex, nofollow, nosnippet, noarchive
```
That's expected on a staging environment (Kinsta keeps it out of the index), **but it must NOT be present on production** or the whole discoverability effort is dead on arrival — crawlers will refuse to index the map.

- **Do:** on `www.brightlocal.com`, confirm `/entitymap.html` and `/entitymap.json` return **no** `noindex` `X-Robots-Tag` header (the plugin already sets `index, follow` in the page `<meta>` and on the dynamic endpoint, but a server/CDN-level header overrides it).
- **Verify:** `curl -sI https://www.brightlocal.com/entitymap.html | grep -i x-robots-tag` → should be absent or `index, follow`.

## 2. Visible internal link to `/entitymap.html` — 🟠 P2 (theme) — report #6

The map is currently orphaned from the site's link graph. Add a visible link so crawlers reach it naturally.

- **Do:** add a footer link (or in About / Learning Hub) to `https://www.brightlocal.com/entitymap.html`. Suggested anchor: **"EntityMap (for AI systems)"**.
- **Why:** internal links are a primary discovery path; the `<head>`/sitemap signals are stronger with a real link backing them.

## 3. Reconcile research naming on the site — 🟠 P2 (content) — report #7

`llms.txt` used to say **"Brand Review Index"** while the EntityMap says **"Brand Beacon Report"**. The plugin now generates `llms.txt` from the map, so **llms.txt and the map already agree**. What's left is editorial:

- **Do:** decide the canonical name, then make the **site page(s)** and any other references (nav, campaigns) match the EntityMap. If the map's name is wrong, fix it in **Manage Entities** instead.

## 4. Submit / confirm the sitemap in Search Console — 🟠 P2 (SEO)

- **Do:** ensure `https://www.brightlocal.com/sitemap_index.xml` is submitted in Google Search Console. It now lists `entitymap-sitemap.xml` (child), so no separate submission is needed — but confirm the child is picked up.
- **One-time after deploy:** flush rewrites and clear Yoast's sitemap cache (SEO → Tools) so the child sitemap appears.

## 5. Tidy `robots.txt` — 🟡 P3 (server) — report #10

- **Do:** there are two `User-agent: *` groups (top block + Yoast block) — consolidate into one to avoid parser ambiguity. Optional harmless human pointer:
  ```
  # EntityMap: https://www.brightlocal.com/entitymap.json
  ```
- **Note:** no robots.txt change is *required* for the map to be found — citation/retrieval crawlers are already allowed. This is hygiene.

## 6. Training-crawler carve-out — ⚪ P3 (decision + policy sign-off) — report #11

Retrieval/citation crawlers (OAI-SearchBot, PerplexityBot, Claude-SearchBot, ChatGPT-User, Googlebot…) are **allowed today** — that's the map's main purpose and it's not blocked. Training crawlers (`GPTBot`, `Google-Extended`, `ClaudeBot`, `CCBot`, …) are **blocked** site-wide (`Disallow: /`).

- **Optional:** to let *only the map* into training crawls without changing the site-wide no-training stance (a specific `Allow` overrides `Disallow: /`):
  ```
  User-agent: GPTBot
  Allow: /entitymap.html
  Allow: /entitymap.json
  Disallow: /
  ```
  Repeat per training crawler.
- **Do not implement without sign-off** from whoever owns the AI-crawler policy. It's a deliberate business decision, not a technical fix.

---

## Priority summary

| # | Task | Owner | Priority |
|---|------|-------|----------|
| 1 | Confirm production isn't `noindex` | Infra | 🔴 P1 |
| 2 | Footer/internal link to `/entitymap.html` | Theme | 🟠 P2 |
| 3 | Reconcile research naming on site pages | Content | 🟠 P2 |
| 4 | Confirm sitemap in Search Console | SEO | 🟠 P2 |
| 5 | Consolidate `robots.txt` `User-agent: *` blocks | Server | 🟡 P3 |
| 6 | Training-crawler carve-out (decision) | Policy | ⚪ P3 |
