<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

final class UrlFilter
{
    private const ALWAYS_EXCLUDED = [
        '~^/wp-admin(?:/|$)~i',
        '~^/wp-json(?:/|$)~i',
        '~/xmlrpc\.php~i',
        '~\.(?:php\d?|phtml|phar)(?:/|$)~i',
        '~(?:^|/)\.(?!well-known/)[^/]~',
        '~/trackback/?$~i',
        '~/embed/?$~i',
        '~/wp-content/uploads/(?:sites/\d+/)?wp2vercel-~i',
        '~\.(?:log|sql|sqlite|bak|env|ini)$~i',
    ];

    private const FEED = '~(?:^|/)feed(?:/(?:rss2?|atom|rdf))?/?$~i';

    private const PAGE_EXTENSIONS = ['', 'html', 'htm', 'xml', 'xsl', 'txt', 'json'];

    private const ASSET_FOLDER = '~/wp-(?:content|includes)/~i';

    private array $patterns;
    private array $literals = [];
    private string $homePath;
    private array $innerSites = [];
    private array $assetPaths = [];

    public function __construct(array $userPatterns, bool $includeFeeds, ?string $authorBase, string $homePath = '', array $otherSitePaths = [], array $assetPaths = [])
    {
        $this->patterns = self::ALWAYS_EXCLUDED;
        $this->homePath = self::normalized($homePath);
        if ($this->homePath !== '') {
            $this->patterns[] = '~^' . preg_quote($this->homePath, '~') . '/(?:wp-admin|wp-json)(?:/|$)~i';
        }
        foreach ($otherSitePaths as $otherPath) {
            $otherPath = self::normalized((string) $otherPath);
            if ($otherPath !== '' && str_starts_with($otherPath, $this->homePath . '/')) {
                $this->innerSites[$otherPath] = true;
            }
        }
        foreach ($assetPaths as $assetPath) {
            $assetPath = self::normalized((string) $assetPath);
            if ($assetPath !== '') {
                $this->assetPaths[] = $assetPath . '/';
            }
        }
        if (!$includeFeeds) {
            $this->patterns[] = self::FEED;
        }
        if ($authorBase !== null && $authorBase !== '') {
            $this->patterns[] = '~^' . preg_quote(self::normalized($authorBase), '~') . '/~i';
        }
        foreach ($userPatterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if (strlen($pattern) > 2 && $pattern[0] === '~' && str_ends_with($pattern, '~') && @preg_match($pattern, '') !== false) {
                $this->patterns[] = $pattern;
                continue;
            }
            $this->literals[] = $pattern;
        }
    }

    public function allows(string $path): bool
    {
        if (!$this->belongsToSite($path)) {
            return false;
        }
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return false;
            }
        }
        foreach ($this->literals as $literal) {
            if (stripos($path, $literal) !== false) {
                return false;
            }
        }
        return true;
    }

    private function belongsToSite(string $path): bool
    {
        if (!$this->isPage($path)) {
            return true;
        }
        $lower = strtolower($path);
        if ($this->homePath !== '' && $lower !== $this->homePath && !str_starts_with($lower, $this->homePath . '/')) {
            return false;
        }
        return !$this->insideAnotherSite($lower);
    }

    private function isPage(string $path): bool
    {
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::PAGE_EXTENSIONS, true) || preg_match(self::ASSET_FOLDER, $path)) {
            return false;
        }
        $lower = strtolower($path);
        foreach ($this->assetPaths as $assetPath) {
            if (str_starts_with($lower, $assetPath)) {
                return false;
            }
        }
        return true;
    }

    private function insideAnotherSite(string $lowerPath): bool
    {
        if ($this->innerSites === []) {
            return false;
        }
        $prefix = '';
        foreach (explode('/', trim($lowerPath, '/')) as $segment) {
            $prefix .= '/' . $segment;
            if (isset($this->innerSites[$prefix])) {
                return true;
            }
        }
        return false;
    }

    private static function normalized(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        $segments = array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path));
        return '/' . strtolower(implode('/', $segments));
    }
}
