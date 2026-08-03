# omero-TinyMCE-Moodle

A Moodle plugin that lets teachers embed enrolment-gated OMERO.iviewer
slides directly into course content - Labels, Pages, Books, anywhere the
normal Moodle content editor works - with no HTML or URL typing, no OMERO
account for students, and no public/unauthenticated OMERO access.

## Why

The previous approach (OMERO's own public-account iframe embeds) meant
anyone with the URL could view course slide material, whether enrolled or
not. This plugin reverse-proxies OMERO.iviewer through Moodle itself: every
request re-checks the student's real Moodle session and course enrolment
server-side, and no OMERO credentials, session cookies, or direct OMERO
URLs are ever exposed to the browser.

## Features

- **No HTML or URLs to hand-type.** The "Embed an OMERO slide" authoring
  tool (`author.php`) gives teachers a live, pannable/zoomable preview next
  to a write-up box, and generates ready-to-paste embed HTML.
- **Multi-view slides.** Select text in the write-up, click "Insert view
  link", and that text becomes a link that jumps the slide to the exact
  pan/zoom position it was at - the same "named iframe + side-by-side
  write-up" pattern already used for teaching, just without needing to
  hand-build the HTML/URLs.
- **A settable opening view**, independent of any inserted links - choose
  what position the embed first loads on.
- **Three layout modes**: slide-left/text-right, text-left/slide-right, or
  slide-only (no write-up). Switching between them is non-destructive -
  nothing already written is ever lost.
- **Preview width matches reality.** The live preview (and the generated
  embed) are constrained to a configurable max-width approximating a real
  course page's content column, not the authoring tool's own (much wider)
  page - so a view chosen while authoring looks right once published.
- **Individually hideable viewer overlays** (overview map, rotate, zoom
  controls, scale bar, coordinate readout, full-screen) to reduce clutter
  for students - purely cosmetic, no effect on pan/zoom or view-links.
- **Two ways to configure the OMERO server, subject accounts, and overlay
  settings**: the standard Moodle Site administration page, or a dedicated
  page (`manage.php`) gated by a new capability
  (`local/omeroembed:managesettings`, Manager role by default) - lets
  someone who isn't a full Site administrator (e.g. whoever actually
  administers the OMERO side) manage these settings without needing
  site-wide config access.
- **Self-healing OMERO sessions.** If a cached session has quietly expired
  on OMERO's side, the proxy detects it and transparently re-authenticates
  once before giving up, rather than surfacing a confusing error.
- **Discoverable, not just URL-only.** Both `author.php` and `manage.php`
  are linked from Moodle's own navigation (course nav and primary nav
  respectively), each gated by the exact same capability check the target
  page itself makes.

## Subject accounts, not personal OMERO logins

Students never authenticate to OMERO at all - every embed is served using
one of a small number of shared "subject" service accounts (e.g. one per
department/subject area), configured centrally. Access control is entirely
on the Moodle side: `proxy.php` re-derives the real, current Moodle session
and checks enrolment against the real course on every single request - it
cannot be bypassed by editing the embed's URL, since the course ID only
selects *which* course to check enrolment against, not whether that check
happens.

## Requirements

- Moodle 4.5+ (developed and tested against 4.5.12)
- OMERO.web with omero-iviewer installed (developed and tested against
  OMERO 5.22.1 / omero-iviewer v0.17.0)
- PHP curl extension (used for all server-to-server OMERO requests)

**Compatibility note on omero-iviewer:** this plugin works around a real
bug in the exact iviewer version above - its own
`Context.processInitialParameters()` reads a `REQUEST_PARAMS.HOST` key that
doesn't actually exist in `REQUEST_PARAMS` (confirmed directly against that
release's source), so a mechanism iviewer is *meant* to support for exactly
this reverse-proxy scenario silently does nothing. `proxy.php` works around
it entirely from the Moodle side (see `inject_server_workaround()` and its
docblock) - if a future iviewer release fixes this upstream, the workaround
is inert (it just sets an unused property) rather than harmful, but the
overlay-hiding CSS selectors (OpenLayers' own standard control classes) are
worth re-confirming against any significantly newer iviewer version.

## Installing

```bash
git clone https://github.com/Jcarlosmiguel/moodle-omero-embed.git local/omeroembed
php admin/cli/upgrade.php --non-interactive
```

Then configure the OMERO server, subject accounts, and viewer overlay
settings, either as a Site administrator (Site administration > Plugins >
Local plugins > OMERO slide embed) or - once granted
`local/omeroembed:managesettings` - via **local/omeroembed/manage.php**,
linked as "OMERO slide embed settings" in the site's top navigation bar's
**"More"** menu (and the drawer menu) for anyone with that capability.

- **OMERO base URL** - the real OMERO.web server, e.g.
  `https://your-omero-server.example.org`.
- **Subject accounts** - one per line, `subject_key|username|password`.
  These are OMERO service-account credentials, used server-side only -
  never sent to students' browsers.

### Granting settings access to someone who isn't a Site administrator

By default `local/omeroembed:managesettings` is granted to the Manager
role. To give it to one specific person without making them a Manager
(e.g. whoever administers the OMERO side but has no other Moodle admin
duties), create a narrow custom role instead:

1. Site administration > Users > Permissions > Define roles > Add a new
   role, based on **"No roles"** (so it starts with zero inherited
   capabilities).
2. Set `local/omeroembed:managesettings` to **Allow**; leave everything
   else at its default.
3. Site administration > Users > Permissions > Assign system roles > pick
   the new role > add that person.

This must be assigned at the **system context** - these are site-wide
settings (not tied to any one course), and `manage.php` checks the
capability at system context specifically.

## Usage

See [USAGE.md](USAGE.md) for a teacher-facing walkthrough with more context
on *why* each step matters. Quick version:

1. In any course you can edit, open the secondary navigation's **"More"**
   dropdown and click **"Embed an OMERO slide"** (or go directly to
   `local/omeroembed/author.php?courseid=<id>`). Only visible to accounts
   with edit rights in that course - students never see this link.
2. Pick a subject account and an image ID and/or dataset ID, choose a
   layout, and click **Load slide**.
3. Pan/zoom the live preview to a view worth calling out, select the
   relevant text in the write-up box, and click **Insert view link**.
   Repeat for as many views as needed on the same image.
4. Optionally pan/zoom to a starting position and click **Set as opening
   view** - independent of any inserted links, this is just what the
   embed shows when it first loads.
5. Click **Generate embed HTML**, then **Copy to clipboard**.
6. Paste into any Moodle content editor (a Label, a Page, a Book chapter,
   ...) and save. Clicking a view-link reloads only that slide, not the
   whole page.

## Known limitations

See [ADMIN.md](ADMIN.md) for the full list with explanations and what to
do about each one - briefly: a stale OMERO session costs one extra request
on self-heal rather than failing outright, new settings need a first save
before their defaults take effect, and there's no way to give a
duplicated/re-used slide a different display name beyond OMERO's own.

## License

GNU General Public License v3 or later (GPL-3.0-or-later) - see
[LICENSE](LICENSE). Required for any plugin distributed via the official
Moodle plugins directory.

## Copyright

Copyright (C) 2026 University of Glasgow MVLS.
