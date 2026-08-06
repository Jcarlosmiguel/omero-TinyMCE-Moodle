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
  hotspot feature uses.
- A click is correct if it lands inside *any* marked region - a plain
  any-of-N model, not a "find all N" checklist exercise, and no partial
  credit either way.
- Plugs into Moodle's normal question bank, gradebook, and quiz review
  flow like any other question type.
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
as needed on the live preview before saving.

## License

GNU General Public License v3 or later (GPL-3.0-or-later) - see
[LICENSE](LICENSE). Required for any plugin distributed via the official
Moodle plugins directory.

## Copyright

Copyright (C) 2026 University of Glasgow MVLS.
