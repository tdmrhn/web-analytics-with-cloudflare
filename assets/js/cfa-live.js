/**
 * Easy Analytics background refreshes, run after the page has loaded.
 * Settings come from the hidden #eacfa-live-config (queued refresh) and
 * #eacfa-bar-config (admin bar counter) elements. Both are printed late in the page,
 * so they are read on window load rather than when this file runs.
 */
(function () {
    'use strict';

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

    function runQueue(cfg) {
        post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, jobs: cfg.jobs }).catch(function () {});
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

    function whenLoaded() {
        var queue = readConfig('eacfa-live-config');
        var bar = readConfig('eacfa-bar-config');

        if (queue) { setTimeout(function () { runQueue(queue); }, 800); }
        if (bar) { setTimeout(function () { runBar(bar); }, 300); }
    }

    if (document.readyState === 'complete') { setTimeout(whenLoaded, 0); } else { window.addEventListener('load', whenLoaded); }
})();
