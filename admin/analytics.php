<?php
/**
 * Web Analytics dashboard: vertical navigation, live data from Cloudflare
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ----------------------------------------------------------------------
 * Read-only navigation parameters
 * -------------------------------------------------------------------- */

/**
 * Value of a navigation parameter (section, range, page ...), sanitised as a key.
 * Reading these changes nothing; every action that does change state verifies a nonce first.
 */
function cfa_param($key, $default = '') {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
    $value = isset($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : $default;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    return $value;
}

function cfa_has_param($key) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
    return isset($_GET[$key]);
}

/**
 * Version string for an asset: plugin version plus the file's modification time, so browsers
 * never keep serving an old stylesheet or script after the files change, even if the plugin
 * version number does not.
 */
function cfa_asset_version($relative_path) {
    $file = CF_ANALYTICS_PLUGIN_DIR . $relative_path;

    return CF_ANALYTICS_VERSION . (file_exists($file) ? '-' . filemtime($file) : '');
}

/* ----------------------------------------------------------------------
 * Theme (light / dark / system), stored per user
 * -------------------------------------------------------------------- */

function cfa_current_theme() {
    $theme = get_user_meta(get_current_user_id(), 'cf_analytics_theme', true);
    return in_array($theme, array('light', 'dark', 'auto'), true) ? $theme : 'light';
}

function cfa_set_theme($theme) {
    if (in_array($theme, array('light', 'dark', 'auto'), true)) {
        update_user_meta(get_current_user_id(), 'cf_analytics_theme', $theme);
    }
}

add_filter('admin_body_class', function ($classes) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if ($screen && ($screen->id === 'dashboard' || strpos($screen->id, 'cf-analytics') !== false)) {
        $classes .= ' cfa-theme-' . cfa_current_theme();
        if ($screen->id !== 'dashboard') {
            $classes .= ' cfa-page';
        }
    }

    return $classes;
});

/* ----------------------------------------------------------------------
 * Menu: one link under Dashboard, between Home and Updates
 * -------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page(
        'index.php',
        'Web Analytics',
        'Web Analytics',
        'manage_options',
        'cf-analytics',
        'cf_analytics_page',
        1
    );
});

/* ----------------------------------------------------------------------
 * Assets + request handlers
 * -------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', function ($hook) {
    $is_plugin_page = strpos($hook, 'cf-analytics') !== false;

    if ($is_plugin_page || $hook === 'index.php') {
        wp_enqueue_style('cf-analytics-admin', CF_ANALYTICS_PLUGIN_URL . 'assets/css/admin.css', array(), cfa_asset_version('assets/css/admin.css'));
    }

    $section = cfa_param('section', 'overview');
    $needs_chart = ($hook === 'index.php') || ($hook === 'dashboard_page_cf-analytics' && $section === 'overview');

    if ($needs_chart) {
        wp_enqueue_script('cfa-chart', CF_ANALYTICS_PLUGIN_URL . 'assets/js/cfa-chart.js', array(), cfa_asset_version('assets/js/cfa-chart.js'), false);
    }
});

add_action('admin_init', function () {
    if (strpos(cfa_param('page'), 'cf-analytics') !== 0 || !current_user_can('manage_options')) {
        return;
    }

    // Refresh data
    if (cfa_has_param('cfa_refresh')) {
        check_admin_referer('cfa_refresh');
        CF_Live::refresh_cache();
        wp_safe_redirect(remove_query_arg(array('cfa_refresh', '_wpnonce')));
        exit;
    }

    // Quick theme switch
    if (cfa_has_param('cfa_theme')) {
        check_admin_referer('cfa_theme');
        cfa_set_theme(cfa_param('cfa_theme'));
        wp_safe_redirect(remove_query_arg(array('cfa_theme', '_wpnonce')));
        exit;
    }
});

/* ----------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------- */

function cfa_sections() {
    return array(
        'overview'   => array('Overview', 'dashicons-chart-area'),
        'pages'      => array('Pages', 'dashicons-admin-page'),
        'referrers'  => array('Referrers', 'dashicons-share'),
        'countries'  => array('Countries', 'dashicons-admin-site-alt3'),
        'technology' => array('Technology', 'dashicons-desktop'),
        'vitals'     => array('Core Web Vitals', 'dashicons-performance')
    );
}

