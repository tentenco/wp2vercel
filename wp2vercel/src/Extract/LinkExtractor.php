<?php

declare(strict_types=1);

namespace WP2Vercel\Extract;

use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\Origin;
use WP2Vercel\Url\OriginPattern;
use WP_HTML_Tag_Processor;

final class LinkExtractor
{
    private const URL_ATTRIBUTES = [
        'href', 'src', 'poster', 'data', 'action', 'formaction', 'background', 'manifest',
        'data-src', 'data-lazy-src', 'data-original', 'data-bg', 'data-background',
        'data-background-image', 'data-thumb', 'data-large_image', 'data-full-url', 'data-href',
    ];

    private const SRCSET_ATTRIBUTES = ['srcset', 'imagesrcset', 'data-srcset', 'data-lazy-srcset'];

    private const META_URL_KEYS = [
        'og:image', 'og:image:url', 'og:image:secure_url', 'og:video', 'og:audio',
        'twitter:image', 'twitter:image:src', 'msapplication-tileimage', 'msapplication-config',
    ];

    private const URL_TERMINATORS = '"\'\\s<>()\\\\`';

    public function __construct(private readonly Origin $origin)
    {
    }

    public function extract(string $body, ContentKind $kind): array
    {
        $references = match ($kind) {
            ContentKind::Html => array_merge(
                $this->fromHtmlTags($body),
                $this->fromStyleBlocks($body),
                $this->fromOriginAbsoluteUrls($body)
            ),
            ContentKind::Css => $this->fromCss($body),
            ContentKind::Xml => array_merge($this->fromXml($body), $this->fromOriginAbsoluteUrls($body)),
            ContentKind::Script, ContentKind::Json => $this->fromOriginAbsoluteUrls($body),
            ContentKind::Text => $this->fromRobotsTxt($body),
            ContentKind::Binary => [],
        };

        return array_values(array_unique(array_filter($references, [self::class, 'looksLikeReference'])));
    }

    private function fromHtmlTags(string $html): array
    {
        $found = [];
        $processor = new WP_HTML_Tag_Processor($html);
        while ($processor->next_tag()) {
            foreach (self::URL_ATTRIBUTES as $attribute) {
                $value = $processor->get_attribute($attribute);
                if (is_string($value)) {
                    $found[] = $value;
                }
            }
            foreach (self::SRCSET_ATTRIBUTES as $attribute) {
                $value = $processor->get_attribute($attribute);
                if (is_string($value)) {
                    array_push($found, ...self::splitSrcset($value));
                }
            }
            $style = $processor->get_attribute('style');
            if (is_string($style)) {
                array_push($found, ...$this->fromCss($style));
            }
            if ($processor->get_tag() === 'META') {
                $key = $processor->get_attribute('property') ?? $processor->get_attribute('name');
                $content = $processor->get_attribute('content');
                if (is_string($key) && is_string($content) && in_array(strtolower($key), self::META_URL_KEYS, true)) {
                    $found[] = $content;
                }
            }
        }
        return $found;
    }

    private static function looksLikeReference(mixed $value): bool
    {
        return is_string($value) && $value !== '' && !preg_match('~[{}<>`]|\$\(~', $value);
    }

    private function fromStyleBlocks(string $html): array
    {
        if (!preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $matches)) {
            return [];
        }
        return $this->fromCss(implode("\n", $matches[1]));
    }

    private function fromCss(string $css): array
    {
        $found = [];
        if (preg_match_all('~url\(\s*(["\']?)(.*?)\1\s*\)~is', $css, $matches)) {
            array_push($found, ...$matches[2]);
        }
        if (preg_match_all('~@import\s+(["\'])(.*?)\1~is', $css, $matches)) {
            array_push($found, ...$matches[2]);
        }
        return array_map(
            static fn(string $reference): string => html_entity_decode(trim($reference), ENT_QUOTES | ENT_HTML5),
            array_filter($found, static fn(string $reference): bool => !str_starts_with(ltrim($reference), 'data:'))
        );
    }

    private function fromXml(string $xml): array
    {
        $found = [];
        if (preg_match_all('~<(?:loc|image:loc|video:content_loc|video:thumbnail_loc|link|guid|url|enclosure:url)\b[^>]*>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)~i', $xml, $matches)) {
            array_push($found, ...$matches[1]);
        }
        if (preg_match_all('~<\?xml-stylesheet\b[^>]*\bhref=(["\'])(.*?)\1~i', $xml, $matches)) {
            array_push($found, ...$matches[2]);
        }
        if (preg_match_all('~\b(?:href|src|url|xlink:href)=(["\'])(.*?)\1~i', $xml, $matches)) {
            array_push($found, ...$matches[2]);
        }
        return array_map(static fn(string $reference): string => html_entity_decode($reference, ENT_QUOTES | ENT_XML1), $found);
    }

    private function fromRobotsTxt(string $text): array
    {
        if (preg_match_all('~^\s*sitemap:\s*(\S+)~im', $text, $matches)) {
            return $matches[1];
        }
        return [];
    }

    private function fromOriginAbsoluteUrls(string $body): array
    {
        $found = [];
        $plain = substr(OriginPattern::plain($this->origin), 0, -2) . '[^' . self::URL_TERMINATORS . ']*~i';
        if (preg_match_all($plain, $body, $matches)) {
            foreach ($matches[0] as $match) {
                $found[] = html_entity_decode(rtrim($match, '.,;'), ENT_QUOTES | ENT_HTML5);
            }
        }
        $escaped = substr(OriginPattern::jsonEscaped($this->origin), 0, -2) . '(?:\\\\/|[^' . self::URL_TERMINATORS . '])*~i';
        if (preg_match_all($escaped, $body, $matches)) {
            foreach ($matches[0] as $match) {
                $found[] = str_replace('\\/', '/', rtrim($match, '.,;'));
            }
        }
        return $found;
    }

    private static function splitSrcset(string $srcset): array
    {
        $urls = [];
        foreach (preg_split('~,(?=\s)|,(?=https?:)|,(?=/)~', $srcset) ?: [] as $candidate) {
            $url = preg_split('~\s+~', trim($candidate))[0] ?? '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
