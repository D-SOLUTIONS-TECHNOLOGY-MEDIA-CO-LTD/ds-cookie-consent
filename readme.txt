=== DS Cookie Consent ===
Contributors: dsolutions
Tags: cookie, consent, gdpr, consent mode, privacy
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie consent banner with Google Consent Mode v2, prior script blocking, a cookie catalog, scanners and a consent log. Built to be reused across sites.

== Description ==

DS Cookie Consent asks visitors for their cookie choice and makes sure tracking only runs after they agree. It is developed by D-Solutions and used on all of its client sites; nothing in it is tied to one customer.

* Two layouts: a compact box in a corner (card) or a horizontal bar with a three-tab preferences popup. Colours, logo, font, button order, position and texts are set in the admin with a live preview and contrast warnings.
* Google Consent Mode v2: denied by default, updated when the visitor chooses, optional region list and URL passthrough, from an inline head script under 1.5 KB. `window.DSCC`, `dataLayer` and DOM events let themes and tag managers react to the choice.
* Prior blocking: scripts, iframes and tracking pixels of the platforms you use are rewritten on the server and released in the browser only after consent. A server cookie guard holds back `Set-Cookie` headers of cookies the visitor has not allowed. An "observe only" mode shows what would be blocked before you turn blocking on. Exclusions, "cookies to always keep" and a Google Tag Manager mode are included.
* Platform picker: tick the platforms used on the site (Google Analytics, Google Ads, Tag Manager, Meta Pixel, TikTok, Clarity, Hotjar, LinkedIn, YouTube, Maps, WooCommerce, Zalo and more) from a known-cookie database. Their cookies enter the cookie list with purpose and duration texts in Vietnamese and English, and visitors can switch each platform on or off. Custom platforms can be added; the database can be updated from an https URL after a SHA-256 check.
* Cookie scanner: a server scan of your own pages (HTML, Google Tag Manager containers, `Set-Cookie` headers), an optional passive collector in visitors' browsers (names only, never values, off by default) and a headless deep scan tool you run yourself with Docker, n8n or cron. Cookies nobody has classified yet are reported, with quick actions to classify them.
* Compliance report on the Overview screen: cookies set before consent, cookies that leak after a visitor rejected, unclassified cookies, and cookies missing from your cookie policy page.
* Consent log with masked IP addresses, filters, CSV export and a retention setting.
* Shortcodes: `[dscc_cookie_settings]` (reopen the preferences), `[dscc_cookie_table]` (cookie tables for your policy page), `[dscc_last_scan]`.
* Export and import of the whole configuration as JSON with a preview of the changes before anything is saved, plus bundled presets that are only ever applied when an administrator chooses them.
* Integrations: Google Site Kit, PixelYourSite, Meta Pixel for WordPress, LiteSpeed Cache, WP Rocket, WP Consent API, and chat widgets (Pancake, Zalo).
* Visitor texts in Vietnamese and English (editable), following WordPress, WPML, Polylang or GTranslate. English admin strings with a complete Vietnamese translation.
* Updates from GitHub Releases through the normal WordPress update screen (works with auto-updates and remote managers).

= What the plugin does not do =

It does not make a site legally compliant by itself. It gives you the tools (blocking, a cookie list, a log, scans) and you remain responsible for the cookie policy text and for checking the result on your own site.

== Installation ==

1. Download `ds-cookie-consent.zip` from the Releases page of https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent, then install it from Plugins > Add New > Upload Plugin and activate it. Or upload the `ds-cookie-consent` folder to `/wp-content/plugins/`.
2. Open Cookie Consent > Settings. Choose the layout and colours on the Appearance tab, and pick the cookie policy page on the Content tab.
3. On the Platforms tab tick the platforms used on the site.
4. The Script blocking tab blocks by default. On a site with real traffic you may set the mode to "Observe only" for the first week, check the report, then switch back to blocking.
5. On the Cookies tab run "Scan now" and classify the cookies marked New.
6. Put `[dscc_cookie_table]` on your cookie policy page.

= Updates =