function cfa_url($args = array()) {
    return CF_Live::dashboard_url($args);
}

function cfa_num($n) {
    return number_format_i18n((float) $n);
}

function cfa_total($rows, $key = 'views') {
    $sum = 0;
    foreach ($rows as $row) {
        $sum += $row[$key];
    }
    return $sum;
}

function cfa_share($value, $total) {
    return $total > 0 ? ($value / $total) * 100 : 0;
}

function cfa_error($res, $inline = false) {
    $msg = isset($res['message']) ? $res['message'] : 'Something went wrong.';
    $class = $inline ? 'cfa-empty is-error' : 'cfa-notice is-error';
    echo '<div class="' . esc_attr($class) . '"><strong>Couldn&rsquo;t load this data.</strong> ' . esc_html($msg) . ' <a href="' . esc_url(cfa_url(array('section' => 'settings'))) . '">Check settings</a></div>';
}

function cfa_country_label($code) {
    return ($code === '') ? 'Unknown' : CF_API::get_country_name($code);
}

/**
 * Ranked list with share bars
 */
function cfa_list_card($title, $res, $link, $label_cb, $limit = 6) {
    ?>
    <section class="cfa-card">
        <div class="cfa-card-head">
            <h3><?php echo esc_html($title); ?></h3>
            <?php if ($link): ?>
                <a class="cfa-link" href="<?php echo esc_url($link); ?>">View all</a>
            <?php endif; ?>
        </div>
        <?php if (empty($res['success'])): ?>
            <?php cfa_error($res, true); ?>
        <?php elseif (empty($res['rows'])): ?>
            <div class="cfa-empty">No data for this period yet.</div>
        <?php else:
            $total = cfa_total($res['rows']);
            $max = max(1, $res['rows'][0]['views']);
            ?>
            <ul class="cfa-list">
                <?php foreach (array_slice($res['rows'], 0, $limit) as $row): ?>
                    <li>
                        <span class="cfa-list-bar" style="width: <?php echo esc_attr(round(($row['views'] / $max) * 100, 1)); ?>%"></span>
                        <span class="cfa-list-label"><?php echo wp_kses_post(call_user_func($label_cb, $row)); ?></span>
                        <span class="cfa-list-value"><?php echo esc_html(cfa_num($row['views'])); ?> <em><?php echo esc_html(CF_Live::pct_text(cfa_share($row['views'], $total))); ?></em></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
}

function cfa_views_cell($row, $max, $total) {
    ?>
    <td class="is-bar">
        <span class="cfa-bar"><i style="width: <?php echo esc_attr(round(($row['views'] / $max) * 100, 1)); ?>%"></i></span>
        <span class="cfa-bar-num"><?php echo esc_html(cfa_num($row['views'])); ?> <em><?php echo esc_html(CF_Live::pct_text(cfa_share($row['views'], $total))); ?></em></span>
    </td>
    <?php
}

/**
 * Table for pages / referrers / countries
 */
