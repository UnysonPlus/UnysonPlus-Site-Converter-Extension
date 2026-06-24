# Recipe — Google Stitch → UnysonPlus (the AI tier)

This is the **Tier 2** playbook: how Claude (or any agent) hand-authors a higher-fidelity convert
**bundle** from a Google Stitch screen, for when the deterministic no-AI converter
(`FW_Site_Converter_Stitch`, the "Convert a Google Stitch screen" card) isn't faithful enough — e.g. a
band is really a **testimonials** slider, an **accordion**, or a multi-row layout the heuristic parser
flattens.

The deterministic tier already produces a correct bundle; you only **refine `pages.json`** (the
section→shortcode mapping). Everything else — `media.json`, `theme-settings.json`, `menus.json` — the
no-AI builder gets right, so reuse them unchanged. Workflow:

1. In the WP admin: **Unyson+ → Convert → Manual tools → Convert a Google Stitch screen → Download draft
   bundle (.zip)**. Unzip it — you get `bundle.json`, `media.json`, `theme-settings.json`, `pages.json`,
   `menus.json`.
2. Read the Stitch inputs alongside it: `code.html` (the markup), `screen.png` (the look), `DESIGN.md`
   (the design system). 
3. Rewrite **`pages.json`** for fidelity (rules below). Leave the other four files as-is.
4. Re-zip and import via **Convert from a bundle (.zip)**.
5. Optional learning: the extension's `FW_Site_Converter_Stitch::distill_from_ai($draft, $yours)` diffs
   your refined `pages.json` against the draft and records the deltas as **local** rules, so the next
   no-AI run on this install improves. (Nothing is transmitted — see the privacy note in `AGENTS.md`.)

## The bundle shape (don't fight the importer)

`pages.json` = `{ "pages": [ { "title", "slug", "status":"publish", "front_page":bool, "builder":[ …sections… ] } ] }`.
`builder` is the **array** of section nodes (the `kind:"full"` tree). The importer never hand-writes the
encoded shortcode string — it sets the page-builder option from this tree. So your job is just to emit a
correct tree. Clone node shapes from a **real export** (or from what the deterministic draft already
emitted) and swap only content — every node must carry the FULL default att shape or the builder editor
chokes. The Mapper's node builders (`class-fw-site-converter-mapper.php`) are the canonical reference for
each node's shape.

## Stitch construct → UnysonPlus element

| In the Stitch `code.html` | Map to |
|---|---|
| inline `tailwind.config` colors / fontFamily / fontSize / spacing / borderRadius (or `DESIGN.md` frontmatter) | already in `theme-settings.json` (`misc_custom_css` `:root` vars + fonts) — leave it |
| `<section>` / `<!-- Hero Section -->` band, `<footer>` | one builder **section** node |
| `rounded-full … uppercase` pill/eyebrow span | `special_heading` **overline** |
| `<h1>`/`<h2>` (hero / section lead) | `special_heading` **title** (`heading` = `h1`/`h2`) |
| `<h3>`–`<h6>` standalone | `special_heading` (`heading` = that level) |
| `<p>` | `text_block` — content field id is **`text`** (not `content`) |
| `<button>` / `<a class="bg-primary … px-…">` CTA | `button` — `icon` is an **object** `{type:"none"}` (or a Font-Awesome icon-v2 value); a Material-Symbols glyph maps to FA |
| `<img src="https://lh3.googleusercontent…">` | `media_image` (or a `code_block` with the bare `<img>`); add the URL to `media.json` |
| a `grid`/`flex` of cards (each card = icon + `<h3>` + `<p>` [+ `<ul>`]) | a row of **columns**, one `icon_box` per card. Widths from `col-span-N` (12-grid) / `grid-cols-K`. `icon_box`: `custom_icon` (inline SVG) or `icon` (FA), `title`, `content` |
| `<ul><li>` check-list inside a card | put as clean `<ul><li>…</li></ul>` inside the `icon_box` `content` |
| a row of repeated quote/avatar/rating cards | `testimonials` (Tier-2 win — the heuristic parser would emit plain cards) |
| a list of question/answer rows | `accordion` (Tier-2 win) |
| header `<nav>` / footer `<nav>` | already in `menus.json` — leave it |

## Hard rules (these bite — from real conversions)

- **Per-shortcode att keys are exact.** `text_block` → `text`; `special_heading` → `title` / `overline` /
  `subtitle` / `heading`; `icon_box` → `custom_icon` / `title` / `content` / `style`; `button` → `label` /
  `link` / `icon`(object). Column **`width`** (`"1_3"`) is a **top-level** key on the column node, NOT an att.
- **`icon` is always an object**, never a string — `{"type":"none"}` when unused. A font icon is the
  `icon-v2` value; prefer **Font Awesome** classes (they're bundled + render). Material Symbols won't load
  on the WP site — convert the glyph (`bolt`→`fa fa-bolt`, `security`→`fa fa-shield`, `check_circle`→
  `fa fa-check-circle`, `arrow_forward`→`fa fa-arrow-right`, …).
- **Clean DOM** (UnysonPlus's selling point): **no `class=` on `<p>`/`<li>`** inside editor content. To
  attach a class, use the element's wrapper field, not the prose.
- **`misc_custom_css` is an object** `{ "custom_css": "…" }`, never a raw string (a string fatals the
  Theme Settings page). The draft already does this — don't "simplify" it to a string.
- **Carried CSS is admin-scoped** `body:not(.wp-admin)` (the asset optimizer loads `misc_custom_css` in
  wp-admin too). Keep the draft's scoping.
- **Column widths:** twelfths (`1_1` `1_2` `1_3` `1_4` `1_6` `2_3` `3_4` `5_12` `7_12` …) plus the one
  fifth `1_5`. Stitch `md:col-span-8` of a 12-grid → `2_3`; `col-span-4` → `1_3`; `grid-cols-3` → `1_3` each.

## Worked example — the `saas_landing_page` sample

Source: `test-sites/stitch_h612_saas_landing_page/saas_landing_page/code.html` (the "Obsidian Precision"
SaaS landing page; tokens: Manrope headings / Inter body, charcoal `#0b1326`, 1200px max, 128px
section-gap). The deterministic draft already maps it well — sections + roles:

```
#hero-section:                overline | title | text | button | button
#product-image-showcase:      image (dashboard render)
#value-proposition-bento-grid: title | text | columns[ icon_box(2_3, fa-bolt) , icon_box(1_3, fa-shield) ]
#final-cta:                   title | text | button | button
footer:                       → menus.json (Privacy / Terms / Security / Contact)
```

Tier-2 refinements you might make on this screen:
- The bento card 2 has a `<ul>` of "End-to-End Encryption / SOC2 Type II / Biometric Auth Ready" — keep it
  as a clean `<ul>` inside the `icon_box` `content` (the draft already does).
- The hero's two CTAs (`Start Building Now` + arrow, `View Demo` + play icon) → two `button` nodes with FA
  icons (`arrow_forward`→`fa fa-arrow-right`, `play_circle`→`fa fa-play-circle`) — `icon_position`
  `after`/`before` from where the glyph sits in the source.

That's it — for this screen the deterministic output is already faithful, so Tier 2 is optional. Reach for
it on screens with testimonials sliders, accordions, tabbed panels, or multi-row bento layouts the
heuristic parser flattens.
