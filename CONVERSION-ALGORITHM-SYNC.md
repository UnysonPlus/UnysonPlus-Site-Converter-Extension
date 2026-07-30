# ⚠️ Keep the "no‑AI" conversion algorithm in sync — BOTH implementations

The deterministic (**no‑AI**) converter — the logic that turns a source design into a UnysonPlus
**child theme + page‑builder pages** *without* calling an LLM — exists **TWICE**, once per input path.
**If you change one, you MUST change the other** so the two paths produce consistent results.

> **📘 Manual procedure + hard-won learnings — read before a demo OR a launch-site conversion.**
> The human-followed process these converters AUTOMATE, and every gotcha from building demos
> (Theme-Settings-first mapping, Text Styles, the 3-tier Custom CSS escape hatch, logo strips /
> inline SVG, `mask-image` background fades, the fidelity + computed-style diff gate, …) lives in the
> **Site Conversion Playbook**: `framework/extensions/site-converter/docs/site-conversion-playbook.md`
> (in the plugin / `UnysonPlus-Site-Converter-Extension` repo). The converters' north star is to EMIT
> what that playbook builds by hand — keep them converging on it.

| Path | Lives in | Files |
|---|---|---|
| **File upload** (this extension) | `framework/extensions/site-converter/includes/` (PHP) | `class-fw-site-converter-stitch.php`, `class-fw-site-converter-mapper.php`, `class-fw-site-converter-theme-generator.php` |
| **URL capture** (capture service repo) | `UnysonPlus-Capture-Service/tools/design-capture/` (JS) | `capture-extract.mjs`, `to-pages.mjs`, `to-design-config.mjs` |

> The **AI** path (`to-ai.mjs`, `/ai-convert`) is separate — this rule is about the **deterministic**
> path that runs offline. (When the AI authors the child theme, the deterministic path is the fallback.)

### Kept in sync — 2026-07-31 · Hero / content-column decomposition
- **A rich content column is not a card** — a cell with an `<h1>` no longer collapses into one `icon_box`
  (the false-positive where a hero overline's sparkle read as a card icon). JS `cardOf` adds the `<h1>`
  guard; PHP `card_from_cell` already only matches `h2`–`h6`, so heroes were never cards there.
- **Recursive content-cell + image-cell decomposition** — a heading-bearing content column is decomposed
  into its child shortcodes (`cell.blocks`), and an image-dominant cell (an `<img>`, no heading/paragraph)
  → `media_image` instead of a verbatim `code_block`. JS `rowCols` + `to-pages` (`c.blocks` / `c.image`);
  PHP `grid_cols` (`role:'image'` → the Mapper's `cell_by_role('image')`).
- **Heroes decompose only when CLEAN** (JS-only gate — the PHP path always decomposes): the auto-build
  decomposes an `<h1>` section only if every block maps to a real shortcode; a design-dense hero with an
  un-mappable overline pill / stat row stays **verbatim** (fidelity preserved). On pinky-bites: 4→3
  fallbacks (an image cell now maps; the hero correctly stays verbatim).

### Kept in sync — 2026-07-31 · Flex-row cells → native column options
- **Column layout from the source flex** on BOTH paths: a grid cell that is itself a flex-**row** container
  is replayed via the column's NATIVE options — `content_direction:row` + `content_gap` (nearest Gap-Scale
  slug: 4px→1, 8px→2, 16px→3, 24px→4, 48px→5) + `content_order:reverse` for `row-reverse` — instead of a CSS
  wrapper. JS: `capture-extract` records `cell.flex`, `to-pages` applies it (`gapSlug`) and the old
  `.btn-row` button wrapper is replaced by `content_direction:row`. PHP: `grid_cols` reads the cell's flex
  from `data-sc-cs` (already carries `flex-direction`/`gap`), the Mapper applies it (`gap_slug`).
  `content_h`/`content_v` are intentionally left to the existing heuristics (direction-dependent semantics).

### Kept in sync — 2026-07-31 · Testimonials + fewer code_block fallbacks
- **Structural testimonials detection** on BOTH paths (no `testimonial`/`review` class needed): a flex/grid
  whose ≥2 sibling cards read like a quote (quote marks / star rating / "— Name") → the `testimonials`
  shortcode. JS `testimonialsOf()` structural fallback + PHP `is_testimonials_grid()`/`testimonials_items()`
  recognizer (priority 92, above `card_grid`). JS also stops `preferVerbatim` from swallowing a media-bearing
  testimonials section (`hasRow` now counts `testimonials`); the PHP path already always decomposes.
- **Fewer code_block fallbacks** on BOTH paths: an unrecognized grid cell with **plain text** → an editable
  `text_block` (JS to-pages cell loop `else`; PHP `grid_cols` `role:'text'`); a truly **empty/decorative**
  cell is **dropped** (no column). On pinky-bites this took the report from **13 → 4** fallbacks — the
  remaining 4 are correct verbatim residuals (media-bearing hero; the bespoke interactive cupcake builder).

### Kept in sync — 2026-07-31 · Tailwind translation + Button Presets
- **Tailwind → design tokens** on BOTH paths: JS `capture-extract.mjs` parses class names into `styles.tw`
  token intent (`shadow`/`radius`/`border`/`padding`/`font` scale) + a site-level `tailwind` flag; PHP
  `class-fw-site-converter-tailwind.php` gained **arbitrary `[…]` values, the full default colour palette
  (`pink-200`…), and `shadow-xl/2xl`** so `compile_class_set()` resolves what the browser resolves.
- **Button Presets from the source's real skin** on BOTH paths: JS `buildButtonPresets()` in
  `to-theme-settings.mjs` and PHP `FW_Site_Converter_Stitch::build_button_presets()` emit identical
  `button_colors` (Primary filled + Secondary bordered, per-state colour/border/`box_shadow`) +
  `button_sizes` (`lg`), and repoint the header CTA to `btn-lg`. Verified: both produce Primary
  `#ff6b8b`/white/no-border/`shadow-lg`, Secondary white/`#be185d`/`2px`/`shadow-md`, `lg` `9999`/`32×16`/`18·28`.

## What "conversion logic" means (any of these changed → sync the other side)

- What counts as a **page section** vs. chrome.
- How sections map to **shortcodes** (roles: `title` / `text` / `button` / `columns` / `icon_box` / `image` / `code` / `skip`).
- What's treated as **chrome** (header / footer / nav) vs. body **content**.
- **Header / footer detection** — e.g. a bare sticky `<nav class="fixed top-0">` as the header when
  there's no `<header>`; excluding the standalone brand link and the CTA from the nav menu.
- **Token / design extraction** — palette, fonts, spacing → the design‑config / child‑theme CSS.
- **Child‑theme file generation** — `style.css`, `header.php`, `footer.php` (this extension's theme
  generator owns these; the capture path feeds it the same design‑config shape).

## Reference

The **capture service's extraction is usually the more‑complete** one (it has a live DOM + computed
styles, not just static HTML). When in doubt, make the PHP here match the JS behavior there.

## Checklist before finishing any conversion change

- [ ] PHP file‑path engine updated (`class-fw-site-converter-*`).
- [ ] JS URL‑path engine updated (`capture-extract.mjs` / `to-pages.mjs` / `to-design-config.mjs`).
- [ ] Both make the same chrome / section / shortcode decisions on a shared sample.
- [ ] Versions bumped (plugin + extension manifests; capture‑service `package.json`).

_(This rule is also in the workspace `CLAUDE.md`, this extension's `AGENTS.md`, and the capture
service's copy of this file.)_
