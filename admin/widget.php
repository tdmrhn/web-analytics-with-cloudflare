<?php
/**
 * WordPress Dashboard Widget: Cloudflare Web Analytics, last 30 days
 *
 * The widget never waits on Cloudflare while the dashboard loads. It draws from the cache
 * straight away (even if the data is a little old). If the data is stale, or there is none yet,
 * it fetches fresh numbers in the background after the page has loaded and updates in place.
 */

if (!defined('ABSPATH')) {
    exit;
}

class EACFA_Analytics_Widget {

    public function __construct() {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('wp_ajax_cf_analytics_widget_data', array($this, 'ajax_data'));
    }

    public function add_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // The button lives in the title bar, next to WordPress's own collapse/move arrows.
        $title = '<span class="cfw-title">Analytics'
            . '<a class="cfw-open" href="' . esc_url(EACFA_Live::dashboard_url()) . '">Open Dashboard</a></span>';

        wp_add_dashboard_widget('cf_analytics_widget', $title, array($this, 'render_widget'));
    }

    /**
     * Data the widget needs, ready for JSON
     */
    private function payload($series) {
        $t = $series['totals'];
        $p = $series['prev'];

        return array(
            'labels' => array_values(wp_list_pluck($series['points'], 'label')),
            'visits' => array_values(wp_list_pluck($series['points'], 'visits')),
            'views'  => array_values(wp_list_pluck($series['points'], 'views')),
            'stats'  => array(
                'visits' => array(number_format_i18n($t['visits']), EACFA_Live::trend(EACFA_Live::change($t['visits'], $p ? $p['visits'] : null))),
                'views'  => array(number_format_i18n($t['views']), EACFA_Live::trend(EACFA_Live::change($t['views'], $p ? $p['views'] : null)))
            ),
            'stale'  => !empty($series['stale']),
            'age'    => isset($series['fetched_at']) ? max(0, time() - (int) $series['fetched_at']) : null
        );
    }

    /**
     * AJAX: the cached 30-day numbers, read from the cache only. Used to redraw the widget in place
     * after the background refresh has finished.
     */
    public function ajax_data() {
        check_ajax_referer('cf_analytics_widget_data', 'nonce');

        if (!current_user_can('manage_options') || !EACFA_API::is_configured()) {
            wp_send_json_error('Unauthorized');
        }

        $series = EACFA_Live::series('30days', true);

        if (empty($series['success'])) {
            wp_send_json_error(isset($series['message']) ? $series['message'] : 'Could not load analytics.');
        }

        wp_send_json_success($this->payload($series));
    }

    public function render_widget() {
        if (!EACFA_API::is_configured()) {
            ?>
            <div class="cfw-empty">
                <p>Connect Cloudflare to see your Web Analytics here.</p>
                <a class="cfw-open" href="<?php echo esc_url(EACFA_Live::dashboard_url(array('section' => 'settings'))); ?>">Connect now</a>
            </div>
            <?php
            return;
        }

        // Cache only: serve what we have (fresh or stale) without calling Cloudflare.
        EACFA_Live::queue_stale();
        $series = EACFA_Live::series('30days', true);

        $data = !empty($series['success']) ? $this->payload($series) : null;

        // A recent failure (not just an empty cache): show it instead of retrying on every load.
        if (!$data && empty($series['miss'])) {
            ?>
            <div class="cfw-empty">
                <p><?php echo esc_html(isset($series['message']) ? $series['message'] : 'Could not load analytics.'); ?></p>
                <a href="<?php echo esc_url(EACFA_Live::dashboard_url(array('section' => 'settings'))); ?>">Check settings</a>
            </div>
            <?php
            return;
        }

        wp_enqueue_script('eacfa-widget', EACFA_PLUGIN_URL . 'assets/js/cfa-widget.js', array('eacfa-chart'), eacfa_asset_version('assets/js/cfa-widget.js'), true);

        $labels = array('visits' => 'Visits', 'views' => 'Page views');
        ?>
        <div class="cfw<?php echo $data ? '' : ' is-loading'; ?>" id="cfw-root">
            <div class="cfw-chart"><div id="cfw-chart"></div></div>

            <div class="cfw-stats">
                <?php foreach ($labels as $key => $label): ?>
                    <div class="cfw-stat" data-key="<?php echo esc_attr($key); ?>">
                        <span class="cfw-pill"><?php echo esc_html($label); ?></span>
                        <div class="cfw-num"><?php echo $data ? esc_html($data['stats'][$key][0]) : '<span class="cfw-skel"></span>'; ?></div>
                        <div class="cfw-trend"><?php echo $data ? wp_kses($data['stats'][$key][1], EACFA_Live::allowed_html()) : ''; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="cfw-updated" id="cfw-updated"><?php echo $data && $data['age'] !== null ? esc_html('Updated ' . ($data['age'] < 60 ? 'just now' : human_time_diff(time() - $data['age'], time()) . ' ago')) : 'Loading&hellip;'; ?></p>
            <p class="cfw-error" id="cfw-error" hidden></p>
        </div>

        <?php EACFA_Live::config_node('eacfa-widget-config', array(
            'initial' => $data,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cf_analytics_widget_data'),
            'colors'  => array(
                'visits' => eacfa_palette()['series_visits'],
                'views'  => eacfa_palette()['series_views'],
            ),
        )); ?>
        <?php
    }
}

new EACFA_Analytics_Widget();
