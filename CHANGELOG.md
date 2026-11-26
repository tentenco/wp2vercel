# Changelog

All notable changes to WP2Vercel are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-11-25

A release for extension authors, with a clearer first publish.

### Added

- `wp2vercel_build_steps` and `wp2vercel_step` filters, so an extension can add, replace or reorder the steps of a publish.
- A `waiting` build status. A step can pause a publish until `BuildRunner::resume()` is called, for example after an approval. A waiting publish is shown on the Publish tab and in `wp wp2vercel status`, and can be cancelled.
- `wp2vercel_build_waiting` action, fired when a publish pauses.
- `wp2vercel_vercel_config` filter for the final contents of `vercel.json`.
- Netlify as a publish target, next to GitHub and Vercel. A publish creates a deploy through Netlify's API and uploads only the files Netlify does not have yet, as a draft or live (**Release**). The site can be entered as `name`, `name.netlify.app`, its address in the Netlify app or its Site ID. Redirects, rewrites and headers are written to `_redirects` and `_headers` from the same rules as `vercel.json`, after the `wp2vercel_vercel_config` filter; redirects by browser language become `Language=` rules, and a rule Netlify cannot express is named in the log. With Netlify no `vercel.json` is written, and with Vercel no Netlify files; files on this server only and GitHub get both. When Netlify asks to slow down or does not answer, the deploy waits and goes on instead of failing.
- `wp2vercel_secret_names` filter, so an extension can keep its own secrets in the encrypted store or in `wp-config.php` constants.
- `window.wp2vercel.registerStepLabels()` for readable names of extension steps on the Publish tab.
- `window.wp2vercel.registerFindingsHelp()` for a line of advice above the leak scan findings.
- Until the first publish succeeds, the Publish tab shows a short check of the site: permalinks, whether WordPress can load its own home page, the public site address and the publish target. `GET /wp2vercel/v1/readiness` returns the same list.
- `bin/ci.sh` runs lint and the unit tests on PHP 8.1 to 8.4 in Docker. `bin/release.sh` publishes a GitHub release from a local machine. Neither needs GitHub Actions.

### Changed

