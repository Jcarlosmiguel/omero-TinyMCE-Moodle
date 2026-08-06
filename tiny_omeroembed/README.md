# tiny_omeroembed

A TinyMCE editor plugin for Moodle that puts
[local_omeroembed](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/local_omeroembed)'s
"Embed an OMERO slide" authoring tool directly inside the standard Moodle
text editor - no separate page, no copy-pasting generated HTML by hand.

## Why

`local_omeroembed` already generates ready-to-paste embed HTML via its own
`author.php` page. This plugin removes the last manual step: a toolbar
button (and matching menu item) that opens that same authoring tool inside
a modal, right where the teacher is already writing - a Page, a Label, a
Book chapter, or a quiz question's text - and inserts the result directly
into the editor on save.

## Features

- **One button, no page-switching.** "Insert OMERO slide" opens
  `local_omeroembed`'s authoring tool (subject/image picker, live
  pan/zoom preview, write-up box) in a modal sized well past TinyMCE's
  default `modal-lg` cap, so the live preview isn't squeezed narrower than
  the embed's own configured width.
- **Edit-in-place, not just insert.** Clicking the button with the cursor
  on or inside an existing OMERO embed re-opens the tool pre-filled with
  that embed's subject, image/dataset, layout, and write-up text, instead
  of starting a blank insert.
- **All four `local_omeroembed` layouts**, including "Image with a short
  question below" - image on top, a short write-up underneath, and a
  reset button that snaps a student's pan/zoom back to the embed's opening
  view, aimed at quiz questions where a student may have explored the
  slide freely while answering.
- **Capability-gated** (`tiny/omeroembed:embed`, editing teacher/manager by
  default) - the real access check still happens course-side in
  `author.php` itself; this only controls whether the button/menu item is
  offered at all.

## Requirements

- Moodle 4.5+ (developed and tested against 4.5.12)
- [local_omeroembed](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/tree/main/local_omeroembed)
  installed and configured - this plugin is a thin editor-integration layer
  around it and does nothing on its own without it.

## Installing

This plugin is one of four bundled together in a single repository - see
[the repository root README](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle#installing)
for the full four-plugin install. This component alone:

```bash
git clone https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle.git omero-tinymce-moodle
cp -r omero-tinymce-moodle/tiny_omeroembed lib/editor/tiny/plugins/omeroembed
php admin/cli/upgrade.php --non-interactive
```

Then grant `tiny/omeroembed:embed` to whichever roles should see the
button (editing teacher and manager already have it by default).

## Usage

1. In any TinyMCE-based editor field (a Page, a Label, a Book chapter, a
   quiz question's text, ...), click the **"Insert OMERO slide"** toolbar
   button (or the equivalent menu item).
2. Pick a subject account and image/dataset, choose a layout, pan/zoom the
   live preview, and optionally set an opening view - exactly as in
   `local_omeroembed`'s own authoring tool, since this modal *is* that
   tool.
3. Click **Insert into page** - the generated embed HTML is inserted at
   the cursor and the modal closes.
4. To edit an existing embed later, put the cursor on/inside it and click
   the same button - the tool re-opens pre-filled with that embed's
   current settings.

## License

GNU General Public License v3 or later (GPL-3.0-or-later) - see
[LICENSE](LICENSE). Required for any plugin distributed via the official
Moodle plugins directory.

## Copyright

Copyright (C) 2026 University of Glasgow MVLS.
