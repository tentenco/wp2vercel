<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use WP2Vercel\Crawl\Fetcher;
use WP2Vercel\Crawl\LocalAssets;
use WP2Vercel\Crawl\Queue;
use WP2Vercel\Crawl\RenderContext;
use WP2Vercel\Crawl\UrlFilter;
use WP2Vercel\Deploy\Deployer;
use WP2Vercel\Deploy\GitHubDeployer;
use WP2Vercel\Deploy\VercelDeployer;
use WP2Vercel\Extract\LinkExtractor;
use WP2Vercel\Http\WordPressHttpClient;
use WP2Vercel\Output\Manifest;
use WP2Vercel\Output\SiteDirectory;
use WP2Vercel\Rewrite\UrlRewriter;
use WP2Vercel\Secrets;
use WP2Vercel\Settings;
use WP2Vercel\Url\Origin;
use WP2Vercel\Url\PathMapper;
use WP2Vercel\Url\UrlResolver;

final class Environment
{
    public readonly Origin $origin;
    public readonly Origin $fetchOrigin;
    public readonly ?Origin $production;
    public readonly UrlResolver $resolver;
    public readonly PathMapper $pathMapper;
    public readonly LinkExtractor $extractor;
    public readonly UrlRewriter $rewriter;
    public readonly SiteDirectory $directory;
    public readonly Queue $queue;

    private ?UrlFilter $filter = null;
    private ?LocalAssets $localAssets = null;

    public function __construct(
        public readonly Settings $settings,
        public readonly Secrets $secrets,
        public readonly BuildLogger $logger,
    ) {
        $this->origin = new Origin(home_url('/'));
        $fetchUrl = $settings->string('fetch_url');
        $this->fetchOrigin = $fetchUrl === '' ? $this->origin : new Origin($fetchUrl);
        $productionUrl = $settings->string('production_url');
        $this->production = $productionUrl === '' ? null : new Origin($productionUrl);
        $this->resolver = new UrlResolver($this->origin);
        $this->pathMapper = new PathMapper();
        $this->extractor = new LinkExtractor($this->origin);
        $this->rewriter = new UrlRewriter($this->origin, $productionUrl);
        $this->directory = SiteDirectory::fromUploads();
        $this->queue = new Queue();
    }

    public function root(Build $build): string
    {
        return $this->directory->root($build->id, $build->usesStaging());
    }

    public function manifest(Build $build): Manifest
    {
        $root = $this->root($build);
        return new Manifest($this->directory->manifestFile($root), $this->directory->site($root));
    }

    public function homePath(): string
    {
        return rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    }

    public function filter(): UrlFilter
    {
        if ($this->filter === null) {
            global $wp_rewrite;
            $authorBase = $this->settings->bool('include_author_archives')
                ? null
                : trim($this->homePath() . '/' . ($wp_rewrite->author_base ?? 'author'), '/');
            $this->filter = new UrlFilter(
                $this->settings->lines('exclude_patterns'),
                $this->settings->bool('include_feeds'),
                $authorBase
            );
        }
        return $this->filter;
    }

    public function localAssets(): LocalAssets
    {
        if ($this->localAssets === null) {
            $sitePath = rtrim((string) wp_parse_url(site_url('/'), PHP_URL_PATH), '/');
            $contentPath = rtrim((string) wp_parse_url(content_url(), PHP_URL_PATH), '/');
            $uploads = wp_upload_dir(null, false);
            $uploadsPath = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/');
            $this->localAssets = new LocalAssets([
                $sitePath . '/wp-includes' => ABSPATH . WPINC,
                $contentPath => WP_CONTENT_DIR,
                $uploadsPath => $uploads['basedir'],
            ], $this->directory->base());
        }
        return $this->localAssets;
    }

    public function fetcher(string $renderToken): Fetcher
    {
        return new Fetcher(
            $this->origin,
            $this->fetchOrigin,
            $renderToken,
            $this->settings->int('fetch_timeout'),
            $this->settings->bool('fetch_ssl_verify'),
            $this->settings->string('fetch_basic_auth_user'),
            $this->secrets->get('fetch_basic_auth_password')
        );
    }

    public function privateOrigins(): array
    {
        $private = [];
        foreach ([$this->origin, $this->fetchOrigin] as $candidate) {
            if ($this->production !== null && $candidate->sameAuthorityAs($this->production)) {
                continue;
            }
            $private[$candidate->authority] = $candidate;
        }
        return array_values($private);
    }

    public function deployer(string $target): ?Deployer
    {
        $http = new WordPressHttpClient();
        return match ($target) {
            Settings::TARGET_GITHUB => new GitHubDeployer(
                $http,
                $this->secrets->get('github_token'),
                $this->settings->string('github_repository'),
                $this->settings->string('github_branch'),
                $this->settings->string('github_base_branch'),
                $this->settings->string('github_directory')
            ),
            Settings::TARGET_VERCEL => new VercelDeployer(
                $http,
                $this->secrets->get('vercel_token'),
                $this->settings->string('vercel_project'),
                $this->settings->string('vercel_team_id'),
                $this->settings->bool('vercel_production')
            ),
            default => null,
        };
    }

    public function usesTrailingSlash(): bool
    {
        return str_ends_with((string) get_option('permalink_structure'), '/');
    }

    public function revokeRenderToken(): void
    {
        RenderContext::revokeToken();
    }
}
