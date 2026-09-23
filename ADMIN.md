# Administering "OMERO slide embed" - a guide for administrators

This is for Site administrators configuring the plugin. If you're a
teacher wanting to embed a slide, see [USAGE.md](USAGE.md) instead. For
installation, see [README.md](README.md).

## Finding the settings page

**Site administration > Plugins > Local plugins > OMERO slide embed.**
This is the only settings page this plugin has (as of 1.7.0) - it's
deliberately Site-administrator-only, with exactly two settings.

*(In earlier versions there was a second, capability-gated settings page
reachable by non-administrators, plus several more settings than the two
below - both were removed. Every one of the removed settings was really
just a site-wide *default* for something a teacher already chooses
per-embed in the authoring tool directly, e.g. which viewer controls are
visible, or whether hotspot questions are available - real feedback
found this genuinely confusing to a site administrator with no obvious
reason to expect two settings pages for the same plugin, so it was
simplified rather than re-explained. None of the underlying features
went away - see [USAGE.md](USAGE.md) for how a teacher controls them.)*

## What each setting does

### OMERO base URL

The real OMERO.web server this plugin talks to, e.g.
`https://your-omero-server.example.org`. Students never see this - every
request is proxied through Moodle, so this address (and the credentials
below) never reach a student's browser.

### Data retention

How long gathered heatmap viewing data (and the periodic heatmap video
frames generated from it) is kept before a daily scheduled task deletes
it automatically. See "Performance overhead" below for the real numbers
behind why this matters - it's the only thing keeping the tracking
feature's storage bounded over time, not a fixed cap on any individual
class.

### Subject accounts - not an admin setting

Subject accounts (the shared OMERO service-account credentials teachers
pick from a dropdown when embedding a slide) are **not** configured on
this page - each teacher adds, edits, and deletes their own from
**local/omeroembed/mysubjects.php** (linked from the authoring tool),
gated by the same `moodle/course:manageactivities` capability that
already lets them edit that course. There is no site-wide subject-account
list for an administrator to maintain, and nothing here needs updating
when a teacher rotates a password or adds a new one themselves.

If a teacher reports a subject they need isn't available, or an
"unknown subject" error, the fix is for them (or another teacher in that
course) to add it themselves via `mysubjects.php` - not something an
admin needs to do on their behalf.

## Restricting who sees the "OMERO embed" button in TinyMCE

This plugin is a niche one - most teachers on a given site may never use
it, so you may not want its button cluttering every TinyMCE toolbar. You
already have three levers for this, all built on Moodle's own permission
system - no plugin setting to configure.

**The default**: the button is already teacher/manager-only. It's gated
by a dedicated capability, `tiny/omeroembed:embed`, which by default is
allowed only for the Teacher (editing) and Manager roles - students never
see it, out of the box.

**Narrowing to specific courses or categories**: `tiny/omeroembed:embed`
is checked at the course-module context, so a permission override applied
at a category or course context (that category/course's own
**Permissions** page, or Site administration's own permissions screen for
that context) hides the button everywhere below it, without touching the
Teacher role site-wide. Useful for e.g. "only courses in the Pathology
category should ever see this."

**Narrowing to specific people**: create a new role built on Moodle's own
permission system rather than editing the Teacher role directly:

1. Site administration > Users > Permissions > Define roles > Add a new
   role, based on **"No roles"** (starts with zero inherited
   capabilities, so this person gets *only* what you explicitly allow).
2. Set `tiny/omeroembed:embed` to **Allow**.
3. Site administration > Users > Permissions > Assign system roles (or
   the equivalent category/course-level permissions page) > pick the new
   role > add that person. This capability can be assigned at **system,
   category, or course** context, whichever is appropriate.

**Turning it off entirely**: if you want the button gone for absolutely
everyone, including Managers, that's a plugin-level toggle rather than a
permission - Site administration > Plugins > Text editors > TinyMCE
editor > General settings lists every installed TinyMCE subplugin with
an enable/disable switch; "OMERO embed" is one of them.

**What restricting the button does and doesn't affect**: this only
controls who can *author new* embeds via the toolbar. It has no effect on
embeds already published - those keep rendering for every student exactly
as before, regardless of who can currently see the authoring button. It's
also cosmetic decluttering, not this plugin's actual security boundary:
the authoring tool the button opens independently requires
`moodle/course:manageactivities` regardless of `tiny/omeroembed:embed`,
so there's no risk of under-restricting by adjusting this capability
alone.

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

A per-file audit of every web-reachable entry point ahead of Marketplace
submission, 2026-08-06, plus a follow-up review. Three real issues were
found and fixed; two broader checks came back clean.

**If you're running a version older than `2026080307`, upgrade** - all
three fixes below landed in that release.

**Fixed - a content-injection issue in the slide-embedding flow.** Certain
input to the authoring tool could end up persisted into course content and
rendered back out without being properly escaped. Fixed with proper
escaping plus stricter server-side validation, verified against a real
attack payload before and after the fix.

**Fixed - a cross-course access control gap affecting hotspot and heatmap
data.** A capability check against one course didn't guarantee the data
being acted on actually belonged to that course, which could expose or
allow modification of another course's hidden hotspot answers or gathered
tracking data. Fixed by verifying the data's real owning course before any
operation proceeds, with automated regression tests added specifically to
stop this recurring, and verified end-to-end against real cross-course
test data - the exploit attempt, the read case, and the
sabotage-by-overwrite case were all confirmed blocked, with legitimate
same-course access continuing to work normally throughout.

**Fixed - a concurrency issue affecting slide-loading performance.** Every
request through the plugin held Moodle's session lock longer than
necessary, causing concurrent requests from the same session - notably
the many parallel tile fetches a student's browser issues while panning
or zooming a slide - to queue behind each other instead of running in
parallel, regardless of server capacity. Fixed by releasing the lock as
soon as it's no longer needed. Verified directly: a controlled test of
the exact mechanism went from serialised to fully parallel once fixed.

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
anything** - this is what actually writes the current defaults into the
database.

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
