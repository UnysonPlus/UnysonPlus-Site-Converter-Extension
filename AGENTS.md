---
type: extension
name: site-converter
since: plugin 2.10.x
provides: admin page (Unyson+ → Convert) + reusable importer engines
status: in progress — roadmap #2 of the AI-site → WordPress initiative
---

# Site Converter

The admin home for the **AI-generated-site → WordPress importer** (roadmap #2 of the
conversion initiative; see `d:\Web Dev\ai-to-wordpress-conversion-contract.md` and
`ai-to-wordpress-conversion-plan.md`). It ingests the artifacts an agent emits per the
conversion contract and applies them to the site, replacing today's manual import steps.

Built incrementally. **All five phases now ship:** the Media tool, the Styling Presets importer, the
Theme-settings importer, the Pages importer, and the Menu importer — plus the one-shot **Convert
bundle** that runs all of them from a single `.zip`. The conversion pipeline is feature-complete.

## Structure

```
site-converter/
├── manifest.php                              ← extension manifest (own version + repo)
├── class-fw-extension-site-converter.php     ← main class: admin page (Unyson+ → Convert)
└── includes/
    ├── class-fw-site-converter-media.php          ← reusable media engine (static helpers)
    ├── class-fw-site-converter-presets.php        ← styling-presets importer (static)
    ├── class-fw-site-converter-menus.php          ← nav-menu importer + scanner (static)
    ├── class-fw-site-converter-theme-settings.php ← theme-settings (design file) importer (static)
    ├── class-fw-site-converter-pages.php          ← pages importer (builder trees → WP pages, static)
    ├── class-fw-site-converter-bundle.php         ← one-shot bundle orchestrator (static)
    ├── class-fw-site-converter-theme-generator.php ← header/footer → child|standalone theme (static)
    ├── class-fw-site-converter-stitch.php         ← Google Stitch ingest engine (static)
    └── class-fw-site-converter-sources.php        ← file-upload source auto-detect + adapter registry
```

## Unified Convert — two methods, auto-detect (`FW_Site_Converter_Sources`)

The **Site Converter** tab converts a whole site **two ways**: **from a URL** (the capture service —
renders JS apps) and **from a file** (upload an AI-builder export). The file path **auto-detects the
source** and routes to the matching adapter, so the tool grows to "convert as many builders as possible"
without a tab per builder. `class-fw-site-converter-sources.php` (`FW_Site_Converter_Sources`) is the
registry: `adapters()` (filterable via `fw_site_converter_sources`) lists each builder with a
`detect_dir`/`detect_html` confidence scorer (0..1) + a `build` callback; `identify_dir()`/`identify_html()`
pick the highest-confidence match (≥ `MIN_CONFIDENCE` 0.5, else the **generic HTML** fallback);
`build_from_dir()`/`build_from_html()` build the standard bundle and tag it with `source`. Today:
**Google Stitch** (specialized — `FW_Site_Converter_Stitch::detect_dir/detect_html` fingerprint the inline
`tailwind.config` + `aida-public` CDN + Material Symbols + a sibling `DESIGN.md`) and **generic HTML**
(the Stitch engine doubles as a plain-HTML converter — it walks semantic sections regardless of source).
Add a builder = one `adapters()` entry. Admin: the `convert_file` step (`run_convert_file()`) unzips →
`FW_Site_Converter_Sources::build_from_dir()` → import + **activate the generated child theme**; the
result notice reports the detected source. (Back-compat: the old `stitch_build` step + `fw_sc_stitch_*`
field names still route here.)

**Manual mapping review (file flow).** Beside "Convert to WordPress" the file form has **"Review mapping
first"** — the same human-in-the-loop the URL flow has. Two AJAX steps: `_ajax_convert_prepare`
(auto-detect + `build_bundle`, **stash** the design half — `theme-design.json` + `media.json` — in a
per-user transient, return the role-annotated `mapping` + `FW_Site_Converter_Mapper::roles()` + the home
`html`) → the in-page editor (per-section CSS ID / Omit, per-element include + role `<select>`, mutating
the mapping client-side) → `_ajax_convert_build` (`Mapper::build_pages()` of the corrected mapping + the
stashed design → `import_bundle()` → activate theme → `Mapper::learn()`). The review build reuses the SAME
child-theme generation as the one-click path; only the page mapping is user-corrected.

