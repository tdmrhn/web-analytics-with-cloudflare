/**
 * Easy Analytics background refresh.
 *
 * - Runs the refresh queue the page printed (#eacfa-live-config), five jobs per request.
 * - Reloads a dashboard page once when the refresh brought in new data (never on the front end).
 * - Updates the admin bar counter (#eacfa-bar-config).
 * - Exposes eacfaRunQueue() for jobs that come back from a background section load.
 */
(function () {
    'use strict';

    var BATCH = 5;

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) { return null; }
        try { return JSON.parse(el.getAttribute('data-config')); } catch (e) { return null; }
    }

    function post(url, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (key) { body.append(key, params[key]); });
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: body });
    }

    function showNotice(text) {
        var el = document.getElementById('eacfa-status') || document.getElementById('cfw-error');
        if (!el) { return; }
        el.textContent = text;
        el.hidden = false;
    }

    function resetButtons() {
        document.querySelectorAll('[data-eacfa-refresh]').forEach(function (btn) {
            btn.textContent = btn.getAttribute('data-label') || 'Refresh';
        });
    }

    // Reload once. The marker in the URL stops the reloaded page from reloading again.
    function reloadOnce() {
        var url = new URL(window.location.href);
        if (url.searchParams.has('eacfa_fresh')) { return false; }
        url.searchParams.set('eacfa_fresh', '1');
        window.location.replace(url.toString());
        return true;
    }

    function finish(changed, failed, reload) {
        if (changed > 0) {
            // The dashboard widget redraws itself from the cache; no page reload needed.
            if (document.getElementById('cfw-root')) {
                document.dispatchEvent(new CustomEvent('eacfa:refreshed'));
                resetButtons();
                return;
            }
            if (reload && reloadOnce()) { return; }
        }

        resetButtons();
        if (failed > 0 && changed === 0) {
            showNotice('Some data could not be refreshed. Try Refresh again in a moment.');
        }
    }

    // Runs the jobs in batches. The server answers with how many changed, failed, or were busy.
    function runJobs(cfg, jobs, onDone) {
        var changed = 0;
        var failed = 0;
        var queue = jobs.slice();

        function next() {
            if (!queue.length) { onDone(changed, failed); return; }

            var batch = queue.splice(0, BATCH);
            post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, jobs: JSON.stringify(batch) })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success) {
                        changed += res.data.changed;
                        failed += res.data.failed;
                    } else {
                        failed += batch.length;
                    }
                    next();
                })
                .catch(function () {
                    failed += batch.length;
                    next();
                });
        }

        next();
    }

    function runBar(cfg) {
        if (!document.querySelector('#wp-admin-bar-cf-analytics .cfab-num')) { return; }

        function set(sel, text) {
            var el = document.querySelector(sel);
            if (el) { el.textContent = text; }
        }

        post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, scope: cfg.scope, path: cfg.path })
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

    if (!window.fetch || !window.URLSearchParams) { return; }

    // Jobs returned by a background section load (dashboard). Always reloads when data changed.
    window.eacfaRunQueue = function (cfg, jobs) {
        runJobs(cfg, jobs, function (changed, failed) { finish(changed, failed, true); });
    };

    // Config is printed late in the page, so it is read once the page has loaded.
    function whenLoaded() {
        var queue = readConfig('eacfa-live-config');
        var bar = readConfig('eacfa-bar-config');

        if (queue) {
            var jobs = [];
            try { jobs = JSON.parse(queue.jobs) || []; } catch (e) { jobs = []; }

            setTimeout(function () {
                runJobs(queue, jobs, function (changed, failed) { finish(changed, failed, !!queue.reload); });
            }, 800);
        }

        if (bar) { setTimeout(function () { runBar(bar); }, 300); }
    }

    if (document.readyState === 'complete') { setTimeout(whenLoaded, 0); } else { window.addEventListener('load', whenLoaded); }
})();
