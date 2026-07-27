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
 * SECURITY: require_login($courseid) below is the actual access gate for this whole
 * plugin, not the filter (see classes/text_filter.php's own comment on this) -
 * *this* script is what a student's browser actually requests when the iframe loads,
 * on a separate HTTP request from whatever page embedded it. require_login() re-derives
 * the real, current session server-side and checks enrolment against the real
 * courseid - it cannot be bypassed by editing the iframe's query string, since courseid
 * only selects *which* course to check enrolment against, not whether that check happens.
 *
 * KNOWN LIMITATION (see PROXY_PATH_PREFIXES below and the project README): iviewer is
 * a full client-side app, so its own JS makes many follow-up requests (tiles, JSON
 * metadata, static assets) after this script's initial response. Those are handled by
 * rewriting root-relative URLs in the response body to route back through this same
 * script (see rewrite_response_urls()) - this covers the common cases (HTML attributes,
 * simple JS string assignments) but has NOT yet been exercised against a real OMERO
 * instance's actual iviewer output. Expect to need real iteration here once real test
 * data exists (see the project README's "Known gaps" section) - treat this as a first
 * working pass, not a finished implementation.
 *
 * @package    filter_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use filter_omeroembed\omero_session;

/**
 * Only these path prefixes are ever proxied through to OMERO - matches the same
 * prefixes already whitelisted server-side by omero.web.public.url_filter for the
 * existing public-account setup this plugin replaces (see omero-mvls's own
 * omero_config/extraweb.omero). Anything else (admin pages, other users' data, etc.)
 * is refused before a request is even made.
 */
const PROXY_PATH_PREFIXES = ['/iviewer/', '/webgateway/', '/api/', '/static/'];

$courseid = required_param('courseid', PARAM_INT);
$subject = required_param('subject', PARAM_ALPHANUMEXT);
$path = optional_param('path', '/iviewer/', PARAM_PATH);

$course = get_course($courseid);
require_login($course);

if (!in_array_prefix($path, PROXY_PATH_PREFIXES)) {
    throw new \moodle_exception('invalidproxypath', 'filter_omeroembed', '', $path);
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

$session = omero_session::get_session($subject);
$baseurl = rtrim((string) get_config('filter_omeroembed', 'omerobaseurl'), '/');

// On the *initial* load (path defaults to /iviewer/), forward the images/dataset
// query params the filter generated the iframe with, per the plan's Section 6 embed
// format. Follow-up requests (the ones rewrite_response_urls() redirects back here)
// carry their own real query string in $path itself instead.
$querystring = '';
if ($path === '/iviewer/') {
    $forwardparams = [
        'images' => required_param('images', PARAM_SEQUENCE),
        'full_page' => 'true',
    ];
    $dataset = optional_param('dataset', '', PARAM_INT);
    if ($dataset !== '') {
        $forwardparams['dataset'] = $dataset;
    }
    // Explicit '&' separator - see the identical comment in omero_session::login().
    $querystring = '?' . http_build_query($forwardparams, '', '&');
}

$targeturl = $baseurl . $path . $querystring;
$proxybase = (new \moodle_url('/filter/omeroembed/proxy.php'))->out(false);
$proxyparams = ['courseid' => $courseid, 'subject' => $subject];

// CURLOPT_FOLLOWLOCATION is deliberately off (see below) - OMERO's own redirects
// (e.g. iviewer normalising a URL) must come back through this proxy too, not be
// followed server-side, otherwise the browser's address bar/history would never
// reflect them and any relative paths in the *final* response could resolve wrong.
$locationheader = null;
$ch = curl_init($targeturl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $session['cookie']]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curlhandle, $headerline) use (&$locationheader) {
    if (preg_match('/^Location:\s*(.+)$/i', trim($headerline), $m)) {
        $locationheader = trim($m[1]);
    }
    return strlen($headerline);
});
$responsebody = curl_exec($ch);
if ($responsebody === false) {
    $error = curl_error($ch);
    curl_close($ch);
    throw new \moodle_exception('omeroconnectionfailed', 'filter_omeroembed', '', null, $error);
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contenttype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

http_response_code($status);
if ($contenttype) {
    header('Content-Type: ' . $contenttype);
}

if ($locationheader) {
    // Root-relative redirect (the only kind OMERO's own url_filter-scoped paths
    // should ever produce) - rewrite the same way response-body URLs are, so the
    // browser's follow-up request comes back through this proxy too.
    if (in_array_prefix($locationheader, PROXY_PATH_PREFIXES)) {
        $params = $proxyparams + ['path' => $locationheader];
        header('Location: ' . $proxybase . '?' . http_build_query($params, '', '&'));
    } else {
        // Anything else (e.g. an absolute URL pointing straight at OMERO) is
        // exactly what this proxy exists to prevent leaking - refuse rather
        // than forward it verbatim.
        throw new \moodle_exception('invalidproxypath', 'filter_omeroembed', '', $locationheader);
    }
}

if ($contenttype && (str_contains($contenttype, 'text/html') || str_contains($contenttype, 'javascript'))) {
    echo rewrite_response_urls($responsebody, $proxybase, $proxyparams);
} else {
    // Binary content (tile images, fonts, etc.) - stream through untouched.
    echo $responsebody;
}

/**
 * Rewrites root-relative OMERO URLs in an HTML/JS response so the browser's own
 * follow-up requests for them come back through this script instead of going to
 * OMERO directly.
 *
 * Covers: href="/...", src="/...", and simple JS-string occurrences of the same
 * whitelisted prefixes (e.g. fetch("/webgateway/..."). Does NOT yet handle every
 * pattern a real build of iviewer's JS might use (e.g. dynamically-constructed URLs
 * built from concatenated string parts) - see this file's own top-of-file comment.
 *
 * @param string $body
 * @param string $proxybase Absolute URL of this proxy.php script.
 * @param array $baseparams courseid/subject to preserve on every rewritten link.
 * @return string
 */
function rewrite_response_urls(string $body, string $proxybase, array $baseparams): string {
    $prefixpattern = implode('|', array_map('preg_quote', PROXY_PATH_PREFIXES));

    return preg_replace_callback(
        '#(["\'(])(' . $prefixpattern . ')([^"\'\)\s]*)#',
        function ($matches) use ($proxybase, $baseparams) {
            [$full, $quote, $prefix, $rest] = $matches;
            $params = $baseparams + ['path' => $prefix . $rest];
            // Explicit '&' - this rewritten URL can land inside inline JS (e.g.
            // fetch("...")), where "&amp;" would be sent literally and break
            // server-side query parsing. Unescaped "&" is also universally
            // tolerated in HTML attribute values by real browsers, even though
            // strict (X)HTML would prefer &amp; there - functional correctness
            // in both contexts wins over strict-mode purity in one of them.
            return $quote . $proxybase . '?' . http_build_query($params, '', '&');
        },
        $body
    );
}
