# BrightLocal - AI Tools

WordPress plugin (`bl-ai-tools`). Manages an **EntityMap** in wp-admin as the single source of truth, auto-generates `/entitymap.json` (and a human-readable `/entitymap.html`), and drives Yoast Schema.org output.

- **Slug / folder / repo:** `bl-ai-tools`
- **Main file:** `bl-ai-tools.php`
- **Text domain:** `bl-ai-tools`
- **Repo:** https://github.com/asha23/bl-ai-tools
- **Version:** see `BL_AI_VERSION` in `bl-ai-tools.php` (kept in sync with the plugin header `Version:`)

> Formerly "BrightLocal - Schema Extender & EntityMap" (`bl-schema-extender`). Renamed to AI Tools; the EntityMap feature and its `BL_EntityMap_*` classes are unchanged.

## Layout

```
bl-ai-tools.php                 Bootstrap: defines BL_AI_* constants, requires classes, wires boot/activation hooks
includes/
  class-bl-entitymap-store.php      Reads bl_entity CPT posts into entity data; caches (BL_EntityMap_Store)
  class-bl-entitymap-cpt.php        Registers the `bl_entity` CPT + meta boxes / repeater UI
  class-bl-entitymap-generator.php  Builds the entitymap document; serves /entitymap.json + .html; writes static files
  class-bl-entitymap-schema.php     Yoast filters: enrich Organization + inject per-page DefinedTerm/Service nodes
  class-bl-entitymap-importer.php   Import entities from uploaded files
  class-bl-entitymap-admin.php      wp-admin settings/tools/help pages under the EntityMap menu
composer.json                   type: wordpress-plugin
```

## Conventions

- **Naming:** plugin-level constants and functions use the `BL_AI_*` / `bl_ai_*` prefix (`BL_AI_VERSION`, `BL_AI_DIR`, `bl_ai_boot`). Feature classes keep descriptive prefixes: `BL_EntityMap_*` (the EntityMap feature). Do **not** rename feature classes to `BL_AI_*` — they name features, not the plugin.
- **CPT:** post type is `bl_entity` (`BL_EntityMap_CPT::CPT`). This is data-facing; changing it would orphan existing entity posts.
- **Booting:** everything is instantiated in `bl_ai_boot()` on `plugins_loaded`. Rewrite rules for `/entitymap.json` are flushed on activation (`bl_ai_activate`).
- **Caching:** the store and generator cache via transients (`bl_entitymap_*` keys). CPT saves/deletes flush the cache and fire `bl_entitymap_changed` to regenerate.
- **Schema injection is behind a master toggle** and off by default (settings page under the EntityMap menu). Yoast (`wpseo_schema_*`) filters only attach when enabled.
- **Version bumps:** update BOTH the header `Version:` and `BL_AI_VERSION` together, and follow the existing commit-message style (`vX.Y.Z: summary`).

## Gotchas

- Renaming the plugin folder or main file deactivates the plugin in WordPress — it must be reactivated, which re-flushes rewrite rules so `/entitymap.json` resolves.
- **Yoast SEO is a feature-level dependency, not plugin-wide.** Two features need it: the XML sitemap (`BL_EntityMap_Sitemap` — registers `/entitymap-sitemap.xml`) and the (currently hidden) schema mapping (`BL_EntityMap_Schema`). Both no-op gracefully when Yoast is absent — the core (Manage Entities, `entitymap.json`/`.html`, `llms.txt`) works without it. Do **not** add a plugin-wide `Requires Plugins` header; gate at runtime and signpost in the UI instead.
- No build step and no automated test suite — this is plain PHP loaded directly by WordPress.
