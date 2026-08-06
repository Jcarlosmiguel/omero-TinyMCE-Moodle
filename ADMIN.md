# Administering "OMERO slide embed" - a guide for administrators and managers

This is for whoever configures the plugin day-to-day: Site administrators,
or anyone granted `local/omeroembed:managesettings` (Manager role by
default). If you're a teacher wanting to embed a slide, see
[USAGE.md](USAGE.md) instead. For installation, see [README.md](README.md).

## Finding the settings page

Both pages live under **Site administration > Plugins**:

- **If you're a Site administrator**: **Local plugins > OMERO slide
  embed** - the full raw settings form (every setting below, including
  `showomerorois`, `enableannotations`, `enablehotspot`, and the heatmap
  data retention period).
- **If you have `local/omeroembed:managesettings` but aren't a Site
  administrator**: look for **"OMERO slide embed settings"** directly
  under **Plugins** (you won't see "Local plugins" itself, or anything
  else under Site administration, unless you're also a Site
  administrator - this one page is the only thing this capability grants
  access to). It reads and writes the *same underlying config values* as
  a subset of the full form - just the OMERO base URL, the 6 overlay
  hide/show checkboxes, and the annotation colour palette. Whichever page
  last saved a shared setting wins; there's nothing to keep in sync
  between them.

## What each setting does

### OMERO base URL

The real OMERO.web server this plugin talks to, e.g.
`https://your-omero-server.example.org`. Students never see this - every
request is proxied through Moodle, so this address (and the credentials
below) never reach a student's browser.

### Subject accounts - not an admin/manager setting

Subject accounts (the shared OMERO service-account credentials teachers
pick from a dropdown when embedding a slide) are **not** configured on
this page or via `manage.php` - each teacher adds, edits, and deletes
their own from **local/omeroembed/mysubjects.php** (linked from the
authoring tool), gated by the same `moodle/course:manageactivities`
capability that already lets them edit that course. There is no
site-wide subject-account list for an administrator or Manager to
maintain, and nothing here needs updating when a teacher rotates a
password or adds a new one themselves.

If a teacher reports a subject they need isn't available, or an
"unknown subject" error, the fix is for them (or another teacher in that
course) to add it themselves via `mysubjects.php` - not something an
admin/manager needs to do on their behalf.

### Embedded viewer overlays

Six checkboxes, each hiding one on-image control - purely cosmetic, no
effect on pan/zoom, view-links, or the opening view teachers set up.

**This is only a starting point for new embeds, not a live global
override.** Changing one of these settings never touches anything a
teacher has already published:

- Every embed is generated through the authoring tool (`author.php`),
  which pre-fills its overlay checkboxes from whatever this setting
  currently is - but the teacher can freely change any of them before
  clicking "Generate embed HTML".
- Whatever the checkboxes show at that moment gets written permanently
  into that specific embed's own stored HTML, as an explicit choice - not
  a reference back to this setting.
- So changing this setting later only changes what a teacher sees as the
  *starting* checkbox state the next time they build a **new** embed.
  Every embed already published elsewhere keeps behaving exactly as it
  was set up, indefinitely, regardless of what this page says afterwards.
- The one exception: an embed built with an older version of the plugin,
  before a particular checkbox existed at all, has no explicit choice
  baked in for that one setting - it keeps following whatever this page
  says until someone reopens and re-saves it through the authoring tool.

| Setting | What it hides | Recommendation |
|---|---|---|
| Hide overview map | Small inset thumbnail of the whole image | Usually fine to hide - most people find it more distracting than useful for a single embedded slide |
| Hide coordinate/zoom readout | Diagnostic pixel position readout | Safe to hide - the authoring tool reads this value directly, not by displaying it, so hiding it doesn't break "Insert view link" or "Set as opening view" |
| Hide full-screen button | Full-screen toggle | Consider leaving visible - useful for students wanting to see fine detail |
| Hide scale bar | Real-world size reference (e.g. "5 mm") | Consider leaving visible - often pedagogically useful for judging magnification |
| Hide zoom controls | Zoom in/out, "1:1", zoom % input | **Not just cosmetic** - hiding this removes the ability to zoom interactively at all. Only enable if an embed is meant to show one fixed view with no student interaction |
| Hide OMERO top navigation bar | OMERO.web's own File/ROIs/Help menu bar - not part of the slide viewer | **Recommended, and on by default** - its links point outside this locked-down embed and don't work correctly here |

