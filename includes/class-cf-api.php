<?php
/**
 * Cloudflare API client
 *
 * - REST: zones, Web Analytics sites
 * - GraphQL Analytics: Cloudflare Web Analytics (RUM) datasets, read live
 *
 * Authentication: Cloudflare API token (Bearer). User tokens (cfut_), account-owned
 * tokens (cfat_) and older unprefixed tokens are all accepted.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF_API {
    private $api_url = 'https://api.cloudflare.com/client/v4';
    private $graphql_url = 'https://api.cloudflare.com/client/v4/graphql';

    /**
     * Optional credentials override (used on the setup screen before anything is saved).
     * Shape: array('type' => 'token', 'token' => '...')
     */
    private $auth = null;

    public function __construct($credentials = null) {
        $this->auth = $credentials;
    }

    /* ------------------------------------------------------------------
     * Credentials
     * ------------------------------------------------------------------ */

    public static function resolve_credentials($settings = null) {
        if ($settings === null) {
            $settings = get_option('cf_analytics_settings', array());
        }

        if (!empty($settings['api_token'])) {
            $token = CF_Encryption::decrypt($settings['api_token']);
            if ($token) {
                return array('type' => 'token', 'token' => $token);
            }
        }

        return null;
    }

    public static function is_configured() {
        $settings = get_option('cf_analytics_settings', array());
        return !empty($settings['api_token']) && !empty($settings['zone_id']);
    }

    /**
     * Trim whitespace/quotes and drop an accidental "Bearer " prefix. No length or prefix check.
     */
    public static function clean_token($token) {
        $token = trim((string) $token, " \t\n\r\0\x0B\"'");
        $token = preg_replace('/^Bearer\s+/i', '', $token);
        return $token;
    }

    public static function is_tag($value) {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/i', $value) === 1;
    }

    private function get_headers() {
        $auth = $this->auth ? $this->auth : self::resolve_credentials();

        if (empty($auth['token'])) {
            return array();
        }

        return array(
            'Authorization' => 'Bearer ' . $auth['token'],
            'Content-Type'  => 'application/json'
        );
    }

    /* ------------------------------------------------------------------
     * Errors
     * ------------------------------------------------------------------ */

    public static function format_errors($errors, $http_code = 0) {
        $parts = array();
        if (is_array($errors)) {
            foreach ($errors as $err) {
                if (is_array($err)) {
                    $msg = isset($err['message']) ? $err['message'] : 'Unknown error';
                    if (isset($err['code'])) {
                        $msg .= ' (code ' . $err['code'] . ')';
                    }
                    $parts[] = $msg;
                } elseif (is_string($err)) {
                    $parts[] = $err;
                }
            }
        }

        $message = $parts ? implode('; ', $parts) : 'Request failed';

        if ($http_code === 401 || $http_code === 403) {
            $message .= ' [HTTP ' . $http_code . '] The token may be expired or missing a permission.';
        } elseif ($http_code === 429) {
            $message .= ' [HTTP 429] Rate limited by Cloudflare. Wait a few minutes and try again.';
        } elseif ($http_code) {
            $message .= ' [HTTP ' . $http_code . ']';
        }

        return $message;
    }

    private static function record_error($message) {
        set_transient('cf_analytics_last_error', array(
            'time'    => time(),
            'message' => $message
        ), DAY_IN_SECONDS);
    }

    /* ------------------------------------------------------------------
     * REST
     * ------------------------------------------------------------------ */

    private function rest_get($path) {
        $headers = $this->get_headers();

        if (empty($headers)) {
            return array('success' => false, 'message' => 'No Cloudflare API token configured.');
        }

        $response = wp_remote_get($this->api_url . $path, array(
            'headers' => $headers,
            'timeout' => 20
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_array($body) && !empty($body['success'])) {
            return array(
                'success' => true,
                'data'    => isset($body['result']) ? $body['result'] : array(),
                'info'    => isset($body['result_info']) ? $body['result_info'] : array()
            );
        }

        return array(
            'success' => false,
            'message' => self::format_errors(is_array($body) && isset($body['errors']) ? $body['errors'] : array(), $code)
        );
    }

    /**
     * All zones the token can see (up to 500)
     */
    public function get_zones() {
        $zones = array();
        $page = 1;
        $total_pages = 1;

        do {
            $result = $this->rest_get('/zones?per_page=50&page=' . $page);

            if (!$result['success']) {
                if ($page === 1) {
                    return array('success' => false, 'message' => 'Failed to fetch zones: ' . $result['message']);
                }
                break;
            }

            $zones = array_merge($zones, is_array($result['data']) ? $result['data'] : array());
            $total_pages = isset($result['info']['total_pages']) ? (int) $result['info']['total_pages'] : 1;
            $page++;
        } while ($page <= $total_pages && $page <= 10);

        return array('success' => true, 'data' => $zones);
    }

    /**
     * Web Analytics sites of an account (needs Account > Account Settings > Read)
     */
    public function list_rum_sites($account_id) {
        if (!self::is_tag($account_id)) {
            return array('success' => false, 'message' => 'Invalid account ID.');
        }

        $sites = array();
        $page = 1;
        $total_pages = 1;

        do {
            $result = $this->rest_get('/accounts/' . $account_id . '/rum/site_info/list?per_page=50&page=' . $page);

            if (!$result['success']) {
                if ($page === 1) {
                    return $result;
                }
                break;
            }

            $sites = array_merge($sites, is_array($result['data']) ? $result['data'] : array());
            $total_pages = isset($result['info']['total_pages']) ? (int) $result['info']['total_pages'] : 1;
            $page++;
        } while ($page <= $total_pages && $page <= 5);

        return array('success' => true, 'data' => $sites);
    }

    /* ------------------------------------------------------------------
     * Connection diagnosis
     * ------------------------------------------------------------------ */

    private static function check($id, $ok, $label, $detail) {
        return array('id' => $id, 'ok' => (bool) $ok, 'label' => $label, 'detail' => $detail);
    }

    /**
     * Walk through everything the plugin needs and report each step:
     * token, zone (-> account), Web Analytics site (-> site tag), readable Web Analytics data.
     *
     * Returns: ok, checks[], zone_name, account_id, site_tag, site_host
     */
    public function diagnose($zone_id, $manual_site_tag = '', $probe = true) {
        $out = array('ok' => false, 'checks' => array(), 'zone_name' => '', 'account_id' => '', 'site_tag' => '', 'site_host' => '');

        $zones = $this->rest_get('/zones?per_page=1');
        if (!$zones['success']) {
            $out['checks'][] = self::check('token', false, 'API token', $zones['message']);
            return $out;
        }
        $out['checks'][] = self::check('token', true, 'API token', 'Valid, and it can list zones.');

        $zone = $this->rest_get('/zones/' . rawurlencode($zone_id));
        if (!$zone['success']) {
            $out['checks'][] = self::check('zone', false, 'Zone', 'The token cannot access this zone: ' . $zone['message']);
            return $out;
        }

        $out['zone_name'] = isset($zone['data']['name']) ? $zone['data']['name'] : '';
        $out['account_id'] = isset($zone['data']['account']['id']) ? $zone['data']['account']['id'] : '';
        $out['checks'][] = self::check('zone', true, 'Zone', $out['zone_name'] . ' (account ' . (isset($zone['data']['account']['name']) ? $zone['data']['account']['name'] : $out['account_id']) . ')');

        if (!self::is_tag($out['account_id'])) {
            $out['checks'][] = self::check('site', false, 'Web Analytics site', 'Could not determine the account for this zone.');
            return $out;
        }

        // Web Analytics site
        $home = preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));

        if (self::is_tag($manual_site_tag)) {
            $out['site_tag'] = strtolower($manual_site_tag);
            $out['checks'][] = self::check('site', true, 'Web Analytics site', 'Using the site tag you entered.');
        } else {
            $sites = $this->list_rum_sites($out['account_id']);

            if (!$sites['success']) {
                $out['checks'][] = self::check('site', false, 'Web Analytics site', $sites['message'] . ' Add Account > Account Settings > Read to the token, or enter the site tag manually under Advanced.');
                return $out;
            }

            $best = null;
            $best_score = 0;

            foreach ($sites['data'] as $site) {
                if (empty($site['site_tag'])) {
                    continue;
                }

                $score = 0;
                if (!empty($site['ruleset']['zone_tag']) && $site['ruleset']['zone_tag'] === $zone_id) {
                    $score = 3;
                }

                $hosts = array();
                if (!empty($site['ruleset']['zone_name'])) {
                    $hosts[] = $site['ruleset']['zone_name'];
                }
                if (!empty($site['rules']) && is_array($site['rules'])) {
                    foreach ($site['rules'] as $rule) {
                        if (!empty($rule['host'])) {
                            $hosts[] = $rule['host'];
                        }
                    }
                }

                foreach ($hosts as $h) {
                    $h = preg_replace('/^www\./', '', strtolower($h));
                    if ($h === $home) {
                        $score = max($score, 2);
                    } elseif ($out['zone_name'] !== '' && $h === strtolower($out['zone_name'])) {
                        $score = max($score, 1);
                    }
                }

                if ($score > $best_score) {
                    $best_score = $score;
                    $best = $site;
                    $out['site_host'] = $hosts ? $hosts[0] : '';
                }
            }

            if (!$best) {
                $out['checks'][] = self::check('site', false, 'Web Analytics site', 'No Web Analytics site was found for ' . $out['zone_name'] . '. Turn on Web Analytics for this site in Cloudflare (your site > Speed > Real User Monitoring), then test again.');
                return $out;
            }

            $out['site_tag'] = $best['site_tag'];
            $out['checks'][] = self::check('site', true, 'Web Analytics site', 'Found ' . ($out['site_host'] !== '' ? $out['site_host'] : 'a matching site') . '.');
        }

        // Readable data
        if ($probe) {
            $res = $this->rum_query($out['account_id'], 'rumPageloadEventsAdaptiveGroups', $out['site_tag'], gmdate('Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS), gmdate('Y-m-d\TH:i:s\Z'), 'count', 1);

            if ($res['success']) {
                $out['checks'][] = self::check('data', true, 'Web Analytics data', 'Readable.');
            } else {
                $out['checks'][] = self::check('data', false, 'Web Analytics data', $res['message'] . ' The token needs Account > Account Analytics > Read.');
                return $out;
            }
        }

        $out['ok'] = true;
        return $out;
    }

    /* ------------------------------------------------------------------
     * GraphQL
     * ------------------------------------------------------------------ */

    /**
     * Post a GraphQL document. Returns success + data (the "data" member).
     */
    public function graphql($query, $record_errors = true) {
        $headers = $this->get_headers();

        if (empty($headers)) {
            return array('success' => false, 'message' => 'No Cloudflare API token configured.');
        }

        $response = wp_remote_post($this->graphql_url, array(
            'headers' => $headers,
            'body'    => wp_json_encode(array('query' => $query)),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            if ($record_errors) {
                self::record_error($message);
            }
            return array('success' => false, 'message' => $message);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // GraphQL can return HTTP 200 with errors[], so check errors first.
        if (is_array($body) && !empty($body['errors'])) {
            $message = self::format_errors($body['errors'], $code === 200 ? 0 : $code);
            if ($record_errors) {
                self::record_error($message);
            }
            return array('success' => false, 'message' => $message);
        }

        if ($code >= 400 || !is_array($body) || !isset($body['data'])) {
            $message = 'Cloudflare returned HTTP ' . $code . ' for the analytics query.';
            if ($record_errors) {
                self::record_error($message);
            }
            return array('success' => false, 'message' => $message);
        }

        return array('success' => true, 'data' => $body['data']);
    }

    /**
     * Query one Web Analytics (RUM) dataset for a site and time window.
     *
     * Everything interpolated here is validated: tags are 32-char hex, dates are generated by
     * the plugin, the dataset is whitelisted. $selection and $order come from plugin code only.
     */
    public function rum_query($account_tag, $dataset, $site_tag, $start_iso, $end_iso, $selection, $limit = 1, $order = '', $extra = array()) {
        $datasets = array('rumPageloadEventsAdaptiveGroups', 'rumWebVitalsEventsAdaptiveGroups', 'rumPerformanceEventsAdaptiveGroups');

        if (!in_array($dataset, $datasets, true) || !self::is_tag($account_tag) || !self::is_tag($site_tag)) {
            return array('success' => false, 'message' => 'Invalid Web Analytics query.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $start_iso) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $end_iso)) {
            return array('success' => false, 'message' => 'Invalid time range.');
        }

        // Optional exact-match filter on one page path. The value is JSON-encoded, which is also
        // a valid GraphQL string literal, so it cannot break out of the string.
        $extra_filter = '';
        if (isset($extra['requestPath']) && is_string($extra['requestPath'])) {
            $encoded = wp_json_encode($extra['requestPath'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return array('success' => false, 'message' => 'Invalid page path.');
            }
            $extra_filter = ', requestPath: ' . $encoded;
        }

        $query = 'query { viewer { accounts(filter: {accountTag: "' . $account_tag . '"}) { '
            . $dataset . '(filter: {siteTag: "' . $site_tag . '"' . $extra_filter . ', datetime_geq: "' . $start_iso . '", datetime_lt: "' . $end_iso . '"}, limit: ' . (int) $limit
            . ($order !== '' ? ', orderBy: [' . $order . ']' : '')
            . ') { ' . $selection . ' } } } }';

        $res = $this->graphql($query);

        if (!$res['success']) {
            return $res;
        }

        if (isset($res['data']['viewer']['accounts'][0][$dataset])) {
            return array('success' => true, 'data' => $res['data']['viewer']['accounts'][0][$dataset]);
        }

        $message = 'No data returned. The token may not have access to this account\'s analytics, or the site tag is wrong.';
        self::record_error($message);
        return array('success' => false, 'message' => $message);
    }

    /**
     * Field names of a GraphQL type (schema introspection, cached for a day).
     * Used to adapt to fields Cloudflare adds or renames. Empty array when unavailable.
     */
    public function type_fields($type_name) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $type_name)) {
            return array();
        }

        $key = 'cfa_schema_' . md5($type_name);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->graphql('query { __type(name: "' . $type_name . '") { fields { name } } }', false);
        $names = array();

        if ($res['success'] && !empty($res['data']['__type']['fields'])) {
            foreach ($res['data']['__type']['fields'] as $f) {
                $names[] = $f['name'];
            }
        }

        // Cache failures briefly, successes for a day.
        set_transient($key, $names, $names ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);

        return $names;
    }

    /* ------------------------------------------------------------------
     * Formatting
     * ------------------------------------------------------------------ */

    public static function format_number($number) {
        if ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        } elseif ($number >= 1000) {
            return round($number / 1000, 1) . 'K';
        }
        return number_format_i18n($number);
    }

    /**
     * Country name from 2-letter ISO code
     */
    public static function get_country_name($code) {
        $countries = array(
            'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
            'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands',
            'BE' => 'Belgium', 'CH' => 'Switzerland', 'AT' => 'Austria', 'SE' => 'Sweden', 'NO' => 'Norway',
            'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'CZ' => 'Czech Republic', 'HU' => 'Hungary',
            'RO' => 'Romania', 'BG' => 'Bulgaria', 'GR' => 'Greece', 'PT' => 'Portugal', 'IE' => 'Ireland',
            'JP' => 'Japan', 'CN' => 'China', 'IN' => 'India', 'KR' => 'South Korea', 'TH' => 'Thailand',
            'VN' => 'Vietnam', 'ID' => 'Indonesia', 'MY' => 'Malaysia', 'PH' => 'Philippines', 'SG' => 'Singapore',
            'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia',
            'PE' => 'Peru', 'VE' => 'Venezuela', 'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Turkey',
            'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'IL' => 'Israel', 'EG' => 'Egypt',
            'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'NZ' => 'New Zealand',
            'UZ' => 'Uzbekistan', 'XX' => 'Unknown'
        );
        
        $countries['T1'] = 'Tor network';

        if (isset($countries[$code])) {
            return $countries[$code];
        }

        if (class_exists('Locale') && preg_match('/^[A-Za-z]{2}$/', $code)) {
            $name = Locale::getDisplayRegion('-' . strtoupper($code), 'en');
            if ($name && $name !== '-' . strtoupper($code) && strtoupper($name) !== strtoupper($code)) {
                return $name;
            }
        }

        return $code;
    }
}
