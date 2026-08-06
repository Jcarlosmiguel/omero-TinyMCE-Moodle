<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Reverse-proxies OMERO.iviewer (and the assets/API calls it makes) through Moodle,
 * authenticated as a subject service account, so no OMERO credentials, session
 * cookies, or direct OMERO URLs are ever exposed to the student's browser.
 *
 * SECURITY: require_login($course) below is the actual access gate for this whole
 * plugin, not author.php (which only gates the authoring tool teachers use to build
 * an embed) - *this* script is what a student's browser actually requests when the
 * iframe loads,
 * on a separate HTTP request from whatever page embedded it. require_login() re-derives
 * the real, current session server-side and checks enrolment against the real
 * courseid - it cannot be bypassed by editing the iframe's query string, since courseid
 * only selects *which* course to check enrolment against, not whether that check happens.
 *
 * KNOWN LIMITATION, WORKED AROUND (see inject_server_workaround() below): iviewer is
 * a full client-side app - most of its own follow-up requests (image_data, render
 * calls, ROI/annotation fetches) are NOT literal quoted URLs in the initial HTML,
 * they're built at runtime as `context.server + <hardcoded prefix>` and would
 * otherwise go straight to this Moodle site's own webroot (404) instead of back
 * through this proxy. iviewer is *meant* to support exactly this reverse-proxy case
 * via a "server" initial param (REQUEST_PARAMS.SERVER in its own source), reflected
 * into the page and read by Context.processInitialParameters() into context.server -
 * except that function actually reads `this.initParams[REQUEST_PARAMS.HOST]`, and
 * REQUEST_PARAMS has no HOST key at all (confirmed against the exact deployed
 * release, tag v0.17.0, on both constants.js and context.js) - a real bug in
 * omero-iviewer itself, not a param-naming mismatch on our end. `REQUEST_PARAMS.HOST`
 * is therefore just `undefined`, and `this.initParams[undefined]` reads the object
 * property literally named "undefined" (JS coerces any bracket-notation key to a
 * string). inject_server_workaround() exploits that determinism directly: it sets
 * `window.INITIAL_REQUEST_PARAMS['undefined']` itself, entirely inside our own
 * response - no server GET param, Django reflection, or iviewer cooperation needed.
 * rewrite_response_urls() (and BODY_REWRITE_PREFIXES) still separately handles plain
 * server-rendered asset tags (<link href>, <script src>) that Django's base template
 * emits directly rather than through iviewer's own JS.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_omeroembed\omero_session;
use local_omeroembed\tracking_repository;

/**
 * Only these path prefixes are ever proxied through to OMERO - matches the same
 * prefixes already whitelisted server-side by omero.web.public.url_filter for the
 * existing public-account setup this plugin replaces (see omero-mvls's own
 * omero_config/extraweb.omero). Anything else (admin pages, other users' data, etc.)
 * is refused before a request is even made.
 */
const PROXY_PATH_PREFIXES = ['/iviewer/', '/webgateway/', '/api/', '/static/'];

/**
 * Subset of PROXY_PATH_PREFIXES that rewrite_response_urls() rewrites as literal
 * quoted strings in the response body. Deliberately excludes /webgateway/ and /api/ -
 * those are also reachable via iviewer's own "host"-based runtime concatenation (see
 * this file's top-of-file comment), and initParams['WEBGATEWAY']/['WEB_API_BASE']
 * are read by that same runtime code as a *relative* prefix to append to
 * context.server. Rewriting them here too would make iviewer concatenate one proxy
 * URL onto another, producing a broken double-URL.
 */
const BODY_REWRITE_PREFIXES = ['/iviewer/', '/static/'];

// courseid/subject travel as slash-arguments (PATH_INFO), not query params, so that
// the *this proxy's own URL*, embedded whole as the value of OMERO's "host" param
// below, needs only one query param ("path") and never a literal "&" of its own.
// OMERO's Django template HTML-escapes that value on the way out (& becomes &amp;)
// and browsers do NOT decode entities inside <script> text - a raw "&" we relied on
// as a delimiter would arrive at this script mangled into the 5 literal characters
// "&amp;" instead of being split into separate params. A single query param sidesteps
// the whole problem instead of trying to out-guess Django's escaping.
$pathinfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
[$courseid, $subject] = array_pad(explode('/', $pathinfo, 2), 2, '');
$courseid = clean_param($courseid, PARAM_INT);
$subject = clean_param($subject, PARAM_ALPHANUMEXT);
if (!$courseid || $subject === '') {
    throw new \moodle_exception('invalidproxypath', 'local_omeroembed', '', $pathinfo);
}

$path = optional_param('path', '/iviewer/', PARAM_PATH);

// Set only on the authoring tool's own live preview iframe src (see author.php) -
// never present on a generated embed's src, since that's built by author.js's
// buildViewUrl()/generateEmbed() from the *unmodified* base proxy URL. Lets
// inject_contextmenu_blocker() (and future annotation-mode features) tell "the
// teacher is previewing this" apart from "a student is viewing the real embed"
// without needing two different proxy scripts.
//
// SECURITY: this is a bare request param, exactly like $heatmap below it -
// capability-checked a few lines down for the identical reason $heatmap is
// (see that variable's own comment). Confirmed as a real, exploitable gap
// (not just theoretical): before this check existed, any student could
// append &authoring=1 to their own embed's iframe src (visible via view-
// source) and skip the entire `else if (!$authoring)` block below, which
// is what runs inject_hide_roi_panel_css() (see that function's own
// docblock - OMERO's native ROI *editing* panel, not read-only) plus the
// context-menu blocker and view-tracking script. Gated on the exact same
// capability author.php itself requires before it would ever legitimately
// set this param (see that file's own require_capability() call) - a
// student reaching this branch was never supposed to be possible in the
// first place, this just makes the server actually enforce that instead
// of trusting author.php to be the only thing that ever sets it.
$authoring = optional_param('authoring', false, PARAM_BOOL);

// Per-placement token minted by author.js's generateEmbed() (see
// js/annotate.js and its own plan doc) - absent on older embeds generated
// before the annotation feature existed, and always absent on the
// authoring preview. inject_annotation_script() requires this to be
// present before injecting anything, since ajax.php needs it to scope
// which embed placement an annotation belongs to.
$embedid = optional_param('embedid', '', PARAM_ALPHANUMEXT);

// 'standalone' (default - local_omeroembed's own hotspot activity, keyed
// by $embedid, see hotspot_repository.php) or 'qtype' (qtype_omerohotspot's
// question edit form / attempt renderer - no $embedid at all, since the
// question itself is the identity; the region round-trips via postMessage
// into a hidden form field instead of any ajax.php call - see that
// plugin's own editform.js/question.js). Purely a request param, never
// trusted as a security boundary on its own - both modes still go through
// their own real checks below ($embedid+hotspot_repository::exists() for
// standalone, local/omeroembed:hotspotauthor for qtype authoring).
$hotspotmode = optional_param('hotspotmode', 'standalone', PARAM_ALPHA);

