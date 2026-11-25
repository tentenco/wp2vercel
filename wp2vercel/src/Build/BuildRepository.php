<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use WP2Vercel\Database;

final class BuildRepository
{
    public function create(string $type, array $state): Build
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Database::builds(), [
            'type' => $type,
            'status' => Build::STATUS_PENDING,
            'step' => Build::STEPS[0],
            'state' => wp_json_encode($state),
            'message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return new Build((int) $wpdb->insert_id, $type, Build::STATUS_PENDING, Build::STEPS[0], $state, '', $now, $now, null);
    }

    public function find(int $id): ?Build
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::builds() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function active(): ?Build
    {
        global $wpdb;
        $row = $wpdb->get_row(
            'SELECT * FROM ' . Database::builds() . " WHERE status IN ('pending','running') ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function latest(int $limit = 10): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . Database::builds() . ' ORDER BY id DESC LIMIT %d', $limit),
            ARRAY_A
        );
        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    public function status(int $id): ?string
    {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . Database::builds() . ' WHERE id = %d', $id));
        return is_string($status) ? $status : null;
    }

    public function save(Build $build): void
    {
        global $wpdb;
        $build->updatedAt = current_time('mysql', true);
        $wpdb->update(Database::builds(), [
            'status' => $build->status,
            'step' => $build->step,
            'state' => wp_json_encode($build->state),
            'message' => $build->message,
            'updated_at' => $build->updatedAt,
            'finished_at' => $build->finishedAt,
        ], ['id' => $build->id]);
    }

    public function prune(int $keep): void
    {
        global $wpdb;
        $threshold = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . Database::builds() . ' ORDER BY id DESC LIMIT 1 OFFSET %d', $keep)
        );
        if ($threshold === 0) {
            return;
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Database::urls() . ' WHERE build_id <= %d', $threshold));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Database::logs() . ' WHERE build_id <= %d', $threshold));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Database::builds() . ' WHERE id <= %d', $threshold));
    }

    private static function hydrate(array $row): Build
    {
        $state = json_decode((string) $row['state'], true);
        return new Build(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['status'],
            (string) $row['step'],
            is_array($state) ? $state : [],
            (string) $row['message'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['finished_at'] === null ? null : (string) $row['finished_at'],
        );
    }
}
