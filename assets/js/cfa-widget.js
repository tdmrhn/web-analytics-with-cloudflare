/**
 * Easy Analytics dashboard widget: draws the chart and refreshes stale data in place.
 * Data and settings come from the hidden #eacfa-widget-config element.
 */
(function () {
    'use strict';

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) { return null; }
        try { return JSON.parse(el.getAttribute('data-config')); } catch (e) { return null; }
    }

    var cfg = readConfig('eacfa-widget-config');
    if (!cfg) { return; }

    var initial = cfg.initial;
    var ajaxUrl = cfg.ajaxUrl;
    var nonce = cfg.nonce;
    var colors = cfg.colors || {};
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
                { label: 'Page views', data: d.views,  color: colors.views || '#c84fb0' },
                { label: 'Visits',     data: d.visits, color: colors.visits || '#8458c8' }
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
