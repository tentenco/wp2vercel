<?php

declare(strict_types=1);

namespace WP2Vercel\Redirects;

final class RedirectCollector
{
    public function __construct(private readonly array $settingLines)
    {
    }

    public function collect(): array
    {
        $rules = array_merge(
            $this->fromRedirectionPlugin(),
            $this->fromYoastPremium(),
            $this->fromRankMath(),
            $this->fromSafeRedirectManager(),
            $this->fromSimple301Redirects(),
            $this->fromSettings()
        );
        return array_values(array_filter((array) apply_filters('wp2vercel_redirects', $rules), static fn($rule): bool => $rule instanceof RedirectRule));
    }

    public function fromSettings(): array
    {
        $rules = [];
        foreach ($this->settingLines as $line) {
            $parts = preg_split('~\s+~', trim((string) $line)) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            $rules[] = RedirectRule::create($parts[0], $parts[1], isset($parts[2]) ? (int) $parts[2] : 301);
        }
        return array_filter($rules);
    }

    private function fromRedirectionPlugin(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';
        if (!$this->tableExists($table)) {
            return [];
        }
        $rows = $wpdb->get_results(
            "SELECT url, action_data, action_code FROM $table WHERE status = 'enabled' AND action_type = 'url' AND regex = 0 AND match_type = 'url' LIMIT 5000",
            ARRAY_A
        );
        $rules = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $rules[] = RedirectRule::create((string) $row['url'], (string) $row['action_data'], (int) $row['action_code']);
        }
        return array_filter($rules);
    }

    private function fromYoastPremium(): array
    {
        $stored = get_option('wpseo-premium-redirects-base');
        $rules = [];
        foreach (is_array($stored) ? $stored : [] as $redirect) {
            if (!is_array($redirect) || ($redirect['format'] ?? 'plain') !== 'plain') {
                continue;
            }
            $rules[] = RedirectRule::create(
                '/' . ltrim((string) ($redirect['origin'] ?? ''), '/'),
                self::rootRelative((string) ($redirect['url'] ?? '')),
                (int) ($redirect['type'] ?? 301)
            );
        }
        return array_filter($rules);
    }

    private function fromRankMath(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        if (!$this->tableExists($table)) {
            return [];
        }
        $rows = $wpdb->get_results("SELECT sources, url_to, header_code FROM $table WHERE status = 'active' LIMIT 5000", ARRAY_A);
        $rules = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $sources = maybe_unserialize($row['sources']);
            foreach (is_array($sources) ? $sources : [] as $source) {
                if (!is_array($source) || ($source['comparison'] ?? '') !== 'exact') {
                    continue;
                }
                $rules[] = RedirectRule::create(
                    '/' . ltrim((string) ($source['pattern'] ?? ''), '/'),
                    self::rootRelative((string) $row['url_to']),
                    (int) $row['header_code']
                );
            }
        }
        return array_filter($rules);
    }

    private function fromSafeRedirectManager(): array
    {
        if (!post_type_exists('redirect_rule')) {
            return [];
        }
        $ids = get_posts(['post_type' => 'redirect_rule', 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids']);
        $rules = [];
        foreach ($ids as $id) {
            if (get_post_meta($id, '_redirect_rule_from_regex', true)) {
                continue;
            }
            $rules[] = RedirectRule::create(
                (string) get_post_meta($id, '_redirect_rule_from', true),
                self::rootRelative((string) get_post_meta($id, '_redirect_rule_to', true)),
                (int) get_post_meta($id, '_redirect_rule_status_code', true)
            );
        }
        return array_filter($rules);
    }

    private function fromSimple301Redirects(): array
    {
        $stored = get_option('301_redirects');
        $rules = [];
        foreach (is_array($stored) ? $stored : [] as $from => $to) {
            if (is_string($from) && is_string($to) && !str_contains($from, '*')) {
                $rules[] = RedirectRule::create($from, $to, 301);
            }
        }
        return array_filter($rules);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private static function rootRelative(string $destination): string
    {
        if ($destination === '' || preg_match('~^(?:https?:)?//~i', $destination) || $destination[0] === '/') {
            return $destination;
        }
        return '/' . $destination;
    }
}
