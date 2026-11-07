/**
 * Easy Analytics dashboard: theme toggle, Refresh button, chart setup and background section loading.
 * Settings come from the hidden #eacfa-dashboard-config element printed by the page.
 */
(function () {
    'use strict';

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) { return null; }
        try { return JSON.parse(el.getAttribute('data-config')); } catch (e) { return null; }
    }

    var cfg = readConfig('eacfa-dashboard-config') || {};

    // With the "System" theme the page follows the device, so decide at click time.
    function bindThemeToggle() {
        var link = document.querySelector('.cfa-theme-toggle');
        if (!link) { return; }

        link.addEventListener('click', function () {
            var body = document.body;
            var dark = body.classList.contains('cfa-theme-dark') ||
                (body.classList.contains('cfa-theme-auto') && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            link.href = dark ? link.getAttribute('data-to-light') : link.getAttribute('data-to-dark');
        });
    }

    function bindRefreshButtons() {
        document.querySelectorAll('[data-eacfa-refresh]').forEach(function (btn) {
            btn.setAttribute('data-label', btn.textContent.trim());
            btn.addEventListener('click', function () { btn.textContent = 'Refreshing\u2026'; });
        });
    }

    // Also called after a section has been loaded in the background.
    window.eacfaChartInit = function () {
        var el = document.getElementById('cfa-chart');
        if (!el || typeof EacfaChart === 'undefined') { return; }

        var d;
        try { d = JSON.parse(el.getAttribute('data-chart')); } catch (e) { return; }
        if (!d) { return; }

        if (el._cfaChart) { el._cfaChart.destroy(); }

        var chart = new EacfaChart(el, {
            label: 'Visits and page views over time',
            labels: d.labels,
            datasets: [
                { label: 'Visits',     data: d.visits, series: 1 },
                { label: 'Page views', data: d.views,  series: 2 }
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

    // Sections that load after the page has appeared (the placeholder is #cfa-deferred).
    function loadSection() {
        var box = document.getElementById('cfa-deferred');
        if (!box || !window.fetch || !window.URLSearchParams) { return; }

        var body = new URLSearchParams();
        body.append('action', 'cf_analytics_section');
        body.append('nonce', cfg.sectionNonce);
        body.append('section', cfg.section);
        body.append('range', cfg.range);

        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var upd = document.getElementById('cfa-updated');
                if (res && res.success) {
                    box.innerHTML = res.data.html;
                    box.removeAttribute('id');
                    if (upd) { upd.textContent = res.data.updated ? '\u00B7 ' + res.data.updated : ''; }
                    if (window.eacfaChartInit) { window.eacfaChartInit(); }

                    if (res.data.jobs && res.data.jobs.length && window.eacfaRunQueue) {
                        window.eacfaRunQueue({ action: 'cf_analytics_refresh', ajaxUrl: cfg.ajaxUrl, nonce: res.data.refresh_nonce }, res.data.jobs);
                    }
                } else {
                    box.innerHTML = '<div class="cfa-notice is-error"><strong>Couldn\u2019t load this data.</strong> ' +
                        ((res && res.data) ? String(res.data).replace(/[<>&]/g, '') : 'Please try again.') + '</div>';
                    if (upd) { upd.textContent = ''; }
                }
            })
            .catch(function () {
                box.innerHTML = '<div class="cfa-notice is-error"><strong>Couldn\u2019t load this data.</strong> Request failed. Use Refresh to try again.</div>';
            });
    }

    function init() {
        bindThemeToggle();
        bindRefreshButtons();

        if (cfg.updated) {
            var el = document.getElementById('cfa-updated');
            if (el) { el.textContent = cfg.updated; }
        }

        if (cfg.chart && window.eacfaChartInit) { window.eacfaChartInit(); }
        if (cfg.deferred) { loadSection(); }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
