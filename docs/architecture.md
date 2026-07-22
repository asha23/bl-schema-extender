# Architecture

## Boot sequence

Everything lives in [`bl-ai-tools.php`](../bl-ai-tools.php):

1. Guards `ABSPATH`, then defines `BL_AI_VERSION`, `BL_AI_FILE`, `BL_AI_DIR`.
2. `require_once`s the seven class files in `includes/`.
3. `bl_ai_boot()` runs on `plugins_loaded` and instantiates the five active
   classes:

   ```php
   new BL_EntityMap_CPT();       // CPT + meta boxes
   new BL_EntityMap_Generator(); // rewrites, endpoints, static-file writer
   new BL_EntityMap_Schema();    // Yoast @graph filters (only attach if enabled)
   new BL_EntityMap_Admin();     // Settings / Tools / Help pages
   new BL_Product_Review_Schema(); // legacy Product+reviews feature
   ```

   `BL_EntityMap_Store` and `BL_EntityMap_Importer` are **static utility
   classes** — never instantiated in the boot path; they're called statically by
   the others.

4. **Activation** (`bl_ai_activate`, `register_activation_hook`): registers the
   CPT, adds the rewrite rules, then `flush_rewrite_rules()` **once** so
   `/entitymap.json` and `/entitymap.html` resolve.
5. **Deactivation** (`bl_ai_deactivate`): `flush_rewrite_rules()` to clean up.

## Class responsibilities

| Class | File | Instantiated? | Role |
|-------|------|:-------------:|------|
| `BL_EntityMap_Store` | `class-bl-entitymap-store.php` | static | The single point that reads entities out of the DB and normalises them to the `entitymap.json` shape. Owns the controlled vocabularies, URL normalisation, caching, and `next_entity_id()`. |
| `BL_EntityMap_CPT` | `class-bl-entitymap-cpt.php` | ✓ | Registers the `bl_entity` CPT, renders the three meta boxes (Details / Evidence Chunks / Relations), sanitises + saves meta, admin columns & ordering, and the vanilla-JS repeater. |
| `BL_EntityMap_Generator` | `class-bl-entitymap-generator.php` | ✓ | Assembles the document, renders both JSON and HTML, registers rewrites + query vars, serves the dynamic endpoints, and writes both static files. |
| `BL_EntityMap_Schema` | `class-bl-entitymap-schema.php` | ✓ | Hooks Yoast's `wpseo_schema_*` filters to enrich the Organization node and inject per-page nodes. Only attaches its filters when enabled (see the toggle logic below). |
| `BL_EntityMap_Importer` | `class-bl-entitymap-importer.php` | static | Imports/upserts entities from a decoded `entitymap.json`, and validates a document as a dry run. |
| `BL_EntityMap_Admin` | `class-bl-entitymap-admin.php` | ✓ | The Settings / Tools / Help admin pages, upload verification, and the live DB-integrity validator. |
| `BL_Product_Review_Schema` | `class-bl-product-review-schema.php` | ✓ | Legacy, standalone: converts the Yoast WebPage piece to a Product and injects reviews on ACF-flagged pages. Not part of the EntityMap flow. |

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