**AI companion (the fidelity tier).** An optional **"Use AI"** checkbox in the file form. The AI lives in
the SAME local capture service (`unysonplus-html-to-wordpress-conversion/tools/design-capture`, package
`unysonplus-site-capture`) — a new `POST /ai-convert` endpoint (`to-ai.mjs`) that refines via Claude.
**Two backends, auto-detected** (`to-ai.mjs` `aiBackend()`): an **`ANTHROPIC_API_KEY`** (pay-per-use API)
OR the **Claude Code CLI** (`claude -p` — uses the user's *subscription*, no key). Pick order:
`AI_BACKEND` env → API key → `claude` on PATH → off. Both are held in the LOCAL service, NEVER in
WordPress, and return a refined `mapping` + a global `custom_css`. The browser orchestrates (admin →
localhost, like the capture flow): `_ajax_convert_prepare` → `POST <svc>/ai-convert {html, mapping, source}`
→ the refined mapping feeds the review editor (or the one-click build) → `_ajax_convert_build` with an extra
`ai_css` POST field, which is folded into the generated child theme's `custom_css`. `/health` reports
`aiReady` + `aiBackend` so the UI shows "AI ready (Claude Code subscription)" / "(API key)" vs "no AI
backend" vs "service not detected". The model works at the **mapping + CSS** level — the deterministic
engine still produces the correct page-builder nodes — so the AI can't emit malformed builder JSON. AI off
→ the flow is unchanged (deterministic + manual review).

Mirrors the `post-types` extension's admin-page pattern: a submenu under `fw-extensions`
(`PARENT_SLUG`), save/run handled on the page's `load-` hook before output (PRG redirect),
results passed via a per-user transient.

## Media engine — `FW_Site_Converter_Media` (the "media phase")

Pure static helpers so the admin page, a future bundle importer, and WP-CLI share one path.

| Method | Purpose |
|---|---|
| `sideload( $url, $post_id = 0, $desc = '' )` | Download one remote image (`download_url`) + insert it (`media_handle_sideload`). **Two-level de-dup:** (1) same source URL (`_unysonplus_source_url` postmeta, no download); (2) identical **content hash** (`_unysonplus_source_hash` = md5 of the bytes) — catches the same image fetched from a *different* URL/site, reusing the existing attachment instead of duplicating. Sets `self::$last_reused`. Sniffs the real type (`getimagesize`) so extension-less URLs land valid. Skips `data:`. Returns attachment ID or `WP_Error`. |
| `find_by_source( $url )` / `find_by_hash( $md5 )` | The two dedup lookups (by URL / by file content). |
| `import_urls( $urls, $post_id = 0 )` | Batch — returns one result row per URL `{ source, ok, id?, url?, reused?, message? }`. |
| `scan_html( $html, $base = '' )` | Collect image refs from `<img src>`, `srcset` candidates, and `url(...)` in inline styles / `<style>`. Returns **absolute**, de-duped URLs; skips `data:`. |
| `scan_meta( $html, $base = '' )` | `<meta og:image / twitter:image>` + `<link rel=icon / apple-touch-icon>` refs (the share image + favicons). |
| `scan_page( $url, $deep = true, $max = 4 )` | **Orchestrator** — fetch the page (with a real **browser UA**, since hosts like Wix serve unknown agents a stripped page), run `scan_html` + `scan_meta`, mine the **page HTML itself** for absolute image URLs embedded in JSON/data-attrs/inline scripts (Wix/Next/Nuxt inline their media URLs), and (when `$deep`) fetch up to `$max` same-origin `<script>` bundles. Returns a report `{ urls[], html, meta, embedded, js, scripts, inline_svg, data_uri, error }`. **This is what makes JS apps work.** |
| `script_srcs( $html, $base )` | Same-origin `<script src>` URLs (the bundles to mine). Never fetches third-party JS. |
| `extract_asset_urls( $text, $base )` | Image URLs (absolute + root-relative `/assets/*.ext`) from arbitrary JS/CSS text. Raster only (SVG excluded — WP blocks it). |
| `absolutize( $url, $base )` | Resolve root-relative / doc-relative / protocol-relative refs against a base URL. |
| `rewrite( $content, $map )` | Replace `source_url => new_url` in content (longest-key-first, so a short URL can't clobber a longer one). For re-pointing page content / settings at the imported media. |

**Why a source-URL postmeta:** re-running an import (or importing the same image referenced
on several pages) reuses the existing attachment instead of duplicating. The theme-settings
design export **strips** `attachment_id` on purpose (contract §4.2) — the importer re-attaches
on the target via this engine.

**JS apps (React / Vite / Lovable / v0):** the static HTML is just a `<div id="root">` shell —
the real images are injected at runtime and never appear in the markup. `scan_page($url, deep:true)`
handles this by fetching the page's own `<script>` bundles and extracting `/assets/*.jpg`-style
asset URLs from them (verified: a Lovable site exposed 0 `<img>` in HTML but 17 images in its
bundle). It also mines the page HTML for image URLs embedded in JSON/data-attrs (Wix encodes them
as `&quot;uri&quot;:&quot;https://static.wixstatic.com/…png&quot;`). **Heavy client-rendered sites
(esp. Wix)** still expose only a few images statically — most load at runtime via API from media
IDs, and the bundles are cross-origin (not mined). For those, fall back to the URL-list mode — the
agent that built the site can render it and supply the image URLs.

**De-dup:** re-running, or scanning a *different* site that hosts the *same* image, never duplicates
— `sideload()` reuses the existing attachment (by source URL, then by content md5) and flags it
`reused`. The picker badges already-imported URLs; content dupes (different URL, identical bytes)
are caught at import and shown amber ("reused").

**Gotchas:** WP blocks SVG upload by default, so inline-SVG sites (e.g. the PayForItUK
round-trip) yield zero bitmap fetches — that's expected, their graphics live inline in the
markup. `media_handle_sideload` needs `wp-admin/includes/{file,media,image}.php` (the engine
requires them lazily).

## Admin page — Unyson+ → Convert

**Two-step flow:** **Scan** (a page URL or pasted URLs) → a **thumbnail picker** (every candidate
shown as a remote `<img>` with a checkbox; "in library" badge + auto-unchecked for already-imported
ones; Select all / none / only-new) → **Import selected**, which runs **via AJAX one image per
request** (`wp_ajax_fw_sc_import`) with a **live progress bar**, so a big batch never times out and
each tile turns green/red as it lands. No-JS degrades to a normal POST that imports server-side and
shows a results table. `_maybe_run()` dispatches the two steps (`run_scan` → preview transient;
`run_import` → results) via the `fw_sc_step` field; both PRG-redirect.

**Only real images are offered.** `accept_image_url()` filters candidates to fetchable raster
images, so CSS `@font-face` `url(...woff2)`, favicons (`.ico`), SVG, video, and junk like
`url(window.location.href)` never reach the picker. `<img>`/`srcset` URLs are trusted; `url()` and
JS-bundle refs must carry a raster extension (png/jpg/jpeg/gif/webp/avif/bmp).

## Verification

1. Activate the extension (Unyson+ → Extensions). Unyson+ → **Convert** appears.
2. Scan mode: paste an AI site URL with real images → **Fetch** → results table lists each image
   as imported, with a link to the Media Library entry; the images appear under Media.
3. Re-run the same URL → every row shows **reused** (de-dup works; no duplicate attachments).
4. URL-list mode: paste a couple of direct image URLs → imported. An extension-less image URL
   still lands with a correct type (sniffed).
5. Presets: paste a presets export (a `{ "values": { … } }` JSON) → **Import presets** → a success
   notice lists the imported keys + counts; Shortcode Settings → presets reflect them. A non-preset
   key in the payload shows as skipped.

## Styling Presets importer (shipped)

`includes/class-fw-site-converter-presets.php` (`FW_Site_Converter_Presets`) applies a presets
export to the theme-independent store. `import($array)` / `import_json($string)` accept either a
`{ "values": { key: value, … } }` envelope (tolerating a leading `_fw_presets_export` / `_note`
metadata block) or a raw `key => value` map; keys starting with `_` are ignored. Each whitelisted
key is written via `FW_WP_Option::set( 'fw_ext_settings_options:shortcodes', $key, $value )` — the
same option `unysonplus_preset_store_get()` reads. **Whitelist** (`ALLOWED_KEYS`): `theme_colors`,
`font_sizes`, `button_colors`, `button_sizes`, `button_animations`, `border_presets`,
`table_presets`, `spacing_scale`, `gap_scale`, `default_gap`, `default_gap_x`, `default_gap_y`.
Unknown keys are reported as **skipped** (never written), so a stray key can't pollute the option.
Static so the future Convert bundle / WP-CLI can reuse it (mirrors the media engine). Admin wiring:
the `import_presets` step on the Convert page → PRG redirect + result transient (`presets_result`
stage). Returns `{ imported: {key:count}, skipped: [keys], error: '' }`.

## Menus importer (shipped)

`includes/class-fw-site-converter-menus.php` (`FW_Site_Converter_Menus`) builds WordPress nav
menus from the source site's navigation and assigns them to the theme's menu locations — the
contract's recommended low-risk chrome path (§3: "create the two WP menus from the source's
nav/footer links and assign them to primary/footer"). `import($array)` / `import_json($string)`
accept `{ "menus": [ … ] }`, a bare list of menu specs, or a single menu object. Each menu spec:
`{ name, location, items: [ { label, url, children: [ … ] } ] }` — field lookups are lenient
(`label|title|text`, `url|href|link`, `children|items|sub`). Per menu:

- **Get-or-create by name**, then **rebuild its items** (delete existing first) — re-running is
  idempotent, never duplicates. `wp_create_nav_menu` / `wp_update_nav_menu_item` (no admin
  includes needed; these live in `wp-includes/nav-menu.php`).
- **Item targets:** an internal link whose path matches an existing page (`get_page_by_path`)
  becomes a real **page** menu item (`menu-item-type=post_type`); an internal link with no match
  is kept **site-relative** (source host dropped so it works on the new domain); an external link
  is a custom link as-is; `#`/anchors pass through. Children recurse (dropdowns).
- **Location:** assigned via `set_theme_mod('nav_menu_locations', …)` when the slug is one the
  theme registers (`get_registered_nav_menus`). If `location` is omitted, it's **inferred from the
  name** (Primary/Main/Header/Nav → `primary`, Footer → `footer`) but only if that location exists.
  Unregistered/unknown → menu still created, reported as "not assigned" (assign under Appearance →
  Menus). The UnysonPlus theme exposes `primary` (`#masthead`) and `footer` (`#colophon`).

Static so the future Convert bundle / WP-CLI can reuse it. Admin wiring: the `import_menus` step on
the Convert page → PRG redirect + result transient (`menus_result` stage). Returns
`{ menus: [ {name, location, assigned, items, created, error} ], locations: {slug=>label}, error }`.

**Nav scanner (scan-first UX, like the Media tool).** `scan_page($url)` fetches a source page (reuses
the media engine's browser-UA `wp_remote_get` + `absolutize`) and `extract_menus($html, $base)` parses
it with `DOMDocument`/`DOMXPath` into the same `{ menus: [ … ] }` shape, which the admin page
pretty-prints into the import box for review (`scan_menus` step → `menus_scanned` stage prefills the
`fw_sc_menus_json` textarea). **Primary** = the nav-ish container (`header nav` / `nav` /
`[role=navigation]` / `header`) yielding the most top-level items; its richest `<ul>` is walked into
nested items (`<li>` own-anchor for label/url, immediate child `<ul>` → `children`, recursing — so
dropdowns survive). **Footer** = the `<footer>`'s links flattened (de-duped, capped). Icon-only links
(no text) are skipped; labels are whitespace-collapsed + entity-decoded. Works on static / SSR markup;
a pure client-rendered SPA has no nav in the static HTML (paste the JSON instead) — same limitation as
the media scanner. The scan only *prefills*; nothing is created until the user clicks **Import menus**.

## Theme-settings importer (shipped)

`includes/class-fw-site-converter-theme-settings.php` (`FW_Site_Converter_Theme_Settings`) applies a
**design file** (the contract §4 export — `{ "_fw_settings_export": {…}, "values": { id: value } }`)
to the theme's settings store: the single `fw_theme_settings_options:{theme-id}` wp_option, via
`fw_get_db_settings_option()` / `fw_set_db_settings_option()`. `import($array)` / `import_json($string)`
**mirror the theme's own Misc → Import** (`unysonplus-theme inc/includes/settings-export-import.php`)
and reuse its helpers when present (`unysonplus_settings_io_exclude_keys`,
`unysonplus_settings_io_strip_media`), falling back to built-in equivalents so it still works under
another theme. Each imported key is applied **on its own** via the single-option path
(`fw_set_db_settings_option($id, $value)`), so only the keys the file carries are touched and every
other setting is preserved. **Do NOT write the whole map** via `fw_set_db_settings_option(null, $map)`
— that re-runs every registered option's `storage_save()` on its already-stored value (which expects
fresh form input, not the stored shape) and corrupts unrelated settings (the bug that bit 1.0.9). **Operational keys are never imported**
(`misc_analytics`, `misc_performance`, `misc_maintenance`, `misc_404`, `misc_custom_scripts` — blocks
tracking / script injection) and **`attachment_id` media refs are blanked** (the media engine
re-attaches on target, §4.2). Fires `do_action('fw_settings_form_saved', $current, $merged)` so the
theme regenerates assets / flushes cache, and flags a `cross_theme` warning if the file's `theme_id`
differs. Admin wiring: the `import_theme_settings` step → `theme_result` stage. Returns
`{ imported: [ids], skipped: [ids], cross_theme: bool, error }`.

## Pages importer (shipped)

`includes/class-fw-site-converter-pages.php` (`FW_Site_Converter_Pages`) creates WordPress pages from
page-builder content (contract §2 — the builder-tree JSON). **It never hand-authors the encoded
shortcode string** (contract rule #1): it `wp_insert_post`s a `page`, then sets the post's
`page-builder` option via `fw_set_db_post_option($post_id, 'page-builder', { json, builder_active:true })`.
That fires the page-builder extension's own `fw_post_options_update` hook
(`_action_fw_post_options_update`), which regenerates `post_content` from the tree with the plugin's
encoder (`json_to_shortcodes`). Setting the option this way is **side-effect-safe** — it doesn't read
`$_POST`, so it can't wipe other post options the way a programmatic `save_post` would.

Payload: `{ "pages": [ { title, slug?, status?, front_page?, builder:[…] | json:"…" } ] }` (or a bare
list / single object). The `builder` value is the §2.1 tree (array of sections) — normalized to a JSON
**string** before storage (the option's `json` is a string). **Idempotent by slug** (`get_page_by_path`
→ update, else create); optional `front_page` sets `show_on_front`/`page_on_front`. Per-leaf **att keys
are the agent's responsibility** (per each shortcode's `AGENTS.md`) — e.g. `text_block`'s content field
id is `text`, not `content`. Admin wiring: the `import_pages` step → `pages_result` stage (a table with
Edit/View links). Returns `{ pages: [ {title, slug, id, created, front_page, error} ], error }`.

## Convert bundle (shipped — one-shot orchestrator)

`includes/class-fw-site-converter-bundle.php` (`FW_Site_Converter_Bundle`) ingests one agent-produced
`.zip` and applies every phase it has an engine for, in contract order. It owns no import logic —
it unzips and delegates to the media / presets / menu engines. Bundle layout (every file optional):

```
bundle.zip
├── bundle.json        (optional metadata: { name, source, generated })
├── media.json          ({ "urls": [ … ] })                  → FW_Site_Converter_Media::import_urls
├── presets.json        ({ "values": { theme_colors:[…] } }) → FW_Site_Converter_Presets::import
├── theme-settings.json ({ "values": { id: value } })        → FW_Site_Converter_Theme_Settings::import
├── pages.json          ({ "pages": [ … ] })                 → FW_Site_Converter_Pages::import
└── menus.json          ({ "menus": [ … ] })                 → FW_Site_Converter_Menus::import
```

### Authoring a bundle — verified gotchas (from the SmartRoute SPA round-trip)

The importer writes whatever shape it's given, so the **emitter** must match the plugin's exact
shapes. Three that bit a real conversion and will bite again:

1. **Per-shortcode att keys are exact.** Clone shapes from a real export (the canonical reference is
   `examples/static-html-site/full-page-template.json` in the conversion repo) and only swap content.
   E.g. `text_block`'s content field id is **`text`** (not `content`); `special_heading` uses
   `title` / `overline` / `heading`; `icon_box` uses `custom_icon` (inline SVG) / `title` / `content`.
   Column `width` is a **top-level** key (`"1_3"`), not an att.
2. **`misc_custom_css` is a `multi` option** → its value MUST be `{ "custom_css": "…" }`, **not a raw
   string**. A string makes the Theme Settings admin page do `$value['custom_css']` on a string →
   fatal → blank page (recover with the Theme Settings Doctor; fix the design file). Other
   theme-settings ids that are containers (uploads, multis) likewise need their object shape.
3. **Carried design CSS must be admin-scoped.** `misc_custom_css` is `wp_head`-only, but the asset
   optimizer **absorbs it into a combined bundle that also loads in wp-admin** — so bare global
   selectors (`body{}`, `h1–h6{}`, `a{}`) restyle the admin. Scope them to **`body:not(.wp-admin)`**.
   Page-scoped classes (`.sr-card`, `#masthead`, `#colophon`) are safe.

For client-rendered SPAs (React / Vite / Lovable), the page must be **rendered** to extract content +
tokens — `chrome --headless --dump-dom` (run the JS) for the DOM, `--screenshot` for the look, and the
linked stylesheet for the token system. Images are usually lazy-loaded (the URL-list `media.json`
captures only what's statically reachable; the building agent can supply the rest).

`import_zip($path)` → `unzip_file` (WP_Filesystem) into a temp dir, `import_dir()`, then delete the
temp dir. `import_dir($dir)` (reusable if already unzipped) `locate_root()`s the bundle (handles a
single wrapping folder), reads each known file, and runs media → presets → theme settings → pages →
menus, returning a combined `{ manifest, media{imported,reused,failed,total}, presets{imported,skipped},
theme_settings{imported,skipped,…}, pages{pages[…]}, menus{…}, sections[], deferred[], error }`. All
five phases are wired; `deferred` stays empty unless a future, not-yet-supported section appears. Admin wiring: the `import_bundle` step (a `multipart/form-data` upload validated as a
real `.zip`) → `FW_Site_Converter_Bundle::import_zip( $_FILES tmp )` → `bundle_result` stage with a
combined per-phase summary. The bundle upload is the page's headline tool (the individual tools sit
below for piecemeal runs).

## Theme generator — `FW_Site_Converter_Theme_Generator` (header/footer conversion, shipped)

The "make the header & footer perfect" tool. Takes a **design config** (the chrome half of a capture)
and writes a real WordPress theme that reproduces the source's **header + footer design** — never its
content. Generalized from the proven hand-built SmartRoute child theme. Static, so a bundle / WP-CLI
can reuse it.

**Cardinal rule — copy stylings, not content.** The generated header logo is always the site's own
(`the_custom_logo()` → `get_bloginfo('name')`); the footer brand is the Site Title. The CTA *label* is
config-supplied (a structural element the user controls), defaulting to "Get started". Never hard-code
the source's brand text.

**Two modes (the top-level conversion choice the user picks in the UI):**

| Mode | What ships | `style.css` header |
|---|---|---|
| `child` (default, recommended) | 6 files: `style.css`, `functions.php`, **`header.php`, `footer.php`**, `template-parts/header-builder.php`, `footer-builder.php` | has `Template: unysonplus-theme` |
| `standalone` | a **copy of the parent tree**, de-parented, chrome overlaid; generated functions become `inc/site-converter-chrome.php` + a `require` appended to the copied `functions.php`; `style.css` = parent style body (header stripped/rewritten) + generated chrome appended | **no** `Template:` line |

**Why the child generates its OWN `header.php` + `footer.php` (not just the template parts).** The parent
theme's `header.php`/`footer.php` now route header/footer through the **Theme Builder** resolver
(`unysonplus_get_active_header_render()` → `fw_ext_hfbuilder_render()`); when no `up_header`/`up_footer`
preset matches, it falls back to **Theme Settings slots**, NOT to a child template part — so a converted
site that creates no Theme Builder preset would render the parent's empty slot header instead of its own
chrome. The Theme Builder + its presets are reserved for the **distributable demo themes**; the converter
deliberately stays out of it. So the child ships its own `header.php`/`footer.php` (`header_php()` /
`footer_php()`) that mirror the parent's wrapper structure (`#page → header → #content`, then
`#content → footer → #page` + `wp_footer()`) but load the converted chrome via the child's
`template-parts/header-builder.php` / `footer-builder.php` directly — `get_header()`/`get_footer()` resolve
the child's copies first (stylesheet dir wins), so the source chrome renders with zero Theme Builder / Theme
Settings indirection.

**Header detection tolerates a bare `<nav>` bar.** `detect_header()` and `extract_menus()` resolve the
header via `header_root()`: a real `<header>`, or — for the many landing pages (Stitch outputs included)
that use a top-level sticky/fixed `<nav class="fixed top-0 …">` as the site bar with NO `<header>` —
that `<nav>`. The header nav links come from `links_in($root, $drop_buttons=true)`, which prefers the
**densest `<ul>`/`<div>` link group** (so a standalone brand/logo `<a>` outside it is excluded → becomes
the site's own logo, not a menu item) and skips button-styled anchors (the CTA, captured separately).
Without this, a `<nav>`-bar header yielded an empty primary menu (the header rendered bare).

Both still use the Unyson+ plugin + page builder — standalone just has the other files needed to stand
on its own.

**Key methods:** `normalize($config)` (sparse → full, sensible defaults), `build_files($cfg)` (the 4-file
map), `chrome_css($cfg)` (the parameterized stylesheet — header `style: pill|bar|minimal`, dotted canvas,
nav-underline, CTA, footer; **all global rules scoped `body:not(.wp-admin)`** per the same optimizer
lesson as misc_custom_css), `install($config)` (writes into `get_theme_root()/<slug>`; standalone copies
the parent tree first), `build_zip($config)` (downloadable; `ZipArchive`, parent tree added then overlaid).

**Capture → generate (shipped).** `is_capture($c)` detects a raw `design-capture.json` (carries
`tokens` / `assets` / a `header.nav` array) and `from_capture($cap)` maps it to a design-config —
`normalize()` runs this automatically, so the admin "Generate theme" textarea accepts **either** a
design-config **or** the capture tool's raw JSON. `from_capture` copies stylings only: heading font from
the logo/section headings, body font from `<body>`, the source's own `fonts.googleapis.com/css` URL
(icon fonts like Material Symbols skipped), `--primary` (→ nav-active → CTA bg) as the accent, foreground
/ background / footer colors (oklch passed through verbatim — evergreen-safe), `position:fixed|sticky` →
`sticky`, and the CTA label + an **origin-stripped, de-branded** href (`https://src.app/signup` → `/signup`).
It also copies the **logo styling** (`header.logo`: font/size/weight/color/letter-spacing — applied to the
site's OWN text logo, so it *looks* like the source but is never the source's wording) and the **button
styling** (`header.cta.style`: bg/color/radius/padding/font-weight from the source button — a pill button's
absurd reported radius like `3.35e7px` is `clamp_radius`'d to `9999px`). The menu always renders the live
WordPress menu assigned to `header.menu_location` (`wp_nav_menu`), so it uses the site's current menu.
The logo/brand text is never taken from the capture.

**Editable footer ("copy the whole thing", shipped).** The footer is reproduced as an *editable* replica,
not baked content. `from_capture` maps the captured footer into `footer.menu` (link columns — a titled
group becomes a top-level item with its links as children; flat links become top-level items; hrefs
origin-stripped), `footer.social`, and `footer.copyright` (just the editable tagline *after* the
"© {year} {brand}." sentence — that sentence is reproduced dynamically from Site Title + year). The
generated theme: registers an `sc_footer` nav-menu location (always), renders it as columns in
`footer-builder.php` (brand = Site Title, then the menu, widgets, social, dynamic copyright), and — key —
bakes an **`after_switch_theme` bootstrap** into `functions.php` that creates a "Footer" menu from the
captured links and assigns it to `sc_footer` **on activation** (idempotent; reuses an existing "Footer"
menu). This sidesteps the theme-switch `nav_menu_locations` reset: the theme re-creates + re-assigns its
own footer menu every activation, so the user never has to. The **header nav** uses the very same
mechanism — `from_capture` maps the captured top nav into `header.menu` (CTA label dropped, hrefs
de-branded) and the theme bootstraps a "Header" menu to `header.menu_location` on activation. Both
bootstraps are emitted by the shared `menu_bootstrap_code()` helper, so **activating the generated
theme brings up the entire chrome — header nav + footer columns — with nothing to re-import.**
**Gotcha:** `wp_create_nav_menu()` / `wp_update_nav_menu_item()` are in `wp-admin/includes/nav-menu.php`
(not loaded by default when `after_switch_theme` fires) — the bootstrap `require_once`s it first or
activation fatals.

**Body → editable Home page (shipped, tooling).** The capture tool's `to-pages.mjs` maps the captured
body `sections[]` into the **Pages importer** payload, cloning the heavy default att-blobs from
`atom-templates.json` (real nodes from a proven export) and swapping only content
(`special_heading` / `text_block` / `icon_box` in `1_1`/`1_3`/`1_4` columns). The plugin's own encoder
regenerates `post_content`, so every section is builder-editable.

**One-shot bundle (shipped).** `FW_Site_Converter_Bundle` now has a **theme phase**: a bundle carrying
`theme-design.json` (or `design-config.json`) is fed to `FW_Site_Converter_Theme_Generator::install()`,
so a single `.zip` builds the child/standalone theme **and** the Home page (`pages.json`) in one upload —
the user just activates the generated theme. The design-capture tool emits a ready `convert-bundle.zip`
(`bundle.json` + `theme-design.json` + `pages.json`) via a tiny dependency-free STORE-method zip writer
(`minimal-zip.mjs`). The capture tool
(`tools/design-capture/to-design-config.mjs`, imported by `capture.mjs`) is a JS mirror of this and emits
a ready `design-config.json` next to `design-capture.json` — verified to produce byte-identical config to
the PHP path on the SmartRoute capture (Fraunces heading, Manrope body, terracotta accent, `/signup` CTA).

**Design-config shape** (everything optional — omitted keys default):

```jsonc
{
  "theme":  { "name": "My Site", "slug": "my-site", "mode": "child|standalone" },
  "fonts":  { "heading": "Fraunces", "body": "Manrope", "google": "https://fonts.googleapis.com/css2?…" },
  "colors": { "ink": "#34251f", "accent": "#994920", "bg": "#fbf9f0",
              "header_bg": "rgba(251,249,240,.72)", "header_border": "#ece6da",
              "footer_bg": "#34251f", "footer_text": "#fbf9f0" },
  "header": { "layout": "logo-left-nav-center-cta-right", "style": "pill",
              "menu_location": "primary", "sticky": false,
              "cta": { "enabled": true, "label": "Get started", "href": "/#get-started", "dedupe_from_menu": true } },
  "footer": { "widget_area": true, "brand": true, "copyright": "All rights reserved." },
  "background": { "dotted": true, "dot_color": "#e7e1d4", "canvas": "#fbf9f0" },
  "custom_css": "/* extra carried CSS, already body:not(.wp-admin)-scoped */"
}
```

Generated CSS classes are namespaced `sc-*` (`.sc-header-inner`, `.sc-menu`, `.sc-header-btn`,
`.sc-footer-inner`, `.sc-widget`) and the footer widget area id is `sc-footer-widgets`.

**Admin wiring:** the `generate_theme` step. The **Child / Standalone radio** is the source of truth for
mode (folded into the config server-side). Two submit buttons share the form: `fw_sc_theme_action=install`
(PRG → `theme_generated` stage with the path + an "activate + re-import menus" note) and `…=download`
(streamed `.zip` on the load- hook, before any output — `readfile` + `unlink` + `exit`).

**Gotcha — menu locations reset on theme switch.** WordPress stores `nav_menu_locations` as theme_mods,
so activating the generated theme clears the assignment. The success panel tells the user to **re-import
menus** after activating (the menus engine re-assigns them).

## Google Stitch ingest — `FW_Site_Converter_Stitch` (shipped)

`includes/class-fw-site-converter-stitch.php` turns a **Google Stitch** export into the SAME convert-bundle
the rest of the extension imports — so a Stitch design becomes a native child theme + page-builder Full
Page **without any LLM**. Stitch is a *first-class deterministic* input (unlike a scraped site): the design
tokens are handed to us in the inline `tailwind.config` JSON (and/or a sibling `DESIGN.md` YAML
frontmatter), sections are explicitly comment-labelled, and the markup is clean semantic HTML — so a
Stitch-aware parser maps confidently with no AI.

**Input — both export layouts** (`build_bundle()` accepts a folder or a `{ html }` payload):
- **Single frame** (Export → one frame, or "Code to Clipboard") — a **flat** folder `{ code.html, DESIGN.md,
  screen.png }`.
- **Multi-screen** (Export → the whole project) — a parent folder with **one subfolder per screen**
  (`<screen>/code.html`) + top-level `<system>/DESIGN.md`. Each screen → one page (the first is the front page).

**It generates + activates a CHILD THEME** (the plan's target — not just custom CSS on the active theme).
`tokens_to_design_config()` maps the tokens + the screen's chrome to the theme generator's design-config:
fonts (raw families, with a fallback parsed from the Google-Fonts URL), colors (ink/bg/accent — a
near-neutral token accent is replaced by the most-saturated inline color via `scan_accent()`, e.g. a
`from-[#FF416C]` gradient stop), header (`detect_header()` → pill vs bar, sticky, dark fill, the CTA
button), footer, and component CSS (cards) under `custom_css`. That ships as **`theme-design.json`**, so
the bundle's theme phase runs `FW_Site_Converter_Theme_Generator::install()` → a child theme carrying the
Stitch palette/fonts + header/footer. The theme name is the brand from the HTML `<title>` (`title_from_html`
keeps the part before " - / | "). **Menus are NOT a separate file** — the design-config's `header.menu` /
`footer.menu` are built into real WP menus by the generated theme's activation bootstrap (avoids duplicate
Header/Primary menus).

**Rest of the pipeline (offline):** `scan_images()` (reuses the media engine) → `media.json`.
`html_to_mapping()` walks each `<section>`/`<footer>` into the **Mapper's** role-annotated mapping (pill →
overline, `<h1..2>` → title, `<p>` → text, CTA `<button>`/`<a>` → button, a `grid` of cards with headings →
a `columns` row of **icon_box**es at `col-span-N`/`grid-cols-K` widths, `<img>` → verbatim media) →
`FW_Site_Converter_Mapper::build_pages()` → `pages.json`. `tokens_to_theme_settings()` (the `misc_custom_css`
"apply to the ACTIVE theme" path) is kept for reuse but the Stitch bundle no longer emits it.

**Admin wiring:** the `stitch_build` step (a "Convert a Google Stitch screen" card in **Manual tools**).
**One primary action** — upload the `.zip` → **Convert to WordPress**: builds + imports via
`FW_Site_Converter_Bundle::import_dir()` AND **`switch_theme()` activates the generated child theme**, so
it's a true one-step "upload .zip → done". Under *Advanced options*: paste one screen's `code.html`, or
**Download bundle (.zip)** (`build_zip()`) to refine `pages.json` with Claude (Tier 2) and re-upload via
Convert bundle. Reuses the bundle's `bundle_result` view.

**Self-learning — LOCAL only, privacy-safe (NO telemetry; nothing leaves the machine).** `rules_get()` /
`rules_put()` persist a per-install `signature → role` store (`fw_site_converter_stitch_rules` wp_option)
consulted before the built-in mapping (a learned rule wins). `distill_from_ai()` diffs a Claude-authored
`pages.json` against the deterministic draft and records the deltas as local rules, so the next no-AI run
on **this** install improves. There is deliberately **no central data collection** — collecting users'
design/content would be a GDPR/CCPA consent + privacy-policy + backend burden for no real gain. Global
improvement happens instead through the **maintainer's curated release**: distil accumulated rules into the
parser's built-in tables (`rules_export()` helps), review, commit — the GitHub auto-updater ships it to
everyone. Document this guarantee anywhere the feature is surfaced.

The Claude authoring recipe (Tier 2 — how to hand-write a higher-fidelity `pages.json` from a Stitch
screen, honoring each shortcode's `AGENTS.md`) lives in the working copy at
`framework/extensions/site-converter/docs/stitch-to-unysonplus.md`.

## Roadmap

The five-phase pipeline (media → presets → theme settings → pages → menus) is **complete** and wired
into the one-shot bundle; the **header/footer theme generator** (child + standalone) ships alongside it;
and the **Google Stitch ingest** (deterministic, no-AI, with a local self-learning loop) is shipped.
Possible future polish: an in-page review editor for the Stitch draft mapping (today: download → edit →
re-import), a `media_image` node so `<img>` localizes instead of hotlinking, bundling actual media
**files** in the zip (today `media.json` is a URL list), and a combined progress UI for large bundles.
