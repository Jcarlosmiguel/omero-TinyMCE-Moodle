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

namespace local_omeroembed;

/**
 * Establishes and caches an OMERO.web session (as a subject service account), so
 * proxy.php doesn't need to log in to OMERO on every single request.
 *
 * Deliberately server-side only: the cookies this class returns are used to make
 * requests *from Moodle's server to OMERO's server* (see proxy.php) - they are never
 * sent to, or stored in, the student's own browser/session.
 *
 * Swapping "subject service account" for "the logged-in Moodle user's own OMERO
 * identity" later (the plan document's Section 8, LDAP passthrough mode - not built
 * yet) only means changing what get_credentials_for_subject() returns; the
 * login/caching/proxying mechanics below stay the same either way.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class omero_session {
    /**
     * @var int How long a cached OMERO.web session is trusted before re-logging in
     * *without being asked to* (i.e. purely from having sat in cache this long).
     * Deliberately shorter than OMERO's own real idle timeout (confirmed ~10 min via
     * the CLI's own "Idle timeout: 10 min" message) rather than trying to match it
     * exactly - this is just a soft ceiling on how often a healthy session gets
     * re-established anyway; it is NOT what makes staleness handling correct. That's
     * $forcerefresh below and proxy.php's retry-once-on-apparent-staleness logic,
     * which self-heals regardless of whether this constant undercuts OMERO's real
     * timeout on any given day.
     */
    const SESSION_TTL_SECONDS = 8 * 60;

    /**
     * Returns a "Cookie:" header value (sessionid + csrftoken) authenticated as the
     * given subject's OMERO service account - from cache if still fresh, otherwise by
     * logging in to OMERO.web now.
     *
     * @param string $subject A local_omeroembed_subjects.id, or (permanently,
     *                         for backward compatibility) an old-style plain
     *                         name - see get_credentials_for_subject()'s own
     *                         docblock.
     * @param bool $forcerefresh Skip the cache entirely and log in fresh, even if a
     *                            cached session would otherwise still look valid by
     *                            SESSION_TTL_SECONDS alone. Used by proxy.php to
     *                            recover from a session that's cached-but-actually-
     *                            already-dead on OMERO's side (its real idle timeout
     *                            can outpace our TTL guess) - see proxy.php's
     *                            looks_like_stale_session() for how that's detected.
     * @return array{cookie: string, csrftoken: string} Cookie header value and the
     *         csrftoken value on its own (proxy.php needs the latter separately for
     *         any state-changing OMERO request, per Django's CSRF requirements).
     * @throws \moodle_exception If the subject key is unknown, or login fails.
     */
    public static function get_session(string $subject, bool $forcerefresh = false): array {
        $cache = \cache::make('local_omeroembed', 'sessions');
        $cached = $forcerefresh ? false : $cache->get($subject);

        if ($cached && (time() - $cached['established']) < self::SESSION_TTL_SECONDS) {
            return ['cookie' => $cached['cookie'], 'csrftoken' => $cached['csrftoken']];
        }

        $session = self::login($subject);
        $cache->set($subject, [
            'cookie' => $session['cookie'],
            'csrftoken' => $session['csrftoken'],
            'established' => time(),
        ]);

        return $session;
    }

    /**
     * Looks up a subject's OMERO username/password - $subject is the
     * {subject} path segment from proxy.php/author.php's own URLs, which
     * means one of two things (see subject_repository.php's own docblock
     * and db/install.xml's comment on local_omeroembed_subjects for the
     * full reasoning):
     *
     * - A local_omeroembed_subjects.id (purely numeric) - the current
     *   scheme, for every subject created via mysubjects.php.
     * - A plain string name - the *old* site-wide admin-list key, frozen
     *   into any embed pasted into course content before this table
     *   existed. Permanently supported as a fallback (matches any owner,
     *   via subject_repository::get_credentials_by_legacy_name()) so
     *   nothing already pasted anywhere ever breaks, even though nothing
     *   new is ever created this way again.
     *
     * @param string $subject
     * @return array{username: string, password: string}
     * @throws \moodle_exception If $subject doesn't resolve either way.
     */
    protected static function get_credentials_for_subject(string $subject): array {
        $credentials = ctype_digit($subject)
            ? \local_omeroembed\subject_repository::get_credentials_by_id((int) $subject)
            : \local_omeroembed\subject_repository::get_credentials_by_legacy_name($subject);

        if ($credentials === null) {
            throw new \moodle_exception('unknownsubject', 'local_omeroembed', '', $subject);
        }
        return $credentials;
    }

    /**
     * Logs in to OMERO.web as the given subject's service account and returns the
     * resulting session. Not cached itself - callers should go through get_session().
     *
     * OMERO.web's login is a standard Django form login: GET the login page first to
     * obtain a csrftoken cookie, then POST credentials back including that same token
     * (Django's CSRF middleware requires the cookie and the posted field to match).
     *
     * @param string $subject
     * @return array{cookie: string, csrftoken: string}
     * @throws \moodle_exception If OMERO is unreachable or the login is rejected.
     */
    protected static function login(string $subject): array {
        $credentials = self::get_credentials_for_subject($subject);
        $baseurl = rtrim((string) get_config('local_omeroembed', 'omerobaseurl'), '/');
        $loginurl = $baseurl . '/webclient/login/';

        // Step 1: GET the login page to obtain an initial csrftoken cookie.
        $getresult = self::curl_request($loginurl, 'GET');
        $csrftoken = $getresult['cookies']['csrftoken'] ?? null;
        if (!$csrftoken) {
            throw new \moodle_exception(
                'omerologinfailed',
                'local_omeroembed',
                '',
                $subject,
                'No csrftoken cookie received from ' . $loginurl
            );
        }

        // Step 2: POST credentials, presenting that same csrftoken both as a cookie
        // and as the csrfmiddlewaretoken form field.
        //
        // http_build_query()'s third argument MUST be explicit here - without it,
        // the separator defaults to ini_get('arg_separator.output'), which some PHP
        // configs set to "&amp;" (HTML-safe by default) instead of "&". A raw POST
        // body needs a literal "&", not "&amp;" - the latter breaks Django's form
        // parsing entirely (confirmed: it silently loses every field including
        // csrfmiddlewaretoken, producing a generic "CSRF token missing" error that
        // gives no hint the real problem is the separator).
        $postfields = http_build_query([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'server' => 1,
            'csrfmiddlewaretoken' => $csrftoken,
        ], '', '&');
        $postresult = self::curl_request($loginurl, 'POST', $postfields, [
            'Cookie: csrftoken=' . $csrftoken,
            'Referer: ' . $loginurl,
        ]);

        $sessionid = $postresult['cookies']['sessionid'] ?? null;
        if (!$sessionid) {
            throw new \moodle_exception(
                'omerologinfailed',
                'local_omeroembed',
                '',
                $subject,
                'Login POST did not return a sessionid cookie - check the configured '
                . 'credentials for this subject.'
            );
        }
        // OMERO.web rotates csrftoken on login - prefer the new value if one came back.
        $csrftoken = $postresult['cookies']['csrftoken'] ?? $csrftoken;

        return [
            'cookie' => 'sessionid=' . $sessionid . '; csrftoken=' . $csrftoken,
            'csrftoken' => $csrftoken,
        ];
    }

    /**
     * Thin wrapper around Moodle's \curl class that also extracts Set-Cookie
     * response headers - \curl::getResponse() exposes them as a plain header
     * name/value map (or an array of values when the same header name
     * appears more than once, e.g. multiple Set-Cookie lines), so this just
     * picks out and parses those.
     *
     * @param string $url
     * @param string $method 'GET' or 'POST'.
     * @param string|null $postfields Already-encoded POST body, if $method is 'POST'.
     * @param string[] $extraheaders Additional raw header lines to send.
     * @return array{body: string, cookies: array<string,string>, status: int}
     * @throws \moodle_exception On a curl-level failure (network/DNS/TLS).
     */
    protected static function curl_request(
        string $url,
        string $method,
        ?string $postfields = null,
        array $extraheaders = []
    ): array {
        $curl = new \curl();
        $curl->setHeader($extraheaders);
        $curl->setopt([
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_TIMEOUT' => 15,
        ]);

        $body = $method === 'POST' ? $curl->post($url, (string) $postfields) : $curl->get($url);

        if ($curl->get_errno()) {
            throw new \moodle_exception('omeroconnectionfailed', 'local_omeroembed', '', null, $curl->error);
        }

        $cookies = [];
        foreach ($curl->getResponse() as $name => $value) {
            if (strtolower($name) !== 'set-cookie') {
                continue;
            }
            foreach ((array) $value as $onesetcookie) {
                if (preg_match('/^\s*([^=]+)=([^;]+)/', (string) $onesetcookie, $m)) {
                    $cookies[trim($m[1])] = trim($m[2]);
                }
            }
        }

        $info = $curl->get_info();
        return ['body' => $body, 'cookies' => $cookies, 'status' => (int) ($info['http_code'] ?? 0)];
    }
}
