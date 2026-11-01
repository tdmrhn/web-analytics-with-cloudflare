/**
 * CfaChart: a small, dependency-free SVG line/area chart.
 *
 * Smooth lines, area fill, dots, dashed grid, hover tooltip, legend toggling, responsive.
 * Colors come from the CSS variables of the container (--cfa-line, --cfa-faint, --cfa-text,
 * --cfa-surface), so light and dark themes work without any extra code.
 *
 *   var chart = new CfaChart(element, {
 *       labels: ['Oct 1', 'Oct 2'],
 *       datasets: [{ label: 'Visits', data: [3, 5], color: '#7b4a9e' }],
 *       dotRadius: 3, lineWidth: 2.5, fontSize: 12, maxXTicks: 8
 *   });
 *   chart.update({ labels: [...], datasets: [{ data: [...] }, ...] });
 *   chart.toggle(0);   // show/hide a dataset, returns the new visibility
 */
(function (global) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';

    function svg(name, attrs) {
        var el = document.createElementNS(NS, name);
        for (var key in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, key)) {
                el.setAttribute(key, attrs[key]);
            }
        }
        return el;
    }

    function cssVar(el, name, fallback) {
        var value = getComputedStyle(el).getPropertyValue(name);
        return (value && value.trim()) || fallback;
    }

    function formatNumber(n) {
        try {
            return Number(n).toLocaleString();
        } catch (e) {
            return String(n);
        }
    }

    function formatTick(n) {
        return n >= 1000 ? (Math.round(n / 100) / 10) + 'k' : String(n);
    }

    // A "nice" tick step (1, 2, 5 x 10^n) so the axis ends on a round number.
    function niceStep(max, ticks) {
        var rough = max / ticks;
        var pow = Math.pow(10, Math.floor(Math.log(rough) / Math.LN10));
        var frac = rough / pow;
        var nice = frac <= 1 ? 1 : frac <= 2 ? 2 : frac <= 5 ? 5 : 10;
        return Math.max(1, nice * pow);
    }

    // Smooth path through the points (Catmull-Rom converted to cubic Beziers), kept inside the plot.
    function smoothPath(points, top, bottom) {
        if (!points.length) { return ''; }
        if (points.length === 1) { return 'M' + points[0].x + ',' + points[0].y; }

        var clamp = function (y) { return Math.min(bottom, Math.max(top, y)); };
        var d = 'M' + points[0].x + ',' + points[0].y;
        var t = 0.2;

        for (var i = 0; i < points.length - 1; i++) {
            var p0 = points[i - 1] || points[i];
            var p1 = points[i];
            var p2 = points[i + 1];
            var p3 = points[i + 2] || p2;

            var c1x = p1.x + (p2.x - p0.x) * t;
            var c1y = clamp(p1.y + (p2.y - p0.y) * t);
            var c2x = p2.x - (p3.x - p1.x) * t;
            var c2y = clamp(p2.y - (p3.y - p1.y) * t);

            d += ' C' + c1x.toFixed(2) + ',' + c1y.toFixed(2) + ' ' + c2x.toFixed(2) + ',' + c2y.toFixed(2) + ' ' + p2.x.toFixed(2) + ',' + p2.y.toFixed(2);
        }

        return d;
    }

    function CfaChart(container, options) {
        this.container = container;
        this.options = options || {};
        this.labels = this.options.labels || [];
        this.datasets = (this.options.datasets || []).map(function (d) {
            return { label: d.label, data: d.data || [], color: d.color, visible: d.visible !== false };
        });

        container.classList.add('cfa-chart-box');
        container.innerHTML = '';

        this.tip = document.createElement('div');
        this.tip.className = 'cfa-tip';
        this.tip.hidden = true;

        var self = this;
        this.onResize = function () { self.render(); };
        if (global.ResizeObserver) {
            this.observer = new ResizeObserver(this.onResize);
            this.observer.observe(container);
        } else {
            global.addEventListener('resize', this.onResize);
        }

        this.render();
    }

    CfaChart.prototype.update = function (next) {
        if (next.labels) { this.labels = next.labels; }
        if (next.datasets) {
            for (var i = 0; i < next.datasets.length; i++) {
                if (this.datasets[i] && next.datasets[i].data) {
                    this.datasets[i].data = next.datasets[i].data;
                }
            }
        }
        this.render();
    };

    CfaChart.prototype.toggle = function (index) {
        if (!this.datasets[index]) { return false; }
        this.datasets[index].visible = !this.datasets[index].visible;
        this.render();
        return this.datasets[index].visible;
    };

    CfaChart.prototype.destroy = function () {
        if (this.observer) { this.observer.disconnect(); } else { global.removeEventListener('resize', this.onResize); }
        this.container.innerHTML = '';
    };

    CfaChart.prototype.render = function () {
        var el = this.container;
        var width = el.clientWidth;
        var height = el.clientHeight;
        if (!width || !height) { return; }

        var o = this.options;
        var fontSize = o.fontSize || 12;
        var dotRadius = o.dotRadius === undefined ? 3 : o.dotRadius;
        var lineWidth = o.lineWidth || 2.5;
        var maxXTicks = o.maxXTicks || 8;

        var gridColor = cssVar(el, '--cfa-line', '#e5e7eb');
        var textColor = cssVar(el, '--cfa-faint', '#9ca3af');

        var count = this.labels.length;
        var visible = this.datasets.filter(function (d) { return d.visible; });

        var max = 0;
        visible.forEach(function (d) {
            d.data.forEach(function (v) { if (v > max) { max = v; } });
        });

        var ticks = 4;
        var step = max > 0 ? niceStep(max, ticks) : 1;
        var top = Math.max(step * ticks, step * Math.ceil(max / step));
        if (max === 0) { top = 4; step = 1; }

        var tickValues = [];
        for (var v = 0; v <= top + 0.0001; v += step) { tickValues.push(v); }

        // Left margin grows with the widest y label.
        var widest = 0;
        tickValues.forEach(function (t) { widest = Math.max(widest, formatTick(t).length); });
        var m = { left: Math.ceil(widest * fontSize * 0.62) + 14, right: 12, top: 10, bottom: fontSize + 14 };
        var plotW = Math.max(10, width - m.left - m.right);
        var plotH = Math.max(10, height - m.top - m.bottom);

        var xAt = function (i) { return count > 1 ? m.left + (i * plotW) / (count - 1) : m.left + plotW / 2; };
        var yAt = function (value) { return m.top + plotH - (value / top) * plotH; };
        var bottom = m.top + plotH;

        var root = svg('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': o.label || 'Chart' });
        root.style.display = 'block';

        // Grid + y labels
        tickValues.forEach(function (t) {
            var y = yAt(t);
            root.appendChild(svg('line', { x1: m.left, x2: m.left + plotW, y1: y, y2: y, stroke: gridColor, 'stroke-width': 1, 'stroke-dasharray': t === 0 ? '0' : '3 4' }));
            var label = svg('text', { x: m.left - 8, y: y + fontSize * 0.35, 'text-anchor': 'end', fill: textColor, 'font-size': fontSize });
            label.textContent = formatTick(t);
            root.appendChild(label);
        });

        // x labels (evenly spaced, no overlap)
        var every = Math.max(1, Math.ceil(count / maxXTicks));
        for (var i = 0; i < count; i += every) {
            var x = xAt(i);
            var text = String(this.labels[i]);
            var half = (text.length * fontSize * 0.3);
            var anchor = 'middle';
            if (x - half < 0) { anchor = 'start'; } else if (x + half > width) { anchor = 'end'; }
            var xl = svg('text', { x: x, y: height - 4, 'text-anchor': anchor, fill: textColor, 'font-size': fontSize });
            xl.textContent = text;
            root.appendChild(xl);
        }

        // Series
        var pointSets = [];
        this.datasets.forEach(function (d, idx) {
            if (!d.visible) { pointSets[idx] = null; return; }

            var pts = d.data.map(function (value, i) { return { x: xAt(i), y: yAt(value) }; });
            pointSets[idx] = pts;
            if (!pts.length) { return; }

            var line = smoothPath(pts, m.top, bottom);

            if (pts.length > 1) {
                var area = line + ' L' + pts[pts.length - 1].x + ',' + bottom + ' L' + pts[0].x + ',' + bottom + ' Z';
                root.appendChild(svg('path', { d: area, fill: d.color, 'fill-opacity': 0.12, stroke: 'none' }));
            }

            root.appendChild(svg('path', { d: line, fill: 'none', stroke: d.color, 'stroke-width': lineWidth, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));

            if (dotRadius > 0) {
                pts.forEach(function (p) {
                    root.appendChild(svg('circle', { cx: p.x, cy: p.y, r: dotRadius, fill: d.color }));
                });
            }
        });

        // Hover layer
        var guide = svg('line', { x1: 0, x2: 0, y1: m.top, y2: bottom, stroke: gridColor, 'stroke-width': 1, visibility: 'hidden' });
        root.appendChild(guide);

        var hoverDots = this.datasets.map(function (d, idx) {
            var c = svg('circle', { r: dotRadius + 2, fill: d.color, stroke: cssVar(el, '--cfa-surface', '#fff'), 'stroke-width': 2, visibility: 'hidden' });
            root.appendChild(c);
            return c;
        });

        var self = this;
        var tip = this.tip;

        function hide() {
            guide.setAttribute('visibility', 'hidden');
            hoverDots.forEach(function (c) { c.setAttribute('visibility', 'hidden'); });
            tip.hidden = true;
        }

        function move(event) {
            if (!count) { return; }
            var rect = root.getBoundingClientRect();
            var px = event.clientX - rect.left;
            var index = count > 1 ? Math.round(((px - m.left) / plotW) * (count - 1)) : 0;
            index = Math.max(0, Math.min(count - 1, index));
            var gx = xAt(index);

            guide.setAttribute('x1', gx);
            guide.setAttribute('x2', gx);
            guide.setAttribute('visibility', 'visible');

            var rows = '';
            self.datasets.forEach(function (d, idx) {
                var pts = pointSets[idx];
                if (!pts || !pts[index]) { hoverDots[idx].setAttribute('visibility', 'hidden'); return; }
                hoverDots[idx].setAttribute('cx', pts[index].x);
                hoverDots[idx].setAttribute('cy', pts[index].y);
                hoverDots[idx].setAttribute('visibility', 'visible');
                rows += '<div class="cfa-tip-row"><i style="background:' + d.color + '"></i><span></span><b>' + formatNumber(d.data[index]) + '</b></div>';
            });

            tip.innerHTML = '<div class="cfa-tip-title"></div>' + rows;
            tip.firstChild.textContent = String(self.labels[index]);
            var names = tip.querySelectorAll('.cfa-tip-row span');
            var n = 0;
            self.datasets.forEach(function (d, idx) {
                if (pointSets[idx] && pointSets[idx][index]) { names[n++].textContent = d.label; }
            });

            tip.hidden = false;
            var tw = tip.offsetWidth;
            var left = gx + 14;
            if (left + tw > width - 4) { left = gx - tw - 14; }
            tip.style.left = Math.max(4, left) + 'px';
            tip.style.top = (m.top + 4) + 'px';
        }

        root.addEventListener('pointermove', move);
        root.addEventListener('pointerdown', move);
        root.addEventListener('pointerleave', hide);

        el.innerHTML = '';
        el.appendChild(root);
        el.appendChild(tip);
    };

    global.CfaChart = CfaChart;
})(window);
