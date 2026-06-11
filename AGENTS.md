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

Built incrementally. **Shipped so far: the Media tool.** Planned next: presets importer,
menu importer, and a one-shot "Convert bundle" that orchestrates every phase
(media → presets → theme settings → pages → menus).

## Structure

```
site-converter/
├── manifest.php                              ← extension manifest (own version + repo)
├── class-fw-extension-site-converter.php     ← main class: admin page (Unyson+ → Convert)
└── includes/
    └── class-fw-site-converter-media.php     ← reusable media engine (static helpers)
```

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

## Roadmap (next slices)

- **Menu importer** — create Primary/Footer menus + assign (contract §3.3).
- **Convert bundle** — ingest one `.zip`/JSON (manifest + presets + theme settings + page
  templates + media manifest) and apply Phases 1–5, using this media engine for the media phase.
