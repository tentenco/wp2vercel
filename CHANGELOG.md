# Changelog

All notable changes to WP2Vercel are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