// Set only on heatmap.php's own iframe src - a third mode alongside the
// default (real student-facing embed) and $authoring, never combined with
// either. Unlike $authoring (which relies entirely on author.php never
// setting it on anything but its own preview), this one is capability-
// checked right here, since heatmap.php is reachable directly by URL and
// $heatmap=1 would otherwise expose the tracking-notice-free heatmap
// render (see inject_heatmap_view_script()) to anyone who guessed the
// query param.
$heatmap = optional_param('heatmap', false, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

if ($heatmap) {
    require_capability('local/omeroembed:viewheatmap', context_course::instance($courseid));
    // SECURITY: the capability check above only checks the *declared*
    // courseid - see tracking_repository::verify_course()'s own docblock.
    // Without this, local/omeroembed:viewheatmap in ANY course would let
    // a teacher render another course's heatmap density view here.
    if ($embedid !== '') {
        tracking_repository::verify_course($embedid, $courseid);
    }
}

if ($authoring) {
    require_capability('moodle/course:manageactivities', context_course::instance($courseid));
}

if (!in_array_prefix($path, PROXY_PATH_PREFIXES)) {
    throw new \moodle_exception('invalidproxypath', 'local_omeroembed', '', $path);
}

// PERFORMANCE: every access check above is done: nothing past this point
// writes to $_SESSION (confirmed - no $SESSION-> anywhere in this file).
// Without this, PHP's session handler holds an exclusive lock on the
// student's session for this entire request, including the curl_exec()
// call to the real OMERO server below and every inject_*() rewrite that
// follows - and every one of OpenSeadragon's many parallel tile requests
// while panning/zooming goes through this same file, with this same
// session. Without releasing the lock here, those requests queue behind
// each other one at a time instead of running concurrently, no matter how
// much server capacity is available. Confirmed directly: two concurrent
// requests through this code path took 6.04s combined without this call,
// 3.03s with it, for identical simulated work - full serialisation,
// fully resolved. This is exactly the cost the plugin overhead benchmark
// (see ADMIN.md's "Performance overhead" section) never covers, since
// that never actually fetches proxy.php's own response.
\core\session\manager::write_close();

/**
 * @param string $path
 * @param string[] $prefixes
 * @return bool
 */
function in_array_prefix(string $path, array $prefixes): bool {
    foreach ($prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return false;
}

/**
 * Makes the actual request to OMERO with the given session's cookie, returning
 * just the raw pieces needed to decide what to do next (retry-on-staleness check,
 * then the existing redirect/body-rewrite handling) - no side effects, doesn't
 * touch $_SERVER output itself, so it's safe to call twice in a row.
 *
 * CURLOPT_FOLLOWLOCATION is deliberately off - OMERO's own redirects (e.g. iviewer
 * normalising a URL, or a session bounce to its login page) must come back through
 * this proxy too, not be followed server-side, otherwise the browser's address
 * bar/history would never reflect them and any relative paths in the *final*
 * response could resolve wrong.
 *
 * @param string $url
 * @param array{cookie: string, csrftoken: string} $session
 * @return array{status: int, contenttype: ?string, body: string, location: ?string}
 * @throws \moodle_exception On a curl-level failure (network/DNS/TLS).
 */
function fetch_from_omero(string $url, array $session): array {
    $locationheader = null;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $session['cookie']]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curlhandle, $headerline) use (&$locationheader) {
        if (preg_match('/^Location:\s*(.+)$/i', trim($headerline), $m)) {
            $locationheader = trim($m[1]);
        }
        return strlen($headerline);
    });
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \moodle_exception('omeroconnectionfailed', 'local_omeroembed', '', null, $error);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contenttype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return ['status' => $status, 'contenttype' => $contenttype, 'body' => $body, 'location' => $locationheader];
}

/**
 * Whether an OMERO response looks like it failed because the cached session had
 * already gone stale on OMERO's own side, rather than a genuine error - see
 * omero_session::SESSION_TTL_SECONDS's own docblock for the underlying gap
 * (OMERO's real idle timeout can outpace our cache TTL guess). Three signals,
 * confirmed against real behaviour rather than guessed:
 *
 * - A redirect to a path NOT in PROXY_PATH_PREFIXES - almost always OMERO
 *   bouncing an unauthenticated request to its own /webclient/login/, the
 *   clearest possible signal.
 * - A bare 403 - unambiguous.
 * - A bare 404 - genuinely ambiguous (a truly-missing image also 404s), but
 *   confirmed via real testing that a stale session hitting a JSON API endpoint
 *   like /iviewer/image_data/<id>/ *also* surfaces as a plain 404 with an
 *   `{"error": "Image not found"}` body indistinguishable from the real thing at
 *   this level. Included anyway because retrying is safe regardless: it's a GET,
 *   and a genuinely-missing image just 404s again on the retry, at the cost of
 *   one extra request only on a path that was already failing.
 *
 * @param array{status: int, location: ?string} $result
 * @return bool
 */
function looks_like_stale_session(array $result): bool {
    if ($result['location'] && !in_array_prefix($result['location'], PROXY_PATH_PREFIXES)) {
        return true;
    }
    return in_array($result['status'], [403, 404], true);
}

$baseurl = rtrim((string) get_config('local_omeroembed', 'omerobaseurl'), '/');
$proxybase = (new \moodle_url("/local/omeroembed/proxy.php/{$courseid}/{$subject}"))->out(false);

// On the *initial* load (path defaults to /iviewer/), forward the images/dataset
// query params the filter generated the iframe with, per the plan's Section 6 embed
// format. Follow-up requests (the ones rewrite_response_urls() redirects back here)
// carry their own real query string in $path itself instead.
$querystring = '';
if ($path === '/iviewer/') {
    // images and dataset are independently optional - all four combinations are
    // valid iviewer views: a single image alone, a single starting image inside
    // a dataset, a whole dataset with no starting image, or a dataset with a
    // starting image. At least one of the two has to be given, or there's
    // nothing to show.
    $images = optional_param('images', '', PARAM_SEQUENCE);
    $dataset = optional_param('dataset', '', PARAM_INT);
    // See the identical comment in author.php - an empty dataset= submits as 0
    // under PARAM_INT cleaning, not '', and OMERO dataset IDs are never 0.
    if ($dataset === 0) {
        $dataset = '';
    }
    if ($images === '' && $dataset === '') {
        throw new \moodle_exception('missingimageordataset', 'local_omeroembed');
    }

    // "browsable" is an explicit, independent choice - NOT inferred from whether
    // a dataset is present. iviewer supports a dataset+full_page combination
    // (a single clean starting image that just happens to belong to a dataset,
    // e.g. embedding "the" representative slide from a teaching set) just as
    // validly as dataset+collapse_right (the sibling-thumbnail strip, for
    // letting students browse the whole set). Only default to browsable when a
    // dataset was actually given and no explicit choice was made - with no
    // dataset there's nothing to browse between regardless.
    $browsable = optional_param('browsable', $dataset !== '' ? '1' : '0', PARAM_BOOL);

    $forwardparams = [];
    if ($images !== '') {
        $forwardparams['images'] = $images;
    }
    if ($dataset !== '') {
        $forwardparams['dataset'] = $dataset;
    }
    if ($browsable) {
        // Hides the right-hand info/ROI panel but keeps the left thumbnail
        // strip usable for browsing between images.
        $forwardparams['collapse_right'] = 'true';
    } else {
        // Collapses both side panels for the cleanest single-slide embed.
        $forwardparams['full_page'] = 'true';
    }

    // Viewport position (x, y - centre point in full-resolution pixels) and zoom
    // (zm - percentage) let a single embedded image jump to a specific saved
    // view - author.php generates an iframe with an auto-assigned "name" and
    // matching <a target="..."> view-links elsewhere on the same page, so
    // clicking a link reloads just that iframe, not the whole page. All three
    // deliberately optional and independent of each other, matching iviewer's
    // own behaviour, and deliberately the only viewport params forwarded -
    // iviewer URLs copied from a real browser session also carry channel/
    // colour/quantization settings (c, m, maps, fx, fy) that are irrelevant
    // noise for this use case and are dropped here rather than blindly
    // forwarded.
    foreach (['x' => PARAM_INT, 'y' => PARAM_INT, 'zm' => PARAM_FLOAT] as $key => $type) {
        $value = optional_param($key, '', $type);
        if ($value !== '') {
            $forwardparams[$key] = $value;
        }
    }

    // Explicit '&' separator - see the identical comment in omero_session::login().
    $querystring = '?' . http_build_query($forwardparams, '', '&');
}

