# qtype_omerohotspot

A Moodle quiz question type: the student answers by clicking directly on a
whole-slide OMERO microscopy image, not by picking from a list. Correct if
the click lands inside a single region the teacher marked as the answer -
which stays hidden from the student at all times, including in review.

This plugin has a hard runtime dependency on
[local_omeroembed](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/local_omeroembed),
which must be installed alongside it - it renders the actual slide by
calling directly into that plugin's `proxy.php`. It cannot function
without it.

## Why

Picking a correct answer from a list doesn't test whether a student can
actually *find* something on a real slide. This mirrors a real feature
from Leica/Slidepath's discontinued Digital Image Hub - a genuinely
useful capability for pathology training that a text-based question can't
replicate.

## Features

- The teacher draws the hidden correct-answer region directly on a live,
  pannable/zoomable preview of the real slide, using the same authoring
  UI `local_omeroembed`'s own standalone hotspot feature uses - resize
  (drag any of its 4 square corner handles) and rotate (drag the round
  handle above it) are the only two ways to adjust it once drawn, both
  staying centred on where it was originally drawn - **there's no way to
  drag a region to a different spot**; drawn in the wrong place means
  delete and redraw, not move. Two more details worth knowing before
  drawing one: the draw gesture starts at the region's *centre*, not a
  corner - press where the feature actually is, then drag outward to set
  its size, rather than dragging corner-to-corner like most drawing
  tools; and switching the ellipse/rectangle mode button off again
  (click it a second time) is what lets a click select an existing
  region for editing instead of starting a new one. See [the full visual
  walkthrough](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/USAGE.md#drawing-and-adjusting-a-region-step-by-step)
  for the whole sequence with screenshots.
- **Opening view** - pan/zoom the slide to a starting position in the
  question editing form and click **Set as opening view**; a student then
  sees that position, rather than OMERO's own default view, when they
  reach the question. Independent of the hidden region itself, and
  entirely optional.
- Plugs into Moodle's normal question bank, gradebook, and quiz review
  flow like any other question type - including getting its own quiz
  page by default, kept separate from whatever else is in the quiz (a
  full slide viewer sharing a page with another question reads as
  genuinely confusing, not just untidy). Only a default, not a lock - a
  teacher can still manually join it back onto a shared page afterward.
- The hidden region is never sent to a student's browser under any
  circumstance, including question review - only a plain correct/incorrect
  result ever reaches the client.
- See
  [qtype_omerohotspotmulti](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/qtype_omerohotspotmulti)
  for the sibling question type where any one of *several* marked regions
  counts as correct, for cases where more than one location on a slide is
  legitimately correct.

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
cp -r omero-tinymce-moodle/qtype_omerohotspot question/type/omerohotspot
php admin/cli/upgrade.php --non-interactive
```

**Moodle 5.1+**: use `public/question/type/omerohotspot` as the copy
target instead.

## Usage

Add an "OMERO hotspot" question in any quiz's question bank, pick a
subject account and image the same way you would in
`local_omeroembed`'s own authoring tool, and draw the correct region on
the live preview - rotate/resize it in place with the same handles as
the standalone embed feature, and optionally click **Set as opening
view** to choose the slide position a student sees first - before
saving.

## License

GNU General Public License v3 or later (GPL-3.0-or-later) - see
[LICENSE](LICENSE). Required for any plugin distributed via the official
Moodle plugins directory.

## Copyright

Copyright (C) 2026 University of Glasgow MVLS.
