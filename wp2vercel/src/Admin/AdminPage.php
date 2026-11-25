<?php

declare(strict_types=1);

namespace WP2Vercel\Admin;

final class AdminPage
{
    public const SLUG = 'wp2vercel';

    private const PUBLISH_ACTION = 'wp2vercel_publish_post';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('post_row_actions', [$this, 'addRowAction'], 10, 2);
        add_filter('page_row_actions', [$this, 'addRowAction'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(WP2VERCEL_FILE), [$this, 'addPluginLink']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            'WP2Vercel',
            'WP2Vercel',
            RestController::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-cloud-upload',
            81
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        $base = plugin_dir_url(WP2VERCEL_FILE) . 'assets/';
        wp_enqueue_style('wp2vercel-admin', $base . 'admin.css', [], WP2VERCEL_VERSION);
        wp_enqueue_script('wp2vercel-admin', $base . 'admin.js', ['wp-api-fetch', 'wp-i18n'], WP2VERCEL_VERSION, true);
        wp_set_script_translations('wp2vercel-admin', 'wp2vercel', WP2VERCEL_DIR . '/languages');
        wp_add_inline_script('wp2vercel-admin', 'window.wp2vercelBoot = ' . wp_json_encode([
            'publishPost' => $this->requestedPost(),
            'version' => WP2VERCEL_VERSION,
        ]) . ';', 'before');
        do_action('wp2vercel_admin_enqueue_scripts');
    }

    public function render(): void
    {
        echo '<div class="wrap wp2vercel"><h1>WP2Vercel</h1><div id="wp2vercel-app"></div></div>';
    }

    public function addRowAction(array $actions, \WP_Post $post): array
    {
        if ($post->post_status !== 'publish' || !current_user_can(RestController::CAPABILITY) || !is_post_type_viewable($post->post_type)) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url('admin.php?page=' . self::SLUG . '&publish_post=' . $post->ID),
            self::PUBLISH_ACTION . '_' . $post->ID
        );
        $actions['wp2vercel'] = sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Publish to static site', 'wp2vercel'));
        return $actions;
    }

    public function addPluginLink(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::SLUG)),
            esc_html__('Publish', 'wp2vercel')
        ));
        return $links;
    }

    private function requestedPost(): int
    {
        $postId = isset($_GET['publish_post']) ? absint($_GET['publish_post']) : 0;
        if ($postId === 0 || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), self::PUBLISH_ACTION . '_' . $postId)) {
            return 0;
        }
        return $postId;
    }
}