// The same "images/dataset/browsable" shape author.php's own $proxyurl
// (config.baseProxyUrl, eventually stored as embed_tracking.sourceurl) is
// built from - reconstructed here so inject_teacher_heatmap_link() below
// can seed heatmap.php the first time a teacher follows it directly from
// the real embed, without ever having gone through author.php's own "View
// heatmap" link first. Deliberately excludes collapse_right/full_page/x/y/zm
// (those are either derived from $browsable or genuinely per-view, not part
// of the embed's own stable identity).
$sourceurlforlink = '';
if ($path === '/iviewer/') {
    $sourceurlparams = [];
    if ($images !== '') {
        $sourceurlparams['images'] = $images;
    }
    if ($dataset !== '') {
        $sourceurlparams['dataset'] = $dataset;
    }
    if ($browsable) {
        $sourceurlparams['browsable'] = 1;
    }
    $sourceurlforlink = $proxybase . '?' . http_build_query($sourceurlparams, '', '&');
}

$targeturl = $baseurl . $path . $querystring;

$session = omero_session::get_session($subject);
$result = fetch_from_omero($targeturl, $session);
if (looks_like_stale_session($result)) {
    // Force a fresh login and retry exactly once - cheaper than it sounds, since
    // it only costs anything on the already-failing path, not the common case
    // (a still-genuinely-valid cached session). Self-heals a session that's
    // cached-but-actually-dead on OMERO's side for *any* reason (its real idle
    // timeout outpacing SESSION_TTL_SECONDS being the one confirmed via real
    // testing, but this doesn't depend on that specific cause) - see
    // omero_session::get_session()'s own docblock for the $forcerefresh contract.
    $session = omero_session::get_session($subject, true);
    $result = fetch_from_omero($targeturl, $session);
}
['status' => $status, 'contenttype' => $contenttype, 'body' => $responsebody, 'location' => $locationheader] = $result;

http_response_code($status);
if ($contenttype) {
    header('Content-Type: ' . $contenttype);
}

if ($locationheader) {
    // Root-relative redirect (the only kind OMERO's own url_filter-scoped paths
    // should ever produce) - rewrite the same way response-body URLs are, so the
    // browser's follow-up request comes back through this proxy too.
    if (in_array_prefix($locationheader, PROXY_PATH_PREFIXES)) {
        header('Location: ' . $proxybase . '?' . http_build_query(['path' => $locationheader], '', '&'));
    } else {
        // Anything else (e.g. an absolute URL pointing straight at OMERO) is
        // exactly what this proxy exists to prevent leaking - refuse rather
        // than forward it verbatim.
        throw new \moodle_exception('invalidproxypath', 'local_omeroembed', '', $locationheader);
    }
}

if ($contenttype && str_contains($contenttype, 'text/css')) {
    // CSS gets its own rewrite pass: all.min.css's own url(images/foo.png),
    // url(fonts/bar.woff) etc. are relative to *the stylesheet's own path*, not
    // root-relative or literal quoted OMERO-prefixed strings, so neither
    // rewrite_response_urls() nor inject_server_workaround() touch these at all -
    // confirmed by real "Refusing to proxy path" log entries for exactly these
    // (courseid/subject got misparsed off a garbled PATH_INFO built from a path
    // that was never routed through this script correctly in the first place).
    echo rewrite_css_relative_urls($responsebody, $proxybase, $path);
} else if ($contenttype && (str_contains($contenttype, 'text/html') || str_contains($contenttype, 'javascript'))) {
    $rewritten = rewrite_response_urls($responsebody, $proxybase);
    if ($path === '/iviewer/' && str_contains($contenttype, 'text/html')) {
        $rewritten = inject_server_workaround($rewritten, $proxybase);

        // Per-embed choice if the generating author.php baked one in,
        // else the site admin default - see resolve_overlay_setting()'s
        // own docblock for why an explicit param (even '0') always wins.
        // Rotate isn't configurable at all (see inject_overlay_hide_css()'s
        // own comment) - always hidden, not part of this loop.
        $hideflags = [];
        foreach (['hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar'] as $key) {
            $hideflags[$key] = resolve_overlay_setting($key);
        }
        $enableannotations = resolve_overlay_setting('enableannotations');
        $annotationcolours = resolve_annotation_colours();
        $showomerorois = resolve_overlay_setting('showomerorois');
        $enablehotspot = resolve_overlay_setting('enablehotspot');
        // Multi-region sibling of $enablehotspot above - a click is correct
        // against ANY one of several teacher-marked regions instead of one
        // single shape (see classes/hotspot_multi_repository.php's own
        // docblock) - a separate flag, a separate table, never a mode of
        // the single-region feature.
        $enablehotspotmulti = resolve_overlay_setting('enablehotspotmulti');

        // A hotspot question wants a clean, distraction-free view - every
        // one of iviewer's own on-image overlay controls hidden, regardless
        // of this embed's individual overlay settings, AND the separate
        // student-annotation layer suppressed too (its own toolbar -
        // colour swatches, shape tools, lock/help buttons - competes for
        // the same screen space and the same clicks a hotspot question
        // needs, confirmed with the user after a live trial: the
        // annotation toolbar was still showing on a hotspot embed). Only
        // forced on the final student-facing embed: never during the
        // authoring tool's own preview (even with hotspot mode on there
        // too), since the teacher still needs a working zoom/pan to
        // precisely mark the region, and never on the heatmap view either
        // (a different mode entirely). Hotspot mode always wins over
        // enableannotations when both happen to be on for the same embed -
        // there's no sensible way to want both active on the same click at
        // once.
        $hotspotoverridesstudentview = ($enablehotspot || $enablehotspotmulti) && !$authoring && !$heatmap;
        if ($hotspotoverridesstudentview) {
            $hideflags = array_fill_keys(array_keys($hideflags), true);
            $enableannotations = false;
        }

        $rewritten = inject_overlay_hide_css($rewritten, $hideflags);
        $rewritten = inject_disable_rotate_interaction($rewritten);
        if ($heatmap) {
            // Its own mode, like $authoring - never combined with the
            // context-menu blocker/annotate/tracking scripts below.
            $rewritten = inject_heatmap_view_script($rewritten, $courseid, $embedid);
        } else if (!$authoring) {
            $rewritten = inject_contextmenu_blocker($rewritten, $enableannotations || $enablehotspot || $enablehotspotmulti);
            $rewritten = inject_annotation_script($rewritten, $courseid, $embedid, $enableannotations, $annotationcolours);
            $rewritten = inject_tracking_script($rewritten, $courseid, $embedid);
            $rewritten = inject_teacher_heatmap_link($rewritten, $courseid, $embedid, $sourceurlforlink);
            // Unconditional - not gated behind $showomerorois, see this
            // function's own docblock for why the risk it closes exists
            // either way.
            $rewritten = inject_hide_roi_panel_css($rewritten);
            if ($showomerorois) {
                $rewritten = inject_show_rois_script($rewritten);
            }
            if ($enablehotspotmulti && $hotspotmode === 'qtypemulti') {
                // qtype_omerohotspotmulti's own student-attempt mode - same
                // reasoning as the single-region qtype's own branch below,
                // just for the multi-region sibling qtype. Checked before
                // every $enablehotspot branch, so if a request somehow
                // carries both flags, multi wins (defense-in-depth -
                // author.php/js/author.js also keep the two checkboxes
                // mutually exclusive, so this is never reachable through
                // the normal authoring UI).
                $rewritten = inject_hotspot_multi_qtype_attempt_script($rewritten);
            } else if ($enablehotspot && $hotspotmode === 'qtype') {
                // qtype_omerohotspot's own student-attempt mode - no
                // $embedid to check existence against at all (the question
                // itself is the identity, not an embed placement); the
                // click never triggers an ajax.php call either, it only
                // ever posts up to the qtype's own hidden form fields (see
                // that plugin's own renderer.php/amd/src/question.js).
                $rewritten = inject_hotspot_qtype_attempt_script($rewritten);
            } else if ($enablehotspotmulti && $embedid !== ''
                    && \local_omeroembed\hotspot_multi_repository::exists($embedid)) {
                // Multi-region sibling of the standalone branch below - same
                // "existence-checked, not just flag-checked" reasoning,
                // just checking whether at least one region has been drawn.
                $rewritten = inject_hotspot_multi_attempt_script($rewritten, $courseid, $embedid);
            } else if ($enablehotspot && $embedid !== ''
                    && \local_omeroembed\hotspot_repository::exists($embedid)) {
                // Existence-checked, not just flag-checked - a teacher who
                // turned this on but never actually drew a region has
                // nothing for a student to click on yet; "simply not
                // injected" is the same convention
                // inject_annotation_script() already uses for an empty
                // $embedid.
                $rewritten = inject_hotspot_attempt_script($rewritten, $courseid, $embedid);
            }
        } else if ($hotspotmode === 'qtypemulti' && $enablehotspotmulti
                && has_capability('local/omeroembed:hotspotauthor', context_course::instance($courseid))) {
            // qtype_omerohotspotmulti's own question-edit-form preview -
            // same reasoning as the single-region qtype's own branch below,
            // just posting a whole array of regions instead of one shape.
            $rewritten = inject_hotspot_multi_edit_form_script($rewritten);
        } else if ($hotspotmode === 'qtype' && $enablehotspot
                && has_capability('local/omeroembed:hotspotauthor', context_course::instance($courseid))) {
            // qtype_omerohotspot's own question-edit-form preview - draws
            // the hidden region the same way the standalone activity's
            // authoring script does, but posts the finished geometry up
            // via postMessage into the edit form's own hidden field
            // instead of calling ajax.php's hotspot_save (there is no
            // $embedid, and nothing to persist server-side here at all -
            // the geometry is just one more field on that form, saved
            // when the whole question is saved).
            $rewritten = inject_hotspot_edit_form_script($rewritten);
        } else if ($enablehotspotmulti && $embedid !== ''
                && has_capability('local/omeroembed:hotspotauthor', context_course::instance($courseid))) {
            // The standalone activity's own multi-region authoring preview -
            // same reasoning as the single-region branch below, just
            // drawing/persisting a set of regions instead of one.
            $rewritten = inject_hotspot_multi_author_script($rewritten, $courseid, $embedid);
        } else if ($enablehotspot && $embedid !== ''
                && has_capability('local/omeroembed:hotspotauthor', context_course::instance($courseid))) {
            // The standalone activity's own authoring preview - drawing
            // the hidden region itself, never combined with any of the
            // student-facing scripts above. Capability-checked here too,
            // not just relied on as "author.php would never show this
            // checkbox to a student" - the same lesson the $authoring
            // capability fix itself already taught (see $authoring's own
            // comment above).
            $rewritten = inject_hotspot_author_script($rewritten, $courseid, $embedid);
        }
    }
    echo $rewritten;
} else {
    // Binary content (tile images, fonts, etc.) - stream through untouched.
    echo $responsebody;
}