The plugin updates from the public GitHub repository https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent (constant `DSCC_UPDATE_REPO`, default `D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent`). No token is needed. You may define `DSCC_GITHUB_TOKEN` in wp-config.php to raise the API rate limit; the token is only sent to api.github.com.

= Documentation =

Detailed guides (Vietnamese) are in the `docs` folder of the repository: installation, every setting, platforms, script blocking, scanners, shortcodes and the JavaScript API, developer hooks, releases and a troubleshooting FAQ.

== Frequently Asked Questions ==

= Does blocking work with page caches? =

The rewritten HTML is the same for every visitor, so full-page caches are fine. Cookies set by the server (for example the `_fbp` cookie of a Meta Pixel plugin) can only be held back when the request reaches PHP; a cached page does not. Test this on a staging copy of your own stack.

= The banner does not show for some visitors =

Search bots (Googlebot, Bingbot and similar) and the user agents on your skip list never see it. By default those visitors are treated like a bot: tracking stays blocked and embedded videos and maps are restored. Lighthouse is not on the bot list, so audits measure the real page with the banner. Visitors who already chose with the current policy version do not see it again until the choice expires or you change the policy version.

= How do I make everyone choose again? =

Change the policy version on the Advanced tab, or press "Ask every visitor to choose again".

= How do I let visitors reopen their choices? =

Use the shortcode `[dscc_cookie_settings]`, a link ending in `#cai-dat-cookie` or `#cookie-settings`, or any element with the attribute `data-dscc-open`. The minimised shield icon does the same.

= Is the consent log personal data? =

The log stores a random consent id, the choice, the policy version, the page path, a masked IP address (last IPv4 octet removed, IPv6 truncated) and the user agent. It has no name or email. You choose how many months it is kept.

= Which servers does the plugin contact? =

Only GitHub for updates: api.github.com for the release information and github.com with its download hosts (objects.githubusercontent.com, release-assets.githubusercontent.com) for the zip, plus the optional known-cookie database URL you configure. Scans only load pages of your own site (and the Google Tag Manager container files of containers found on it). Nothing is sent to third parties without your setting.

= What happens to my data when I delete the plugin? =

Settings, caches and scheduled events are removed. The consent log and the catalog tables are kept unless you turned on "Delete data on uninstall" on the Advanced tab before deleting.

== Changelog ==

= 1.0.1 =
* YouTube and Google Maps moved from Marketing to the Functional category; third-party functional embeds stay blocked until the visitor allows Functional.
* The Functional group is shown separately by default (existing sites keep their saved setting). Consent Mode mapping is unchanged.
* New default colours taken from the D-Solutions palette, all passing WCAG AA.
* New email alert when a deep scan finds consent violations (setting `notify_violations`, not repeated for the same list within 24 hours).
* Deep scan: a Google Analytics / Ads / Floodlight host contacted before consent without any Google cookie (a cookieless Consent Mode ping) is now a warning, not a violation, and sends no email.
* Accessibility: the cookie table checkboxes in the admin have screen-reader labels.

= 1.0.0 =
* First stable release.
* Banner with two layouts (card and bar), live preview, contrast warnings, per-platform switches, Vietnamese and English texts.
* Google Consent Mode v2, consent log with CSV export and retention.
* Platform picker and known-cookie database with verified updates, custom platforms, cookie table with bulk actions and the `[dscc_cookie_table]` shortcode.
* Prior blocking with server cookie guard, observe-only mode, exclusions, Google Tag Manager mode and integrations (Meta Pixel for WordPress, PixelYourSite, Site Kit, WP Consent API, LiteSpeed Cache, WP Rocket).
* Scanners: server scan, visitor beacon, headless deep scan tool with a REST endpoint, compliance report with quick classification.
* JSON export and import with preview, and bundled presets applied only on request.
* English source strings with a Vietnamese translation; GitHub Releases updater.

== Upgrade Notice ==

= 1.0.1 =
Functional category shown by default, YouTube and Google Maps moved to Functional, deep scan violation emails. Sites with saved settings keep their colours and Functional setting.

= 1.0.0 =
First stable release.
