# SEO, Performance & Accessibility Standards — site creation + conversion

The checklist we hold every **created OR converted** UnysonPlus site to, so it keeps a high
PageSpeed / GTmetrix score and passes the common Lighthouse **Performance / Accessibility / SEO**
audits. Each item names the audit it satisfies **and the framework lever that already does the
work** — so the fix is "use the built-in", not "hand-write CSS".

> Companion docs: the [site-conversion playbook](./site-conversion-playbook.md) (how a source site
> becomes theme-settings + shortcodes), the workspace `CLAUDE.md` (heading-order, descriptive-link,
> clean-DOM rules), and the agent-readiness work (structured data / ARIA). This doc is the
> **score-keeping** layer that ties them together.

---

## 0. Ship gate (run before you call a site "done")

Run Lighthouse (or PageSpeed Insights / GTmetrix) on **three** representative URLs — the **home**, a
**review/inner page**, and a **blog post** — and clear these before shipping:

| Category | Target | The usual offenders |
|---|---|---|
| Performance | ≥ 90 mobile | over-fetched images, render-blocking CSS/JS, no caching/CDN, layout shift |
| Accessibility | 100 | **color contrast**, **links distinguishable by color only**, heading skips, missing alt, bad ARIA |
| SEO | 100 | missing meta description / canonical, no structured data, non-crawlable, tiny tap targets |

> **Audit the environment the code is actually deployed to.** Lighthouse findings reflect the
> *deployed* build — an audit of the production/live site keeps re-flagging issues that were already
> fixed locally until the plugin/theme is re-uploaded. Before re-fixing any finding, first check the
> local render + the source (`curl http://localhost/<site>/ | grep …` + a grep of the working copy);
> if both are clean, the finding is stale and the fix is **deploy**, not more code.
>
> **And mind the THREE cache layers that can replay pre-fix markup even after deploying:** (1) the
> host page cache (WP Engine — purge it after an upload); (2) the asset-optimizer combined CSS/JS
> (clears on next visit after its cache reset); (3) **shortcode output transients** — any cached
> rendered HTML **must include the code version in its cache key** so a plugin update invalidates it
> instantly (the posts shortcode's `cache_output` did not, and kept serving pre-fix
> `role="listitem"` cards after the ARIA fix shipped; its key is now salted with the extension
> version — copy that pattern in any new render cache). And a fourth, subtler one: **editing
> theme settings via a direct `update_option()` (scripts/wp-cli) bypasses the settings-save hook
> that regenerates the tokens/presets CSS** — the old values survive inside the combined bundle
> and keep winning. Any script that edits `fw_theme_settings_options:*` must also delete the
> generated files (`uploads/unysonplus/unysonplus-generated.css`, `presets-*.css`, and the
> `unysonplus-asset-optimizer/*` bundle) so they rebuild — they all regenerate on the next visit.

Quick source-level checks (no browser needed):

```bash
curl -s <url> | grep -oE '<h[1-6]'        # heading order — must descend without gaps
curl -s <url> | grep -c 'alt='            # images should all carry alt (fw_image_tag always emits it)
curl -s <url>/llms.txt                     # crawl entry point present (crawl-signals.php)
curl -s <url> | grep -oE '"@type":"[A-Za-z]+"' | sort -u   # structured-data coverage
```

---

## 1. Performance (PageSpeed / GTmetrix)

- **Image delivery — always `fw_image_tag()`.** It emits `srcset` + `sizes` + intrinsic
  `width`/`height` + `loading="lazy"` and picks the right crop. Pass an **accurate `sizes`** so the
  browser doesn't over-fetch (the reviews-table logo bug: a `sizes=300px` fetched a 640 original on
  retina). Hero/LCP image only: `loading="eager"` + `fetchpriority="high"` (+ a `<link rel=preload>`).
  Decorative images get `alt=""`. Prefer WebP originals.
  - **A `sizes` attribute is useless without candidates.** `wp_get_attachment_image_srcset('full')`
    only offers renditions WP has already generated — an original SMALLER than the registered sizes
    (typical for a logo) has **none**, so 1x screens silently download the full file. Generate the
    display-size renditions on demand with **`fw_resize()`** (the header-logo srcset now does this:
    120/140/240/280w capped at the original). Requires GD/Imagick — note **XAMPP ships with
    `;extension=gd` commented out**; enable it locally or fw_resize/media-import silently can't work.
  - **Brand logos: compress with *lossless* WebP, and MEASURE before accepting lossy.** The
    newbingosites logo: 40 KB PNG → **26.5 KB lossless WebP at zero visible difference**; both
    palette-quantized PNG *and* q90 lossy WebP showed real artifacts on its gradients (up to 87/255
    visible diff). Check fidelity with an **alpha-weighted (premultiplied) pixel diff** — raw RGB
    diffs on transparent pixels are invisible noise and overstate the damage.