/**
 * Rewrites root-relative OMERO URLs that appear as literal quoted strings in an
 * HTML/JS response (plain server-rendered <link href>/<script src> tags) so the
 * browser's own follow-up requests for them come back through this script instead
 * of going to OMERO directly.
 *
 * Deliberately only covers BODY_REWRITE_PREFIXES, not the full PROXY_PATH_PREFIXES
 * whitelist - see BODY_REWRITE_PREFIXES's own comment for why /webgateway/ and
 * /api/ are excluded here (they're handled by inject_server_workaround() instead,
 * see this file's top-of-file comment).
 *
 * @param string $body
 * @param string $proxybase Absolute URL of this proxy.php script (courseid/subject
 *                          already baked into its path).
 * @return string
 */
function rewrite_response_urls(string $body, string $proxybase): string {
    $prefixpattern = implode('|', array_map('preg_quote', BODY_REWRITE_PREFIXES));

    return preg_replace_callback(
        '#(["\'(])(' . $prefixpattern . ')([^"\'\)\s]*)#',
        function ($matches) use ($proxybase) {
            [$full, $quote, $prefix, $rest] = $matches;
            // Single query param ("path") - see the identical comment on the
            // "host" forward param above for why that matters here too: this
            // rewritten URL can land inside inline JS, where a literal "&" this
            // script emitted wouldn't itself be at risk (we're not going through
            // Django's template escaping here, we control this output byte for
            // byte) - but keeping the same single-param shape everywhere this
            // file builds a proxy URL is one less thing to reason about.
            return $quote . $proxybase . '?' . http_build_query(['path' => $prefix . $rest], '', '&');
        },
        $body
    );
}

/**
 * Rewrites url(...) references inside a CSS response so the browser's own
 * follow-up requests for them (icons, fonts) come back through this script.
 *
 * Unlike rewrite_response_urls(), this has to handle plain *relative* references
 * (e.g. `url(images/collapse-right.png)`, no leading slash) - CSS resolves those
 * against the stylesheet's own URL, not the page's, so they need the requesting
 * CSS file's own directory (derived from $path) as their base, not PROXY_PATH_PREFIXES.
 * Root-relative references (starting with one of PROXY_PATH_PREFIXES) are also
 * handled here for completeness, the same way rewrite_response_urls() does for
 * HTML/JS. Absolute (http(s):) and data: URIs are left untouched.
 *
 * @param string $body
 * @param string $proxybase Absolute URL of this proxy.php script (courseid/subject
 *                          already baked into its path).
 * @param string $path The OMERO-side path this CSS was itself fetched from (e.g.
 *                     "/static/omero_iviewer/css/all.min.css") - used as the base
 *                     directory for relative url(...) references within it.
 * @return string
 */
function rewrite_css_relative_urls(string $body, string $proxybase, string $path): string {
    $cssdir = substr($path, 0, strrpos($path, '/') + 1);

    return preg_replace_callback(
        '#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
        function ($matches) use ($proxybase, $cssdir) {
            [, $quote, $ref] = $matches;
            if (preg_match('#^(https?:|data:)#i', $ref)) {
                return $matches[0];
            }
            $target = str_starts_with($ref, '/') ? $ref : $cssdir . $ref;
            return 'url(' . $quote . $proxybase . '?' . http_build_query(['path' => $target], '', '&') . $quote . ')';
        },
        $body
    );
}

/**
 * Works around a real bug in omero-iviewer v0.17.0 (see this file's top-of-file
 * comment): Context.processInitialParameters() reads
 * `this.initParams[REQUEST_PARAMS.HOST]`, but REQUEST_PARAMS has no HOST key, so
 * that lookup is really `this.initParams["undefined"]`. Setting that exact property
 * on window.INITIAL_REQUEST_PARAMS ourselves - before main.js runs - makes iviewer's
 * own runtime-constructed requests (image_data, webgateway renders, ROI/annotation
 * fetches, etc.) route through this proxy exactly as if the intended mechanism
 * worked. Only applied to the initial /iviewer/ page, since that's the one and only
 * response containing a `window.INITIAL_REQUEST_PARAMS = {...}` block for main.js to
 * read from.
 *
 * @param string $body
 * @param string $proxybase Absolute URL of this proxy.php script (courseid/subject
 *                          already baked into its path).
 * @return string
 */
