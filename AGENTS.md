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
| `sideload( $url, $post_id = 0, $desc = '' )` | Download one remote image (`download_url`) + insert it (`media_handle_sideload`). **De-duped** by source URL via the `_unysonplus_source_url` postmeta. Sniffs the real type from file contents (`getimagesize` → `image_type_to_extension`) so extension-less URLs land valid. Skips `data:` URIs. Returns attachment ID or `WP_Error`. |
| `find_by_source( $url )` | Existing attachment imported from this URL (the dedup lookup). |
| `import_urls( $urls, $post_id = 0 )` | Batch — returns one result row per URL `{ source, ok, id?, url?, reused?, message? }`. |
| `scan_html( $html, $base = '' )` | Collect image refs from `<img src>`, `srcset` candidates, and `url(...)` in inline styles / `<style>`. Returns **absolute**, de-duped URLs; skips `data:`. |
| `scan_meta( $html, $base = '' )` | `<meta og:image / twitter:image>` + `<link rel=icon / apple-touch-icon>` refs (the share image + favicons). |
| `scan_page( $url, $deep = true, $max = 4 )` | **Orchestrator** — fetch the page, run `scan_html` + `scan_meta`, and (when `$deep`) fetch up to `$max` same-origin `<script>` bundles and mine them for assets. Returns a report `{ urls[], html, meta, js, scripts, inline_svg, data_uri, error }`. **This is what makes JS apps work.** |
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
bundle). For sites a server-side scan still can't see, fall back to the URL-list mode — the agent
that built the site can render it and supply the image URLs (the bundle's media manifest).

**Gotchas:** WP blocks SVG upload by default, so inline-SVG sites (e.g. the PayForItUK
round-trip) yield zero bitmap fetches — that's expected, their graphics live inline in the
markup. `media_handle_sideload` needs `wp-admin/includes/{file,media,image}.php` (the engine
requires them lazily).

## Admin page — Unyson+ → Convert

`render_page()` shows the **Fetch images** tool: scan a source page URL **or** paste image
URLs, optionally attach to a post, → results table (imported / reused / failed, with links to
each Media entry). Run handler `_maybe_run()` validates the nonce, fetches (`wp_remote_get` for
scan mode), calls `import_urls()`, stashes results in a transient, PRG-redirects.

## Verification

1. Activate the extension (Unyson+ → Extensions). Unyson+ → **Convert** appears.
2. Scan mode: paste an AI site URL with real images → **Fetch** → results table lists each image
   as imported, with a link to the Media Library entry; the images appear under Media.
3. Re-run the same URL → every row shows **reused** (de-dup works; no duplicate attachments).
4. URL-list mode: paste a couple of direct image URLs → imported. An extension-less image URL
   still lands with a correct type (sniffed).

## Roadmap (next slices)

- **Presets importer** — `_fw_presets_export` → `fw_ext_settings_options:shortcodes` (contract
  §1.4, the top gap). Mirror the theme-settings export/import in `unysonplus-theme`.
- **Menu importer** — create Primary/Footer menus + assign (contract §3.3).
- **Convert bundle** — ingest one `.zip`/JSON (manifest + presets + theme settings + page
  templates + media manifest) and apply Phases 1–5, using this media engine for the media phase.