- **Render-blocking CSS/JS.** Let the **asset-optimizer** extension combine + minify. Keep
  above-the-fold CSS small; defer non-critical JS; never add a per-component stylesheet when the
  rule fits the child theme's main sheet (the "why another stylesheet" rule).
- **Fonts.** Preconnect to the font host, limit weights, `font-display: swap`, avoid `@import`
  chains. Self-host where practical.
- **Kill dead weight.** Use the **performance toggles** (Theme Settings → Misc): drop
  `jquery-migrate`, emoji script, embeds. Remove unused vendor CSS/JS.
  - **Icon fonts / kits: if only a handful of glyphs are used, inline them as SVG and drop the
    kit.** The Font Awesome kit cost ~12 KiB injected CSS + kit JS + webfonts + a third-party
    request chain — for THREE star glyphs (newbingosite). Replaced with inline SVGs of the same
    geometry and disabled via the theme's `unysonplus_fontawesome_kit_url` filter
    (`__return_empty_string`). Audit any icon kit with DevTools coverage before shipping.
    **Since theme 2.3.139 the FA kit is OPT-IN** (off by default) — enable via the
    `UNYSONPLUS_FONTAWESOME_KIT` constant or the filter only on sites that genuinely use FA icons.
- **Caching + CDN.** Enable a page cache and a CDN (e.g. WP Engine CDN) with long-lived cache
  headers on static assets — this is the single biggest GTmetrix lever and is a **hosting** setting,
  not code.
- **Critical request chains / LCP.** Preload the LCP image + primary font; minimize chained requests.
- **CLS = 0 — every `<img>` needs explicit `width` + `height`.** Three layers already cover this
  automatically; know which one owns each image:
  - **Shortcode/theme images** → `fw_image_tag()` emits width+height+srcset.
  - **Editor / WYSIWYG images** → WordPress core adds them (`wp_filter_content_tags`).
  - **Custom-HTML header/footer elements + badge/logo strips** → the parent theme's
    **`unysonplus_img_add_dimensions()`** (in `inc/includes/header-builder.php`) now auto-injects
    width/height into any local `<img>` that lacks them, resolving the intrinsic size from the file:
    an SVG's root `<svg width/height>` (else its `viewBox`), a raster's `getimagesize()`. It **mirrors
    how the browser derives intrinsic size**, so the reserved box always matches — CSS still controls
    the display size, the look is unchanged. *This closed the newbingosite footer trust-badge strip
    (`.nbs-badges > img`) — 8 SVGs flagged "Image elements do not have explicit width and height".*
  - **Any OTHER raw `<img>`** a build script/importer emits: add `width`/`height` yourself — for an
    SVG use the root `<svg width/height>`, else the `viewBox` w/h (`viewBox="0 0 652 189"` →
    `width="652" height="189"`). Don't hand-guess an SVG that has a `viewBox` different from a declared
    size — read what the browser reads (declared first, else viewBox), which is what the filter does.
  - **Quick check:** `curl -s <url> | grep -oiE '<img[^>]*>' | grep -v 'width=' ` should return nothing.
  - Reserve space for embeds/ads too; never inject above-content layout after load.

## 2. Accessibility (the audits that keep recurring)

- **Color contrast ≥ 4.5:1** (normal text) / **3:1** large text (≥ 24px, or ≥ 18.66px **bold**) /
  **3:1** for UI components + graphical objects. See §4 for the math + minimum shades.
  - **Buttons & badges:** white text needs a background dark enough (relative luminance **≤ 0.175**).
    On a **bright brand color** (amber `#f59e0b`, green `#16a34a`, …) white text FAILS — use **dark
    text** on the bright fill instead (amber CTA + near-black label is the canonical pattern), or
    darken the fill to the 700/800 shade. *This is exactly the newbingosite "PLAY NOW" (white on
    amber ≈ 2.15:1) + rank-badge (white on green ≈ 3.3:1) failure — fixed with dark text on the amber
    and the darker `--bcr-accent-dark` green behind the white badge.*
  - **Muted / meta text** (dates, bylines, captions) on white must be **≥ `#767676`** (4.5:1). A
    lighter grey (`#9aa4b2`, `#999`) fails.
  - **The site's own Color Presets must be AA too** — a palette entry cascades everywhere its
    slug is consumed (`--color-<slug>` vars, `text-/bg-` classes, theme tokens like
    `--color-muted`). *A "Muted" preset of `#adb5bd` (2.07:1!) silently failed every post-card
    date/byline on newbingosite — fixed by darkening the preset itself (`#5f6773`, 5.72:1), which
    fixed every consumer at once.* When defining a palette (or reviewing the converter's contrast
    report), check each preset intended for TEXT against the backgrounds it will sit on.
  - **Dark-surface text** (footers, dark cards): light muted colors need the same math against the
    dark fill — e.g. `#9d90a9` on `#4e2660` is 3.95:1 (fails); `#b7abc3` passes at 5.44:1.