function cfa_table_card($res, $first_col, $label_cb) {
    if (empty($res['success'])) {
        cfa_error($res);
        return;
    }

    if (empty($res['rows'])) {
        echo '<div class="cfa-empty">No data for this period yet.</div>';
        return;
    }

    $total = cfa_total($res['rows']);
    $max = max(1, $res['rows'][0]['views']);
    ?>
    <section class="cfa-card cfa-table-card">
        <table class="cfa-table">
            <thead>
                <tr>
                    <th class="is-rank">#</th>
                    <th><?php echo esc_html($first_col); ?></th>
                    <th>Page views</th>
                    <th class="is-num">Visits</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($res['rows'] as $i => $row): ?>
                    <tr>
                        <td class="is-rank"><?php echo (int) ($i + 1); ?></td>
                        <td class="is-main"><?php echo wp_kses_post(call_user_func($label_cb, $row)); ?></td>
                        <?php cfa_views_cell($row, $max, $total); ?>
                        <td class="is-num"><?php echo esc_html(cfa_num($row['visits'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
}

function cfa_page_label($row) {
    if (!empty($row['title'])) {
        return '<strong>' . esc_html($row['title']) . '</strong><small>' . esc_html($row['key']) . '</small>';
    }
    return '<strong>' . esc_html($row['key'] === '' ? '/' : $row['key']) . '</strong>';
}

function cfa_referrer_label($row) {
    return '<strong>' . esc_html($row['key'] === '' ? 'Direct / none' : $row['key']) . '</strong>';
}


/**
 * Chart code, printed once per page. cfaChartInit() can be called again after a section has been
 * injected by the deferred loader.
 */
function cfa_chart_script() {
    ?>
    <script>
    window.cfaChartInit = function () {
        var el = document.getElementById('cfa-chart');
        var dataEl = document.getElementById('cfa-chart-data');
        if (!el || !dataEl || typeof CfaChart === 'undefined') { return; }

        var d = JSON.parse(dataEl.textContent);

        if (el._cfaChart) { el._cfaChart.destroy(); }

        var chart = new CfaChart(el, {
            label: 'Visits and page views over time',
            labels: d.labels,
            datasets: [
                { label: 'Visits',     data: d.visits, color: '#7b4a9e' },
                { label: 'Page views', data: d.views,  color: '#f59e0b' }
            ],
            dotRadius: d.labels.length <= 31 ? 3 : 0,
            lineWidth: 2.5,
            fontSize: 12,
            maxXTicks: 8
        });
        el._cfaChart = chart;

        document.querySelectorAll('#cfa-legend button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var on = chart.toggle(parseInt(btn.getAttribute('data-i'), 10));
                btn.classList.toggle('is-on', on);
            });
        });
    };

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', window.cfaChartInit); } else { window.cfaChartInit(); }
    </script>
    <?php
}

/**
 * Placeholder shown while a section loads in the background
 */
function cfa_skeleton() {
    ?>
    <div class="cfa-skeleton" aria-busy="true" aria-label="Loading">
        <div class="cfa-skel-kpis"><i></i><i></i><i></i><i></i></div>
        <div class="cfa-skel-card tall"></div>
        <div class="cfa-skel-grid"><div class="cfa-skel-card"></div><div class="cfa-skel-card"></div></div>
    </div>
    <?php
}

/**
 * Render one data section. Returns array('fetched_at' => timestamp).
 */
function cfa_render_section($section, $range) {
    switch ($section) {
        case 'pages':
            return cfa_render_pages($range);
        case 'referrers':
            return cfa_render_referrers($range);
        case 'countries':
            return cfa_render_countries($range);
        case 'technology':
            return cfa_render_technology($range);
        case 'vitals':
            return cfa_render_vitals($range);
        default:
            return cfa_render_overview($range);
    }
}

function cfa_updated_text($meta) {
    return !empty($meta['fetched_at']) ? 'updated ' . human_time_diff($meta['fetched_at'], time()) . ' ago' : '';
}

/**
 * AJAX: render a section with live fetching (used when the page had to defer it)
 */
add_action('wp_ajax_cf_analytics_section', function () {
    check_ajax_referer('cf_analytics_section', 'nonce');

    if (!current_user_can('manage_options') || !CF_API::is_configured()) {
        wp_send_json_error('Unauthorized');
    }

    $sections = cfa_sections();
    $section = isset($_POST['section']) ? sanitize_key(wp_unslash($_POST['section'])) : 'overview';
    if (!isset($sections[$section])) {
        $section = 'overview';
    }
    $range = CF_Live::sanitize_range(isset($_POST['range']) ? sanitize_key(wp_unslash($_POST['range'])) : '', '7days');

    ignore_user_abort(true);

    ob_start();
    $meta = cfa_render_section($section, $range);
    $html = ob_get_clean();

    // Anything that had to be served from stale cache is refreshed by the browser right after.
    $jobs = CF_Live::queued_jobs();
    CF_Live::clear_queue();

    wp_send_json_success(array(
        'html'          => $html,
        'updated'       => cfa_updated_text($meta),
        'jobs'          => $jobs,
        'refresh_nonce' => wp_create_nonce('cf_analytics_refresh')
    ));
});

