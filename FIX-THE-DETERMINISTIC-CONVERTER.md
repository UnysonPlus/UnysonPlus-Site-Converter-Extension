# ⛔ READ FIRST — FIX THE DETERMINISTIC CONVERTER, NEVER HAND-PATCH THE SITE

When the user reports a conversion problem on their site (e.g. `http://localhost/`), the fix goes
into the **deterministic Site Converter code** — **NOT** into the live site. This is the standing
agreement. Violating it is why "did another conversion, still broken" keeps happening.

## The user's ACTUAL flow — this is the ONLY thing to reproduce and verify against

The user does exactly ONE thing:

> Paste the source URL (e.g. `https://amiable-arc-archive.lovable.app/`) into
> **wp-admin → Site Converter** (`admin.php?page=fw-site-converter`) and click **run**.

The plugin then, on its own:
1. **Captures the URL FRESH** via the capture service — a brand-new bundle **every run**.
2. **Converts/imports** it through the PHP engine (`maybe_reconvert_with_php` → Stitch/Mapper) +
   theme generator.

The user **uploads nothing**. Success = pasting the URL and running produces a **better site,
because the converter got better**.

## Two code paths (keep in sync) — the ONLY place fixes belong

- **JS capture service:**
  `UnysonPlus-AI-Dev-Kit/assembled/UnysonPlus-Capture-Service/tools/design-capture/*.mjs`
  (captures the URL → bundle: rendered.html, media.json, bundle.json, pages.json, theme-settings…).
- **PHP engine:**
  `unysonplus/framework/extensions/site-converter/includes/*.php`
  (`class-fw-site-converter-{stitch,mapper,theme-generator,bundle,media}.php`).

## ❌ What NOT to do (the repeated mistake — do not do these again)

- **DON'T** verify by importing a **stale** bundle from `capture-out/` via wp-cli
  `FW_Site_Converter_Bundle::import_zip/import_dir`. That is **not** the user's flow. A stale bundle
  can import perfectly while a **fresh capture → convert** of the same URL still breaks.
- **DON'T** "fix" the site by re-importing, or by editing post meta / theme mods / options / builder
  JSON directly, or flushing caches to make the page *look* right. That is fixing the **website by
  hand**, not the **converter**.
- **DON'T** claim "fixed & verified" from any side-door import. If it wasn't proven on a **fresh
  capture of the URL**, it is not verified.

## ✅ What TO do

