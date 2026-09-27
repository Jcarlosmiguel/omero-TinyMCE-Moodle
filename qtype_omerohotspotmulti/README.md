# qtype_omerohotspotmulti

A Moodle quiz question type: the student answers by clicking directly on
a whole-slide OMERO microscopy image, not by picking from a list. Correct
if the click lands inside *any one* of several regions the teacher marked
as acceptable answers - which stay hidden from the student at all times,
including in review.

A sibling of
[qtype_omerohotspot](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/qtype_omerohotspot),
not a mode of it - see that plugin's own README for the single-region
version. This plugin has a hard runtime dependency on
[local_omeroembed](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/local_omeroembed),
which must be installed alongside it - it renders the actual slide by
calling directly into that plugin's `proxy.php`. It cannot function
without it.

## Why

Forcing a single "one true spot" answer is unfair when a real slide
legitimately contains several equally correct examples of the same
feature - e.g. asking a student to find a cell with carcinogenic
characteristics on a tissue slide that may genuinely contain more than
one. This mirrors a real feature from Leica/Slidepath's discontinued
Digital Image Hub.

## Features

- The teacher marks as many acceptable regions as the slide needs,
  directly on a live, pannable/zoomable preview of the real slide, using
  the same authoring UI `local_omeroembed`'s own standalone multi-region
  hotspot feature uses - rotate (drag the round handle above the
  currently-selected region) and resize (drag any of its 4 square corner
  handles) are the only two ways to adjust one once drawn, both staying
  centred on where it was originally drawn - **there's no way to drag a
  region to a different spot**; drawn in the wrong place means delete
  and redraw, not move. A few more details worth knowing before drawing:
  the draw gesture starts at the region's *centre*, not a corner - press
  where the feature actually is, then drag outward to set its size,
  rather than dragging corner-to-corner like most drawing tools; the
  ellipse/rectangle mode button you used stays switched on after
  drawing, so you can draw several regions in a row - switch it off
  again (click it a second time) before clicking a region to select it
  for editing, or the click just starts a new region instead; and only
  the currently-selected region shows its rotate/resize handles, with
  "Delete region" only ever removing that one. See [the full visual
  walkthrough](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/USAGE.md#drawing-and-adjusting-a-region-step-by-step)
  for the whole sequence with screenshots.
- **Opening view** - pan/zoom the slide to a starting position in the
  question editing form and click **Set as opening view**; a student then
  sees that position, rather than OMERO's own default view, when they
  reach the question. Independent of the marked regions themselves, and
  entirely optional.
- A click is correct if it lands inside *any* marked region - a plain
  any-of-N model, not a "find all N" checklist exercise, and no partial
  credit either way.
- Plugs into Moodle's normal question bank, gradebook, and quiz review
  flow like any other question type - including getting its own quiz
  page by default, kept separate from whatever else is in the quiz (a
  full slide viewer sharing a page with another question reads as
  genuinely confusing, not just untidy). Only a default, not a lock - a
  teacher can still manually join it back onto a shared page afterward.
- The marked regions are never sent to a student's browser under any
  circumstance, including question review - only a plain correct/incorrect
  result ever reaches the client.

## Requirements

- Moodle 4.5+ (developed and tested against 4.5.12; also verified against
  5.2.1)
- [local_omeroembed](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/local_omeroembed)
  installed and configured - required, not optional.

## Installing

This plugin is one of four bundled together in a single repository - see
[the repository root README](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle#installing)
for the full four-plugin install (recommended, since this plugin cannot
function without `local_omeroembed`). This component alone:

```bash
git clone https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle.git omero-tinymce-moodle
cp -r omero-tinymce-moodle/qtype_omerohotspotmulti question/type/omerohotspotmulti
php admin/cli/upgrade.php --non-interactive
```

**Moodle 5.1+**: use `public/question/type/omerohotspotmulti` as the copy
target instead.

## Usage

Add an "OMERO hotspot (multi-region)" question in any quiz's question
bank, pick a subject account and image the same way you would in
`local_omeroembed`'s own authoring tool, and draw as many correct regions
as needed on the live preview - select a region to rotate/resize it in
place with the same handles as the standalone embed feature, and
optionally click **Set as opening view** to choose the slide position a
student sees first - before saving.

## License

GNU General Public License v3 or later (GPL-3.0-or-later) - see
[LICENSE](LICENSE). Required for any plugin distributed via the official
Moodle plugins directory.

## Copyright

Copyright (C) 2026 University of Glasgow MVLS.
