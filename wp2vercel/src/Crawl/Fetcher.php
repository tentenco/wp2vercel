<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

use Throwable;
use WP2Vercel\Url\Origin;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

final class Fetcher
{
    public const RENDER_HEADER = 'X-WP2Vercel-Render';

    public function __construct(
        private readonly Origin $origin,
        private readonly Origin $fetchOrigin,
        private readonly string $renderToken,
        private readonly int $timeout,
        private readonly bool $verifySsl,
        private readonly string $basicAuthUser = '',
        private readonly string $basicAuthPassword = '',
    ) {
    }

    public function fetchMany(array $urls): array
    {
        $requests = [];
        foreach ($urls as $key => $url) {
            $requests[$key] = [
                'url' => $this->fetchOrigin->base() . substr($url, strlen($this->origin->base())),
                'type' => Requests::GET,
                'headers' => $this->headers(),
            ];
        }
        if ($requests === []) {
            return [];
        }

        try {
            $responses = Requests::request_multiple($requests, $this->options());
        } catch (Throwable $error) {
            return array_fill_keys(array_keys($requests), FetchResult::failure($error->getMessage()));
        }

        $results = [];
        foreach (array_keys($requests) as $key) {
            $response = $responses[$key] ?? null;
            if (!$response instanceof Response) {
                $results[$key] = FetchResult::failure($response instanceof Throwable ? $response->getMessage() : 'No response');
                continue;
            }
            $results[$key] = new FetchResult(
                (int) $response->status_code,
                (string) ($response->headers['content-type'] ?? ''),
                (string) ($response->headers['location'] ?? ''),
                (string) $response->body
            );
        }
        return $results;
    }

    private function headers(): array
    {
        $headers = [
            self::RENDER_HEADER => $this->renderToken,
            'Accept' => '*/*',
            'Accept-Encoding' => 'identity',
            'Cache-Control' => 'no-cache',
        ];
        if (!$this->origin->sameAuthorityAs($this->fetchOrigin)) {
            $headers['Host'] = $this->origin->authority;
        }
        if ($this->origin->scheme === 'https' && $this->fetchOrigin->scheme === 'http') {
            $headers['X-Forwarded-Proto'] = 'https';
        }
        if ($this->basicAuthUser !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->basicAuthUser . ':' . $this->basicAuthPassword);
        }
        return $headers;
    }

    private function options(): array
    {
        $bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        return [
            'timeout' => $this->timeout,
            'connect_timeout' => min(10, $this->timeout),
            'follow_redirects' => false,
            'useragent' => 'WP2Vercel/' . WP2VERCEL_VERSION . '; ' . $this->origin->base(),
            'verify' => $this->verifySsl ? (is_file($bundle) ? $bundle : true) : false,
            'verifyname' => $this->verifySsl,
        ];
    }
}
