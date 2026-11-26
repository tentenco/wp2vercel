=== WP2Vercel ===
Contributors: tenten
Tags: static site, vercel, github, security, headless
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep WordPress private. Publish the public site as static files to your own Git repository, Vercel project or Netlify site.

== Description ==

WP2Vercel crawls your WordPress site from the inside, writes every page and asset as a static file, and publishes the result to a GitHub branch, or straight to a Vercel project or Netlify site you own. Visitors and bots reach the static copy. WordPress, PHP, the database and the login page can stay behind your access gateway.

What it does:

* Exports the whole site, or only the pages you choose.
* Lists published posts, pages and terms from the database, so pages nothing links to are still exported.
* Rewrites every mention of the private WordPress address. Canonical tags, social tags and sitemaps use the public address you set.
* Writes a `vercel.json`, or `_redirects` and `_headers` for Netlify, with real 301 and 302 redirects, read from Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager and Simple 301 Redirects.
* Scans the finished output for the private address and for file types that should not be public, and shows the file and line.
* Pushes one commit per publish to a GitHub branch, uploading only changed files, or deploys to Vercel or Netlify through their APIs, again uploading only changed files.
* Runs from the admin screen, from WP-CLI, or in the background.

What it does not do: forms, search, comments, logins and anything else that needs PHP at request time will not work on the static copy. Comment forms are left out of the published pages, because they have nowhere to send a comment. Comments that already exist stay visible.

== Installation ==

1. Upload the `wp2vercel` folder to `wp-content/plugins/` and activate it.
2. Open WP2Vercel, then Settings. Enter the public site address.
3. Choose a publish target and add a token. For GitHub, use a fine-grained token with Contents read and write on one repository.
4. If the server cannot reach its own public address, set the fetch address to one it can reach.
5. Open the Publish tab and choose Publish site.

== Frequently Asked Questions ==

= Does WordPress have to be reachable from the internet? =

No. The plugin fetches pages from the server itself and pushes files out. Nothing calls back into WordPress.

= Where do the tokens go? =

They are encrypted with libsodium and stored outside the autoloaded settings. You can also define `WP2VERCEL_GITHUB_TOKEN` or `WP2VERCEL_VERCEL_TOKEN` in `wp-config.php` and keep them out of the database.

= Which pages are left out? =

Drafts, private posts, password-protected posts, feeds, author archives, the REST API, `wp-admin`, `wp-login.php`, `xmlrpc.php` and every PHP file. Feeds and author archives can be turned on in the settings.

= The WP2Vercel screen says it could not load its data =

The screen talks to WordPress through the REST API, under `/wp-json/wp2vercel/v1/`. A security plugin or the firewall of your host is blocking it. Allow the REST API for signed-in administrators and reload the page.

= What do I send when I ask for help? =

Open Tools → Site Health → Info and use "Copy site info to clipboard". The WP2Vercel section lists the version, the publish target, the settings that matter and the result of the latest publish. Tokens are never part of it, and the addresses of the private WordPress are left out of the copied text.

== Changelog ==

= 0.2.0 =
* Extensions can add build steps, pause a publish until it is resumed, change `vercel.json` and register their own stored secrets.
* Builds that wait for an outside decision are shown as waiting and can be cancelled.
* Before the first publish, the Publish tab checks permalinks, whether WordPress can load its own home page, the public site address and the publish target.
* A failed publish names the address WordPress tried and the reason. A publish to this server only names the folder that holds the files.
* Publishes to Netlify as a draft or live deploy, with `_redirects` and `_headers` written from the same rules as `vercel.json`.
* A failed connection test, and a publish that GitHub, Vercel or Netlify refuses, say what to fix.
* The Publish tab lists pages that were left out and pages with a form that will not send on the published site.
* Tools → Site Health → Info has a WP2Vercel section to copy into a support request. The screen keeps keyboard focus during a publish, and labels are tied to their fields.

= 0.1.0 =
* First development release.