- **Fix the converter CODE** (JS capture rules and/or the PHP engine), keeping the two paths in sync.
- **VERIFY by driving the SAME pipeline the wp-admin button drives:** capture the URL **FRESH**
  (`node capture.mjs <url> <out>` in the capture service, or the service's `/capture` endpoint),
  then let the converter import that **fresh** bundle. Reproduce *paste-URL-and-run*. If a **fresh**
  capture+convert is still broken, the converter is **not** fixed yet — keep going.
- Bump versions + mirror to localhost as usual — but the mirror is so the user's **next run** picks
  up the fixed code, **not** a substitute for proving the fix on the real flow.

## ⚠️ READ THE KIT DOCS FIRST — use NATIVE options, never hack custom CSS (REQUIRED)

Before fixing any conversion mapping, **read the target shortcode's doc in
`UnysonPlus-AI-Dev-Kit/docs/shortcodes/<name>.md` (and option-type / Theme-Settings docs)** to find the
RIGHT native option. Do NOT reach for `custom_css` (`selector{…}`) to force a look — that dumps machine
CSS into the user-facing **Advanced tab** and almost always means you missed a real option.

The tell (this happened): a column wasn't centring vertically, so a `selector{align-self:center
!important;height:auto !important;}` was jammed into the column's `custom_css`. But the column doc lists
a native **`align_self`** option ("column vertical align vs row siblings": start=Top, center=Middle,
end=Bottom) that emits exactly that — and separately a `content_v` (aligns contents WITHIN the column,
which needs the column stretched and hits an h-100/flex-height trap). The fix was one line:
`align_self:{base:'center'}` — a real option the user can see/edit in the Layout tab, not raw CSS.

Rules:
- **Map source intent → a documented native att** (check the doc's atts table). `custom_css` is the LAST
  resort, only for a genuinely unsupported source detail — and say so in a comment when you use it.
- **Know the semantic difference between similar options** before picking one (`align_self` = the column
  vs its siblings; `content_v` = the contents inside the column). The doc's one-line descriptions say
  which is which — read them.
- When the converter emits `selector{…}` custom_css, ask "is there an option for this?" first. If a doc
  exists and you didn't open it, you skipped a step.

## ⚠️ THE URL FLOW IS TWO STEPS — the origin MUST survive into step 2 (REQUIRED)

A URL conversion is **prepare → build**, and the imported page comes from **step 2**, not step 1:

1. **`_ajax_convert_prepare`** — captures the URL, runs `build_from_html($html, …, $opts)` (with
   `source_url` from `$_POST['fw_sc_source_url']`), and returns the **mapping** for the review UI.
2. **`_ajax_convert_build`** — takes the (possibly corrected) mapping and rebuilds via
   **`build_pages($mapping)`**. THIS is what imports. It re-derives the origin with
   `resolve_source_url($mapping, $stash)` and calls `set_source_url()` — standalone from step 1.

**The trap (this caused "images still not loading after another conversion" for many runs):** on a URL
conversion there is NO bundle.json/media.json and the source assets are **relative** (`/assets/*.svg`),
so every heuristic in `resolve_source_url()` returns `''` → `set_source_url('')` → step 2 ships bare
relative `/assets/…` that 404. Testing only step 1's `build_from_html` output (which HAD `source_url`)
looks perfect while the real import is broken. **The pasted URL must be STASHED in step 1 and honoured
first in `resolve_source_url()`** (branch: `$stash['source_url']`).

**How to verify (do BOTH steps, not just step 1):** reproduce prepare → stash → `set_source_url(
resolve_source_url($mapping,$stash))` → `build_pages($mapping)`, then assert **0** relative `/assets/`
in the BUILT pages (not the draft). See `tests/reproduce-conversion.php` / the two-step snippet. If you
only tested `build_from_html`, you tested the wrong half.

## ⚠️ NEVER DROP A CLASS / STYLE when fixing a rule (REQUIRED)

Whenever a converter rule builds or rewrites an element, it MUST carry the source's **look** across —
never emit a bare tag that renders with default styling. The source's styling lives in two places and
BOTH must survive:

1. **The source classes** (`font-display text-4xl text-purple-500 italic`, etc.), and/or
2. **The captured computed styles** (`data-sc-cs` on the element).

Helpers like `clean_inline_html()` **scrub classes**, and WordPress's `wp_kses_post` (which text /
footer / topbar elements run through) **strips inline styles it dislikes** (e.g. spaced `rgb(...)`
colours). So a rule that "works" on text content can still ship a visually-flat element. When you
add or change a rule:

- **Re-bake the source look INLINE** from `data-sc-cs` (use `cs_inline_style()` in stitch —
  font-family, size, weight, colour, line-height, italic, letter-spacing) so a scrubbed element keeps
  its appearance instead of falling back to the theme default.
- **Normalise colours to hex** (`rgb(12, 31, 64)` → `#0c1f40`) so `safecss_filter_attr` keeps them —
  `cs_inline_style()` already does this; do the same anywhere else you emit a colour.
- **Prove it survives kses**: verify the built value AFTER `wp_kses_post()` still carries the
  font/size/weight/colour — not just that the text is present. If a style vanishes post-kses, it's
  dropped, and the rule is **not** done.

The tell: user says "the text is right but the **classes/styling were dropped**" (it renders plain).
That means a rule carried content but not appearance — go back and bake the look inline + hex-normalise
+ re-verify post-kses.

## ▶ HOW TO REPRODUCE THE wp-admin RUN FROM THE CLI (the exact verify recipe)

You cannot click the `admin.php?page=fw-site-converter` button (no browser session). Instead drive the
**same two-step pipeline** the button drives, from the command line. This is the canonical way for ANY
agent (here or on another developer's machine) to run the deterministic converter and verify a fix.

### 🪙 TOKEN-CHEAP: use the reusable harness `tests/reproduce-conversion.php` (PREFERRED)

Do NOT hand-write throwaway PHP that `echo`s the whole built JSON — that floods your context with a
multi-MB blob and burns tokens every debug session. Instead run the shared harness, which writes the
full build to DISK and prints only a compact one-screen summary (section/footer/icon/code_block
counts + red flags). Pull only tiny slices into context afterwards.

```bash
# 1) capture FRESH (running service, or `node capture.mjs <url> <out>`)
curl "http://localhost:8787/capture?url=https://example.com/&html=1" > C:/tmp/rendered.html
# 2) reproduce the wp-admin build — COMPACT summary to stdout, full JSON to disk
SC_HTML='C:/tmp/rendered.html' SC_URL='https://example.com/' \
  php wp-cli.phar --path='D:\xampp\htdocs' eval-file \
  'D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/reproduce-conversion.php'
# 3) inspect ONE thing (small): either the on-disk dump…
#    Grep C:/tmp/rendered.html.built.json  for the value you care about
#    …or ask the harness for a subtree:
SC_HTML=… SC_URL=… SC_QUERY='main_footer_columns' php wp-cli.phar … eval-file …reproduce-conversion.php
```

Env vars: `SC_HTML` (Windows path to rendered.html — /d/… paths FAIL in Windows PHP), `SC_URL`
(required, becomes `source_url`), `SC_QUERY` (optional dot-path to print one subtree), `SC_OUT`
(optional dump path). The harness is the token-efficient front door; the raw recipe below is what it
does under the hood if you need to customise the assertions.

### Raw recipe (what the harness runs internally)

**Step 1 — CAPTURE the URL FRESH** (the button calls the running capture service; do the same):

```bash
# Option A: the running service (Node on :8787, started by start-converter.bat)
curl "http://localhost:8787/capture?url=https://amiable-arc-archive.lovable.app/&html=1" > svc-rendered.html
# Option B: a one-off capture (spawns capture.mjs itself)
cd "d:/Web Dev/UnysonPlus-AI-Dev-Kit/assembled/UnysonPlus-Capture-Service/tools/design-capture"
node capture.mjs https://amiable-arc-archive.lovable.app/ ./capture-out/   # rendered.html lands inside
```

**Step 2 — CONVERT via the SAME entry point the AJAX handler calls** (`build_from_html`, WITH the
pasted URL as `source_url` — omitting it is the #1 flow bug):

```php
// /tmp/reproduce.php — run with:  php wp-cli.phar --path='D:\xampp\htdocs' eval-file /tmp/reproduce.php
$html = file_get_contents('C:/Users/.../svc-rendered.html');
$res  = FW_Site_Converter_Sources::build_from_html(
    $html, 'Home',
    array( 'dynamic_chrome' => true, 'hifi_css' => true,
           'source_url' => 'https://amiable-arc-archive.lovable.app' ) // <-- MUST pass it
);
$ts = $res['files']['theme-settings.json'];              // inspect the BUILT output
$v  = isset($ts['values']) ? $ts['values'] : $ts;        // pages JSON also in $res['files']
// ...assert on $v (e.g. footer column text_content AFTER wp_kses_post — see the class/style rule above)
```

**Gotchas baked in (these bit us):**
- Use **Windows-style paths** (`D:/Web Dev/…`, `C:/Users/…`) in the PHP — Git-Bash `/d/…` paths FAIL
  in Windows PHP (`is_dir`/`ZipArchive` read errors).
- Always pass **`source_url`** — it's what lets the Mapper fetch+inline SVG icons and absolutize
  `/assets/*` (without it: broken icons, illustrations left as code blocks).
- Verify the built value **after `wp_kses_post()`**, not just raw (styles can vanish in kses — see the
  class/style rule above).
- This inspects the BUILT JSON; it does NOT do the final DB import. The user's click does that — so
  after a green CLI reproduction, **bump + mirror**, then have them re-run the wp-admin flow to confirm.

## Legit exception (call it out separately, it is NOT "fixing the converter")

Local **environment/config** fixes (e.g. enabling `extension=zip` so WordPress can unzip a bundle)
are legitimate **setup** — but they are infrastructure, not converter fixes, and must be reported as
such. They never count as "the converter is fixed."

## The tell

If the user says **"did another conversion, still broken"** after you said "fixed," you tested the
**wrong path**. Re-capture the URL **FRESH** and reproduce their exact flow **before** concluding
anything about whether the converter is fixed.
