# Changelog

## [0.2] - 2026-11-05

- Renamed to Easy Analytics with Cloudflare RUM
- Primary color setting: the accent color and chart colors follow the color you choose
- Internal function and class names carry a unique prefix (`eacfa_`, `EACFA_`)
- Stylesheet uses design tokens with relative units (rem, em) and fluid sizes (clamp)

## [0.1] - 2026-10-31

First release.

- Cloudflare Web Analytics in WordPress: visits, page views, page load time and Core Web Vitals (LCP, INP, CLS), with trends against the previous period
- Sections: Overview, Pages, Referrers, Countries, Technology, Core Web Vitals
- Dashboard widget and admin bar counter (exact views of the page you are viewing on the public site, total views in wp-admin)
- Stale-while-revalidate cache: pages open from saved data and refresh in the background after load; refresh interval of 5 / 10 / 30 minutes, hourly or manual
- Light, dark and system themes; appearance and refresh interval save as soon as you pick an option; the header toggle shows a crescent or a sun
- Every save is verified against the database and retried from a clean state; failures are reported with the reason
- Dependency-free SVG charts bundled with the plugin (`assets/js/cfa-chart.js`)
- Token-only authentication, token stored encrypted; `uninstall.php` removes everything the plugin saved
