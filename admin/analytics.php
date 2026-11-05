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
function eacfa_param($key, $default = '') {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
    $value = isset($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : $default;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    return $value;
}

function eacfa_has_param($key) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
    return isset($_GET[$key]);
}

/**
 * Version string for an asset: plugin version plus the file's modification time, so browsers
 * never keep serving an old stylesheet or script after the files change, even if the plugin
 * version number does not.
 */
function eacfa_asset_version($relative_path) {
    $file = EACFA_PLUGIN_DIR . $relative_path;

    return EACFA_VERSION . (file_exists($file) ? '-' . filemtime($file) : '');
}

/* ----------------------------------------------------------------------
 * Theme (light / dark / system), stored per user
 * -------------------------------------------------------------------- */

function eacfa_current_theme() {
    $theme = get_user_meta(get_current_user_id(), 'cf_analytics_theme', true);
    return in_array($theme, array('light', 'dark', 'auto'), true) ? $theme : 'light';
}

/**
 * Save the theme for the current user and confirm it was stored. Returns true on success.
 */
function eacfa_set_theme($theme) {
    if (!in_array($theme, array('light', 'dark', 'auto'), true)) {
        return false;
    }

    $user_id = get_current_user_id();

    $stored = function () use ($user_id, $theme) {
        wp_cache_delete($user_id, 'user_meta'); // read from the database, not a cached copy
        return get_user_meta($user_id, 'cf_analytics_theme', true) === $theme;
    };

    update_user_meta($user_id, 'cf_analytics_theme', $theme);
    if ($stored()) {
        return true;
    }

    // Try again from a clean state (row missing, or an outdated cached copy).
    delete_user_meta($user_id, 'cf_analytics_theme');
    wp_cache_delete($user_id, 'user_meta');
    add_user_meta($user_id, 'cf_analytics_theme', $theme, true);

    return $stored();
}

/* ----------------------------------------------------------------------
 * Primary colour: the one colour the site owner picks. The accent, the
 * chart lines and the bars are derived from it. Status colours (good,
 * needs improvement, poor) stay fixed so the Core Web Vitals ratings
 * keep their meaning.
 * -------------------------------------------------------------------- */

function eacfa_default_primary_color() {
    return '#5b21b6';
}

function eacfa_primary_color() {
    $saved = get_option('cf_analytics_primary_color', '');
    $color = is_string($saved) ? sanitize_hex_color($saved) : null;

    return $color ? $color : eacfa_default_primary_color();
}

/** "#rrggbb" to array(r, g, b), each 0 to 255 */
function eacfa_hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');

    return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

function eacfa_rgb_to_hex($rgb) {
    $hex = '#';
    foreach ($rgb as $channel) {
        $hex .= str_pad(dechex((int) round(max(0, min(255, $channel)))), 2, '0', STR_PAD_LEFT);
    }

    return $hex;
}

/** Blend $hex toward $target: 0 keeps the colour, 1 gives the target */
function eacfa_mix($hex, $target, $amount) {
    $from = eacfa_hex_to_rgb($hex);
    $to = eacfa_hex_to_rgb($target);
    $out = array();
    for ($i = 0; $i < 3; $i++) {
        $out[] = $from[$i] + ($to[$i] - $from[$i]) * $amount;
    }

    return eacfa_rgb_to_hex($out);
}

function eacfa_rgba($hex, $alpha) {
    $rgb = eacfa_hex_to_rgb($hex);

    return 'rgba(' . $rgb[0] . ', ' . $rgb[1] . ', ' . $rgb[2] . ', ' . $alpha . ')';
}

/** Readable text colour (dark or white) for text placed on $hex */
function eacfa_on_color($hex) {
    $rgb = eacfa_hex_to_rgb($hex);
    $luma = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;

    return $luma > 0.6 ? '#1f2430' : '#ffffff';
}

/** Hue (degrees), saturation and lightness (0 to 1) of a colour */
function eacfa_to_hsl($hex) {
    list($r, $g, $b) = eacfa_hex_to_rgb($hex);
    $r /= 255;
    $g /= 255;
    $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;

    if ($max === $min) {
        return array(0.0, 0.0, $l);
    }

    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

    if ($max === $r) {
        $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
    } elseif ($max === $g) {
        $h = ($b - $r) / $d + 2;
    } else {
        $h = ($r - $g) / $d + 4;
    }

    return array($h * 60, $s, $l);
}

