/**
 * Easy Analytics settings: options that save on change, zone picker and connection test.
 * Nonces come from the hidden #eacfa-settings-config element.
 */
(function ($) {
    'use strict';

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) { return null; }
        try { return JSON.parse(el.getAttribute('data-config')); } catch (e) { return null; }
    }

    var cfg = readConfig('eacfa-settings-config') || {};

    // Picking an option saves it straight away.
    function saveOnChange(selector) {
        document.querySelectorAll(selector).forEach(function (input) {
            input.addEventListener('change', function () { input.form.submit(); });
        });
    }

    saveOnChange('#cf-refresh-form input[type="radio"]');
    saveOnChange('#cf-appearance-form input[type="radio"]');

    $(function () {
        function esc(s) { return $('<div>').text(s).html(); }

        $('#cf-load-zones').on('click', function () {
            var token = $('#cf_api_token').val();
            var $btn = $(this).prop('disabled', true).text('Loading...');
            var $box = $('#cf-zones');

            $.post(ajaxurl, {
                action: 'cf_analytics_get_zones',
                nonce: cfg.zonesNonce,
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
                nonce: cfg.testNonce
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
})(jQuery);
