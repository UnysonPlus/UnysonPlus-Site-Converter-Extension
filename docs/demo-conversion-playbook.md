# Demo Conversion Playbook — source site → Theme-Settings-driven UnysonPlus demo

How to turn a source website (e.g. an [openhero.art](https://openhero.art/) hero) into a
UnysonPlus **demo** whose design is reproduced **from Theme Settings + shortcode options**,
with a **near-empty child theme** — NOT a child theme full of scoped CSS.

This is both the manual process to follow AND the north star for the automated converters
(the Site Converter PHP `Mapper`/`Theme_Generator` and the JS capture service): today they
emit mostly a CSS child theme + only a color palette preset; the goal is for a conversion to
emit **colors + typography + buttons + boxes + spacing as Theme Settings presets** and have
shortcodes **reference** them. Improving the converters toward that is the standing target.

Root `CLAUDE.md` points here — read this before starting any demo conversion.

---

## Inputs — source files live in `demo-pages\<slug>\`

The user downloads each source into `d:\Web Dev\demo-pages\<slug>\` (e.g. the page source
`page.tsx`/HTML, a **`view-source.html`** dump, the real `video.mp4` + images, and a full-page
reference screenshot). ALWAYS use these:

- **`view-source.html` is the single most valuable input — ASK FOR IT if it's not there.** Its
  embedded `<style>` block is the AUTHOR'S CSS with exact values, and it hands you — in one grep —
  everything a live computed-style walk misses or must work for: **pseudo-elements** (`body::before`,
  `.organic-shell::before`), **hover states** (`.dew:hover{transform:translateY(-5px) scale(1.02)}`),
  **`@keyframes` + who uses them** (`.float-core`=drift, `.wind-sway`, `.pulse-react`, `.bloom`
  scroll-reveal, `.scroll-grow` scroll-timeline), and the **named premium classes verbatim**
  (`.liquid-panel`, `.dew`, `.telemetry`, `.light-river`). Grep it for `body`, `::before`, `::after`,
  `:hover`, `@keyframes`, `mask`, `filter`, `backdrop`, and each named class so nothing hides.
  **Caveat — it's complementary, not a replacement:** it only contains INLINE CSS (`<style>` + inline
  styles), so external `.css` files aren't in it (openhero `page.tsx` templates are all-inline, so
  near-complete; other sites still need the external CSS); and it's AUTHORED, not RESOLVED, so
  Tailwind utilities (`px-8`, `text-[12px]`) still need computed styles to resolve to px. **Ideal =
  view-source (authored CSS, structure, hover/animation/pseudo — nothing hidden) + Playwright
  computed capture (resolved px, external CSS).** Reactive Forest's view-source caught three misses
  the screenshots hadn't: the atom shell is an organic BLOB (`border-radius:44% 56% 58% 42%/…`) not a
  circle, the atom FLOATS (`.float-core` drift), and `.light-river` is a faded 1px gradient hairline.
- Read the **declared design tokens** from the source for exact values (Tailwind config
  `colors`/`fontFamily`/`fontSize`, CSS custom properties, `@font-face`).
- **Sideload** the real video/images into the demo's Media Library. NEVER bloat the
  downloadable `theme.zip` with a multi-MB video — reference it from the library. A **local
  file** (the video) sideloads via `media_handle_sideload` on a temp copy (dedupe by a
  `_upw_demo_src` meta = the source path); resolve it into the tree via a marker string
  (e.g. `__SENKEI_VIDEO__`) the importer swaps for `{attachment_id,url}`.
- **background-pro shape gotcha:** the section's bg `image.src`, `video.source_mp4`, and
  `video.poster` are each a **single object** `{attachment_id,url}` — NOT an array. `sc_bg_pro_style`
  / `sc_bg_pro_video_attr` read `image/src/url` and `video/source_mp4/url`, so an array yields no
  bg/video. (Set `video.enabled:'yes'`; the section renders the `<video>` client-side from data
  attrs → the markup shows `class="… background-video"`, no inline `<video>` tag.)
- **Diff** the rebuild against the reference screenshot at every step.

If a slug's folder isn't there yet, ask the user to drop the source in.

## Match the source's COMPUTED styles — measure, never guess (REQUIRED)

The demo must look **exactly** like the source, and the only reliable source of truth is the
source's **computed** styles — not a guess, not "it's probably a pill", not "close enough". Every
time a demo ended up looking like "a generic Bootstrap site", it was because a value was
*approximated* instead of *read*: the button was guessed as a full pill (`border-radius:999px`)
when the source's computed radius is `32px`; the section heading was left as the default sans when
the source uses the serif; the video was reproduced as a flat box when the source composites it
with `filter`/`mix-blend-mode`/`mask-image`. **Read the value, don't invent it.**

**The audit workflow (run it, don't wait to be told a thing is wrong).** After building — and
whenever a section looks even slightly off — open BOTH the source and the demo in a headless
browser (Playwright, viewport 1600px) and **diff `getComputedStyle` for every element**, matched by
visible text or class. Capture, per element: `font-family / font-size / font-weight /
letter-spacing / text-transform / color / line-height / background / border-radius / box-shadow /
filter / backdrop-filter / mix-blend-mode / padding`. Print source vs demo side by side and fix
every mismatch. Gotchas that bit us:

- **Climb to the styled ancestor** when matching by text (the text node's own element often has no
  background/border) — walk up ≤5 parents to the first with a bg/shadow/radius/border/filter.
- **Query the REAL element, not a stray namesake.** `document.querySelector('.btn-primary')` grabbed
  a hidden parent-theme `.btn-primary` (Arial/6px), not the hero button. Find the hero button by its
  TEXT ("Launch Ecosystem"), then read *its* classes + computed style.
- **A size utility can beat your base rule.** The buttons carried the right `.btn`/`.dew` classes and
  the correct glow, but the framework's `.btn-lg` size class overrode plain `.btn`, so font-size /
  radius / padding came out 20px / 8px / 8-16 instead of 12px / 32px / 20-32. Pin the source geometry
  with `!important` (or a higher-specificity selector) so the source values win.
- **Headings may be the display serif too.** Reactive Forest uses the Iowan serif for the hero H1
  **and** the section H2 **and** the feature-card titles — I'd only set H1. Read each heading's
  computed `font-family`; don't assume only H1 is special.
- **Capture `body` / `html` AND their pseudo-elements — an element-walk misses the premium ambiance.**
  The site's mood often lives OUTSIDE the DOM elements: a multi-layer radial-glow stack on **`body`**
  and a masked grid/noise overlay on **`body::before`**. `getComputedStyle(el)` skips pseudo-elements
  unless you pass the second arg, and a `querySelectorAll` walk never visits `body::before` at all —
  so read them explicitly: `getComputedStyle(document.body)`, `getComputedStyle(document.body,
  '::before')`, `'::after'`, and the same for `document.documentElement`. Capture `background(-image)`,
  `background-size`, `mask-image`, `filter`, `opacity`, `position`, `z-index`. **Translation is two
  parts, and the second is the one that bites:** (1) the base color → a Theme Settings background /
  palette; the gradient stack + masked `::before` → global CSS (Misc → Custom CSS on a live site, or
  the child theme `style.css` for a demo — no option can express a multi-layer gradient + a masked
  pseudo-element). (2) **Make the page SECTIONS transparent** — the source sections are
  `rgba(0,0,0,0)` so the body ambiance shows through; if your section `css_class` paints an opaque
  bg (even the same near-black), it COVERS the ambiance and the page looks flat. Reactive Forest:
  `body` = 3 radial glows + `#05070b`, `body::before` = a 110px grid masked `radial 30%→78%` at
  `opacity .45`, and `.rfi-hero`/`.rfi-eco` had to switch from opaque `--rfi-bg` to `transparent`.
  (If this ambient backdrop recurs across sources, it's a candidate to promote to a Theme Settings
  "Background Effects" option rather than hand-CSS each time.)

This is a MEASUREMENT step, not a judgement call — if the numbers differ, the demo is wrong, full
stop. Reactive Forest's final audit brought buttons to an exact `size=12px radius=32px pad=20px 32px
ls=2.88px` match with the source, and the eco heading/card titles to the correct Iowan serif.

## Method

1. **Theme-Settings-first (the goal).** Anything that *can* be a Theme Settings preset MUST
   be: **colors, typography/fonts, buttons, box/card presets, spacing**. Every element then
   CONSUMES those presets through its shortcode options (compact color-preset picker,
   button-style picker, column `border_preset`, …) instead of hardcoded CSS. The child theme
   ends up (near) empty — only truly un-settable rules stay (e.g. a hero video-mask scrim).
   **Preset options cover the COMMON properties; special CSS goes in Custom CSS, not a new
   field.** This holds for EVERY preset with a Custom CSS field — **Button, Box, and Table
   presets** each expose a `{{SELECTOR}}`-aware Custom CSS textarea for exactly this. Don't bloat
   a preset's schema with a field per exotic property. Standard stuff (bg, border, radius, shadow,
   padding, hover, font, colour) uses the preset's native fields; anything special —
   `backdrop-filter: blur()` (glassmorphism), blend modes, clip-path, a hover `transform` lift —
   goes in that preset's **Custom CSS** via `{{SELECTOR}}`. (So the glass card is a Box Preset
   using native bg/border/radius/padding + Custom CSS
   `{{SELECTOR}}{backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}` — NOT a new field.)
   Same for a one-off element: put special CSS in its **Advanced → Custom CSS**. Only build a
   genuinely NEW framework control when a *common* need has no home at all (e.g. the Button Shape
   control — a per-button radius override that decouples pill from size).

2. **Consult the converters FIRST — they own the mapping.** Before mapping a source element
   to a shortcode/option by hand, read how the converters already do it:
   - **Site Converter** (PHP): `framework/extensions/site-converter/includes/`
     `class-fw-site-converter-mapper.php` (element→shortcode + option assignment), `-stitch.php`,
     `-tailwind.php` (token extraction), `-theme-settings.php` + `-theme-generator.php`.
   - **Capture service** (JS): `Github Repository/UnysonPlus-HTML-to-Wordpress-Conversion/tools/design-capture/`
     `capture-extract.mjs`, `to-pages.mjs`, `to-design-config.mjs`, `to-presets.mjs`, `atom-templates.json`.
   Standing improvement target: extend `to-presets.mjs` (add `button_colors`/`border_presets`/
   `font_sizes`/`spacing_scale` — all whitelisted in `FW_Site_Converter_Presets::ALLOWED_KEYS`),
   add a `to-theme-settings.mjs` (typography), and switch `to-pages.mjs`/the PHP `Mapper`'s
   `n_button`/`n_column`/`n_icon_box` to set `style`/`border_preset`/`{predefined}` preset
   values. Keep the JS and PHP paths in sync (see `CONVERSION-ALGORITHM-SYNC.md`).

3. **Incremental — slowly but surely, ONE component at a time, verify each.** Do
   **colors → typography → buttons → boxes → spacing → per-element wiring**, and after each
   step VERIFY (render the page + inspect the generated `presets-{hash}.css` / theme tokens)
   before moving on. Do **NOT** big-bang the whole design in one pass. *(The Casa Uluwatu
   lesson: a one-shot full conversion comes out a mess; redone component-by-component with
   verification, it comes out right.)*

4. **Source fidelity audit — element-by-element, BEFORE calling a section done.** Rebuilding
   by section is not enough; you drop small things (an arrow icon, a badge, a hover colour).
   For EACH element, enumerate every notable attribute from the source (`page.tsx`/HTML) and
   confirm it's carried:
   - **Icons** (leading/trailing — e.g. a CTA's `arrow-right`), **badges/ribbons**
     ("Most Popular"), decorative marks.
   - **Exact copy** (word-for-word), **link targets** (`#anchor` / external → new tab),
     **image `alt`**.
   - **States**: hover colour/scale/shadow, active, disabled.
   - **Effects**: gradient text, glow/box-shadow, blur/glass, drop-shadows, transitions,
     **`mask-image` / background fades** (a hero video/image that fades its bottom edge into the
     next section), blend modes, `clip-path`, `object-fit`, filters on a video/image.
   - **Per-breakpoint** differences (stack order, sizes, what's hidden on mobile).
   **Scan the source's ARBITRARY Tailwind values** — the `[property:value]` bracket classes (e.g.
   `[mask-image:linear-gradient(...)]`, `[text-shadow:...]`, `[filter:drop-shadow(...)]`) carry the
   non-obvious CSS that's easy to miss when you only look at the visible boxes. Every such value is a
   piece of CSS you must reproduce (almost always via a Custom CSS tier — see below). **When the user
   flags any uncaptured CSS, ADD it to this playbook so the next demo captures it.**
   Then **diff the rendered result against the reference screenshot** (side by side). Only then
   is the section done. *(This is the step that would have caught the missing CTA arrow AND the
   missing hero-video `mask-image` fade.)*

5. **Section-by-section VISUAL + COMPUTED-STYLE diff (REQUIRED — the fidelity gate).**
   Attribute-checking the source (step 4) is necessary but NOT sufficient: it catches *missing*
   things, not *wrong-looking* ones. Some defects are invisible in the atts and only show up in
   the rendered pixels — the classic case is **typography** (a heading that's the right text but
   the wrong SIZE or WEIGHT, so it also wraps to the wrong number of lines). So before a section
   is done, **capture the demo output for that section and put it beside the source screenshot**,
   and when a heading/label looks even slightly off, **measure the computed style — don't eyeball
   it.** Drive the live demo with Playwright (the `pw-screens/` install has it):
   ```js
   // measure a heading: computed size/weight + rendered height (⇒ line count)
   const h1 = page.locator('h1.heading-title').first();
   const s = await h1.evaluate(el => { const c = getComputedStyle(el);
     return { size: c.fontSize, weight: c.fontWeight, box: el.getBoundingClientRect().height }; });
   ```
   Compare each against the source's Tailwind class (`text-8xl`=96px, `font-bold`=700,
   `max-w-4xl`=896px, `tracking-tight`=-0.025em). A ~2% width delta is enough to change where a
   line wraps, so an off-by-one on weight reads as "the max-width is broken" when it isn't.
   *(This is the step that would have caught — in ONE pass instead of five — the hero H1 rendering
   at weight 300 instead of 700: Bootstrap's `.display-1..6` hardcode `font-weight:300`, which the
   framework now overrides, shortcodes 1.10.73. Symptom was a wrong 2-line wrap; root cause was
   weight, not width.)* **Checklist per heading/label:** computed `font-size` == source px,
   computed `font-weight` == source weight, block `max-width` == source container, and the
   **rendered line count matches** the source screenshot.

---

## Special CSS — the 3-tier Custom CSS escape hatch

Anything that ISN'T a native option and isn't worth a whole new field goes in a **Custom CSS** field.
There are three, chosen by SCOPE — never add a schema field for a property one demo needs once:

1. **Preset → Custom CSS** (`{{SELECTOR}}` → `.btn-/.boxp-/.tbl-{slug}`) — reusable, part of a
   Button/Box/Table preset (the glass card's `backdrop-filter:blur()`). Travels in `settings.json`.
2. **Element → Advanced → Custom CSS** (bare `selector` keyword → the element's `.u{hash}` wrapper) —
   ONE element's effect; travels with the PAGE. Use for a hero heading's `filter:drop-shadow()`, a
   subtitle's legibility `text-shadow`, or a responsive line break
   (`@media(max-width:767.98px){selector br{display:none}}`). `dynamic-css.php` replaces `selector`
   with the scope class. **sc_needs_wrapper honors custom_css (shortcodes 1.10.72):** an element with
   ONLY Custom CSS still renders its wrapper — otherwise the scope class is absent and the CSS never
   applies (bit us on special_heading).
3. **Miscellaneous → Custom CSS** (global, Theme Settings) — site-wide cross-cutting rules; travels in
   `settings.json`. Keep it for genuinely global stuff; page-specific rules belong on the element (tier 2).

**Rule of thumb:** reusable + preset → tier 1; this-one-element → tier 2; site-wide → tier 3.

### Responsive line breaks (source `<br class="hidden md:block">`)

The source's utility-class-on-a-`<br>` is exactly the DOM noise UnysonPlus avoids. Reproduce it CLEAN:
a plain `<br>` in the WYSIWYG content (no class) + a media query in the element's **Advanced → Custom
CSS** to hide it on mobile: `@media (max-width:767.98px){selector br{display:none}}`. Desktop breaks,
mobile flows — faithful, clean DOM.

### Section / background effects — `mask-image` fades (video/image edge blend)

A hero whose **video/image fades its bottom edge** into the next section (source: a `[mask-image:
linear-gradient(to bottom, rgba(0,0,0,1) 60%, rgba(0,0,0,0) 100%)]` on the `<video>`) → put the mask
in the **SECTION's Advanced → Custom CSS** (tier 2), targeting the background LAYER, not the section
itself (masking the section would fade the CONTENT too):

```
selector .fs-background-container{
  -webkit-mask-image:linear-gradient(to bottom,#000 60%,transparent 100%);
          mask-image:linear-gradient(to bottom,#000 60%,transparent 100%)}
```

`selector` → the section's `.u{hash}` wrapper; `.fs-background-container` is the section's JS-injected
bg layer (the `<video>` lives in `.fs-background-media` inside it), a SIBLING of the content
(`.fw-container`) — so only the background fades. Emit **both** `-webkit-mask-image` and `mask-image`.

**bg-pro gotcha (why the fade looked broken at first):** a video Section's background-pro sets BOTH a
section `background-image` (the poster jpg, painted on the section element) AND the video. Masking the
video then reveals the IMAGE behind it — no fade. **When a video Section needs a mask fade, do NOT
also paint a section background-image** — leave `background.image.src` **empty** and rely on the
video's `poster` for the pre-load frame (so the masked-out area reveals the dark section/body, not the
image). *(In the tree factory: `image.src = (imgUrl && !videoUrl) ? {…} : []`.)*

### Video/media role — ELEMENT vs BACKGROUND (classify it, don't assume)

A striking hero visual (a looping atom, a product render) is often a **content element** in a layout
cell, NOT a section background. Getting this wrong is very visible: a background **fills-and-crops**
and sits behind the copy (zoomed, clipped, full-bleed), whereas the source frames the video in a cell
(a fixed-aspect, rounded box beside the text). **Classify every source `<video>`/media before placing
it** (Reactive Forest Intelligence bit us here — I wired the atom as a `background-pro` video; the
source is a `<video>` element in the hero grid's right cell):

> **Role algorithm.** For each source `<video>` (measure at a wide viewport):
> - `position: fixed/absolute` **AND** its box covers ≥~85% of a section **AND** it's `z-index`ed
>   behind the content → **BACKGROUND** → emit as the Section's `background-pro` video (full-cover).
> - `position: static/relative`, sitting in a **grid/flex cell** with a sibling content cell, box
>   **smaller than the section** (`coversSection:false`) → **ELEMENT** → emit as a real `<video>` in
>   the matching page-builder column, carrying the source wrapper's **aspect-ratio + `object-fit`**.
>
> Reactive Forest → hero is a 2-col grid `596px | 700px`; the video is `position:static` in an
> `aspect-[.95]` `object-fit:cover` wrapper, `coversSection:false` → **element**. Rebuilt as a 2-column
> hero (content LEFT, a `<video autoplay muted loop playsinline>` element RIGHT). The video is a lean
> `<video>` inside a `text_block` (markers `__RFI_VIDEO__`/`__RFI_ATOM__` resolved on import by a
> **string** replace, since the URL now lives in element HTML, not a `url` node).

> **Translate the video's COMPOSITING, not just its box** (this is what makes a clip read as
> "integrated" vs a boxed rectangle — grab the `<video>`'s + its wrapper's computed `filter`,
> `mix-blend-mode`, `mask-image`, `drop-shadow` and reproduce them). Reactive Forest's atom:
> - `filter: brightness(.9) contrast(1.08) saturate(1.2)` on the video (crisper, punchier).
> - **`mix-blend-mode: screen`** — the clip's BLACK background blends into the dark section so only the
>   bright subject shows (the atom "floats", no hard edges). The single biggest effect; a plain boxed
>   video looks generic without it.
> - **`mask-image: radial-gradient(circle,#000 42%,transparent 74%)`** — fades the edges to transparent
>   (soft vignette, no rectangle).
> - `drop-shadow(0 0 80px rgba(97,218,251,.18))` glow + an `::before` blurred cyan bloom behind it;
>   `isolation:isolate` on the wrapper so the screen-blend composites within the atom group.
>
> All of that rides the child theme (`.rfi-hero-media` + `.rfi-hero-media video`) — it's per-element
> compositing that presets can't express. **Lesson: when a demo "looks generic", diff the source's
> computed `filter`/`mix-blend-mode`/`mask-image` against yours — those are usually what's missing.**

> **Layout note — nested rows for a 2-col hero.** The left cell stacks (badge/H1/subtitle) but the
> button + stat rows are horizontal. Sections never nest, but a **column CAN nest columns** (the
> builder wraps a column's child columns in an `fw_inner_row`). So the left column holds nested `1_1`
> columns; the button/stat rows are `1_1` with `content_direction:'row'`. Gotcha: the row's
> `.flex-wrap` utility is `!important`, so force single-line with `flex-wrap:nowrap !important` when
> tracked buttons sit ~1px over the cell width (measure — ours were 617px in a 633px cell → wrapped).

### Hero heading width (source `max-w-4xl`)

A page-builder **column has no max-width option**, so DON'T scope `.fw-col-*` in child CSS. Each
element carries its own: special_heading's **Heading Max Width** (`block_max_width`) constrains the
title (e.g. `max-w-4xl` = 896px → emits `max-width:896px;margin-inline:auto`), text_block's **Max
Width** (multi-picker; custom = `{preset:'custom',custom:{custom_width:{value,unit}}}`) constrains a
paragraph. Since a hero is text-centered, per-element max-widths read identically to one wrapper —
with zero child CSS.

### Section container width (source `max-w-7xl` / `max-w-[1600px]`)

The source's content bands (`mx-auto max-w-7xl` = 1280px, or an arbitrary `max-w-[1600px]`) set the
GLOBAL **Container Width** (General → Layout → Container Width, responsive Phone/Tablet/Desktop) →
drives `--container-max-desktop` consumed by every `.fw-container`. Getting this wrong makes the whole
demo read narrower/wider than the source, so **MEASURE it, don't guess** — via this algorithm:

> **Container-width algorithm** (implemented in the capture service, `capture-extract.mjs` →
> `containerMax`; mirror it if you measure by hand). The old approach (look for a Bootstrap `.container`
> class, take the first hit) missed Tailwind `max-w-[…]` and picked stray wrappers. The robust version:
> 1. Walk every `div/section/header/footer/main/article/nav` and keep the ones with an **explicit
>    `max-width` in a sane range (600–2400px)** that are actually **rendered wide (≥480px)**.
> 2. Require the box to be **horizontally SYMMETRIC on the viewport** (`|left-gap − right-gap|` small).
>    Test the *rendered box*, NOT the margin value — `getComputedStyle` resolves `margin:auto` to
>    `0px`, so a `margin === 'auto'` test never matches. Symmetry holds whether the container fills the
>    viewport (both gaps ~0) or is inset by auto margins (both gaps equal); a left-aligned sidebar
>    (asymmetric) is rejected.
> 3. **Bucket by max-width and weight each bucket by the content AREA it wraps.** The site container
>    (header bar + hero + every section share one max-width) dominates a one-off centered card. The
>    winner's max-width IS the container width. (Reading computed `max-width` is viewport-independent,
>    so it's reliable even when the capture viewport is narrower than the container.)
>
> Reactive Forest Intelligence → `max-w-[1600px]` (header pill-bar + hero + sections all share it) →
> Container Width **1600px**. Wired by `rfi-layout.php`; verified `--container-max-desktop:1600px`.
> **Gutter math (the source's `max-w-N` is CONTENT width; UnysonPlus's is OUTER).** Tailwind puts the
> gutter OUTSIDE the max-width (`<section class="px-6"><div class="mx-auto max-w-7xl">`), so `max-w-7xl`
> is a TRUE 1280px of content. But `.fw-container` is **border-box** and bundles max-width **+** a 24px
> gutter each side, so a 1280px setting yields only **1232px** of content. To reproduce a true 1280px
> content width (while keeping the gutter on narrow screens), set Container Width = **source max-width
> + 2×gutter = 1280 + 48 = 1328px**. Verify at a viewport WIDER than the container (a viewport-clipped
> measurement lies). (The hero's narrower `max-w-4xl` content
is per-element, above — not the container.) **Two write gotchas bit us here:**

> **1. Theme-option GROUP write.** `layout_container_width` lives INSIDE the `general_layout` group
> option (a Theme-Settings TAB stores all its fields under the tab's group key). The var-collector
> reads `fw_get_db_settings_option('general_layout')['layout_container_width']`, so you must
> **read → merge → write the WHOLE group** (`general_layout`) — NOT `fw_set_db_settings_option(
> 'layout_container_width', …)` top-level. (`fw_get` will happily echo a top-level write, but the
> collector never sees it, so nothing changes — a silent no-op.) Same for any tab-grouped theme option.
>
> **2. Regenerate the compiled CSS.** A CLI/programmatic write does NOT fire the settings-save hook, so
> `uploads/…/unysonplus-generated.css` (which carries `--container-max-*`, colours, spacers, …) won't
> rebuild — call **`unysonplus_hf_regenerate_css()`** after writing. (Same family as the Google-fonts
> `_action_theme_process_google_fonts()` gotcha.)

### Element (heading / text) max-widths — a DIFFERENT thing from the container

A **container** max-width bands a whole section; an **element** max-width is a per-block readability
"measure" (a `max-w-xl` on a paragraph so lines don't run too long). Detect them separately:

> **Element max-width algorithm.** For each heading/paragraph read `getComputedStyle(el).maxWidth`:
> 1. **Definite px** (not `none`) → that's the cap. Tailwind: `max-w-sm`=384, `max-w-md`=448,
>    `max-w-lg`=512, `max-w-xl`=**576**, `max-w-2xl`=672, `max-w-3xl`=768, `max-w-prose`≈576 (65ch),
>    `max-w-[Npx]`=N.
> 2. **`none`** → measure `getBoundingClientRect().width` vs the parent's content width. `render ≈
>    parent` → NO cap (a HEADLINE fills its column and breaks by font-size — e.g. "Reactive / Forest
>    Logic"); `render < parent` consistently → an effective wrapper/inline-block constraint to chase.
> 3. **MEASURE THE RIGHT ELEMENT.** The cap sits on the block WRAPPER, so the inner `<p>` reads
>    `maxWidth:none` while rendering at the capped width — read the wrapper (or the render width), not
>    the `<p>`, or you'll wrongly conclude "no cap".
>
> **Translate:** `text_block` carries a native **`max_width`** option (`{preset:'custom',
> custom:{custom_width:{value,unit}}}`) → renders `max-width` on the `.text-block` wrapper; use it. For
> a shortcode without one, a one-line scoped rule on the block's CSS Class (`.rfi-lead{max-width:576px}`)
> is the smallest lever. Reactive Forest: **H1 = `none`** (fills its column) but **subtitle + eco body =
> `max-w-xl` (576px)** — verified the `text_block` wrapper renders `max-width:576px`, `<p>` at 576px.

### Translating a Tailwind flex container → a page-builder row (don't hand-write flex CSS)

A class string like **`flex flex-col gap-8 lg:flex-row lg:items-stretch`** is the *signature of a
page-builder row* — the row/column grid IS that container, so map it, never reproduce the flex in CSS:

| Tailwind on the container | Page-builder equivalent | Cost |
|---|---|---|
| `flex flex-col` (mobile) + `lg:flex-row` (desktop) | a **row**'s built-in responsive stack→row behavior | **free** |
| `gap-8` (2rem/32px) | the column **gutter** | set gutter |
| `lg:items-stretch` (equal-height children) | the row's **default** `align-items:stretch` | **free** — pair with the box preset `height:100%` so the *card* (not just the column) fills (see the equal-height algorithm) |
| child rendered widths, e.g. **937 : 492 ≈ 2 : 1** | column twelfths from the ratio → **`2_3` + `1_3`** | pick nearest twelfths (fifths `1_5` also available) |

> **Ratio, not absolute px.** Read the children's `getBoundingClientRect().width`, take the RATIO, and
> snap to twelfths (937:492 = 1.9:1 → 8:4 → `2_3`+`1_3`). Your container is usually wider than the
> source's, so the absolute px won't match (1051:525 vs 937:492) — the **ratio** is what must match.
> `items-stretch` is also the tell that the children are MEANT to be equal-height (why the box
> `height:100%` matters). Reactive Forest's eco band was first built `1_2`+`1_2` (6/6) and corrected to
> `2_3`+`1_3` (8/4) once measured.

---

## Color preset standard

Color presets live in **Theme Settings → Components → Color Presets** (option `theme_colors`,
per-theme, stored in `fw_theme_settings_options:{theme-id}`). Each entry is `{ name, color(hex) }`;
the **slug = name lowercased, non-alphanumeric → `-`** ("Primary" → `primary`, "Green Light" →
`green-light`). The palette emits `:root{--color-{slug}}` + utility classes `.text-{slug}` /
`.bg-{slug}` (via `unysonplus_build_presets_css_string()` in `framework/includes/css-tokens.php`,
cached to `uploads/unysonplus/presets-{hash}.css`; the hash includes the palette, so edits
auto-cache-bust).

**Procedure / getting the colors** — don't scan-and-dump every hex:

1. **Read the source's declared tokens first** (Tailwind `colors`, CSS vars) — the authoritative
   palette. Fall back to computed styles only if no code (what `to-design-config.mjs` does).
2. **Resolve to semantic ROLES by usage**, not frequency: CTA/link color → brand; body bg →
   canvas; panel/card bg → surface; most-used text → ink; low-contrast text → muted; hairlines →
   line; a warm secondary → accent.
3. **Collapse, don't collect**: drop translucent/alpha values (they're a base + opacity, handled
   in the box step) and one-off decorative tints; fold a color *scale* into one role in shades
   (brand light/default/deep).
4. **Curate a MINIMAL, role-based palette** (~8–12 swatches).
5. **Reuse the framework's semantic role NAMES** — map the source's **CTA/brand colour to
   `Primary`** so button presets and the `.text-primary` accent mapping inherit it. Add new named
   swatches only for roles the defaults lack (dark canvas, surface/panel, …).
6. **Name swatches for clean slugs** (Primary, Primary Light, Primary Deep, Accent, Dark, Surface,
   Ink, Muted, Line).

Rules: **every element colour REFERENCES a preset** via `sc_color_field_compact()`
`{ predefined: 'text-{slug}' | 'bg-{slug}' }` — never a raw hex (custom hex only for a genuine
one-off). **Palette entries are OPAQUE hex only** (no alpha; no `--color-{slug}-rgb` triplet) —
translucent surfaces (glass, hairlines) go in the box step's Custom CSS.

Set with `fw_set_db_settings_option( 'theme_colors', $palette )` on the target (sub)site
(bootstrapped AS that blog); the preset CSS regenerates on the next front-end load.

**Senkei palette (reference):** Primary #22c55e, Primary Light #86efac, Primary Deep #16a34a,
Accent #facc15, Dark #020617, Surface #0f172a, Ink #f8fafc, Muted #94a3b8, Line #1e293b, White,
Black.

---

## Typography standard

Typography lives in **General → Typography** (theme-owned:
`unysonplus-theme/framework-customizations/theme/options/general-typography.php`) — heading + body
**font families**, base body size/line-height, and the **h1–h6 size scale**. (This is NOT the same
as **Components → Font Sizes** — see below.) Tokens emit into `:root` as `--font-heading` /
`--font-body` / `--body-*` / `--h{1..6}-{font-size,font-weight,line-height,letter-spacing,color}`
(front end: compiled into `uploads/unysonplus/unysonplus-generated.css`; admin: inline
`<style id="unysonplus-tokens">`), consumed by fixed `body` / `h1..h6` selectors in the theme
`style.css`. The framework auto-applies a mobile scale-down.

Procedure:

1. **Detect the source's REAL font families — read the computed stack, don't guess.** For each
   distinct text role (body, heading, label/overline, button) read
   `getComputedStyle(el).fontFamily` on the LIVE source; the **first quoted family** is the real
   font (`"PP Neue Montreal", sans-serif` → `PP Neue Montreal`). If the first entry is a
   system/generic (`-apple-system`, `Arial`, `sans-serif`, `Segoe UI`), the element is on a
   fallback — keep reading siblings. Then **classify** each family:
   - **Google Font** — in `fw_get_google_fonts()` (or a `fonts.googleapis.com` `<link>` is in the
     source `<head>`)? → set it as a Google family; it auto-enqueues on save.
   - **NOT on Google Fonts** (PP Neue Montreal, Iowan Old Style, most foundry fonts) → it MUST be
     **self-hosted** as a Custom Font (sideload woff2 → `custom_fonts` → the picker filter registers
     it → set the family with `google_font:false`). **Do NOT silently substitute a look-alike Google
     font** (Inter for PP Neue Montreal). If you can't obtain the woff2, **STOP and tell the user the
     exact family name so they can download it** — a substituted font is a wrong font.
   - **OTF/TTF → woff2:** the user's download is often OTF (e.g. `cufonfonts`). Convert to woff2 with
     fonttools (`f = TTFont(src); f.flavor='woff2'; f.save(dst)` — has native woff2 support) before
     sideloading; woff2 is ~half the size and is what the `@font-face` generator emits. Map the OTF
     weight names → numeric weights (book=400, medium=500, semibold=600, bold=700; `*italic` → the
     italic face). A weight the download lacks (e.g. no true 600) resolves to the nearest face —
     note it rather than faking it.
   Reactive Forest: the source is **PP Neue Montreal** (body/UI/labels/buttons/stat-numbers) +
   **Iowan Old Style** (H1/H2/card titles) — neither is on Google Fonts, so BOTH are self-hosted;
   the first build wrongly substituted Inter/Inter Tight until the user supplied the real PP woff2.
2. **Map the source heading sizes to the h1–h6 scale** (px — the framework shrinks them on
   mobile): hero display → h1, section headings → h2, card titles → h3, etc. Set size / weight /
   line-height / letter-spacing per heading; set base body size + line-height.
3. **Write** with `fw_set_db_settings_option( 'typography', $merged )` — MERGE into the current
   value so `body_link*` etc. survive. `typography-v2` value shape:
   `{ google_font, subset, variation, family, style, weight, size, line-height, letter-spacing, color }`;
   a heading override with `family => ''` inherits the Heading Font.
4. **Rebuild the Google `<link>`.** A CLI/programmatic write does NOT fire `fw_settings_form_saved`,
   so the cached link (`fw_theme_google_fonts_link`) won't regenerate — call
   `_action_theme_process_google_fonts()` after writing.
5. **Verify** the `--font-*` + `--h{1..6}-*` tokens (in `unysonplus-generated.css`) and the Google
   `<link>`, and that the mobile scale-down applied.
6. **Strip from the child theme**: the manual webfont enqueue, `body`/heading `font-family`, and
   every per-heading `font-size` / `font-weight` / `letter-spacing`. Keep non-typographic effects
   (hero drop-shadow scrim, a deliberate light weight). Re-verify the render.

Watch the **`*/` docblock gotcha**: writing `--font-*/--h*-*` in a PHP docblock closes it early —
rephrase.

**Senkei scale (reference):** Body + Heading = Inter, body 16/1.65; h1 88px/700/-2px, h2 44/700/-1px,
h3 24/600, h4 20/600, h5 18/600, h6 16/600.

### Text Styles (Components) — the reusable type-style system

**Components → Text Styles** (renamed from "Font Sizes"; shortcodes 1.10.74 — the store key stays
`font_sizes` for compat) is SEPARATE from General → Typography. Each Text Style is a NAMED, reusable
type token = a size PLUS **optional weight / line-height / letter-spacing / text-transform**. Emits
`:root .{class}{…}` (scoped to `.font-{slug}` or a literal `class`) into `presets-{hash}.css`.

- **Every property is OPT-IN.** A style emits only the fields you fill in; a blank field **inherits**
  from the element's tag token — a **blank weight keeps the heading's weight, it does NOT thin it**.
  Size is optional too (an *Eyebrow* style can set only weight+tracking+uppercase, no size).
- **Cascade (why it composes):** the style's `:root .{class}` = specificity **(0,2,0)**, which beats
  Bootstrap's `.display-N` (0,1,0) AND the tag-weight override (`hN.heading-title` = 0,1,1). So a
  style that SETS a weight wins; a blank weight falls through to the tag token. No `!important`;
  element Custom CSS still overrides.
- **Consume it** via any element's **Text Style** dropdown (`font_size_preset`, formerly "Font Size
  Preset"; special_heading's `display_size` / `subtitle_size`). The picker value IS the class
  (`lead`, `font-eyebrow`). The picker lists any styled preset — a size is NOT required.
- **Defaults:** Display 1–5 (96/88/72/56/48px, class `display-N`) + Lead. Reshape/add freely (a
  never-used preset store is free to rewrite — no migration).

**When to use:** a source `<p>` that bundles size+weight+line-height (a *Lead* paragraph), or a
label that's uppercase+tracked+small (an *Eyebrow*/overline) → collapse it into ONE Text Style the
element consumes, instead of scattered child CSS. (Senkei: **Lead** = 20/300/1.625 for the hero
subtitle; **Eyebrow** = 12/600/uppercase/0.1em for "Trusted by …".)

**Display-size weight (shortcodes 1.10.73).** Bootstrap's `.display-1..6` hardcode `font-weight:300`;
the framework now re-asserts the heading's OWN weight (`hN.heading-title` reads `--hN-font-weight`),
so a Display size changes SIZE only — never thins the heading. A font-weight that's ~2% off changes
where a line WRAPS, so a wrong wrap usually means a wrong WEIGHT, not a wrong max-width (see Method
step 5).

---

## Buttons standard

Buttons are a **two-axis (now three)** system, composed as `class="btn btn-primary btn-lg btn-shape-pill"`:

- **Color preset** (`button_colors`, Theme Settings → Components → Buttons) → `.btn-{slug}`. Per state
  (default/hover/active/focus/disabled): bg / text / border color (these **reference Color Presets**
  as `{predefined:'<color-slug>', custom:''}` — bare slug, e.g. `primary`), border width/style,
  gradient, **box-shadow** (the glow). Shared: **font** (typography-v2 — weight lives here),
  transition, custom CSS. Ships 6 solid + 6 outline + gradient + link.
- **Size preset** (`button_sizes`) → `.btn-{slug}`: font-size, line-height, padding-y/x, border-radius.
- **Shape** (per-button option, shortcodes 1.10.65) → `.btn-shape-{pill|rounded|square}`: overrides
  ONLY the size's border-radius. Default keeps the size radius. Decouples "pill" from "large".

Standard (mirror the colour standard — override roles, don't dump):

1. **Override the basics, then TRIM/RE-POINT to the palette.** Re-skin **Primary** to the source's
   main CTA. Because Primary's bg **references the Primary Color Preset**, it already renders in the
   brand colour once the palette is set — you mostly add hover + glow + weight.
   **⚠ Interdependency (bit us on Senkei):** button presets reference Color Preset *slugs*, so
   **curating the palette orphans every preset that referenced a removed colour** — the default
   Secondary/Success/Info/Warning/Danger point to `secondary/green/cyan/amber/red`, which vanish
   when you trim the palette to a minimal role set → those buttons render with **no colour** (empty
   previews). So after setting the palette, **curate the button presets to match**: keep only what
   the design uses and **re-point every reference to a LIVE palette slug**. Keep status buttons ONLY
   if the design uses status colours (and they're in the palette). *(Same rule applies to Box
   Presets — their colours reference the palette too.)*
   Senkei kept 5, all re-pointed: Primary (green+glow), Secondary (→`muted`), Primary Outline
   (`primary`), Secondary Outline (→`muted`), Link (`primary`); removed the 4 status buttons + their
   outlines + Gradient.
2. **Match the source CTA on Primary**: default bg/text + the glow via the default-state `box_shadow`;
   hover bg/text (the source's hover colours); font-weight via the shared `font`. Colours are bare
   Color-Preset slugs.
3. **Get sizes right**: tune the size preset the CTA uses (font-size / padding) to the source's button
   dimensions. Radius is handled by **Shape**, so don't fight it in the size.
4. **Shape** for the CTA silhouette: `pill` (999px), `rounded` (0.75rem), `square` (0), or Default.
5. **Wire + strip**: set the button's `style` (colour preset), `size`, and `shape`; then delete the
   `.btn` CSS from the child theme (skin + radius + padding + glow all come from settings now).

**Set programmatically:** load `unysonplus_get_button_color_presets()` / `..._size_presets()`, mutate
the entry you're overriding, `fw_set_db_settings_option('button_colors'|'button_sizes', $arr)`.
box-shadow value shape: `{x,y,blur,spread,color,inset}`. Note: the glow colour is a **raw** value
(no alpha on Color Presets — the rgb-triplet gap), so a literal `rgba(...)` is expected there.

**Senkei (reference):** Primary → default `#22c55e` + glow `0 0 30px -5px rgba(34,197,94,.5)`, hover
bg `primary-light` + text `surface`, weight 600; Large size → 18 / 16 / 32; hero button = `btn-primary
btn-lg btn-shape-pill`.

### Button options — improvement SHIPPED

- **Shape control (shortcodes 1.10.65)** — border-radius was welded to the size preset; the per-button
  Shape option decouples it (override-only, no migration, existing buttons unchanged).

## Box / card presets standard

A card = a **Box Preset** (`border_presets`, Theme Settings → Components → Box Presets) → `.boxp-{slug}`.
Per preset: `border_sides`, `border_radius` (unit-input), `padding` (a spacing-scale value, e.g. `p-4`),
`transition`, `custom_css` ({{SELECTOR}}-aware); and per **Default/Hover** state: `background`
(**background-pro — rgba-capable**), `border_style`, `border_width`, `border_color` (compact picker —
**hex only**, no alpha), `box_shadow`. **Consumed by the Column's `border_preset` option**, which stores
the FULL class (`boxp-{slug}`) and wraps the column's inner content in `.boxp-{slug}`. (Table Frame +
Countdown also consume box presets; `pricing_table` does NOT — see below.)

Building a card (the glass card is the reference):

1. **Native fields for the common properties**: radius, padding (add a spacing scale entry if the
   source's value is missing — Senkei added `Card`=2rem), border width/style, box_shadow, transition,
   and the **background** (bg-pro's colour `custom` takes **rgba**, so a translucent glass fill IS a
   native field).
2. **Custom CSS for the special bits** (per the "special CSS → Custom CSS" rule): `backdrop-filter:
   blur()`, the hover `transform` lift, and — because the border-colour field is **hex-only** — a
   **translucent hairline border** (`{{SELECTOR}}{border-color:rgba(255,255,255,.08) !important}`;
   `!important` is needed because the base rule emits `border:1px solid currentColor !important` when
   the colour field is empty).
3. **Wire it**: set each card column's `border_preset` = `boxp-{slug}`; **strip** the card CSS from the
   child theme.

> **Equal-height cards — the algorithm (a card row where every card is the same height regardless of
> content).** Two layers, and BOTH are needed:
> 1. **Columns auto-stretch (free).** A page-builder row is `display:flex; align-items:stretch`, so the
>    column ELEMENTS in a row are already equal height (the tallest column). You get this for nothing.
> 2. **The card must fill its column.** The visible card is the Box Preset on the column's INNER
>    wrapper, and that wrapper is **content-height** by default — so a shorter card leaves a gap in its
>    (stretched) column and the cards look UNEQUAL even though the columns match. Fix: give the box
>    preset **`height:100%`** so the card fills the stretched column. Put it IN the preset's Custom CSS
>    (`{{SELECTOR}}{height:100%}`) so every `.boxp-{slug}` card is equal-height everywhere, or as a
>    scoped `.boxp-{slug}{height:100%}` rule.
>
> **Diagnose by MEASURING** the card heights (`getBoundingClientRect().height`) against the source's —
> Reactive Forest's eco cards were `[192, 230]` (unequal) vs the source's `[295, 295]`; after
> `height:100%` they were `[230, 230]`. (For content INSIDE a taller card to distribute — e.g. pin a
> button to the bottom — make the card a flex-column; filling is enough when the content is top-aligned.)
4. **Re-point the default box presets** too — Card/Outline/Hover Lift reference `light-gray`/`indigo`,
   which a curated palette drops (same interdependency as buttons). Re-point to live slugs.

**`pricing_table` caveat:** it renders its own `.fw-pt__plan` cards and does NOT consume box presets
(it has an opaque `card_bg`). So a translucent/glass pricing card can't be a Box Preset without adding
`border_preset` support to `pricing_table` — a self-contained component, so leaving its card look as a
few scoped child-CSS rules is the pragmatic call (like the CLAUDE.md exception for bespoke UIs).

**Senkei (reference):** Glass preset — bg `rgba(15,23,42,.5)` (bg-pro custom) / hover `rgba(30,41,59,.8)`;
border 1px + radius 16px + padding `p-card` (2rem); Custom CSS = hairline `rgba(255,255,255,.08)!important`
+ `backdrop-filter:blur(8px)` + `:hover{transform:translateY(-4px)}`. Feature columns → `boxp-glass`.

## Table presets standard

Table presets (`table_presets`, Theme Settings → Components → Tables) → `.tbl-{slug}`. The colour
fields ARE the same compact preset picker (so tables CAN track the palette) — but the **defaults
hardcode neutral WordPress-admin hex** (`#1d2327` text, `#2271b1` blue header, `#ffffff` body), because a
table needs **theme-aware neutrals** (body bg is white on light themes, dark on dark) that no single
palette slug provides. So a table dropped on a curated (e.g. dark) theme looks **off** — light body,
WP-blue header — the palette's third orphan after buttons and boxes.

- **Leave the FRAMEWORK defaults as hex** (correct neutral for a fresh site — re-pointing them to
  `surface`/`ink` would break palettes that lack those neutral roles).
- **Curate the DEMO's table presets to the palette** so a table is on-theme: re-point every hardcoded
  hex to the matching role — light WP neutrals → `surface`/`line`, dark text → `ink`/`muted`, borders →
  `line`, an accent header → `primary`; a white body bg → **empty** (transparent, inherits the dark
  section). Walk the preset tree and swap `{predefined:'',custom:'#hex'}` → `{predefined:'<slug>'}`. It's
  demo data (travels in `settings.json`); no framework change. (Senkei: 50 fields re-pointed; a table
  now renders light-on-dark with `line` borders.)

## Section Styles standard (`.section--{slug}` skins)

Section Styles (`section_style_presets`, Theme Settings → Components → **Section Styles**, an
`addable-box`) → a `.section--{slug}` class the user picks on a Section (**Layout → Section Variant**).
The three defaults **Alt / Light / Dark** reproduce the old hardcoded section variants exactly, so a
section that stored `variant: dark` renders identically with zero migration. Each preset carries a
Background-Pro fill, Text/Heading/Link colours (compact preset), a **combined Border**, a Border
Radius, and a Padding (spacing scale). `css-tokens.php` turns each into one `.section--{slug}` rule
(no `!important`, so a section's own one-off Background / Spacing still wins).

**Use a Section Style when the source has a repeated section "skin"** (an alternating band, a dark
CTA band, a bordered callout) — define it ONCE as a preset and apply the variant, instead of
per-section Custom CSS. Colours reference palette slugs, so a re-tint of the palette re-tints every
band.

### The combined Border control (shared `multi-inline` primitive)

Both Section Styles AND the header/footer Custom Styling now express a border as **three coordinated
controls**, built on the `multi-inline` option type (see below):

1. **`border` (multi-inline row)** — `{ width:{value,unit}, style, color:{predefined,custom} }` laid
   out **Width · Style · Color** on one line (the CSS-shorthand order `1px solid #000`). Style choices
   include a `''` = **None** (Section Styles) / solid·dashed·dotted·double (chrome). Color is the
   palette-linked compact preset. A border shows only when a style is chosen and a width+colour set.
2. **`border_sides`** — a **multi-select image-picker** (`multiple => true`, data-URI edge tiles);
   value is an ARRAY subset of `['top','right','bottom','left']`, default **all four** (= the legacy
   all-around border). `css-tokens` maps it to per-edge `border-{edge}` declarations.
3. **`border_extent`** — an inline multi-picker `{ mode: 'full'|'container'|'custom' }` (`custom`
   reveals a unit-input width). Governs how far the **top/bottom** border runs: `full` = edge-to-edge
   (a real `border-top/bottom`); `container` / `custom` render as a **centered `::before`/`::after`
   pseudo-element** capped at a max-width (`margin-inline:auto`). Left/right are always real vertical
   borders, unaffected by extent.

**Legacy safety (copy this pattern for any "combine scattered leaves into one control" change):**
- A **normalizer** (`unysonplus_section_style_normalize_border()` / `unysonplus_hf_normalize_sides()`)
  folds the old flat leaves (`border_style`/`border_width`/`border_color`, or a `'top'|'both'` string)
  into the combined shape on read — a no-op once already combined — so the CSS consumer only reads the
  new shape.
- The **consumer tolerates both shapes** as a belt-and-suspenders (reads the combined row, falls back
  to the flat leaves).
- A **one-time migration** rewrites the stored blob so the *editor* reflects the value and a re-save
  doesn't drop it: Section Styles `unysonplus_migrate_section_style_border_row()` (init pri 21, flag
  `upw_section_border_row_migrated`); chrome `unysonplus_hf_migrate_border_sides()` (admin_init pri 20)
  + `_array()` (pri 21). Each gated by its own `get_option` flag. Missing `border_sides` defaults to
  all four so old saves render unchanged.

### The `multi-inline` option type (the primitive)

`core/includes/option-types/multi-inline/` — renders **N child controls side-by-side on ONE row**,
each with its caption **below** (muted italic, matched to `typography-v2`'s `.fw-inner`), stacking
**vertically at ≤782px** (WP admin's mobile breakpoint). This is the canonical successor to the
off-convention `fw-multi-inline` (which still works; call sites migrate one-by-one). Reach for it
whenever several small fields read better as one line (Width·Style·Color, T·R·B·L, a min/max pair).

- **Child types:** `short-text`, `text`, `color` (→ color-picker), `rgbacolor` (→ rgba-color-picker),
  `short-select`, `select`, **`unit-input`** (passes `units/separate/min/max/step`), and
  **`predefined-colors-color-picker-compact`** / `compact-color` (passes `picker`/`choices`, so a
  colour child stays palette-linked). Extend the `view.php` switch to add more.
- **Config / value shape:** the option's `fw_multi_options` map holds each child's `{type, title, …}`;
  the saved value is an assoc array keyed by child key, each value in that child's own native shape
  (unit-input `{value,unit}`, compact color `{predefined,custom}`, select scalar).
- **Two save-correctness fixes are baked in — replicate them in any composite control:** `view.php`
  routes each child through its OWN option type's `render()` (so nested `unit-input`/compact-color
  enqueue their own JS/CSS — else the unit dropdown never saves), and the value comes back through
  each child's own `get_value_from_input` (else a nested value is stored as a raw JSON string). A
  hand-rolled `<input>` grid would hit both bugs.
- **CSS layout gotcha:** the stylesheet is namespaced `.fw-backend-option-type-multi-inline`
  (derived from the option-type id). When you clone an option type, the copied CSS still targets the
  OLD id and silently no-ops (the "why are my inline fields stacking?" bug) — rename the selectors.

## Spacing standard

A demo's spacing is mostly **section vertical rhythm** (the big top/bottom padding on each band).
A Section expresses that through its **Top Spacing / Bottom Spacing** options
(`padding_top` / `padding_bottom`, `sc_spacing_field`) — dropdowns that store a spacing-scale
utility class (e.g. `pt-sectionlarge` → `padding-top: var(--spacer-sectionlarge)`) referencing the
Theme Settings **Spacing Scale** (`spacing_scale`, Components → Spacing). Column gaps use the
Section **Gap** option against the **Gap Scale** (`gap_scale`).

Procedure (mirrors the colour standard — curate, don't dump):

1. **Inventory the source's rhythm**: section vertical padding (Tailwind `py-*`), column gaps
   (`gap-*`), card padding. Read the real values from the source.
2. **Curate the Spacing Scale to cover it.** The default scale tops out at **5rem**
   (`0,.25,.5,1,1.5,3,3.5,4,4.5,5rem`), so common landing rhythms (`py-24`=6rem, `py-32`=8rem)
   aren't there — ADD **role/rhythm-named** spacers (not raw numbers), e.g. `Section`=6rem,
   `Section Large`=8rem, via `fw_set_db_settings_option( 'spacing_scale', $scale )` (merge/append).
   Emits `--spacer-{slug}` + `.p-/.py-/.pt-/.pb-{slug}` utilities.
3. **Set each Section's Top/Bottom Spacing** in the tree (`padding_top`/`padding_bottom` = the
   `pt-{slug}`/`pb-{slug}` class). Set column gaps via the Section Gap option. Leave a hero's
   full-height (`min-height:100vh`) and its fixed-nav clearance in the child theme — that's layout,
   not rhythm. **Card padding belongs to the Box Preset, not here.**
4. **Strip** the `padding-block` / section-padding rules from the child theme; verify the render.

**Slug gotcha:** the spacing-scale slug **CONCATENATES** multi-word names (`sc_sanitize_class`):
`Section Large` → `sectionlarge` (NOT `section-large`) — unlike colour slugs which hyphenate. The
dropdown value and the emitted class agree, so it works; just expect the concatenated slug (or use
single-word names). *(Candidate framework fix — see below.)*

**Responsive section spacing (shipped, shortcodes 1.10.64).** The Section's **Top Spacing / Bottom
Spacing / Gap X / Gap Y** are now per-device (Phone/Tablet/Desktop tabs), matching the existing
responsive Gap. In a builder tree, pass `padding_top`/`padding_bottom` (and `gap_x`/`gap_y`) as
`{ base, md, lg }` where each layer is the utility class (`base` applies at all widths; md/lg
override from that breakpoint up — the view/`sc_apply_styling_classes` inject the infix, e.g.
`pt-3` → `pt-lg-3`). A legacy scalar still folds into `base`. Use this to keep big section rhythm on
desktop while staying lighter on phones — e.g. Senkei features/pricing = base `pt-section` (6rem) →
lg `pt-sectionlarge` (8rem). Only reach for FIXED (scalar) padding when the source is genuinely
non-responsive.

**Senkei (reference):** added `Section`=6rem + `Section Large`=8rem; features/pricing =
`pt-sectionlarge`/`pb-sectionlarge` (8rem), worlds = `pt-section`/`pb-section` (6rem), credit =
`pt-5`/`pb-5` (3rem).

### Element margins (not just section rhythm)

Beyond section padding, an ELEMENT's own top/bottom margin (a source `mb-10` gap below a subtitle)
comes from its **Spacing** option (Styling tab), which stores spacing-scale UTILITY CLASSES
(`mb-{slug}` / `mt-{slug}`) — there is **NO custom-value mode**. So if the source's exact value isn't
in the scale, ADD a scale step (same as colours). The composite `spacing` att =
`{margin:{top,bottom,…}, padding:…, advanced:{md,lg}}`; each slot value is the FULL class
(`mb-block`), blank = none.

- The default scale skips 2rem-ish / 2.5rem, so `mb-8`(2rem)=`Card`, and `mb-10`(2.5rem) needed a new
  **Block**=2.5rem step. Add it, then set `spacing.margin.bottom='mb-block'`.
- Match the source PER-element (h1 `mb-6`, p `mb-10`) rather than a uniform column gap when the source
  uses different margins.

**Header block (heading + subtitle) — SPLIT for precise spacing.** A source section header
(`<div class="mb-20 text-center"><h2 class="mb-6">…</h2><p class="max-w-2xl mx-auto">…</p></div>`) is
best built as a **special_heading (title only) + a separate text_block (subtitle)**, so each gap is an
exact Spacing-Scale value (heading `mb-6`=1.5rem, block `mb-20`=5rem) instead of the special_heading's
coarse `element_spacing` presets (tight/normal/relaxed = 0.25/0.5/1rem — none hit 1.5rem). Subtitle
gets its own `max_width` + Muted colour + `text_align:center`.

**Header ROW — heading-block LEFT + link/button RIGHT (`flex justify-between items-end`).** A source
header with a title-block on the left and a "View all →" link on the right is done in **ONE column,
natively — no child CSS**: set the column's **Content Direction = Row** (`content_direction:'row'`,
lays elements inline), **Content Alignment = Space Between** (`content_h:'between'` → `justify-content:
space-between`), and **Content Vertical Alignment = Bottom** (`content_v:'end'` → `align-items:flex-end`).
Put the heading (special_heading title+subtitle) + the link (a `btn-link` button with an `arrow-right`
icon) in that column. The column also exposes **Gap** (`content_gap`) + a bottom margin.
> **special_heading grouping gotcha:** in a Row column, the heading + link must each be ONE flex item.
> But a special_heading only wraps its title+subtitle in a `.heading` container when
> `sc_needs_wrapper` fires — otherwise it renders them as BARE siblings, so the row's space-between
> spreads THREE items (title / subtitle / link) instead of two. Force the wrapper by giving the
> special_heading a **CSS Class** (a class/id always produces the wrapper), so its title+subtitle stay
> a single left-hand block.
> **special_heading title-margin gotcha:** even title-only, the `.heading-title` carries a built-in
> ~8px bottom margin (from `element_spacing`), which ADDS to the element's own bottom margin. So to
> land a 24px (`mb-6`) heading→subtitle gap, use **`mb-3` (16px) + the 8px = 24px**, not `mb-4` (24px,
> which measures 32px). Measure the rendered gap, don't assume the class value is the whole gap.

**Element alignment — match the source; don't leave the centered default.** icon_box's
`icon_align` / `title_align` / `content_align` default to the layout's centred look, but many source
cards are **LEFT-aligned** (icon circle top-left, title + body left). Set all three to `left` (the
factory's `align` param) — a centred card when the source is left is a fidelity miss the computed-style
diff catches (`getComputedStyle(title).textAlign`).

**icon_box inside a card — double padding.** `.icon-box__wrapper` carries a baked-in `padding: 1.5rem 0`
(24px top/bottom). Inside a Box-Preset card (which already provides `p-8`/`p-card` padding) that
DOUBLES the vertical inset and the card reads too tall. Zero it via the icon_box's Custom CSS.
> **Scope-class-on-the-BEM-wrapper gotcha:** icon_box puts its `.u{hash}` scope class ON the
> `.icon-box__wrapper` element itself (`class="icon-box uXXXX icon-box__wrapper …"`), so
> `selector .icon-box__wrapper` (DESCENDANT) matches nothing — use **`selector.icon-box__wrapper{padding:0}`**
> (compound, same element). General rule: some shortcodes stamp the scope class on their root BEM
> element, so a `selector .foo` may need to be `selector.foo` (or just `selector`). Check the rendered
> markup for where `.u{hash}` lands.

### Spacing component — improvement candidates (framework)

Flag these when relevant; don't silently work around them:

1. ~~**Responsive section padding**~~ — **SHIPPED (shortcodes 1.10.64).** Top/Bottom Spacing + Gap
   X/Y are now per-device (see the Responsive section spacing note above).
2. **Slug consistency.** Spacing slugs concatenate; colour slugs hyphenate. Normalize all preset
   slug derivation to ONE helper (hyphenate) for consistency — but mind back-compat (existing
   multi-word spacers would change slug; needs a migration or forward-only application).
3. **Richer defaults.** Ship a couple of larger section-rhythm spacers (6rem, 8rem) + a 2rem gap in
   the DEFAULT scales, so common landing rhythms need no additions.

---

## Logo strips / "trusted by" brand rows

A "trusted by [logos]" strip = the **logo-grid** shortcode — NOT `icon_box` (it stacks icon-over-
title, is heavy ×N, and its Lucide icons have no brand logos). logo-grid natively does the
grayscale→colour hover, gap, columns, and logo height.

**Inline SVG marks — the right way (shortcodes 1.10.76+).** WordPress BLOCKS SVG *uploads*, but
logo-grid's per-logo **SVG Markup** field renders pasted `<svg>` INLINE (sanitised via
`sc_icon_sanitize_svg`) — crisp, recolourable, self-contained, and its text uses page fonts. Do **NOT**
rasterize to PNG, and do **NOT** require the Safe SVG plugin (a demo dependency, and an uploaded
`<img src=svg>` can't use page fonts). This mirrors how icon-v2 stores icons inline.

- **Fetch brand marks** from simple-icons: `https://cdn.simpleicons.org/{slug}/ffffff` (white) or
  `https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/{slug}.svg` (raw — add a fill). Read them
  into the tree (Node `readFileSync`, collapse whitespace) and put the markup in each logo's `svg`.
- **KEEP the native square `0 0 24 24` viewBox — do NOT tight-crop it to the artwork bounds.** Iconify
  (and the source) render every icon at 1em SQUARE, so a wordmark (Wacom) sits letterboxed small in
  its square and is the SAME WIDTH as the others. Tight-cropping the viewBox to the artwork makes a
  wordmark render as a wide box, DIVERGING from the source. *(This bit us twice: "normalize" → wide
  Wacom; reverting to square viewBoxes matched the source.)*
- **Options:** `show_labels` (render each Name as a text label beside the mark — the source's
  `<Icon/> + text`), **Logo Color** (`text_color`, compact preset → colours labels + `currentColor`
  marks), `no_label` per-logo (suppress a wordmark's duplicate label — but the SOURCE often shows
  both, so MATCH the source). Grayscale fades the whole item so labels dim with their marks.

**Framework fixes that make inline SVG actually render (all shipped, shortcodes 1.10.77–79) — and are
the standard SVG gotchas:**
- **viewBox case:** `wp_kses` lowercases `viewBox` → `viewbox`, which browsers IGNORE (collapsing the
  aspect ratio). `sc_icon_sanitize_svg` now restores `viewBox` / `preserveAspectRatio` case. Affects
  ANY pasted inline SVG, not just logos.
- **max-width collapse:** an inline svg is a blockified flex item; a global `svg{max-width:100%}`
  creates a circular width constraint that shrinks a **square-viewBox** mark to a sliver (a
  non-square one survives). logo-grid sets `max-width:none` on inline marks. *(This was the "Unreal
  Engine renders 7×26px" bug — nothing to do with the viewBox itself.)*
- **Consistent gaps:** the grid uses `grid-template-columns: repeat(N, auto)` + `justify-content:
  center` — content-width columns, centered, ONE fixed gap — NOT equal `1fr` cells (which center
  varying-width logos in equal cells → uneven-LOOKING gaps). This matches the source's `flex
  justify-center gap-N` row.
- **Canvas `title_template`:** inject `fill:currentColor` + a set height onto each `<svg>` (a white
  frontend mark is invisible on the light builder canvas) and wrap each logo in a `white-space:nowrap`
  span so multi-word names stay on one line.

*(Senkei "Trusted by": an **Eyebrow** Text-Style label + logo-grid with Unreal/Unity/Blender/Wacom/
Adobe simple-icons marks, Show Names on, White Logo Color, grayscale, height 22, SQUARE viewBoxes.)*

---

## Component robustness gotchas (gallery crop, btn-link)

- **Gallery crop vs a theme `img{height:auto}` reset.** The gallery's cropped designs (`grid` with a
  ratio, `metro`, …) size each cell with `aspect-ratio` + `overflow:hidden`, and the `<img>` fills it
  via `object-fit:cover; height:100%`. A theme's ubiquitous `#content img { height:auto }` (ID
  specificity 1,0,1) BEATS the gallery's `.fw-gallery__media img` (0,1,1), collapsing the height so
  a LANDSCAPE image letterboxes (short, empty space) instead of filling the square. Fixed
  framework-side with `height:100% !important` on the cropped img (shortcodes 1.10.80). **For a
  gallery, use the CLOSEST design — don't pixel-match the source.**
- **Featured / dominant tile → the Grid design's `Column Ratio (Desktop)` control (shortcodes
  1.10.82), NOT `metro` or scoped CSS.** A source gallery with one bigger, dominant image (a
  `col-span-2` cell, a hero-tile-plus-thumbnails layout) maps to the **`grid`** design with an
  UNEQUAL Column Ratio split-slider (e.g. `1 : 2 : 1` = middle tile twice as wide). Internals:
  the template emits `--gal-tpl` (fr-unit `grid-template-columns`) only when the split is
  meaningfully unequal (>2% spread — an even split falls back to `--gal-cols`); the widest tile
  keeps its `aspect-ratio` to define the row height, and the narrower tiles get
  `fw-gallery__media--fill` so they stretch to that same height (portrait crop, no dead space
  under a square side-tile). The `metro` design gives a fixed, non-adjustable featured cell — the
  Column Ratio control is the tunable, source-matching route.
- **btn-link hardcodes underline + a blue hover.** `.btn-link { text-decoration:underline }` +
  `.btn-link:hover { color:#0a58ca }` are baked into the button CSS; a Link Button-Colour preset
  re-points the base colour but NOT those. For a source text-CTA link (no underline, brand colour,
  hover-lighter), override in the button's **Custom CSS**: `selector{text-decoration:none}
  selector:hover{color:var(--color-primary-light)}` — or set the Link preset's hover state +
  a `{{SELECTOR}}{text-decoration:none}` preset Custom CSS (tier-1, all links at once).

## Site chrome — header + footer via Theme Settings (REQUIRED, and I kept missing details)

The header + footer are **site chrome** = the parent theme's **Header/Footer Theme Settings**, NOT a
child `header.php`/`footer.php` and NOT page-builder content. Configure them on the (sub)site with
`fw_set_db_settings_option()` (see `wordpress/demos/anime-header-footer.php` — the canonical recipe),
then **delete the child `header.php`/`footer.php`** and their dead `.sk-nav`/`.sk-foot` CSS/JS so the
parent renders the chrome. Building the Senkei nav I re-missed the SAME kind of detail over and over
(font size, exact link colour, the CTA style, the header tint) — so the rule is:

> **EXTRACT every chrome sub-element's computed style from the source and set it explicitly — never
> assume the theme default matches.** For the nav that means, per element: font-size, colour (exact
> hex, not "muted-ish"), hover colour, weight, and whether a control is a *link* or a *button* and
> *outline* vs *solid*. A quick DevTools/computed-style read of the source nav catches all of it.

**Header conversion checklist (map source → Theme Settings):**
- **Behavior / background.** A fixed bar that *overlays* the hero → `header_layout.header_behavior =
  'transparent-overlay'` (+ `header_glass='yes'` for `backdrop-blur`). **Gotcha:** transparent-overlay
  forces the outer `.site-header` transparent at the top, so a solid `bg_color` only shows once stuck.
  The source's *translucent tint at the top* (e.g. `bg-[#020617]/40`) is the **Main Header Background**
  (`header_main.main_custom_styling` → `main_background`, an rgba) — it renders on the inner
  `.header-main` (full-width block) so it shows even under transparent-overlay. **Keep
  `main_container='container'`** — `'full'` makes the *content* full-bleed and jams the logo to the
  edge (the background is full-width regardless of the container).
- **FLOATING GLASS PILL header — neutralise the parent theme's STUCK background (REQUIRED for pill
  designs).** When the source header is a floating rounded pill (a translucent glass bar inset from
  the edges, page showing around it), the parent theme's **glass sticky mode** fights it: once the
  header sticks, the theme paints **`.site-header` ITSELF** full-width (≈`rgba(bg,.95)` + its own
  `backdrop-filter`). That's TWO bugs from one cause — (1) a full-width bar appears OUTSIDE the pill,
  and (2) it sits directly behind the pill, so the pill's own `backdrop-filter` blurs *that near-solid
  bar* instead of the page and the pill reads as **solid, glass gone**. **Fix:** in the chrome CSS,
  force `.site-header` transparent in EVERY stuck/scrolled state so only the pill container is glass
  (blurring the page): `.site-header,.site-header.is-stuck,.site-header.sticky,.site-header.is-scrolled,
  .site-header.scrolled{background:transparent!important;backdrop-filter:none!important;box-shadow:none
  !important;border:0!important}`. Note the real stuck class is **`.is-stuck`/`.sticky`**, NOT
  `.is-scrolled` — target both. Then give the STUCK pill a touch more tint than at the top so the nav
  stays legible over scrolling content. **Debugging tell:** scroll the demo, read `getComputedStyle`
  of `.site-header` — a non-transparent `backgroundColor` (or a `backdrop-filter`) on the OUTER header
  = the full-width bar; the pill (`.header-main .fw-container`) should be the only glass surface.
- **Logo.** Icon + wordmark → the native **Logo Icon** (`header_logo.logo_icon` icon-v2 +
  `logo_icon_position`/`_color`/`_size`) beside `site_title` — do NOT bake text into an SVG. Set
  `title_weight`, `color`, and hide the tagline with `header_logo.tagline = ' d-none'` (the "Hide
  Tagline" switch stores that class, not `'yes'`). Uppercase/tracking wordmark → `misc_custom_css`
  (`.site-title{text-transform:uppercase;letter-spacing:…}`).
- **Menu.** WP menu (custom links → `#anchors`) on the `primary` location. Colour EXACTLY (source
  `text-slate-300` = `#cbd5e1` near-white, NOT slate-400 `#94a3b8`) via `header_menu.menu_link_color`
  + `menu_link_hover_color`. **Font size:** `header_menu.menu_link_font_size` (theme default is a small
  12px; source `text-sm` = `0.875rem`/14px — set it).
- **Header CTAs** (`header_main.main_right`). Each is a `cta_button` element whose `cta_style` = a
  button preset (`btn-primary`/`btn-link`/…). A text "Sign in" link → a `btn-link` **button-link**
  (not an `icon_text` element); style it muted→white for a dark header. A pill/outline CTA (source
  `border-white rounded-full … hover:bg-brand`) has **no white-outline preset and no shape control on
  the header CTA**, so ride a scoped rule on its own class: `.site-header .header-cta-btn.btn-primary{
  background:transparent;border:1.5px solid #fff;border-radius:999px}` + `:hover{background:var(
  --color-primary)}`. Distinguish the two CTAs by their style class (`.header-cta-btn.btn-primary` vs
  `.header-cta-btn.btn-link`), and note `element_css_class` does NOT reach the header CTA's `<a>`.
- **Hover underlines.** The theme USED to underline every link on hover (a global
  `a:hover{text-decoration:underline}`) — over-broad, it hit nav/footer/social/buttons. Removed in
  theme 2.3.59: nothing underlines on hover by default. CONTENT/prose links get their hover underline
  from the dedicated **Body Link Underline** system (`--body-link-decoration-hover`, Typography → Body
  Link, default `underline`; scoped to `.entry-content a:not([class])`); chrome + buttons signal hover
  by colour/fill. Source sites almost never underline chrome on hover, so this is now the default — no
  per-site override needed.
- **Non-destructive setup script.** Seed the menu ONLY when empty (preserve hand-added items), and
  MERGE `header_main`/`header_logo`/`header_menu` (read-modify-write) — a full replace wipes the
  user's Main Header Background / added elements (this bit me twice).

**Footer:** dark bg via `footer_background` — **it's a `background-pro` (color/gradient/image layers),
NOT the compact color shape**: set `array('color'=>array('value'=>array('custom'=>'#020617')))` or it
falls back to the theme's `--footer-bg` default (`#1a1a1a`). `footer_text_color`/`footer_link_color`
ARE compact colours. Copyright columns render as Bootstrap `.fw-col-md-*` — right-align the last
column (`.footer-section--copyright .fw-col-md-6:last-child{text-align:right}`) for a left-copyright /
right-status bar. A simple strip =
the **copyright** section (`copyright_settings.enabled='yes'`, `copyright_columns` count 1, a `text`
element with `&copy; {{current_year}} …` + credit `<a>`). Center it via `misc_custom_css`
(`.footer-section--copyright{text-align:center}`). Richer footers use `main_footer_columns` (the
footer-columns multi-picker + split-slider). **Fifths footer (source "5-col grid, brand spans 2"):**
the twelfths split-slider snaps `40/20/20/20` → `[4,3,3,2]` (33/25/25/17), so use the **5-column
choice's fifths image-picker** — `main_footer_layout` = `f5-2-1-1-1` (2/5 + 1/5 + 1/5 + 1/5). It maps
to `fw-col-sm-25` + three `fw-col-sm-15` and the render sets the real column count (4) from the
composition. Set `count: '5'` + the `_layout` key + only the columns you fill.

**Converter TODO (wire into the no-AI path — PHP `Mapper` + JS `to-pages`):** detect the source
`<header>`/`<nav>` and EMIT these settings (behavior, glass, main-header tint, logo icon+text, menu
colour+size, CTA outline-vs-solid) instead of leaving the theme defaults — and the source `<footer>`
→ `footer_*` + `copyright_settings`. The checklist above IS the mapping spec.

**Per-bar/section borders** (a hairline under the header, a rule above the footer, an edge on any
Custom-Styling section) use the **combined Border control** — one `multi-inline` Width·Style·Color
row + `border_sides` (which edges) + `border_extent` (how far top/bottom run). See *Section Styles
standard → The combined Border control* above; the chrome fields are the same trio via
`unysonplus_hf_border_row_field` / `_sides_field` / `_extent_field`, grouped under `_grp_borders`.

## Framework capabilities added while building demos (use them; don't rebuild them)

Each demo tends to surface a missing framework capability — when a COMMON need has no home, BUILD it
into the framework rather than hardcoding child CSS (that's the whole point). Built so far:

| Capability | Where | Version |
|---|---|---|
| **`multi-inline` option type** (N child controls on ONE horizontal row — e.g. Width · Style · Color — captions BELOW each, stacking vertically ≤782px). Child types: short-text/text/color/rgbacolor/short-select/select/**unit-input**/**compact color preset**. Value = assoc array keyed per child, each in its own native shape. Config in `fw_multi_options`. The shared primitive under every combined "border" row below. Supersedes the off-convention `fw-multi-inline`. | core `includes/option-types/multi-inline/` | core 2.15.5 |
| **Section Styles — combined Border** (the `border` multi-inline row `{width,style,color}` + **`border_sides`** multi-edge image-picker + **`border_extent`** full/container/custom). Replaces the flat `border_style`/`_width`/`_color` leaves; `border_extent` renders top/bottom as a centered `::before`/`::after` when not full-width. Legacy-fold normalizer + init-pri-21 migration keep old presets rendering. | shortcodes components-section-styles + core section-style-presets + css-tokens | core 2.15.5 / shortcodes 1.10.94 |
| **Header/Footer Custom-Styling Border** (same trio — `unysonplus_hf_border_row_field` + `unysonplus_hf_border_sides_field` + `unysonplus_hf_border_extent_field`, grouped in `_grp_borders` for header main/topbar/bottombar AND footer sections + Footer Layout). `unysonplus_hf_normalize_sides` + two admin_init migrations fold legacy per-side/string shapes. | theme header-footer-option-helpers + hf-custom-css | theme 2.3.74 |
| **`medium-select` option type** (select sized between full `select` and `short-select` — `fw-option-width-medium`, 50% / min 300px) | core `includes/option-types/simple.php` | core 2.14.93 |
| **Footer fifths ratio** (5-column footer → image-picker of fifth compositions: `1/5×5`, `2/5+1/5+1/5+1/5`, `3/5+1/5+1/5`, … via `fw-col-sm-15/25/35/45`). Lets a source "5-col grid, brand spans 2" (`2/5+1/5+1/5+1/5`) render EXACTLY — the twelfths split-slider can't. The render sets the real column count from the composition's parts. | theme footer-builder + header-footer-option-helpers | theme 2.3.61 |
| **Logo Icon** (native icon + wordmark — `logo_icon`/position/color/size on Header → Identity; inline-SVG mark via `sc_icon_render` beside the real text title). The modern AI-site "icon + text" logo WITHOUT baking text into an SVG — keeps the wordmark editable/accessible. Converter should emit this for `<icon> + <text>` logos. | theme header-identity + `unysonplus_logo()` | theme 2.3.56 |
| **Menu Font Size** (`header_menu.menu_link_font_size` → `--menu-link-font-size`; the nav had colour/padding but no size control — theme default was a small 12px) | theme header-menu + theme-vars + style.css | theme 2.3.57 |
| **Gallery Columns** (single "Columns" control; grid = footer-style multi-picker → locked N-pane ratio slider for a featured tile; tablet/phone auto-derived) | gallery (grid design) | shortcodes 1.10.83 |
| **Text Styles** (weight/lh/tracking/transform, size-optional) — Components → Text Styles (was Font Sizes) | shortcodes + core css-tokens | shortcodes 1.10.74 / core 2.14.82 |
| **Display-size weight preservation** (Display sizes change size only) | special-heading CSS | shortcodes 1.10.73 |
| **sc_needs_wrapper honors custom_css** (element-only Custom CSS renders its wrapper) | shortcode-styling-helper | shortcodes 1.10.72 |
| **Heading Max Width** (`block_max_width`) — constrain a hero heading like `max-w-4xl` | special_heading | (existing option) |
| **Button Shape** (pill/rounded/square radius override) | button | shortcodes 1.10.65 |
| **Responsive section spacing** (Top/Bottom/Gap per-device) | section | shortcodes 1.10.64 |
| **Logo Grid inline SVG + Show Names + Logo Color + no_label + auto-column gaps** + viewBox/max-width sanitizer fixes | logo-grid + `sc_icon_sanitize_svg` | shortcodes 1.10.76–79 |
| **Spacing scale steps** added per demo (Section / Section Large / Card / Block) | Components → Spacing | (demo data) |

Before hand-rolling a control or child CSS for a COMMON need, check this table + the sections above —
it may already exist, or belong as a small framework addition.