function eacfa_hue_to_channel($p, $q, $t) {
    if ($t < 0) {
        $t += 1;
    }
    if ($t > 1) {
        $t -= 1;
    }
    if ($t < 1 / 6) {
        return $p + ($q - $p) * 6 * $t;
    }
    if ($t < 1 / 2) {
        return $q;
    }
    if ($t < 2 / 3) {
        return $p + ($q - $p) * (2 / 3 - $t) * 6;
    }

    return $p;
}

/** Hex colour from hue (degrees), saturation and lightness */
function eacfa_from_hsl($hue, $sat, $light) {
    $h = fmod(fmod($hue, 360) + 360, 360) / 360;
    $s = max(0, min(1, $sat));
    $l = max(0, min(1, $light));

    if ($s == 0) {
        $r = $l;
        $g = $l;
        $b = $l;
    } else {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $r = eacfa_hue_to_channel($p, $q, $h + 1 / 3);
        $g = eacfa_hue_to_channel($p, $q, $h);
        $b = eacfa_hue_to_channel($p, $q, $h - 1 / 3);
    }

    return eacfa_rgb_to_hex(array($r * 255, $g * 255, $b * 255));
}

/**
 * Every colour the plugin draws with, derived from the primary colour. Cached for the request.
 */
function eacfa_palette() {
    static $palette = null;

    if ($palette !== null) {
        return $palette;
    }

    $primary = eacfa_primary_color();
    list($hue, $sat, $light) = eacfa_to_hsl($primary);
    $dark_accent = eacfa_mix($primary, '#ffffff', 0.25);

    // Lines and bars must stay visible on both light and dark backgrounds
    $line_light = min(max($light, 0.48), 0.62);

    $palette = array(
        'light' => array(
            'accent'       => $primary,
            'accent_hover' => eacfa_mix($primary, '#000000', 0.12),
            'accent_soft'  => eacfa_rgba($primary, 0.1),
            'accent_ink'   => eacfa_on_color($primary),
        ),
        'dark' => array(
            'accent'       => $dark_accent,
            'accent_hover' => eacfa_mix($dark_accent, '#ffffff', 0.12),
            'accent_soft'  => eacfa_rgba($primary, 0.24),
            'accent_ink'   => eacfa_on_color($dark_accent),
        ),
        'series_visits' => eacfa_from_hsl($hue, $sat, $line_light),
        // Page views: a hue-shifted sibling. For a grey primary, lightness tells the two lines apart instead.
        'series_views'  => $sat < 0.12
            ? eacfa_from_hsl($hue, 0, min($line_light + 0.25, 0.8))
            : eacfa_from_hsl($hue + 60, $sat * 0.8, $line_light),
        'subparts'      => array(
            eacfa_from_hsl($hue, $sat, 0.5),
            eacfa_from_hsl($hue, $sat, 0.68),
            eacfa_from_hsl($hue, $sat, 0.34),
            eacfa_from_hsl($hue, $sat, 0.82),
        ),
    );

    return $palette;
}

/**
 * CSS custom properties for the accent, added after admin.css so they take effect.
 * Only the accent group lives here; neutral and status tokens are in admin.css.
 */
function eacfa_palette_css() {
    $p = eacfa_palette();
    $vars = function ($t) {
        return '--cfa-accent:' . $t['accent'] . ';--cfa-accent-hover:' . $t['accent_hover']
            . ';--cfa-accent-soft:' . $t['accent_soft'] . ';--cfa-accent-ink:' . $t['accent_ink'] . ';';
    };

    return '.cfa-wrap,#cf_analytics_widget{' . $vars($p['light']) . '}'
        . 'body.cfa-theme-dark .cfa-wrap,body.cfa-theme-dark #cf_analytics_widget{' . $vars($p['dark']) . '}'
        . '@media (prefers-color-scheme: dark){'
        . 'body.cfa-theme-auto .cfa-wrap,body.cfa-theme-auto #cf_analytics_widget{' . $vars($p['dark']) . '}}';
}