/* ----------------------------------------------------------------------
 * Page
 * -------------------------------------------------------------------- */

function cf_analytics_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $configured = CF_API::is_configured();
    $settings = get_option('cf_analytics_settings', array());
    $sections = cfa_sections();
    $section = cfa_param('section', 'overview');
    if ($section !== 'settings' && !isset($sections[$section])) {
        $section = 'overview';
    }
    // Until Cloudflare is connected there is nothing to show but the setup screen.
    if (!$configured) {
        $section = 'settings';
    }
    $is_settings = ($section === 'settings');

    $range = CF_Live::sanitize_range(cfa_param('range'), '7days');
    $ranges = CF_Live::ranges();

    $deferred = false;

    $theme = cfa_current_theme();
    $next_theme = ($theme === 'dark') ? 'light' : 'dark';
    $args = array('section' => $section, 'range' => $range);
    $theme_url = wp_nonce_url(cfa_url(array_merge($args, array('cfa_theme' => $next_theme))), 'cfa_theme');
    $refresh_url = wp_nonce_url(cfa_url(array_merge($args, array('cfa_refresh' => 1))), 'cfa_refresh');
    $settings_url = cfa_url(array('section' => 'settings', 'range' => $range));

    $title = $is_settings ? 'Settings' : $sections[$section][0];
    ?>
    <div class="wrap cfa-wrap">
        <h1 class="screen-reader-text">Cloudflare Web Analytics</h1>

        <div class="cfa-app">
            <aside class="cfa-nav">
                <div class="cfa-brand">
                    <span class="cfa-brand-mark">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M7 18a4 4 0 0 1-.6-7.96A5.5 5.5 0 0 1 17 9.5a4.25 4.25 0 0 1 .5 8.5H7z" fill="currentColor"/></svg>
                    </span>
                    <span class="cfa-brand-text">
                        <strong>Web Analytics</strong>
                        <?php if (!empty($settings['zone_name'])): ?>
                            <small><?php echo esc_html($settings['zone_name']); ?></small>
                        <?php endif; ?>
                    </span>
                </div>

                <nav class="cfa-nav-list" aria-label="Analytics sections">
                    <?php foreach ($sections as $key => $item): ?>
                        <a class="cfa-nav-link<?php echo $key === $section ? ' is-active' : ''; ?>" href="<?php echo esc_url(cfa_url(array('section' => $key, 'range' => $range))); ?>">
                            <span class="dashicons <?php echo esc_attr($item[1]); ?>"></span>
                            <span><?php echo esc_html($item[0]); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <main class="cfa-main">
                <header class="cfa-head">
                    <div class="cfa-head-title">
                        <h2 class="cfa-title"><?php echo esc_html($title); ?></h2>
                        <p class="cfa-sub">
                            <?php if ($is_settings): ?>
                                Cloudflare Web Analytics &middot; version <?php echo esc_html(CF_ANALYTICS_VERSION); ?>
                            <?php else: ?>
                                <?php echo esc_html($ranges[$range]); ?> &middot; <?php echo esc_html(CF_Live::tz_label()); ?>
                                <span id="cfa-updated"></span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="cfa-head-center">
                        <?php if (!$is_settings): ?>
                            <div class="cfa-seg" role="group" aria-label="Date range">
                                <?php foreach ($ranges as $key => $label): ?>
                                    <a class="<?php echo $key === $range ? 'is-active' : ''; ?>" href="<?php echo esc_url(cfa_url(array('section' => $section, 'range' => $key))); ?>"><?php echo esc_html($label); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="cfa-tools">
                        <?php if ($configured): ?>
                            <span class="cfa-live" title="Data is read live from Cloudflare"><i></i> Live</span>
                        <?php endif; ?>
                        <a class="cfa-icon-btn" href="<?php echo esc_url($theme_url); ?>" title="<?php echo $theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'; ?>" aria-label="<?php echo $theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'; ?>">
                            <span class="dashicons <?php echo $theme === 'dark' ? 'dashicons-lightbulb' : 'dashicons-admin-appearance'; ?>"></span>
                        </a>
                        <a class="cfa-icon-btn<?php echo $is_settings ? ' is-active' : ''; ?>" href="<?php echo esc_url($settings_url); ?>" title="Settings" aria-label="Settings">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </a>
                        <?php if (!$is_settings): ?>
                            <a class="cfa-btn" href="<?php echo esc_url($refresh_url); ?>" title="Fetch fresh data from Cloudflare">
                                <span class="dashicons dashicons-update"></span> Refresh
                            </a>
                        <?php endif; ?>
                    </div>
                </header>

                <?php
                if ($is_settings) {
                    cfa_render_settings();
                } else {
                    // The first render reads the cache only (fresh or stale), so the page never waits on
                    // Cloudflare. If something isn't cached yet, the output is discarded and the section is
                    // loaded right after the page appears.
                    CF_Live::$cache_only_mode = true;
                    CF_Live::$missed = false;
                    ob_start();
                    $meta = cfa_render_section($section, $range);
                    CF_Live::$cache_only_mode = false;

                    if (CF_Live::$missed) {
                        ob_end_clean();
                        $deferred = true;
                        CF_Live::clear_queue(); // the loader below fetches everything itself
                        echo '<div id="cfa-deferred">';
                        cfa_skeleton();
                        echo '</div>';
                    } else {
                        ob_end_flush();
                    }

                    $updated = $deferred ? 'loading…' : cfa_updated_text($meta);
                    if ($updated !== '') {
                        ?>
                        <script>
                        (function () {
                            var el = document.getElementById('cfa-updated');
                            if (el) { el.textContent = <?php echo wp_json_encode('· ' . $updated); ?>; }
                        })();
                        </script>
                        <?php
                    }
                }
                ?>

                <?php if (!$is_settings): ?>
                    <p class="cfa-foot">Cloudflare Web Analytics, read from Cloudflare and refreshed in the background <?php echo esc_html(CF_Live::interval_phrase()); ?>. Cloudflare samples this data, so small differences from its dashboard are normal. Times use your WordPress timezone.</p>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php
    if ($section === 'overview') {
        cfa_chart_script();
    }

    if ($deferred) {
        ?>
        <script>
        (function () {
            function load() {
                var box = document.getElementById('cfa-deferred');
                if (!box || !window.fetch || !window.URLSearchParams) { return; }

                var body = new URLSearchParams();
                body.append('action', 'cf_analytics_section');
                body.append('nonce', <?php echo wp_json_encode(wp_create_nonce('cf_analytics_section')); ?>);
                body.append('section', <?php echo wp_json_encode($section); ?>);
                body.append('range', <?php echo wp_json_encode($range); ?>);

                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var upd = document.getElementById('cfa-updated');
                        if (res && res.success) {
                            box.innerHTML = res.data.html;
                            box.removeAttribute('id');
                            if (upd) { upd.textContent = res.data.updated ? '\u00B7 ' + res.data.updated : ''; }
                            if (window.cfaChartInit) { window.cfaChartInit(); }

                            if (res.data.jobs && res.data.jobs.length) {
                                var more = new URLSearchParams();
                                more.append('action', 'cf_analytics_refresh');
                                more.append('nonce', res.data.refresh_nonce);
                                more.append('jobs', JSON.stringify(res.data.jobs));
                                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: more }).catch(function () {});
                            }
                        } else {
                            box.innerHTML = '<div class="cfa-notice is-error"><strong>Couldn\u2019t load this data.</strong> ' + ((res && res.data) ? String(res.data).replace(/[<>&]/g, '') : 'Please try again.') + '</div>';
                            if (upd) { upd.textContent = ''; }
                        }
                    })
                    .catch(function () {
                        box.innerHTML = '<div class="cfa-notice is-error"><strong>Couldn\u2019t load this data.</strong> Request failed. Use Refresh to try again.</div>';
                    });
            }

            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', load); } else { load(); }
        })();
        </script>
        <?php
    }
}

