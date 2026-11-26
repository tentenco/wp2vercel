<?php

declare(strict_types=1);

namespace WP2Vercel\Admin;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildRepository;
use WP2Vercel\Settings;

final class SiteHealth
{
    public function __construct(private readonly Settings $settings, private readonly BuildRepository $builds)
    {
    }

    public function register(): void
    {
        add_filter('debug_information', [$this, 'add']);
    }

    public function add(mixed $information): array
    {
        $information = (array) $information;
        $latest = $this->builds->latest(1)[0] ?? null;
        $information['wp2vercel'] = [
            'label' => 'WP2Vercel',
            'description' => __('What support needs to know about publishing on this site. Access tokens are never part of it.', 'wp2vercel'),
            'fields' => (array) apply_filters('wp2vercel_site_health_fields', self::fields([
                'version' => WP2VERCEL_VERSION,
                'target' => $this->settings->string('deploy_target'),
                'production_url' => $this->settings->string('production_url'),
                'fetch_url' => $this->settings->string('fetch_url'),
                'home_url' => home_url('/'),
                'permalinks' => (string) get_option('permalink_structure'),
                'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'time_limit' => (int) ini_get('max_execution_time'),
                'latest' => $latest instanceof Build ? $latest->toArray() : null,
            ])),
        ];
        return $information;
    }

    public static function fields(array $facts): array
    {
        $latest = is_array($facts['latest'] ?? null) ? $facts['latest'] : null;
        return [
            'version' => ['label' => 'Version', 'value' => (string) ($facts['version'] ?? '')],
            'target' => ['label' => 'Publish target', 'value' => (string) ($facts['target'] ?? '')],
            'production_url' => ['label' => 'Public site address', 'value' => ($facts['production_url'] ?? '') === '' ? 'not set' : (string) $facts['production_url']],
            'fetch_url' => ['label' => 'Fetch address', 'value' => ($facts['fetch_url'] ?? '') === '' ? 'same as the WordPress address' : (string) $facts['fetch_url'], 'private' => true],
            'home_url' => ['label' => 'WordPress address', 'value' => (string) ($facts['home_url'] ?? ''), 'private' => true],
            'permalinks' => ['label' => 'Permalinks', 'value' => ($facts['permalinks'] ?? '') === '' ? 'plain (publishing needs another setting)' : (string) $facts['permalinks']],
            'cron' => ['label' => 'WP-Cron', 'value' => empty($facts['cron_disabled']) ? 'started by visits' : 'DISABLE_WP_CRON is set, a system cron job has to call it'],
            'time_limit' => ['label' => 'PHP time limit', 'value' => (int) ($facts['time_limit'] ?? 0) === 0 ? 'none' : (int) $facts['time_limit'] . ' seconds'],
            'latest' => ['label' => 'Latest publish', 'value' => $latest === null ? 'none yet' : sprintf(
                '#%d, %s, %s, %s: %s',
                (int) ($latest['id'] ?? 0),
                (string) ($latest['type'] ?? ''),
                (string) ($latest['status'] ?? ''),
                (string) (($latest['finished_at'] ?? '') ?: ($latest['updated_at'] ?? '')),
                (string) ($latest['message'] ?? '')
            )],
        ];
    }
}
