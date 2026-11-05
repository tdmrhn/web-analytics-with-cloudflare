<?php
/**
 * Settings: handlers and the settings section shown inside the Web Analytics dashboard
 * (Dashboard > Web Analytics > gear icon).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Saved token shown as asterisks plus its last four characters
 */
function eacfa_masked_token() {
    $credentials = EACFA_API::resolve_credentials();

    if (empty($credentials['token'])) {
        return '';
    }

    return str_repeat('*', 24) . substr($credentials['token'], -4);
}

function eacfa_is_settings_request() {
    return eacfa_param('page') === 'cf-analytics'
        && eacfa_param('section') === 'settings'
        && current_user_can('manage_options');
}

/**
 * Handle form submissions (runs before any output)
 */
add_action('admin_init', function() {
    if (!eacfa_is_settings_request()) {
        return;
    }

    // Connect / change token or zone
    if (isset($_POST['cf_analytics_save_settings'])) {
        check_admin_referer('cf_analytics_settings');

        $existing = get_option('cf_analytics_settings', array());
        $zone_id = sanitize_text_field(wp_unslash(isset($_POST['cf_zone_id']) ? $_POST['cf_zone_id'] : ''));
        $token = EACFA_API::clean_token(isset($_POST['cf_api_token']) ? sanitize_text_field(wp_unslash($_POST['cf_api_token'])) : '');
        $manual_tag = strtolower(trim(sanitize_text_field(wp_unslash(isset($_POST['cf_site_tag']) ? $_POST['cf_site_tag'] : ''))));

        // Leaving the token empty keeps the saved one (handy when only changing the zone).
        if ($token === '' && !empty($existing['api_token'])) {
            $token = (string) EACFA_Encryption::decrypt($existing['api_token']);
        }

        if ($token === '') {
            add_settings_error('cf_analytics', 'token_missing', 'Please paste your Cloudflare API token.');
            return;
        }

        if (!EACFA_API::is_tag($zone_id)) {
            add_settings_error('cf_analytics', 'zone_invalid', 'The Zone ID should be 32 characters (letters a-f and digits). Use "Load my zones" to pick it automatically.');
            return;
        }

        if ($manual_tag !== '' && !EACFA_API::is_tag($manual_tag)) {
            add_settings_error('cf_analytics', 'tag_invalid', 'The Web Analytics site tag should be 32 characters (letters a-f and digits), or leave it empty to detect it automatically.');
            return;
        }

        $api = new EACFA_API(array('type' => 'token', 'token' => $token));
        $d = $api->diagnose($zone_id, $manual_tag);

        // Token and zone are required; the Web Analytics steps may need follow-up permissions.
        if (empty($d['zone_name'])) {
            $failed = array();
            foreach ($d['checks'] as $c) {
                if (!$c['ok']) {
                    $failed[] = $c['label'] . ': ' . $c['detail'];
                }
            }
            add_settings_error('cf_analytics', 'credentials_invalid', 'Could not connect to Cloudflare. ' . implode(' ', $failed));
            return;
        }

        $saved = EACFA_Live::save_option('cf_analytics_settings', array(
            'auth_type'   => 'token',
            'api_token'   => EACFA_Encryption::encrypt($token),
            'zone_id'     => $zone_id,
            'zone_name'   => $d['zone_name'],
            'account_id'  => $d['account_id'],
            'site_tag'    => $d['site_tag'],
            'site_host'   => $d['site_host'],
            'site_manual' => ($manual_tag !== '')
        ));

        if (!$saved['ok']) {
            add_settings_error('cf_analytics', 'save_failed', 'Connected to Cloudflare, but the settings could not be saved: ' . $saved['error']);
            return;
        }

        EACFA_Live::refresh_cache();

        if ($d['ok']) {
            add_settings_error('cf_analytics', 'settings_saved', 'Connected. Web Analytics data is readable.', 'success');
        } else {
            $failed = array();
            foreach ($d['checks'] as $c) {
                if (!$c['ok']) {
                    $failed[] = $c['label'] . ': ' . $c['detail'];
                }
            }
            add_settings_error('cf_analytics', 'needs_followup', 'Saved, but one step needs attention. ' . implode(' ', $failed), 'warning');
        }
        return;
    }

    // Disconnect
    if (isset($_POST['cf_analytics_disconnect'])) {
        check_admin_referer('cf_analytics_disconnect');
        if (EACFA_Live::remove_option('cf_analytics_settings')) {
            EACFA_Live::refresh_cache();
            add_settings_error('cf_analytics', 'disconnected', 'Disconnected. Your token has been removed from this site.', 'success');
        } else {
            add_settings_error('cf_analytics', 'disconnect_failed', 'The token could not be removed from the database.');
        }
        return;
    }

    // Clear cache
    if (isset($_POST['cf_analytics_refresh_cache'])) {
        check_admin_referer('cf_analytics_refresh_cache');
        EACFA_Live::refresh_cache();
        add_settings_error('cf_analytics', 'cache_cleared', 'Cache cleared. The next view fetches fresh data from Cloudflare.', 'success');
        return;
    }

    // Refresh interval
    if (isset($_POST['cf_analytics_save_refresh'])) {
        check_admin_referer('cf_analytics_refresh_interval');
        $value = isset($_POST['cf_analytics_interval']) ? (int) $_POST['cf_analytics_interval'] : EACFA_Live::TTL_LIVE;
        if (!array_key_exists($value, EACFA_Live::intervals())) {
            $value = EACFA_Live::TTL_LIVE;
        }
        $saved = EACFA_Live::save_option('cf_analytics_refresh_interval', $value);

        if ($saved['ok']) {
            wp_safe_redirect(EACFA_Live::dashboard_url(array('section' => 'settings', 'eacfa_saved' => 'interval')));
            exit;
        }

        add_settings_error('cf_analytics', 'interval_failed', 'The refresh setting could not be saved: ' . $saved['error']);
        return;
    }

    // Primary colour (site-wide)
    if (isset($_POST['cf_analytics_save_primary']) || isset($_POST['cf_analytics_reset_primary'])) {
        check_admin_referer('cf_analytics_primary_color');

        $color = isset($_POST['cf_analytics_reset_primary'])
            ? eacfa_default_primary_color()
            : sanitize_hex_color(isset($_POST['cf_analytics_primary_color']) ? wp_unslash($_POST['cf_analytics_primary_color']) : '');

        if (!$color) {
            add_settings_error('cf_analytics', 'color_invalid', 'Please pick a valid color.');
            return;
        }

        $saved = EACFA_Live::save_option('cf_analytics_primary_color', $color);
        if ($saved['ok']) {
            wp_safe_redirect(EACFA_Live::dashboard_url(array('section' => 'settings', 'eacfa_saved' => 'color')));
            exit;
        }

        add_settings_error('cf_analytics', 'color_failed', 'The color could not be saved. Please try again.');
        return;
    }

    // Appearance (per user)
    if (isset($_POST['cf_analytics_save_appearance'])) {
        check_admin_referer('cf_analytics_appearance');

        if (eacfa_set_theme(isset($_POST['cf_analytics_theme']) ? sanitize_key($_POST['cf_analytics_theme']) : 'light')) {
            wp_safe_redirect(EACFA_Live::dashboard_url(array('section' => 'settings', 'eacfa_saved' => 'appearance')));
            exit;
        }

        add_settings_error('cf_analytics', 'appearance_failed', 'Your appearance setting could not be saved.');
        return;
    }
});