- **Links must not rely on color alone.** Inline text links (inside a paragraph — T&C links,
  "BeGambleAware", affiliate-disclosure) need a **non-color indicator: an underline** (and ≥ 3:1
  contrast against the surrounding body text). Standalone links / buttons / nav items that are
  visually obvious are exempt. Rule of thumb: **if a link sits inside a run of text, underline it.**
  - **The framework lever: Theme Settings → Typography → Body Link Underline.** Since theme
    2.3.139 the DEFAULT is `always` (in-text links underline at rest), so new sites pass the
    `link-in-text-block` audit out of the box; `hover`/`never` remain explicit opt-outs — flipping
    to them re-fails the audit.
  - **Don't patch this with component CSS.** The theme's prose-link rule
    (`.entry-content :is(p,li) a:not([class])`, specificity 0,2,2) intentionally out-ranks component
    underline rules and reads `--body-link-decoration` — a `.my-block a { text-decoration:underline }`
    (0,1,1) silently loses to it. The setting is the source of truth; CSS patches only cover
    *classed* links outside prose. **One sanctioned exception:** links that must NEVER lose their
    underline regardless of the site-wide setting (regulatory / T&C / responsible-gambling links)
    may use a deliberate, commented `text-decoration: underline !important` — the audit outcome
    for those cannot be allowed to depend on a theme toggle.
- **Heading order** — never skip a level going down (see the `CLAUDE.md` heading-order rule; footer
  column titles are `<h2>` styled small, not `<h5>`).
- **Descriptive link text** — no bare "Read more"/"Click here" (see the `CLAUDE.md` descriptive-link
  rule; fold the item title into a visually-hidden span).
- **Image alt** — always present; `fw_image_tag()` sources it from the attachment; decorative = `alt=""`.
- **ARIA correctness** — valid roles only; interactive controls are real `<button>`/`<a>`; icon-only
  controls get an accessible name (see the agent-readiness audit).
- **ARIA roles come in required parent/child PAIRS — never emit half of one** (the
  `aria-required-parent` / `aria-required-children` audits). `role="listitem"` requires a
  `role="list"` parent; `role="tab"` requires a `tablist` (+ `tabpanel`s); `row` requires
  `table`/`grid`; `option` requires `listbox`. **The safe default for card grids is NO ARIA at
  all** — a grid of semantic `<article>` cards already conveys its structure; bolting `listitem`
  onto the cards (with a plain `<div>` grid parent) is a half-pattern that fails the audit.
  *This was the posts shortcode's `article.posts__card[role="listitem"]` — fixed by deleting the
  roles entirely, not by completing the pair.* If you genuinely need list semantics, use a real
  `<ul>/<li>`; if you add any paired role, add its counterpart in the same commit.
- **Tap targets ≥ 24×24px**; form fields have labels; one `<main>`, one `<h1>`.

- **CSS design tokens stay in their FAMILY — chrome tokens never style content.** `--menu-*`,
  `--topbar-*`, `--header-*` tokens are tuned for the chrome's background (often white-on-dark) and
  must never be consumed by body/content rules — that's how the global `a:hover`, the prose-link
  hover, AND the comment-submit button all turned **white-on-white** on dark-header sites (three
  separate instances of one disease). Content rules use body tokens
  (`--body-link-*`, `--color-primary`, `--color-muted`) with sane fallbacks. **Run the auditor**
  after touching any `var(--…)` wiring: `node "D:\Web Dev\pw-screens	oken-audit.mjs"` — it
  cross-references every token the static theme CSS consumes against what the dynamic layers emit,
  flagging scope violations, undefined tokens, multi-layer conflicts and inconsistent fallbacks.

