<?php

declare(strict_types=1);

namespace WP2Vercel\Scan;

use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\Origin;
use WP2Vercel\Url\OriginPattern;
use WP2Vercel\Utf8;

final class LeakScanner
{
    public const TYPE_ORIGIN_HOST = 'origin_host';
    public const TYPE_SENSITIVE_FILE = 'sensitive_file';

    private const MAX_SCANNED_BYTES = 8 * 1024 * 1024;
    private const SENSITIVE_FILE = '~(?:\.(?:php\d?|phtml|phar|sql|sqlite|bak|log|env|ini|htaccess|htpasswd)|(?:^|/)wp-config[^/]*|(?:^|/)\.git(?:/|$))~i';

    private array $patterns = [];

    private array $rules = [];

    public function __construct(array $privateOrigins, private readonly int $maxFindings = 200, array $extraRules = [])
    {
        foreach ($extraRules as $rule) {
            $type = is_array($rule) ? (string) ($rule['type'] ?? '') : '';
            $pattern = is_array($rule) ? (string) ($rule['pattern'] ?? '') : '';
            if ($type !== '' && $pattern !== '' && @preg_match($pattern, '') !== false) {
                $this->rules[] = ['type' => $type, 'pattern' => $pattern];
            }
        }
        foreach ($privateOrigins as $origin) {
            if (!$origin instanceof Origin) {
                continue;
            }
            $this->patterns[$origin->authority] = self::isDistinctiveHost($origin->authority)
                ? array_values(array_filter([
                    OriginPattern::bareAuthority($origin),
                    OriginPattern::bareEncodedAuthority($origin),
                ]))
                : [
                    OriginPattern::plain($origin),
                    OriginPattern::jsonEscaped($origin),
                    OriginPattern::urlEncoded($origin),
                ];
        }
    }

    public function scanFile(string $relativePath, string $absolutePath): array
    {
        $findings = [];
        if (preg_match(self::SENSITIVE_FILE, $relativePath)) {
            $findings[] = new Finding(self::TYPE_SENSITIVE_FILE, $relativePath, 0, basename($relativePath));
        }
        if (($this->patterns === [] && $this->rules === []) || !ContentKind::fromExtension($relativePath)->isText()) {
            return $findings;
        }
        $size = @filesize($absolutePath);
        if ($size === false || $size === 0 || $size > self::MAX_SCANNED_BYTES) {
            return $findings;
        }
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            return $findings;
        }
        return array_merge($findings, $this->scanText($relativePath, $content));
    }

    public function scanText(string $relativePath, string $content): array
    {
        $findings = [];
        foreach ($this->patterns as $authority => $patterns) {
            if (stripos($content, explode(':', $authority)[0]) === false) {
                continue;
            }
            foreach ($patterns as $pattern) {
                $findings = $this->collect($findings, self::TYPE_ORIGIN_HOST, $pattern, $relativePath, $content);
            }
        }
        foreach ($this->rules as $rule) {
            $findings = $this->collect($findings, $rule['type'], $rule['pattern'], $relativePath, $content);
        }
        return $findings;
    }

    private function collect(array $findings, string $type, string $pattern, string $relativePath, string $content): array
    {
        if (count($findings) >= $this->maxFindings || !preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $findings;
        }
        foreach ($matches[0] as [$text, $offset]) {
            $findings[] = new Finding(
                $type,
                $relativePath,
                substr_count($content, "\n", 0, $offset) + 1,
                self::excerpt($content, $offset, strlen($text))
            );
            if (count($findings) >= $this->maxFindings) {
                break;
            }
        }
        return $findings;
    }

    private static function isDistinctiveHost(string $authority): bool
    {
        return str_contains($authority, '.') || str_contains($authority, ':');
    }

    private static function excerpt(string $content, int $offset, int $length): string
    {
        $start = max(0, $offset - 40);
        $excerpt = substr($content, $start, $length + 80);
        return trim(preg_replace('~\s+~', ' ', Utf8::scrub($excerpt)) ?? '');
    }
}
