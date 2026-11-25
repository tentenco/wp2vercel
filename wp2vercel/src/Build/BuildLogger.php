<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use WP2Vercel\Database;

final class BuildLogger
{
    private $listener = null;

    public function listen(?callable $listener): void
    {
        $this->listener = $listener;
    }

    public function info(int $buildId, string $message): void
    {
        $this->write($buildId, 'info', $message);
    }

    public function warning(int $buildId, string $message): void
    {
        $this->write($buildId, 'warning', $message);
    }

    public function error(int $buildId, string $message): void
    {
        $this->write($buildId, 'error', $message);
    }

    public function tail(int $buildId, int $afterId = 0, int $limit = 200): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, level, message, created_at FROM ' . Database::logs() . ' WHERE build_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
            $buildId,
            $afterId,
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function write(int $buildId, string $level, string $message): void
    {
        global $wpdb;
        $wpdb->insert(Database::logs(), [
            'build_id' => $buildId,
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'created_at' => current_time('mysql', true),
        ]);
        if ($this->listener !== null) {
            ($this->listener)($level, $message);
        }
    }
}
