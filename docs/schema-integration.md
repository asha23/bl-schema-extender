# Schema.org integration

Two independent features write Schema.org markup, both through Yoast's
`wpseo_schema_*` filter pipeline. **Yoast SEO must be active** for either to do
anything.

1. **EntityMap → Yoast** (`BL_EntityMap_Schema`) — driven by the DB entities.
   Behind a master toggle, off by default.
2. **Product + reviews** (`BL_Product_Review_Schema`) — legacy, standalone,
   ACF-driven, unrelated to the EntityMap.

---

## EntityMap → Yoast (`BL_EntityMap_Schema`)

### The toggle chain

The class returns immediately in `is_admin()`, then checks options in order —
its filters only attach when the switches are on:

| Option | Default | Effect |
|--------|:-------:|--------|
| `bl_em_enable_schema` | `0` (**off**) | Master switch. If off, **no** Yoast filters attach at all. |
| `bl_em_enable_org` | `1` | When the master is on, attach `wpseo_schema_organization` enrichment. |
| `bl_em_enable_perpage` | `1` | When the master is on, attach `wpseo_schema_graph` per-page injection. |

Because the master defaults off, a fresh install publishes the `entitymap.json` /
`.html` files but touches **nothing** in the on-page Schema.org markup until an
admin opts in under *Settings → Add EntityMap data to Yoast schema*.

`@id` fragments are built as `<base_url>/entitymap.json#<entityId>`, where
`base_url` is the `bl_em_base_url` option or `home_url()`.

### Organization enrichment — `enrich_organization()`

Hooks `wpseo_schema_organization` (priority 11). Fires only when Yoast's site
representation is "company" (Yoast doesn't generate the Organization node
otherwise). Adds, to the sitewide Organization node:

- **`sameAs`** — merged from the Organization entity's `sameAs` (de-duplicated
  against any existing values).
- **`knowsAbout`** — a `DefinedTerm` for every `Concept` / `ProprietaryTerm`
  entity (name, `@id`, description, sameAs where present).
- **`makesOffer`** — an `Offer` wrapping each entity the Organization `OFFERS`
  (via a relation), typed with `offer_schema_type()`
  (`SoftwareApplication` or `Service`).

### Per-page nodes — `inject_page_nodes()`

Hooks `wpseo_schema_graph` (priority 11). Resolves the current page's canonical
URL (`current_url()`: front page, post permalink, or term link), finds entities
whose `_bl_page_url` path matches (host-agnostic), and appends one node per entity
to the `@graph`:

| Entity `@type` | Emitted node |
|----------------|--------------|
| `Organization` | **skipped** — already represented by Yoast's sitewide Organization node |
| `Concept`, `ProprietaryTerm` | `DefinedTerm` |
| `Platform`, `Service`, `SoftwareProduct` | `SoftwareApplication` or `Service` (via `offer_schema_type()`) |
| anything else | `Thing` |

Each node carries `@id`, `name`, and — when present — `description`, `sameAs`,
`alternateName`.

### Prerequisite (surfaced in Tools)

The Organization node only renders when Yoast SEO is active **and** *Yoast →
Settings → Site representation* is set to an **Organization/company** with a name
and logo. The Tools validator warns when this isn't the case, since the
enrichment would otherwise be inert.

---

## Product + reviews (`BL_Product_Review_Schema`)

A legacy feature (predates the EntityMap; relocated into `includes/` at 2.0.0),
**unchanged in behaviour** and unrelated to the EntityMap data. It depends on
**ACF**.

On the front end (`is_admin()` guarded) it hooks `wp` → `create_schema_constructor()`:

- Reads the ACF field `activate_product_schema` on the current post. Only runs
  when it equals `'on'`.
- If the ACF options field `debug_product_schema` is set, enables Yoast's
  development mode (`yoast_seo_development_mode`).
- When active, it attaches three Yoast filters:
  - `wpseo_schema_webpage` → `change_schema_to_product()`: switches the piece's
    `@type` from `WebPage` to `Product`.
  - `wpseo_schema_graph_pieces` → `remove_breadcrumbs_from_schema()`: strips the
    Breadcrumb piece.
  - `wpseo_schema_webpage` → `change_schema_properties()`: builds the Product
    body.

`change_schema_properties()` sets `sku` (slugified title), `mpn` (title),
`brand` (BrightLocal), and an `aggregateRating` from the ACF fields
`aggregate_rating` / `best_rating` / `total_reviews` (with defaults 0 / 5 / 5).
It unsets `breadcrumb`, `potentialAction`, `datePublished`, `dateModified`,
`inLanguage`, `isPartOf`. Reviews are read from the ACF flexible-content field
`sections_content` — for each visible `testimonials` layout it walks the
`testimonial` rows and emits a `Review` (with `reviewRating`, `author`,
`publisher`) for every row that has a score.

> This feature has its own ACF field contract and does not read or write any
> `bl_entity` data. Treat it as a separate concern from the EntityMap.
