<?php

declare(strict_types=1);

namespace WP2Vercel\Cli;

use Throwable;
use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\BuildRunner;
use WP2Vercel\Crawl\Queue;
use WP2Vercel\Secrets;
use WP2Vercel\Settings;
use WP_CLI;
use WP_CLI\Utils;

final class Command
{
    public function __construct(
        private readonly BuildRunner $runner,
        private readonly Settings $settings,
        private readonly Secrets $secrets,
    ) {
    }

    /**
     * Publishes the site as static files.
     *
     * ## OPTIONS
     *
     * [--post=<ids>]
     * : Comma-separated post IDs. Publishes only those pages into the existing output.
     *
     * [--skip-deploy]
     * : Export the files without pushing to GitHub, Vercel or Netlify.
     *
     * [--fail-on-leak]
     * : Exit with an error when the leak scan reports findings.
     *
     * ## EXAMPLES
     *
     *     wp wp2vercel publish
     *     wp wp2vercel publish --post=42,57
     *     wp wp2vercel publish --skip-deploy --fail-on-leak
     *
     * @when after_wp_load
     */
    public function publish(array $arguments, array $flags): void
    {
        $postIds = array_filter(array_map('intval', explode(',', (string) ($flags['post'] ?? ''))));
        $this->runner->logger()->listen(static function (string $level, string $message): void {
            $level === 'info' ? WP_CLI::log($message) : WP_CLI::warning($message);
        });

        try {
            $build = $this->runner->start($postIds === [] ? Build::TYPE_FULL : Build::TYPE_SINGLE, [
                'deploy' => !Utils\get_flag_value($flags, 'skip-deploy', false),
                'post_ids' => $postIds,
            ], false);
        } catch (BuildFailed $error) {
            WP_CLI::error($error->getMessage());
        }

        $build = $this->runner->runToCompletion($build->id);
        $this->runner->logger()->listen(null);

        if ($build->isWaiting()) {
            WP_CLI::warning(sprintf('Publish #%d is paused and waiting: %s.', $build->id, (string) ($build->state['waiting']['reason'] ?? 'paused')));
            return;
        }
        if ($build->status !== Build::STATUS_COMPLETED) {
            WP_CLI::error($build->message);
        }
        $leaks = (int) ($build->state['scan']['total'] ?? 0);
        if ($leaks > 0 && Utils\get_flag_value($flags, 'fail-on-leak', false)) {
            WP_CLI::error(sprintf('Leak scan reported %d findings.', $leaks));
        }
        WP_CLI::success($build->message);
    }

    /**
     * Shows the most recent publish runs.
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     *
     * @when after_wp_load
     */
    public function status(array $arguments, array $flags): void
    {
        $queue = new Queue();
        $rows = [];
        foreach ($this->runner->builds()->latest(10) as $build) {
            $counts = $queue->counts($build->id);
            $rows[] = [
                'id' => $build->id,
                'type' => $build->type,
                'status' => $build->status,
                'step' => $build->step,
                'saved' => $counts[Queue::FETCHED],
                'failed' => $counts[Queue::FAILED],
                'leaks' => (int) ($build->state['scan']['total'] ?? 0),
                'message' => $build->message,
            ];
        }
        Utils\format_items($flags['format'] ?? 'table', $rows, ['id', 'type', 'status', 'step', 'saved', 'failed', 'leaks', 'message']);
    }

    /**
     * Lists leak scan findings of the latest publish run.
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     *
     * @when after_wp_load
     */
    public function leaks(array $arguments, array $flags): void
    {
        $build = $this->runner->builds()->latest(1)[0] ?? null;
        Utils\format_items($flags['format'] ?? 'table', $build?->state['scan']['findings'] ?? [], ['type', 'file', 'line', 'excerpt']);
    }

