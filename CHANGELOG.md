# Changelog

## [0.5] - 2026-11-07

- Stylesheet reworked on design tokens
- Chart colors and sizes come from the stylesheet, with light and dark themes
- Numbered setup steps, clearer settings spacing, ghost "View all" links
- Layout spacing uses flex and grid gaps instead of margins
- Shimmer no longer turns off with the system's reduced-motion setting

## [0.4] - 2026-11-06

- Dashboard, widget and admin bar share one cache
- Widget updates in place as new data arrives
- Analytics overview loads first after a cache clear; the rest loads in the background
- Refresh button fetches everything, including yesterday
- Skeleton loading for cards

## [0.3] - 2026-11-05

- All scripts and styles are loaded with WordPress enqueue functions; no inline script or style blocks remain
- Fixed a false "could not be saved" message for the refresh interval

## [0.2] - 2026-11-05

- Renamed to Easy Analytics with Cloudflare RUM
- Primary color setting: the accent color and chart colors follow the color you choose
- Internal function and class names carry a unique prefix
- Stylesheet uses design tokens with relative units (rem, em) and fluid sizes (clamp)

## [0.1] - 2026-10-31

First release.

- Cloudflare Web Analytics in WordPress: visits, page views, page load time and Core Web Vitals (LCP, INP, CLS), with trends against the previous period
- Sections: Overview, Pages, Referrers, Countries, Technology, Core Web Vitals
- Dashboard widget, and an admin bar counter with the exact views of the page you are viewing
- Pages open from saved data and refresh in the background; choose the interval or refresh manually
- Light, dark and system themes; the header toggle shows a crescent (switch to dark) or a sun (switch to light)
- Dependency-free SVG charts drawn by a small script bundled with the plugin (no external scripts)
- Token-only authentication, token stored encrypted; `uninstall.php` removes everything the plugin saved