<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

final class Lock
{
    private const OPTION = 'wp2vercel_runner_lock';

    private ?string $token = null;

    public function acquire(int $ttlSeconds): bool
    {
        global $wpdb;
        $now = time();
        $token = bin2hex(random_bytes(8));
        $value = ($now + $ttlSeconds) . ':' . $token;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::OPTION,
            $value
        ));
        if ($inserted === 1) {
            $this->token = $token;
            return true;
        }

        $current = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION
        ));
        if ((int) $current > $now) {
            return false;
        }

        $taken = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            self::OPTION,
            $current
        ));
        if ($taken === 1) {
            $this->token = $token;
            return true;
        }
        return false;
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            self::OPTION,
            '%:' . $wpdb->esc_like($this->token)
        ));
        $this->token = null;
    }
}
