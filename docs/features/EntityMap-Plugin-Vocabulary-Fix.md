# Entity Maps plugin — register new vocabulary (clears the 26 import warnings)

**For:** the developer who maintains the "Entity Maps" WordPress plugin.
**Accompanying file:** `entitymap.json` — the enriched **33-entity** map (the additive "do-now" version, no front-end content changes required). This is the exact file to import and to test the acceptance criteria against. Ships alongside this doc.

**Why:** importing that `entitymap.json` (33 entities) returns **"Looks valid"** but with **26 warnings**. The file is fine — the warnings are only because 13 relation predicates and 1 entity `@type` aren't in the plugin's **recognised vocabulary** allowlist. Add them so enriched imports validate with **zero warnings** and the richer graph is first-class.

There is **no vocabulary editor in Settings**, so this is a small code change to the plugin's recognised-predicate / recognised-type lists (or the filter that populates them). Additive only — don't remove anything already recognised.

---

## 1. Add one entity type

| `@type` | Add to recognised entity types | Map to (schema.org) |
|---|---|---|
| `Person` | yes | `schema.org/Person` |

Used by `e_030` (Jamie Banks — author/E-E-A-T). All other types we use (`Organization`, `Concept`, `Platform`, `Service`, `SoftwareProduct`, `ProprietaryTerm`, `Metric`) are already recognised.

## 2. Add thirteen relation predicates

Register each of these as a recognised predicate. Definitions and a real example from our map are given so labels/inverses can be set correctly.

| Predicate | Meaning | Example (from → to) | Inverse (if paired) |
|---|---|---|---|
| `ALTERNATIVE_TO` | X is a competing / replacement offering for Y | Yext Replacement Service → Yext | — |
| `AUTHOR_OF` | a Person authored an entity / its content | Jamie Banks → Data Aggregators | (authored_by) |
| `AFFILIATED_WITH` | a Person is affiliated with an organisation | Jamie Banks → BrightLocal | — |
| `COVERED_BY` | a concept sits under a broader topic | AI-Powered Local Search → Local SEO | inverse of `COVERS` (already recognised) |
| `DESCRIBES` | research / a report describes a concept | Local Consumer Review Survey → AI-Powered Local Search | inverse of `DESCRIBED_BY` (already recognised) |
| `DISTRIBUTES_VIA` | a service distributes through a channel | Citation Builder → Data Aggregators | — |
| `ENQUIRES_ABOUT` | a conversion / booking route is an enquiry about an offering | API Enquiry Call → BrightLocal Anywhere | — |
| `EVOLVES_FROM` | product lineage — successor from predecessor | BrightLocal Brain → AI Insights | pairs with `EVOLVES_INTO` |
| `EVOLVES_INTO` | product lineage — predecessor to successor | AI Insights → BrightLocal Brain | pairs with `EVOLVES_FROM` |
| `EXPOSES` | a product exposes a sub-capability / interface | BrightLocal Anywhere → BrightLocal MCP Server | — |
| `FEEDS` | a data source feeds a system | Data Aggregators → AI-Powered Local Search | — |
| `PART_OF_FRAMEWORK` | entity belongs to a named framework / stage (distinct from product hierarchy `PART_OF`) | Active Sync → Monitor–Know–Act approach | — |
| `TRIAL_OF` | a free trial of a product | Free Trial → BrightLocal Platform | — |

> Note: `COVERED_BY` and `DESCRIBES` are just the inverse directions of predicates you already recognise (`COVERS`, `DESCRIBED_BY`). If the plugin supports inverse registration, register them as inverses; otherwise add them as standalone recognised predicates.

## 3. Make sure they pass through to output

- Confirm the generator emits these predicates and the `Person` entity into `entitymap.json` / `entitymap.html` unchanged (they already serialise — this is only about the validator's allowlist).
- If the "Inject EntityMap data into Yoast Schema.org output" option is later enabled, map `Person`/`AUTHOR_OF` to schema.org `author`, and treat the others as descriptive relations. (Optional — not required to clear the warnings.)

## 4. Acceptance criteria

1. Import tab → **Verify only** on the same `entitymap.json` → **"Looks valid"** with **0 warnings**.
2. **Verify & import** writes successfully; Manage Entities shows **33 entities**.
3. Validator shows **"All checks passed"**; data integrity: entity IDs unique, chunk IDs unique, **all relation targets resolve**.
4. `entitymap.html` and `entitymap.json` regenerate and include the new entities/relations; the `Person` entity renders.
5. No regression: the original 20 entities and their existing predicates are unchanged.

## Fallback (only if you'd rather not extend the vocabulary)

Map our predicates onto the plugin's existing recognised set where an equivalent exists (`COVERED_BY`→`COVERS` reversed, `DESCRIBES`→`DESCRIBED_BY` reversed), and re-point the genuinely-new ones (`FEEDS`, `EXPOSES`, `EVOLVES_*`, `DISTRIBUTES_VIA`, `ALTERNATIVE_TO`, `ENQUIRES_ABOUT`, `TRIAL_OF`, `PART_OF_FRAMEWORK`, `AUTHOR_OF`, `AFFILIATED_WITH`) to the nearest existing predicate. This clears warnings but **loses expressiveness** and flattens the graph — registering the new vocabulary (sections 1–2) is preferred.
