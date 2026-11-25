<?php

declare(strict_types=1);

namespace WP2Vercel\Rewrite;

use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\Origin;
use WP2Vercel\Url\OriginPattern;

final class UrlRewriter
{
    private const ABSOLUTE_HEAD_TAG = '~<(?:link|meta)\b[^>]*>~i';
    private const ABSOLUTE_HEAD_MARKERS = '~\b(?:rel=["\']?(?:canonical|alternate|next|prev)\b|(?:property|name)=["\']?(?:og:|twitter:|article:))~i';
    private const LINKED_DATA_BLOCK = '~<script\b[^>]*application/ld\+json[^>]*>.*?</script>~is';

    private readonly string $production;

    public function __construct(private readonly Origin $origin, ?string $productionUrl)
    {
        $this->production = rtrim((string) $productionUrl, '/');
    }

    public function hasProductionUrl(): bool
    {
        return $this->production !== '';
    }

    public function rewrite(string $body, ContentKind $kind): string
    {
        if (!$kind->isText() || stripos($body, $this->hostWithoutPort()) === false) {
            return $body;
        }

        if ($kind === ContentKind::Xml || $kind === ContentKind::Text) {
            return $this->hasProductionUrl() ? $this->toProduction($body) : $this->toRootRelative($body);
        }

        if ($kind === ContentKind::Html && $this->hasProductionUrl()) {
            $body = preg_replace_callback(
                self::ABSOLUTE_HEAD_TAG,
                fn(array $match): string => preg_match(self::ABSOLUTE_HEAD_MARKERS, $match[0]) ? $this->toProduction($match[0]) : $match[0],
                $body
            ) ?? $body;
            $body = preg_replace_callback(
                self::LINKED_DATA_BLOCK,
                fn(array $match): string => $this->toProduction($match[0]),
                $body
            ) ?? $body;
        }

        return $this->toRootRelative($body);
    }

    public function rewriteLocation(string $location): string
    {
        return $this->toRootRelative($location);
    }

    private function toProduction(string $text): string
    {
        $text = preg_replace(OriginPattern::jsonEscaped($this->origin), str_replace(['\\', '/'], ['\\\\', '\\\\/'], $this->production), $text) ?? $text;
        $text = preg_replace(OriginPattern::urlEncoded($this->origin), rawurlencode($this->production), $text) ?? $text;
        return preg_replace(OriginPattern::plain($this->origin), self::quoteReplacement($this->production), $text) ?? $text;
    }

    private function toRootRelative(string $text): string
    {
        $text = self::replaceWithRoot(OriginPattern::jsonEscaped($this->origin), $text, '\\/');
        if ($this->hasProductionUrl()) {
            $text = preg_replace(OriginPattern::urlEncoded($this->origin), rawurlencode($this->production), $text) ?? $text;
        } else {
            $text = self::replaceWithRoot(OriginPattern::urlEncoded($this->origin), $text, '%2F');
        }
        return self::replaceWithRoot(OriginPattern::plain($this->origin), $text, '/');
    }

    private static function replaceWithRoot(string $pattern, string $text, string $slash): string
    {
        $withLookahead = substr($pattern, 0, -2) . '(?=(' . preg_quote($slash, '~') . ')?)~i';
        return preg_replace_callback(
            $withLookahead,
            static fn(array $match): string => isset($match[1]) && $match[1] !== '' ? '' : $slash,
            $text
        ) ?? $text;
    }

    private static function quoteReplacement(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }

    private function hostWithoutPort(): string
    {
        return explode(':', $this->origin->authority)[0];
    }
}
