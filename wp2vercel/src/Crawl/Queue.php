<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

use WP2Vercel\Database;

final class Queue
{
    public const QUEUED = 'queued';
    public const FETCHED = 'fetched';
    public const REDIRECT = 'redirect';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;

    private const INSERT_CHUNK = 100;

    public function enqueue(int $buildId, array $urls, string $foundOn = ''): int
    {
        global $wpdb;
        $added = 0;
        foreach (array_chunk(array_values(array_unique($urls)), self::INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $url) {
                $placeholders[] = "(%d, %s, %s, 'queued', '', %s)";
                array_push($values, $buildId, sha1($url), $url, $foundOn);
            }
            $added += (int) $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . Database::urls() . ' (build_id, url_hash, url, status, file_path, found_on) VALUES ' . implode(', ', $placeholders),
                $values
            ));
        }
        return $added;
    }

    public function claim(int $buildId, int $limit, int $leaseSeconds): array
    {
        global $wpdb;
        $now = time();
        $candidates = $wpdb->get_results($wpdb->prepare(
            'SELECT id, url, found_on, attempts, claimed_at FROM ' . Database::urls()
            . " WHERE build_id = %d AND status = 'queued' AND attempts < %d AND claimed_at < %d ORDER BY id ASC LIMIT %d",
            $buildId,
            self::MAX_ATTEMPTS,
            $now - $leaseSeconds,
            $limit
        ), ARRAY_A);

        $claimed = [];
        foreach (is_array($candidates) ? $candidates : [] as $row) {
            $won = $wpdb->query($wpdb->prepare(
                'UPDATE ' . Database::urls() . ' SET claimed_at = %d, attempts = attempts + 1 WHERE id = %d AND claimed_at = %d AND status = %s',
                $now,
                $row['id'],
                $row['claimed_at'],
                self::QUEUED
            ));
            if ($won === 1) {
                $claimed[] = new QueueItem((int) $row['id'], (string) $row['url'], (string) $row['found_on'], (int) $row['attempts'] + 1);
            }
        }
        return $claimed;
    }

    public function complete(QueueItem $item, string $status, int $httpStatus = 0, string $contentType = '', string $filePath = '', string $note = ''): void
    {
        global $wpdb;
        $wpdb->update(Database::urls(), [
            'status' => $status,
            'http_status' => $httpStatus,
            'content_type' => mb_substr($contentType, 0, 120),
            'file_path' => $filePath,
            'note' => mb_substr($note, 0, 255),
        ], ['id' => $item->id]);
    }

    public function retryLater(QueueItem $item, string $note): void
    {
        global $wpdb;
        if ($item->attempts >= self::MAX_ATTEMPTS) {
            $this->complete($item, self::FAILED, 0, '', '', $note);
            return;
        }
        $wpdb->update(Database::urls(), ['claimed_at' => 0, 'note' => mb_substr($note, 0, 255)], ['id' => $item->id]);
    }

    public function remaining(int $buildId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Database::urls() . " WHERE build_id = %d AND status = 'queued'",
            $buildId
        ));
    }

    public function failExhausted(int $buildId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Database::urls() . " SET status = 'failed', note = IF(note = '', 'Gave up after repeated attempts', note) WHERE build_id = %d AND status = 'queued' AND attempts >= %d",
            $buildId,
            self::MAX_ATTEMPTS
        ));
    }

    public function counts(int $buildId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT status, COUNT(*) AS total FROM ' . Database::urls() . ' WHERE build_id = %d GROUP BY status',
            $buildId
        ), ARRAY_A);
        $counts = [self::QUEUED => 0, self::FETCHED => 0, self::REDIRECT => 0, self::SKIPPED => 0, self::FAILED => 0];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    public function listByStatus(int $buildId, string $status, int $limit = 100): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT url, http_status, found_on, note FROM ' . Database::urls() . ' WHERE build_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
            $buildId,
            $status,
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function countHtmlPages(int $buildId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Database::urls() . " WHERE build_id = %d AND status = 'fetched' AND content_type LIKE %s",
            $buildId,
            'text/html%'
        ));
    }
}
