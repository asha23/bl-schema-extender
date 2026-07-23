# Architecture

## Boot sequence

The bootstrap [`bl-ai-tools.php`](../bl-ai-tools.php) is now thin — it wires the
tool registry, not the feature directly:

1. Guards `ABSPATH`, then defines `BL_AI_VERSION`, `BL_AI_FILE`, `BL_AI_DIR`.
2. `require_once`s the framework (`BL_AI_Tool`, `BL_AI_Tools_Registry`) and each
   tool module (currently just `class-bl-ai-tool-entity-maps.php`, which in turn
   `require`s its own feature classes).
3. `bl_ai_registry()` builds a singleton `BL_AI_Tools_Registry` and registers the
   tool instances (`$registry->add( new BL_AI_Tool_EntityMaps() )`).
4. `bl_ai_boot()` runs on `plugins_loaded` and calls `$registry->boot()`, which:
   - calls `register()` on every tool (that's where the EntityMap module
     instantiates `BL_EntityMap_CPT`, `BL_EntityMap_Generator`,
     `BL_EntityMap_Schema`, and `BL_EntityMap_Admin` — the last of which
     constructs `BL_EntityMap_Manager`, wiring its AJAX + asset hooks);
   - hooks `admin_menu` at **priority 9** so the top-level menu exists before WP
     builds CPT submenus (priority 10) that attach to it.
5. **Activation** (`bl_ai_activate`): calls `$registry->activate()` (each tool
   registers its CPTs/rewrites), then `flush_rewrite_rules()` **once**.
6. **Deactivation** (`bl_ai_deactivate`): `flush_rewrite_rules()`.
7. **Uninstall** ([`uninstall.php`](../uninstall.php)): on plugin *delete*, removes
   all options, transients, `bl_entity` posts, the static files, and the backups
   directory.

`BL_EntityMap_Store` and `BL_EntityMap_Importer` remain **static utility
classes** — never instantiated in the boot path.

## The modular framework

| Class | File | Role |
|-------|------|------|
| `BL_AI_Tool` | `framework/class-bl-ai-tool.php` | Abstract base. A tool implements `id()` + `label()`, and optionally `description()`, `icon()`, `menu_slug()`, `register()` (runtime hooks), `register_admin($parent)` (submenus), and `activate()`. |
| `BL_AI_Tools_Registry` | `framework/class-bl-ai-tools-registry.php` | Holds the tools, boots them, owns the top-level **BL AI Tools** menu (`MENU_SLUG = bl-ai-tools`), and renders the dashboard of tool cards. |

## Class responsibilities (Entity Maps tool)

All under `includes/tools/entity-maps/`:

| Class | File | Instantiated? | Role |
|-------|------|:-------------:|------|
| `BL_AI_Tool_EntityMaps` | `class-bl-ai-tool-entity-maps.php` | ✓ | The module. Wires the classes below into the BL AI Tools menu and adds the "Entity Maps" tabbed hub submenu. `require`s its own feature classes. |
| `BL_EntityMap_Store` | `class-bl-entitymap-store.php` | static | The single point that **reads and writes** entities. Reads/normalises to the `entitymap.json` shape (`build_entity()`); reads raw editable meta for the editor (`get_entity_for_edit()`); writes/sanitises meta for both editors (`save_entity_meta()` + `sanitize_chunks/relations()`). Owns vocabularies, URL normalisation, caching, and monotonic `next_entity_id()`. |
| `BL_EntityMap_CPT` | `class-bl-entitymap-cpt.php` | ✓ | Registers the `bl_entity` CPT (`show_in_menu => false` — hidden; the Manage Entities screen is the way in, but `show_ui` stays true so the classic per-post editor still works as an internal fallback), the three meta boxes, save (via the shared `Store::save_entity_meta()`), columns & ordering, the vanilla-JS repeater. |
| `BL_EntityMap_Manager` | `class-bl-entitymap-manager.php` | ✓ | The **Manage Entities** master–detail screen (default hub tab) and its AJAX endpoints (`bl_em_save_entity` / `_delete_entity` / `_search_pages`). Upserts `bl_entity` posts through the same `Store` write path as the classic editor, then flushes + regenerates. Assets: `assets/manage.{js,css}`. |
| `BL_EntityMap_Generator` | `class-bl-entitymap-generator.php` | ✓ | Assembles the document, renders JSON + HTML, registers rewrites + query vars, serves the dynamic endpoints, writes both static files. |
| `BL_EntityMap_Schema` | `class-bl-entitymap-schema.php` | ✓ | Hooks Yoast's `wpseo_schema_*` filters to enrich the Organization node and inject per-page nodes. Currently hidden behind the `FEATURE_ENABLED` kill-switch. |
| `BL_EntityMap_Sitemap` | `class-bl-entitymap-sitemap.php` | ✓ | Registers `/entitymap-sitemap.xml` with Yoast (`register_sitemap` + `wpseo_sitemap_index`) listing `entitymap.html`, and clears Yoast's cache on change. **Feature-level Yoast dependency** — no-ops (no fatal) when Yoast is absent; the rest of the plugin is unaffected. |
| `BL_EntityMap_Importer` | `class-bl-entitymap-importer.php` | static | Imports/upserts entities from a decoded `entitymap.json`, and validates a document as a dry run. |
| `BL_EntityMap_Backups` | `class-bl-entitymap-backups.php` | static | Timestamped `entitymap.json` snapshots in a private uploads dir; archive-before-import, list, download, restore (re-import + regenerate), prune. |
| `BL_EntityMap_Admin` | `class-bl-entitymap-admin.php` | ✓ | The tabbed **Entity Maps** hub (Files / Import / Settings / Help), settings, tool actions, gated downloads, and the live DB-integrity validator. |

