<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

use WP2Vercel\Settings;

final class RenderContext
{
    public const TOKEN_OPTION = 'wp2vercel_render_token';

    public static function issueToken(): string
    {
        $token = bin2hex(random_bytes(24));
        update_option(self::TOKEN_OPTION, $token, false);
        return $token;
    }

    public static function revokeToken(): void
    {
        delete_option(self::TOKEN_OPTION);
    }

    public static function isExportRequest(): bool
    {
        $presented = $_SERVER['HTTP_X_WP2VERCEL_RENDER'] ?? '';
        $expected = get_option(self::TOKEN_OPTION);
        return is_string($presented) && $presented !== ''
            && is_string($expected) && $expected !== ''
            && hash_equals($expected, $presented);
    }

    public static function register(Settings $settings): void
    {
        if (!self::isExportRequest()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        remove_action('init', 'wp_cron');
        add_filter('show_admin_bar', '__return_false', PHP_INT_MAX);
        add_filter('the_generator', '__return_empty_string', PHP_INT_MAX);
        add_filter('xmlrpc_enabled', '__return_false', PHP_INT_MAX);
        add_filter('pings_open', '__return_false', PHP_INT_MAX);
        add_filter('wp_headers', static function (array $headers): array {
            unset($headers['X-Pingback'], $headers['Link']);
            return $headers;
        }, PHP_INT_MAX);

        add_filter('robots_txt', static function (string $output): string {
            $lines = array_filter(
                explode("\n", $output),
                static fn(string $line): bool => !preg_match('~/wp-admin/|/wp-login\.php|/wp-json/|/xmlrpc\.php~i', $line)
            );
            return implode("\n", $lines);
        }, PHP_INT_MAX);
        if (!$settings->bool('include_author_archives')) {
            add_filter('wp_sitemaps_add_provider', static function ($provider, string $name) {
                return $name === 'users' ? false : $provider;
            }, PHP_INT_MAX, 2);
        }
        add_action('template_redirect', static function (): void {
            $post = is_singular() ? get_queried_object() : null;
            if ($post instanceof \WP_Post && $post->post_password !== '') {
                status_header(403);
                nocache_headers();
                exit;
            }
        }, 0);

        add_action('init', static function () use ($settings): void {
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'wp_generator');
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('template_redirect', 'rest_output_link_header', 11);
            remove_action('template_redirect', 'wp_shortlink_header', 11);
            if (!$settings->bool('include_feeds')) {
                remove_action('wp_head', 'feed_links', 2);
                remove_action('wp_head', 'feed_links_extra', 3);
            }
        }, PHP_INT_MAX);
    }
}
