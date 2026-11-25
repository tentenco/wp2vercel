<?php

declare(strict_types=1);

namespace WP2Vercel\Deploy;

use WP2Vercel\Build\Deadline;
use WP2Vercel\Http\HttpClient;
use WP2Vercel\Http\HttpResponse;
use WP2Vercel\Output\Manifest;

final class VercelDeployer implements Deployer
{
    private const API = 'https://api.vercel.com';
    private const MAX_FILE_BYTES = 100 * 1024 * 1024;
    private const MAX_CREATE_ATTEMPTS = 4;
    private const MAX_POLLS = 120;
    private const POLL_INTERVAL_SECONDS = 3;
    private const FAILED_STATES = ['ERROR', 'CANCELED'];

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
        private readonly string $project,
        private readonly string $teamId,
        private readonly bool $production,
    ) {
    }

    public function configurationError(): ?string
    {
        if ($this->token === '') {
            return __('Add a Vercel access token before publishing to Vercel.', 'wp2vercel');
        }
        if ($this->project === '') {
            return __('Enter the Vercel project name before publishing to Vercel.', 'wp2vercel');
        }
        return null;
    }

    public function testConnection(): string
    {
        $response = $this->http->request('GET', $this->url('/v9/projects/' . rawurlencode($this->project)), $this->headers());
        if ($response->status === 404) {
            return sprintf('Token accepted. Project "%s" does not exist yet and will be created on the first publish.', $this->project);
        }
        if (!$response->ok()) {
            throw new DeployFailed('Vercel rejected the request (' . $response->describe() . ').');
        }
        return sprintf('Connected to Vercel project "%s".', (string) ($response->json()['name'] ?? $this->project));
    }

    public function advance(array $state, Manifest $manifest, Deadline $deadline, callable $log): array
    {
        $state += ['phase' => 'create', 'missing' => [], 'attempts' => 0, 'polls' => 0, 'uploaded' => 0];

        while (!$deadline->passed()) {
            if (time() < (int) ($state['wait_until'] ?? 0)) {
                return $state;
            }
            switch ($state['phase']) {
                case 'create':
                    $state = $this->create($state, $manifest, $log);
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
        if ($state['attempts'] >= self::MAX_CREATE_ATTEMPTS) {
            throw new DeployFailed('Vercel kept reporting missing files after several uploads.');
        }
        $files = [];
        foreach ($manifest->entries() as $entry) {
            if ($entry['size'] > self::MAX_FILE_BYTES) {
                throw new DeployFailed(sprintf('%s is larger than 100 MB and cannot be deployed to Vercel.', $entry['path']));
            }
            $files[] = ['file' => $entry['path'], 'sha' => $entry['sha1'], 'size' => $entry['size']];
        }

        $payload = [
            'name' => $this->project,
            'project' => $this->project,
            'files' => $files,
            'projectSettings' => ['framework' => null, 'buildCommand' => null, 'installCommand' => null, 'outputDirectory' => null],
            'meta' => ['source' => 'wp2vercel'],
        ];
        if ($this->production) {
            $payload['target'] = 'production';
        }

        $state['attempts']++;
        $response = $this->http->request(
            'POST',
            $this->url('/v13/deployments', ['skipAutoDetectionConfirmation' => '1']),
            $this->headers() + ['Content-Type' => 'application/json'],
            (string) wp_json_encode($payload),
            120
        );
        $data = $response->json();

        if (($data['error']['code'] ?? '') === 'missing_files') {
            $state['missing'] = array_values((array) ($data['error']['missing'] ?? []));
            $state['phase'] = 'upload';
            $log(sprintf('Vercel needs %d of %d files.', count($state['missing']), count($files)));
            return $state;
        }
        if (!$response->ok() || empty($data['id'])) {
            throw new DeployFailed('Vercel did not accept the deployment (' . $response->describe() . ').');
        }

        $state['id'] = (string) $data['id'];
        $state['url'] = 'https://' . (string) ($data['url'] ?? '');
        $state['inspector_url'] = (string) ($data['inspectorUrl'] ?? '');
        $state['phase'] = 'poll';
        $log('Vercel deployment created: ' . $state['url']);
        return $state;
    }

    private function upload(array $state, Manifest $manifest, Deadline $deadline, callable $log): array
    {
        $paths = $manifest->pathBySha1();
        while ($state['missing'] !== [] && !$deadline->passed()) {
            $sha = (string) $state['missing'][0];
            if (!isset($paths[$sha])) {
                throw new DeployFailed('Vercel asked for a file that is not part of this build.');
            }
            $content = file_get_contents($manifest->absolutePath($paths[$sha]));
            if ($content === false) {
                throw new DeployFailed('Could not read ' . $paths[$sha]);
            }
            $response = $this->http->request('POST', $this->url('/v2/files'), $this->headers() + [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($content),
                'x-vercel-digest' => $sha,
            ], $content, 300);
            if (!$response->ok()) {
                throw new DeployFailed(sprintf('Uploading %s to Vercel failed (%s).', $paths[$sha], $response->describe()));
            }
            array_shift($state['missing']);
            $state['uploaded']++;
        }
        if ($state['missing'] === []) {
            $log(sprintf('Uploaded %d files to Vercel.', $state['uploaded']));
            $state['phase'] = 'create';
        }
        return $state;
    }

    private function poll(array $state, callable $log): array
    {
        $response = $this->http->request('GET', $this->url('/v13/deployments/' . rawurlencode((string) $state['id'])), $this->headers());
        $data = $response->json();
        $readyState = (string) ($data['readyState'] ?? $data['status'] ?? '');
        $state['polls']++;

        if ($readyState === 'READY') {
            $state['done'] = true;
            $state['ready_state'] = $readyState;
            $state['aliases'] = array_values(array_map(static fn($alias): string => 'https://' . $alias, (array) ($data['alias'] ?? [])));
            $log('Vercel deployment is ready: ' . $state['url']);
            return $state;
        }
        if (in_array($readyState, self::FAILED_STATES, true)) {
            throw new DeployFailed('Vercel reported the deployment as ' . $readyState . '. ' . (string) ($data['errorMessage'] ?? ''));
        }
        if ($state['polls'] >= self::MAX_POLLS) {
            throw new DeployFailed('Vercel did not finish the deployment in time. Check ' . ($state['inspector_url'] ?: $state['url']));
        }
        $state['wait_until'] = time() + self::POLL_INTERVAL_SECONDS;
        return $state;
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function url(string $path, array $query = []): string
    {
        if ($this->teamId !== '') {
            $query['teamId'] = $this->teamId;
        }
        return self::API . $path . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
