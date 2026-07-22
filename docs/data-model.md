# Data model

## The `bl_entity` custom post type

Each EntityMap entity is one `bl_entity` post
(`BL_EntityMap_CPT::CPT` / `BL_EntityMap_Store::CPT`). It is private
(`public => false`, `show_ui => true`), not in REST, has no archive and no
front-end rewrite. Its admin screens are nested under the shared **BL AI Tools**
menu (`show_in_menu => BL_AI_Tools_Registry::MENU_SLUG`). Supports `title`,
`editor`, `page-attributes`.

Mapping of the WordPress post to entity fields:

- **Post title** → entity `name`
- **Post content** → entity `description` (stripped of tags on read)
- Everything else lives in **post meta**, edited via three meta boxes.

The admin list defaults to **Entity ID order** (`_bl_entity_id`, ascending) rather
than by date, and the *Entity ID* / *Type* columns are added after the title
column. IDs are zero-padded (`e_001`), so a string sort is numerically correct.

### Meta keys

| Meta key | Source (meta box) | Notes |
|----------|-------------------|-------|
| `_bl_entity_id` | assigned on first save | Stable ID `e_NNN`. Read-only in the UI. Allocated by `BL_EntityMap_Store::next_entity_id()` (max existing + 1). |
| `_bl_type` | Entity Details → Type | One of the entity-type vocabulary. Defaults to `Concept`. |
| `_bl_alternate_name` | Entity Details | Optional `alternateName`. |
| `_bl_canonical_label` | Entity Details | Optional, for proprietary terms → `canonicalLabel`. |
| `_bl_same_as` | Entity Details → sameAs URL | Verified external identifier (Wikidata, etc.). |
| `_bl_maturity` | Entity Details → Maturity status | `''` / `established` / `emerging` / `deprecated`. |
| `_bl_page_url` | Entity Details → Attach to page URL | The page whose Schema.org gets this entity's per-page node. |
| `_bl_chunks` | Evidence Chunks (repeater) | Array of chunk rows (see below). |
| `_bl_relations` | Relations (repeater) | Array of typed edges (see below). |

**Chunk row** (`_bl_chunks[]`): `chunkId`, `text` (required — empty rows are
dropped), `sourceUrl`, `pageTitle`, `contentType`, `audienceType`.

**Relation row** (`_bl_relations[]`): `predicate` + `targetId` (both required),
`confidence`, `condition`. Empty/incomplete rows are dropped.

The meta boxes use a dependency-free vanilla-JS repeater (add/remove rows via a
`<script type="text/template">` clone), loaded only on the entity editor screen.
All fields are sanitised on save (`sanitize_text_field`, `sanitize_textarea_field`,
`esc_url_raw`) behind a nonce + capability + autosave guard.

## Controlled vocabularies

Defined as static arrays on `BL_EntityMap_Store` and surfaced as admin dropdowns:

| Vocabulary | Values |
|------------|--------|
| `entity_types()` | `Organization`, `Concept`, `Platform`, `Service`, `SoftwareProduct`, `ProprietaryTerm`, `Metric` |
| `definedterm_types()` | `Concept`, `ProprietaryTerm` — surface as schema.org `DefinedTerm` |
| `offer_types()` | `Platform`, `Service`, `SoftwareProduct` — become `Organization → makesOffer` |
| `predicates()` | `OFFERS`, `INCLUDES`, `PART_OF`, `COVERS`, `ENABLES`, `DEPENDS_ON`, `ACHIEVES`, `IMPROVES`, `MEASURES`, `PRODUCED_BY`, `DESCRIBED_BY`, `RELATED_TO` |
| `content_types()` | `definition`, `evidence`, `statistic`, `procedure` |
| `audience_types()` | `general`, `technical`, `executive` |
| `confidence_levels()` | `''` (stated), `stated`, `inferred` |
| `maturity_levels()` | `''` (none), `established`, `emerging`, `deprecated` |

`offer_schema_type($type)` maps an EntityMap type to the schema.org type used when
it's offered: `Platform`/`SoftwareProduct` → `SoftwareApplication`, everything
else → `Service`.

## The `entitymap.json` document

`BL_EntityMap_Generator::get_document()` assembles:

```jsonc
{
  "version": "1.0",                              // bl_em_version
  "schema": "https://entitymap.org/spec/v1.0",   // bl_em_schema_url
  "publisher": {
    "name": "BrightLocal",                        // bl_em_publisher_name
    "url": "https://www.brightlocal.com/",        // bl_em_publisher_url
    "sameAs": "https://www.wikidata.org/..."       // bl_em_publisher_sameas (omitted if blank)
  },
  "generated": "2026-07-22T12:00:00Z",            // gmdate at build time (UTC)
  "verificationStatus": "self-declared",          // bl_em_verification
  "profile": "core",                              // bl_em_profile
  "entities": [ /* normalised entities */ ]
}
```

Serialised with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

### Normalised entity shape

Built by `BL_EntityMap_Store::build_entity()`. Optional fields are omitted when
empty:

```jsonc
{
  "entityId": "e_003",              // _bl_entity_id, or "e_<postID>" fallback
  "@type": "Service",               // _bl_type, defaults to "Concept"
  "name": "…",                      // post title
  "description": "…",               // post content, tags stripped
  "alternateName": "…",             // if set
  "canonicalLabel": "…",            // if set
  "hasChunks": [
    {
      "chunkId": "c_<postID>_<n>",   // stored id, or generated
      "text": "…",
      "sourceUrl": "https://…",       // if set
      "pageTitle": "…",              // if set
      "publisher": "BrightLocal",     // always, from bl_em_publisher_name
      "retrieved": "…",              // if set
      "contentType": "definition",    // if set
      "audienceType": "general"       // if set
    }
  ],
  "relations": [
    {
      "predicate": "OFFERS",
      "targetId": "e_010",
      "targetName": "…",             // resolved from the target entity's name
      "confidence": "inferred",       // if set
      "context": { "condition": "…" } // from the relation's `condition`, if set
    }
  ],
  "sameAs": "https://…",             // if set
  "maturityStatus": "established"     // if set
}
```

Only **published** entities are included. `targetName` is resolved in a first
pass (id → name) so relations carry a human-readable label. Note the stored meta
uses a flat `condition` field, but it's emitted nested under `context.condition`;
the importer maps it back on the way in.

## URL matching (host-agnostic)

`BL_EntityMap_Store::normalise_url()` reduces any URL to a trailing-slashed
**path only**, discarding scheme and host. So an entity authored with a
production URL (`https://www.brightlocal.com/learn/x/`) still matches the same
page on staging or local (`https://site.test/learn/x/`). `get_entities_for_url()`
uses this to find the entities attached to the page currently being rendered.

`base_url()` (used for schema `@id` fragments) defaults to `home_url()` but can be
pinned to a canonical domain via the `bl_em_base_url` option.
