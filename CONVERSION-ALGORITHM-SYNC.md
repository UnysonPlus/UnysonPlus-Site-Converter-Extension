# ⚠️ Keep the "no‑AI" conversion algorithm in sync — BOTH implementations

The deterministic (**no‑AI**) converter — the logic that turns a source design into a UnysonPlus
**child theme + page‑builder pages** *without* calling an LLM — exists **TWICE**, once per input path.
**If you change one, you MUST change the other** so the two paths produce consistent results.

> **Tier-3 structural mirror (PHP-only, parity pending).** The `code` builder fallback now tries a
> nested-flexbox structural mirror of an un-decomposable subtree (`n_structural_mirror`/`mirror_el` in
> `class-fw-site-converter-mapper.php`) before a flat `code_block` — an editable clone (containers →
> flexbox from `data-sc-cs`, leaves → media_image/text_block). The JS `to-pages.mjs` has NO twin yet;
> add one for capture-path parity.


> **✅ Container Width — pin the SECTION container, not the inner text cap (fixed).** `section_content_max_width`
> now scans the full content-band range INCLUDING the site container (up to 1600px) and returns the section's
> WIDEST centered content cap = its real outer container (e.g. modfii's consistent **1400px**), instead of
> skipping caps >1300 and grabbing the widest INNER TEXT measure (a `max-w-3xl`=768 / `max-w-5xl`=1024). Before
> the fix every section pinned a DIFFERENT narrow width (768/896/1024/…) → a jumbled, inconsistent layout; now
> every section pins the source's one consistent container. A non-standard width (1400) becomes a shared
> "Content 1400" preset (`build_container_width_presets`), and `Mapper::container_width_px()` now resolves a
> `content-NNNN` preset → its px so the flexbox-band content_width push caps at the same container. **⚠️ JS
> divergence:** `to-pages.mjs` `containerWidthPreset` still returns null (inherit) for a container >1100px and
> the JS `to-theme-settings.mjs` has NO container-width-preset builder — so the JS/URL path can't pin a
> non-standard site container yet (only `containerWidthPx` learned `content-NNNN`, for the push). Close by
> adding a JS container-width-preset builder + pinning, mirroring the PHP.
>
> **✅ Container Width now at PARITY.** `to-pages.mjs` sets a section's `container_width` from the
> source's content cap (`max-w-*` / computed max-width → narrow/medium/wide preset, inherit for the wide
> range) and pushes that cap onto a direct flexbox child's `content_width` — the JS twin of the PHP
> `n_section` container_width + flexbox content_width push. Verified on a capture. Keep the two in sync.


> ## ✅ Flexbox ("Div") emission — single-level row overlay is now at PARITY
>
> The page builder defaults to the **flexbox "Div"** container. Both paths now emit it for a clean row:
> - **PHP (file-upload) path** — `class-fw-site-converter-mapper.php` rewrites a **single level of clean
>   rows** into a `flexbox` Div (`n_flexbox()` + `column_to_flexbox_cell()`, gated by `row_flex_safe()` —
>   ≥2 cells, no `inner_class`, no `element_position`).
> - **JS (URL/capture) path** — `to-pages.mjs` now has the **byte-faithful twin**
>   (`nFlexbox` / `flexWidthPreset` / `slugToSpan` / `rowFlexSafe` / `columnToFlexboxCell`), and
>   `atom-templates.json` carries the `flexbox` atom. A clean row emits one flexbox row of flexbox cells,
>   not loose columns. Verified on shared captures (pinky_bites, my_website) — flexbox nodes now appear
>   where the JS path previously emitted zero.
>
> **Keep them in lockstep**: any change to the PHP `n_flexbox` / `row_flex_safe` / `column_to_flexbox_cell`
> must be mirrored in the `to-pages.mjs` twins (and the `flexbox` atom's default atts), and vice-versa.
>
> **Nesting is now RECURSIVE (both paths).** `column_to_flexbox_cell` / `columnToFlexboxCell` run their
> cell's `_items` through `flexify_items()` / `flexifyItems()` — a mutually-recursive pass that flexes
> every run of ≥2 clean `column` siblings at ANY depth. A nested grid becomes a nested flexbox, not a
> nested `fw-row`. Verified: a capture with nested grids reaches flexbox nesting depth 4 and its nested
> `column` count drops to near-zero. Keep the `flexify_items` twins in lockstep.
>
> **✅ SECTION-LEVEL rows now flex (both paths).** A section that is ITSELF the row (its direct children
> are ≥2 clean columns — a hero's text+image, a 2-up/3-up feature band) previously stayed
> `section → [column, column]` = the classic bootstrap `fw-row`/`fw-col`. Both paths now run the
> section's OWN items through the same `flexify_items()` / `flexifyItems()` pass (PHP mapper right before
> `n_section()`; `to-pages.mjs` right before `s._items =`), so section-as-row bands emit a flexbox Div
> (`fw-flex`) too. A run that doesn't qualify falls back to columns; a single column is untouched (so the
> full-bleed hero shape is preserved). Verified: shared captures dropped to **0** sections with ≥2 direct
> bootstrap columns. Keep the two section-level flexify calls in lockstep.
>
> **✅ Boxed-card / floating-card / max-width rows now FLEX via a TWO-NODE cell (both paths).** `row_flex_safe`
> no longer vetoes a run because a cell has `inner_class` (box skin / `max-width;margin:auto` cap) or
> `element_position` (floating-card ancestor). `column_to_flexbox_cell` / `columnToFlexboxCell` now emit a
> **two-node** cell for any inner-wrapper column: an OUTER flex track (the width span, `display:block`, plus
> column-level `selector{}` CSS like gutter padding) wrapping an INNER Div that carries the box + `max-width`
> + content-layout. This keeps `max-width;margin:auto` on a BLOCK child (classic left-align) instead of a flex
> item (where `margin:auto` eats free space). `element_position` is carried onto the cell. Helpers:
> `split_col_css` / `splitColCss` (route `selector .CLS{}` → inner, bare `selector{}` → outer) and
> `content_layout_over` / `contentLayoutOver`. Verified (PHP reflection): hero `max-w` cell, floating cell,
> and a box-card cell all produce the faithful two-node tree. **This closed the PHP path's biggest fidelity
> gap** — heroes and card grids that PHP kept as bootstrap columns now flex, matching the JS path.
>
> **⚠️ Residual PHP↔JS difference to converge:** PHP builds the `max-width` cap as an `inner_class` scoped
> rule (so the two-node translation kicks in), whereas the **JS extractor routes a cell max-width to the
> cell's `custom_css`** (bare `selector{max-width}`) WITHOUT `inner_class`, so JS still single-nodes it (the
> cap lands on the flex cell, shrinking the track rather than the inner content). To fully converge, teach the
> JS extractor to emit `inner_class` for a cell max-width (then the shared two-node handles it identically).
>
> **⚠️ Still DIVERGED (both paths):** a **nested-grid cell** the extractor didn't split decomposes differently
> (PHP → nested `column`s, JS → `code_block`); and the **tier-3 structural mirror** is PHP-only. Faithful
> duplication of those shapes remains a KNOWN GAP.

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
