# Homepage Manager tests

Run the module's rules with **no WordPress and no database** — the payoff of the ports design.
`wp-stubs.php` supplies the handful of WordPress functions the code touches (including a real
filter registry); everything else is the actual module code.

```bash
php tests/homepage-domain/run.php           # domain rules                (32)
php tests/homepage-domain/run-bridge.php    # theme binding               (24)
php tests/homepage-domain/run-handlers.php  # use cases + permissions     (40)
php tests/homepage-domain/run-porting.php   # revisions, undo, packages   (31)
php tests/homepage-domain/run-templates.php # templates + shared sections  (27)
php tests/homepage-domain/run-sections.php  # plugin-drawn sections       (54)
php tests/homepage-domain/run-design.php    # per-section design          (24)
php tests/homepage-domain/run-documents.php # landing pages               (20)
```

Exit code 0 = everything passed.

## `run.php` — domain and application

- a key the schema does not declare never reaches storage
- `javascript:` URLs, SVG attachments and out-of-range numbers are refused
- a field the actor may not write keeps its stored value **and is reported back**
- `max_instances` holds, a partial reorder is refused, a duplicate gets a new id
- publishing is the only thing that changes what a visitor sees
- an **empty document is valid** — the backward-compatibility guarantee

## `run-handlers.php` — the use cases

Runs every write handler against in-memory repositories. The permission matrix here is the most
important test in the module — it is the difference between "the UI hides the button" and "the
server refuses the request":

- a view-only role is refused create, edit, delete, reorder, duplicate and publish
- a section-scoped role (`homepage.section.hero.edit`) edits its own section and nothing else
- a stale `version` is refused with a conflict instead of overwriting someone's work
- `max_instances` holds, and duplicate is not a way around it
- a section whose component is no longer registered **survives a save** — deactivating a plugin
  must not make the whole homepage unsaveable (this test found exactly that bug)

## `run-porting.php` — history and packages

- a restore lands in the DRAFT and snapshots what it replaced, so it is itself reversible
- **undo publish** moves the LIVE page back one version and leaves the draft alone
- there is nothing to undo after the first publish, and it says so instead of breaking
- a diff reports a reorder as MOVED, not as a delete plus an add
- a package with a component this site does not have is refused WHOLE, never half-applied
- image references that do not resolve here are cleared and reported, never left dangling

## `run-templates.php` — the library

- a template applied mints a NEW section id, so using one twice is two sections, not a collision
- `max_instances` still applies: a template is not a way around a component's own limit
- applying, saving and deleting each need their own permission
- a whole-homepage template REPLACES the draft, needs delete + sort too, and snapshots first
- a **shared** section is inserted by reference, never copied — a "shared" section implemented as
  a copy stops being shared the first time anyone edits one of them
- a shared section cannot point at another shared section (a loop waiting to be closed)
- state, visibility and schedule stay per-page, so one shared band can run here and be paused there
- detaching gives the page a private copy in the same position, and leaves the shared one alone

## `run-sections.php` — the sections the plugin draws

Video, numbers and questions have no theme template, so the module renders them:

- a video band never autoplays, never preloads, and refuses to render without both a poster and a file
- an empty row in the numbers band is dropped rather than printed blank
- the FAQ is `<details>`/`<summary>` — it works before any script runs
- every value is escaped at output, and rich text is re-filtered on the way out as well as in
- a brands row with no logo, a CTA with no heading and a sale with no discounted products each
  render **nothing** rather than an empty band with a heading over it
- a `javascript:` URL never reaches the markup, and its button is dropped whole
- a hero slide outside its window is **removed on the server**, never hidden with CSS — hiding it
  would ship next week's campaign images to today's visitors
- the first hero slide loads eagerly at high priority (it is the page's LCP); the rest are lazy
- the product carousel renders nothing, and does not fatal, when WooCommerce is absent
- **structured data describes only what actually rendered** — an FAQ that bailed, or a question
  with no answer, contributes nothing. Describing content that is not on the page is not an SEO
  tactic; it is what manual actions are for.
- the video's `uploadDate` is the attachment's, not today's

## `run-design.php` — spacing, colour and motion

A stylesheet is executable text, so the assertions here are mostly about what must NOT come out
of it:

- `red;}body{display:none;}` compiles to **nothing** — it is neither a token nor a hex colour
- `expression(...)` never survives
- an SVG cannot be a background image
- an absurd animation delay is clamped, and an unknown animation falls back to none
- a colours-only role changes colours but not spacing, **and is told which permission it lacks**
- the `homepage.design.edit` umbrella covers every design field, so "can design" means can design
- an empty design payload emits no CSS and no wrapper at all — the untouched page is untouched

## `run-documents.php` — landing pages

A landing page is the same document with a different key, bound to a WordPress page. The rules that
keep a page builder from eating a site:

- the homepage document cannot be deleted, and cannot be bound anywhere else
- two layouts cannot claim the same page — that would make the visible one a coin toss
- a page nobody bound resolves to nothing, so ordinary pages are never touched
- everything except listing sits behind `homepage.settings`

## `run-bridge.php` — the theme seam

- with nothing in context, every `yazan_home_*` filter passes the theme's own value through
- a published section supplies its content; an **empty field keeps the theme's copy**
- two blocks of the same type read their own content, not each other's
- category cards, trust items and product-query arguments come out in the exact shape each
  template already expects
- an empty manual product list stays empty instead of becoming the whole catalogue
