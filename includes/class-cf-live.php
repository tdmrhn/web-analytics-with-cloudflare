<?php
/**
 * Live Web Analytics data provider
 *
 * Reads Cloudflare Web Analytics (RUM) straight from Cloudflare and keeps results in transients
 * with stale-while-revalidate: cached data is always served first, and old data is refreshed in
 * a separate request after the page has loaded. The refresh interval is a setting (default
 * 10 minutes, or manual only). Nothing is written to custom database tables.
 *
 * Page views = count, visits = sum.visits (the same definitions Cloudflare's dashboard uses).
 * Days start at midnight in the WordPress timezone.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF_Live {
    const TTL_LIVE = 600;   // how long data counts as fresh
    const TTL_PAST = 3600;  // finished ranges (yesterday) stay fresh longer
    const TTL_FAIL = 30;    // failures are remembered briefly
    const PATH_LIMIT = 1000;   // size of the shared page-path list (pages, overview, admin bar)
    const STALE_KEEP = 172800; // old data is kept for 2 days so it can be shown while refreshing

    /** Set to true to serve stale data without scheduling a background refresh (the widget refreshes itself). */
    public static $no_queue = false;

    /** While true, every data call reads the cache only (a miss is recorded in $missed). */
    public static $cache_only_mode = false;
    public static $missed = false;

    private static $queue = array();

    const DS_PAGELOAD = 'rumPageloadEventsAdaptiveGroups';
    const DS_VITALS   = 'rumWebVitalsEventsAdaptiveGroups';
    const DS_PERF     = 'rumPerformanceEventsAdaptiveGroups';

    /* ------------------------------------------------------------------
     * Ranges (WordPress timezone)
     * ------------------------------------------------------------------ */

    public static function ranges() {
        return array(
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            '7days'     => '7 days',
            '30days'    => '30 days'
        );
    }

    /**
     * Refresh intervals offered in Settings (seconds => label). 0 = manual only.
     */
    public static function intervals() {
        return array(
            300  => 'Every 5 minutes',
            600  => 'Every 10 minutes',
            1800 => 'Every 30 minutes',
            3600 => 'Every hour',
            0    => 'Manual only'
        );
    }

    /**
     * How old data may get before the next visit refreshes it in the background (seconds).
     * Nothing runs on a timer. 0 means manual only.
     */
    public static function refresh_interval() {
        $value = (int) get_option('cf_analytics_refresh_interval', self::TTL_LIVE);
        return array_key_exists($value, self::intervals()) ? $value : self::TTL_LIVE;
    }

    /**
     * Seconds a result counts as fresh. Manual mode never expires on its own (a new day still
     * starts with a fresh lookup, because ranges are relative to today).
     */
    private static function ttl($live) {
        $interval = self::refresh_interval();

        if ($interval === 0) {
            return self::STALE_KEEP;
        }

        return $live ? $interval : max(self::TTL_PAST, $interval);
    }

    /**
     * Wording for the footer: "about every 10 minutes" / "when you click Refresh"
     */
    public static function interval_phrase() {
        $interval = self::refresh_interval();

        if ($interval === 0) {
            return 'when you click Refresh';
        }

        return 'about every ' . ($interval >= 3600 ? 'hour' : ($interval / 60) . ' minutes');
    }

    public static function clear_queue() {
        self::$queue = array();
    }

    /**
     * Refreshes queued so far (used by AJAX handlers that can't print the footer script)
     */
    public static function queued_jobs() {
        return array_values(self::$queue);
    }

    public static function sanitize_range($key, $default = '7days') {
        $ranges = self::ranges();
        return isset($ranges[$key]) ? $key : $default;
    }

    public static function zone_id() {
        $settings = get_option('cf_analytics_settings', array());
        return isset($settings['zone_id']) ? $settings['zone_id'] : '';
    }

    /**
     * Dashboard URL (Dashboard > Web Analytics). Works on the public site too.
     * Sections: overview, pages, referrers, countries, technology, vitals, settings.
     */
    public static function dashboard_url($args = array()) {
        return add_query_arg($args, admin_url('index.php?page=cf-analytics'));
    }

    private static function iso($ts) {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * "GMT+3" style label for the WordPress timezone
     */
    public static function tz_label() {
        $offset = wp_timezone()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $sign = $offset < 0 ? '-' : '+';
        $hours = (int) floor(abs($offset) / 3600);
        $minutes = (int) ((abs($offset) % 3600) / 60);

        return 'GMT' . $sign . $hours . ($minutes ? ':' . sprintf('%02d', $minutes) : '');
    }

    public static function range($key) {
        $ranges = self::ranges();
        $key = isset($ranges[$key]) ? $key : '7days';

        $tz = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);
        $now = time();

        if ($key === 'today') {
            $r = array('gran' => 'hour', 'live' => true,
                'start' => $today->getTimestamp(), 'end' => $now,
                'prev_start' => $today->modify('-1 day')->getTimestamp(), 'prev_end' => $now - DAY_IN_SECONDS);
        } elseif ($key === 'yesterday') {
            $r = array('gran' => 'hour', 'live' => false,
                'start' => $today->modify('-1 day')->getTimestamp(), 'end' => $today->getTimestamp(),
                'prev_start' => $today->modify('-2 days')->getTimestamp(), 'prev_end' => $today->modify('-1 day')->getTimestamp());
        } else {
            $days = ($key === '30days') ? 30 : 7;
            $start = $today->modify('-' . ($days - 1) . ' days')->getTimestamp();
            $r = array('gran' => 'day', 'live' => true,
                'start' => $start, 'end' => $now,
                'prev_start' => $today->modify('-' . (2 * $days - 1) . ' days')->getTimestamp(), 'prev_end' => $start);
        }

        $r['key'] = $key;
        $r['label'] = $ranges[$key];
        return $r;
    }

    /* ------------------------------------------------------------------
     * Cache
     * ------------------------------------------------------------------ */

    /**
     * Stale-while-revalidate cache.
     *
     * - Fresh data (younger than $ttl) is returned as is.
     * - Stale data is returned immediately, flagged 'stale', and a refresh job is queued. The
     *   refresh runs in a separate request after the page has loaded, so pages never wait on
     *   Cloudflare when there is something to show.
     * - With no data at all, the callback runs now (or, with $cache_only, a 'miss' is returned
     *   and the refresh is queued).
     * - A failed refresh never replaces older good data.
     */
    private static function remember($name, $ttl, $cache_only, $callback, $job = null, $force = false) {
        $settings = get_option('cf_analytics_settings', array());
        $gen = (int) get_option('cf_analytics_cache_gen', 1);
        $site = isset($settings['site_tag']) ? $settings['site_tag'] : '';
        $key = 'cfa_v2_' . md5($gen . '|' . self::zone_id() . '|' . $site . '|' . wp_date('Ymd') . '|' . $name);

        if (self::$cache_only_mode) {
            $cache_only = true;
        }

        $entry = get_transient($key);
        $has_entry = is_array($entry) && isset($entry['v'], $entry['fresh']) && is_array($entry['v']);
        $usable = $has_entry && !empty($entry['v']['success']);

        if ($has_entry && !$force) {
            if ($entry['fresh'] > time()) {
                return $entry['v'];
            }

            if ($usable) {
                if ($job) {
                    self::queue_refresh($job);
                }
                $stale = $entry['v'];
                $stale['stale'] = true;
                return $stale;
            }
        }

        if ($cache_only && !$force) {
            self::$missed = true;
            if ($job) {
                self::queue_refresh($job);
            }
            return array('success' => false, 'miss' => true, 'message' => 'Not loaded yet. Open the dashboard to fetch live stats.');
        }

        $value = call_user_func($callback);

        if (is_array($value)) {
            $value['fetched_at'] = time();
        }

        if (is_array($value) && !empty($value['success'])) {
            set_transient($key, array('v' => $value, 'fresh' => time() + $ttl), self::STALE_KEEP);
        } elseif (!$usable) {
            set_transient($key, array('v' => $value, 'fresh' => time() + self::TTL_FAIL), self::TTL_FAIL);
        }

        return $value;
    }

    /* ------------------------------------------------------------------
     * Background refresh (runs after the page has loaded)
     * ------------------------------------------------------------------ */

    private static function queue_refresh($job) {
        if (self::$no_queue) {
            return;
        }
        self::$queue[md5(wp_json_encode($job))] = $job;
    }

    /**
     * Footer script: once the page has finished loading, ask the server to refresh the data it
     * had to serve from cache. The refresh happens in its own request, so it can't slow the page.
     */
    public static function print_refresh_script() {
        if (!self::$queue || !current_user_can('manage_options')) {
            return;
        }

        $jobs = wp_json_encode(array_values(self::$queue));
        ?>
        <script>
        (function () {
            if (!window.fetch || !window.URLSearchParams) { return; }

            var body = new URLSearchParams();
            body.append('action', 'cf_analytics_refresh');
            body.append('nonce', <?php echo wp_json_encode(wp_create_nonce('cf_analytics_refresh')); ?>);
            body.append('jobs', <?php echo wp_json_encode($jobs); ?>);

            function run() {
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
            }

            function later() { setTimeout(run, 800); }

            if (document.readyState === 'complete') { later(); } else { window.addEventListener('load', later); }
        })();
        </script>
        <?php
    }

    /**
     * Run one queued refresh (validated; called from the AJAX handler)
     */
    public static function run_job($method, $args) {
        $range = self::sanitize_range(isset($args[0]) ? (string) $args[0] : '', '7days');

        // One refresh per dataset at a time.
        $lock = 'cfa_lock_' . md5($method . '|' . wp_json_encode($args));
        if (get_transient($lock)) {
            return;
        }
        set_transient($lock, 1, 60);

        switch ($method) {
            case 'series':
                self::series($range, false, true);
                break;
            case 'breakdown':
                self::breakdown($range, isset($args[1]) ? (string) $args[1] : '', max(1, min(self::PATH_LIMIT, isset($args[2]) ? (int) $args[2] : 50)), false, true);
                break;
            case 'vitals':
                self::vitals($range, false, true);
                break;
            case 'vitals_by_page':
                self::vitals_by_page($range, false, true);
                break;
            case 'page_views':
                self::page_views($range, isset($args[1]) ? (string) $args[1] : '/', false, true);
                break;
        }

        delete_transient($lock);
    }

    /**
     * Normalise a page path for lookups: starts with "/", no control characters, bounded length
     */
    public static function clean_path($path) {
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $path);

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return substr($path, 0, 512);
    }

    /**
     * Forget everything cached. Cache keys include a generation number, so bumping it makes every
     * existing entry unreachable at once; the old entries expire on their own within two days.
     */
    public static function refresh_cache() {
        update_option('cf_analytics_cache_gen', (int) get_option('cf_analytics_cache_gen', 1) + 1, false);
        delete_transient('cf_analytics_last_error');
        delete_transient('cfa_valid_paths');
        delete_transient('cfa_ctx_fail');
    }

    /* ------------------------------------------------------------------
     * Account + site tag (discovered once, then saved)
     * ------------------------------------------------------------------ */

    private static function context() {
        $settings = get_option('cf_analytics_settings', array());

        if (!empty($settings['account_id']) && !empty($settings['site_tag'])) {
            return array('success' => true, 'account' => $settings['account_id'], 'site' => $settings['site_tag']);
        }

        $failed = get_transient('cfa_ctx_fail');
        if (is_array($failed)) {
            return $failed;
        }

        $api = new CF_API();
        $manual = (!empty($settings['site_manual']) && !empty($settings['site_tag'])) ? $settings['site_tag'] : '';
        $d = $api->diagnose(isset($settings['zone_id']) ? $settings['zone_id'] : '', $manual, false);

        if (!empty($d['site_tag']) && !empty($d['account_id'])) {
            $settings['account_id'] = $d['account_id'];
            $settings['site_tag'] = $d['site_tag'];
            if ($d['site_host'] !== '') {
                $settings['site_host'] = $d['site_host'];
            }
            update_option('cf_analytics_settings', $settings);
            return array('success' => true, 'account' => $d['account_id'], 'site' => $d['site_tag']);
        }

        $message = 'Could not find the Web Analytics site for this zone.';
        foreach ($d['checks'] as $check) {
            if (!$check['ok']) {
                $message = $check['label'] . ': ' . $check['detail'];
                break;
            }
        }

        $failed = array('success' => false, 'message' => $message);
        set_transient('cfa_ctx_fail', $failed, MINUTE_IN_SECONDS);
        return $failed;
    }

    private static function rum($ctx, $dataset, $start, $end, $selection, $limit = 1, $order = '', $extra = array()) {
        $api = new CF_API();
        return $api->rum_query($ctx['account'], $dataset, $ctx['site'], self::iso($start), self::iso($end), $selection, $limit, $order, $extra);
    }

    private static function first_row($res) {
        return (!empty($res['data']) && isset($res['data'][0])) ? $res['data'][0] : array();
    }

    /* ------------------------------------------------------------------
     * Overview: totals, page load time, chart
     * ------------------------------------------------------------------ */

    /**
     * Returns: success, points[] (ts, label, views, visits), totals (views, visits, load_ms),
     * prev (same shape or null), gran, fetched_at
     */
    public static function series($range_key, $cache_only = false, $force = false) {
        $r = self::range($range_key);

        return self::remember('series_' . $r['key'], self::ttl($r['live']), $cache_only, function () use ($r) {
            $ctx = self::context();
            if (!$ctx['success']) {
                return $ctx;
            }

            $chart = self::fetch_points($ctx, $r);
            if (!$chart['success']) {
                return $chart;
            }

            $totals = self::fetch_totals($ctx, $r['start'], $r['end']);
            if (!$totals['success']) {
                // Fall back to summing the chart points.
                $totals = array('success' => true, 'views' => array_sum(wp_list_pluck($chart['points'], 'views')), 'visits' => array_sum(wp_list_pluck($chart['points'], 'visits')));
            }
            $totals['load_ms'] = self::fetch_page_load($ctx, $r['start'], $r['end']);

            // The comparison period is best-effort.
            $prev = self::fetch_totals($ctx, $r['prev_start'], $r['prev_end']);
            if ($prev['success'] && $prev['views'] > 0) {
                $prev['load_ms'] = self::fetch_page_load($ctx, $r['prev_start'], $r['prev_end']);
            } else {
                $prev = null;
            }

            return array(
                'success' => true,
                'gran'    => $r['gran'],
                'points'  => $chart['points'],
                'totals'  => $totals,
                'prev'    => $prev
            );
        }, array('series', array($range_key)), $force);
    }

    private static function fetch_totals($ctx, $start, $end) {
        $res = self::rum($ctx, self::DS_PAGELOAD, $start, $end, 'count sum { visits }', 1);

        if (!$res['success']) {
            return $res;
        }

        $row = self::first_row($res);

        return array(
            'success' => true,
            'views'   => (int) (isset($row['count']) ? $row['count'] : 0),
            'visits'  => (int) (isset($row['sum']['visits']) ? $row['sum']['visits'] : 0)
        );
    }

    /**
     * Average page load time in ms, or null when Cloudflare doesn't expose it
     */
    private static function fetch_page_load($ctx, $start, $end) {
        $api = new CF_API();

        if (in_array('pageLoadTime', $api->type_fields('AccountRumPerformanceEventsAdaptiveGroupsAvg'), true)) {
            $selection = 'avg { pageLoadTime }';
            $path = array('avg', 'pageLoadTime');
        } elseif (in_array('pageLoadTimeP50', $api->type_fields('AccountRumPerformanceEventsAdaptiveGroupsQuantiles'), true)) {
            $selection = 'quantiles { pageLoadTimeP50 }';
            $path = array('quantiles', 'pageLoadTimeP50');
        } else {
            return null;
        }

        $res = self::rum($ctx, self::DS_PERF, $start, $end, $selection, 1);
        if (!$res['success']) {
            return null;
        }

        $row = self::first_row($res);
        if (!isset($row[$path[0]][$path[1]]) || $row[$path[0]][$path[1]] < 0) {
            return null;
        }

        return $row[$path[0]][$path[1]] / 1000; // microseconds -> ms
    }

    private static function fetch_points($ctx, $r) {
        $tz = wp_timezone();
        $dims = ($r['gran'] === 'hour') ? array('datetimeHour', 'datetimeMinute') : array('datetimeHour', 'date');

        $res = array('success' => false, 'message' => 'No data.');
        $used = '';

        foreach ($dims as $dim) {
            $res = self::rum($ctx, self::DS_PAGELOAD, $r['start'], $r['end'], 'count sum { visits } dimensions { ' . $dim . ' }', 10000);

            if ($res['success']) {
                $used = $dim;
                break;
            }

            // Only try the next dimension when this one simply doesn't exist.
            if (!preg_match('/unknown|cannot query|not exist|invalid/i', isset($res['message']) ? $res['message'] : '')) {
                break;
            }
        }

        if (!$res['success']) {
            return $res;
        }

        $buckets = array();

        foreach ($res['data'] as $row) {
            $raw = isset($row['dimensions'][$used]) ? $row['dimensions'][$used] : '';
            if ($raw === '') {
                continue;
            }

            if ($used === 'date') {
                $key = $raw;
            } else {
                $ts = strtotime($raw);
                if (!$ts) {
                    continue;
                }
                $key = ($r['gran'] === 'hour') ? (int) (floor($ts / 3600) * 3600) : wp_date('Y-m-d', $ts, $tz);
            }

            if (!isset($buckets[$key])) {
                $buckets[$key] = array('views' => 0, 'visits' => 0);
            }
            $buckets[$key]['views'] += (int) (isset($row['count']) ? $row['count'] : 0);
            $buckets[$key]['visits'] += (int) (isset($row['sum']['visits']) ? $row['sum']['visits'] : 0);
        }

        $points = array();

        if ($r['gran'] === 'hour') {
            for ($t = (int) (floor($r['start'] / 3600) * 3600); $t < $r['end']; $t += 3600) {
                $b = isset($buckets[$t]) ? $buckets[$t] : array('views' => 0, 'visits' => 0);
                $points[] = array('ts' => $t, 'label' => wp_date('H:i', $t, $tz), 'views' => $b['views'], 'visits' => $b['visits']);
            }
        } else {
            $cursor = (new DateTimeImmutable('@' . $r['start']))->setTimezone($tz);
            while ($cursor->getTimestamp() < $r['end']) {
                $k = $cursor->format('Y-m-d');
                $b = isset($buckets[$k]) ? $buckets[$k] : array('views' => 0, 'visits' => 0);
                $points[] = array('ts' => $cursor->getTimestamp(), 'label' => wp_date('M j', $cursor->getTimestamp(), $tz), 'views' => $b['views'], 'visits' => $b['visits']);
                $cursor = $cursor->modify('+1 day');
            }
        }

        return array('success' => true, 'points' => $points);
    }

    /* ------------------------------------------------------------------
     * Breakdowns (pages, referrers, countries, browsers, operating systems, devices)
     * ------------------------------------------------------------------ */

    /**
     * Returns: success, rows[] (key, views, visits), fetched_at
     */
    public static function breakdown($range_key, $dimension, $limit = 50, $cache_only = false, $force = false) {
        $allowed = array('requestPath', 'refererHost', 'countryName', 'userAgentBrowser', 'userAgentOS', 'deviceType');
        if (!in_array($dimension, $allowed, true)) {
            return array('success' => false, 'message' => 'Unsupported breakdown.');
        }

        $r = self::range($range_key);

        return self::remember('bd_' . $dimension . '_' . (int) $limit . '_' . $r['key'], self::ttl($r['live']), $cache_only, function () use ($r, $dimension, $limit) {
            $ctx = self::context();
            if (!$ctx['success']) {
                return $ctx;
            }

            $res = self::rum($ctx, self::DS_PAGELOAD, $r['start'], $r['end'], 'count sum { visits } dimensions { ' . $dimension . ' }', (int) $limit, 'count_DESC');
            if (!$res['success']) {
                return $res;
            }

            $rows = array();
            foreach ($res['data'] as $row) {
                $key = isset($row['dimensions'][$dimension]) ? $row['dimensions'][$dimension] : '';
                $rows[] = array(
                    'key'    => ($key === null) ? '' : (string) $key,
                    'views'  => (int) (isset($row['count']) ? $row['count'] : 0),
                    'visits' => (int) (isset($row['sum']['visits']) ? $row['sum']['visits'] : 0)
                );
            }

            return array('success' => true, 'rows' => $rows);
        }, array('breakdown', array($range_key, $dimension, (int) $limit)), $force);
    }

    /**
     * Published posts/pages: normalised path => title (cached for an hour)
     */
    private static function valid_paths() {
        $map = get_transient('cfa_valid_paths');
        if (is_array($map)) {
            return $map;
        }

        $map = array('' => 'Homepage');

        $posts = get_posts(array(
            'post_type'              => array('post', 'page'),
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ));

        foreach ($posts as $post) {
            $path = wp_parse_url(get_permalink($post), PHP_URL_PATH);
            if ($path) {
                $title = get_the_title($post);
                $map[self::normalize_path($path)] = ($title !== '') ? $title : '(no title)';
            }
        }

        set_transient('cfa_valid_paths', $map, HOUR_IN_SECONDS);
        return $map;
    }

    private static function normalize_path($path) {
        return strtolower(rtrim(rawurldecode((string) $path), '/'));
    }

    /**
     * Top pages with the WordPress title added where the path matches a post or page
     */
    public static function top_pages($range_key, $limit = 20, $cache_only = false) {
        $bd = self::breakdown($range_key, 'requestPath', self::PATH_LIMIT, $cache_only);

        if (empty($bd['success'])) {
            return $bd;
        }

        $valid = self::valid_paths();
        $rows = array();

        foreach ($bd['rows'] as $row) {
            $clean = self::normalize_path($row['key']);
            $row['title'] = isset($valid[$clean]) ? $valid[$clean] : '';
            $rows[] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return array('success' => true, 'rows' => $rows, 'fetched_at' => isset($bd['fetched_at']) ? $bd['fetched_at'] : time());
    }

    /* ------------------------------------------------------------------
     * One page (used by the admin bar counter on the public site)
     * ------------------------------------------------------------------ */

    /**
     * Exact page views and visits for one page path.
     *
     * Read from the page-path list the Pages tab already uses (one cached query shared by every
     * page), summing variants such as /contact and /contact/. Only when the list is full and the
     * page isn't in it does this fall back to a filtered query for that single page.
     */
    public static function page_views($range_key, $path, $cache_only = false, $force = false) {
        $path = self::clean_path($path);
        $bd = self::breakdown($range_key, 'requestPath', self::PATH_LIMIT, $cache_only, $force);

        if (empty($bd['success'])) {
            return $bd;
        }

        $wanted = self::normalize_path($path);
        $views = 0;
        $visits = 0;
        $found = false;

        foreach ($bd['rows'] as $row) {
            if (self::normalize_path($row['key']) === $wanted) {
                $views += $row['views'];
                $visits += $row['visits'];
                $found = true;
            }
        }

        if (!$found && count($bd['rows']) >= self::PATH_LIMIT) {
            return self::page_views_exact($range_key, $path, $cache_only, $force);
        }

        return array(
            'success'    => true,
            'views'      => $views,
            'visits'     => $visits,
            'stale'      => !empty($bd['stale']),
            'fetched_at' => isset($bd['fetched_at']) ? $bd['fetched_at'] : time()
        );
    }

    /**
     * Filtered single-page query (fallback for pages outside the shared list)
     */
    private static function page_views_exact($range_key, $path, $cache_only, $force) {
        $r = self::range($range_key);

        return self::remember('pv_' . $r['key'] . '_' . md5($path), self::ttl($r['live']), $cache_only, function () use ($r, $path) {
            $ctx = self::context();
            if (!$ctx['success']) {
                return $ctx;
            }

            $res = self::rum($ctx, self::DS_PAGELOAD, $r['start'], $r['end'], 'count sum { visits }', 1, '', array('requestPath' => $path));
            if (!$res['success']) {
                return $res;
            }

            $row = self::first_row($res);

            return array(
                'success' => true,
                'views'   => (int) (isset($row['count']) ? $row['count'] : 0),
                'visits'  => (int) (isset($row['sum']['visits']) ? $row['sum']['visits'] : 0)
            );
        }, null, $force);
    }

    /* ------------------------------------------------------------------
     * Core Web Vitals
     * ------------------------------------------------------------------ */

    private static function vitals_defs() {
        return array(
            'lcp' => array('base' => 'largestContentfulPaint', 'prefix' => 'lcp', 'unit' => 'ms'),
            'inp' => array('base' => 'interactionToNextPaint', 'prefix' => 'inp', 'unit' => 'ms'),
            'cls' => array('base' => 'cumulativeLayoutShift', 'prefix' => 'cls', 'unit' => '')
        );
    }

    private static function vitals_subparts() {
        return array(
            'lcp' => array(
                'Time to first byte'     => 'timeToFirstByte',
                'Resource load delay'    => 'lcpResourceLoadDelay',
                'Resource load duration' => 'lcpResourceLoadTime',
                'Element render delay'   => 'lcpElementRenderDelay'
            ),
            'inp' => array(
                'Input delay'            => 'inpInputDelay',
                'Processing duration'    => 'inpProcessingDuration',
                'Presentation delay'     => 'inpPresentationDelay'
            )
        );
    }

    /**
     * Fetch a list of fields from a dataset in batches. Cloudflare limits how many fields a query
     * may select; when it reports that limit, the batch is halved and retried.
     * Returns: success, data (merged fields of $block), message on failure.
     */
    private static function rum_chunked($ctx, $dataset, $start, $end, $block, $fields, $size = 8) {
        $out = array();
        $i = 0;
        $total = count($fields);
        $size = max(1, (int) $size);

        while ($i < $total) {
            $part = array_slice($fields, $i, $size);
            $res = self::rum($ctx, $dataset, $start, $end, $block . ' { ' . implode(' ', $part) . ' }', 1);

            if ($res['success']) {
                $row = self::first_row($res);
                if (!empty($row[$block]) && is_array($row[$block])) {
                    $out = array_merge($out, $row[$block]);
                }
                $i += count($part);
                continue;
            }

            if ($size > 1 && preg_match('/number of fields/i', isset($res['message']) ? $res['message'] : '')) {
                $size = max(1, (int) floor($size / 2));
                continue;
            }

            return array('success' => false, 'message' => $res['message'], 'data' => $out);
        }

        return array('success' => true, 'data' => $out);
    }

    /**
     * Returns: success, metrics[lcp|inp|cls] => array(p50, p75, p90, p99 [, good, needs, poor, subparts])
     * Values are ms (LCP, INP) or a unitless score (CLS); null when Cloudflare reports N/A.
     */
    public static function vitals($range_key, $cache_only = false, $force = false) {
        $r = self::range($range_key);

        return self::remember('vitals_' . $r['key'], self::ttl($r['live']), $cache_only, function () use ($r) {
            $ctx = self::context();
            if (!$ctx['success']) {
                return $ctx;
            }

            $api = new CF_API();
            $quantile_fields = $api->type_fields('AccountRumWebVitalsEventsAdaptiveGroupsQuantiles');
            $sum_fields = $api->type_fields('AccountRumWebVitalsEventsAdaptiveGroupsSum');

            $defs = self::vitals_defs();
            $subparts = self::vitals_subparts();
            $percentiles = array('P50' => 'p50', 'P75' => 'p75', 'P90' => 'p90', 'P99' => 'p99');

            // Cloudflare rejects queries with more than 30 fields, so the data is fetched in three
            // small queries: percentiles (required), rating counts and sub-parts (both optional).
            $known = function ($f) use ($quantile_fields) {
                return !$quantile_fields || in_array($f, $quantile_fields, true);
            };

            $q = array();
            foreach ($defs as $metric => $def) {
                foreach ($percentiles as $p => $_) {
                    if ($known($def['base'] . $p)) {
                        $q[] = $def['base'] . $p;
                    }
                }
            }

            if (!$q) {
                return array('success' => false, 'message' => 'Core Web Vitals fields are not available for this account.');
            }

            $main = self::rum_chunked($ctx, self::DS_VITALS, $r['start'], $r['end'], 'quantiles', $q);
            if (!$main['success']) {
                return $main;
            }

            $quant = $main['data'];

            // Good / needs improvement / poor counts (only fields the schema lists)
            $sum = array();
            $sums = array();
            foreach ($sum_fields as $f) {
                if (preg_match('/^(lcp|inp|cls)(good|needsimprovement|poor)$/i', $f)) {
                    $sums[] = $f;
                }
            }
            if ($sums) {
                $res_sum = self::rum_chunked($ctx, self::DS_VITALS, $r['start'], $r['end'], 'sum', $sums);
                $sum = $res_sum['data'];
            }

            // LCP / INP sub-parts at P75 (only fields the schema lists)
            $sub = array();
            if ($quantile_fields) {
                foreach ($subparts as $metric => $fields) {
                    foreach ($fields as $label => $field) {
                        if ($known($field . 'P75')) {
                            $sub[] = $field . 'P75';
                        }
                    }
                }
            }
            if ($sub) {
                $res_sub = self::rum_chunked($ctx, self::DS_VITALS, $r['start'], $r['end'], 'quantiles', $sub);
                $quant = array_merge($quant, $res_sub['data']);
            }

            $metrics = array();

            foreach ($defs as $metric => $def) {
                $m = array();

                foreach ($percentiles as $p => $key) {
                    $v = isset($quant[$def['base'] . $p]) ? $quant[$def['base'] . $p] : null;
                    $m[$key] = self::vital_value($v, $def['unit']);
                }

                // Rating distribution
                $good = self::pick($sum, $def['prefix'] . 'Good');
                $needs = self::pick($sum, $def['prefix'] . 'NeedsImprovement');
                $poor = self::pick($sum, $def['prefix'] . 'Poor');
                if ($good !== null && $needs !== null && $poor !== null && ($good + $needs + $poor) > 0) {
                    $m['good'] = (int) $good;
                    $m['needs'] = (int) $needs;
                    $m['poor'] = (int) $poor;
                }

                // Sub-parts (P75, ms)
                if (isset($subparts[$metric])) {
                    $parts = array();
                    foreach ($subparts[$metric] as $label => $field) {
                        if (isset($quant[$field . 'P75']) && $quant[$field . 'P75'] >= 0) {
                            $parts[$label] = $quant[$field . 'P75'] / 1000;
                        }
                    }
                    if ($parts) {
                        $m['subparts'] = $parts;
                    }
                }

                $metrics[$metric] = $m;
            }

            return array('success' => true, 'metrics' => $metrics);
        }, array('vitals', array($range_key)), $force);
    }

    private static function vital_value($value, $unit) {
        if ($value === null || $value < 0) {
            return null; // negative = not available
        }
        return ($unit === 'ms') ? $value / 1000 : (float) $value; // microseconds -> ms
    }

    private static function pick($arr, $field) {
        foreach ($arr as $k => $v) {
            if (strcasecmp($k, $field) === 0) {
                return $v;
            }
        }
        return null;
    }

    /**
     * P75 vitals per page. Returns the same shape as breakdown() with lcp/inp/cls (or null).
     */
    public static function vitals_by_page($range_key, $cache_only = false, $force = false) {
        $r = self::range($range_key);

        return self::remember('vitals_pages_' . $r['key'], self::ttl($r['live']), $cache_only, function () use ($r) {
            $ctx = self::context();
            if (!$ctx['success']) {
                return $ctx;
            }

            $res = self::rum($ctx, self::DS_VITALS, $r['start'], $r['end'],
                'count dimensions { requestPath } quantiles { largestContentfulPaintP75 interactionToNextPaintP75 cumulativeLayoutShiftP75 }', 25, 'count_DESC');
            if (!$res['success']) {
                return $res;
            }

            $rows = array();
            foreach ($res['data'] as $row) {
                $q = isset($row['quantiles']) ? $row['quantiles'] : array();
                $rows[] = array(
                    'key'   => isset($row['dimensions']['requestPath']) ? (string) $row['dimensions']['requestPath'] : '',
                    'count' => (int) (isset($row['count']) ? $row['count'] : 0),
                    'lcp'   => self::vital_value(isset($q['largestContentfulPaintP75']) ? $q['largestContentfulPaintP75'] : null, 'ms'),
                    'inp'   => self::vital_value(isset($q['interactionToNextPaintP75']) ? $q['interactionToNextPaintP75'] : null, 'ms'),
                    'cls'   => self::vital_value(isset($q['cumulativeLayoutShiftP75']) ? $q['cumulativeLayoutShiftP75'] : null, '')
                );
            }

            return array('success' => true, 'rows' => $rows);
        }, array('vitals_by_page', array($range_key)), $force);
    }

    /**
     * good | needs | poor | null for a vital value, using Google's thresholds
     */
    public static function rating($metric, $value) {
        if ($value === null) {
            return null;
        }

        $limits = array('lcp' => array(2500, 4000), 'inp' => array(200, 500), 'cls' => array(0.1, 0.25));

        if (!isset($limits[$metric])) {
            return null;
        }

        if ($value <= $limits[$metric][0]) {
            return 'good';
        }
        return ($value <= $limits[$metric][1]) ? 'needs' : 'poor';
    }

    /* ------------------------------------------------------------------
     * Display helpers
     * ------------------------------------------------------------------ */

    public static function change($current, $previous) {
        if ($previous === null || $previous <= 0 || $current === null) {
            return null;
        }
        return (($current - $previous) / $previous) * 100;
    }

    /**
     * "14%" (or "%14" for Turkish locales)
     */
    public static function pct_text($value, $decimals = 0) {
        $n = number_format_i18n(abs($value), $decimals);
        return (strpos(get_locale(), 'tr') === 0) ? '%' . $n : $n . '%';
    }

    /**
     * "3,388ms" or "0.032"
     */
    public static function vital_text($metric, $value) {
        if ($value === null) {
            return "\xE2\x80\x94";
        }
        return ($metric === 'cls') ? number_format_i18n($value, 3) : number_format_i18n($value) . 'ms';
    }

    /**
     * Tags and attributes CF_Live::trend() produces, for wp_kses() at the point of output
     */
    public static function allowed_html() {
        return array(
            'span' => array('class' => true),
            'svg'  => array('viewbox' => true, 'width' => true, 'height' => true, 'aria-hidden' => true),
            'path' => array('d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true)
        );
    }

    /**
     * Trend badge: arrow + value. $inverse = true when an increase is bad (e.g. load time).
     */
    public static function trend($change, $inverse = false, $text = null) {
        if ($change === null) {
            return '<span class="cfa-trend is-flat">&mdash;</span>';
        }

        if (abs($change) < 0.05) {
            $dir = 'flat';
        } else {
            $dir = ($change > 0) ? 'up' : 'down';
        }

        $tone = ($dir === 'flat') ? 'flat' : ((($dir === 'up') xor $inverse) ? 'good' : 'bad');

        $arrows = array(
            'up'   => '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M4 12L12 4M5.5 4H12v6.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'down' => '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M4 4l8 8M12 5.5V12H5.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'flat' => '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M3 8h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        );

        $label = ($text !== null) ? $text : self::pct_text($change, abs($change) < 10 ? 1 : 0);

        return '<span class="cfa-trend is-' . esc_attr($tone) . '">' . $arrows[$dir] . '<span>' . esc_html($label) . '</span></span>';
    }
}

// Refresh queued data after the page has loaded. The admin bar renders late on the public site
// (wp_footer priority 1000), so the script is printed after it.
add_action('admin_footer', array('CF_Live', 'print_refresh_script'), 100);
add_action('wp_footer', array('CF_Live', 'print_refresh_script'), 1001);

add_action('wp_ajax_cf_analytics_refresh', function () {
    check_ajax_referer('cf_analytics_refresh', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    // JSON payload: decoded and every field validated in run_job(). sanitize_text_field() would
    // strip percent-encoded characters from page paths, so it is deliberately not used here.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $jobs = json_decode(isset($_POST['jobs']) ? wp_unslash($_POST['jobs']) : '', true);

    if (!is_array($jobs)) {
        wp_send_json_error('Invalid request.');
    }

    ignore_user_abort(true);

    $done = 0;

    foreach (array_slice($jobs, 0, 10) as $job) {
        if (!is_array($job) || empty($job[0]) || !isset($job[1]) || !is_array($job[1])) {
            continue;
        }
        CF_Live::run_job((string) $job[0], $job[1]);
        $done++;
    }

    wp_send_json_success(array('refreshed' => $done));
});

/**
 * AJAX: today's count for the admin bar. scope=page -> one page, scope=total -> whole site.
 * Called right after the page has loaded, only when the counter had no fresh number.
 */
add_action('wp_ajax_cf_analytics_bar_count', function () {
    check_ajax_referer('cf_analytics_bar_count', 'nonce');

    if (!current_user_can('manage_options') || !CF_API::is_configured()) {
        wp_send_json_error('Unauthorized');
    }

    ignore_user_abort(true);

    $scope = (isset($_POST['scope']) && $_POST['scope'] === 'page') ? 'page' : 'total';

    if ($scope === 'page') {
        // clean_path() strips control characters and bounds the length; sanitize_text_field() would
        // strip percent-encoded characters from the path.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $path = CF_Live::clean_path(isset($_POST['path']) ? wp_unslash($_POST['path']) : '/');
        $res = CF_Live::page_views('today', $path, false, true);

        if (empty($res['success'])) {
            wp_send_json_error(isset($res['message']) ? $res['message'] : 'Could not load the count.');
        }

        wp_send_json_success(array('views' => number_format_i18n($res['views']), 'visits' => number_format_i18n($res['visits'])));
    }

    $res = CF_Live::series('today', false, true);

    if (empty($res['success'])) {
        wp_send_json_error(isset($res['message']) ? $res['message'] : 'Could not load the count.');
    }

    wp_send_json_success(array('views' => number_format_i18n($res['totals']['views']), 'visits' => number_format_i18n($res['totals']['visits'])));
});
