=== WP2Vercel ===
Contributors: tenten
Tags: static site, vercel, github, security, headless
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep WordPress private. Publish the public site as static files to your own Git repository and Vercel account.

== Description ==

WP2Vercel crawls your WordPress site from the inside, writes every page and asset as a static file, and publishes the result to a GitHub branch or straight to a Vercel project you own. Visitors and bots reach the static copy. WordPress, PHP, the database and the login page can stay behind your access gateway.

What it does:

* Exports the whole site, or only the pages you choose.
* Lists published posts, pages and terms from the database, so pages nothing links to are still exported.
* Rewrites every mention of the private WordPress address. Canonical tags, social tags and sitemaps use the public address you set.
* Writes a `vercel.json` with real 301 and 302 redirects, read from Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager and Simple 301 Redirects.
* Scans the finished output for the private address and for file types that should not be public, and shows the file and line.
* Pushes one commit per publish to a GitHub branch, uploading only changed files, or deploys to Vercel through its API.
* Runs from the admin screen, from WP-CLI, or in the background.

What it does not do: forms, search, comments, logins and anything else that needs PHP at request time will not work on the static copy.

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

== Changelog ==

= 0.1.0 =
* First development release.
