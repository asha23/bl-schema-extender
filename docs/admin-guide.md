# Admin guide

Everything lives under the top-level **BL AI Tools** menu (owned by
`BL_AI_Tools_Registry`, slug `bl-ai-tools`, capability `edit_posts`, positioned
just below **Tools**, with a custom icon from `assets/icon.svg`):

```
BL AI Tools
├── Dashboard        Cards for each registered tool (the registry's landing page)
└── Entity Maps      The tool's tabbed hub — Manage Entities · Files · Import · Settings · Help
```

The `bl_entity` CPT is registered with `show_in_menu => false`, so **All Entities /
Add New are not in the menu**. The classic per-post editor still exists (reachable
by direct URL) as an internal fallback, but curation happens on **Manage Entities**.

The **Entity Maps** hub is a single page (`bl-em-entity-maps`, `manage_options`)
rendered by `BL_EntityMap_Admin::render_hub()`, which switches on `?tab=` between
five tabs. **Manage Entities is the default.**

## Manage Entities tab (default)

The primary editor — a dependency-free master–detail screen (`BL_EntityMap_Manager`,
assets in `assets/manage.{js,css}`):

- **Left:** searchable, type-filterable list of every entity, with type badges,
  `e_NNN`, a ⚠ marker for issues, and **＋ Add entity**.
- **Right:** the full editor — name, description, type, alternate/canonical name,
  **sameAs** (with a **Find on Wikidata** typeahead), **Attach to page** (a page
  search), plus the evidence-chunk and relation repeaters (relation targets are a
  live dropdown of the other entities).
- **Save/Delete** via AJAX (`bl_em_save_entity` / `bl_em_delete_entity`,
  nonce + `manage_options`); a saving overlay while in flight; a live validation
  banner. Each save/delete **snapshots a backup**, then regenerates the outputs.

All entities are stored as `bl_entity` posts through the shared
`BL_EntityMap_Store::save_entity_meta()` path, so this and the classic editor
sanitise/store identically.

## Files tab

The file-management hub for the published outputs in the webroot:

- **Per-file cards** for `entitymap.json`, `entitymap.html`, and — when *Generate
  llms.txt* is on — `llms.txt`: live URL, last-built time, size, **View** /
  **Download**. Downloads stream through a nonce + `manage_options`-gated
  `handle_download()` (`bl_em_dl=json|html|llms|backup`).
- **Static-write status** (`bl_em_static_ok`): *writable ✓ (served directly)* /
  *not writable (served dynamically)* / *not yet generated*.
- **llms.txt status** — a pointer to Settings when generation is off.
- **XML sitemap status** — "registered ✓ (with links)" when Yoast is active, or
  "requires Yoast SEO" when not.
- **Regenerate now** — rebuilds all outputs from the current entities.
- **Backups & restore** — see below.
- **Preview** — read-only textarea of the current `entitymap.json`.

### Backups & restore (undo)

`BL_EntityMap_Backups` keeps timestamped snapshots of `entitymap.json` in a
private, listing-guarded directory: `uploads/bl-ai-tools/entitymap-backups/`
(provisioned on activation; named `entitymap-YYYYMMDD-HHMMSS.json`, with an
optional `.meta` reason sidecar).

- Snapshots are built **from the database** (the live document via
  `BL_EntityMap_Generator::snapshot_json()`), **not** by copying the published
  file — so a restore point is always created even when the webroot isn't
  writable / no static file exists. (Only skipped when the map is empty.)
- Taken **automatically before every change** — each Manage Entities save/delete,
  a webroot import, an uploaded "verify & import", and before a restore — plus a
  **Create backup now** button on the Files tab for on-demand restore points.
- The Files tab lists snapshots (newest first) with **Restore**, **Download**,
  **Delete**.
- **Restore** re-imports that snapshot (full sync) and regenerates — a true undo of
  the whole map. The current state is snapshotted first, so a restore is reversible.
- Retention is `bl_em_backup_keep` (default **10**); older snapshots are pruned.

## Import tab

- **Upload & verify a JSON file** — **Verify only** (dry-run report, no write) and
  **Verify & import** (imports only if zero errors; warnings allowed). Report is
  stashed in a per-user transient (`bl_em_verify_<id>`, 5 min) and shown once.
- **Import from the webroot** — imports the `entitymap.json` in the site root as a
  full sync. Disabled if no file is present.
