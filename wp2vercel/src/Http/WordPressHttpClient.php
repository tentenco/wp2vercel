<?php

declare(strict_types=1);

namespace WP2Vercel\Http;

final class WordPressHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers + ['User-Agent' => 'WP2Vercel/' . WP2VERCEL_VERSION],
            'body' => $body,
            'timeout' => $timeout,
            'redirection' => 0,
            'data_format' => 'body',
        ]);
        if (is_wp_error($response)) {
            return new HttpResponse(0, '', [], $response->get_error_message());
        }
        $headers = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return new HttpResponse((int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response), $headers);
    }
}
