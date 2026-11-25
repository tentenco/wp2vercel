<?php

declare(strict_types=1);

namespace WP2Vercel\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly string $error = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function describe(): string
    {
        if ($this->error !== '') {
            return $this->error;
        }
        $data = $this->json();
        $message = $data['error']['message'] ?? $data['message'] ?? '';
        return 'HTTP ' . $this->status . (is_string($message) && $message !== '' ? ': ' . $message : '');
    }
}
