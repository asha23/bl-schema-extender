# BrightLocal - AI Tools

WordPress plugin (`bl-ai-tools`). A modular host for AI-facing tools; currently one tool, **Entity Maps**, which curates an EntityMap in wp-admin (the **Manage Entities** screen) as the single source of truth and publishes it as `/entitymap.json`, a human-readable `/entitymap.html`, and a generated `/llms.txt`, plus an XML sitemap entry via Yoast. An optional Yoast Schema.org integration is included but **currently hidden** (`BL_EntityMap_Schema::FEATURE_ENABLED = false`).

- **Slug / folder / repo:** `bl-ai-tools`
- **Main file:** `bl-ai-tools.php`
- **Text domain:** `bl-ai-tools`
- **Repo:** https://github.com/asha23/bl-ai-tools
- **Version:** see `BL_AI_VERSION` in `bl-ai-tools.php` (kept in sync with the plugin header `Version:`)

> Formerly "BrightLocal - Schema Extender & EntityMap" (`bl-schema-extender`). Renamed to AI Tools; the EntityMap feature and its `BL_EntityMap_*` classes are unchanged.

## Layout

```
bl-ai-tools.php                       Bootstrap: BL_AI_* constants, requires framework + tools, boot/activation hooks
includes/
  framework/
    class-bl-ai-tool.php              Abstract base for a tool module
    class-bl-ai-tools-registry.php    Holds/boots tools; owns the top-level "BL AI Tools" menu + dashboard
    class-bl-ai-markdown.php          Tiny Markdown→HTML renderer (lets admin screens render docs/*.md)
  tools/entity-maps/
    class-bl-ai-tool-entity-maps.php  The Entity Maps tool module (wires the classes below into the menu)
    class-bl-entitymap-store.php      Reads/writes bl_entity posts ↔ entity data; vocab; caching (BL_EntityMap_Store)
    class-bl-entitymap-cpt.php        Registers the bl_entity CPT (hidden from menu) + meta boxes
    class-bl-entitymap-manager.php    The Manage Entities master–detail screen + AJAX (save/delete/page + Wikidata search)
    class-bl-entitymap-generator.php  Builds the document; serves/writes entitymap.json + .html + llms.txt; head links
    class-bl-entitymap-sitemap.php    Registers entitymap-sitemap.xml with Yoast (feature-level Yoast dependency)
    class-bl-entitymap-schema.php     Yoast schema filters (currently hidden behind FEATURE_ENABLED)
    class-bl-entitymap-importer.php   Import/upsert entities from a decoded document
    class-bl-entitymap-backups.php    Timestamped entitymap.json snapshots; restore
    class-bl-entitymap-admin.php      The tabbed hub: Manage Entities · Files · Import · Settings · Help
    assets/manage.{js,css}            Manage Entities screen assets (vanilla JS, no build)
assets/icon.svg                       Top-level menu icon
docs/                                 help.md (rendered as the Help tab), CHANGELOG.md, admin-guide.md, architecture.md, data-model.md, schema-integration.md, bugs/, features/
uninstall.php                         Full data cleanup on plugin delete
composer.json                         type: wordpress-plugin
```

## Conventions

- **Naming:** plugin-level constants and functions use the `BL_AI_*` / `bl_ai_*` prefix (`BL_AI_VERSION`, `BL_AI_DIR`, `bl_ai_boot`). Feature classes keep descriptive prefixes: `BL_EntityMap_*` (the EntityMap feature). Do **not** rename feature classes to `BL_AI_*` — they name features, not the plugin.
- **CPT:** post type is `bl_entity` (`BL_EntityMap_CPT::CPT`). This is data-facing; changing it would orphan existing entity posts.
- **Booting:** everything is instantiated in `bl_ai_boot()` on `plugins_loaded`. Rewrite rules for `/entitymap.json` are flushed on activation (`bl_ai_activate`).
- **Caching:** the store and generator cache via transients (`bl_entitymap_*` keys). CPT saves/deletes flush the cache and fire `bl_entitymap_changed` to regenerate.
- **Schema injection is behind a master toggle** and off by default (settings page under the EntityMap menu). Yoast (`wpseo_schema_*`) filters only attach when enabled.
- **Version bumps:** update BOTH the header `Version:` and `BL_AI_VERSION` together on **every** change (the plugin ships via GitHub/Composer, so the version is how sites pull a new build), and follow the existing commit-message style (`vX.Y.Z: summary`).
- **Docs are part of every change — keep them current in the same commit.** This is a hard rule (the in-admin Help is generated from these, and teammates rely on them):
  - `docs/help.md` — rendered **live** as the Help tab via `BL_AI_Markdown`; the single source of truth for end-user help (no hardcoded help HTML in PHP). Stay within the renderer's subset: `#`/`##`/`###` headings, `-`/`1.` lists, `**bold**`, `` `code` ``, `[links](url)`, ` ``` ` fences — **no tables**. Internal links use `{{tab:*}}` / `{{url:*}}` tokens resolved in `tab_help()`.
  - `docs/CHANGELOG.md` — add an entry per version bump. The Help tab's "Latest changes" pulls the top 5 from here automatically (`{{changelog:5}}`), so don't duplicate it by hand.
  - `docs/admin-guide.md` (tabs / options / mechanics) and `docs/architecture.md` (classes / boot / hooks / data flow) — update when behaviour or structure changes. Keep this `CLAUDE.md` (intro, Layout) accurate too.

## Gotchas

- Renaming the plugin folder or main file deactivates the plugin in WordPress — it must be reactivated, which re-flushes rewrite rules so `/entitymap.json` resolves.
- **Yoast SEO is a feature-level dependency, not plugin-wide.** Two features need it: the XML sitemap (`BL_EntityMap_Sitemap` — registers `/entitymap-sitemap.xml`) and the (currently hidden) schema mapping (`BL_EntityMap_Schema`). Both no-op gracefully when Yoast is absent — the core (Manage Entities, `entitymap.json`/`.html`, `llms.txt`) works without it. Do **not** add a plugin-wide `Requires Plugins` header; gate at runtime and signpost in the UI instead.
- No build step and no automated test suite — this is plain PHP loaded directly by WordPress.
