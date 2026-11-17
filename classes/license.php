<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_scormreport;

defined('MOODLE_INTERNAL') || die();

/**
 * License management for block_scormreport (Accros licensing server, ACCROS3 tokens).
 *
 * The licensing server (clientes.accros.es) issues a token signed with RSA-SHA256 for this
 * exact Moodle site. The token is verified offline on every check with the public key below
 * and refreshed by an hourly scheduled task (the server decides when a refresh is due), so the
 * plugin keeps working until the license end date even if the server cannot be reached.
 *
 * What the license controls (see course_allowed()):
 *  - While the license is valid, every course report works normally.
 *  - When it ends (trial not converted, license not renewed), courses that started on or before
 *    the license end date keep their full report, so running and finished courses are not lost.
 *    Courses that start later (new courses, course copies) show the locked page.
 *  - When the license is suspended or cancelled by Accros, every course is locked.
 *
 * Only signed data counts: the end date comes from the last valid token, so editing stored
 * dates in the database has no effect. Only the public verification key is distributed.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class license {
    /** Token prefix and version. */
    private const TOKEN_PREFIX = 'ACCROS3';
    private const TOKEN_VERSION = 3;

    /** Product identifier included in every signed token. */
    private const PRODUCT = 'block_scormreport';

    /** Plugin configuration component. */
    private const COMPONENT = 'block_scormreport';

    /** Licensing server API. Can be overridden in config.php with $CFG->block_scormreport_apiurl (testing only). */
    public const API_URL = 'https://clientes.accros.es/api/v1/';

    /** Plugin configuration keys. */
    private const CFG_TYPE = 'lic_type';
    private const CFG_START = 'lic_start';
    private const CFG_EXPIRY = 'lic_expiry';
    /** Current token (tamper-proof source of truth). */
    private const CFG_TOKEN = 'lic_token';
    /** Last token that was valid on this site, kept after it ends to know which courses keep working. */
    private const CFG_LASTTOKEN = 'lic_lasttoken';
    /** When the server ended the license early (suspension, revocation): caps the last token's end date. */
    private const CFG_CLEAREDAT = 'lic_clearedat';
    /** Last 4 characters of the license key, for display only. */
    private const CFG_KEYLAST4 = 'lic_keylast4';
    /** Timestamp and result of the last contact with the licensing server. */
    private const CFG_LASTCHECK = 'lic_lastcheck';
    private const CFG_LASTRESULT = 'lic_lastresult';
    /** Set when the server says the installation is gone (released, revoked): stop asking with the last token. */
    private const CFG_NOREFRESH = 'lic_norefresh';
    /** Set to 'suspended' or 'revoked' when Accros stops the license: every course is locked. */
    private const CFG_BLOCKED = 'lic_blocked';
    /** Automated tests only (PHPUnit / Behat): treat the site as licensed. */
    private const CFG_TESTMODE = 'lic_testmode';

    /** Server answers that lock every course, including the ones already started. */
    private const BLOCKING_CODES = ['suspended', 'revoked'];

    /** Server answers after which the site stops asking with the last token. */
    private const STOP_CODES = ['released', 'revoked', 'invalid_token', 'not_found', 'wrong_product'];

    /** Server answers that mean the stored token must be discarded. */
    private const TERMINAL_CODES = ['expired', 'suspended', 'revoked', 'released', 'invalid_token', 'wrong_product', 'not_found'];

    /**
     * RSA public key of the Accros licensing server, used to verify ACCROS3 tokens.
     */
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtjKVvRyVO2+VOwvmOijT
EsinIl2Cb5Xy+D+jidgy0qm6QLWDohmzIt4MpeQGOrMFSQNG7xglFptZg7LVtbrd
PBc4vX7hYTAHSW7/SxVhviDLufh3Up1Ljzl8L1I9ZX+7kanRnBUq7p5sRrnnnlQb
PyPLQtXJaDuizYJ0VuIs/yGFAzVLmMcl5kEyoiRjI7dEplwewSUcqxnM/YwftzQZ
+i6ZOazYiJChpODow49biQpTDbXLltA6S/GTGjTDDaqxJi7M+UP7UnEK95NM76Il
8L8cyJSzYhVkm2XAZaRM1M72ICuYzdxPF+ZHnTj5a46mS3Lt601zXNMRlI6x72qO
MwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * Public key used to verify tokens (overridden in unit tests with a test-only key).
     *
     * @return string
     */
    protected static function public_key(): string {
        return self::PUBLIC_KEY;
    }

    /** @var array|false|null Per-request cache of the verified token payload. */
    private static $tokencache = null;

    /** @var array|false|null Per-request cache of the verified last-token payload. */
    private static $lastcache = null;

    // ------------------------------------------------------------------
    // What the license allows.
    // ------------------------------------------------------------------

    /**
     * Is the license valid right now?
     *
     * @return bool
     */
    public static function is_valid(): bool {
        if (self::test_mode()) {
            return true;
        }
        if (static::blocked_reason() !== "") {
            return false;
        }
        $data = static::token_data();
        return $data && (int)$data['exp'] >= time();
    }

    /**
     * Until when was this site licensed? Courses that start on or before this date keep working
     * after the license ends. 0 = never licensed.
     *
     * Only signed data counts: editing the stored dates in the database has no effect.
     *
     * @return int
     */
    public static function licensed_until(): int {
        $current = static::token_data();
        $last = static::last_token_data();
        $until = max($current ? (int)$current['exp'] : 0, $last ? (int)$last['exp'] : 0);
        $clearedat = (int)get_config(self::COMPONENT, self::CFG_CLEAREDAT);
        if (!$current && $clearedat > 0) {
            $until = min($until, $clearedat);
        }
        return $until;
    }

    /**
     * Is the license stopped by Accros (suspended or cancelled)? Then every course is locked.
     *
     * @return string '' when not blocked, otherwise 'suspended' or 'revoked'.
     */
    public static function blocked_reason(): string {
        $reason = (string)get_config(self::COMPONENT, self::CFG_BLOCKED);
        return in_array($reason, self::BLOCKING_CODES, true) ? $reason : '';
    }

    /**
     * Date that decides whether a course is covered: the course start date, or its
     * creation date when the course has no start date.
     *
     * @param \stdClass $course Course record (startdate, timecreated, id).
     * @return int
     */
    public static function course_start(\stdClass $course): int {
        global $DB;
        $start = (int)($course->startdate ?? 0);
        if ($start <= 0) {
            if (isset($course->timecreated)) {
                $start = (int)$course->timecreated;
            } else if (!empty($course->id)) {
                $start = (int)$DB->get_field('course', 'timecreated', ['id' => $course->id]);
            }
        }
        return $start;
    }

    /**
     * Can the report of this course be used (on screen, export, PDF, reminders)?
     *
     *  - Valid license: yes.
     *  - License suspended or cancelled: no, for every course.
     *  - License ended: only courses that started on or before the signed end date.
     *
     * @param \stdClass $course Course record (startdate, timecreated, id).
     * @return bool
     */
    public static function course_allowed(\stdClass $course): bool {
        if (static::is_valid()) {
            return true;
        }
        if (static::blocked_reason() !== '') {
            return false;
        }
        $until = static::licensed_until();
        $start = static::course_start($course);
        return $until > 0 && $start > 0 && $start <= $until;
    }

    /**
     * Can at least one course still be used (valid license, or ended but not blocked)?
     * Used by the cross-course compliance dashboard before filtering course by course.
     *
     * @return bool
     */
    public static function has_any_access(): bool {
        if (static::is_valid()) {
            return true;
        }
        return static::blocked_reason() === '' && static::licensed_until() > 0;
    }

    /**
     * Whole or partial days left on the current license (0 when there is none).
     *
     * @return int
     */
    public static function days_left(): int {
        $remaining = static::effective_expiry() - time();
        return $remaining > 0 ? (int)ceil($remaining / DAYSECS) : 0;
    }

    /**
     * Test mode, honoured only while PHPUnit or Behat are running.
     *
     * @return bool
     */
    private static function test_mode(): bool {
        $testing = (defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING');
        return $testing && !empty(get_config(self::COMPONENT, self::CFG_TESTMODE));
    }

    // ------------------------------------------------------------------
    // Status for display.
    // ------------------------------------------------------------------

    /**
     * Stored dates (cache for display; never trusted for decisions).
     *
     * @param int $start Start timestamp.
     * @param int $expiry Expiry timestamp.
     * @param string $type 'trial', 'full' or '' when never activated.
     * @return void
     */
    public static function store_dates(int $start, int $expiry, string $type): void {
        set_config(self::CFG_START, $start, self::COMPONENT);
        set_config(self::CFG_EXPIRY, $expiry, self::COMPONENT);
        set_config(self::CFG_TYPE, $type, self::COMPONENT);
    }

    /**
     * End date of the current license (0 when there is none).
     *
     * @return int
     */
    public static function effective_expiry(): int {
        $data = static::token_data();
        return $data ? (int)$data['exp'] : 0;
    }

    /**
     * License type: 'trial', 'full' or '' (never activated).
     *
     * @return string
     */
    public static function get_type(): string {
        $data = static::token_data() ?: static::last_token_data();
        if ($data) {
            return $data['type'] === 'trial' ? 'trial' : 'full';
        }
        return '';
    }

    /**
     * Human-readable status when the license is NOT valid.
     *
     * @return string
     */
    public static function inactive_status_text(): string {
        $blocked = static::blocked_reason();
        if ($blocked !== '') {
            return get_string('license_' . $blocked . '_status', 'block_scormreport');
        }
        $until = static::licensed_until();
        if ($until <= 0) {
            return get_string('license_none', 'block_scormreport');
        }
        $date = userdate($until, get_string('strftimedate', 'langconfig'));
        return static::get_type() === 'trial'
            ? get_string('license_trial_ended_on', 'block_scormreport', $date)
            : get_string('license_expired_on', 'block_scormreport', $date);
    }

    // ------------------------------------------------------------------
    // ACCROS3 tokens.
    // ------------------------------------------------------------------

    /**
     * Normalise a Moodle wwwroot exactly as the licensing server does:
     * https://Campus.Example.com/moodle/ → campus.example.com/moodle
     *
     * @param string $url URL.
     * @return string
     */
    public static function normalise_site(string $url): string {
        $url = trim($url);
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }
        $host = strtolower($parts['host']);
        $port = (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) ? ':' . (int)$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        return $host . $port . $path;
    }

    /**
     * This Moodle site, normalised.
     *
     * @return string
     */
    public static function current_site(): string {
        global $CFG;
        return self::normalise_site($CFG->wwwroot);
    }

    /**
     * Verified payload of the current token, or false.
     *
     * @return array|false
     */
    public static function token_data() {
        if (self::$tokencache === null) {
            $token = (string)get_config(self::COMPONENT, self::CFG_TOKEN);
            self::$tokencache = $token === '' ? false : static::verify_token($token, static::public_key(), self::current_site());
        }
        return self::$tokencache;
    }

    /**
     * Verified payload of the last token this site held, or false.
     *
     * @return array|false
     */
    protected static function last_token_data() {
        if (self::$lastcache === null) {
            $token = (string)get_config(self::COMPONENT, self::CFG_LASTTOKEN);
            self::$lastcache = $token === '' ? false : static::verify_token($token, static::public_key(), self::current_site());
        }
        return self::$lastcache;
    }

    /**
     * Verify an ACCROS3 token with the given public key for the given site.
     * The expiry is NOT checked here: an expired but authentic token is still authentic.
     *
     * @param string $token Token.
     * @param string $publickey PEM public key.
     * @param string $site Normalised site that must match the token.
     * @return array|false
     */
    protected static function verify_token(string $token, string $publickey, string $site) {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_PREFIX || $parts[1] === '' || $parts[2] === '') {
            return false;
        }
        $payload = self::base64url_decode($parts[1]);
        $signature = self::base64url_decode($parts[2]);
        if ($payload === false || $signature === false) {
            return false;
        }
        $key = openssl_pkey_get_public($publickey);
        if ($key === false) {
            return false;
        }
        if (openssl_verify($parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return false;
        }
        foreach (['v', 'product', 'type', 'site', 'exp', 'start'] as $field) {
            if (!array_key_exists($field, $data)) {
                return false;
            }
        }
        if ((int)$data['v'] !== self::TOKEN_VERSION || $data['product'] !== self::PRODUCT) {
            return false;
        }
        if (!in_array($data['type'], ['trial', 'annual'], true)) {
            return false;
        }
        if (!is_string($data['site']) || $site === '' || $data['site'] !== $site) {
            return false;
        }
        if (!is_int($data['exp']) || $data['exp'] <= 0 || !is_int($data['start'])) {
            return false;
        }
        return $data;
    }

    /**
     * Store a token after verifying it.
     *
     * @param string $token Token returned by the server.
     * @return array|false Verified payload.
     */
    private static function store_token(string $token) {
        $data = static::verify_token($token, static::public_key(), self::current_site());
        if ($data === false) {
            return false;
        }
        set_config(self::CFG_TOKEN, $token, self::COMPONENT);
        set_config(self::CFG_LASTTOKEN, $token, self::COMPONENT);
        unset_config(self::CFG_CLEAREDAT, self::COMPONENT);
        unset_config(self::CFG_NOREFRESH, self::COMPONENT);
        unset_config(self::CFG_BLOCKED, self::COMPONENT);
        self::store_dates((int)$data['start'], (int)$data['exp'], $data['type'] === 'trial' ? 'trial' : 'full');
        self::reset_caches();
        return $data;
    }

    /**
     * Discard the current token (expired, suspended, revoked). The last token is kept so that
     * courses started while the site was licensed keep working.
     *
     * @param string $reason Server code.
     * @return void
     */
    private static function clear_token(string $reason): void {
        $data = static::token_data();
        set_config(self::CFG_TOKEN, '', self::COMPONENT);
        if ($data && (int)$data['exp'] > time()) {
            // Ended before its date (suspension, revocation): courses starting from now on are not covered.
            set_config(self::CFG_CLEAREDAT, time(), self::COMPONENT);
        }
        if (in_array($reason, self::BLOCKING_CODES, true)) {
            set_config(self::CFG_BLOCKED, $reason, self::COMPONENT);
        }
        if (in_array($reason, self::STOP_CODES, true)) {
            set_config(self::CFG_NOREFRESH, 1, self::COMPONENT);
        }
        self::record_result($reason);
        self::reset_caches();
    }

    /**
     * Forget cached verifications (after changing the stored tokens).
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$tokencache = null;
        self::$lastcache = null;
    }

    /**
     * Has this site a current token (valid signature for this site)?
     *
     * @return bool
     */
    public static function has_token(): bool {
        return (bool)static::token_data();
    }

    /**
     * Public license reference (L-2026-00042).
     *
     * @return string
     */
    public static function license_reference(): string {
        $data = static::token_data();
        return $data ? (string)($data['lic'] ?? '') : '';
    }

    /**
     * Last 4 characters of the activated key.
     *
     * @return string
     */
    public static function key_last4(): string {
        return (string)get_config(self::COMPONENT, self::CFG_KEYLAST4);
    }

    /**
     * Last contact with the licensing server: ['time' => int, 'code' => string].
     *
     * @return array
     */
    public static function last_check(): array {
        return [
            'time' => (int)get_config(self::COMPONENT, self::CFG_LASTCHECK),
            'code' => (string)get_config(self::COMPONENT, self::CFG_LASTRESULT),
        ];
    }

    /**
     * Remember the result of a call to the server.
     *
     * @param string $code Server code.
     * @return void
     */
    private static function record_result(string $code): void {
        set_config(self::CFG_LASTCHECK, time(), self::COMPONENT);
        set_config(self::CFG_LASTRESULT, $code, self::COMPONENT);
    }

    // ------------------------------------------------------------------
    // Licensing server calls.
    // ------------------------------------------------------------------

    /**
     * Activate a license key (ACC-XXXX-XXXX-XXXX-XXXX) on this site.
     *
     * @param string $key License key.
     * @return array ['ok' => bool, 'code' => string, 'expires' => int]
     */
    public static function activate_key(string $key): array {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
        if (strpos($key, 'ACC') === 0) {
            $key = substr($key, 3);
        }
        if (strlen($key) !== 16) {
            return ['ok' => false, 'code' => 'not_found', 'expires' => 0];
        }
        $key = 'ACC-' . implode('-', str_split($key, 4));
        $r = static::api_request('activate', ['key' => $key] + self::site_params());
        if (!empty($r['ok']) && !empty($r['token'])) {
            $data = self::store_token((string)$r['token']);
            if ($data === false) {
                self::record_result('invalid_token');
                return ['ok' => false, 'code' => 'invalid_token', 'expires' => 0];
            }
            set_config(self::CFG_KEYLAST4, substr($key, -4), self::COMPONENT);
            self::record_result((string)$r['code']);
            return ['ok' => true, 'code' => (string)$r['code'], 'expires' => (int)$data['exp']];
        }
        $code = (string)($r['code'] ?? 'server_error');
        self::record_result($code);
        return ['ok' => false, 'code' => $code, 'expires' => 0];
    }

    /**
     * Ask the licensing server for a free trial. The key is sent to the email address.
     *
     * @param string $email Administrator email.
     * @return array ['ok' => bool, 'code' => string]
     */
    public static function request_trial(string $email): array {
        $r = static::api_request('trial', ['email' => trim($email)] + self::site_params());
        $code = (string)($r['code'] ?? 'server_error');
        self::record_result($code);
        return ['ok' => !empty($r['ok']), 'code' => $code];
    }

    /**
     * Refresh the license with the server (renewals, suspensions, revocations).
     *
     * With a current token: network errors keep it, so the plugin keeps working until its end date.
     * Without one (the license ended): the last token is used to ask whether the license has been
     * renewed in the meantime, so a renewal in the Accros panel reaches this Moodle by itself.
     *
     * @return array ['ok' => bool, 'code' => string]
     */
    public static function refresh(): array {
        $token = (string)get_config(self::COMPONENT, self::CFG_TOKEN);
        $usinglast = false;
        if ($token === '' && static::last_token_data() && !get_config(self::COMPONENT, self::CFG_NOREFRESH)) {
            $token = (string)get_config(self::COMPONENT, self::CFG_LASTTOKEN);
            $usinglast = true;
        }
        if ($token === '') {
            return ['ok' => false, 'code' => 'no_token'];
        }
        $r = static::api_request('refresh', ['token' => $token] + self::site_params());
        $code = (string)($r['code'] ?? 'server_error');
        if (!empty($r['ok']) && !empty($r['token'])) {
            if (self::store_token((string)$r['token']) === false) {
                self::record_result('invalid_token');
                return ['ok' => false, 'code' => 'invalid_token'];
            }
            self::record_result($code);
            return ['ok' => true, 'code' => $code];
        }
        if ($usinglast) {
            // Still not renewed (expired, suspended) or gone for good (released, revoked...).
            if (in_array($code, self::STOP_CODES, true)) {
                set_config(self::CFG_NOREFRESH, 1, self::COMPONENT);
            }
            if (in_array($code, self::BLOCKING_CODES, true)) {
                set_config(self::CFG_BLOCKED, $code, self::COMPONENT);
            }
            self::record_result($code);
        } else if (in_array($code, self::TERMINAL_CODES, true)) {
            self::clear_token($code);
        } else {
            self::record_result($code);
        }
        return ['ok' => false, 'code' => $code];
    }

    /**
     * Can the server be asked for the license state (current token, or last token not released)?
     *
     * @return bool
     */
    public static function refresh_possible(): bool {
        return static::has_token() || (static::last_token_data() && !get_config(self::COMPONENT, self::CFG_NOREFRESH));
    }

    /**
     * Is a refresh due? With a current token: after the "refresh" date set by the server in the
     * token (one day). Without one: once a day, with the last token, to pick up a renewal.
     *
     * @return bool
     */
    public static function refresh_due(): bool {
        $data = static::token_data();
        if ($data) {
            return time() >= (int)($data['refresh'] ?? 0);
        }
        if (!static::last_token_data() || get_config(self::COMPONENT, self::CFG_NOREFRESH)) {
            return false;
        }
        return time() - (int)get_config(self::COMPONENT, self::CFG_LASTCHECK) >= 20 * HOURSECS;
    }

    /**
     * Release this site's installation so the license can be used on another Moodle.
     * The last token is kept: courses started while licensed keep working here.
     *
     * @return array ['ok' => bool, 'code' => string]
     */
    public static function deactivate(): array {
        $token = (string)get_config(self::COMPONENT, self::CFG_TOKEN);
        $r = $token === '' ? ['ok' => true, 'code' => 'released'] : static::api_request('deactivate', ['token' => $token]);
        $code = (string)($r['code'] ?? 'server_error');
        if (!empty($r['ok']) || in_array($code, self::TERMINAL_CODES, true)) {
            self::clear_token('released');
            set_config(self::CFG_NOREFRESH, 1, self::COMPONENT);
            set_config(self::CFG_KEYLAST4, '', self::COMPONENT);
            return ['ok' => true, 'code' => 'released'];
        }
        self::record_result($code);
        return ['ok' => false, 'code' => $code];
    }

    /**
     * Data about this site sent with every call.
     *
     * @return array
     */
    private static function site_params(): array {
        global $CFG;
        return [
            'product' => self::PRODUCT,
            'site' => $CFG->wwwroot,
            'moodle_version' => (string)($CFG->release ?? ''),
            'plugin_version' => (string)get_config(self::COMPONENT, 'version'),
        ];
    }

    /**
     * POST to the licensing server. Never throws: returns ['ok' => false, 'code' => 'server_error'] on failure.
     *
     * @param string $endpoint activate|refresh|deactivate|trial
     * @param array $params Parameters.
     * @return array
     */
    protected static function api_request(string $endpoint, array $params): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $base = !empty($CFG->block_scormreport_apiurl) ? $CFG->block_scormreport_apiurl : self::API_URL;
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $response = $curl->post(rtrim($base, '/') . '/' . $endpoint, json_encode($params), [
            'CURLOPT_TIMEOUT' => 20,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        if ($curl->get_errno() || !is_string($response) || $response === '') {
            return ['ok' => false, 'code' => 'server_error'];
        }
        $data = json_decode($response, true);
        return is_array($data) && isset($data['code']) ? $data : ['ok' => false, 'code' => 'server_error'];
    }

    /**
     * Decode unpadded base64url data.
     *
     * @param string $data Encoded data.
     * @return string|false
     */
    private static function base64url_decode(string $data) {
        if ($data === '' || preg_match('/[^A-Za-z0-9_-]/', $data)) {
            return false;
        }
        $remainder = strlen($data) % 4;
        if ($remainder === 1) {
            return false;
        }
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