function inject_server_workaround(string $body, string $proxybase): string {
    $value = $proxybase . '?' . http_build_query(['path' => ''], '', '&');
    $script = '<script>window.INITIAL_REQUEST_PARAMS = window.INITIAL_REQUEST_PARAMS || {};'
        . "window.INITIAL_REQUEST_PARAMS['undefined'] = " . json_encode($value) . ';</script>';
    // Must land AFTER Django's own inline `window.INITIAL_REQUEST_PARAMS = {...}`
    // block, not before - that block does a full reassignment (`= {}`, then sets
    // each key), which would silently wipe out an 'undefined' key set any earlier
    // in <head>. Inserting just before </head> guarantees we run after it while
    // still running before main.js (loaded later, in <body>).
    $withhook = preg_replace('#(</head>)#i', $script . '$1', $body, 1);
    // If there's genuinely no </head> tag (shouldn't happen for iviewer's own page,
    // but fail safe rather than silently dropping the fix) fall back to appending.
    return $withhook !== null ? $withhook : ($body . $script);
}

/**
 * Resolves one of the 6 overlay/annotation boolean settings (hideoverview,
 * hideintensity, hidefullscreen, hidescaleline, hidezoom, enableannotations
 * - rotate isn't one of these, see inject_overlay_hide_css()'s own comment
 * for why) for the current request: an explicit per-embed value baked into
 * the URL by author.php wins outright (even '0', overriding a site default
 * of "on") - only when the param is genuinely absent (embeds generated
 * before this feature existed) does the site admin's own setting apply,
 * exactly as it always has.
 *
 * @param string $key
 * @return bool
 */
function resolve_overlay_setting(string $key): bool {
    $override = optional_param($key, null, PARAM_BOOL);
    return $override ?? (bool) get_config('local_omeroembed', $key);
}

/**
 * Same precedence idea as resolve_overlay_setting(), but for the
 * annotation colour palette - a set rather than a single boolean, so it's
 * its own function rather than another resolve_overlay_setting() call.
 * annotationcolours is always baked into the URL explicitly by author.php
 * (see that file's own comment) - absence means an embed generated before
 * this feature existed, falling back to the site default exactly like
 * every other setting here does. Either way, re-validated through
 * annotations_repository::parse_colours() rather than trusted as-is - a
 * request parameter is never more trustworthy just because it usually
 * comes from author.php's own form.
 *
 * @return string[]
 */
function resolve_annotation_colours(): array {
    $override = optional_param('annotationcolours', null, PARAM_RAW);
    if ($override !== null) {
        return \local_omeroembed\annotations_repository::parse_colours($override);
    }
    return \local_omeroembed\annotations_repository::parse_colours((string) get_config('local_omeroembed', 'annotationcolours'));
}

/**
 * Hides whichever of iviewer's own on-image UI controls this embed (or, for
 * embeds generated before that was possible, the site admin default - see
 * resolve_overlay_setting()) has configured to be hidden - for both the
 * authoring tool's live preview and the final student-facing embed, since
 * both load through this same proxy. Class names are OpenLayers' own
 * standard control classes, not anything OMERO-specific - found by
 * inspecting the live viewer in a browser. Purely cosmetic in every case
 * except "hide zoom controls" (see its own setting description) - a single
 * combined CSS override is enough, no JS needed.
 *
 * @param string $body
 * @param array<string, bool> $hideflags Keyed the same as the class map below, resolved by the caller.
 * @return string
 */
function inject_overlay_hide_css(string $body, array $hideflags): string {
    $classesbysetting = [
        'hideoverview' => '.ol-overviewmap',
        'hideintensity' => '.ol-intensity',
        'hidefullscreen' => '.ol-full-screen',
        'hidescaleline' => '.ol-scale-line',
        'hidezoom' => '.ol-zoom',
        // OMERO.web's own top navbar, not part of the OpenLayers viewer -
        // its links lead outside this locked-down embed and don't work
        // correctly here (confirmed with the user), so this is on by
        // default (see settings.php) unlike the OpenLayers controls above.
        'hidenavbar' => '.navbar-fixed-top',
    ];

    // Always hidden, not configurable - confirmed with the user: there's
    // no way to actually control slide rotation from this embed anyway,
    // so a control for it is just confusing clutter, never a real choice.
    $selectors = ['.ol-rotate'];
    foreach ($classesbysetting as $setting => $selector) {
        if (!empty($hideflags[$setting])) {
            $selectors[] = $selector;
        }
    }

    $style = '<style>' . implode(', ', $selectors) . ' { display: none !important; }</style>';
    if (!empty($hideflags['hidenavbar'])) {
        // The page's own top padding exists to keep content clear of the
        // fixed navbar above it - hiding the navbar without also touching
        // this would leave an empty gap the same height where it used to
        // be. Not zeroed outright though: .row.center has its own 3px
        // solid outline (confirmed directly, not a border - it renders
        // outside the box, not counted in padding/layout) that gives the
        // viewer a consistent frame on every side today. Dropping the top
        // padding to 0 leaves nothing above the box for that outline to
        // render into at the very top specifically, so it reads as
        // clipped/missing there while still showing normally on the other
        // three sides. 3px (matching the outline's own width) keeps the
        // same framed look on all four sides instead. Scoped to this one
        // specific container (.row.center is unique on this page,
        // confirmed directly), not a bare .row.center rule that could
        // catch something unrelated on a future page layout.
        $style .= '<style>body.container-fluid > .row.center { padding-top: 3px !important; }</style>';
    }
    $withstyle = preg_replace('#(</head>)#i', $style . '$1', $body, 1);
    return $withstyle !== null ? $withstyle : ($body . $style);
}

/**
 * Hiding the rotate *control* above only hides its on-screen button - it
 * does nothing about OpenLayers' own DragRotate *interaction*, which
 * independently listens for shift+drag on the map itself and is active
 * whether or not that control is visible. Confirmed with the user: the
 * gesture itself should be blocked too, not just the button - rotation
 * was never meant to be a real, usable feature here (same reasoning that
 * already made the button permanently hidden). js/annotate.js's own
 * redraw()/tryStartRotateDrag() rotation-compounding fix stays in place
 * regardless, as a safety net for however rotation might still end up
 * happening (a future OL update, a different input method, etc.) - this
 * function is the primary mitigation, not a replacement for that one.
 *
 * OpenLayers' interaction classes are minified in the production bundle
 * (constructor.name is meaningless, and no global `ol` reference is
 * exposed to compare against with instanceof) - identified instead by
 * `lastAngle_`, a property that only ever appears on DragRotate (it
 * tracks the previous pointer angle to compute a rotation delta; no
 * pan/zoom interaction has any use for an angle at all), confirmed
 * against every interaction actually present in the live viewer.
 *
 * @param string $body
 * @return string
 */
function inject_disable_rotate_interaction(string $body): string {
    $script = <<<'JS'
<script>
(function() {
    function init() {
        var viewerEl = document.querySelector('ol3-viewer');
        if (!viewerEl || !viewerEl.au || !viewerEl.au.controller) {
            window.setTimeout(init, 300);
            return;
        }
        var viewer = viewerEl.au.controller.viewModel.viewer;
        if (!viewer || !viewer.viewer_) {
            window.setTimeout(init, 300);
            return;
        }
        var interactions = viewer.viewer_.getInteractions();
        var toRemove = [];
        interactions.forEach(function(i) {
            if ('lastAngle_' in i) {
                toRemove.push(i);
            }
        });
        toRemove.forEach(function(i) {
            interactions.remove(i);
        });
    }
    init();
})();
</script>
JS;

    $withscript = preg_replace('#(</head>)#i', $script . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $script);
}

