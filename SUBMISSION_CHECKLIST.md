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

- [ ] Build the zip from the tagged commit, not the working tree:
      `git archive --format=zip --prefix=local_omeroembed/ HEAD:local_omeroembed -o <path>/local_omeroembed-<release>-<build>.zip`
      (swap the subdirectory/prefix for whichever component is being
      packaged).
- [ ] **ZIP-matches-HEAD byte check**: extract the zip to a scratch dir
      and `diff -rq` it against `git show HEAD:<component>` (or the
      working tree, if the working tree is confirmed clean and pushed).
      Zero output required.
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
