<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use RuntimeException;
use WP2Vercel\Build\Build;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Crawl\FetchResult;
use WP2Vercel\Crawl\Queue;
use WP2Vercel\Crawl\QueueItem;
use WP2Vercel\Output\SiteMeta;
use WP2Vercel\Redirects\RedirectRule;
use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\UrlResolver;

final class CrawlStep implements Step
{
    public const NOT_FOUND_FILE = '404.html';

    private const LEASE_SECONDS = 180;

    private string $root = '';
    private ?SiteMeta $meta = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        $environment = $this->environment;
        $queue = $environment->queue;
        $fetcher = $environment->fetcher((string) $build->state['render_token']);
        $this->root = $environment->root($build);
        $this->meta = SiteMeta::load($environment->directory->metaFile($this->root));
        $concurrency = max(1, $environment->settings->int('fetch_concurrency'));

        try {
            while (!$deadline->passed()) {
                $items = $queue->claim($build->id, $concurrency, self::LEASE_SECONDS);
                if ($items === []) {
                    break;
                }
                $remote = [];
                foreach ($items as $index => $item) {
                    $localFile = $environment->localAssets()->resolve($environment->resolver->path($item->url));
                    if ($localFile !== null) {
                        $this->process($build, $item, FetchResult::local($localFile));
                        continue;
                    }
                    $remote[$index] = $item->url;
                }
                foreach ($fetcher->fetchMany($remote) as $index => $result) {
                    $this->process($build, $items[$index], $result);
                }
            }
        } finally {
            $this->meta->save();
        }

        $queue->failExhausted($build->id);
        if ($queue->remaining($build->id) > 0) {
            return false;
        }

        $counts = $queue->counts($build->id);
        $environment->logger->info($build->id, sprintf(
            'Crawl finished: %d saved, %d redirects, %d skipped, %d failed.',
            $counts[Queue::FETCHED],
            $counts[Queue::REDIRECT],
            $counts[Queue::SKIPPED],
            $counts[Queue::FAILED]
        ));
        return true;
    }

    private function process(Build $build, QueueItem $item, FetchResult $result): void
    {
        $environment = $this->environment;
        $queue = $environment->queue;
        $path = $environment->resolver->path($item->url);

        if ($item->url === ($build->state['not_found_url'] ?? null)) {
            $this->saveNotFoundPage($build, $item, $result);
            return;
        }
        if ($result->isRedirect()) {
            $this->saveRedirect($build, $item, $path, $result);
            return;
        }
        if ($result->isRetryable()) {
            $queue->retryLater($item, $result->error !== '' ? $result->error : 'HTTP ' . $result->status);
            return;
        }
        if ($result->status === 404 || $result->status === 410) {
            $queue->complete($item, $item->foundOn === '' ? Queue::SKIPPED : Queue::FAILED, $result->status, '', '', 'Not found');
            return;
        }
        if ($result->status === 401 || $result->status === 403) {
            $queue->complete($item, Queue::SKIPPED, $result->status, '', '', 'Password protected or forbidden');
            return;
        }
        if ($result->status !== 200) {
            $queue->complete($item, Queue::FAILED, $result->status, '', '', 'HTTP ' . $result->status);
            return;
        }

        $kind = $result->localFile !== null
            ? ContentKind::fromExtension($path)
            : ContentKind::detect($result->contentType, $path);
        $mapped = $environment->pathMapper->map($path, $kind);
        if ($mapped === null) {
            $queue->complete($item, Queue::FAILED, $result->status, $result->contentType, '', 'Unsafe path');
            return;
        }

        try {
            if ($result->localFile !== null && !$kind->isText()) {
                $environment->directory->copyInto($this->root, $mapped->file, $result->localFile);
            } else {
                $body = $result->localFile !== null ? (string) file_get_contents($result->localFile) : $result->body;
                if ($kind === ContentKind::Html && trim($body) === '') {
                    $queue->complete($item, Queue::FAILED, $result->status, $result->contentType, '', 'Empty HTML response');
                    return;
                }
                if ($kind->isText()) {
                    $this->enqueueDiscovered($build, $item, $environment->extractor->extract($body, $kind));
                    $body = $this->finished($build, $environment->rewriter->rewrite($body, $kind), $kind, $mapped->file);
                }
                $environment->directory->write($this->root, $mapped->file, $body);
            }
        } catch (RuntimeException $error) {
            $queue->complete($item, Queue::FAILED, $result->status, $result->contentType, '', $error->getMessage());
            return;
        }

        if ($mapped->rewriteSource !== null) {
            $this->meta->addRewrite($mapped->rewriteSource, $mapped->file);
        }
        $this->meta->forgetRedirect($path);
        $queue->complete(
            $item,
            Queue::FETCHED,
            $result->status,
            $result->contentType !== '' ? $result->contentType : 'file/' . $kind->value,
            $mapped->file
        );
    }

    private function saveNotFoundPage(Build $build, QueueItem $item, FetchResult $result): void
    {
        $environment = $this->environment;
        if ($result->body !== '' && ContentKind::detect($result->contentType, '/') === ContentKind::Html) {
            $this->enqueueDiscovered($build, $item, $environment->extractor->extract($result->body, ContentKind::Html));
            $environment->directory->write(
                $this->root,
                self::NOT_FOUND_FILE,
                $this->finished($build, $environment->rewriter->rewrite($result->body, ContentKind::Html), ContentKind::Html, self::NOT_FOUND_FILE)
            );
        }
        $environment->queue->complete($item, Queue::SKIPPED, $result->status, $result->contentType, self::NOT_FOUND_FILE, 'Not-found template');
    }

    private function saveRedirect(Build $build, QueueItem $item, string $path, FetchResult $result): void
    {
        $environment = $this->environment;
        $target = $environment->resolver->resolve($result->location, $item->url);
        $destination = $target !== null
            ? $environment->resolver->path($target)
            : $environment->rewriter->rewriteLocation($result->location);

        $rule = RedirectRule::create($path, $destination, $result->status);
        if ($rule !== null) {
            $this->meta->addRedirect($rule);
        }
        if ($target !== null) {
            $this->enqueueDiscovered($build, $item, [$target]);
        }
        $environment->queue->complete($item, Queue::REDIRECT, $result->status, '', '', mb_substr($destination, 0, 250));
    }

    private function finished(Build $build, string $content, ContentKind $kind, string $file): string
    {
        $filtered = apply_filters('wp2vercel_output', $content, $kind->value, $file, $build);
        return is_string($filtered) ? $filtered : $content;
    }

    private function isFollowedPagination(Build $build, string $path): bool
    {
        return !empty($build->state['follow_pagination']) && (bool) preg_match('~/page/\d+/?$~', $path);
    }

    private function enqueueDiscovered(Build $build, QueueItem $source, array $references): void
    {
        $environment = $this->environment;
        $followPages = $build->isFull();
        $urls = [];
        foreach ($references as $reference) {
            $resolved = $environment->resolver->resolve($reference, $source->url);
            if ($resolved === null) {
                continue;
            }
            $path = $environment->resolver->path($resolved);
            if (!$followPages && !UrlResolver::hasFileExtension($path) && !$this->isFollowedPagination($build, $path)) {
                continue;
            }
            if ($environment->filter()->allows($path)) {
                $urls[$resolved] = true;
            }
        }
        if ($urls !== []) {
            $environment->queue->enqueue($build->id, array_keys($urls), $source->url);
        }
    }
}
