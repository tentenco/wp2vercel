<?php

declare(strict_types=1);

namespace WP2Vercel\Http;

interface HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
}
