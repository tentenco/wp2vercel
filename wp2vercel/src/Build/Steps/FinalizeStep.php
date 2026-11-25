<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Output\SiteMeta;
use WP2Vercel\Redirects\RedirectCollector;
use WP2Vercel\Redirects\RedirectRule;
use WP2Vercel\Redirects\VercelConfig;
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
            if (!$directory->exists($root, 'index.html')) {
                throw new BuildFailed(__('The home page could not be saved, so nothing was published. Check that WordPress can reach itself at the fetch URL.', 'wp2vercel'));
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

        $config = new VercelConfig($environment->usesTrailingSlash(), array_values($redirects), $meta->rewrites());
        if ($config->exceedsRouteLimit()) {
            $environment->logger->warning($build->id, sprintf(
                'vercel.json holds %d routes; Vercel accepts %d. Trim redirects or the deployment will be rejected.',
                $config->routeCount(),
                VercelConfig::MAX_ROUTES
            ));
        }
        $directory->write($root, VercelConfig::FILE_NAME, $config->toJson());
        $environment->logger->info($build->id, sprintf('Wrote vercel.json with %d redirects and %d rewrites.', count($redirects), count($meta->rewrites())));

        $build->state['redirects'] = count($redirects);
        return true;
    }

    private function pageExists(string $root, string $sourcePath): bool
    {
        $mapped = $this->environment->pathMapper->map($sourcePath, ContentKind::Html);
        return $mapped !== null && $this->environment->directory->exists($root, $mapped->file);
    }
}
