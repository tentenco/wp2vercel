<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use WP2Vercel\Settings;
use WP2Vercel\Url\Origin;

final class Dispatcher
{
    public const ACTION = 'wp2vercel_run';
    public const TOKEN_OPTION = 'wp2vercel_run_token';

    private const MAX_WAIT_SECONDS = 10;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(BuildRunner $runner): void
    {
        $handler = function () use ($runner): void {
            $this->handle($runner);
        };
        add_action('wp_ajax_nopriv_' . self::ACTION, $handler);
        add_action('wp_ajax_' . self::ACTION, $handler);
    }

    public function dispatch(): void
    {
        $token = get_option(self::TOKEN_OPTION);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(24));
            update_option(self::TOKEN_OPTION, $token, false);
        }

        $origin = new Origin(home_url('/'));
        $fetchUrl = $this->settings->string('fetch_url');
        $fetchOrigin = $fetchUrl === '' ? $origin : new Origin($fetchUrl);
        $headers = [];
        if (!$origin->sameAuthorityAs($fetchOrigin)) {
            $headers['Host'] = $origin->authority;
        }
        if ($origin->scheme === 'https' && $fetchOrigin->scheme === 'http') {
            $headers['X-Forwarded-Proto'] = 'https';
        }
        $user = $this->settings->string('fetch_basic_auth_user');
        if ($user !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . \WP2Vercel\Plugin::secrets()->get('fetch_basic_auth_password'));
        }

        wp_remote_post($fetchOrigin->base() . (string) wp_parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH), [
            'blocking' => false,
            'timeout' => 1,
            'redirection' => 0,
            'sslverify' => $this->settings->bool('fetch_ssl_verify'),
            'headers' => $headers,
            'body' => ['action' => self::ACTION, 'token' => $token],
        ]);
    }

    public static function revoke(): void
    {
        delete_option(self::TOKEN_OPTION);
    }

    private function handle(BuildRunner $runner): void
    {
        $expected = get_option(self::TOKEN_OPTION);
        $presented = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $presented)) {
            status_header(403);
            exit;
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $active = $runner->builds()->active();
        $wait = (int) ($active?->state['deployment']['wait_until'] ?? 0) - time();
        if ($wait > 0) {
            sleep(min($wait, self::MAX_WAIT_SECONDS));
        }

        $runner->tick();
        exit;
    }
}