add_filter('admin_body_class', function ($classes) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if ($screen && ($screen->id === 'dashboard' || strpos($screen->id, 'cf-analytics') !== false)) {
        $classes .= ' cfa-theme-' . eacfa_current_theme();
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
        'eacfa_analytics_page',
        1
    );
});

/* ----------------------------------------------------------------------
 * Assets + request handlers
 * -------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', function ($hook) {
    $is_plugin_page = strpos($hook, 'cf-analytics') !== false;

    if ($is_plugin_page || $hook === 'index.php') {
        wp_enqueue_style('cf-analytics-admin', EACFA_PLUGIN_URL . 'assets/css/admin.css', array(), eacfa_asset_version('assets/css/admin.css'));
        wp_add_inline_style('cf-analytics-admin', eacfa_palette_css());
    }
	if ($is_plugin_page && eacfa_param('section') === 'settings') {
    wp_enqueue_style('wp-components');
    wp_enqueue_script(
        'eacfa-color',
        EACFA_PLUGIN_URL . 'assets/js/cfa-color.js',
        array('wp-element', 'wp-components', 'wp-i18n'),
        eacfa_asset_version('assets/js/cfa-color.js'),
        true
    );
}

    $section = eacfa_param('section', 'overview');
    $needs_chart = ($hook === 'index.php') || ($hook === 'dashboard_page_cf-analytics' && $section === 'overview');

    if ($needs_chart) {
        wp_enqueue_script('cfa-chart', EACFA_PLUGIN_URL . 'assets/js/cfa-chart.js', array(), eacfa_asset_version('assets/js/cfa-chart.js'), false);
    }
});

add_action('admin_init', function () {
    if (strpos(eacfa_param('page'), 'cf-analytics') !== 0 || !current_user_can('manage_options')) {
        return;
    }

    // Refresh data
    if (eacfa_has_param('eacfa_refresh')) {
        check_admin_referer('eacfa_refresh');
        EACFA_Live::refresh_cache();
        wp_safe_redirect(remove_query_arg(array('eacfa_refresh', '_wpnonce')));
        exit;
    }

    // Quick theme switch
    if (eacfa_has_param('eacfa_theme')) {
        check_admin_referer('eacfa_theme');
        $saved = eacfa_set_theme(eacfa_param('eacfa_theme'));
        $url = remove_query_arg(array('eacfa_theme', '_wpnonce'));
        wp_safe_redirect($saved ? $url : add_query_arg('eacfa_notice', 'save_failed', $url));
        exit;
    }
});

/* ----------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------- */

function eacfa_sections() {
    return array(
        'overview'   => array('Overview', 'dashicons-chart-area'),
        'pages'      => array('Pages', 'dashicons-admin-page'),
        'referrers'  => array('Referrers', 'dashicons-share'),
        'countries'  => array('Countries', 'dashicons-admin-site-alt3'),
        'technology' => array('Technology', 'dashicons-desktop'),
        'vitals'     => array('Core Web Vitals', 'dashicons-performance')
    );
}

function eacfa_url($args = array()) {
    return EACFA_Live::dashboard_url($args);
}

function eacfa_num($n) {
    return number_format_i18n((float) $n);
}

function eacfa_total($rows, $key = 'views') {
    $sum = 0;
    foreach ($rows as $row) {
        $sum += $row[$key];
    }
    return $sum;
}

function eacfa_share($value, $total) {
    return $total > 0 ? ($value / $total) * 100 : 0;
}

function eacfa_error($res, $inline = false) {
    $msg = isset($res['message']) ? $res['message'] : 'Something went wrong.';
    $class = $inline ? 'cfa-empty is-error' : 'cfa-notice is-error';
    echo '<div class="' . esc_attr($class) . '"><strong>Couldn&rsquo;t load this data.</strong> ' . esc_html($msg) . ' <a href="' . esc_url(eacfa_url(array('section' => 'settings'))) . '">Check settings</a></div>';
}

function eacfa_country_label($code) {
    return ($code === '') ? 'Unknown' : EACFA_API::get_country_name($code);
}

/**
 * Ranked list with share bars
 */
