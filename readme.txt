=== Web Analytics with Cloudflare RUM ===
Contributors: dmrhn
Tags: cloudflare, web analytics, core web vitals, performance, statistics
Requires at least: 5.3
Tested up to: 7.1
Stable tag: 0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See Cloudflare Web Analytics in WordPress: visits, page views, page load time and Core Web Vitals, read live from Cloudflare.

== Description ==

Web Analytics for Cloudflare shows your Cloudflare **Web Analytics** RUM (real user monitoring) data inside WordPress. Data is read from Cloudflare and kept for a short time, so nothing is copied into custom database tables.

This plugin is not affiliated with, endorsed by, or sponsored by Cloudflare, Inc. Cloudflare is a trademark of Cloudflare, Inc.

= Features =

* **Overview**: visits, page views, page load time and a Core Web Vitals summary, with a traffic chart and trends against the previous period
* **Pages, referrers, countries, browsers, operating systems and devices**
* **Core Web Vitals**: LCP, INP and CLS with good / needs improvement / poor distribution, percentiles (P50 to P99), sub-parts and a per-page table
* **Dashboard widget**: 30-day chart with visits and page views
* **Admin bar counter**: today's page views for the page you are viewing on the public site, and total views in wp-admin
* **Fast**: pages open from saved data and refresh in the background after they have loaded. Choose how often: every 5 minutes, 10 minutes, 30 minutes, hourly, or manual only
* **Dark mode**: Light, Dark or System, saved per user
* **No external scripts**: the charts are drawn by a small script bundled with the plugin
* **Secure**: your API token is encrypted using your WordPress salts

= Requirements =

* Cloudflare Web Analytics turned on for your site (this plugin reads the data; it does not add the tracking snippet)
* A Cloudflare API token with Zone > Zone > Read, Account > Account Settings > Read and Account > Account Analytics > Read
* Works with passkey, SSO and 2FA logins (the plugin never uses your login, only the token)
* WordPress 5.3 or higher, PHP 7.4 or higher

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it
2. Open Dashboard > Web Analytics and click the gear icon (Settings)
3. Make sure Web Analytics is turned on for your site, then create an API token in Cloudflare (My Profile > API Tokens > Create Token > Create Custom Token) with the permissions above
4. Paste the token, click "Load my zones", pick your zone and click Connect

== Frequently Asked Questions ==

= How do I connect if I sign in to Cloudflare with a passkey? =

Sign in to Cloudflare the way you normally do, then create an API token and paste it into the plugin. The plugin never needs your password or passkey.

= I see "No Web Analytics site was found" =

Turn on Web Analytics for your site in Cloudflare (your site > Speed > Real User Monitoring), then use Test connection in the plugin settings. If your token can't read Account Settings, enter the Web Analytics site tag under Advanced.

= Is anything stored in my database? =

Only the encrypted token, the zone, account and site IDs, your refresh and theme choices, and short-lived cached results (transients) that expire on their own. Deleting the plugin removes what it saved.

= Why are some numbers different from the Cloudflare dashboard? =

Cloudflare samples Web Analytics data, and its dashboard may apply filters (for example excluding bots) that this plugin doesn't. Times use your WordPress timezone.

= Does the plugin refresh data on a timer? =

No. Nothing runs on a schedule. When an administrator opens a page and the saved data is older than the refresh interval you chose, it is refreshed in the background after the page has loaded. Choose "Manual" to refresh only with the Refresh button.

= Which Cloudflare plans work? =

Any plan with Web Analytics. Available data depends on what Cloudflare provides for your account.

== External services ==

This plugin connects to the Cloudflare API to read your Web Analytics data. Without that connection it cannot show anything.

* **Service:** Cloudflare API (`https://api.cloudflare.com/client/v4`), including its GraphQL Analytics endpoint.
* **What it is used for:** listing your zones, finding your Web Analytics site, and reading analytics: page views, visits, page load time, Core Web Vitals, top pages, referrers, countries, browsers, operating systems and devices.
* **What is sent, and when:** your Cloudflare API token (as an authorization header), your zone, account and Web Analytics site IDs, and the date range being viewed. For the admin bar counter, the path of a page is sent only when it is not in your top-pages list. Requests are made when an administrator opens the plugin's dashboard or settings, the WordPress Dashboard (for the widget), or a page where the admin bar counter needs a refresh, and when you use Test connection or Refresh. The plugin does not send any information about your site's visitors to Cloudflare; it only reads aggregated analytics.
* **Cloudflare's terms and privacy policy:** [Terms of Use](https://www.cloudflare.com/website-terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

== Changelog ==

= 0.1 =
* First release
* Cloudflare Web Analytics in WordPress: visits, page views, page load time and Core Web Vitals (LCP, INP, CLS) with trends against the previous period
* Pages, referrers, countries, browsers, operating systems and devices
* Dashboard widget, and an admin bar counter with the exact views of the page you are viewing
* Pages open from saved data and refresh in the background; choose the interval or refresh manually
* Light, dark and system themes
* Charts drawn by a small script bundled with the plugin (no external scripts)