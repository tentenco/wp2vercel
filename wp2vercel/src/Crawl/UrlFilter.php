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
        '~/wp-content/uploads/wp2vercel-~i',
        '~\.(?:log|sql|sqlite|bak|env|ini)$~i',
    ];

    private const FEED = '~(?:^|/)feed(?:/(?:rss2?|atom|rdf))?/?$~i';

    private array $patterns;
    private array $literals = [];

    public function __construct(array $userPatterns, bool $includeFeeds, ?string $authorBase)
    {
        $this->patterns = self::ALWAYS_EXCLUDED;
        if (!$includeFeeds) {
            $this->patterns[] = self::FEED;
        }
        if ($authorBase !== null && $authorBase !== '') {
            $this->patterns[] = '~^/' . preg_quote(trim($authorBase, '/'), '~') . '/~i';
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
}