The slide's rotate control isn't in this list because it isn't configurable at all - it's always hidden, unconditionally, regardless of any setting here (there's no way to actually control rotation from this embed, so a visible control for it would just be confusing clutter).

## Granting settings access to someone who isn't a Site administrator

By default `local/omeroembed:managesettings` is granted to the Manager
role. To give it to one specific person without making them a Manager
(e.g. whoever administers OMERO but has no other Moodle admin duties):

1. Site administration > Users > Permissions > Define roles > Add a new
   role, based on **"No roles"** (starts with zero inherited
   capabilities, so this person gets *only* what you explicitly allow).
2. Set `local/omeroembed:managesettings` to **Allow**; leave everything
   else at its default.
3. Site administration > Users > Permissions > Assign system roles > pick
   the new role > add that person.

This must be assigned at the **system context**, not a course or category
- these are site-wide settings, and the check happens at system context
specifically.

## Performance overhead

Measured directly (2026-08-05, re-verified as still valid 2026-08-06 after
the privacy fix, the `$plugin->supported`/`$plugin->dependencies`
declarations, and the 4.5/5.2 branch collapse - none of that touches page
rendering, only install/upgrade-time checks and GDPR export/delete
requests): the cost of rendering a page containing the OMERO `<iframe>`
tag, versus an otherwise-identical page without one.

| | Overhead | 95% CI | Page weight | Paired samples |
|---|---|---|---|---|
| Moodle 4.5.12 | +0.3ms (not significant) | [-0.2, +0.9] | +0.7KB | 45 |
| Moodle 5.2.1  | +1.0ms (barely significant) | [+0.1, +1.6] | +0.7KB | 45 |

Both negligible, and statistically indistinguishable from each other (the
two confidence intervals overlap) - no evidence the plugin got more
expensive on 5.2. Method: paired A/B on the same course, interleaved
sampling, bootstrap confidence intervals (5000 iterations), each Moodle
instance isolated with 8 CPU / 16GB pinned to its webserver and 4 CPU /
8GB to its database, tested one at a time (the other instance's
containers fully paused) to rule out resource contention as a confound.

**What this figure doesn't cover, and why - two separate things, not one:**

- `proxy.php`'s own round-trip cost to the real OMERO server. A page's
  initial load never fetches its own `<iframe>`'s `src` - only a real
  browser does, once the page is in front of a student. No local test
  environment could reach the real OMERO server to measure that half
  separately (network topology, not a plugin limitation).
- **Viewport tracking (the heatmap feature's sampling).** For the same
  reason as the OMERO round-trip: `js/track.js` runs *inside* the
  `<iframe>`, posting samples via the browser during an open viewing
  session - never triggered by a page's initial render, on or off. Unlike
  the OMERO round-trip, this half genuinely is measurable locally - it's
  a plain Moodle-side DB write, no external server involved - and it's
  the number that matters most for capacity planning, since it scales
  directly with concurrent students, not with page views.

  Measured directly (as a real student account, not a Manager - tracking
  intentionally never records for anyone with
  `moodle/course:manageactivities`, so testing as a teacher/manager
  account would silently hit the no-op path instead of the real write):
  one `action=sample` POST, tracking genuinely active, real `INSERT` into
  `local_omeroembed_view_samples` confirmed (`recorded: true` on every
  request) - **median 25.0ms** (30 samples, stdev 0.4ms). Sampling runs
  every `SAMPLE_INTERVAL_MS` (5000ms) while a student's tab is visible and
  tracking is active for that embed - **12 rows per actively-tracked
  student per minute**. For a class of 50 students all viewing a tracked
  embed simultaneously for a 10-minute session, that's 6,000 rows and
  roughly 150 seconds of aggregate write time spread across that window -
  what the `retentionperiod` setting is actually holding back from
  accumulating indefinitely.

## Security

A per-file audit of every web-reachable entry point (`ajax.php`,
`author.php`, `export.php`, `heatmap.php`, `video.php`, `proxy.php`) ahead
of Marketplace submission, 2026-08-06. Two real, exploitable issues were
found and fixed; two broader checks came back clean.

**Fixed - stored XSS in the generated embed HTML.** `author.js`'s
`generateEmbed()` built the final embed HTML (the string saved into course
content and rendered to every student who views it) by string
concatenation, and the width/height form fields flowed into `style="..."`
attributes unescaped. A crafted link with a malicious width/height value
could inject a real `<script>` tag into persisted, student-facing content.
Fixed in two layers: `author.js` now HTML-escapes every value it
concatenates into the generated markup, and `author.php` independently
validates width/height against a genuine CSS-length pattern server-side.
Verified against the actual attack payload, not just asserted clean.

**Fixed - cross-course broken access control.** Every privileged action
(`hotspot_get`/`save`/`clear`, the multi-region siblings, `heatmap.php`,
`export.php`, `video.php`) checked the requester's capability against
whichever `courseid` was declared in the request, then read or wrote data
keyed by `embedid` alone - with no check that the embedid actually
belonged to that course. Anyone holding `hotspotauthor` or `viewheatmap`
in *any* course could, given another course's embedid, read a hidden
quiz answer region, overwrite or delete it outright, or view/export
another course's gathered student tracking data. Fixed by verifying each
embedid's real, stored courseid matches the declared one before any
operation proceeds. Verified end-to-end against live production with real
cross-course test data - the exploit attempt, the read case, and the
sabotage-by-overwrite case were all confirmed blocked, with legitimate
same-course access continuing to work normally throughout.

**Checked, no issues found - SQL injection.** Every database interaction
in the codebase (90 call sites across 14 files) goes through Moodle's
parameterised DML API or, in the Privacy API implementation, raw SQL with
named placeholders - the sanctioned pattern for that specific API. Zero
instances of a variable concatenated directly into a query string.

**Checked, no issues found - encryption at rest.** OMERO subject account
passwords (`local_omeroembed_subjects.omeropassword`) are encrypted via
Moodle core's `\core\encryption` class, which uses libsodium. The key is
a 32-byte file stored entirely outside the database
(`$CFG->dataroot/secret/key/sodium.key`), permissioned `-r--------`
(owner-only, not even writable after creation) - a database-only
compromise (a leaked backup, a leaked DB credential) is not sufficient on
its own to decrypt any stored password; separate filesystem access to the
Moodle dataroot is required too.

## Known limitations

### A stale session costs one extra request, not more than that

Every request to OMERO is made using a cached, shared session (per
subject, not per student). If that session has quietly expired on OMERO's
own side, the plugin detects this and automatically re-authenticates once
before giving up - this is transparent to students (they'd never see an
error from it), but the *very first* request after a long idle period
pays the cost of that extra round trip. This is a self-contained
behaviour of the plugin itself - nothing needs to be changed on the OMERO
server for it to work correctly.

### New settings need a first save before their defaults apply

This matters most right after installing or upgrading the plugin. Moodle
only reads a setting's value from what's actually saved in the database -
a checkbox's "default" only exists to pre-fill the form the very first
time you look at it, not something the plugin reads on its own if nobody
has ever saved that page. **After installing or upgrading, open the
settings page and click Save changes once, even without changing
anything** - this is what actually writes the current defaults (including
the "Embedded viewer overlays" checkboxes) into the database.

### omero-iviewer version compatibility

This plugin works around a real bug in the specific omero-iviewer release
it was built and tested against (see [README.md](README.md)'s
Requirements section for the exact version and technical detail) - a
mechanism iviewer is *meant* to support for exactly this reverse-proxy
scenario silently does nothing due to a bug in iviewer itself, so the
plugin achieves the same result a different way. If OMERO or iviewer gets
upgraded on the server side, it's worth re-confirming slides still load
correctly - if a future iviewer release fixes that upstream bug, this
plugin's workaround is harmless either way, but the viewer overlay
checkboxes above (which target specific CSS class names) are the more
likely thing to need a small update if a much newer iviewer changes its
own internal structure.

### No custom display name for a duplicated/re-used slide

Not something this plugin adds a workaround for - the embed shows the
image's own name from OMERO, whatever it was named there.

## Troubleshooting

**"Image not found" when loading a slide.** Usually resolves itself
automatically (see "stale session" above) - if it persists after a
refresh, check: the Image ID/Dataset ID actually exist and are correct,
the subject account still has permission to view them in OMERO, and its
username/password (in that teacher's own **mysubjects.php**, not a
site-wide setting) are still correct.

**A subject doesn't appear in the authoring tool's dropdown, or shows an
"unknown subject" error.** Subject accounts are teacher-owned, not
something to look for here - the teacher (or another teacher in that
course) needs to add it themselves via **mysubjects.php**, linked from
the authoring tool.

**A setting doesn't seem to be taking effect right after install/upgrade.**
See "New settings need a first save" above - open the settings page and
save once.
