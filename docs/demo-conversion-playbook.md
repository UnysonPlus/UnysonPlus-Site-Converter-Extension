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
`page.tsx`/HTML, the real `video.mp4` + images, and a full-page reference screenshot).
ALWAYS use these:

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

### Hero heading width (source `max-w-4xl`)

A page-builder **column has no max-width option**, so DON'T scope `.fw-col-*` in child CSS. Each
element carries its own: special_heading's **Heading Max Width** (`block_max_width`) constrains the
title (e.g. `max-w-4xl` = 896px → emits `max-width:896px;margin-inline:auto`), text_block's **Max
Width** (multi-picker; custom = `{preset:'custom',custom:{custom_width:{value,unit}}}`) constrains a
paragraph. Since a hero is text-centered, per-element max-widths read identically to one wrapper —
with zero child CSS.

### Section container width (source `max-w-7xl`)

The source's content bands (`mx-auto max-w-7xl` = 1280px) are WIDER than the theme's default boxed
container (1170px), so the demo reads narrower. This is the GLOBAL **Container Width** (General →
Layout → Container Width, responsive Phone/Tablet/Desktop) → drives `--container-max-desktop` consumed
by every `.fw-container`.
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

1. **Get the source's font families** (from `page.tsx`'s Tailwind `fontFamily` / CSS `font-family`
   / `@font-face`, or computed). Set **Body Font** + **Heading Font** families. A Google font
   auto-enqueues on save **only if it's in `fw_get_google_fonts()`** — check first; if absent,
   register it self-hosted via **General → Typography → Custom Fonts** (`custom_fonts`).
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
   child theme. Equal-height stays as a tiny layout rule (`.boxp-{slug}{height:100%}`) — that's layout,
   not a preset property.
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

## Framework capabilities added while building demos (use them; don't rebuild them)

Each demo tends to surface a missing framework capability — when a COMMON need has no home, BUILD it
into the framework rather than hardcoding child CSS (that's the whole point). Built so far:

| Capability | Where | Version |
|---|---|---|
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
