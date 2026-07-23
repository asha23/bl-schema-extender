## Entity Maps — help

Curate the entities BrightLocal is known for — products, services, key concepts, research, and people — in one place, and publish them in the formats AI systems and search crawlers read. You edit the list **once**, here in wp-admin; every output regenerates on its own. You never edit a published file by hand.

## What this tool publishes

From one curated list of entities it produces, and keeps in sync:

- **[entitymap.json]({{url:json}})** — the machine-readable catalogue; the source-of-truth export.
- **[entitymap.html]({{url:html}})** — a human-readable rendering of the same data, with a Schema.org `@graph` (Organization + a CollectionPage indexing every entity).
- **[llms.txt]({{url:llms}})** — an AI-facing site guide generated from the map (title, summary, machine-readable index, and the entities grouped by kind). *Enable it under Settings.*
- **XML sitemap** — a dedicated `entitymap-sitemap.xml`, listed in `sitemap_index.xml`, so crawlers discover the map. *Requires Yoast SEO.*
- A sitewide `<head>` link and a canonical/alternate link on the HTML page, so the map is signposted everywhere.

## Where things live (tabs)

- **Manage Entities** — the main editor: add, edit, and delete entities.
- **Files** — view / download the published files, see build status, regenerate, and restore backups.
- **Import** — verify and import a whole `entitymap.json`.
- **Settings** — publisher details, the canonical URL, output toggles, and custom vocabulary.
- **Help** — this page.

## Key words

- **Entity** — one "thing" we describe: a product, service, concept, research report, or person.
- **Type** — what kind of thing it is (Organization, Service, Platform, Concept, ProprietaryTerm, Metric, Person, …). You can add your own types under Settings.
- **Evidence chunk** — a short quote (1–5 sentences) from our site that backs up the entity, with a link to its source page.
- **Relation** — a typed link between two entities, e.g. BrightLocal `OFFERS` Citation Builder. You can add your own predicates under Settings.
- **sameAs** — a link to the same thing on an authoritative site (usually Wikidata). Only add one you've verified. This is the single most useful field for helping AI resolve exactly who or what an entity is — use the built-in **Find on Wikidata** search to add it.

## Everyday tasks

### Edit or add an entity

1. Open the **[Manage Entities]({{tab:manage}})** tab.
2. Click an entity in the left-hand list to edit it (search/filter to find one), or **＋ Add entity** to create one.
3. Set the **Name** and **Description**, the **Type**, an optional verified **sameAs** (use *Find on Wikidata*), and the page to **Attach to** (search your site by title).
4. Add **Evidence chunks** and **Relations** as needed.
5. Click **Save entity**. The files regenerate automatically, and a backup of the previous map is taken so you can undo.

### Upload a whole new map

1. Go to the **[Import]({{tab:import}})** tab.
2. **Verify only** checks a file and reports errors/warnings without changing anything; **Verify & import** loads it only if there are zero errors.
3. The current map is backed up first — undo any time from the Files tab.

### Restore a previous version

Every import and every save snapshots the map. On the **[Files]({{tab:files}})** tab, under **Backups & restore**, pick a snapshot and **Restore** — it re-imports that version and regenerates the files.

## Settings worth knowing

- **Canonical base URL** — set this to the production URL (`https://www.brightlocal.com`). All absolute URLs in the published files come from it, so a map regenerated on staging still emits production URLs. Leave blank to use the current site.
- **Publish EntityMap files** — master switch for serving `/entitymap.json` and `/entitymap.html`.
- **Generate llms.txt** — off by default; when on, writes `/llms.txt` from the map. It overwrites any existing `llms.txt`.
- **Vocabulary** — add custom entity types and relation predicates (one per line) without a code change. Built-ins are always kept.
- **Backups to keep** — how many snapshots to retain before pruning the oldest.

## Check it's working

- **Files:** open **[entitymap.json]({{url:json}})** and **[entitymap.html]({{url:html}})** — you should see all your entities.
- **llms.txt:** open **[llms.txt]({{url:llms}})** (once enabled) — it should list the machine-readable index and your entities.
- **Sitemap:** open **[entitymap-sitemap.xml]({{url:sitemap}})** (with Yoast active) — it should list `entitymap.html`, and appear in `sitemap_index.xml`.
- **JSON-LD:** paste the HTML page's structured data into [validator.schema.org](https://validator.schema.org/) or [Google's Rich Results Test](https://search.google.com/test/rich-results) to confirm the `@graph`.

## Latest changes

{{changelog:5}}
