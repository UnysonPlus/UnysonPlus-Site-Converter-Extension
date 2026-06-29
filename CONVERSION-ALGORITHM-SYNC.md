# ⚠️ Keep the "no‑AI" conversion algorithm in sync — BOTH implementations

The deterministic (**no‑AI**) converter — the logic that turns a source design into a UnysonPlus
**child theme + page‑builder pages** *without* calling an LLM — exists **TWICE**, once per input path.
**If you change one, you MUST change the other** so the two paths produce consistent results.

| Path | Lives in | Files |
|---|---|---|
| **File upload** (this extension) | `framework/extensions/site-converter/includes/` (PHP) | `class-fw-site-converter-stitch.php`, `class-fw-site-converter-mapper.php`, `class-fw-site-converter-theme-generator.php` |
| **URL capture** (capture service repo) | `UnysonPlus-HTML-to-Wordpress-Conversion/tools/design-capture/` (JS) | `capture-extract.mjs`, `to-pages.mjs`, `to-design-config.mjs` |

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
