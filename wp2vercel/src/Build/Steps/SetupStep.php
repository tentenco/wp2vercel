<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Crawl\RenderContext;
use WP2Vercel\Settings;
use WP2Vercel\Url\ContentKind;

final class SetupStep implements Step
{
    public function __construct(private readonly Environment $environment)
    {
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        $environment = $this->environment;

        if ((string) get_option('permalink_structure') === '') {
            throw new BuildFailed(__('Plain permalinks cannot be exported. Choose a permalink structure under Settings → Permalinks first.', 'wp2vercel'));
        }

        $target = ($build->state['deploy'] ?? true) ? $environment->settings->string('deploy_target') : Settings::TARGET_NONE;
        $deployer = $environment->deployer($target);
        if ($deployer !== null && ($problem = $deployer->configurationError()) !== null) {
            throw new BuildFailed($problem);
        }
        $build->state['target'] = $target;

        if ($build->usesStaging()) {
            $root = $environment->directory->prepareStaging($build->id);
            if ($build->isRestore()) {
                $this->restore($build, $root);
            }
        } elseif (!$environment->directory->hasCurrent()) {
            throw new BuildFailed(__('Run a full publish once before publishing single pages.', 'wp2vercel'));
        } else {
            $this->removeUnpublished($build);
        }

        $build->state['render_token'] = RenderContext::issueToken();
        $notFound = $environment->origin->base() . $environment->homePath() . '/wp2vercel-not-found-' . bin2hex(random_bytes(6)) . '/';
        $build->state['not_found_url'] = $environment->resolver->resolve($notFound) ?? $notFound;

        $environment->logger->info($build->id, sprintf(
            'Started a publish of %s of %s (fetching from %s, target: %s).',
            match ($build->type) {
                Build::TYPE_SINGLE => 'the changed pages',
                Build::TYPE_RESTORE => 'a saved version',
                default => 'the whole site',
            },
            $environment->origin->base(),
            $environment->fetchOrigin->base(),
            $target
        ));
        if ($environment->production === null) {
            $environment->logger->warning($build->id, 'No public site address is set. Canonical tags, social tags and sitemaps will use root-relative URLs.');
        }

        return true;
    }

    private function restore(Build $build, string $root): void
    {
        $directory = $this->environment->directory;
        $source = (string) ($build->state['restore_from'] ?? '');
        if ($source === '' || !str_starts_with($source, $directory->base() . '/') || !is_dir($directory->site($source))) {
            throw new BuildFailed(__('The saved version to restore no longer exists.', 'wp2vercel'));
        }
        $directory->copyTree($directory->site($source), $directory->site($root));
        if (is_file($directory->metaFile($source))) {
            copy($directory->metaFile($source), $directory->metaFile($root));
        }
    }

    private function removeUnpublished(Build $build): void
    {
        $environment = $this->environment;
        $root = $environment->root($build);
        foreach ((array) ($build->state['remove_urls'] ?? []) as $url) {
            $resolved = $environment->resolver->resolve((string) $url);
            if ($resolved === null) {
                continue;
            }
            $mapped = $environment->pathMapper->map($environment->resolver->path($resolved), ContentKind::Html);
            if ($mapped !== null && $environment->directory->exists($root, $mapped->file)) {
                $file = $environment->directory->site($root) . '/' . $mapped->file;
                $environment->directory->remove($file);
                if (count(scandir(dirname($file)) ?: []) === 2) {
                    rmdir(dirname($file));
                }
                $environment->logger->info($build->id, 'Removed ' . $mapped->file);
            }
        }
    }
}
