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
    /** @var int How long a cached OMERO.web session is trusted before re-logging in. */
    const SESSION_TTL_SECONDS = 20 * 60;

    /**
     * Returns a "Cookie:" header value (sessionid + csrftoken) authenticated as the
     * given subject's OMERO service account - from cache if still fresh, otherwise by
     * logging in to OMERO.web now.
     *
     * @param string $subject Subject key, as used in {omero:ID:SUBJECT} and the
     *                         admin settings textarea.
     * @return array{cookie: string, csrftoken: string} Cookie header value and the
     *         csrftoken value on its own (proxy.php needs the latter separately for
     *         any state-changing OMERO request, per Django's CSRF requirements).
     * @throws \moodle_exception If the subject key is unknown, or login fails.
     */
    public static function get_session(string $subject): array {
        $cache = \cache::make('local_omeroembed', 'sessions');
        $cached = $cache->get($subject);

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
     * Lists every configured subject key (never their credentials) - used to populate
     * the subject dropdown on author.php. Public and safe to call from a page that
     * only needs to know which subjects exist, not authenticate as one.
     *
     * @return string[]
     */
    public static function get_subject_keys(): array {
        $raw = (string) get_config('local_omeroembed', 'subjects');
        $keys = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) === 3) {
                $keys[] = trim($parts[0]);
            }
        }
        return $keys;
    }

    /**
     * Looks up a subject's OMERO username/password from the admin settings textarea
     * (one "subject_key|username|password" line per subject - see settings.php).
     *
     * @param string $subject
     * @return array{username: string, password: string}
     * @throws \moodle_exception If the subject key isn't configured.
     */
    protected static function get_credentials_for_subject(string $subject): array {
        $raw = (string) get_config('local_omeroembed', 'subjects');
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) === 3 && trim($parts[0]) === $subject) {
                return ['username' => trim($parts[1]), 'password' => trim($parts[2])];
            }
        }
        throw new \moodle_exception('unknownsubject', 'local_omeroembed', '', $subject);
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
            throw new \moodle_exception('omerologinfailed', 'local_omeroembed', '', $subject,
                'No csrftoken cookie received from ' . $loginurl);
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
            throw new \moodle_exception('omerologinfailed', 'local_omeroembed', '', $subject,
                'Login POST did not return a sessionid cookie - check the configured '
                . 'credentials for this subject.');
        }
        // OMERO.web rotates csrftoken on login - prefer the new value if one came back.
        $csrftoken = $postresult['cookies']['csrftoken'] ?? $csrftoken;

        return [
            'cookie' => 'sessionid=' . $sessionid . '; csrftoken=' . $csrftoken,
            'csrftoken' => $csrftoken,
        ];
    }

    /**
     * Minimal curl wrapper that also captures Set-Cookie response headers - PHP's
     * curl extension doesn't expose these directly, only via a header callback.
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
        $cookies = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $extraheaders);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curlhandle, $headerline) use (&$cookies) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/i', $headerline, $m)) {
                $cookies[trim($m[1])] = trim($m[2]);
            }
            return strlen($headerline);
        });
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \moodle_exception('omeroconnectionfailed', 'local_omeroembed', '', null, $error);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['body' => $body, 'cookies' => $cookies, 'status' => $status];
    }
}