/**
 * Hides every element that lets a student manually open iviewer's own
 * right-hand ROI panel, on the final student-facing embed - unconditionally,
 * not gated behind "Show OMERO ROIs by default" (see
 * inject_show_rois_script()). The risk this closes exists regardless of
 * that setting: without it, a student could open OMERO's own ROI
 * *editing* panel directly - Save/Undo/Redo, Edit/Delete, a full drawing
 * toolbar (see inject_show_rois_script()'s own docblock for why that
 * panel is a real risk, not just clutter, and why this plugin can't offer
 * a "browse-only" version of it instead - it's OMERO's native editor, not
 * something built here that could be selectively stripped down).
 *
 * Two independent elements needed hiding here, confirmed live - hiding
 * only the first isn't enough on its own:
 * - `.collapse-right`, the panel's own expand/collapse toggle arrow.
 * - `#col_splitter_right`, a *separate* draggable divider (cursor:
 *   ew-resize) that resizes the panel by dragging - present and grabbable
 *   even while the panel is fully collapsed (width 0), so a student could
 *   still drag the panel open by its edge with the arrow itself hidden
 *   and no click involved at all. (`.col-splitter` alone isn't a safe
 *   selector - the same class also exists on a *different*, unrelated
 *   left-hand splitter, confirmed live - hence the specific #id here.)
 *
 * Never applied to the authoring tool's own preview - a teacher
 * legitimately might use this panel themselves while building an embed,
 * with their own real OMERO permissions; this is specifically about
 * closing off a student's manual access, not removing the panel outright.
 *
 * inject_show_rois_script()'s own programmatic `.click()` on
 * `.collapse-right` still works perfectly fine once it's hidden this way -
 * a dispatched click event doesn't check computed style before firing
 * bound handlers, it only means there's no visible, clickable/draggable
 * control left for a student to trigger it themselves. Like every other
 * client-side control this plugin hides (hidezoom, the rotate
 * interaction, etc.), this is a deterrent against casual/curious access
 * via the ordinary UI, not a hard security boundary - a student who opens
 * their browser's own dev tools could still call this element's .click()
 * directly, the same way they always could work around any of this
 * plugin's other client-side-only restrictions. The real access boundary
 * is still whatever OMERO session/permissions the proxy is authenticated as.
 *
 * @param string $body
 * @return string
 */
function inject_hide_roi_panel_css(string $body): string {
    $style = '<style>.collapse-right, #col_splitter_right { display: none !important; }</style>';
    $withstyle = preg_replace('#(</head>)#i', $style . '$1', $body, 1);
    return $withstyle !== null ? $withstyle : ($body . $style);
}

/**
 * Makes OMERO's native Regions of Interest render on the slide on page
 * load, WITHOUT leaving the panel that draws them actually open. Briefly
 * expands iviewer's own right-hand panel and switches it to the "ROIs"
 * tab - confirmed live that both steps are needed to make the shapes
 * render at all (expanding the panel alone lands on "Settings" with zero
 * ROI shapes drawn) - then collapses the panel straight back, since the
 * shapes themselves stay rendered on the canvas once drawn, independent
 * of whether the panel that triggered the draw is still open (also
 * confirmed live).
 *
 * The collapse-back-down step is not cosmetic: this right-hand panel is
 * OMERO's own ROI *editing* UI, not a read-only viewer - it carries
 * Save/Undo/Redo, Edit/Delete, per-row editable Comment fields, and a
 * full drawing toolbar. Leaving it open would hand a student direct
 * access to edit or delete the teacher's ROIs in OMERO itself, which is
 * exactly what this plugin's whole reason for existing (a locked-down,
 * read-only proxied embed) exists to prevent - confirmed as a real,
 * user-reported problem, not a hypothetical: an earlier version of this
 * function left the panel open once expanded, and that editing UI was
 * genuinely visible/usable on the student-facing embed.
 *
 * Gated behind this embed's own (or, absent an override, the site
 * default - see resolve_overlay_setting()) "Show OMERO ROIs by default"
 * choice; only ever called for the real student-facing embed, never the
 * authoring tool's own preview (the teacher already has full manual
 * control over the iviewer menu while building the embed) or the heatmap
 * view (a static overlay page, unrelated to this panel).
 *
 * `a[href="#rois"]` is the stable selector for the tab itself - its
 * visible text includes a "[N]" ROI count that varies per image (and was
 * confirmed fragile during investigation: matching literal text like
 * "ROIs [10]" would break for any image with a different count), but the
 * href fragment never changes. The panel's collapsed/expanded state isn't
 * reflected in its class list (`right-hand-panel sidebar au-target`
 * either way) - only in its own width (0 when collapsed, confirmed live).
 * `wasCollapsed` is captured once, before either click, so a panel some
 * other future change had already left open by default is (a) not
 * collapsed to trigger the render, and (b) not collapsed again
 * afterwards either - this function only ever restores whatever state it
 * found, it never forces the panel shut on someone else's behalf.
 *
 * @param string $body
 * @return string
 */
function inject_show_rois_script(string $body): string {
    $script = <<<'JS'
<script>
(function() {
    function init() {
        var roiTab = document.querySelector('a[href="#rois"]');
        if (!roiTab) {
            window.setTimeout(init, 300);
            return;
        }
        var panel = document.querySelector('.right-hand-panel');
        var wasCollapsed = !!(panel && panel.offsetWidth === 0);
        var toggle = document.querySelector('.collapse-right');
        if (wasCollapsed && toggle) {
            toggle.click();
        }
        roiTab.click();
        // The shapes take a moment to actually render onto the canvas once
        // the tab is activated - collapsing immediately in the same tick
        // risks racing that render, so this waits a beat before restoring
        // the panel to whatever state it found it in.
        window.setTimeout(function() {
            if (wasCollapsed && toggle) {
                toggle.click();
            }
        }, 300);
    }
    init();
})();
</script>
JS;

    $withscript = preg_replace('#(</head>)#i', $script . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $script);
}

/**
 * Suppresses iviewer's own right-click ROI context menu ("Enable ROI
 * Popup" / "Save Viewport as PNG" / "Get Link to Viewport") on the final
 * student-facing embed, gated behind whether this specific view has any
 * reason to want the right-click gesture kept clear for itself: either the
 * student annotation UI (original reason this existed - prep for it taking
 * the gesture over), or a hotspot question's own click-to-answer
 * interaction, standalone or a quiz question either way. Confirmed as a
 * real gap: originally gated on "Enable student annotations" alone, since
 * hotspot mode didn't exist yet when this was written - but hotspot mode
 * unconditionally forces annotations off (see $hotspotoverridesstudentview
 * above), so a hotspot question got OMERO's raw right-click menu back with
 * no way to suppress it, letting a student reach "Get Link to Viewport"
 * mid-attempt. Inert (never called) when none of these apply, and never
 * applied to the authoring tool's own live preview regardless (see
 * $authoring in this file's main dispatch), so teachers keep OMERO's
 * normal ROI menu while building an embed either way.
 *
 * iviewer is built with Aurelia, whose `.trigger`/`.delegate` binding
 * commands only ever attach bubble-phase listeners (confirmed against the
 * live app's own `<viewer-context-menu>` component) - so a listener added
 * here in the *capture* phase on `document` always runs first, and
 * `stopImmediatePropagation()` stops the event before Aurelia's own
 * handler ever sees it, regardless of which element actually shows the
 * menu. Loaded via <script> in <head>, so it's attached well before
 * iviewer's own app bundle (loaded later, in <body>) finishes initialising.
 *
 * @param string $body
 * @param bool $suppress Whether this view has any reason to want the
 *        right-click gesture suppressed - annotations enabled, or either
 *        hotspot mode active.
 * @return string
 */
