# Administering "OMERO slide embed" - a guide for administrators and managers

This is for whoever configures the plugin day-to-day: Site administrators,
or anyone granted `local/omeroembed:managesettings` (Manager role by
default). If you're a teacher wanting to embed a slide, see
[USAGE.md](USAGE.md) instead. For installation, see [README.md](README.md).

## Finding the settings page

- **If you're a Site administrator**: Site administration > Plugins >
  Local plugins > OMERO slide embed.
- **If you have `local/omeroembed:managesettings` but aren't a Site
  administrator**: look for **"OMERO slide embed settings"** in the top
  navigation bar's **"More"** menu, or in the drawer menu on the left.
  Both pages read and write the exact same settings - it doesn't matter
  which one you use, or if different people use different ones.

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
Applies everywhere the slide is shown - the authoring tool's own live
preview and the final student-facing embed both use the same setting.

| Setting | What it hides | Recommendation |
|---|---|---|
| Hide overview map | Small inset thumbnail of the whole image | Usually fine to hide - most people find it more distracting than useful for a single embedded slide |
| Hide rotate control | "Reset rotation" arrow | Usually fine to hide - rarely relevant for flat slide images |
| Hide coordinate/zoom readout | Diagnostic pixel position readout | Safe to hide - the authoring tool reads this value directly, not by displaying it, so hiding it doesn't break "Insert view link" or "Set as opening view" |
| Hide full-screen button | Full-screen toggle | Consider leaving visible - useful for students wanting to see fine detail |
| Hide scale bar | Real-world size reference (e.g. "5 mm") | Consider leaving visible - often pedagogically useful for judging magnification |
| Hide zoom controls | Zoom in/out, "1:1", zoom % input | **Not just cosmetic** - hiding this removes the ability to zoom interactively at all. Only enable if an embed is meant to show one fixed view with no student interaction |

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
