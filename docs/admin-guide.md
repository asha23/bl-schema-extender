# Admin guide

Everything lives under the top-level **BL AI Tools** menu (owned by
`BL_AI_Tools_Registry`, slug `bl-ai-tools`, capability `edit_posts`):

```
BL AI Tools
├── Dashboard        Cards for each registered tool (the registry's landing page)
├── Entity Maps      The tool's tabbed hub — Files · Import · Settings · Help
├── All Entities     bl_entity CPT list (nested here via show_in_menu)
└── Add New Entity   bl_entity CPT editor
```

The **Entity Maps** hub is a single page (`bl-em-entity-maps`, `manage_options`)
rendered by `BL_EntityMap_Admin::render_hub()`, which switches on `?tab=` between
four tabs. The CPT screens (All Entities / Add New) are standard WordPress CPT
screens, nested under the menu — that's where entities are actually edited.

## Files tab (default)

The file-management hub for the two published files in the webroot
(`corpblob-roots/public/`):

- **Per-file cards** for `entitymap.json` and `entitymap.html`: live URL,
  last-built time (site timezone + relative), size, and **View** / **Download**
  buttons. Downloads stream through a nonce + `manage_options`-gated
  `handle_download()` action (`bl_em_dl=json|html|backup`).
- **Static-write status** (`bl_em_static_ok`): *writable ✓ (served directly)* /
  *not writable (served dynamically)* / *not yet generated*.
- **Regenerate now** — rebuilds both files from the current entities.
- **Backups & restore** — see below.
- **Preview** — read-only textarea of the current `entitymap.json`.

### Backups & restore (undo)

`BL_EntityMap_Backups` keeps timestamped snapshots of `entitymap.json` in a
private, listing-guarded directory: `uploads/bl-ai-tools/entitymap-backups/`
(named `entitymap-YYYYMMDD-HHMMSS.json`, with an optional `.meta` reason sidecar).

- A snapshot is taken **automatically before every destructive change** — a
  webroot import, an uploaded "verify & import", and before a restore. It is
  **not** taken on ordinary regenerates/saves, so backups stay meaningful.
- The Files tab lists snapshots (newest first) with **Restore**, **Download**,
  and **Delete**.
- **Restore** re-imports that snapshot into the database (full sync) and
  regenerates the files — a true undo of the whole map, not just the file on
  disk. The current state is itself snapshotted first, so a restore is reversible.
- Retention is `bl_em_backup_keep` (default **10**); older snapshots are pruned.

This is what preserves an uploaded map: uploading/importing a new one archives the
previous published `entitymap.json` first, so you can always revert.

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
- Writes the root/publisher settings from the document; maps chunks + relations
  back into the flat meta shape (`context.condition` → `condition`); picks
  `_bl_page_url` via `primary_url()` (first `definition` chunk URL, else first
  chunk URL).
- **Full sync (`$replace = true`):** any existing entity **not** in the document
  is moved to **Trash**. Both import paths run in this mode — always upload the
  *whole* map. (A snapshot is archived first, so this is undoable.)

### Dry-run validation — `BL_EntityMap_Importer::validate_document()`

Never touches the DB. *Errors:* missing required fields (`entityId`, `@type`,
`name`, `description`), duplicate `entityId`/`chunkId`, relation to a missing
entity. *Warnings:* unrecognised `@type`/predicate, invalid `sameAs` URL, empty
chunk text, incomplete relation. Plus entity/chunk/relation counts.

### Live DB validation — `BL_EntityMap_Admin::validate()`

Runs against the current published entities (forced, uncached): counts; duplicate
entity/chunk IDs (errors); relations to missing entities (error); entities missing
a description (warning); Organization-entity count (warns on 0 or >1); and the
Yoast site-representation prerequisite (warns if not a company).

## Settings tab

Registered under the `bl_em_settings` option group.

### Publisher & root

| Option | Default | Type | Feeds |
|--------|---------|------|-------|
| `bl_em_publisher_name` | `BrightLocal` | text | document `publisher.name`, chunk `publisher` |
| `bl_em_publisher_url` | `home_url('/')` | url | document `publisher.url` |
| `bl_em_publisher_sameas` | `''` | url | document `publisher.sameAs` (omitted if blank) |
| `bl_em_base_url` | `''` | url | schema `@id` base; blank = use this site automatically |
| `bl_em_version` | `1.0` | text | document `version` |
| `bl_em_schema_url` | `https://entitymap.org/spec/v1.0` | url | document `schema` |
| `bl_em_verification` | `self-declared` | text | document `verificationStatus` |
| `bl_em_profile` | `core` | text | document `profile` |

### Output

| Option | Default | Controls |
|--------|:-------:|----------|
| `bl_em_enable_json` | `1` | Publish `/entitymap.json` + `/entitymap.html` (static write + dynamic endpoints). Off → both 404, no static write. |
| `bl_em_backup_keep` | `10` | Number of `entitymap.json` snapshots to retain (`int`, absint-sanitised). |
| `bl_em_enable_schema` | `0` | **Master** switch for injecting EntityMap data into Yoast Schema.org. Off by default. |
| `bl_em_enable_org` | `1` | (only when master on) Organization enrichment. |
| `bl_em_enable_perpage` | `1` | (only when master on) Per-page nodes. |

Booleans are sanitised to `'1'`/`'0'`. Saving Settings triggers
`maybe_regenerate_after_save()` (detects `page=bl-em-entity-maps` +
`settings-updated`), which flushes the cache and regenerates immediately.

## Help tab

`BL_EntityMap_Admin::tab_help()` — the in-admin, end-user guide: what the tool
does (honest about the files being a curated catalogue and Yoast being the part
AI/Google read today), a glossary, everyday tasks, the whole-map-upload caveat,
and a paste-in DevTools snippet that tabulates a page's JSON-LD `@graph` and prints
`knowsAbout` / `makesOffer` counts. The end-user counterpart to this `docs/` folder.
