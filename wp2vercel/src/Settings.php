<?php

declare(strict_types=1);

namespace WP2Vercel;

final class Settings
{
    public const OPTION = 'wp2vercel_settings';

    public const TARGET_NONE = 'none';
    public const TARGET_GITHUB = 'github';
    public const TARGET_VERCEL = 'vercel';
    public const TARGET_NETLIFY = 'netlify';
    public const TARGETS = [self::TARGET_NONE, self::TARGET_GITHUB, self::TARGET_VERCEL, self::TARGET_NETLIFY];

    private const DEFAULTS = [
        'production_url' => '',
        'fetch_url' => '',
        'fetch_basic_auth_user' => '',
        'fetch_ssl_verify' => true,
        'fetch_concurrency' => 4,
        'fetch_timeout' => 30,
        'additional_urls' => '',
        'exclude_patterns' => '',
        'extra_redirects' => '',
        'include_feeds' => false,
        'include_author_archives' => false,
        'deploy_target' => self::TARGET_NONE,
        'github_repository' => '',
        'github_branch' => 'wp2vercel-preview',
        'github_base_branch' => 'main',
        'github_directory' => '',
        'vercel_project' => '',
        'vercel_team_id' => '',
        'vercel_production' => false,
        'netlify_site' => '',
        'netlify_production' => false,
    ];

    private const VALIDATED = ['production_url', 'fetch_url', 'github_repository', 'github_branch', 'github_base_branch', 'netlify_site'];
    private const BRANCHES = ['github_branch', 'github_base_branch'];
    private const NETWORK_ONLY = ['fetch_url'];
    private const BRANCH_NAME = '~^[A-Za-z0-9_.\-/]+$~';

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_merge(self::DEFAULTS, array_intersect_key(is_array($stored) ? $stored : [], self::DEFAULTS));
        }
        return $this->cache;
    }

    public function string(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    public function int(string $key): int
    {
        return (int) ($this->all()[$key] ?? 0);
    }

    public function lines(string $key): array
    {
        $lines = preg_split('~\R~', $this->string($key)) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== '' && $line[0] !== '#'));
    }

    public static function clean(string $key, mixed $value): mixed
    {
        return self::sanitize($key, $value, self::DEFAULTS[$key]);
    }

    public static function rejected(array $input): array
    {
        return array_values(array_filter(
            self::VALIDATED,
            static fn(string $key): bool => array_key_exists($key, $input) && !self::accepts($key, $input[$key])
        ));
    }

    private static function accepts(string $key, mixed $value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $typed = trim((string) $value);
        if ($typed === '') {
            return true;
        }
        return in_array($key, self::BRANCHES, true) ? preg_match(self::BRANCH_NAME, $typed) === 1 : self::clean($key, $typed) !== '';
    }

    public static function locked(): array
    {
        $mayChangeServerAccess = !is_multisite() || current_user_can('manage_network_options') || (defined('WP_CLI') && WP_CLI);
        return $mayChangeServerAccess ? [] : self::NETWORK_ONLY;
    }

    public function update(array $input): array
    {
        $clean = $this->all();
        $skipped = array_merge(self::rejected($input), self::locked());
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input) || in_array($key, $skipped, true)) {
                continue;
            }
            $clean[$key] = self::sanitize($key, $input[$key], $default);
        }
        update_option(self::OPTION, $clean, false);
        $this->cache = $clean;
        return $clean;
    }

    private static function sanitize(string $key, mixed $value, mixed $default): mixed
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if ($key === 'fetch_concurrency') {
            return max(1, min(16, (int) $value));
        }
        if ($key === 'fetch_timeout') {
            return max(5, min(300, (int) $value));
        }
        $value = is_scalar($value) ? (string) $value : '';
        return match ($key) {
            'production_url' => self::sanitizeBaseUrl(self::withScheme($value)),
            'fetch_url' => self::sanitizeBaseUrl($value),
            'deploy_target' => in_array($value, self::TARGETS, true) ? $value : self::TARGET_NONE,
            'github_repository' => self::repositoryName($value),
            'github_branch', 'github_base_branch' => preg_match(self::BRANCH_NAME, trim($value)) === 1 ? trim($value) : (string) $default,
            'github_directory' => trim(preg_replace('~[^A-Za-z0-9_.\-/]~', '', $value) ?? '', '/'),
            'vercel_project' => strtolower(preg_replace('~[^A-Za-z0-9_.\-]~', '', $value) ?? ''),
            'vercel_team_id' => preg_replace('~[^A-Za-z0-9_\-]~', '', $value) ?? '',
            'netlify_site' => self::netlifySite($value),
            'additional_urls', 'exclude_patterns', 'extra_redirects' => trim(str_replace("\r", '', wp_strip_all_tags($value))),
            default => sanitize_text_field($value),
        };
    }

    private static function withScheme(string $value): string
    {
        $value = trim($value);
        return preg_match('~^[a-z0-9\-]+(\.[a-z0-9\-]+)+(:\d+)?(/.*)?$~i', $value) === 1 ? 'https://' . $value : $value;
    }

    private static function repositoryName(string $value): string
    {
        $name = preg_replace('~^(?:https?://github\.com/|git@github\.com:)~i', '', trim($value)) ?? '';
        $name = preg_replace('~(?:\.git)?/*$~', '', $name) ?? '';
        return preg_match('~^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$~', $name) === 1 ? $name : '';
    }

    private static function netlifySite(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~', $value) === 1) {
            return $value;
        }
        if (preg_match('~^(?:https?://)?app\.netlify\.com/(?:sites|projects)/([a-z0-9-]+)(?:[/?#].*)?$~', $value, $match) === 1) {
            return $match[1] . '.netlify.app';
        }
        if (preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $value) === 1) {
            return $value . '.netlify.app';
        }
        $host = preg_replace('~^https?://~', '', $value) ?? '';
        $host = rtrim(strtok($host, '/?#') ?: '', '.');
        return preg_match('~^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$~', $host) === 1 ? $host : '';
    }

    private static function sanitizeBaseUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = wp_parse_url($value);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
