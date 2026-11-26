<?php

declare(strict_types=1);

namespace WP2Vercel\Admin;

use RuntimeException;
use WP2Vercel\Build\BuildRunner;
use WP2Vercel\Output\ExportArchive;

final class CopyDownload
{
    private const ACTION = 'wp2vercel_download_copy';
    private const ZIP_SECONDS = 300;

    public function __construct(private readonly BuildRunner $runner)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public static function url(BuildRunner $runner): ?string
    {
        if (!ExportArchive::available() || $runner->builds()->active() !== null || !ExportArchive::hasFiles($runner->exportFolder())) {
            return null;
        }
        return add_query_arg(['action' => self::ACTION, '_wpnonce' => wp_create_nonce(self::ACTION)], admin_url('admin-post.php'));
    }

    public function handle(): void
    {
        if (!current_user_can(RestController::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to download the copy of this site.', 'wp2vercel'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        if (!ExportArchive::available()) {
            $this->stop(__('PHP on this server has no zip support (the zip extension), so the copy cannot be packed here.', 'wp2vercel'));
        }
        if (($active = $this->runner->builds()->active()) !== null) {
            $this->stop(sprintf(__('Publish #%d has not ended yet. Download the copy when it has, so the zip holds one whole version.', 'wp2vercel'), $active->id));
        }

        @set_time_limit(self::ZIP_SECONDS);
        $zipFile = wp_tempnam('wp2vercel-copy.zip');
        register_shutdown_function(static function () use ($zipFile): void {
            if (is_file($zipFile)) {
                @unlink($zipFile);
            }
        });
        try {
            ExportArchive::write($this->runner->exportFolder(), $zipFile);
        } catch (RuntimeException $error) {
            $this->stop($error->getMessage());
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::fileName() . '"');
        header('Content-Length: ' . (string) filesize($zipFile));
        header('X-Content-Type-Options: nosniff');
        readfile($zipFile);
        exit;
    }

    public static function fileName(?string $home = null, ?int $time = null): string
    {
        $home ??= home_url('/');
        $host = (string) parse_url($home, PHP_URL_HOST);
        $path = trim(rawurldecode((string) parse_url($home, PHP_URL_PATH)), '/');
        $name = preg_replace('~[^a-z0-9.-]+~', '-', strtolower($host . ($path === '' ? '' : '-' . $path))) ?: 'site';
        return trim($name, '-.') . '-static-' . gmdate('Y-m-d-His', $time ?? time()) . '.zip';
    }

    private function stop(string $message): never
    {
        wp_die(
            '<p>' . esc_html($message) . '</p><p><a href="' . esc_url(admin_url('admin.php?page=' . AdminPage::SLUG)) . '">' . esc_html__('Back to WP2Vercel', 'wp2vercel') . '</a></p>',
            esc_html__('Download the copy', 'wp2vercel'),
            ['response' => 409]
        );
    }
}
