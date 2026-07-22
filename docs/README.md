# BrightLocal — AI Tools

WordPress plugin (`bl-ai-tools`) that manages an **EntityMap** in wp-admin as the
single source of truth, auto-publishes it as `/entitymap.json` (machine-readable)
and `/entitymap.html` (human-readable), and can optionally drive Yoast's
Schema.org output.

> Formerly *"BrightLocal — Schema Extender & EntityMap"* (`bl-schema-extender`).
> Renamed to AI Tools; the EntityMap feature and its `BL_EntityMap_*` classes are
> unchanged. The plugin is explicitly a **work in progress** — EntityMap
> management is currently the only shipping capability.

- **Slug / folder / repo:** `bl-ai-tools`
- **Main file:** [`bl-ai-tools.php`](../bl-ai-tools.php)
- **Text domain:** `bl-ai-tools`
- **Repo:** https://github.com/asha23/bl-ai-tools
- **Version:** `BL_AI_VERSION` in [`bl-ai-tools.php`](../bl-ai-tools.php) (kept in
  sync with the plugin header `Version:`), currently **2.5.0**
- **Dependencies:** [Yoast SEO](https://yoast.com/wordpress/plugins/seo/) for all
  Schema.org output. No build step, no test suite.

## What it does, in one paragraph

You maintain a list of the **things BrightLocal is about** — products, services,
concepts, proprietary terms, research — as `bl_entity` posts in wp-admin. That
one list is normalised into a canonical shape and published automatically to two
static files (`entitymap.json` + `entitymap.html`) written to the webroot, with a
WordPress rewrite as a dynamic fallback. When explicitly enabled, the same data is
also layered into Yoast's Schema.org `@graph` (Organization enrichment + per-page
nodes). You never edit a file by hand — the DB is the single source of truth.

## Documentation index

| Doc | What's in it |
|-----|--------------|
| [architecture.md](architecture.md) | Boot sequence, class responsibilities, data flow, caching, the two output endpoints |
| [data-model.md](data-model.md) | The `bl_entity` CPT, its meta keys, controlled vocabularies, and the `entitymap.json` document shape |
| [schema-integration.md](schema-integration.md) | Yoast Organization enrichment, per-page nodes, and the master toggle |
| [admin-guide.md](admin-guide.md) | Settings, Tools (import / regenerate / verify), Help page, and the validation checks |

## File layout

```
bl-ai-tools.php                          Bootstrap: BL_AI_* constants, requires, boot/activation hooks
includes/
  class-bl-entitymap-store.php           Reads bl_entity posts → normalised entity data; caching, vocabularies
  class-bl-entitymap-cpt.php             Registers the bl_entity CPT + native meta-box repeater UI
  class-bl-entitymap-generator.php       Builds the document; serves /entitymap.json + .html; writes static files
  class-bl-entitymap-schema.php          Yoast filters: enrich Organization + inject per-page nodes
  class-bl-entitymap-importer.php        Import + validate entities from an entitymap.json document
  class-bl-entitymap-admin.php           Settings / Tools / Help pages under the EntityMap menu
docs/                                    ← this documentation
```

## Conventions (important before editing)

- **Naming:** plugin-level constants/functions use `BL_AI_*` / `bl_ai_*`
  (`BL_AI_VERSION`, `BL_AI_DIR`, `bl_ai_boot`). Feature classes keep descriptive
  prefixes: `BL_EntityMap_*`. **Do not** rename feature classes to `BL_AI_*` —
  they name features, not the plugin.
- **CPT:** the post type is `bl_entity` (`BL_EntityMap_CPT::CPT`). It's
  data-facing — changing it orphans existing entity posts.
- **Version bumps:** update **both** the header `Version:` and `BL_AI_VERSION`
  together, and follow the commit-message style `vX.Y.Z: summary`.

## Gotchas

- Renaming the plugin folder or main file deactivates the plugin — it must be
  reactivated, which re-flushes the rewrite rules so `/entitymap.json` resolves.
- All Schema.org output depends on **Yoast SEO** being active.
- Schema injection is behind a **master toggle and off by default**. The static
  `entitymap.json` / `.html` files are the default behaviour and are independent
  of the Yoast injection toggle.
