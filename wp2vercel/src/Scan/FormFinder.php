<?php

declare(strict_types=1);

namespace WP2Vercel\Scan;

final class FormFinder
{
    private readonly string $publicHost;

    public function __construct(string $publicAddress)
    {
        $this->publicHost = strtolower((string) parse_url($publicAddress, PHP_URL_HOST));
    }

    public static function pageAddress(string $file): string
    {
        return '/' . preg_replace('~(^|/)index\.html$~', '$1', $file);
    }

    public function postsToTheSite(string $html): int
    {
        if (!preg_match_all('~<form\b[^>]*>~i', $html, $tags)) {
            return 0;
        }
        $count = 0;
        foreach ($tags[0] as $tag) {
            if (preg_match('~\smethod\s*=\s*["\']?\s*post\b~i', $tag) && $this->isOwn(self::attribute($tag, 'action'))) {
                $count++;
            }
        }
        return $count;
    }

    private static function attribute(string $tag, string $name): string
    {
        if (!preg_match('~\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $found)) {
            return '';
        }
        return trim(html_entity_decode($found[1] . ($found[2] ?? '') . ($found[3] ?? ''), ENT_QUOTES));
    }

    private function isOwn(string $action): bool
    {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $action)) {
            return $this->publicHost !== '' && strtolower((string) parse_url($action, PHP_URL_HOST)) === $this->publicHost;
        }
        return !preg_match('~^[a-z][a-z0-9+.-]*:~i', $action);
    }
}
