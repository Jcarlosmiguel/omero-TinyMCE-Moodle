# Moodle 4.5 → 5.2 compatibility: findings and history

One codebase, one branch (`main`), all four components declaring
`$plugin->supported = [405, 502]` - **this is already an inclusive
range** ("4.5 through 5.2"), not a discrete list of individually-supported
versions; Moodle core requires `supported` to be exactly a `[min, max]`
pair (`lib/classes/plugininfo/base.php` throws a `coding_exception` -
fatal for the whole site's plugin manager, not just this plugin - for
anything else). A 2026-08-11 resubmission briefly changed this to
`[405, 500, 501, 502]` on the mistaken belief that 500/501 needed to be
added explicitly to be "included" - caught via a real `admin/cli/
upgrade.php` run before it shipped, reverted back to `[405, 502]`, which
was correct (and already covered 5.0/5.1) the whole time. 5.0 and 5.1
have since also been verified clean (see "5. Fresh install, Moodle 5.0
and 5.1, MariaDB" below), so the existing declaration's range is now
fully backed by direct testing across its whole span, not just its two
endpoints. This doc consolidates: a colleague's
(Eric Davies) 4.5→5.2 developer notes, a cross-check against the official
Moodle Marketplace plugin requirements page, and direct verification
against this plugin's own code across five locally-run test environments
(4.5 on MariaDB, 5.0 on MariaDB, 5.1 on MariaDB, 5.2 on MariaDB, 4.5 on
PostgreSQL) - all since torn down, their job done; the findings below are
what they were for.

**This was originally tracked on a separate `moodle-5.2-compat` branch,
submitting 4.5 first and 5.2 to follow as a second release.** That branch
is gone now: once the actual content delta between the two branches
turned out to be a handful of files - none of them version-specific, all
either docs or general correctness fixes (the privacy API gap, a missing
plugin dependency declaration) that just happened to be made while
working on this branch - keeping them stranded there was a branching-model
problem waiting to repeat with the next fix, not a reason to keep the
split. Collapsed into `main`, `$plugin->supported = [405, 502]` added to
all four `version.php` files, verified against live production
(`core_plugin_manager::instance()->all_plugins_ok()` confirms the whole
dependency graph still resolves), branch deleted.

## Summary

- Core install/upgrade to 5.2 works with **zero code changes** — the only
  fix needed was relocating the four components under Moodle 5.2's new
  `public/` webroot split (see below).
- **5.0 and 5.1 also verified clean**, zero code changes needed. The
  `public/` split's exact boundary is now pinned down precisely: absent
  in 5.0, present in 5.1 (previously only known as "5.1+" from
  moodledev.io's own docs).
- A DEVELOPER-debug sweep (all four components, both Moodle versions)
  produced **zero PHP notices, warnings, or deprecation messages** across
  every real page/action exercised.
- A PostgreSQL install + a full write/read round trip through the plugin's
  real save/attempt code paths (single-region, multi-region JSON, and the
  geometry-matching attempt check) **all succeeded with no errors** —
  consistent with the codebase using only Moodle's portable DML API
  (zero raw SQL found anywhere in the four components).
- One real, unresolved item: the **repository naming convention**
  (`moodle-{plugintype}_{pluginname}`) doesn't fit a single repo bundling
  four separate plugins the way this one does — needs an actual answer
  from Moodle Marketplace, not a guess (see "Open questions").
- The one confirmed **approval blocker** independent of any Moodle-version
  question — `local_omeroembed_subjects` (teacher OMERO credentials)
  excluded from Privacy API coverage — is now **fixed**, see below.

## Environment requirements (moodledev.io + each version's own environment.xml, verified directly)

| | Moodle 4.5 | Moodle 5.0 | Moodle 5.1 | Moodle 5.2 |
|---|---|---|---|---|
| PHP | 8.1–8.4 (this project runs 8.2) | **8.2.0 minimum** (own environment.xml) | **8.2.0 minimum** (own environment.xml) | **8.3.0 minimum**, 8.3.x/8.4.x only |
| MariaDB | — | — | — | 10.11.0 minimum |
| PostgreSQL | — | — | — | 16 minimum |
| MySQL | — | — | — | 8.4 minimum |
| Oracle | unsupported since 5.0 | unsupported | unsupported | unsupported |
| sodium extension | required | required | required | required |
| `max_input_vars` | ≥ 5000 | ≥ 5000 | ≥ 5000 | ≥ 5000 |
| `public/` webroot split | no | **no** | **yes** | yes |

Note: an earlier verbal figure of "PHP 8.2 minimum" for 5.2 doesn't match
moodledev.io's own release page, which states 8.3.0. Resolved without
needing to circle back to whoever supplied that figure: our 5.2
environment was built directly to moodledev.io's own published 8.3.0
minimum from the start, so it's already correct regardless of where the
8.2 figure came from.

## The `public/` webroot restructure (Moodle 5.1+)

The single biggest practical gotcha, and one that isn't a code change at
all: since Moodle 5.1, the actual web-servable code lives under `public/`
— top-level `local/`, `lib/`, `question/type/` are stub directories only.
**Moodle does not move third-party plugins into `public/` for you on
upgrade.** All four components need to land at:

```
public/local/omeroembed
public/lib/editor/tiny/plugins/omeroembed
public/question/type/omerohotspot
public/question/type/omerohotspotmulti
```

`$CFG->wwwroot`/`$CFG->dirroot` are unaffected, so no URLs or path-building
code in the plugin itself needed to change — confirmed by running the
actual upgrade both ways (it silently no-ops if the plugin is left in the
old location, then installs cleanly once moved).

## Verification actually run (not just researched)

### 1. Fresh install, Moodle 5.2, MariaDB
Core + all four components installed cleanly via `admin/cli/upgrade.php`
after the `public/` relocation above. Zero errors.

### 2. Real upgrade path, Moodle 4.5.12 → 5.2.1, MariaDB
A full production database + files clone was run through the actual
`admin/cli/upgrade.php` core upgrade (not a fresh install) — 505 → 501
tables (core schema changes, not plugin-related), all 55 users, 4 courses,
and every hotspot/annotation table migrated correctly.

### 3. DEVELOPER-debug sweep, both versions
`$CFG->debug = DEVELOPER`, `debugdisplay = 1`, then every real
plugin-facing page/action fetched authenticated: course view, the real
OMERO-embedded page, the quiz containing `qtype_omerohotspot`/
`qtype_omerohotspotmulti` questions, `author.php`, `manage.php`,
`mysubjects.php`, `ajax.php`'s list action. **Zero notices, warnings,
deprecated-function messages, or uncaught errors on either version.**

### 4. PostgreSQL, Moodle 4.5
Fresh install on `postgres:17`, all four components installed cleanly, then
a real write/read exercise through `ajax.php`:
- `hotspot_save` → `hotspot_get`: single-region INSERT + SELECT round trip.
- `hotspotmulti_save` → `hotspotmulti_get`: JSON-array region set INSERT +
  SELECT round trip.
- `hotspot_attempt`: the actual geometry-matching grading logic, correctly
  returned `{"correct":true}` for a click at the stored coordinates.

All five requests succeeded with no errors — the strongest practical
evidence available (short of testing on the real Marketplace review
infrastructure) that the cross-database compatibility requirement is met.

### 5. Fresh install, Moodle 5.0 and 5.1, MariaDB

The gap between the two versions actually tested above (4.5 and 5.2) -
at the time, only the two endpoints of the `[405, 502]` range had been
directly verified, so 5.0/5.1 were an unverified assumption riding along
inside the declared range, not something the declaration could exclude on
its own (see this doc's own top-of-file correction - `supported` is a
`[min, max]` range, not a discrete list, so there was never a way to
declare "4.5 and 5.2 but not 5.0/5.1" in the first place). Both are now
directly verified too, in two fully isolated docker-compose stacks (own
Moodle core checkout, own DB
container, own ports - nothing shared with any other running
environment), each torn down (containers, volumes, networks) once done.

**5.0 does not have the `public/` split; 5.1 does.** This pins down the
exact boundary precisely, rather than just "5.1+" from moodledev.io's own
docs: a first pass at 5.1 placed the four components at the old top-level
paths (matching 5.0's layout) and `admin/cli/upgrade.php` reported "No
upgrade needed" - a false negative, not a real result, because
`core_component::get_plugin_list('local')` returned nothing at all
(confirmed directly) until the components were moved under `public/`, at
which point all four installed and upgraded cleanly. 5.0 needed no such
move.

**PHP requirement**: checked each version's own `admin/environment.xml`
directly (not assumed from the 4.5/5.2 figures already in the table
above) - both 5.0 and 5.1 declare **PHP 8.2.0** as their own minimum
(the jump to 8.3.0 only starts at the 5.2 block). Both stacks were built
on PHP 8.2, matching production and correctly meeting each version's own
declared floor.

For each version: core + all four components installed cleanly via
`admin/cli/upgrade.php` (fresh install), `core_plugin_manager`-reported
versions matched disk exactly with `uptodate` status for all four, a
second `admin/cli/upgrade.php` run confirmed a clean no-op, web server
logs showed zero fatal/exception/undefined-function/parse-error lines,
and - beyond just installing - a real authenticated login followed by
loading `local_omeroembed`'s own admin settings page returned HTTP 200
with the plugin's real settings content present (`retentionperiod`,
`omerobaseurl`) and no error text, on both versions.

**Not yet done for 5.0/5.1** (unlike the 5.2 pass): the DEVELOPER-debug
sweep across every plugin-facing page/action, a real 4.5→5.0/5.1 upgrade
path against production-shaped data, and the PostgreSQL round trip. This
pass confirms clean install/upgrade and no runtime errors on the pages
checked - a real, positive result - but is narrower in coverage than
verification #3 and #4 above until/unless someone repeats that fuller
pass for these two versions specifically.

## Cross-checked against the Moodle Marketplace plugin requirements

The Marketplace has replaced the old moodle.org plugins directory as the
submission destination; both free and paid listings go through it, using
the same [contribution
checklist](https://moodledev.io/general/community/plugincontribution/checklist)
as before as part of the review.

**Confirmed compliant:**
- LICENSE file present in all four component directories.
- No Composer/vendor dependency, no bundled third-party libraries needing
  `thirdpartylibs.xml`.
- Public, accessible GitHub issue tracker (enabled, if currently empty).
- Both question types implement real `backup`/`restore` (Moodle2 format).
- `settings.php` uses the required `plugintype_pluginname/settingname`
  naming format.
- `core/modal_factory`/`modal_registry` (deprecated in 5.2): not used
  anywhere — the plugin already uses the current `core/modal` API.

**Fixed — was an approval blocker:**
- `local_omeroembed_subjects` stores a teacher's OMERO username (plaintext)
  and encrypted password, with a `NOTNULL` FK to a specific user, and was
  excluded from `classes/privacy/provider.php` coverage on the grounds
  that OMERO credentials are "not personal data" — reasoning that doesn't
  hold up against the Marketplace's own explicit blocker list: *"The
  plugin processes personal data... without a compliant Privacy API
  implementation."* Now fully covered, scoped to `CONTEXT_USER` rather
  than `CONTEXT_COURSE` like the plugin's other four covered tables since
  this data belongs to the teacher directly, not to any course. Export
  includes the connection's name and OMERO username, never the encrypted
  password. Deletion removes the credential outright. All seven touched
  provider methods verified directly against real data on a running
  instance, not just syntax-checked.

## Open questions — need real answers, not assumptions

1. **Repo structure for Marketplace submission — RESOLVED 2026-08-11.**
   The Marketplace reviewer (Volodymyr Dovhan, MMR-101, issue #1) has
   answered this definitively: split required, one repository per
   plugin. Not a guess or an inferred convention - a direct instruction
   from the actual reviewer. The reasoning below for why we'd have
   preferred not to split is kept as the decision record for *why the
   split happens last* in the implementation sequence (see the reviewer
   response plan), not as an argument against complying - it isn't one.
   The dependency coupling described below is unaffected by the split
   (`$plugin->dependencies` continues to do the real work across
   repos, exactly as reasoned here before the answer came back).

   Original open-question text, preserved for context:

   The naming convention
   (`moodle-{plugintype}_{pluginname}`) assumes one plugin per repo. This
   repo bundles four - but not as four unrelated plugins that happen to
   share a repo. They're a genuinely coupled system: `tiny_omeroembed`'s
   `ui.js` opens `local_omeroembed`'s `author.php` directly by URL, and
   both qtypes' `renderer.php` render the actual question by building a
   URL against `local_omeroembed/proxy.php` - none of the three "satellite"
   components can do anything meaningful without `local_omeroembed`
   installed alongside them. `local_omeroembed` itself has no such
   dependency the other way - it works standalone (its own hotspot/
   heatmap/annotation features don't need any of the other three).

   That coupling is already modelled the sanctioned Moodle way: all three
   dependent components now declare `$plugin->dependencies` on
   `local_omeroembed` in their own `version.php` (`qtype_omerohotspot` and
   `qtype_omerohotspotmulti` already had it; `tiny_omeroembed` was
   missing it - fixed and verified directly against
   `core_plugin_manager::instance()->all_plugins_ok()`, which now
   confirms the whole dependency graph resolves correctly). So the real
   open question isn't "is this dependency modelled correctly" - it is -
   it's narrower: does Moodle Marketplace's one-repo-per-listing
   convention accommodate a coordinated four-plugin submission from one
   source repo (where three listings would each declare a version-gated
   dependency on the fourth), or does the repo itself need splitting into
   four to satisfy the naming convention while `$plugin->dependencies`
   still does the actual coupling work across the split? Worth asking
   Marketplace support directly with this framing, rather than assuming
   either answer.

   **Why we'd prefer not to split, if Marketplace can accommodate it** -
   reasoning worth including when actually asking, not just "is this
   allowed":

   - **Splitting doesn't remove the coupling, it just spreads it across
     more repos.** The three satellite components would still hard-depend
     on `local_omeroembed` for the same reasons listed above - four repos
     instead of one doesn't change what depends on what, it just makes
     the dependency harder to see and easier to let drift.
   - **Version-skew risk on install.** `qtype_omerohotspot` and
     `qtype_omerohotspotmulti` each declare a *specific* required
     `local_omeroembed` version (`2026080303`, `2026080306`). In one repo,
     every commit that changes `local_omeroembed`'s contract (a
     `proxy.php` query param, `author.php`'s URL shape) and the
     dependents' own required-version bumps land together, atomically. In
     four repos, an admin can clone four independently-tagged versions
     that were never actually tested together - exactly the kind of
     mismatch `$plugin->dependencies` is meant to catch at install time,
     not something to rely on catching only after the fact.
   - **This session's own testing already depends on the coupling.**
     Every verification pass this week - the real 4.5→5.2 upgrade, the
     DEVELOPER-debug sweep, the PostgreSQL round trip - installed and
     exercised all four together, from one checkout, in one pass. That's
     not incidental; it's the only way to meaningfully test a system where
     three components only render anything through the fourth's
     `proxy.php`. Four separate repos would mean four separate release
     processes to keep in sync before any of that testing means what it
     currently means.
   - **Lower maintenance burden for a genuinely niche plugin.** Earlier
     research already flagged low odds of external co-maintainers and a
     real risk of the "niche, unmaintained a decade later" pattern seen in
     Cy-TEST, the closest real predecessor. Four repos means four issue
     trackers, four sets of CI config, four READMEs to keep consistent -
     multiplied maintenance surface for what is, in practice, one team
     maintaining one cohesive teaching tool.

Resolved without needing further follow-up: the PHP 8.2-vs-8.3 figure
(see "Environment requirements" above).

## Not applicable to this plugin (checked, not assumed)

- Course format changes (`supports_components()`, `get_max_sections()`,
  activity chooser refactor) — none of the four components are course
  formats.
- New activity overview page replacing `index.php` — none are `mod_*`
  activity modules; confirmed no such file exists anywhere in the repo.
- Filter class relocation to `classes/text_filter.php` — none are filter
  plugins.
- `subplugins.json` format change — none of the four declare subplugins.
