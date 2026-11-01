# Changelog

## [0.1] - 2026-10-31

First release.

- Cloudflare Web Analytics in WordPress: visits, page views, page load time and Core Web Vitals (LCP, INP, CLS), with trends against the previous period
- Sections: Overview, Pages, Referrers, Countries, Technology, Core Web Vitals
- Dashboard widget and admin bar counter (exact views of the page you are viewing on the public site, total views in wp-admin)
- Stale-while-revalidate cache: pages open from saved data and refresh in the background after load; refresh interval of 5 / 10 / 30 minutes, hourly or manual
- Light, dark and system themes
- Dependency-free SVG charts bundled with the plugin (`assets/js/cfa-chart.js`)
- Token-only authentication, token stored encrypted; `uninstall.php` removes everything the plugin saved