function eacfa_list_card($title, $res, $link, $label_cb, $limit = 6) {
    ?>
    <section class="cfa-card">
        <div class="cfa-card-head">
            <h3><?php echo esc_html($title); ?></h3>
            <?php if ($link): ?>
                <a class="cfa-link" href="<?php echo esc_url($link); ?>">View all</a>
            <?php endif; ?>
        </div>
        <?php if (empty($res['success'])): ?>
            <?php eacfa_error($res, true); ?>
        <?php elseif (empty($res['rows'])): ?>
            <div class="cfa-empty">No data for this period yet.</div>
        <?php else:
            $total = eacfa_total($res['rows']);
            $max = max(1, $res['rows'][0]['views']);
            ?>
            <ul class="cfa-list">
                <?php foreach (array_slice($res['rows'], 0, $limit) as $row): ?>
                    <li>
                        <span class="cfa-list-bar" style="width: <?php echo esc_attr(round(($row['views'] / $max) * 100, 1)); ?>%"></span>
                        <span class="cfa-list-label"><?php echo wp_kses_post(call_user_func($label_cb, $row)); ?></span>
                        <span class="cfa-list-value"><?php echo esc_html(eacfa_num($row['views'])); ?> <em><?php echo esc_html(EACFA_Live::pct_text(eacfa_share($row['views'], $total))); ?></em></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
}

function eacfa_views_cell($row, $max, $total) {
    ?>
    <td class="is-bar">
        <span class="cfa-bar"><i style="width: <?php echo esc_attr(round(($row['views'] / $max) * 100, 1)); ?>%"></i></span>
        <span class="cfa-bar-num"><?php echo esc_html(eacfa_num($row['views'])); ?> <em><?php echo esc_html(EACFA_Live::pct_text(eacfa_share($row['views'], $total))); ?></em></span>
    </td>
    <?php
}

/**
 * Table for pages / referrers / countries
 */
