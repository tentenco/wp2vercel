# Contributing to WP2Vercel

Thanks for helping. This page covers how to report a problem, how to set up a development copy, and what a pull request needs before it can be merged.

## Reporting bugs

Open an issue with the bug report form. The details that help most:

- WordPress, PHP and WP2Vercel versions
- the publish target (GitHub, Vercel, or this server only)
- what you expected and what happened
- the build log from the Publish tab, or the output of `wp wp2vercel status` and `wp wp2vercel failures`

Remove tokens, private host names and anything else you would not post in public. Security problems go through [SECURITY.md](SECURITY.md) and never into a public issue.

## Suggesting features

Open an issue with the feature request form and describe the problem you are trying to solve. Some features belong to the paid editions listed at [wp2vercel.com/pricing](https://wp2vercel.com/pricing). We will say so in the issue when that is the case.

## Development setup

You need PHP 8.1 or newer, Docker, and a WordPress checkout for the unit tests (they load the HTML API from WordPress core).

```bash
git clone https://github.com/tentenco/wp2vercel.git
cd wp2vercel

docker compose -f dev/docker-compose.yml up -d
dev/seed.sh
dev/wp wp2vercel publish --skip-deploy
```

The development site runs at `http://localhost:8089` with the user `admin` and the password `admin`. `dev/wp` runs WP-CLI inside the container.

Run every check the way a release does. This lints the code and runs the unit tests on PHP 8.1 to 8.4 in Docker, then builds the zip:

```bash
bin/ci.sh
```

To have it run before each push, on the oldest and newest PHP version only:

```bash
git config core.hooksPath .githooks
```

For a quick run on your own PHP, from the plugin folder:

```bash
cd wp2vercel
WP_CORE_DIR=/path/to/wordpress phpunit
```

`dev/tools/check_output.py` checks an exported site for broken internal links and for strings that should never appear in the output.

## Code style

- PHP 8.1 syntax, `declare(strict_types=1)` in every file, PSR-4 under the `WP2Vercel` namespace, four-space indentation.
- No Composer packages and no JavaScript build step. The plugin has to run from a plain zip.
- No explanatory comments in code. If something needs explaining, give it a clearer name or move it into a small function. WP-CLI docblocks are the exception because WP-CLI reads them.
- Text shown to users goes through the WordPress translation functions with the `wp2vercel` text domain.
- After changing such text, refresh the translation template: `dev/wp i18n make-pot wp-content/plugins/wp2vercel wp-content/plugins/wp2vercel/languages/wp2vercel.pot --exclude=tests`. Translations go next to it as `wp2vercel-<locale>.po`, compiled with `wp i18n make-mo` and `wp i18n make-json`.
- Escape output and check capabilities and nonces the way WordPress core does.

## Pull requests

1. Open an issue first for anything larger than a small fix, so we can agree on the approach.
2. Branch from `main` and keep the change to one topic.
3. Add or update unit tests for the behaviour you change. Parsing, URL rewriting, redirects and the leak scan all have tests you can copy from.
4. Run `bin/ci.sh` and a publish in the Docker site before you push.
5. Describe what changed and how you tested it in the pull request.

By contributing you agree that your contribution is licensed under the GNU General Public License, version 2 or later, the same license as the project.
