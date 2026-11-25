<?php

declare(strict_types=1);

namespace WP2Vercel;

final class Settings
{
    public const OPTION = 'wp2vercel_settings';

    public const TARGET_NONE = 'none';
    public const TARGET_GITHUB = 'github';
    public const TARGET_VERCEL = 'vercel';

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
    ];

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

    public function update(array $input): array
    {
        $clean = $this->all();
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $clean[$key] = $this->sanitize($key, $input[$key], $default);
        }
        update_option(self::OPTION, $clean, false);
        $this->cache = $clean;
        return $clean;
    }

    private function sanitize(string $key, mixed $value, mixed $default): mixed
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
            'production_url', 'fetch_url' => self::sanitizeBaseUrl($value),
            'deploy_target' => in_array($value, [self::TARGET_NONE, self::TARGET_GITHUB, self::TARGET_VERCEL], true) ? $value : self::TARGET_NONE,
            'github_repository' => preg_match('~^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$~', trim($value)) ? trim($value) : '',
            'github_branch', 'github_base_branch' => preg_match('~^[A-Za-z0-9_.\-/]+$~', trim($value)) ? trim($value) : (string) $default,
            'github_directory' => trim(preg_replace('~[^A-Za-z0-9_.\-/]~', '', $value) ?? '', '/'),
            'vercel_project' => strtolower(preg_replace('~[^A-Za-z0-9_.\-]~', '', $value) ?? ''),
            'vercel_team_id' => preg_replace('~[^A-Za-z0-9_\-]~', '', $value) ?? '',
            'additional_urls', 'exclude_patterns', 'extra_redirects' => trim(str_replace("\r", '', wp_strip_all_tags($value))),
            default => sanitize_text_field($value),
        };
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
