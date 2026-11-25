# Security policy

## Supported versions

Security fixes are released for the latest published version.

| Version | Supported |
| --- | --- |
| 0.1.x | Yes |

## Reporting a vulnerability

Please do not report security problems in public issues, discussions or pull requests.

Report privately through GitHub: open the **Security** tab of this repository and choose **Report a vulnerability**. If that option is not available to you, use the contact form at <https://wp2vercel.com/contact> and write "Security report" in the first line. We will reply with a private channel for the details.

A useful report includes:

- the WP2Vercel, WordPress and PHP versions
- the steps to reproduce the problem, or a proof of concept
- what an attacker could gain
- whether the problem is already public

## What to expect

- We confirm that we received your report within 3 working days.
- We tell you whether we can reproduce it within 10 working days.
- We agree a disclosure date with you once a fix is ready, and we credit you in the release notes unless you prefer otherwise.

## Scope

In scope: the plugin code in this repository. Examples are a way to make the plugin write files outside its output folder, read or leak stored tokens, start or change a publish without the `manage_options` capability, or publish content that the plugin is meant to leave out, such as drafts and password-protected posts.

Out of scope: vulnerabilities in WordPress core, in other plugins or themes, in GitHub or in Vercel. Report those to their maintainers. A leak scan that misses a pattern is a bug we want to hear about, and a normal issue is fine for it unless the report itself would expose a private site.