function inject_contextmenu_blocker(string $body, bool $suppress): string {
    if (!$suppress) {
        return $body;
    }

    $script = '<script>document.addEventListener("contextmenu", function(e) {'
        . 'e.preventDefault(); e.stopImmediatePropagation();'
        . '}, true);</script>';
    $withscript = preg_replace('#(</head>)#i', $script . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $script);
}

/**
 * Injects js/annotate.js (a real file, not another inline string - this one's
 * substantial enough to deserve one) into the final student-facing embed,
 * plus a small inline config block giving it what it needs to call
 * ajax.php: courseid, embedid, a sesskey, and the ajax.php URL itself.
 * Requires $embedid to be non-empty - embeds generated before this feature
 * existed have no token (see author.js's generateEmbed()) and simply don't
 * get the annotation layer rather than erroring. Gated the same way as
 * inject_contextmenu_blocker() (see its own docblock for why): behind this
 * embed's own (or the site default) "Enable student annotations" choice,
 * and only ever on the final embed, never the authoring tool's own live
 * preview.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @param bool $enableannotations
 * @param string[] $annotationcolours Already resolved/validated by resolve_annotation_colours().
 * @return string
 */
function inject_annotation_script(
    string $body,
    int $courseid,
    string $embedid,
    bool $enableannotations,
    array $annotationcolours
): string {
    if (!$enableannotations || $embedid === '') {
        return $body;
    }

    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'colours' => $annotationcolours,
        'strings' => [
            'placepin' => get_string('annotatetoolbar_point', 'local_omeroembed'),
            'drawellipse' => get_string('annotatetoolbar_ellipse', 'local_omeroembed'),
            'drawrectangle' => get_string('annotatetoolbar_rectangle', 'local_omeroembed'),
            'drawpolygon' => get_string('annotatetoolbar_polygon', 'local_omeroembed'),
            'constrainshape' => get_string('annotatetoolbar_constrain', 'local_omeroembed'),
            'cancelpolygon' => get_string('annotatetoolbar_cancelpolygon', 'local_omeroembed'),
            'snapshot' => get_string('annotatetoolbar_snapshot', 'local_omeroembed'),
            'delete' => get_string('annotatetoolbar_delete', 'local_omeroembed'),
            'labelprompt' => get_string('annotatetoolbar_labelprompt', 'local_omeroembed'),
            'help' => get_string('annotatetoolbar_help', 'local_omeroembed'),
            'helptitle' => get_string('annotatetoolbar_helptitle', 'local_omeroembed'),
            'helpclose' => get_string('annotatetoolbar_helpclose', 'local_omeroembed'),
        ],
    ];
    $configscript = '<script id="omero-annotate-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/annotate.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * Injects js/track.js (the heatmap feature's periodic viewport sampler)
 * into the final student-facing embed. Requires $embedid to be non-empty,
 * and re-checks every one of the conditions that actually determine
 * whether sampling should happen at all, rather than relying on ajax.php's
 * action=sample to be the only thing enforcing them - if a teacher never
 * sees the notice/script in the first place, there's nothing for ajax.php
 * to even need to reject.
 *
 * The on-slide notice itself (confirmed with the user - students should
 * see this, not have it happen silently) is deliberately NOT injected here
 * as static HTML - confirmed live that iviewer's Aurelia app replaces
 * <body>'s contents once it hydrates, which silently wiped a first attempt
 * at doing exactly that. track.js builds and appends the notice element
 * itself instead, in JS, the same way annotate.js builds its own overlay
 * canvas/toolbar - only after the real viewer is confirmed ready, so it
 * survives Aurelia's own render pass. The notice text travels down via
 * config.noticeText below.
 *
 * Never applied when $heatmap is set (see inject_heatmap_view_script(),
 * called instead in that case) or on the authoring tool's own live preview.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_tracking_script(string $body, int $courseid, string $embedid): string {
    if ($embedid === '' || !\local_omeroembed\tracking_repository::is_active($embedid)) {
        return $body;
    }

    // A teacher/manager casually opening the real embed (not through
    // heatmap.php) shouldn't see the tracking notice or generate samples
    // of their own browsing - see ajax.php's action=sample for the actual
    // enforcement point this mirrors, so a teacher never even sees the
    // notice appear only for it to silently do nothing.
    $context = context_course::instance($courseid);
    if (has_capability('moodle/course:manageactivities', $context)) {
        return $body;
    }

    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'noticeText' => get_string('trackingnotice', 'local_omeroembed'),
    ];
    $configscript = '<script id="omero-track-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/track.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * Injects js/heatmap-view.js into a heatmap.php-driven load of the slide
 * (see the $heatmap dispatch branch above) - a teacher-only rendering mode,
 * entirely separate from the student-facing annotate/track scripts (never
 * combined with them). $heatmap already required
 * local/omeroembed:viewheatmap at the point it was read, near the top of
 * this file, so no further capability check is needed here.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_heatmap_view_script(string $body, int $courseid, string $embedid): string {
    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
    ];
    $configscript = '<script id="omero-heatmap-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/heatmap-view.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * Injects a small "View heatmap" link directly into the final student-
 * facing embed, visible only to viewers with local/omeroembed:viewheatmap
 * (teachers/editingteachers) - lets a teacher jump straight to heatmap.php
 * for this exact embed from wherever it's actually shown on a course page,
 * without having to open the authoring tool first. $sourceurlforlink lets
 * heatmap.php bootstrap itself (see that file's own $sourceurlparam
 * comment) even if this is genuinely the first time anyone has ever
 * followed a link there for this embedid.
 *
 * Requires $embedid to be non-empty (older embeds from before the heatmap
 * feature existed have no token to link with) - simply not injected
 * rather than erroring, same convention inject_annotation_script()/
 * inject_tracking_script() already use.
 *
 * Appended via a small inline script, polling until iviewer's own JS app
 * has settled, rather than spliced as static HTML before </body> - a
 * static tag there is silently wiped, confirmed live: iviewer rebuilds
 * document.body itself during its own startup, same reason
 * annotate.js/heatmap-view.js/track.js all defensively poll for the real
 * viewer element instead of assuming anything about the initial markup
 * survives.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @param string $sourceurlforlink
 * @return string
 */