## 3. SEO (Lighthouse SEO + machine-readability)

- **Structured data (JSON-LD).** Organization + WebSite + SearchAction + Article ship from the
  theme (`inc/includes/schema-jsonld.php`, SEO-plugin-aware). Turn on the **opt-in per-shortcode**
  schema where it fits: Review/AggregateRating (star-rating, testimonials), Product/Offer
  (pricing-table, comparison-table), FAQPage (accordion), HowTo (timeline).
- **Head meta.** title, meta description, canonical, Open Graph, Twitter — emitted by
  `inc/includes/head-meta.php` when no SEO plugin is present (Yoast/Rank Math/etc. own it otherwise).
- **Crawlability.** `robots.txt` Sitemap line + generated **`/llms.txt`** entry point
  (`inc/includes/crawl-signals.php`). Ensure a real XML sitemap exists.
- **Semantic HTML** — landmarks, single H1, meaningful headings, clean DOM (UnysonPlus's whole pitch).
- **Mobile-friendly** — responsive, legible font sizes, adequate tap targets.

## 4. Contrast — the math + minimum shades (reference)

Relative luminance `L` per channel `c` (sRGB): `c/12.92` if `c ≤ 0.03928` else `((c+0.055)/1.055)^2.4`;
`L = 0.2126·R + 0.7152·G + 0.0722·B`. **Ratio = (L_lighter + 0.05) / (L_darker + 0.05)**.

- **White text (`#fff`)** passes 4.5:1 only when the background `L ≤ 0.175` → use the **700/800 shade**
  of a hue, not the 500. (e.g. green: `#166534`, not `#16a34a`; amber has *no* white-safe shade that
  still reads "amber" — use dark text.)
- **Black-ish text** passes 4.5:1 when background `L ≥ 0.183` → any light/mid fill.
- **Muted grey on white** minimum: **`#767676`**.
- **Suggested-fix rule (what the converter proposes, §5 — it does NOT apply it silently):** keep the
  **hue + saturation**, nudge **lightness** toward the needed ratio in small steps until AA passes. On a
  bright brand fill with white text, the usual suggestion is **dark text** (keeps the brand color) or the
  700/800 shade of the fill. The converter reports the suggestion; the user decides.

## 5. Conversion-specific (Site Converter)

- The converter runs a **WCAG contrast pass** on the generated palette: every text/background pair it
  emits is checked. **It never rewrites the brand colors silently** — the source site's palette is the
  user's branding and we respect it. Instead, each low-contrast pair is **reported** in the
  **conversion report** (`conversion-report.csv` / `.html`) with the measured ratio **and a suggested
  nearest-AA shade** (keep hue + saturation, nudge lightness), so the user can **opt in** to the
  suggestion or keep their color. Keep the **JS** (`capture-extract.mjs`) and **PHP**
  (`class-fw-site-converter-theme-generator.php`) contrast logic **in sync** (the deterministic-converter
  sync rule).
- **Why report-and-ask, not auto-fix:** a converted site is a mirror of someone's existing brand; an
  amber CTA or a green badge is a deliberate identity choice. We flag the risk and propose a fix, but
  the human decides — auto-darkening a logo-matched button would quietly break the brand.
- Inline links in converted body copy get an **underline** by default (link-distinguishability) — this
  is a structural default, not a brand-color change, so it's safe to apply automatically.
- After a conversion, open the report, review the flagged contrast pairs, apply the suggested shades
  you agree with, then run the §0 ship gate.

---

## Verification checklist (copy into the conversion report / PR)

- [ ] Lighthouse Perf ≥ 90, A11y 100, SEO 100 on home + inner + post
- [ ] All images via `fw_image_tag` with accurate `sizes`; LCP image preloaded
- [ ] Every `<img>` has explicit `width`+`height` — incl. hand-authored custom-HTML / footer-badge /
      editor images (SVGs use their `viewBox` size); `grep -oiE '<img[^>]*>' | grep -v 'width='` is empty
- [ ] Contrast: no text/bg pair < 4.5:1 (buttons, badges, meta, footer links)
- [ ] Inline text links underlined (not color-only)
- [ ] Heading order descends without gaps; one `<h1>`; one `<main>`
- [ ] alt on every meaningful image; `alt=""` on decorative
- [ ] Structured data present (Organization/WebSite/Article + relevant shortcode schema)
- [ ] Meta description + canonical + OG/Twitter present
- [ ] `/llms.txt` + robots Sitemap reachable; XML sitemap exists
- [ ] Caching + CDN enabled on the host
