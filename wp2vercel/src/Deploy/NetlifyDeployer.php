<?php

declare(strict_types=1);

namespace WP2Vercel\Deploy;

use WP2Vercel\Build\Deadline;
use WP2Vercel\Http\HttpClient;
use WP2Vercel\Http\HttpResponse;
use WP2Vercel\Output\Manifest;

final class NetlifyDeployer implements Deployer
{
    private const API = 'https://api.netlify.com/api/v1';
    private const MAX_POLLS = 200;
    private const POLL_INTERVAL_SECONDS = 3;
    private const PREPARED_STATES = ['prepared', 'uploading', 'uploaded', 'processing', 'processed', 'ready'];
    private const FAILED_STATES = ['error', 'rejected'];
    private const MAX_PAUSES = 20;
    private const DEFAULT_PAUSE_SECONDS = 10;
    private const LONGEST_PAUSE_SECONDS = 120;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
        private readonly string $site,
        private readonly bool $production,
    ) {
    }

    public function configurationError(): ?string
    {
        if ($this->token === '') {
            return __('Add a Netlify access token before publishing to Netlify.', 'wp2vercel');
        }
        if ($this->site === '') {
            return __('Enter the Netlify site before publishing to Netlify.', 'wp2vercel');
        }
        return null;
    }

    public function testConnection(): string
    {
        $response = $this->http->request('GET', $this->url('/sites/' . rawurlencode($this->site)), $this->headers());
        if (!$response->ok()) {
            throw new DeployFailed($this->connectionProblem($response));
        }
        $data = $response->json();
        return sprintf(
            'Connected to Netlify site "%s" (%s).',
            (string) ($data['name'] ?? $this->site),
            (string) ($data['ssl_url'] ?? $data['url'] ?? $this->site)
        );
    }

    public function advance(array $state, Manifest $manifest, Deadline $deadline, callable $log): array
    {
        $state += ['phase' => 'create', 'required' => [], 'polls' => 0, 'uploaded' => 0];

        while (!$deadline->passed()) {
            if (time() < (int) ($state['wait_until'] ?? 0)) {
                return $state;
            }
            switch ($state['phase']) {
                case 'create':
                    $state = $this->create($state, $manifest, $log);
                    break;
                case 'prepare':
                    $state = $this->prepare($state, $log);
                    break;
                case 'upload':
                    $state = $this->upload($state, $manifest, $deadline, $log);
                    break;
                case 'poll':
                    return $this->poll($state, $log);
            }
        }
        return $state;
    }

    private function create(array $state, Manifest $manifest, callable $log): array
    {
        $files = [];
        foreach ($manifest->entries() as $entry) {
            $files['/' . ltrim((string) $entry['path'], '/')] = (string) $entry['sha1'];
        }
        $response = $this->http->request(
            'POST',
            $this->url('/sites/' . rawurlencode($this->site) . '/deploys'),
            $this->headers() + ['Content-Type' => 'application/json'],
            (string) wp_json_encode(['files' => (object) $files, 'draft' => !$this->production, 'async' => true, 'title' => 'WP2Vercel']),
            120
        );
        $data = $response->json();
        if ($response->status === 429 && ($paused = $this->pause($state, $response, $log)) !== null) {
            return $paused;
        }
        if (!$response->ok() || empty($data['id'])) {
            throw new DeployFailed(
                in_array($response->status, [0, 401, 403, 404], true)
                    ? $this->connectionProblem($response)
                    : 'Netlify did not accept the deploy (' . $response->describe() . ').'
            );
        }
        $state['id'] = (string) $data['id'];
        $state['url'] = (string) ($data['deploy_ssl_url'] ?? $data['deploy_url'] ?? '');
        $state['admin_url'] = (string) ($data['admin_url'] ?? '');
        $state['files'] = count($files);
        $state['phase'] = 'prepare';
        $log(sprintf('Netlify deploy created for %d files%s.', count($files), $this->production ? '' : ' as a draft'));
        return $this->afterPreparing($state, $data, $log);
    }

    private function prepare(array $state, callable $log): array
    {
        $response = $this->http->request('GET', $this->url('/deploys/' . rawurlencode((string) $state['id'])), $this->headers());
        if (self::isBusy($response) && ($paused = $this->pause($state, $response, $log)) !== null) {
            return $paused;
        }
        if (!$response->ok()) {
            throw new DeployFailed('Netlify did not report the deploy (' . $response->describe() . ').');
        }
        return $this->afterPreparing($state, $response->json(), $log);
    }

    private function afterPreparing(array $state, array $data, callable $log): array
    {
        $deployState = (string) ($data['state'] ?? '');
        if (in_array($deployState, self::FAILED_STATES, true)) {
            throw new DeployFailed('Netlify reported the deploy as ' . $deployState . '. ' . (string) ($data['error_message'] ?? ''));
        }
        if (!in_array($deployState, self::PREPARED_STATES, true)) {
            return $this->wait($state);
        }
        $state['required'] = array_values(array_unique(array_map('strval', (array) ($data['required'] ?? []))));
        $state['phase'] = $state['required'] === [] ? 'poll' : 'upload';
        $state['polls'] = 0;
        $log(sprintf('Netlify needs %d of %d files.', count($state['required']), (int) ($state['files'] ?? 0)));
        return $state;
    }

    private function upload(array $state, Manifest $manifest, Deadline $deadline, callable $log): array
    {
        $paths = $manifest->pathBySha1();
        while ($state['required'] !== [] && !$deadline->passed()) {
            $sha = (string) $state['required'][0];
            if (!isset($paths[$sha])) {
                throw new DeployFailed('Netlify asked for a file that is not part of this build.');
            }
            $content = file_get_contents($manifest->absolutePath($paths[$sha]));
            if ($content === false) {
                throw new DeployFailed('Could not read ' . $paths[$sha]);
            }
            $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($paths[$sha], '/'))));
            $response = $this->http->request(
                'PUT',
                $this->url('/deploys/' . rawurlencode((string) $state['id']) . '/files/' . $encoded),
                $this->headers() + ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($content)],
                $content,
                300
            );
            if (self::isBusy($response) && ($paused = $this->pause($state, $response, $log)) !== null) {
                return $paused;
            }
            if (!$response->ok()) {
                throw new DeployFailed(sprintf('Uploading %s to Netlify failed (%s).', $paths[$sha], $response->describe()));
            }
            array_shift($state['required']);
            $state['uploaded']++;
            $state['pauses'] = 0;
        }
        if ($state['required'] === []) {
            $log(sprintf('Uploaded %d files to Netlify.', $state['uploaded']));
            $state['phase'] = 'poll';
        }
        return $state;
    }

    private function poll(array $state, callable $log): array
    {
        $response = $this->http->request('GET', $this->url('/deploys/' . rawurlencode((string) $state['id'])), $this->headers());
        $data = $response->json();
        $deployState = (string) ($data['state'] ?? '');
        if ($deployState === 'ready') {
            $state['done'] = true;
            $state['url'] = (string) ($data['deploy_ssl_url'] ?? $state['url']);
            if ($this->production && !empty($data['ssl_url'])) {
                $state['aliases'] = [(string) $data['ssl_url']];
            }
            $log('Netlify deploy is ready: ' . $state['url']);
            return $state;
        }
        if (in_array($deployState, self::FAILED_STATES, true)) {
            throw new DeployFailed('Netlify reported the deploy as ' . $deployState . '. ' . (string) ($data['error_message'] ?? ''));
        }
        return $this->wait($state);
    }

    private static function isBusy(HttpResponse $response): bool
    {
        return $response->status === 0 || $response->status === 429 || $response->status >= 500;
    }

    private function pause(array $state, HttpResponse $response, callable $log): ?array
    {
        $state['pauses'] = (int) ($state['pauses'] ?? 0) + 1;
        if ($state['pauses'] > self::MAX_PAUSES) {
            return null;
        }
        $seconds = self::pauseSeconds($response);
        $state['wait_until'] = time() + $seconds;
        $log(sprintf('Netlify asked to slow down or did not answer (%s); the deploy goes on in %d seconds.', $response->describe(), $seconds));
        return $state;
    }

    private static function pauseSeconds(HttpResponse $response): int
    {
        $reset = (int) $response->header('x-ratelimit-reset');
        $retryAfter = (int) $response->header('retry-after');
        $seconds = match (true) {
            $reset > time() => $reset - time() + 1,
            $retryAfter > 0 => $retryAfter,
            default => self::DEFAULT_PAUSE_SECONDS,
        };
        return min($seconds, self::LONGEST_PAUSE_SECONDS);
    }

    private function wait(array $state): array
    {
        $state['polls']++;
        if ($state['polls'] >= self::MAX_POLLS) {
            throw new DeployFailed('Netlify did not finish the deploy in time. Check ' . ($state['admin_url'] ?: $state['url']));
        }
        $state['wait_until'] = time() + self::POLL_INTERVAL_SECONDS;
        return $state;
    }

    private function connectionProblem(HttpResponse $response): string
    {
        $answer = $response->describe();
        return match ($response->status) {
            0 => sprintf(__('Netlify could not be reached (%s). Check that this server can open api.netlify.com.', 'wp2vercel'), $answer),
            401, 403 => sprintf(__('Netlify did not accept the access token (%s). Check the token, and that it belongs to a member of the team that owns the site.', 'wp2vercel'), $answer),
            404 => sprintf(
                __('Netlify has no site "%1$s" that this token can see (%2$s). Enter the site name as in name.netlify.app, or the Site ID under Site configuration → General, and use a token of a member of its team.', 'wp2vercel'),
                $this->site,
                $answer
            ),
            default => 'Netlify rejected the request (' . $answer . ').',
        };
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function url(string $path): string
    {
        return self::API . $path;
    }
}