function inject_teacher_heatmap_link(string $body, int $courseid, string $embedid, string $sourceurlforlink): string {
    if ($embedid === '') {
        return $body;
    }
    if (!has_capability('local/omeroembed:viewheatmap', context_course::instance($courseid))) {
        return $body;
    }

    $heatmapurl = new \moodle_url('/local/omeroembed/heatmap.php', [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sourceurl' => $sourceurlforlink,
        // SECURITY: heatmap.php's own first-visit branch requires this -
        // see that file's own comment on why a plain link with no sesskey
        // would otherwise let anyone who gets a teacher to click a crafted
        // URL plant an arbitrary external iframe src.
        'sesskey' => sesskey(),
    ]);

    $config = [
        'href' => $heatmapurl->out(false),
        'label' => get_string('viewheatmaplink', 'local_omeroembed'),
    ];
    $configscript = '<script id="omero-teacher-heatmap-link-config" type="application/json">'
        . json_encode($config) . '</script>';

    // Same defensive-poll idiom as annotate.js's findViewer()/init() -
    // waits for the real ol3-viewer element (a reliable "the app has
    // finished its own DOM setup" signal) before appending, rather than
    // a fixed delay guess.
    $script = <<<'JS'
<script>
(function() {
    var configEl = document.getElementById('omero-teacher-heatmap-link-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }
    function init() {
        if (!document.querySelector('ol3-viewer') || document.getElementById('omero-teacher-heatmap-link')) {
            window.setTimeout(init, 300);
            return;
        }
        var link = document.createElement('a');
        link.id = 'omero-teacher-heatmap-link';
        link.href = config.href;
        link.target = '_blank';
        link.textContent = config.label;
        // Bottom, not top - top-right collides with OMERO's own
        // File/ROIs/Help toolbar (confirmed live: the link rendered but
        // was visually swallowed by the toolbar's own blue bar, same
        // stacking area). Centred horizontally rather than pinned to
        // bottom-left, since iviewer renders its own small corner control
        // in both bottom corners (confirmed live) - the middle is the one
        // stretch of the bottom edge iviewer doesn't already use.
        link.style.cssText = 'position:fixed; bottom:0.5rem; left:50%; transform:translateX(-50%); z-index:1000; '
            + 'background:rgba(0,0,0,0.65); color:#fff; font-size:0.75rem; padding:0.3rem 0.6rem; '
            + 'border-radius:4px; font-family:sans-serif; text-decoration:none; white-space:nowrap;';
        document.body.appendChild(link);
    }
    init();
})();
</script>
JS;

    $withscript = preg_replace('#(</head>)#i', $configscript . $script . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $script);
}

/**
 * Injects js/hotspot-author.js into the authoring tool's own live preview
 * iframe - the small drag-to-draw UI a teacher uses to mark the hidden
 * correct-answer region for the click-to-answer hotspot feature. Never
 * injected on the final student-facing embed (see
 * inject_hotspot_attempt_script() for that side) - the caller already
 * re-checks local/omeroembed:hotspotauthor before calling this, same
 * belt-and-braces reasoning as every other capability-gated inject
 * function in this file.
 *
 * Deliberately mirrors js/annotate.js's own config-script + src-script
 * pattern (inject_annotation_script(), just above) rather than reusing
 * that file wholesale - annotate.js is a large, security-irrelevant
 * (public, multi-shape) student-facing module; conflating it with a small,
 * privileged, single-secret-shape authoring tool would couple two features
 * with very different security postures for no real benefit.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_hotspot_author_script(string $body, int $courseid, string $embedid): string {
    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'strings' => [
            'drawellipse' => get_string('hotspottoolbar_ellipse', 'local_omeroembed'),
            'drawrectangle' => get_string('hotspottoolbar_rectangle', 'local_omeroembed'),
            'constrainshape' => get_string('annotatetoolbar_constrain', 'local_omeroembed'),
            'clear' => get_string('hotspottoolbar_clear', 'local_omeroembed'),
            'saved' => get_string('hotspot_saved', 'local_omeroembed'),
        ],
    ];
    $configscript = '<script id="omero-hotspot-author-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-author.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * Injects js/hotspot-attempt.js into the final student-facing embed - a
 * click listener that posts the clicked image-pixel coordinates to
 * ajax.php's hotspot_attempt action and shows the bare correct/incorrect
 * verdict it gets back. The hidden region's own geometry never reaches
 * this script, or any script - see hotspot_repository::check_attempt()'s
 * own docblock for where that's actually enforced.
 *
 * Only ever called once the caller has already confirmed a region exists
 * (see this file's own dispatch, above) - "simply not injected" is the
 * same convention inject_annotation_script() already uses for an embed
 * with nothing to show.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_hotspot_attempt_script(string $body, int $courseid, string $embedid): string {
    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'strings' => [
            'correct' => get_string('hotspot_correct', 'local_omeroembed'),
            'incorrect' => get_string('hotspot_incorrect', 'local_omeroembed'),
        ],
    ];
    $configscript = '<script id="omero-hotspot-attempt-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-attempt.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * qtype_omerohotspot's own question-edit-form authoring mode - draws the
 * hidden region the same way inject_hotspot_author_script() does, but the
 * injected script (js/hotspot-qtype-author.js) reports the finished
 * geometry to the parent *question edit form* via postMessage instead of
 * calling ajax.php's hotspot_save. No courseid/embedid needed by this
 * function at all - unlike the standalone activity, there's nothing to
 * key a database row by here (the geometry is just one more field on the
 * qtype's own edit form, saved when the whole question is saved).
 *
 * @param string $body
 * @return string
 */
function inject_hotspot_edit_form_script(string $body): string {
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-qtype-author.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $srcscript);
}

/**
 * qtype_omerohotspot's own student-attempt mode - the injected script
 * (js/hotspot-qtype-attempt.js) reports every click's image-pixel
 * coordinates to the parent attempt page via postMessage, but never
 * calls ajax.php and never learns whether a click was correct - that
 * verdict only ever gets computed later, server-side, by
 * qtype_omerohotspot_question::grade_response() (see that class's own
 * docblock), whenever the surrounding question behaviour decides to
 * grade. No courseid/embedid needed here either, same reasoning as
 * inject_hotspot_edit_form_script() above.
 *
 * @param string $body
 * @return string
 */
function inject_hotspot_qtype_attempt_script(string $body): string {
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-qtype-attempt.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $srcscript);
}

/**
 * Multi-region sibling of inject_hotspot_author_script() - injects
 * js/hotspot-multi-author.js into the standalone activity's own authoring
 * preview, drawing/persisting a whole SET of hidden regions instead of one
 * shape (see classes/hotspot_multi_repository.php's own docblock). Same
 * config-script + src-script pattern, same capability already re-checked
 * by the caller before this is ever reached.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_hotspot_multi_author_script(string $body, int $courseid, string $embedid): string {
    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'strings' => [
            'drawellipse' => get_string('hotspottoolbar_ellipse', 'local_omeroembed'),
            'drawrectangle' => get_string('hotspottoolbar_rectangle', 'local_omeroembed'),
            'constrainshape' => get_string('annotatetoolbar_constrain', 'local_omeroembed'),
            'clear' => get_string('hotspottoolbar_clear', 'local_omeroembed'),
            'saved' => get_string('hotspot_saved', 'local_omeroembed'),
            'deleteregion' => get_string('hotspotmultitoolbar_delete', 'local_omeroembed'),
        ],
    ];
    $configscript = '<script id="omero-hotspotmulti-author-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-multi-author.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * Multi-region sibling of inject_hotspot_attempt_script() - injects
 * js/hotspot-multi-attempt.js into the final student-facing embed. Only
 * ever called once the caller has already confirmed at least one region
 * exists (see this file's own dispatch, above).
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_hotspot_multi_attempt_script(string $body, int $courseid, string $embedid): string {
    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'strings' => [
            'correct' => get_string('hotspot_correct', 'local_omeroembed'),
            'incorrect' => get_string('hotspot_incorrect', 'local_omeroembed'),
        ],
    ];
    $configscript = '<script id="omero-hotspotmulti-attempt-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-multi-attempt.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}

/**
 * qtype_omerohotspotmulti's own question-edit-form authoring mode -
 * multi-region sibling of inject_hotspot_edit_form_script(), injecting
 * js/hotspot-multi-qtype-author.js instead - reports the whole array of
 * regions to the parent question edit form via postMessage, same "no
 * embedid, nothing persisted until the whole question is saved" reasoning.
 *
 * @param string $body
 * @return string
 */
function inject_hotspot_multi_edit_form_script(string $body): string {
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-multi-qtype-author.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $srcscript);
}

/**
 * qtype_omerohotspotmulti's own student-attempt mode - multi-region
 * sibling of inject_hotspot_qtype_attempt_script(), injecting
 * js/hotspot-multi-qtype-attempt.js instead. Same "never calls ajax.php,
 * grading happens later via grade_response()" reasoning.
 *
 * @param string $body
 * @return string
 */
function inject_hotspot_multi_qtype_attempt_script(string $body): string {
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/hotspot-multi-qtype-attempt.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $srcscript);
}
