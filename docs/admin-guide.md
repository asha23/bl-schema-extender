# Admin guide

All admin UI lives under the **EntityMap** menu (the `bl_entity` CPT menu). Three
submenus are added by `BL_EntityMap_Admin`:

- **Settings** (`bl-em-settings`, `manage_options`)
- **Tools** (`bl-em-tools`, `manage_options`)
- **Help & Docs** (`bl-em-help`, `edit_posts`)

…alongside the CPT's own **All Entities** / **Add New** screens.

## Settings

Registered under the `bl_em_settings` option group. Two sections:

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

### Output toggles

| Option | Default | Controls |
|--------|:-------:|----------|
| `bl_em_enable_json` | `1` | Publish `/entitymap.json` + `/entitymap.html` (static write + dynamic endpoints). Off → both 404 and no static write. |
| `bl_em_enable_schema` | `0` | **Master** switch for injecting EntityMap data into Yoast Schema.org. Off by default. |
| `bl_em_enable_org` | `1` | (only when master on) Organization enrichment. |
| `bl_em_enable_perpage` | `1` | (only when master on) Per-page nodes. |

Booleans are sanitised to `'1'` / `'0'`. Saving Settings triggers
`maybe_regenerate_after_save()`, which flushes the cache and regenerates the
outputs immediately.

## Tools

`render_tools()` shows:

- **Publishing status** — the live `/entitymap.json` and `/entitymap.html` URLs,
  the static file path, and whether the last static write succeeded
  (`bl_em_static_ok`: *writable ✓* / *not writable, served dynamically* / *not
  yet generated*).
- **Regenerate now** — forces a rebuild + rewrite of both static files.
- **Import from entitymap.json** — imports the `entitymap.json` sitting in the
  webroot (`dirname(ABSPATH)/entitymap.json`) as a **full sync** (`$replace = true`).
- **Upload & verify a JSON file** — see below.
- **Validation** — the live DB-integrity report (see below).
- **Generated entitymap.json** — a read-only preview of the current output.

All tool actions are gated by `manage_options` + the `bl_em_tool` nonce.

### Import & validation workflows

Two distinct concepts:

**1. Dry-run validation of an uploaded file** —
`BL_EntityMap_Importer::validate_document()`. Never touches the DB. Checks:

- *Errors:* missing required fields (`entityId`, `@type`, `name`, `description`),
  duplicate `entityId`s, duplicate `chunkId`s, relations pointing at a missing
  entity.
- *Warnings:* unrecognised `@type`, invalid `sameAs` URL, empty chunk text,
  incomplete relations, unrecognised predicate.
- *Stats:* entity / chunk / relation counts.

The upload form offers **Verify only** (report, no write) and **Verify & import**
(imports **only if there are zero errors**; warnings are allowed). The report is
stashed in a per-user transient (`bl_em_verify_<user_id>`, 5 min) and rendered
once on the next page load. Uploads must be `.json` and pass the standard
`is_uploaded_file` / upload-error checks.

**2. The actual import** — `BL_EntityMap_Importer::import_array()`:

- Upserts each entity **by `entityId`** (idempotent — re-running updates rather
  than duplicating). `find_by_entity_id()` deliberately includes `trash`, so a
  previously-removed entity is restored in place instead of re-created as a
  duplicate.
- Also writes the root/publisher settings from the document.
- Maps chunks and relations back into the flat meta shape (including
  `context.condition` → `condition`), and picks a `_bl_page_url` via
  `primary_url()`: the first chunk whose `contentType` is `definition`, else the
  first chunk with a `sourceUrl`.
- **Full sync (`$replace = true`):** any existing entity whose `entityId` is
  **not** in the uploaded document is moved to **Trash** (recoverable). Both the
  webroot import and Verify & import run in this mode — so always upload the
  *whole* map, never a partial list.
- Flushes the cache at the end.

### Live DB validation — `BL_EntityMap_Admin::validate()`

Runs against the current published entities (forced, uncached) and reports:
counts; duplicate entity IDs / chunk IDs (errors); relations to missing entities
(error); entities missing a description (warning); the Organization-entity count
(warns on 0 or >1); and the Yoast site-representation prerequisite (warns if not
set to a company).

## Help & Docs

`render_help()` is an in-admin, end-user guide (glossary, everyday tasks, the
bulk-upload caveat, settings overview, and a paste-in DevTools console snippet
that tabulates a page's JSON-LD `@graph` and prints `knowsAbout` / `makesOffer`
counts). It restates that Schema.org output is off by default and requires Yoast
site representation to be a company. This is the audience-facing counterpart to
this `docs/` folder (which targets developers).
