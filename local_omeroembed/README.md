# local_omeroembed

A Moodle plugin that lets teachers embed enrolment-gated OMERO.iviewer
whole-slide microscopy images directly into course content - Labels,
Pages, Books, quiz questions, anywhere the normal Moodle content editor
works - with no HTML or URL typing, no OMERO account for students, and no
public/unauthenticated OMERO access.

This is the core plugin of a four-component suite - see
[the repository root](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle)
for the other three (`tiny_omeroembed`, a TinyMCE editor button for this
plugin's authoring tool; `qtype_omerohotspot`/`qtype_omerohotspotmulti`,
quiz question types built on it). Both of those genuinely require this
plugin to be installed - it works standalone, they do not.

## Why

The previous approach (OMERO's own public-account iframe embeds) meant
anyone with the URL could view course slide material, whether enrolled or
not. This plugin reverse-proxies OMERO.iviewer through Moodle itself:
every request re-checks the student's real Moodle session and course
enrolment server-side, and no OMERO credentials, session cookies, or
direct OMERO URLs are ever exposed to the browser.

## Features

- A live, pannable/zoomable authoring tool (`author.php`) that generates
  ready-to-paste embed HTML - no hand-typed HTML or OMERO URLs.
- Multi-view slides: select text in the write-up, click "Insert view
  link", and that text jumps the slide to a specific pan/zoom position.
- Three layout modes (slide-left, slide-right, slide-only), plus a
  question-style layout with a short write-up below the slide.
- Individually hideable viewer overlays (overview map, zoom controls,
  scale bar, ...) for a cleaner student-facing view.
- Click-to-answer hotspot questions, single- or multi-region, as both a
  standalone embed feature and (via `qtype_omerohotspot`/
  `qtype_omerohotspotmulti`) real graded Moodle quiz questions.
- Student point/shape annotations on an embed.
- A teacher-facing heatmap of where students actually looked, built from
  periodic viewport samples while tracking is on.
- Subject accounts (shared OMERO service-account credentials) are
  teacher-owned, not a site-wide admin setting - each teacher adds and
  manages their own, encrypted at rest via Moodle core's own
  `\core\encryption` (libsodium).

Full detail on all of the above - including every admin setting, known
limitations, and measured performance overhead - is in
[ADMIN.md](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/ADMIN.md)
and [USAGE.md](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/USAGE.md)
at the repository root.

## Requirements

- Moodle 4.5+ (developed and tested against 4.5.12; also verified against
  5.2.1 - see
  [MOODLE_5.2_COMPAT.md](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/MOODLE_5.2_COMPAT.md))
- OMERO.web with omero-iviewer installed (developed and tested against
  OMERO 5.22.1 / omero-iviewer v0.17.0)
- PHP curl extension (used for all server-to-server OMERO requests - see
  [External services](#external-services) below)

## External services

This plugin makes outbound HTTP(S) requests to the OMERO server address
configured by the site administrator (Site administration > Plugins >
Local plugins > OMERO slide embed). No other third-party or external
service is contacted. There are three call sites:

- **Authentication** (`classes/omero_session.php`) - on session start, the
  plugin logs in to `{omerobaseurl}/webclient/login/` as a single shared
  subject service account (Django CSRF token fetch, then a login POST),
  and reuses the resulting session cookie for subsequent requests. This
  is how the plugin obtains permission to serve slide imagery without
  asking each Moodle user to hold their own OMERO credentials.
- **Slide viewer reverse proxy** (`proxy.php`) - passes browser requests
  for `/iviewer/`, `/webgateway/`, `/api/` and `/static/` through to the
  same OMERO server and returns the response, rewriting URLs as needed.
  This is what actually renders the slide viewer and serves image tiles
  to students and staff.
- **Heatmap rendering** (`classes/heatmap_renderer.php`) - a server-side
  scheduled task (no browser involved) fetches image metadata and
  thumbnails from `{omerobaseurl}/webgateway/imgData/{imageid}/` and
  `{omerobaseurl}/webgateway/render_thumbnail/{imageid}/1200/` to build
  view-activity heatmap video frames.

No data is sent to any service other than the administrator-configured
OMERO server.

*(Not to be confused with Moodle's own "External Services" (Web
services) API, `db/services.php` - a different, unrelated meaning of
"external" - that's an in-progress internal migration of `ajax.php`'s
own endpoints onto Moodle's standard web-services layer, still entirely
internal to this Moodle site; it has nothing to do with third-party
services and isn't part of this section.)*

## Installing

This plugin is one of four bundled together in a single repository - see
[the repository root README](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle#installing)
for the full four-plugin install (recommended, since `tiny_omeroembed` and
both question types depend on this one being present). This component
alone:

```bash
git clone https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle.git omero-tinymce-moodle
cp -r omero-tinymce-moodle/local_omeroembed local/omeroembed
php admin/cli/upgrade.php --non-interactive
```

**Moodle 5.1+**: the web-servable code lives under `public/` - use
`public/local/omeroembed` as the copy target instead.

Then configure the OMERO server address via Site administration >
Plugins > Local plugins > OMERO slide embed - see
[ADMIN.md](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/ADMIN.md)
for what every setting does.

## Usage

See
[USAGE.md](https://github.com/Jcarlosmiguel/omero-TinyMCE-Moodle/blob/main/USAGE.md)
for a full teacher-facing walkthrough. Quick version: open the "Embed an
OMERO slide" authoring tool from a course's navigation, pick a subject
account and image, choose a layout, and generate the embed HTML to paste
into any content editor.

## License

GNU General Public License v3 or later (GPL-3.0-or-later) - see
[LICENSE](LICENSE). Required for any plugin distributed via the official
Moodle plugins directory.

## Copyright

Copyright (C) 2026 University of Glasgow MVLS.