## Data flow

```
              wp-admin editor / import                 read/normalise
  bl_entity posts + postmeta  ───────────►  BL_EntityMap_Store::get_entities()
        (source of truth)                          │  (cached, DAY_IN_SECONDS)
                                                    ▼
                                    ┌───────────────┴─────────────────┐
                                    ▼                                  ▼
                       BL_EntityMap_Generator             BL_EntityMap_Schema
                       get_document()/get_json()/get_html()   (Yoast @graph filters,
                                    │                          only when enabled)
                    ┌───────────────┼───────────────┐              │
                    ▼               ▼               ▼              ▼
          static entitymap.json  static .html   dynamic          Yoast Schema.org
          (webroot)              (webroot)      WP endpoints      on-page output
                                                (fallback)
```

Everything downstream reads through `BL_EntityMap_Store` — the JSON generator,
the HTML renderer, the Yoast schema filters, and the admin validators — so the
outputs can never drift from each other or from the database.

## Caching & invalidation

Two transients, both set for `DAY_IN_SECONDS`:

- `bl_entitymap_entities_v2` — normalised entity array (Store).
- `bl_entitymap_json_v2` — the pretty-printed JSON string (Generator).

Invalidation path:

- Saving a `bl_entity` (`save_post_bl_entity`) → `BL_EntityMap_Store::flush_cache()`.
- Deleting or trashing a `bl_entity` (`deleted_post` / `trashed_post`) →
  `maybe_flush()` → `flush_cache()` (only if the post is a `bl_entity`).
- Saving Settings → `maybe_regenerate_after_save()` flushes + regenerates.
- Importing → `flush_cache()` at the end of `import_array()`.

`flush_cache()` deletes the entity transient **and** fires the
`bl_entitymap_changed` action. The Generator listens on that action and runs
`regenerate()`, which clears the JSON transient, rebuilds from a forced
(uncached) read, and rewrites both static files.

## The two output endpoints

`BL_EntityMap_Generator` publishes each document **two ways**:

1. **Static files**, written to the webroot on every change
   (`dirname(ABSPATH)/entitymap.json` and `…/entitymap.html`, both filterable via
   `bl_entitymap_path` / `bl_entitymap_html_path`). Served directly by the web
   server — fast, no PHP.
2. **Dynamic WP endpoints** as a fallback when the webroot isn't writable. Two
   rewrite rules map `^entitymap\.json$` and `^entitymap\.html$` to internal
   query vars (`bl_entitymap`, `bl_entitymap_html`); `maybe_serve()` on
   `template_redirect` renders and echoes the content with the right
   `Content-Type` and `X-Robots-Tag: index, follow`.

`regenerate()` refuses to overwrite existing maps with an **empty** one — if the
DB has no entities it returns early, preserving any seed file the importer reads.
The `bl_em_static_ok` option records whether the last static write succeeded, so
the Tools page can show *writable ✓* vs *served dynamically*.

Both endpoints 404 (and static writing is skipped) when the *Publish EntityMap
files* toggle (`bl_em_enable_json`) is off.

## Extension points (hooks)

| Hook | Type | Purpose |
|------|------|---------|
| `bl_entitymap_changed` | action | Fired on any entity/settings change; the Generator regenerates on it. |
| `bl_entitymap_path` | filter | Override the static JSON file path. |
| `bl_entitymap_html_path` | filter | Override the static HTML file path. |
| `bl_entitymap_llms_path` | filter | Override the static `llms.txt` file path. |
| `bl_em_entity_types` | filter | Extend the recognised entity types (built-ins + Settings additions are passed in). |
| `bl_em_predicates` | filter | Extend the recognised relation predicates (built-ins + Settings additions are passed in). |
