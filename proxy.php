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
$authoring = optional_param('authoring', false, PARAM_BOOL);

// Per-placement token minted by author.js's generateEmbed() (see
// js/annotate.js and its own plan doc) - absent on older embeds generated
// before the annotation feature existed, and always absent on the
// authoring preview. inject_annotation_script() requires this to be
// present before injecting anything, since ajax.php needs it to scope
// which embed placement an annotation belongs to.
$embedid = optional_param('embedid', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
require_login($course);

if (!in_array_prefix($path, PROXY_PATH_PREFIXES)) {
    throw new \moodle_exception('invalidproxypath', 'local_omeroembed', '', $path);
}

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
        $rewritten = inject_overlay_hide_css($rewritten);
        if (!$authoring) {
            $rewritten = inject_contextmenu_blocker($rewritten);
            $rewritten = inject_annotation_script($rewritten, $courseid, $embedid);
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
 * Hides whichever of iviewer's own on-image UI controls are configured to be
 * hidden (Site administration > Plugins > Local plugins > OMERO slide embed >
 * "Embedded viewer overlays") - for both the authoring tool's live preview and
 * the final student-facing embed, since both load through this same proxy.
 * Class names are OpenLayers' own standard control classes, not anything
 * OMERO-specific - found by inspecting the live viewer in a browser. Purely
 * cosmetic in every case except "hide zoom controls" (see its own setting
 * description) - a single combined CSS override is enough, no JS needed.
 *
 * @param string $body
 * @return string
 */
function inject_overlay_hide_css(string $body): string {
    $classesbysetting = [
        'hideoverview' => '.ol-overviewmap',
        'hiderotate' => '.ol-rotate',
        'hideintensity' => '.ol-intensity',
        'hidefullscreen' => '.ol-full-screen',
        'hidescaleline' => '.ol-scale-line',
        'hidezoom' => '.ol-zoom',
    ];

    $selectors = [];
    foreach ($classesbysetting as $setting => $selector) {
        if (get_config('local_omeroembed', $setting)) {
            $selectors[] = $selector;
        }
    }
    if (!$selectors) {
        return $body;
    }

    $style = '<style>' . implode(', ', $selectors) . ' { display: none !important; }</style>';
    $withstyle = preg_replace('#(</head>)#i', $style . '$1', $body, 1);
    return $withstyle !== null ? $withstyle : ($body . $style);
}

/**
 * Suppresses iviewer's own right-click ROI context menu on the final
 * student-facing embed, gated behind the (currently off-by-default)
 * "Enable student annotations" setting - prep for a student annotation UI
 * that will take the right-click gesture over; inert (never called) while
 * that setting is off, and never applied to the authoring tool's own live
 * preview regardless (see $authoring in this file's main dispatch), so
 * teachers keep OMERO's normal ROI menu while building an embed.
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
 * @return string
 */
function inject_contextmenu_blocker(string $body): string {
    if (!get_config('local_omeroembed', 'enableannotations')) {
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
 * inject_contextmenu_blocker() (see its own docblock for why): behind the
 * "Enable student annotations" setting, and only ever on the final embed,
 * never the authoring tool's own live preview.
 *
 * @param string $body
 * @param int $courseid
 * @param string $embedid
 * @return string
 */
function inject_annotation_script(string $body, int $courseid, string $embedid): string {
    if (!get_config('local_omeroembed', 'enableannotations') || $embedid === '') {
        return $body;
    }

    $config = [
        'courseid' => $courseid,
        'embedid' => $embedid,
        'sesskey' => sesskey(),
        'ajaxurl' => (new \moodle_url('/local/omeroembed/ajax.php'))->out(false),
        'strings' => [
            'placepin' => get_string('annotatetoolbar_point', 'local_omeroembed'),
            'drawellipse' => get_string('annotatetoolbar_ellipse', 'local_omeroembed'),
            'drawrectangle' => get_string('annotatetoolbar_rectangle', 'local_omeroembed'),
            'snapshot' => get_string('annotatetoolbar_snapshot', 'local_omeroembed'),
            'delete' => get_string('annotatetoolbar_delete', 'local_omeroembed'),
            'labelprompt' => get_string('annotatetoolbar_labelprompt', 'local_omeroembed'),
        ],
    ];
    $configscript = '<script id="omero-annotate-config" type="application/json">'
        . json_encode($config) . '</script>';
    $srcscript = '<script src="' . (new \moodle_url('/local/omeroembed/js/annotate.js'))->out(false) . '"></script>';

    $withscript = preg_replace('#(</head>)#i', $configscript . $srcscript . '$1', $body, 1);
    return $withscript !== null ? $withscript : ($body . $configscript . $srcscript);
}