- A build records the user who started it.
- Tools → Site Health → Info has a WP2Vercel section with the version, the publish target, the settings that matter and the latest publish, to copy into a support request. No tokens, and the private WordPress addresses are left out of the copied text. Extensions add fields with the `wp2vercel_site_health_fields` filter.
- When the home page cannot be saved, the result names the address WordPress tried and the reason (connection error, redirect, password or HTTP status), and points to the Fetch address setting.
- A publish to "This server only" names the folder that holds the files.
- A publish stopped by the leak scan says what to do next.
- When the WordPress REST API is blocked, by a security plugin or by the firewall of the host, the WP2Vercel screen says so and what to allow. Until now every tab showed "Loading…" and nothing else.
- When WordPress stops answering while a publish runs or waits for approval, the Publish tab says that what it shows may be out of date and keeps trying. Until now the progress stood still without a word, and a publish waiting for approval was never refreshed again. A **Cancel** that fails is reported.
- A publish of the whole site that is cancelled or fails no longer leaves its half-written copy of the site in `wp-content/uploads`. Until now that folder stayed until the next publish of the whole site.
- Deleting the plugin no longer stops with a critical error on servers that run Alpine Linux, which many Docker images do. The clean-up used a PHP option that does not exist there, so the plugin, its tables, its settings and the published files all stayed.
- The plugin no longer needs PHP's mbstring extension. On a server without it, a publish stopped with a fatal error as soon as the leak scan found something or a page had a very long address.
- A publish that the server keeps ending, for example at the PHP time or memory limit, no longer stays "Running" for ever. After five minutes without progress the Publish tab says so, and after five runs in a row that never came back the publish is stopped with a reason, so that later publishes are not blocked behind it.
- When WordPress cannot write to its publish folder, the publish says so and what to check (owner and permissions of `wp-content/uploads`, free disk space), and the check before the first publish lists it. Until now the result was "Could not create directory … (SiteDirectory.php:215)".
- A publish that fails because GitHub or Vercel refuses the access token says so and what to change, the same way the connection test does. Until now it read "Could not read branch (HTTP 401: Bad credentials)".
- The plugin header has an `Update URI`, so WordPress does not accept an update from a wordpress.org plugin that happens to use the same folder name. Remove the line if the plugin is ever listed there.
- The Publish tab lists the pages that were left out because they ask for a password or refuse visitors, with the page that links to each one. Until now they were only counted as "skipped" in the log, and a menu link to such a page led to the not-found page without a word.
- The exported `robots.txt` keeps a valid rule after the admin lines are removed, and the exported sitemap no longer lists password-protected pages, which are not part of the published site.
- The Publish tab lists the pages with a form that sends its entries to WordPress, such as a Contact Form 7 form or a plain HTML form. On the published site such a form shows the visitor an error and nothing arrives; until now nothing said so. Forms sent to another host are not listed. The `wp2vercel_report_forms` filter switches the list off.
- Published pages no longer carry WordPress's comment form, which posted to an address that does not exist on the static site. Comments that already exist stay visible. The `wp2vercel_keep_comment_forms` filter keeps the form.
- Settings: the token fields link to the page on GitHub and Vercel where a token is created, the Team ID field says where to find it, and the repository field says that the repository needs a first commit and has to be imported on Vercel.
- Settings: a public site address typed without `https://` is completed, and a repository can be pasted as its GitHub address. A value that cannot be used is reported and the stored value is kept, on the Settings tab and in `wp wp2vercel config set`.
- Settings cannot be saved while a publish runs (Settings tab, REST API and `wp wp2vercel config set`), so one publish no longer mixes old and new settings. A publish that waits for approval does not block saving.
- **Download as zip** on the Publish tab packs the copy saved on this server. It uses PHP's zip extension, or WordPress's own zip code where the extension is missing. The temporary zip is deleted also when the download is broken off.
- **Cancel** stops the running step within about a second; a page that is being fetched at that moment is still waited for, up to the fetch timeout. Until now the publish went on fetching new pages for up to 30 seconds.
- A publish cancelled while its last step runs stays cancelled. The copy on this server stays as it was and no failure is reported. Until now the cancel could remove the new files while the publish still used them, so it ended as failed and the next "publish changes" ran as a whole-site publish. A second **Cancel**, or a request that ends after the cancel, no longer changes the result or reports the publish twice, and the half-written copy of a publish whose request was killed is removed at the next run.
- Sites whose address has a path, such as a sub-site of a multisite network at `example.com/shop/` or a site at `example.com/blog/`, export only their own pages. This includes paths that are not in Latin letters, such as `example.com/博客/`. The root of the export redirects to the site's path, links into other sites of the network are not followed, and images and files from other sites still are.
- Multisite: only a network administrator can change the fetch address. Uninstalling or deactivating the plugin for the whole network cleans up every site, and deleting a site deletes its publish tables.
- Files of themes, plugins and uploads are read from disk only from those folders, and not from a folder that the web server guards with `.htaccess` or `web.config`. Such files are fetched over HTTP like a visitor would.
- When the finished copy cannot be put in place on this server, for example because Windows holds a file open, the move is tried again. If it still fails, the next "changes" publish runs as a whole-site publish, so no change is lost.
- On Windows, pages whose address is a reserved file name (`con`, `aux`, `nul` and the like) are saved under another name and served at their own address through `vercel.json`.
- On PHP without mbstring, a publish no longer stops at the leak scan when another plugin or WP-CLI loads an mbstring replacement that does not work on Alpine Linux.
- With author archives exported, publishing a changed post also refreshes the author's page.

## [0.1.0] - 2026-11-25

First development release.

### Added

- Full-site static export with a resumable, time-budgeted build that runs in the background, in the browser or from WP-CLI.
- Single-page publishing from the Posts and Pages lists and with `wp wp2vercel publish --post=<ids>`.
- Publish targets: GitHub branch (one commit per publish, changed files only), direct Vercel deployment, or files on the server.
- Rewriting of the private WordPress address in HTML, CSS, JavaScript, JSON, XML and feeds, in plain, JSON-escaped and URL-encoded form.
- `vercel.json` with redirects collected from WordPress, Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager and Simple 301 Redirects.
- Leak scan for the private address and for sensitive file types.
- Export requests leave out drafts, private and password-protected posts, REST API and XML-RPC links, the generator tag and the users sitemap.
- Encrypted token storage, with constants and environment variables as an alternative.
- WP-CLI commands: `publish`, `status`, `leaks`, `failures`, `cancel`, `config`, `test`.
- Filters and actions for extensions, documented in `docs/hooks.md`.

[0.1.0]: https://github.com/tentenco/wp2vercel/releases/tag/v0.1.0
- A translation template, `languages/wp2vercel.pot`, with every string of the settings screen and the notices. Translations placed next to it are loaded by WordPress.
- A failed connection test says what to fix: a token GitHub does not accept, a repository the token cannot see, missing permissions, a Vercel token or team that does not match, or a server that cannot reach the API.
- Settings: a branch name Git would refuse is reported instead of being replaced by the default.
- The screen keeps keyboard focus and the cursor while a publish is running or waiting. Labels and hints are tied to their fields, the current tab is marked, and notices are announced by screen readers.
- Times in the publish log are shown in the browser's time zone, like every other time on the screen.
- `wp wp2vercel config set <secret> -` reads the value from standard input, so a token stays out of the shell history. Values are printed without escaped slashes.