    /**
     * Lists URLs that failed in the latest publish run.
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     *
     * @when after_wp_load
     */
    public function failures(array $arguments, array $flags): void
    {
        $build = $this->runner->builds()->latest(1)[0] ?? null;
        $rows = $build === null ? [] : (new Queue())->listByStatus($build->id, Queue::FAILED, 500);
        Utils\format_items($flags['format'] ?? 'table', $rows, ['url', 'http_status', 'note', 'found_on']);
    }

    /**
     * Cancels the running publish.
     *
     * @when after_wp_load
     */
    public function cancel(): void
    {
        $build = $this->runner->cancel();
        $build === null ? WP_CLI::log('Nothing is running.') : WP_CLI::success(sprintf('Cancelled publish #%d.', $build->id));
    }

    /**
     * Reads or changes a setting.
     *
     * ## OPTIONS
     *
     * <action>
     * : get, set or list.
     *
     * [<key>]
     * : Setting name. Secrets: github_token, vercel_token, netlify_token, fetch_basic_auth_password.
     *
     * [<value>]
     * : New value for set. For a secret, - reads the value from standard input.
     *
     * ## EXAMPLES
     *
     *     wp wp2vercel config list
     *     wp wp2vercel config set production_url https://www.example.com
     *     wp wp2vercel config set deploy_target vercel
     *     wp wp2vercel config set github_token - < token.txt
     *
     * @when after_wp_load
     */
    public function config(array $arguments): void
    {
        [$action, $key, $value] = $arguments + [null, null, null];

        if ($action === 'list') {
            $rows = [];
            foreach ($this->settings->all() as $name => $current) {
                $rows[] = ['key' => $name, 'value' => is_bool($current) ? ($current ? 'true' : 'false') : str_replace("\n", ' | ', (string) $current)];
            }
            foreach (Secrets::NAMES as $name) {
                $rows[] = ['key' => $name, 'value' => $this->secrets->has($name) ? '(set)' : '(empty)'];
            }
            Utils\format_items('table', $rows, ['key', 'value']);
            return;
        }
        if ($key === null) {
            WP_CLI::error('Name a setting. `wp wp2vercel config list` shows all of them.');
        }
        $isSecret = in_array($key, Secrets::NAMES, true);
        if (!$isSecret && !array_key_exists($key, $this->settings->all())) {
            WP_CLI::error('Unknown setting: ' . $key);
        }
        if ($action === 'get') {
            WP_CLI::log($isSecret ? ($this->secrets->has($key) ? '(set)' : '(empty)') : self::shown($this->settings->all()[$key]));
            return;
        }
        if ($action !== 'set' || $value === null) {
            WP_CLI::error('Usage: wp wp2vercel config set <key> <value>');
        }
        if (($running = $this->runner->runningPublish()) !== null) {
            WP_CLI::error(BuildRunner::settingsWaitMessage($running->id));
        }
        if ($isSecret) {
            $secret = $value === '-' ? trim((string) stream_get_contents(STDIN)) : $value;
            if ($secret === '') {
                WP_CLI::error($key . ' was not changed: nothing was read from standard input.');
            }
            $this->secrets->set($key, $secret);
            WP_CLI::success($key . ' saved.');
            return;
        }
        if (Settings::rejected([$key => $value]) !== []) {
            WP_CLI::error(sprintf('%1$s was not changed: %2$s is not a valid value.', $key, self::shown($value)));
        }
        $saved = $this->settings->update([$key => $value]);
        WP_CLI::success($key . ' = ' . self::shown($saved[$key]));
    }

    private static function shown(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Checks the GitHub, Vercel or Netlify credentials.
     *
     * @when after_wp_load
     */
    public function test(): void
    {
        $deployer = $this->runner->environment()->deployer($this->settings->string('deploy_target'));
        if ($deployer === null) {
            WP_CLI::error('No publish target is configured.');
        }
        if (($problem = $deployer->configurationError()) !== null) {
            WP_CLI::error($problem);
        }
        try {
            WP_CLI::success($deployer->testConnection());
        } catch (Throwable $error) {
            WP_CLI::error($error->getMessage());
        }
    }
}
