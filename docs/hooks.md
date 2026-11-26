# Hooks

WP2Vercel exposes a small set of WordPress filters and actions, and a JavaScript object on its admin screen. They are the supported way to change what is exported without editing the plugin.

All examples go in a small plugin of your own or in a must-use plugin.

## Filters

### `wp2vercel_seed_urls`

The URLs a full publish starts from, before posts, terms and authors are added from the database.

```php
add_filter('wp2vercel_seed_urls', function (array $urls, int $buildId): array {
    $urls[] = home_url('/landing/spring-sale/');
    return $urls;
}, 10, 2);
```

For a fixed list, the **Extra URLs** setting does the same thing without code.

### `wp2vercel_redirects`

The redirect rules that go into `vercel.json`. Each rule is a `WP2Vercel\Redirects\RedirectRule`. Create one with `RedirectRule::create($source, $destination, $status)`, which returns `null` for input it cannot use.

```php
use WP2Vercel\Redirects\RedirectRule;

add_filter('wp2vercel_redirects', function (array $rules): array {
    $rules[] = RedirectRule::create('/old-shop/', '/shop/', 301);
    return array_filter($rules);
});
```

### `wp2vercel_output`

The content of every text file (HTML, CSS, JavaScript, JSON, XML and plain text) after the private address has been rewritten and before the file is saved.

```php
add_filter('wp2vercel_output', function (string $content, string $kind, string $file, $build): string {
    if ($kind !== 'html') {
        return $content;
    }
    return str_replace('</body>', '<script src="/analytics.js" defer></script></body>', $content);
}, 10, 4);
```

`$kind` is one of `html`, `css`, `js`, `json`, `xml` or `txt`. `$file` is the path inside the exported site, such as `about/index.html`.

### `wp2vercel_keep_comment_forms`

WordPress's comment form is left out of the published pages, because it posts to `wp-comments-post.php`, which does not exist on the static site. Return `true` to keep it, for example when another plugin replaces the form with a hosted comment service and only shows it while comments are open.

```php
add_filter('wp2vercel_keep_comment_forms', '__return_true');
```

### `wp2vercel_site_health_fields`

Fields of the WP2Vercel section under Tools → Site Health → Info. Each field has a `label` and a `value`; add `'private' => true` to keep it out of the text that "Copy site info to clipboard" produces. Never add a token or a key.

```php
add_filter('wp2vercel_site_health_fields', function (array $fields): array {
    $fields['my_cdn'] = ['label' => 'CDN zone', 'value' => 'eu-west'];
    return $fields;
});
```

### `wp2vercel_report_forms`

After the export, the Publish tab lists the pages with a form that posts to the site itself, because such a form has nothing to send to once the site is static. Forms sent to another host are not listed. Return `false` when your own code takes care of these forms. WP2Vercel Pro does this while its Forms feature is on.

```php
add_filter('wp2vercel_report_forms', '__return_false');
```

### `wp2vercel_scan_rules`

Extra patterns for the leak scan. Each rule has a `type` (a short label shown in the findings table) and a `pattern` (a regular expression with delimiters). Rules with a broken pattern are ignored.

```php
add_filter('wp2vercel_scan_rules', function (array $rules, $build): array {
    $rules[] = ['type' => 'staging_host', 'pattern' => '~staging\.example\.com~i'];
    return $rules;
}, 10, 2);
```

### `wp2vercel_scan_finding`

Each finding before it is counted. Return `null` to drop it, or the array to keep it. A finding has `type`, `file`, `line` and `excerpt`.

```php
add_filter('wp2vercel_scan_finding', function ($finding, $build) {
    return str_starts_with($finding['file'], 'docs/') ? null : $finding;
}, 10, 2);
```

### `wp2vercel_block_on_findings`

Return `true` to stop a publish before the deploy step when the scan reports at least one finding. The build is marked as failed and nothing is pushed.

```php
add_filter('wp2vercel_block_on_findings', '__return_true');
```

### `wp2vercel_build_steps`

The ordered list of step names for a publish. A full publish starts with `setup`, `seed`, `crawl`, `finalize`, `scan`, `deploy`. The list is stored with the build when the publish is created, and it has to start with `setup`.

```php
add_filter('wp2vercel_build_steps', function (array $steps, $build): array {
    array_splice($steps, array_search('deploy', $steps, true), 0, ['notify_legal']);
    return $steps;
}, 10, 2);
```

