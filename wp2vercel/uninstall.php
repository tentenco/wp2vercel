<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Autoloader.php';

WP2Vercel\Autoloader::register(__DIR__ . '/src');

WP2Vercel\Plugin::onEverySite(true, static function (): void {
    wp_clear_scheduled_hook(WP2Vercel\Build\BuildRunner::CRON_HOOK);
    WP2Vercel\Output\SiteDirectory::fromUploads()->destroy();
    WP2Vercel\Database::drop();
    foreach ([
        WP2Vercel\Settings::OPTION,
        WP2Vercel\Secrets::OPTION,
        WP2Vercel\Output\SiteDirectory::SUFFIX_OPTION,
        WP2Vercel\Crawl\RenderContext::TOKEN_OPTION,
        WP2Vercel\Build\Dispatcher::TOKEN_OPTION,
        WP2Vercel\Build\BuildRunner::COPY_BEHIND_OPTION,
        'wp2vercel_runner_lock',
        'wp2vercel_unfinished_runs',
    ] as $option) {
        delete_option($option);
    }
});

if (is_multisite()) {
    WP2Vercel\Database::dropLeftoversOfDeletedSites();
}