function eacfa_table_card($res, $first_col, $label_cb) {
    if (empty($res['success'])) {
        eacfa_error($res);
        return;
    }

    if (empty($res['rows'])) {
        echo '<div class="cfa-empty">No data for this period yet.</div>';
        return;
    }

    $total = eacfa_total($res['rows']);
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
                        <?php eacfa_views_cell($row, $max, $total); ?>
                        <td class="is-num"><?php echo esc_html(eacfa_num($row['visits'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
}

function eacfa_page_label($row) {
    if (!empty($row['title'])) {
        return '<strong>' . esc_html($row['title']) . '</strong><small>' . esc_html($row['key']) . '</small>';
    }
    return '<strong>' . esc_html($row['key'] === '' ? '/' : $row['key']) . '</strong>';
}

function eacfa_referrer_label($row) {
    return '<strong>' . esc_html($row['key'] === '' ? 'Direct / none' : $row['key']) . '</strong>';
}


/**
 * Chart code, printed once per page. eacfaChartInit() can be called again after a section has been
 * injected by the deferred loader.
 */
function eacfa_chart_script() {
    ?>
    <script>
    window.eacfaChartInit = function () {
        var el = document.getElementById('cfa-chart');
        var dataEl = document.getElementById('cfa-chart-data');
        if (!el || !dataEl || typeof EacfaChart === 'undefined') { return; }

        var d = JSON.parse(dataEl.textContent);

        if (el._cfaChart) { el._cfaChart.destroy(); }

        var chart = new EacfaChart(el, {
            label: 'Visits and page views over time',
            labels: d.labels,
            datasets: [
                { label: 'Visits',     data: d.visits, color: '<?php echo esc_js(eacfa_palette()['series_visits']); ?>' },
                { label: 'Page views', data: d.views,  color: '<?php echo esc_js(eacfa_palette()['series_views']); ?>' }
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

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', window.eacfaChartInit); } else { window.eacfaChartInit(); }
    </script>
    <?php
}

/**
 * Placeholder shown while a section loads in the background
 */
function eacfa_skeleton() {
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
function eacfa_render_section($section, $range) {
    switch ($section) {
        case 'pages':
            return eacfa_render_pages($range);
        case 'referrers':
            return eacfa_render_referrers($range);
        case 'countries':
            return eacfa_render_countries($range);
        case 'technology':
            return eacfa_render_technology($range);
        case 'vitals':
            return eacfa_render_vitals($range);
        default:
            return eacfa_render_overview($range);
    }
}

function eacfa_updated_text($meta) {
    return !empty($meta['fetched_at']) ? 'updated ' . human_time_diff($meta['fetched_at'], time()) . ' ago' : '';
}

/**
 * AJAX: render a section with live fetching (used when the page had to defer it)
 */
add_action('wp_ajax_cf_analytics_section', function () {
    check_ajax_referer('cf_analytics_section', 'nonce');

    if (!current_user_can('manage_options') || !EACFA_API::is_configured()) {
        wp_send_json_error('Unauthorized');
    }

    $sections = eacfa_sections();
    $section = isset($_POST['section']) ? sanitize_key(wp_unslash($_POST['section'])) : 'overview';
    if (!isset($sections[$section])) {
        $section = 'overview';
    }
    $range = EACFA_Live::sanitize_range(isset($_POST['range']) ? sanitize_key(wp_unslash($_POST['range'])) : '', '7days');

    ignore_user_abort(true);

    ob_start();
    $meta = eacfa_render_section($section, $range);
    $html = ob_get_clean();

    // Anything that had to be served from stale cache is refreshed by the browser right after.
    $jobs = EACFA_Live::queued_jobs();
    EACFA_Live::clear_queue();

    wp_send_json_success(array(
        'html'          => $html,
        'updated'       => eacfa_updated_text($meta),
        'jobs'          => $jobs,
        'refresh_nonce' => wp_create_nonce('cf_analytics_refresh')
    ));
});

/* ----------------------------------------------------------------------
 * Page
 * -------------------------------------------------------------------- */

function eacfa_analytics_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $configured = EACFA_API::is_configured();
    $settings = get_option('cf_analytics_settings', array());
    $sections = eacfa_sections();
    $section = eacfa_param('section', 'overview');
    if ($section !== 'settings' && !isset($sections[$section])) {
        $section = 'overview';
    }
    // Until Cloudflare is connected there is nothing to show but the setup screen.
    if (!$configured) {
        $section = 'settings';
    }
    $is_settings = ($section === 'settings');

    $range = EACFA_Live::sanitize_range(eacfa_param('range'), '7days');
    $ranges = EACFA_Live::ranges();

    $deferred = false;

    $theme = eacfa_current_theme();
    $args = array('section' => $section, 'range' => $range);
    $to_dark_url = wp_nonce_url(eacfa_url(array_merge($args, array('eacfa_theme' => 'dark'))), 'eacfa_theme');
    $to_light_url = wp_nonce_url(eacfa_url(array_merge($args, array('eacfa_theme' => 'light'))), 'eacfa_theme');
    $theme_url = ($theme === 'dark') ? $to_light_url : $to_dark_url;
    $theme_title = ($theme === 'dark') ? 'Switch to light mode' : (($theme === 'light') ? 'Switch to dark mode' : 'Switch between light and dark mode');
    $refresh_url = wp_nonce_url(eacfa_url(array_merge($args, array('eacfa_refresh' => 1))), 'eacfa_refresh');
    $settings_url = eacfa_url(array('section' => 'settings', 'range' => $range));

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
                        <a class="cfa-nav-link<?php echo $key === $section ? ' is-active' : ''; ?>" href="<?php echo esc_url(eacfa_url(array('section' => $key, 'range' => $range))); ?>">
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
                                Cloudflare Web Analytics &middot; version <?php echo esc_html(EACFA_VERSION); ?>
                            <?php else: ?>
                                <?php echo esc_html($ranges[$range]); ?> &middot; <?php echo esc_html(EACFA_Live::tz_label()); ?>
                                <span id="cfa-updated"></span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="cfa-head-center">
                        <?php if (!$is_settings): ?>
                            <div class="cfa-seg" role="group" aria-label="Date range">
                                <?php foreach ($ranges as $key => $label): ?>
                                    <a class="<?php echo $key === $range ? 'is-active' : ''; ?>" href="<?php echo esc_url(eacfa_url(array('section' => $section, 'range' => $key))); ?>"><?php echo esc_html($label); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="cfa-tools">
                        <?php if ($configured): ?>
                            <span class="cfa-live" title="Data is read live from Cloudflare"><i></i> Live</span>
                        <?php endif; ?>
                        <a class="cfa-icon-btn cfa-theme-toggle" href="<?php echo esc_url($theme_url); ?>" data-to-dark="<?php echo esc_url($to_dark_url); ?>" data-to-light="<?php echo esc_url($to_light_url); ?>" title="<?php echo esc_attr($theme_title); ?>" aria-label="<?php echo esc_attr($theme_title); ?>">
                            <svg class="cfa-ico-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                            <svg class="cfa-ico-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
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
                if (eacfa_param('eacfa_notice') === 'save_failed') {
                    ?>
                    <div class="cfa-notice is-error"><strong>Couldn&rsquo;t save that setting.</strong> Please try again, or check your connection in Settings.</div>
                    <?php
                }

                if ($is_settings) {
                    eacfa_render_settings();
                } else {
                    // The first render reads the cache only (fresh or stale), so the page never waits on
                    // Cloudflare. If something isn't cached yet, the output is discarded and the section is
                    // loaded right after the page appears.
                    EACFA_Live::$cache_only_mode = true;
                    EACFA_Live::$missed = false;
                    ob_start();
                    $meta = eacfa_render_section($section, $range);
                    EACFA_Live::$cache_only_mode = false;

                    if (EACFA_Live::$missed) {
                        ob_end_clean();
                        $deferred = true;
                        EACFA_Live::clear_queue(); // the loader below fetches everything itself
                        echo '<div id="cfa-deferred">';
                        eacfa_skeleton();
                        echo '</div>';
                    } else {
                        ob_end_flush();
                    }

                    $updated = $deferred ? 'loading…' : eacfa_updated_text($meta);
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
                    <p class="cfa-foot">Cloudflare Web Analytics, read from Cloudflare and refreshed in the background <?php echo esc_html(EACFA_Live::interval_phrase()); ?>. Cloudflare samples this data, so small differences from its dashboard are normal. Times use your WordPress timezone.</p>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php
    ?>
    <script>
    (function () {
        var link = document.querySelector('.cfa-theme-toggle');
        if (!link) { return; }

        // With the "System" theme the page follows the device, so decide at click time.
        link.addEventListener('click', function () {
            var body = document.body;
            var dark = body.classList.contains('cfa-theme-dark') ||
                (body.classList.contains('cfa-theme-auto') && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            link.href = dark ? link.getAttribute('data-to-light') : link.getAttribute('data-to-dark');
        });
    })();
    </script>
    <?php

    if ($section === 'overview') {
        eacfa_chart_script();
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
                            if (window.eacfaChartInit) { window.eacfaChartInit(); }

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

function eacfa_render_overview($range) {
    $series = EACFA_Live::series($range);
    $meta = array('fetched_at' => isset($series['fetched_at']) ? $series['fetched_at'] : 0);

    if (empty($series['success'])) {
        eacfa_error($series);
        return $meta;
    }

    $t = $series['totals'];
    $p = $series['prev'];
    $vitals = EACFA_Live::vitals($range);

    $load_change = ($p && $t['load_ms'] !== null && $p['load_ms'] !== null) ? EACFA_Live::change($t['load_ms'], $p['load_ms']) : null;

    $chart = array(
        'labels' => array_values(wp_list_pluck($series['points'], 'label')),
        'visits' => array_values(wp_list_pluck($series['points'], 'visits')),
        'views'  => array_values(wp_list_pluck($series['points'], 'views'))
    );
    ?>
    <section class="cfa-kpis is-four">
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Visits</div>
            <div class="cfa-kpi-value"><?php echo esc_html(eacfa_num($t['visits'])); ?></div>
            <div class="cfa-kpi-trend"><?php echo wp_kses(EACFA_Live::trend(EACFA_Live::change($t['visits'], $p ? $p['visits'] : null)), EACFA_Live::allowed_html()); ?></div>
        </div>
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Page views</div>
            <div class="cfa-kpi-value"><?php echo esc_html(eacfa_num($t['views'])); ?></div>
            <div class="cfa-kpi-trend"><?php echo wp_kses(EACFA_Live::trend(EACFA_Live::change($t['views'], $p ? $p['views'] : null)), EACFA_Live::allowed_html()); ?></div>
        </div>
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Page load time</div>
            <div class="cfa-kpi-value"><?php echo esc_html($t['load_ms'] === null ? "\xE2\x80\x94" : eacfa_num(round($t['load_ms'])) . 'ms'); ?></div>
            <div class="cfa-kpi-trend"><?php echo wp_kses(EACFA_Live::trend($load_change, true), EACFA_Live::allowed_html()); ?></div>
        </div>
        <div class="cfa-kpi">
            <div class="cfa-kpi-label">Core Web Vitals <span class="cfa-muted">(P75)</span></div>
            <?php if (!empty($vitals['success'])): ?>
                <div class="cfa-cwv-chips">
                    <?php foreach (array('lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS') as $m => $label):
                        $v = $vitals['metrics'][$m]['p75'];
                        $rate = EACFA_Live::rating($m, $v);
                        ?>
                        <a class="cfa-chip <?php echo $rate ? 'is-' . esc_attr($rate) : ''; ?>" href="<?php echo esc_url(eacfa_url(array('section' => 'vitals', 'range' => $range))); ?>">
                            <i></i><b><?php echo esc_html($label); ?></b> <?php echo esc_html(EACFA_Live::vital_text($m, $v)); ?>
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
                <button type="button" class="is-on" data-i="0"><i style="background:<?php echo esc_attr(eacfa_palette()['series_visits']); ?>"></i>Visits</button>
                <button type="button" class="is-on" data-i="1"><i style="background:<?php echo esc_attr(eacfa_palette()['series_views']); ?>"></i>Page views</button>
            </div>
        </div>
        <div class="cfa-chart"><div id="cfa-chart"></div></div>
    </section>

    <script type="application/json" id="cfa-chart-data"><?php echo wp_json_encode($chart); ?></script>

    <div class="cfa-grid">
        <?php
        eacfa_list_card('Top pages', EACFA_Live::top_pages($range, 6), eacfa_url(array('section' => 'pages', 'range' => $range)), 'eacfa_page_label');
        eacfa_list_card('Referrers', EACFA_Live::breakdown($range, 'refererHost', 20), eacfa_url(array('section' => 'referrers', 'range' => $range)), 'eacfa_referrer_label');
        eacfa_list_card('Countries', EACFA_Live::breakdown($range, 'countryName', 30), eacfa_url(array('section' => 'countries', 'range' => $range)), function ($row) {
            return '<strong>' . esc_html(eacfa_country_label($row['key'])) . '</strong>';
        });
        eacfa_list_card('Browsers', EACFA_Live::breakdown($range, 'userAgentBrowser', 20), eacfa_url(array('section' => 'technology', 'range' => $range)), function ($row) {
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

function eacfa_render_pages($range) {
    $res = EACFA_Live::top_pages($range, 50);
    eacfa_table_card($res, 'Page', 'eacfa_page_label');
    return array('fetched_at' => isset($res['fetched_at']) ? $res['fetched_at'] : 0);
}

function eacfa_render_referrers($range) {
    $res = EACFA_Live::breakdown($range, 'refererHost', 50);
    eacfa_table_card($res, 'Referrer', 'eacfa_referrer_label');
    return array('fetched_at' => isset($res['fetched_at']) ? $res['fetched_at'] : 0);
}

function eacfa_render_countries($range) {
    $res = EACFA_Live::breakdown($range, 'countryName', 100);
    eacfa_table_card($res, 'Country', function ($row) {
        return '<strong>' . esc_html(eacfa_country_label($row['key'])) . '</strong>' . ($row['key'] !== '' ? '<small>' . esc_html($row['key']) . '</small>' : '');
    });
    return array('fetched_at' => isset($res['fetched_at']) ? $res['fetched_at'] : 0);
}

function eacfa_render_technology($range) {
    $browsers = EACFA_Live::breakdown($range, 'userAgentBrowser', 20);
    $label = function ($row) {
        return '<strong>' . esc_html($row['key'] === '' ? 'Unknown' : $row['key']) . '</strong>';
    };

    echo '<div class="cfa-grid">';
    eacfa_list_card('Browsers', $browsers, '', $label, 12);
    eacfa_list_card('Operating systems', EACFA_Live::breakdown($range, 'userAgentOS', 20), '', $label, 12);
    echo '</div>';
    eacfa_list_card('Devices', EACFA_Live::breakdown($range, 'deviceType', 10), '', function ($row) {
        return '<strong>' . esc_html($row['key'] === '' ? 'Unknown' : ucfirst($row['key'])) . '</strong>';
    }, 10);

    return array('fetched_at' => isset($browsers['fetched_at']) ? $browsers['fetched_at'] : 0);
}

/* ----------------------------------------------------------------------
 * Core Web Vitals
 * -------------------------------------------------------------------- */

function eacfa_render_vitals($range) {
    $v = EACFA_Live::vitals($range);
    $meta = array('fetched_at' => isset($v['fetched_at']) ? $v['fetched_at'] : 0);

    if (empty($v['success'])) {
        eacfa_error($v);
        return $meta;
    }

    $info = array(
        'lcp' => array('Largest Contentful Paint', 'LCP', 'Render time of the largest image or text block visible in the viewport.'),
        'inp' => array('Interaction to Next Paint', 'INP', 'How quickly the page responds to clicks, taps and key presses.'),
        'cls' => array('Cumulative Layout Shift', 'CLS', 'The largest burst of unexpected layout shifts during the life of a page.')
    );
    $percentiles = array('p50' => 'P50', 'p75' => 'P75', 'p90' => 'P90', 'p99' => 'P99');
    $subpart_colors = eacfa_palette()['subparts'];

    foreach ($info as $metric => $text):
        $m = $v['metrics'][$metric];
        $overall = EACFA_Live::rating($metric, $m['p75']);
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
                            <span class="cfa-dist-value"><?php echo esc_html(EACFA_Live::pct_text(eacfa_share($m[$k], $all))); ?></span>
                            <span class="cfa-dist-bar"><i style="width: <?php echo esc_attr(round(eacfa_share($m[$k], $all), 1)); ?>%"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="cfa-pctls">
                <?php foreach ($percentiles as $key => $label):
                    $rate = EACFA_Live::rating($metric, $m[$key]);
                    ?>
                    <div class="cfa-pctl">
                        <span class="cfa-pctl-label"><span class="cfa-dot <?php echo $rate ? 'is-' . esc_attr($rate) : ''; ?>"></span><?php echo esc_html($label); ?></span>
                        <span class="cfa-pctl-value"><?php echo esc_html(EACFA_Live::vital_text($metric, $m[$key])); ?></span>
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
                            <i style="width: <?php echo esc_attr(round(eacfa_share($ms, $parts_total), 2)); ?>%; background: <?php echo esc_attr($subpart_colors[$i % 4]); ?>;" title="<?php echo esc_attr($label . ': ' . number_format_i18n($ms) . 'ms'); ?>"></i>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="cfa-stack-legend">
                        <?php $i = 0; foreach ($m['subparts'] as $label => $ms): ?>
                            <span><i style="background: <?php echo esc_attr($subpart_colors[$i % 4]); ?>;"></i><?php echo esc_html($label); ?> <b><?php echo esc_html(number_format_i18n($ms)); ?>ms</b> <em><?php echo esc_html(EACFA_Live::pct_text(eacfa_share($ms, $parts_total))); ?></em></span>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
        <?php
    endforeach;

    // By page
    $pages = EACFA_Live::vitals_by_page($range);
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
                            <td class="is-num"><?php echo esc_html(eacfa_num($row['count'])); ?></td>
                            <?php foreach (array('lcp', 'inp', 'cls') as $metric):
                                $rate = EACFA_Live::rating($metric, $row[$metric]);
                                ?>
                                <td class="is-num"><span class="cfa-dot <?php echo $rate ? 'is-' . esc_attr($rate) : ''; ?>"></span> <?php echo esc_html(EACFA_Live::vital_text($metric, $row[$metric])); ?></td>
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
