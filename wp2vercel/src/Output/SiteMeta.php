<?php

declare(strict_types=1);

namespace WP2Vercel\Output;

use WP2Vercel\Redirects\RedirectRule;

final class SiteMeta
{
    private array $redirects = [];
    private array $rewrites = [];
    private bool $dirty = false;

    private function __construct(private readonly string $file)
    {
    }

    public static function load(string $file): self
    {
        $meta = new self($file);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($data)) {
            $meta->redirects = is_array($data['redirects'] ?? null) ? $data['redirects'] : [];
            $meta->rewrites = is_array($data['rewrites'] ?? null) ? $data['rewrites'] : [];
        }
        return $meta;
    }

    public function addRedirect(RedirectRule $rule): void
    {
        $this->redirects[$rule->source] = $rule->toArray();
        $this->dirty = true;
    }

    public function forgetRedirect(string $source): void
    {
        if (isset($this->redirects[$source])) {
            unset($this->redirects[$source]);
            $this->dirty = true;
        }
    }

    public function addRewrite(string $source, string $file): void
    {
        if (($this->rewrites[$source] ?? null) !== $file) {
            $this->rewrites[$source] = $file;
            $this->dirty = true;
        }
    }

    public function redirects(): array
    {
        return array_values(array_filter(array_map([RedirectRule::class, 'fromArray'], $this->redirects)));
    }

    public function rewrites(): array
    {
        return $this->rewrites;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }
        file_put_contents(
            $this->file,
            json_encode(['redirects' => $this->redirects, 'rewrites' => $this->rewrites], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->dirty = false;
    }
}
