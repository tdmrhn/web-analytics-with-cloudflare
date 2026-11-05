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
     * AJAX: fetch fresh data (this is what updates the cache after the page has loaded)
     */
    public function ajax_data() {
        check_ajax_referer('cf_analytics_widget_data', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $series = EACFA_Live::series('30days', false, true);

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
        EACFA_Live::$no_queue = true;
        $series = EACFA_Live::series('30days', true);
        EACFA_Live::$no_queue = false;

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

        <script>
        (function () {
            var initial = <?php echo wp_json_encode($data); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('cf_analytics_widget_data')); ?>;
            var chart = null;

            var fetchedAt = null; // client clock time the data was fetched (server age subtracted)

            function ago(seconds) {
                if (seconds < 60) { return 'just now'; }
                if (seconds < 3600) { return Math.floor(seconds / 60) + ' min ago'; }
                if (seconds < 86400) { return Math.floor(seconds / 3600) + ' h ago'; }
                return Math.floor(seconds / 86400) + ' d ago';
            }

            function tick() {
                var el = document.getElementById('cfw-updated');
                if (el && fetchedAt !== null) { el.textContent = 'Updated ' + ago((Date.now() - fetchedAt) / 1000); }
            }

            function render(d) {
                var root = document.getElementById('cfw-root');
                if (!root) { return; }

                if (d.age !== null && d.age !== undefined) { fetchedAt = Date.now() - d.age * 1000; tick(); }

                root.classList.remove('is-loading');
                document.getElementById('cfw-error').hidden = true;

                ['visits', 'views'].forEach(function (key) {
                    var cell = root.querySelector('.cfw-stat[data-key="' + key + '"]');
                    if (!cell) { return; }
                    cell.querySelector('.cfw-num').textContent = d.stats[key][0];
                    cell.querySelector('.cfw-trend').innerHTML = d.stats[key][1];
                });

                var el = document.getElementById('cfw-chart');
                if (!el || typeof EacfaChart === 'undefined') { return; }

                if (chart) {
                    chart.update({ labels: d.labels, datasets: [{ data: d.views }, { data: d.visits }] });
                    return;
                }

                chart = new EacfaChart(el, {
                    label: 'Visits and page views, last 30 days',
                    labels: d.labels,
                    datasets: [
                        { label: 'Page views', data: d.views,  color: '<?php echo esc_js(eacfa_palette()['series_views']); ?>' },
                        { label: 'Visits',     data: d.visits, color: '<?php echo esc_js(eacfa_palette()['series_visits']); ?>' }
                    ],
                    dotRadius: 4,
                    lineWidth: 4,
                    fontSize: 13,
                    maxXTicks: 6
                });
            }

            function refresh() {
                var body = new URLSearchParams();
                body.append('action', 'cf_analytics_widget_data');
                body.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            render(res.data);
                        } else if (!initial) {
                            var err = document.getElementById('cfw-error');
                            err.textContent = (res && res.data) ? res.data : 'Could not load analytics.';
                            err.hidden = false;
                            document.getElementById('cfw-root').classList.remove('is-loading');
                        }
                    })
                    .catch(function () {});
            }

            function draw() {
                if (initial) { render(initial); }
            }

            // Stale or missing data: update after the page has finished loading.
            function update() {
                if ((!initial || initial.stale) && window.fetch && window.URLSearchParams) {
                    setTimeout(refresh, 300);
                }
            }

            setInterval(tick, 30000);

            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', draw); } else { draw(); }
            if (document.readyState === 'complete') { update(); } else { window.addEventListener('load', update); }
        })();
        </script>
        <?php
    }
}

new EACFA_Analytics_Widget();
