<?php
/**
 * Admin bar: a page-views counter
 *
 * - Public site: the exact views of the page you are looking at (today). No dropdown.
 *   Click opens the Analytics dashboard.
 * - wp-admin: total site page views today, with a short dropdown.
 *
 * The server renders whatever is cached. If there is no number yet (or it is old), a request
 * right after the page has loaded fetches it and updates the counter in place.
 */

if (!defined('ABSPATH')) {
    exit;
}

class EACFA_Analytics_Admin_Bar {

    /** array('scope' => 'page'|'total', 'path' => string) when the counter needs a fresh number */
    private $refresh = null;

    public function __construct() {
        add_action('admin_bar_menu', array($this, 'add_admin_bar_item'), 100);
        add_action('wp_head', array($this, 'print_styles'));
        add_action('admin_head', array($this, 'print_styles'));
        // Printed after the bar has been built (it renders late on the public site).
        add_action('wp_footer', array($this, 'print_refresh_script'), 1002);
        add_action('admin_footer', array($this, 'print_refresh_script'), 101);
    }

    /**
     * Path of the page being viewed, as Cloudflare records it
     */
    private function current_path() {
        if (is_singular()) {
            $path = wp_parse_url(get_permalink(get_queried_object_id()), PHP_URL_PATH);
            if ($path) {
                return EACFA_Live::clean_path($path);
            }
        } elseif (is_front_page()) {
            $path = wp_parse_url(home_url('/'), PHP_URL_PATH);
            return EACFA_Live::clean_path($path ? $path : '/');
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);

        return EACFA_Live::clean_path($path ? $path : '/');
    }

    public function add_admin_bar_item($wp_admin_bar) {
        if (!current_user_can('manage_options') || !EACFA_API::is_configured()) {
            return;
        }

        $front = !is_admin();
        $dashboard = EACFA_Live::dashboard_url();
        $dash = "\xE2\x80\x93"; // placeholder until the number arrives
        $views = $dash;
        $visits = $dash;
        $needs_refresh = true;

        // Read the cache only; the refresh below happens after the page has loaded.
        EACFA_Live::$no_queue = true;

        if ($front) {
            $path = $this->current_path();
            $res = EACFA_Live::page_views('today', $path, true);
            if (!empty($res['success'])) {
                $views = number_format_i18n($res['views']);
                $needs_refresh = !empty($res['stale']);
            }
            $this->refresh = array('scope' => 'page', 'path' => $path);
            $tip = 'Page views today for this page. Open Cloudflare Web Analytics.';
        } else {
            $res = EACFA_Live::series('today', true);
            if (!empty($res['success'])) {
                $views = number_format_i18n($res['totals']['views']);
                $visits = number_format_i18n($res['totals']['visits']);
                $needs_refresh = !empty($res['stale']);
            }
            $this->refresh = array('scope' => 'total', 'path' => '');
            $tip = 'Total page views today. Open Cloudflare Web Analytics.';
        }

        EACFA_Live::$no_queue = false;

        if (!$needs_refresh) {
            $this->refresh = null;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'cf-analytics',
            'title' => '<span class="cfab-icon" aria-hidden="true"></span><span class="cfab-num">' . esc_html($views) . '</span>',
            'href'  => $dashboard,
            'meta'  => array('title' => $tip)
        ));

        // The dropdown is only for wp-admin.
        if ($front) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'parent' => 'cf-analytics',
            'id'     => 'cf-analytics-views',
            'title'  => 'Page views today: <span class="cfab-views">' . esc_html($views) . '</span>',
            'href'   => $dashboard
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'cf-analytics',
            'id'     => 'cf-analytics-visits',
            'title'  => 'Visits today: <span class="cfab-visits">' . esc_html($visits) . '</span>',
            'href'   => $dashboard
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'cf-analytics',
            'id'     => 'cf-analytics-open',
            'title'  => 'Open dashboard',
            'href'   => $dashboard
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'cf-analytics',
            'id'     => 'cf-analytics-settings',
            'title'  => 'Settings',
            'href'   => EACFA_Live::dashboard_url(array('section' => 'settings'))
        ));
    }

    /**
     * After the page has loaded: fetch the number and update the counter in place.
     * If it fails, the reason is shown when hovering the counter.
     */
    public function print_refresh_script() {
        if (!$this->refresh || !current_user_can('manage_options')) {
            return;
        }
        ?>
        <script>
        (function () {
            var num = document.querySelector('#wp-admin-bar-cf-analytics .cfab-num');
            if (!num || !window.fetch || !window.URLSearchParams) { return; }

            var body = new URLSearchParams();
            body.append('action', 'cf_analytics_bar_count');
            body.append('nonce', <?php echo wp_json_encode(wp_create_nonce('cf_analytics_bar_count')); ?>);
            body.append('scope', <?php echo wp_json_encode($this->refresh['scope']); ?>);
            body.append('path', <?php echo wp_json_encode($this->refresh['path']); ?>);

            function set(sel, text) {
                var el = document.querySelector(sel);
                if (el) { el.textContent = text; }
            }

            function run() {
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            set('#wp-admin-bar-cf-analytics .cfab-num', res.data.views);
                            set('#wp-admin-bar-cf-analytics-views .cfab-views', res.data.views);
                            set('#wp-admin-bar-cf-analytics-visits .cfab-visits', res.data.visits);
                        } else {
                            var link = document.querySelector('#wp-admin-bar-cf-analytics > a');
                            if (link) { link.title = 'Could not load the count: ' + ((res && res.data) ? res.data : 'unknown error'); }
                        }
                    })
                    .catch(function () {});
            }

            function later() { setTimeout(run, 300); }

            if (document.readyState === 'complete') { later(); } else { window.addEventListener('load', later); }
        })();
        </script>
        <?php
    }

    public function print_styles() {
        if (!is_admin_bar_showing()) {
            return;
        }
        ?>
        <style>
            #wpadminbar #wp-admin-bar-cf-analytics > .ab-item { display: flex; align-items: center; gap: 6px; }
            #wpadminbar .cfab-icon { display: inline-block; width: 16px; height: 16px; background: currentColor; opacity: .85;
                -webkit-mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M7 18a4 4 0 0 1-.6-7.96A5.5 5.5 0 0 1 17 9.5a4.25 4.25 0 0 1 .5 8.5H7z'/></svg>") center / contain no-repeat;
                mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M7 18a4 4 0 0 1-.6-7.96A5.5 5.5 0 0 1 17 9.5a4.25 4.25 0 0 1 .5 8.5H7z'/></svg>") center / contain no-repeat; }
        </style>
        <?php
    }
}

new EACFA_Analytics_Admin_Bar();
