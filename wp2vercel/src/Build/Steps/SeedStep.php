<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;

final class SeedStep implements Step
{
    private const BATCH = 200;
    private const STAGES = ['basics', 'posts', 'terms', 'authors'];

    public function __construct(private readonly Environment $environment)
    {
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        if (!$build->isFull()) {
            $this->enqueue($build, $this->singleSeeds($build));
            return true;
        }

        $cursor = $build->state['seed'] ?? ['stage' => 0, 'offset' => 0, 'taxonomy' => 0];
        while (!$deadline->passed() && isset(self::STAGES[$cursor['stage']])) {
            $finished = match (self::STAGES[$cursor['stage']]) {
                'basics' => $this->seedBasics($build),
                'posts' => $this->seedPosts($build, $cursor),
                'terms' => $this->seedTerms($build, $cursor),
                'authors' => $this->seedAuthors($build, $cursor),
            };
            if ($finished) {
                $cursor = ['stage' => $cursor['stage'] + 1, 'offset' => 0, 'taxonomy' => 0];
            }
            $build->state['seed'] = $cursor;
        }

        if (isset(self::STAGES[$cursor['stage']])) {
            return false;
        }
        $this->environment->logger->info($build->id, sprintf('Queued %d seed URLs.', $this->environment->queue->remaining($build->id)));
        return true;
    }

    private function seedBasics(Build $build): bool
    {
        $urls = [
            home_url('/'),
            home_url('/robots.txt'),
            home_url('/favicon.ico'),
            home_url('/wp-sitemap.xml'),
            home_url('/sitemap.xml'),
            home_url('/sitemap_index.xml'),
            (string) $build->state['not_found_url'],
        ];
        foreach (get_post_types(['public' => true, 'has_archive' => true], 'names') as $postType) {
            $link = get_post_type_archive_link($postType);
            if (is_string($link)) {
                $urls[] = $link;
            }
        }
        $postsPage = (int) get_option('page_for_posts');
        if ($postsPage > 0) {
            $urls[] = (string) get_permalink($postsPage);
        }
        foreach ($this->environment->settings->lines('additional_urls') as $line) {
            $urls[] = $line;
        }
        $this->enqueue($build, (array) apply_filters('wp2vercel_seed_urls', $urls, $build->id));
        return true;
    }

    private function seedPosts(Build $build, array &$cursor): bool
    {
        global $wpdb;
        $types = array_values(array_diff(get_post_types(['public' => true], 'names'), ['attachment']));
        if ($types === []) {
            return true;
        }
        $placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish' AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d",
            array_merge($types, [(int) $cursor['offset'], self::BATCH])
        ));
        if ($ids === []) {
            return true;
        }
        $urls = [];
        foreach ($ids as $id) {
            $permalink = get_permalink((int) $id);
            if (is_string($permalink)) {
                $urls[] = $permalink;
            }
        }
        $this->enqueue($build, $urls);
        $cursor['offset'] = (int) end($ids);
        return count($ids) < self::BATCH;
    }

    private function seedTerms(Build $build, array &$cursor): bool
    {
        $taxonomies = array_values(get_taxonomies(['public' => true, 'publicly_queryable' => true], 'names'));
        $taxonomy = $taxonomies[$cursor['taxonomy']] ?? null;
        if ($taxonomy === null) {
            return true;
        }
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => self::BATCH,
            'offset' => (int) $cursor['offset'],
            'orderby' => 'id',
        ]);
        $urls = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $link = get_term_link($term);
            if (is_string($link)) {
                $urls[] = $link;
            }
        }
        $this->enqueue($build, $urls);
        if (count($urls) < self::BATCH) {
            $cursor['taxonomy']++;
            $cursor['offset'] = 0;
        } else {
            $cursor['offset'] += self::BATCH;
        }
        return false;
    }

    private function seedAuthors(Build $build, array &$cursor): bool
    {
        if (!$this->environment->settings->bool('include_author_archives')) {
            return true;
        }
        $authors = get_users([
            'has_published_posts' => true,
            'fields' => 'ID',
            'number' => self::BATCH,
            'offset' => (int) $cursor['offset'],
        ]);
        $this->enqueue($build, array_map(static fn($id): string => get_author_posts_url((int) $id), $authors));
        $cursor['offset'] += self::BATCH;
        return count($authors) < self::BATCH;
    }

    private function singleSeeds(Build $build): array
    {
        $urls = [home_url('/'), (string) $build->state['not_found_url']];
        foreach ((array) ($build->state['urls'] ?? []) as $url) {
            $urls[] = (string) $url;
        }
        foreach ((array) ($build->state['post_ids'] ?? []) as $postId) {
            $post = get_post((int) $postId);
            if ($post === null || $post->post_status !== 'publish') {
                continue;
            }
            $urls[] = (string) get_permalink($post);
            $archive = get_post_type_archive_link($post->post_type);
            if (is_string($archive)) {
                $urls[] = $archive;
            }
            foreach (get_object_taxonomies($post->post_type, 'names') as $taxonomy) {
                $terms = get_the_terms($post, $taxonomy);
                foreach (is_array($terms) ? $terms : [] as $term) {
                    $link = get_term_link($term);
                    if (is_string($link)) {
                        $urls[] = $link;
                    }
                }
            }
            if ($this->environment->settings->bool('include_author_archives')) {
                $urls[] = get_author_posts_url((int) $post->post_author);
            }
        }
        return $urls;
    }

    private function enqueue(Build $build, array $candidates): void
    {
        $environment = $this->environment;
        $urls = [];
        foreach ($candidates as $candidate) {
            $resolved = is_string($candidate) ? $environment->resolver->resolve($candidate) : null;
            if ($resolved !== null && $environment->filter()->allows($environment->resolver->path($resolved))) {
                $urls[] = $resolved;
            }
        }
        $environment->queue->enqueue($build->id, $urls);
    }
}