/* ----------------------------------------------------------------------
 * Overview
 * -------------------------------------------------------------------- */

function cfa_render_overview($range) {
    $series = CF_Live::series($range);
    $meta = array('fetched_at' => isset($series['fetched_at']) ? $series['fetched_at'] : 0);

    if (empty($series['success'])) {
        cfa_error($series);
        return $meta;
    }

    $t = $series['totals'];
    $p = $series['prev'];
    $vitals = CF_Live::vitals($range);

    $load_change = ($p && $t['load_ms'] !== null && $p['load_ms'] !== null) ? CF_Live::change($t['load_ms'], $p['load_ms']) : null;

    $chart = array(
        'labels' => array_values(wp_list_pluck($series['points'], 'label')),
        'visits' => array_values(wp_list_pluck($series['points'], 'visits')),
        'views'  => array_values(wp_list_pluck($series['points'], 'views'))
    );
    ?>
    <section class="cfa-kpis is-four">
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Visits</div>
            <div class="cfa-kpi-value"><?php echo esc_html(cfa_num($t['visits'])); ?></div>
            <div class="cfa-kpi-trend"><?php echo wp_kses(CF_Live::trend(CF_Live::change($t['visits'], $p ? $p['visits'] : null)), CF_Live::allowed_html()); ?></div>
        </div>
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Page views</div>
            <div class="cfa-kpi-value"><?php echo esc_html(cfa_num($t['views'])); ?></div>
            <div class="cfa-kpi-trend"><?php echo wp_kses(CF_Live::trend(CF_Live::change($t['views'], $p ? $p['views'] : null)), CF_Live::allowed_html()); ?></div>
        </div>
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Page load time</div>
            <div class="cfa-kpi-value"><?php echo esc_html($t['load_ms'] === null ? "\xE2\x80\x94" : cfa_num(round($t['load_ms'])) . 'ms'); ?></div>
            <div class="cfa-kpi-trend"><?php echo wp_kses(CF_Live::trend($load_change, true), CF_Live::allowed_html()); ?></div>
        </div>
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Core Web Vitals <span class="cfa-muted">(P75)</span></div>
            <?php if (!empty($vitals['success'])): ?>
                <div class="cfa-cwv-chips">
                    <?php foreach (array('lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS') as $m => $label):
                        $v = $vitals['metrics'][$m]['p75'];
                        $rate = CF_Live::rating($m, $v);
                        ?>
                        <a class="cfa-chip <?php echo $rate ? 'is-' . esc_attr($rate) : ''; ?>" href="<?php echo esc_url(cfa_url(array('section' => 'vitals', 'range' => $range))); ?>">
                            <i></i><b><?php echo esc_html($label); ?></b> <?php echo esc_html(CF_Live::vital_text($m, $v)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="cfa-kpi-trend"><span class="cfa-muted">Not available yet</span></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="cfa-card cfa-chart-card">
        <div class="cfa-card-head">
            <h3>Traffic</h3>
            <div class="cfa-legend" id="cfa-legend">
                <button type="button" class="is-on" data-i="0"><i style="background:#7b4a9e"></i>Visits</button>
                <button type="button" class="is-on" data-i="1"><i style="background:#f59e0b"></i>Page views</button>
            </div>
        </div>
        <div class="cfa-chart"><div id="cfa-chart"></div></div>
    </section>

    <script type="application/json" id="cfa-chart-data"><?php echo wp_json_encode($chart); ?></script>

    <div class="cfa-grid">
        <?php
        cfa_list_card('Top pages', CF_Live::top_pages($range, 6), cfa_url(array('section' => 'pages', 'range' => $range)), 'cfa_page_label');
        cfa_list_card('Referrers', CF_Live::breakdown($range, 'refererHost', 20), cfa_url(array('section' => 'referrers', 'range' => $range)), 'cfa_referrer_label');
        cfa_list_card('Countries', CF_Live::breakdown($range, 'countryName', 30), cfa_url(array('section' => 'countries', 'range' => $range)), function ($row) {
            return '<strong>' . esc_html(cfa_country_label($row['key'])) . '</strong>';
        });
        cfa_list_card('Browsers', CF_Live::breakdown($range, 'userAgentBrowser', 20), cfa_url(array('section' => 'technology', 'range' => $range)), function ($row) {
            return '<strong>' . esc_html($row['key'] === '' ? 'Unknown' : $row['key']) . '</strong>';
        });
        ?>
    </div>
    <?php
    return $meta;
}

/* ----------------------------------------------------------------------
 * Pages, referrers, countries, technology
 * -------------------------------------------------------------------- */

function cfa_render_pages($range) {
    $res = CF_Live::top_pages($range, 50);
    cfa_table_card($res, 'Page', 'cfa_page_label');
    return array('fetched_at' => isset($res['fetched_at']) ? $res['fetched_at'] : 0);
}

function cfa_render_referrers($range) {
    $res = CF_Live::breakdown($range, 'refererHost', 50);
    cfa_table_card($res, 'Referrer', 'cfa_referrer_label');
    return array('fetched_at' => isset($res['fetched_at']) ? $res['fetched_at'] : 0);
}

function cfa_render_countries($range) {
    $res = CF_Live::breakdown($range, 'countryName', 100);
    cfa_table_card($res, 'Country', function ($row) {
        return '<strong>' . esc_html(cfa_country_label($row['key'])) . '</strong>' . ($row['key'] !== '' ? '<small>' . esc_html($row['key']) . '</small>' : '');
    });
    return array('fetched_at' => isset($res['fetched_at']) ? $res['fetched_at'] : 0);
}

function cfa_render_technology($range) {
    $browsers = CF_Live::breakdown($range, 'userAgentBrowser', 20);
    $label = function ($row) {
        return '<strong>' . esc_html($row['key'] === '' ? 'Unknown' : $row['key']) . '</strong>';
    };

    echo '<div class="cfa-grid">';
    cfa_list_card('Browsers', $browsers, '', $label, 12);
    cfa_list_card('Operating systems', CF_Live::breakdown($range, 'userAgentOS', 20), '', $label, 12);
    echo '</div>';
    cfa_list_card('Devices', CF_Live::breakdown($range, 'deviceType', 10), '', function ($row) {
        return '<strong>' . esc_html($row['key'] === '' ? 'Unknown' : ucfirst($row['key'])) . '</strong>';
    }, 10);

    return array('fetched_at' => isset($browsers['fetched_at']) ? $browsers['fetched_at'] : 0);
}

/* ----------------------------------------------------------------------
 * Core Web Vitals
 * -------------------------------------------------------------------- */

function cfa_render_vitals($range) {
    $v = CF_Live::vitals($range);
    $meta = array('fetched_at' => isset($v['fetched_at']) ? $v['fetched_at'] : 0);

    if (empty($v['success'])) {
        cfa_error($v);
        return $meta;
    }

    $info = array(
        'lcp' => array('Largest Contentful Paint', 'LCP', 'Render time of the largest image or text block visible in the viewport.'),
        'inp' => array('Interaction to Next Paint', 'INP', 'How quickly the page responds to clicks, taps and key presses.'),
        'cls' => array('Cumulative Layout Shift', 'CLS', 'The largest burst of unexpected layout shifts during the life of a page.')
    );
    $percentiles = array('p50' => 'P50', 'p75' => 'P75', 'p90' => 'P90', 'p99' => 'P99');
    $subpart_colors = array('#3b82f6', '#f59e0b', '#22c55e', '#ec4899');

    foreach ($info as $metric => $text):
        $m = $v['metrics'][$metric];
        $overall = CF_Live::rating($metric, $m['p75']);
        ?>
        <section class="cfa-card cfa-pad cfa-vital">
            <div class="cfa-card-head">
                <h3><?php echo esc_html($text[0]); ?> <span class="cfa-muted">(<?php echo esc_html($text[1]); ?>)</span></h3>
                <?php if ($overall): ?>
                    <span class="cfa-badge is-<?php echo esc_attr($overall); ?>"><?php echo esc_html($overall === 'good' ? 'Good' : ($overall === 'needs' ? 'Needs improvement' : 'Poor')); ?> at P75</span>
                <?php endif; ?>
            </div>
            <p class="cfa-hint cfa-vital-desc"><?php echo esc_html($text[2]); ?></p>

            <?php if (isset($m['good'])):
                $all = $m['good'] + $m['needs'] + $m['poor'];
                ?>
                <div class="cfa-dist">
                    <?php foreach (array('good' => 'Good', 'needs' => 'Needs improvement', 'poor' => 'Poor') as $k => $label): ?>
                        <div class="cfa-dist-item is-<?php echo esc_attr($k); ?>">
                            <span class="cfa-dist-label"><?php echo esc_html($label); ?></span>
                            <span class="cfa-dist-value"><?php echo esc_html(CF_Live::pct_text(cfa_share($m[$k], $all))); ?></span>
                            <span class="cfa-dist-bar"><i style="width: <?php echo esc_attr(round(cfa_share($m[$k], $all), 1)); ?>%"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="cfa-pctls">
                <?php foreach ($percentiles as $key => $label):
                    $rate = CF_Live::rating($metric, $m[$key]);
                    ?>
                    <div class="cfa-pctl">
                        <span class="cfa-pctl-label"><span class="cfa-dot <?php echo $rate ? 'is-' . esc_attr($rate) : ''; ?>"></span><?php echo esc_html($label); ?></span>
                        <span class="cfa-pctl-value"><?php echo esc_html(CF_Live::vital_text($metric, $m[$key])); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($m['subparts'])):
                $parts_total = array_sum($m['subparts']);
                $i = 0;
                ?>
                <div class="cfa-subparts">
                    <div class="cfa-subparts-title">Sub-parts <span class="cfa-muted">(P75)</span></div>
                    <div class="cfa-stack" role="img" aria-label="<?php echo esc_attr($text[1]); ?> sub-parts">
                        <?php foreach ($m['subparts'] as $label => $ms): ?>
                            <i style="width: <?php echo esc_attr(round(cfa_share($ms, $parts_total), 2)); ?>%; background: <?php echo esc_attr($subpart_colors[$i % 4]); ?>;" title="<?php echo esc_attr($label . ': ' . number_format_i18n($ms) . 'ms'); ?>"></i>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="cfa-stack-legend">
                        <?php $i = 0; foreach ($m['subparts'] as $label => $ms): ?>
                            <span><i style="background: <?php echo esc_attr($subpart_colors[$i % 4]); ?>;"></i><?php echo esc_html($label); ?> <b><?php echo esc_html(number_format_i18n($ms)); ?>ms</b> <em><?php echo esc_html(CF_Live::pct_text(cfa_share($ms, $parts_total))); ?></em></span>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
        <?php
    endforeach;

    // By page
    $pages = CF_Live::vitals_by_page($range);
    ?>
    <section class="cfa-card cfa-table-card">
        <div class="cfa-card-head"><h3>By page <span class="cfa-muted">(P75)</span></h3></div>
        <?php if (empty($pages['success'])): ?>
            <div class="cfa-empty">Per-page vitals aren&rsquo;t available right now. <?php echo esc_html(isset($pages['message']) ? $pages['message'] : ''); ?></div>
        <?php elseif (empty($pages['rows'])): ?>
            <div class="cfa-empty">No data for this period yet.</div>
        <?php else: ?>
            <table class="cfa-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th class="is-num">Samples</th>
                        <th class="is-num">LCP</th>
                        <th class="is-num">INP</th>
                        <th class="is-num">CLS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages['rows'] as $row): ?>
                        <tr>
                            <td class="is-main"><strong><?php echo esc_html($row['key'] === '' ? '/' : $row['key']); ?></strong></td>
                            <td class="is-num"><?php echo esc_html(cfa_num($row['count'])); ?></td>
                            <?php foreach (array('lcp', 'inp', 'cls') as $metric):
                                $rate = CF_Live::rating($metric, $row[$metric]);
                                ?>
                                <td class="is-num"><span class="cfa-dot <?php echo $rate ? 'is-' . esc_attr($rate) : ''; ?>"></span> <?php echo esc_html(CF_Live::vital_text($metric, $row[$metric])); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
    return $meta;
}