- **Data integrity** — the live DB validator (see below).

All tool actions are gated by `manage_options` + the `bl_em_tool` nonce.

### Import mechanics — `BL_EntityMap_Importer::import_array()`

- Upserts each entity **by `entityId`** (idempotent). `find_by_entity_id()`
  includes `trash`, so a previously-removed entity is restored in place rather
  than duplicated.
- Writes root/publisher settings from the document; maps chunks + relations back
  into the flat meta shape (`context.condition` → `condition`); picks
  `_bl_page_url` via `primary_url()`. Reconciles the monotonic id counter
  (`bl_em_entity_seq`) so later allocations never reuse an imported id.
- **Full sync (`$replace = true`):** any existing entity **not** in the document is
  moved to **Trash**. Both import paths run in this mode — always upload the
  *whole* map. (A snapshot is archived first, so this is undoable.)

### Dry-run validation — `BL_EntityMap_Importer::validate_document()`

Never touches the DB. *Errors:* missing required fields, duplicate
`entityId`/`chunkId`, relation to a missing entity. *Warnings:* unrecognised
`@type`/predicate, invalid `sameAs` URL, empty chunk text, incomplete relation.
(Recognised types/predicates include the built-ins **and** any added under
Settings → Vocabulary.)

### Live DB validation — `BL_EntityMap_Admin::validate()`

Structural checks against the current published entities: counts; duplicate
entity/chunk IDs (errors); relations to missing entities (error); entities missing
a description (warning). The Organization-count and Yoast site-representation
checks only run when the schema feature is enabled (see below) — they're hidden
while it is off.

## Settings tab

Registered under the `bl_em_settings` option group.

### Publisher & root

| Option | Default | Feeds |
|--------|---------|-------|
| `bl_em_publisher_name` | `BrightLocal` | document `publisher.name`, chunk `publisher`, llms.txt title |
| `bl_em_publisher_url` | canonical base + `/` | document `publisher.url` |
| `bl_em_publisher_sameas` | `''` | document `publisher.sameAs` / JSON-LD Organization `sameAs` (omitted if blank) |
| `bl_em_base_url` | `''` | **Canonical base URL** — the base for schema `@id` **and all absolute URLs in the published files** (JSON-LD, canonical/alternate links, llms.txt, sitemap). Blank = use this site. Set to production so files regenerated on staging still emit production URLs. |
| `bl_em_version` / `bl_em_schema_url` / `bl_em_verification` / `bl_em_profile` | `1.0` / spec URL / `self-declared` / `core` | document root fields |

### Output

| Option | Default | Controls |
|--------|:-------:|----------|
| `bl_em_enable_json` | `1` | Publish `/entitymap.json` + `/entitymap.html` (static write + dynamic endpoints). Also gates the sitemap. |
| `bl_em_enable_llms` | `0` | Generate `/llms.txt` from the EntityMap on every change (overwrites any existing file). |
| `bl_em_backup_keep` | `10` | Snapshots to retain before pruning. |

### Vocabulary

`bl_em_custom_types` / `bl_em_custom_predicates` (arrays; one-per-line textareas) —
add recognised entity types and relation predicates without code. Additive only;
built-ins are always kept and shown read-only. Also extendable via the
`bl_em_entity_types` / `bl_em_predicates` filters.

### Schema (currently hidden)

The Yoast Schema.org mapping (`bl_em_enable_schema` / `_org` / `_perpage`) is
hidden behind `BL_EntityMap_Schema::FEATURE_ENABLED` (`false`). The options remain
registered; the settings, help, and integrity checks reappear if it's flipped on.

Saving Settings triggers `maybe_regenerate_after_save()`, which flushes the cache
and regenerates immediately.

### Internal options (not user-facing)

`bl_em_static_ok`, `bl_em_llms_ok` (last write status), `bl_em_changed_gmt` (for
`Last-Modified`), `bl_em_entity_seq` (monotonic id counter).

## Help tab

`BL_EntityMap_Admin::tab_help()` renders **`docs/help.md`** through the small
`BL_AI_Markdown` renderer — the doc is the single source of truth, so the in-admin
help never drifts from the repo. Internal links use `{{tab:*}}` / `{{url:*}}`
tokens resolved at render time, and a **Latest changes** section is pulled live
from `docs/CHANGELOG.md`.
