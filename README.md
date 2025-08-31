# NextPress=== ReactWoo Static Builder ===
Contributors: reactwoo
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Next.js-style static rendering for WordPress pages/products with auto rebuilds. Optional Cloud Hosting connect to Vercel/Cloudflare/Netlify.

== Description ==
- Writes fully rendered HTML to /wp-content/rwsb-static/{host}/{path}/index.html
- Serves static instantly via template_redirect short-circuit (no server config)
- Rebuilds on post save, product stock/status changes, menus/terms, and hourly archives
- Bypass with ?rwsb=miss or when logged-in (configurable)
- Optional deploy webhook (Netlify/Vercel) after builds
 - Cloud Hosting: pick provider and Connect to trigger managed deploys
- WP-CLI: `wp rwsb build-all` and `wp rwsb build --id=123|--url=...`

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/
2. Activate in WP Admin → Plugins
3. Configure in WP Admin → Static Builder

== Cloud Hosting (optional) ==
1. In WP Admin → Static Builder, choose a provider (Cloudflare/Vercel/Netlify) and Save.
2. Click "Connect to {Provider}" to authorize via `server.reactwoo.com`.
3. Once connected, use "Deploy to {Provider}". If you have a provider Build Hook URL, place it in "Deploy Webhook".
4. For managed tiers, entitlements and quotas are enforced by the license server.

== Frequently Asked Questions ==
= Will this break dynamic pages like carts/checkout? =
Don’t include those post types/routes. Logged-in users automatically bypass static if enabled.

= How do I preview fresh content? =
Append `?rwsb=miss` to the URL or stay logged-in if “Respect Logged-in” is enabled.

= Can I put the static files behind a CDN? =
Yes, point your CDN to `/wp-content/rwsb-static`. Files are immutable; set long cache lifetimes.

== Changelog ==
1.0.0 — Initial release