/**
 * AJAX: list zones for the token typed into the form (or the saved one)
 */
add_action('wp_ajax_cf_analytics_get_zones', function() {
    check_ajax_referer('cf_analytics_get_zones', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $token = EACFA_API::clean_token(isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '');
    $credentials = null;

    if ($token !== '') {
        $credentials = array('type' => 'token', 'token' => $token);
    } elseif (!EACFA_API::resolve_credentials()) {
        wp_send_json_error('Paste your API token first.');
    }

    $api = new EACFA_API($credentials);
    $result = $api->get_zones();

    if (empty($result['success'])) {
        wp_send_json_error($result['message']);
    }

    $host = preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
    $zones = array();
    $match = '';

    foreach ($result['data'] as $zone) {
        $zones[] = array('id' => $zone['id'], 'name' => $zone['name']);
        if ($match === '' && $host && ($host === $zone['name'] || substr($host, -strlen('.' . $zone['name'])) === '.' . $zone['name'])) {
            $match = $zone['id'];
        }
    }

    wp_send_json_success(array('zones' => $zones, 'match' => $match));
});

/**
 * AJAX: run the connection checks with the saved settings
 */
add_action('wp_ajax_cf_analytics_test_connection', function() {
    check_ajax_referer('cf_analytics_test_connection', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $settings = get_option('cf_analytics_settings', array());
    $api = new EACFA_API();
    $manual = (!empty($settings['site_manual']) && !empty($settings['site_tag'])) ? $settings['site_tag'] : '';
    $d = $api->diagnose(isset($settings['zone_id']) ? $settings['zone_id'] : '', $manual);

    // Remember a newly discovered site so the dashboard can use it.
    if (!empty($d['site_tag']) && !empty($d['account_id'])) {
        $settings['account_id'] = $d['account_id'];
        $settings['site_tag'] = $d['site_tag'];
        if ($d['site_host'] !== '') {
            $settings['site_host'] = $d['site_host'];
        }
        EACFA_Live::save_option('cf_analytics_settings', $settings);
        delete_transient('eacfa_ctx_fail');
    }

    if ($d['ok']) {
        delete_transient('cf_analytics_last_error');
    }

    wp_send_json_success(array('ok' => $d['ok'], 'checks' => $d['checks']));
});

/**
 * The settings section (rendered inside the dashboard shell)
 */
function eacfa_render_settings() {
    $settings = get_option('cf_analytics_settings', array());
    $is_configured = EACFA_API::is_configured();
    $last_error = get_transient('cf_analytics_last_error');
    $theme = eacfa_current_theme();
    $masked = eacfa_masked_token();
    // A link the user clicks to open their Cloudflare dashboard. Nothing is loaded from it.
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
    $tokens_url = 'https://dash.cloudflare.com/profile/api-tokens';

    settings_errors('cf_analytics');
    $saved_notice = eacfa_param('eacfa_saved');
    if ($saved_notice === 'appearance') {
        echo '<div class="cfa-notice is-success">Appearance saved.</div>';
    } elseif ($saved_notice === 'interval') {
        echo '<div class="cfa-notice is-success">Refresh setting saved.</div>';
    } elseif ($saved_notice === 'color') {
        echo '<div class="cfa-notice is-success">Accent color saved.</div>';
    }
    ?>
    <div class="cfa-settings-inner">
        <?php if ($is_configured): ?>
            <section class="cfa-card cfa-pad">
                <div class="cfa-card-head">
                    <h3>Connection</h3>
                    <span class="cfa-badge is-green">Connected</span>
                </div>

                <dl class="cfa-dl">
                    <dt>Zone</dt>
                    <dd><?php echo esc_html(!empty($settings['zone_name']) ? $settings['zone_name'] : 'Unknown'); ?></dd>
                    <dt>Web Analytics site</dt>
                    <dd>
                        <?php if (!empty($settings['site_tag'])): ?>
                            <?php echo esc_html(!empty($settings['site_host']) ? $settings['site_host'] : 'Detected'); ?> <code><?php echo esc_html($settings['site_tag']); ?></code>
                        <?php else: ?>
                            <span class="cfa-muted">Not detected yet. Run the test below.</span>
                        <?php endif; ?>
                    </dd>
                    <dt>API token</dt>
                    <dd><code class="cfa-masked"><?php echo esc_html($masked); ?></code> <span class="cfa-muted">stored encrypted</span></dd>
                    <?php if (!empty($last_error['message'])): ?>
                        <dt>Last API error</dt>
                        <dd class="cfa-text-red"><?php echo esc_html($last_error['message']); ?> <span class="cfa-muted">&middot; <?php echo esc_html(human_time_diff($last_error['time'], time())); ?> ago</span></dd>
                    <?php endif; ?>
                </dl>

                <ul class="cfa-checks" id="cf-checks" hidden></ul>

                <div class="cfa-actions">
                    <button type="button" class="cfa-btn" id="cf-test-connection">Test connection</button>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('cf_analytics_refresh_cache'); ?>
                        <button type="submit" name="cf_analytics_refresh_cache" class="cfa-btn">Clear cache</button>
                    </form>
                </div>
                <p class="cfa-hint">Stats are read from Cloudflare and refreshed in the background <?php echo esc_html(EACFA_Live::interval_phrase()); ?>. Only the encrypted token and the IDs above are saved in your database.</p>
            </section>
        <?php endif; ?>

        <section class="cfa-card cfa-pad">
            <div class="cfa-card-head">
                <h3><?php echo $is_configured ? 'Change token or zone' : 'Connect Cloudflare'; ?></h3>
            </div>

            <ol class="cfa-steps">
                <li>
                    <strong>Turn on Web Analytics</strong>
                    <p class="cfa-hint">Enable it for your site in Cloudflare (your site &rarr; Speed &rarr; Real User Monitoring). This plugin only reads the data; it doesn&rsquo;t add the tracking snippet.</p>
                </li>
                <li>
                    <strong>Create an API token</strong>
                    <p class="cfa-hint">In <a href="<?php echo esc_url($tokens_url); ?>" target="_blank" rel="noopener">Cloudflare &rarr; My Profile &rarr; API Tokens</a> choose Create Token &rarr; Create Custom Token and add these permissions. You sign in with your passkey as usual; the plugin only needs the token.</p>
                    <div class="cfa-perms">
                        <span><b>Zone</b> &rsaquo; Zone &rsaquo; Read</span>
                        <span><b>Account</b> &rsaquo; Account Settings &rsaquo; Read</span>
                        <span><b>Account</b> &rsaquo; Account Analytics &rsaquo; Read</span>
                    </div>
                </li>
                <li><strong>Paste the token and pick your zone</strong></li>
            </ol>

            <form method="post" id="cf-connect-form">
                <?php wp_nonce_field('cf_analytics_settings'); ?>

                <div class="cfa-field">
                    <label for="cf_api_token"><?php echo $is_configured ? 'Replace API token' : 'API token'; ?></label>
                    <?php if ($masked !== ''): ?>
                        <p class="cfa-saved-token">Saved token: <code class="cfa-masked"><?php echo esc_html($masked); ?></code></p>
                    <?php endif; ?>
                    <input type="password" id="cf_api_token" name="cf_api_token" autocomplete="off" placeholder="<?php echo $is_configured ? 'Leave empty to keep the saved token' : 'Paste your token'; ?>">
                </div>

                <div class="cfa-field">
                    <label for="cf_zone_id">Zone</label>
                    <div class="cfa-inline">
                        <input type="text" id="cf_zone_id" name="cf_zone_id" required pattern="[A-Fa-f0-9]{32}" maxlength="32" placeholder="32-character Zone ID" value="<?php echo esc_attr(isset($settings['zone_id']) ? $settings['zone_id'] : ''); ?>">
                        <button type="button" class="cfa-btn" id="cf-load-zones">Load my zones</button>
                    </div>
                    <div id="cf-zones" class="cfa-zones" hidden></div>
                </div>

                <details class="cfa-advanced">
                    <summary>Advanced</summary>
                    <div class="cfa-field">
                        <label for="cf_site_tag">Web Analytics site tag</label>
                        <input type="text" id="cf_site_tag" name="cf_site_tag" maxlength="32" placeholder="Detected automatically" value="<?php echo esc_attr(!empty($settings['site_manual']) && !empty($settings['site_tag']) ? $settings['site_tag'] : ''); ?>">
                        <p class="cfa-hint">Only needed if the token can&rsquo;t read Account Settings. Find it in the Web Analytics snippet or the site list in Cloudflare.</p>
                    </div>
                </details>

                <button type="submit" name="cf_analytics_save_settings" class="cfa-btn is-primary"><?php echo $is_configured ? 'Save and test' : 'Connect'; ?></button>
            </form>
        </section>

        <section class="cfa-card cfa-pad">
            <div class="cfa-card-head"><h3>Data refresh</h3></div>
            <form method="post" id="cf-refresh-form">
                <?php wp_nonce_field('cf_analytics_refresh_interval'); ?>
                <input type="hidden" name="cf_analytics_save_refresh" value="1">
                <?php
                $short = array(300 => '5 min', 600 => '10 min', 1800 => '30 min', 3600 => '1 hour', 0 => 'Manual');
                $current = EACFA_Live::refresh_interval();
                ?>
                <div class="cfa-seg cfa-seg-radio" role="radiogroup" aria-label="Refresh data">
                    <?php foreach (EACFA_Live::intervals() as $seconds => $label): ?>
                        <label title="<?php echo esc_attr($label . ($seconds === EACFA_Live::TTL_LIVE ? ' (default)' : '')); ?>">
                            <input type="radio" name="cf_analytics_interval" value="<?php echo esc_attr($seconds); ?>" <?php checked($current, $seconds); ?>>
                            <span><?php echo esc_html($short[$seconds]); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="cfa-hint">Pages always open instantly with the data already saved. This sets how old that data may get before the next visit refreshes it in the background, after the page has loaded. Nothing runs on a timer. &ldquo;Manual&rdquo; keeps what you see until you click Refresh in the dashboard header (a new day still starts with a new lookup). The default is 10 min.</p>
                <noscript><button type="submit" class="cfa-btn">Save</button></noscript>
            </form>
            <script>
            document.querySelectorAll('#cf-refresh-form input[type="radio"]').forEach(function (input) {
                input.addEventListener('change', function () { input.form.submit(); });
            });
            </script>
        </section>

        <section class="cfa-card cfa-pad">
            <div class="cfa-card-head"><h3>Appearance</h3></div>
            <form method="post" id="cf-appearance-form">
                <?php wp_nonce_field('cf_analytics_appearance'); ?>
                <input type="hidden" name="cf_analytics_save_appearance" value="1">
                <div class="cfa-themes" role="radiogroup" aria-label="Theme">
                    <?php foreach (array('light' => 'Light', 'dark' => 'Dark', 'auto' => 'System') as $value => $label): ?>
                        <label class="cfa-theme">
                            <input type="radio" name="cf_analytics_theme" value="<?php echo esc_attr($value); ?>" <?php checked($theme, $value); ?>>
                            <span class="cfa-theme-swatch is-<?php echo esc_attr($value); ?>"><i></i><i></i><i></i></span>
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="cfa-hint">Saves as soon as you pick an option. Applies to the dashboard, the dashboard widget and this page, for your user only. &ldquo;System&rdquo; follows your device setting.</p>
                <noscript><button type="submit" class="cfa-btn">Save</button></noscript>
            </form>
            <script>
            document.querySelectorAll('#cf-appearance-form input[type="radio"]').forEach(function (input) {
                input.addEventListener('change', function () { input.form.submit(); });
            });
            </script>
        </section>

        <section class="cfa-card cfa-pad">
            <div class="cfa-card-head"><h3>Primary color</h3></div>
            <form method="post" id="cf-primary-form">
                <?php wp_nonce_field('cf_analytics_primary_color'); ?>
                <div class="cfa-field">
                    <label for="cf_analytics_primary_color">Accent color</label>
                    <div class="cfa-color-row">
						<div id="cfa-color-picker"></div>
						<input type="hidden" id="cf_analytics_primary_color" name="cf_analytics_primary_color" value="<?php echo esc_attr(eacfa_primary_color()); ?>">
                        <button type="submit" name="cf_analytics_save_primary" value="1" class="cfa-btn is-primary">Save color</button>
                        <button type="submit" name="cf_analytics_reset_primary" value="1" class="cfa-btn">Reset to default</button>
                    </div>
                </div>
                <p class="cfa-hint">Used for buttons, highlights and the chart lines. Every other tone is derived from this color. Good, needs improvement and poor keep their own colors so the Core Web Vitals ratings stay readable. Applies to everyone who uses this site's dashboard.</p>
            </form>
        </section>


        <?php if ($is_configured): ?>
            <section class="cfa-card cfa-pad">
                <div class="cfa-card-head"><h3>Disconnect</h3></div>
                <p>Removes the saved token and zone from this site. Your Cloudflare account is not changed; you can also revoke the token in Cloudflare.</p>
                <form method="post" onsubmit="return confirm('Disconnect Easy Analytics with Cloudflare RUM?');">
                    <?php wp_nonce_field('cf_analytics_disconnect'); ?>
                    <button type="submit" name="cf_analytics_disconnect" class="cfa-btn is-danger">Disconnect</button>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
    jQuery(function ($) {
        function esc(s) { return $('<div>').text(s).html(); }

        $('#cf-load-zones').on('click', function () {
            var token = $('#cf_api_token').val();
            var $btn = $(this).prop('disabled', true).text('Loading...');
            var $box = $('#cf-zones');

            $.post(ajaxurl, {
                action: 'cf_analytics_get_zones',
                nonce: '<?php echo esc_js(wp_create_nonce('cf_analytics_get_zones')); ?>',
                token: token
            }).done(function (res) {
                if (!res.success) { $box.prop('hidden', false).html('<div class="cfa-notice is-error">' + esc(res.data) + '</div>'); return; }

                var zones = res.data.zones;
                if (!zones.length) { $box.prop('hidden', false).html('<div class="cfa-notice is-error">This token cannot see any zones. Check its Zone Resources.</div>'); return; }

                var html = '';
                zones.forEach(function (z) {
                    html += '<button type="button" class="cfa-zone" data-id="' + esc(z.id) + '"><strong>' + esc(z.name) + '</strong><code>' + esc(z.id) + '</code></button>';
                });
                $box.prop('hidden', false).html(html);

                if (res.data.match) {
                    $('#cf_zone_id').val(res.data.match);
                    $box.find('[data-id="' + res.data.match + '"]').addClass('is-picked');
                }
            }).fail(function () {
                $box.prop('hidden', false).html('<div class="cfa-notice is-error">Request failed. Please try again.</div>');
            }).always(function () {
                $btn.prop('disabled', false).text('Load my zones');
            });
        });

        $('#cf-zones').on('click', '.cfa-zone', function () {
            $('#cf_zone_id').val($(this).data('id'));
            $(this).addClass('is-picked').siblings().removeClass('is-picked');
        });

        $('#cf-test-connection').on('click', function () {
            var $btn = $(this).prop('disabled', true).text('Testing...');
            var $list = $('#cf-checks').prop('hidden', true).empty();

            $.post(ajaxurl, {
                action: 'cf_analytics_test_connection',
                nonce: '<?php echo esc_js(wp_create_nonce('cf_analytics_test_connection')); ?>'
            }).done(function (res) {
                if (!res.success) { $list.prop('hidden', false).html('<li class="is-fail"><span>&#10007;</span><div><strong>Test failed</strong><small>' + esc(res.data) + '</small></div></li>'); return; }

                res.data.checks.forEach(function (c) {
                    $list.append('<li class="' + (c.ok ? 'is-ok' : 'is-fail') + '"><span>' + (c.ok ? '&#10003;' : '&#10007;') + '</span><div><strong>' + esc(c.label) + '</strong><small>' + esc(c.detail) + '</small></div></li>');
                });
                $list.prop('hidden', false);
            }).fail(function () {
                $list.prop('hidden', false).html('<li class="is-fail"><span>&#10007;</span><div><strong>Request failed</strong><small>Please try again.</small></div></li>');
            }).always(function () {
                $btn.prop('disabled', false).text('Test connection');
            });
        });
    });
    </script>
    <?php

    return array('fetched_at' => 0);
}
