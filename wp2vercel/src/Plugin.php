<?php

declare(strict_types=1);

namespace WP2Vercel;

use WP2Vercel\Admin\AdminPage;
use WP2Vercel\Admin\CopyDownload;
use WP2Vercel\Admin\RestController;
use WP2Vercel\Admin\SiteHealth;
use WP2Vercel\Build\BuildLogger;
use WP2Vercel\Build\BuildRepository;
use WP2Vercel\Build\BuildRunner;
use WP2Vercel\Build\Dispatcher;
use WP2Vercel\Cli\Command;
use WP2Vercel\Crawl\RenderContext;
use WP_CLI;

final class Plugin
{
    private static ?BuildRunner $runner = null;
    private static ?Settings $settings = null;
    private static ?Secrets $secrets = null;

    public static function boot(): void
    {
        Database::migrate();
        RenderContext::register(self::settings());

        add_action(BuildRunner::CRON_HOOK, static function (): void {
            self::runner()->tick();
        });
        (new Dispatcher(self::settings()))->register(self::runner());
        add_action('rest_api_init', static function (): void {
            (new RestController(self::runner(), self::settings(), self::secrets()))->register();
        });
        add_action('init', static function (): void {
            load_plugin_textdomain('wp2vercel', false, dirname(plugin_basename(WP2VERCEL_FILE)) . '/languages');
        });
        add_filter('wpmu_drop_tables', static fn($tables, $siteId) => array_merge((array) $tables, Database::tablesOfSite((int) $siteId)), 10, 2);

        if (is_admin()) {
            (new AdminPage())->register();
            (new CopyDownload(self::runner()))->register();
            (new SiteHealth(self::settings(), self::runner()->builds()))->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('wp2vercel', new Command(self::runner(), self::settings(), self::secrets()));
        }
    }

    public static function runner(): BuildRunner
    {
        return self::$runner ??= new BuildRunner(
            self::settings(),
            self::secrets(),
            new BuildRepository(),
            new BuildLogger(),
            new Dispatcher(self::settings())
        );
    }

    public static function settings(): Settings
    {
        return self::$settings ??= new Settings();
    }

    public static function secrets(): Secrets
    {
        return self::$secrets ??= new Secrets();
    }

    public static function activate(): void
    {
        Database::migrate();
    }

    public static function deactivate(bool $networkWide = false): void
    {
        self::onEverySite($networkWide, static function (): void {
            wp_clear_scheduled_hook(BuildRunner::CRON_HOOK);
            RenderContext::revokeToken();
            Dispatcher::revoke();
        });
    }

    public static function onEverySite(bool $everySite, callable $task): void
    {
        if (!$everySite || !is_multisite()) {
            $task();
            return;
        }
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                $task();
            } finally {
                restore_current_blog();
            }
        }
    }
}