### `wp2vercel_step`

Returns the object that runs a step you added. It must implement `WP2Vercel\Build\Steps\Step`. `run()` returns `true` when the step is done and `false` when it should be called again, which lets a long step spread its work over several requests.

```php
add_filter('wp2vercel_step', function ($step, string $name, $environment) {
    return $name === 'notify_legal' ? new NotifyLegalStep() : $step;
}, 10, 3);
```

To pause a publish, set `$build->state['waiting']` to a non-empty array and return `false`. The build status becomes `waiting`. Continue it later with `WP2Vercel\Plugin::runner()->resume()`, or stop it with `cancel()`. `resume()` clears the waiting flag and merges the array you pass into the build state, so the step can read the decision on its next run.

### `wp2vercel_vercel_config`

The contents of `vercel.json` as an array, after redirects and headers have been added. With Netlify as the target, `_redirects` and `_headers` are written from the filtered array, so a rule added here reaches both hosts. Netlify has no equivalent for `has` conditions other than the browser language, nor for route patterns with `:name` or `(...)`; such rules stay in `vercel.json` and are named in the publish log.

```php
add_filter('wp2vercel_vercel_config', function (array $config, $build): array {
    $config['cleanUrls'] = true;
    return $config;
}, 10, 2);
```

### `wp2vercel_secret_names`

Extra names for the encrypted secret store. A name uses lowercase letters, digits and underscores. A secret named `crm_token` can then be saved through `WP2Vercel\Secrets::set()`, or defined as the constant or environment variable `WP2VERCEL_CRM_TOKEN`.

```php
add_filter('wp2vercel_secret_names', fn(array $names): array => [...$names, 'crm_token']);
```

## Actions

### `wp2vercel_build_started`

Fires once when a publish is created. Receives the `WP2Vercel\Build\Build` object.

### `wp2vercel_finalize`

Fires after the crawl, before `vercel.json` is written. Use it to add files to the output.

```php
add_action('wp2vercel_finalize', function ($build, $environment, string $root): void {
    $environment->directory->write($root, 'health.txt', 'ok');
}, 10, 3);
```

### `wp2vercel_build_waiting`

Fires when a step pauses a publish. Receives the `WP2Vercel\Build\Build` object.

### `wp2vercel_build_finished`

Fires when a publish ends. Receives the build ID and the status: `completed`, `failed` or `cancelled`.

```php
add_action('wp2vercel_build_finished', function (int $buildId, string $status): void {
    if ($status === 'failed') {
        wp_mail(get_option('admin_email'), 'Publish failed', 'Build #' . $buildId . ' failed.');
    }
}, 10, 2);
```

### `wp2vercel_admin_enqueue_scripts`

Fires on the WP2Vercel admin screen after the plugin's own script is enqueued. Enqueue a script that depends on `wp2vercel-admin` to extend the screen.

## Detecting an export request

While a publish runs, WP2Vercel fetches pages with a one-time token. `WP2Vercel\Crawl\RenderContext::isExportRequest()` returns `true` for those requests, so you can change what the theme prints in the static copy only.

```php
add_action('wp_enqueue_scripts', function (): void {
    if (WP2Vercel\Crawl\RenderContext::isExportRequest()) {
        wp_dequeue_script('comment-reply');
    }
}, 100);
```

## Admin screen: `window.wp2vercel`

A script that depends on `wp2vercel-admin` can add to the admin screen.

| Method | Purpose |
| --- | --- |
| `registerTab(id, label, render)` | Adds a tab. `render(api)` returns a DOM node. |
| `registerPublishAction(render)` | Adds a button next to **Publish site**. |
| `registerPublishPanel(render)` | Adds a panel under the build card. |
| `registerFindingLabels(map)` | Readable names for your scan rule types. |
| `registerFindingsHelp(render)` | Adds a line above the leak scan findings. `render(api, build)` returns a DOM node or `null`. |
| `registerStepLabels(map)` | Readable names for build steps you added. |
| `el(tag, attributes, children)` | Small DOM helper used by the plugin itself. |
| `apiFetch`, `start(body)`, `refresh()`, `render()`, `setNotice(type, message)`, `snapshot()`, `isBusy()`, `openTab(id)` | Access to the REST client and the screen state. |

## WP-CLI

`wp help wp2vercel` lists the commands. They are also shown in the [README](../README.md#wp-cli).
