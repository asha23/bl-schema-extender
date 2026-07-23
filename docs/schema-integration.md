# Schema.org integration

> **Currently hidden.** The whole schema-mapping feature is disabled and hidden
> from the UI via the master kill-switch `BL_EntityMap_Schema::FEATURE_ENABLED`
> (set to `false`). The code below is retained and unchanged; flip the constant
> to `true` to bring back the settings, help, integrity checks, and runtime
> injection (behaviour then falls back to the per-option toggles). The rest of
> this document describes how it works when enabled.

The EntityMap data can be layered into Yoast's Schema.org output through the
`wpseo_schema_*` filter pipeline (`BL_EntityMap_Schema`). **Yoast SEO must be
active** for any of it to do anything, and it is behind a master toggle that is
off by default.

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
