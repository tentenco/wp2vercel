<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Build\Readiness;
use WP2Vercel\Output\SiteMeta;
use WP2Vercel\Redirects\NetlifyConfig;
use WP2Vercel\Redirects\RedirectCollector;
use WP2Vercel\Redirects\RedirectRule;
use WP2Vercel\Redirects\VercelConfig;
use WP2Vercel\Settings;
use WP2Vercel\Url\ContentKind;

final class FinalizeStep implements Step
{
    private const FALLBACK_NOT_FOUND = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Page not found</title></head><body><h1>Page not found</h1><p><a href="/">Back to the home page</a></p></body></html>';

    public function __construct(private readonly Environment $environment)
    {
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        $environment = $this->environment;
        $directory = $environment->directory;
        $root = $environment->root($build);

        if ($build->isFull()) {
            if (!$directory->exists($root, ltrim($environment->homeFolder() . '/index.html', '/'))) {
                throw new BuildFailed(Readiness::queued($environment->homeFetchAddress(), $environment->queue->outcome($build->id, $environment->resolver->resolve(home_url('/')) ?? home_url('/'))) . ' ' . __('Nothing was published.', 'wp2vercel'));
            }
            if ($environment->queue->countHtmlPages($build->id) === 0) {
                throw new BuildFailed(__('No HTML pages were saved, so nothing was published.', 'wp2vercel'));
            }
        }

        if (!$directory->exists($root, CrawlStep::NOT_FOUND_FILE)) {
            $directory->write($root, CrawlStep::NOT_FOUND_FILE, self::FALLBACK_NOT_FOUND);
            $environment->logger->warning($build->id, 'The theme returned no 404 template; a plain fallback page was written.');
        }

        do_action('wp2vercel_finalize', $build, $environment, $root);

        $meta = SiteMeta::load($directory->metaFile($root));
        $collector = new RedirectCollector($environment->settings->lines('extra_redirects'));
        $redirects = [];
        foreach (array_merge($meta->redirects(), $collector->collect()) as $rule) {
            $rule = RedirectRule::create($rule->source, $environment->rewriter->rewriteLocation($rule->destination), $rule->status);
            if ($rule === null || $this->pageExists($root, $rule->source)) {
                continue;
            }
            $redirects[$rule->source] = $rule;
        }

        $homeElsewhere = $environment->homePath() !== '' && !$directory->exists($root, 'index.html') ? $environment->homePath() . '/' : '';
        $config = new VercelConfig($environment->usesTrailingSlash(), array_values($redirects), $meta->rewrites(), $homeElsewhere);
        if ($config->exceedsRouteLimit()) {
            $environment->logger->warning($build->id, sprintf(
                'vercel.json holds %d routes; Vercel accepts %d. Trim redirects or the deployment will be rejected.',
                $config->routeCount(),
                VercelConfig::MAX_ROUTES
            ));
        }
        $filtered = apply_filters('wp2vercel_vercel_config', $config->toArray(), $build);
        $final = is_array($filtered) ? $filtered : $config->toArray();
        $target = (string) ($build->state['target'] ?? Settings::TARGET_NONE);
        $counts = [count($redirects) + ($homeElsewhere === '' ? 0 : 1), count($meta->rewrites())];
        $this->place($root, VercelConfig::FILE_NAME, $target === Settings::TARGET_NETLIFY ? null : VercelConfig::encode($final));
        if ($target !== Settings::TARGET_NETLIFY) {
            $environment->logger->info($build->id, vsprintf('Wrote vercel.json with %d redirects and %d rewrites.', $counts));
        }
        $netlify = $target === Settings::TARGET_VERCEL ? null : new NetlifyConfig($final);
        $this->place($root, NetlifyConfig::REDIRECTS_FILE, $netlify?->redirects());
        $this->place($root, NetlifyConfig::HEADERS_FILE, $netlify?->headers());
        if ($netlify !== null) {
            $environment->logger->info($build->id, vsprintf('Wrote _redirects and _headers for Netlify with %d redirects and %d rewrites.', $counts));
            if ($netlify->skipped() !== []) {
                $environment->logger->warning($build->id, sprintf(
                    'Netlify has no equivalent for %d rules of vercel.json, so _redirects leaves them out: %s',
                    count($netlify->skipped()),
                    implode(', ', array_slice($netlify->skipped(), 0, 5))
                ));
            }
        }

        $build->state['redirects'] = count($redirects);
        return true;
    }

    private function place(string $root, string $file, ?string $contents): void
    {
        $directory = $this->environment->directory;
        if ($contents !== null && $contents !== '') {
            $directory->write($root, $file, $contents);
        } elseif ($directory->exists($root, $file)) {
            $directory->remove($directory->site($root) . '/' . $file);
        }
    }

    private function pageExists(string $root, string $sourcePath): bool
    {
        $mapped = $this->environment->pathMapper->map($sourcePath, ContentKind::Html);
        return $mapped !== null && $this->environment->directory->exists($root, $mapped->file);
    }
}
