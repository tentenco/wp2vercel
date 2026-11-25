<?php

declare(strict_types=1);

namespace WP2Vercel\Redirects;

final class VercelConfig
{
    public const FILE_NAME = 'vercel.json';
    public const MAX_ROUTES = 2048;

    private const SECURITY_HEADERS = [
        ['key' => 'X-Content-Type-Options', 'value' => 'nosniff'],
        ['key' => 'Referrer-Policy', 'value' => 'strict-origin-when-cross-origin'],
        ['key' => 'X-Frame-Options', 'value' => 'SAMEORIGIN'],
    ];

    public function __construct(
        private readonly bool $trailingSlash,
        private readonly array $redirects,
        private readonly array $rewrites,
    ) {
    }

    public function routeCount(): int
    {
        return count($this->redirects) + count($this->rewrites);
    }

    public function exceedsRouteLimit(): bool
    {
        return $this->routeCount() > self::MAX_ROUTES;
    }

    public function toArray(): array
    {
        $config = [
            '$schema' => 'https://openapi.vercel.sh/vercel.json',
            'framework' => null,
            'buildCommand' => null,
            'installCommand' => null,
            'outputDirectory' => '.',
            'cleanUrls' => false,
            'trailingSlash' => $this->trailingSlash,
            'headers' => [
                ['source' => '/(.*)', 'headers' => self::SECURITY_HEADERS],
            ],
        ];

        $redirects = [];
        foreach ($this->redirects as $rule) {
            if ($rule instanceof RedirectRule) {
                $redirects[$rule->source] = $rule->toVercel();
            }
        }
        if ($redirects !== []) {
            ksort($redirects);
            $config['redirects'] = array_values($redirects);
        }

        $rewrites = [];
        foreach ($this->rewrites as $source => $destination) {
            foreach ($this->sourceVariants((string) $source) as $variant) {
                $rewrites[$variant] = ['source' => $variant, 'destination' => '/' . ltrim((string) $destination, '/')];
            }
        }
        if ($rewrites !== []) {
            ksort($rewrites);
            $config['rewrites'] = array_values($rewrites);
        }

        return $config;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function sourceVariants(string $source): array
    {
        $bare = '/' . trim($source, '/');
        if ($bare === '/') {
            return ['/'];
        }
        return [$this->trailingSlash ? $bare . '/' : $bare];
    }
}
