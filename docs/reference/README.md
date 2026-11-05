# docs/reference — brand and UI references

Expected files (drop them here; they are referenced by CLAUDE.md and Prompt 1c):

| File | What it is | Status |
|---|---|---|
| `infernix-logo.jpg` | Infernix Evolv logo: orange phoenix (gradient #FF6A00 → #FFA000) with a data-point line through the wing, black "INFERNIX / EVOLV" wordmark, tagline "Intelligence for Better Business", on white | **MISSING** — was shared inline in chat, not as a file. `public/images/infernix-logo.svg` is a PLACEHOLDER wordmark until the real file is added and copied into `resources/images/`. |
| `ui-ref-1-picker-modal.png` | "Iron Core" Inventory picker modal over a "Creating Quote" stepper page: tabs (Available / Catalog / Part Numbers) + search + filter, Columns/Density/Export toolbar, dense grid with checkbox column, status chips, totals row, rows-per-page footer, Cancel / "Create & Select" primary orange button | MISSING (seen in chat) |
| `ui-ref-2-receiving-stepper.png` | Receiving Report page: breadcrumb (Receiving / Open Purchase Orders / Receiving Report #12345), filter bar (Vendor, Search, PO Type, Saved Filters), stepper header (1. Creating Tags · 2. Enter Inventory Details), two stacked grids with Columns/Density/Export, orange "Continue" + dark "Review Receiving Report" actions | MISSING (seen in chat) |
| `ui-ref-3-tag-preview.png` | "Tag #011" modal with prev/next pager and a document preview (label with barcodes), single orange "Continue" action | MISSING (seen in chat) |

Layout pattern to replicate (not the Iron Core brand): white left sidebar with module icons and an orange
active item, grey page background, breadcrumb + page title + status chip, dense sortable grids with a
Columns / Density / Export toolbar, modal pickers with tabs + search, stepper headers for multi-step flows.
