<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Support;

use WP2Vercel\Http\HttpClient;
use WP2Vercel\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    public array $requests = [];
    private array $handlers = [];

    public function on(string $method, string $urlPattern, callable $handler): void
    {
        $this->handlers[] = [$method, $urlPattern, $handler];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $this->requests[] = $request;
        foreach ($this->handlers as [$handlerMethod, $pattern, $handler]) {
            if ($handlerMethod === $method && preg_match($pattern, $url)) {
                $response = $handler($request);
                if ($response instanceof HttpResponse) {
                    return $response;
                }
            }
        }
        return new HttpResponse(599, '', [], 'No fake response for ' . $method . ' ' . $url);
    }

    public function sent(string $method, string $urlPattern): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn(array $request): bool => $request['method'] === $method && (bool) preg_match($urlPattern, $request['url'])
        ));
    }

    public static function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($data), $headers);
    }
}
