# Marketplace submission checklist

Walk this in order, every time a `local_omeroembed` (or companion) build
gets packaged for Moodle Marketplace/MMR-* submission. No step is
optional because it "should be fine" - every check on this list exists
because a real bug got caught by exactly that check and would have
shipped without it (see "Why this list exists" at the bottom).

The rule this list exists to enforce: **nothing leaves the machine -
uploaded to the Marketplace, or referenced in a ticket message - until
every box above the upload step is checked, for the actual built ZIP,
not just the working tree.**

## Before building the zip

- [ ] Working tree is clean (`git status --porcelain` empty) and the
      commit that will be tagged is already pushed to `origin/main`.
- [ ] `version.php`'s `$plugin->version`, `$plugin->release`, and the
      git tag you're about to create all agree with each other, and with
      the changelog you're about to write. (`$plugin->supported` is a
      strict `[min, max]` inclusive-range pair, not a discrete list of
      versions - if you're touching this line, re-read that sentence
      before you touch it. This exact mistake shipped once already.)

## Build

- [ ] Build the zip from the tagged commit, not the working tree, with
      the zip's internal top-level folder set to the plugin's **short**
      name - the frankenstyle name (`local_omeroembed`) *minus* its type
      prefix (`local_`), i.e. `omeroembed/`, not `local_omeroembed/`.
      This is a real Marketplace validation failure, not a style
      preference: it reconstructs the frankenstyle name from the known
      listing type + the zip's own folder name and rejects the upload
      ("The frankenstyle component name in the uploaded plugin does not
      match") if the folder already has the type baked in. `version.php`'s
      own `$plugin->component` stays the full frankenstyle name either
      way (`local_omeroembed`) - only the zip's folder changes:
      `git archive --format=zip --prefix=omeroembed/ HEAD:local_omeroembed -o <path>/local_omeroembed-<release>-<build>.zip`
      (swap the subdirectory for whichever component is being packaged,
      and the prefix for that component's own short name - `omeroembed`
      for `tiny_omeroembed` too, `omerohotspot`/`omerohotspotmulti` for
      the two qtypes, always the part after the type's own underscore).
- [ ] **ZIP-matches-HEAD byte check**: extract the zip to a scratch dir
      and `diff -rq` it against `git show HEAD:<component>` (or the
      working tree, if the working tree is confirmed clean and pushed) -
      compare the zip's `omeroembed/` (short-name) folder against the
      repo's own `local_omeroembed/` (frankenstyle) folder; the names
      differing is expected, only the *contents* need to match. Zero
      output required.
- [ ] Delete any previous zip for the same component sitting in the
      submission folder once the new one is confirmed good, so a stale
      build can't get uploaded by accident. Update `VERSION_INFO.txt`/
      `RELEASE_NOTES.txt` (or equivalent) in that folder to match.

## Test the actual zip - not the working tree, not "it should be the same"

- [ ] Extract the **built zip** (not the repo checkout) into a clean
      Moodle install's plugin directory and run
      `admin/cli/upgrade.php --non-interactive` there. This is the
      single highest-value check on this list - every real
      installation-breaking bug caught this week (`$plugin->supported`'s
      wrong format) was caught by this exact command and by nothing
      else. Coding-standard checks and code review both passed clean
      while this bug was live.
- [ ] Run `php -l` across every changed file, and the real Moodle Code
      Checker (`phpcs --standard=moodle`, via `moodlehq/moodle-cs`) -
      already installed in the `moodle-docker` dev container under
      `/tmp/moodlecs`, reuse it rather than reinstalling. Fix real
      findings in anything touched this cycle; note (don't silently mass
      -fix) pre-existing findings in untouched code.
- [ ] **One live smoke test per subsystem actually touched this cycle**
      - not a full regression sweep, a targeted real execution of
      whatever changed:
  - Any HTTP client code (curl_init/`\curl`, proxy/session logic): a
    real request against the real target server, checked with
    reflection if the method is protected/private. Static review is not
    a substitute - the `\curl` redirect-following default (opposite of
    raw `curl_init()`'s default) was invisible to code review and only
    surfaced against a real response.
  - Any backup/restore code: a real seeded-course backup → restore round
    trip, covering **both** "restore while the original course still
    exists" (duplicate-course path) and "restore after the original is
    gone" (migration path) - the embedid unique-index collision only
    ever showed up in the first of those two, never in isolated unit
    logic.
  - Any AMD/JS build output: run the actual `grunt`/build step, don't
    assume checked-in `.min.js` matches source - stale build artifacts
    passed every static check and only grunt itself caught them.
  - Anything else: use judgement, but it must be a real execution
    against a real target, not a read-through.

## Tag and GitHub Release (if this build gets one)

- [ ] Tag pushed, GitHub Release created from it.
- [ ] **Zenodo webhook actually fired - don't assume it did because the
      Release page looks normal.** `gh api repos/<owner>/<repo>/hooks`
      must return a non-empty array with a `zenodo.org` entry, and that
      entry's `last_response` must show a real success code for the
      `published` event, not `403` and not simply be absent. Two
      independent failures have now happened this way, on two different
      releases in a row, and **both looked completely normal on
      GitHub** - no error, no warning, nothing to catch by reading the
      Release page itself:
  - **v1.5.0**: the webhook's token had expired/rotated Zenodo-side.
    GitHub delivered the `published` event; Zenodo rejected it with
    `403`. The webhook existed and looked fine in a casual glance -
    only the delivery's own response code showed the failure.
  - **v1.6.0**: the Zenodo integration had been switched off for the
    repo entirely, so GitHub never even had a webhook to deliver to -
    `gh api .../hooks` returned `[]`. No delivery, no error, nothing.
  - **Recovery, both times**: fix it Zenodo-side first (re-enable the
    GitHub integration for the repo / re-authorize), **then delete and
    re-create the GitHub Release from the same tag** - never move or
    re-create the tag itself. Zenodo archives on a `published` event
    and never back-fills from history, so editing the existing
    Release's title/notes achieves nothing; only a fresh publish fires
    a new delivery. Confirm the fix the same way: re-check
    `gh api .../hooks` and the new delivery's response code before
    trusting it.

## Before uploading

- [ ] Release notes are written, describe the actual diff (not last
      cycle's), and agree with `version.php`'s release/build numbers.
- [ ] The version number, release string, and supported-Moodle-versions
      you're about to type into the Marketplace form match `version.php`
      exactly - re-check by reading the file, not from memory of what it
      "should" say.

## Upload, then message - in that order, every time

- [ ] Upload the zip to the Marketplace / MMR-* ticket.
- [ ] Confirm the upload succeeded and is the file you intended (right
      version number showing on the ticket/listing).
- [ ] Only now send any ticket comment or reviewer message referencing
      this build. Never draft or send the message before the upload is
      confirmed - two of the mistakes that made this list happened at
      exactly this human/form boundary, not in anything Claude Code
      verified.

---

## Why this list exists

Three real bugs shipped past every static check this week and were only
caught by an actual execution:

- **`$plugin->supported` wrong format** (v1.4.0) - `phpcs` was clean.
  `admin/cli/upgrade.php` on a real instance threw a fatal
  `coding_exception` immediately.
- **`\curl` redirect-following default** - a code review reading the
  diff would have passed it (the change looked like a faithful
  `curl_init` → `\curl` port). A live tile fetch against production
  OMERO exposed that `\curl` follows redirects by default, unlike raw
  `curl_init()`, and would have silently served a login page as image
  data.
- **`embedid` site-wide unique-index collision** - only a real course
  duplication (backup + restore into a new course while the original
  still existed) surfaced the uncaught `dml_write_exception`. Isolated
  logic review of the restore class gave no signal.

Static checks (lint, phpcs, code review) kept passing while real runs
kept failing - three times in one week. This list exists so the next
packaging run doesn't need a fourth incident to relearn the same lesson.

Two more incidents, same pattern, different layer - the "static check"
that kept passing wasn't a linter this time, it was the GitHub Release
page itself looking normal:

- **Zenodo webhook token expired/rotated** (v1.5.0) - the `published`
  event was delivered and rejected with `403`. The Release existed, had
  the right tag, the right notes - nothing about it suggested archival
  had failed.
- **Zenodo integration switched off entirely** (v1.6.0) - no webhook
  existed at all, so no event was ever sent. Same outcome: a completely
  normal-looking Release, silently not archived.

Both needed the same real check to catch: querying the webhook and its
actual delivery response, not reading the Release page - see "Tag and
GitHub Release" above.

A fourth incident, this time in the checklist's own build command: the
`git archive` command in "Build" above used `--prefix=local_omeroembed/`
(the full frankenstyle name) from the day this list was first written,
through every zip built this way since - including the one this exact
list's own byte-check step passed clean, repeatedly, because content
matching HEAD was never the problem. The Moodle Marketplace's real
upload validator was the first thing to ever actually check the zip's
own folder name against what it expects, and rejected it outright:
*"The frankenstyle component name in the uploaded plugin does not
match."* Fixed by using the plugin's short name (`omeroembed`, not
`local_omeroembed`) as the zip's internal folder - `version.php`'s own
`$plugin->component` was never wrong, only the zip's folder name was.
Same lesson as every other entry here: a check that verifies the wrong
property (byte-for-byte content, in this case) can pass cleanly forever
while missing the actual failure mode entirely.
