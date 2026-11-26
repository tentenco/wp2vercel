<?php

declare(strict_types=1);

namespace WP2Vercel\Deploy;

use WP2Vercel\Build\Deadline;
use WP2Vercel\Http\HttpClient;
use WP2Vercel\Http\HttpResponse;
use WP2Vercel\Output\Manifest;

final class GitHubDeployer implements Deployer
{
    private const API = 'https://api.github.com';
    private const MAX_FILE_BYTES = 50 * 1024 * 1024;
    private const TREE_CHUNK = 500;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
        private readonly string $repository,
        private readonly string $branch,
        private readonly string $baseBranch,
        private readonly string $directory,
    ) {
    }

    public function configurationError(): ?string
    {
        if ($this->token === '') {
            return __('Add a GitHub access token before publishing to GitHub.', 'wp2vercel');
        }
        if ($this->repository === '') {
            return __('Enter the GitHub repository as owner/name before publishing to GitHub.', 'wp2vercel');
        }
        if ($this->branch === '') {
            return __('Enter the branch that receives published files.', 'wp2vercel');
        }
        return null;
    }

    public function testConnection(): string
    {
        $response = $this->api('GET', '');
        if (!$response->ok()) {
            throw new DeployFailed($this->connectionProblem($response));
        }
        $data = $response->json();
        if (empty($data['permissions']['push'])) {
            throw new DeployFailed('The token can read ' . $this->repository . ' but cannot push to it.');
        }
        return sprintf('Connected to %s (default branch: %s).', $this->repository, (string) ($data['default_branch'] ?? ''));
    }

    private function connectionProblem(HttpResponse $response): string
    {
        $answer = $response->describe();
        return match ($response->status) {
            0 => sprintf(__('GitHub could not be reached (%s). Check that this server can open api.github.com.', 'wp2vercel'), $answer),
            401 => sprintf(__('GitHub did not accept the access token (%s). It may be mistyped, expired or revoked. Create a new token and paste it again.', 'wp2vercel'), $answer),
            403 => sprintf(
                __('GitHub refused the request for %1$s (%2$s). When this is about permissions: the token needs read and write access to Contents on this repository, and in an organization with single sign-on it has to be authorized for that organization.', 'wp2vercel'),
                $this->repository,
                $answer
            ),
            404 => sprintf(
                __('GitHub cannot find %1$s with this access token (%2$s). Check the repository name, and that the token was given access to this repository.', 'wp2vercel'),
                $this->repository,
                $answer
            ),
            default => 'GitHub rejected the request (' . $answer . ').',
        };
    }

    public function advance(array $state, Manifest $manifest, Deadline $deadline, callable $log): array
    {
        $state += ['phase' => 'ref', 'pending' => [], 'changes' => [], 'deletions' => [], 'uploaded' => 0, 'tree' => ''];

        while (!$deadline->passed() && empty($state['done'])) {
            if (time() < (int) ($state['wait_until'] ?? 0)) {
                return $state;
            }
            $state = match ($state['phase']) {
                'ref' => $this->resolveBranch($state, $log),
                'diff' => $this->diff($state, $manifest, $log),
                'blobs' => $this->uploadBlobs($state, $manifest, $deadline, $log),
                'tree' => $this->buildTree($state, $deadline),
                'commit' => $this->commit($state, $log),
            };
        }
        return $state;
    }

    private function resolveBranch(array $state, callable $log): array
    {
        $reference = $this->api('GET', '/git/ref/heads/' . $this->encodeRef($this->branch));
        if ($reference->status === 404) {
            $base = $this->api('GET', '/git/ref/heads/' . $this->encodeRef($this->baseBranch));
            if (!$base->ok()) {
                throw new DeployFailed(sprintf(
                    'Branch "%s" does not exist and base branch "%s" could not be read (%s). Create the repository with an initial commit first.',
                    $this->branch,
                    $this->baseBranch,
                    $base->describe()
                ));
            }
            $sha = (string) ($base->json()['object']['sha'] ?? '');
            $created = $this->api('POST', '/git/refs', ['ref' => 'refs/heads/' . $this->branch, 'sha' => $sha]);
            if (!$created->ok()) {
                throw new DeployFailed('Could not create branch "' . $this->branch . '" (' . $created->describe() . ').');
            }
            $log(sprintf('Created branch %s from %s.', $this->branch, $this->baseBranch));
            $state['parent'] = $sha;
        } elseif ($reference->ok()) {
            $state['parent'] = (string) ($reference->json()['object']['sha'] ?? '');
        } else {
            throw new DeployFailed(
                in_array($reference->status, [0, 401, 403], true)
                    ? $this->connectionProblem($reference)
                    : 'Could not read branch "' . $this->branch . '" (' . $reference->describe() . ').'
            );
        }

        $commit = $this->api('GET', '/git/commits/' . $state['parent']);
        if (!$commit->ok()) {
            throw new DeployFailed('Could not read the latest commit (' . $commit->describe() . ').');
        }
        $state['base_tree'] = (string) ($commit->json()['tree']['sha'] ?? '');
        $state['tree'] = $state['base_tree'];
        $state['phase'] = 'diff';
        return $state;
    }

    private function diff(array $state, Manifest $manifest, callable $log): array
    {
        $response = $this->api('GET', '/git/trees/' . $state['base_tree'] . '?recursive=1');
        if (!$response->ok()) {
            throw new DeployFailed('Could not list the repository tree (' . $response->describe() . ').');
        }
        $data = $response->json();
        $prefix = $this->directory === '' ? '' : $this->directory . '/';
        $remote = [];
        foreach ((array) ($data['tree'] ?? []) as $node) {
            if (($node['type'] ?? '') === 'blob' && ($prefix === '' || str_starts_with((string) $node['path'], $prefix))) {
                $remote[(string) $node['path']] = (string) $node['sha'];
            }
        }
        $truncated = !empty($data['truncated']);

        $local = [];
        $pending = [];
        foreach ($manifest->entries() as $entry) {
            if ($entry['size'] > self::MAX_FILE_BYTES) {
                throw new DeployFailed(sprintf('%s is larger than 50 MB and cannot be committed through the GitHub API.', $entry['path']));
            }
            $path = $prefix . $entry['path'];
            $local[$path] = true;
            if (($remote[$path] ?? null) !== $entry['git']) {
                $pending[] = ['path' => $entry['path'], 'git' => $entry['git']];
            }
        }

        $deletions = [];
        if ($truncated) {
            $log('The repository tree is too large to list completely; files removed from the site will stay in the repository.');
        } else {
            foreach (array_keys($remote) as $path) {
                if (!isset($local[$path]) && !$this->isProtectedPath($path, $prefix)) {
                    $deletions[] = $path;
                }
            }
        }

        $state['pending'] = $pending;
        $state['deletions'] = $deletions;
        $state['phase'] = 'blobs';
        $log(sprintf('GitHub diff: %d files to upload, %d to delete, %d unchanged.', count($pending), count($deletions), count($local) - count($pending)));
        return $state;
    }

    private function uploadBlobs(array $state, Manifest $manifest, Deadline $deadline, callable $log): array
    {
        $prefix = $this->directory === '' ? '' : $this->directory . '/';
        while ($state['pending'] !== [] && !$deadline->passed()) {
            $file = $state['pending'][0];
            $content = file_get_contents($manifest->absolutePath($file['path']));
            if ($content === false) {
                throw new DeployFailed('Could not read ' . $file['path']);
            }
            $response = $this->api('POST', '/git/blobs', ['content' => base64_encode($content), 'encoding' => 'base64'], 300);
            if ($this->isRateLimited($response)) {
                $log('GitHub rate limit reached; the upload resumes on the next run.');
                $state['wait_until'] = time() + 60;
                return $state;
            }
            if (!$response->ok()) {
                throw new DeployFailed(sprintf('Uploading %s to GitHub failed (%s).', $file['path'], $response->describe()));
            }
            $sha = (string) ($response->json()['sha'] ?? '');
            if ($sha !== $file['git']) {
                throw new DeployFailed(sprintf('GitHub stored %s with an unexpected hash.', $file['path']));
            }
            $state['changes'][] = ['path' => $prefix . $file['path'], 'sha' => $sha];
            array_shift($state['pending']);
            $state['uploaded']++;
        }
        if ($state['pending'] === []) {
            $state['phase'] = 'tree';
        }
        return $state;
    }

    private function buildTree(array $state, Deadline $deadline): array
    {
        while (($state['changes'] !== [] || $state['deletions'] !== []) && !$deadline->passed()) {
            $entries = [];
            foreach (array_splice($state['changes'], 0, self::TREE_CHUNK) as $change) {
                $entries[] = ['path' => $change['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => $change['sha']];
            }
            foreach (array_splice($state['deletions'], 0, max(0, self::TREE_CHUNK - count($entries))) as $path) {
                $entries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
            }
            $response = $this->api('POST', '/git/trees', ['base_tree' => $state['tree'], 'tree' => $entries], 120);
            if (!$response->ok()) {
                throw new DeployFailed('Could not write the repository tree (' . $response->describe() . ').');
            }
            $state['tree'] = (string) ($response->json()['sha'] ?? '');
        }
        if ($state['changes'] === [] && $state['deletions'] === []) {
            $state['phase'] = 'commit';
        }
        return $state;
    }

    private function commit(array $state, callable $log): array
    {
        $state['done'] = true;
        $state['branch'] = $this->branch;
        $state['url'] = 'https://github.com/' . $this->repository . '/tree/' . $this->branch;

        if ($state['tree'] === $state['base_tree']) {
            $state['commit'] = $state['parent'];
            $log('GitHub already has this exact output; no commit was needed.');
            return $state;
        }

        $commit = $this->api('POST', '/git/commits', [
            'message' => sprintf('Publish static site from WP2Vercel (%s)', gmdate('Y-m-d H:i') . ' UTC'),
            'tree' => $state['tree'],
            'parents' => [$state['parent']],
        ]);
        if (!$commit->ok()) {
            throw new DeployFailed('Could not create the commit (' . $commit->describe() . ').');
        }
        $sha = (string) ($commit->json()['sha'] ?? '');

        $updated = $this->api('PATCH', '/git/refs/heads/' . $this->encodeRef($this->branch), ['sha' => $sha, 'force' => false]);
        if (!$updated->ok()) {
            throw new DeployFailed('The branch moved while publishing, so the commit was not applied (' . $updated->describe() . '). Publish again.');
        }

        $state['commit'] = $sha;
        $state['commit_url'] = 'https://github.com/' . $this->repository . '/commit/' . $sha;
        $log(sprintf('Pushed commit %s to %s (%d files uploaded).', substr($sha, 0, 7), $this->branch, $state['uploaded']));
        return $state;
    }

    private function isProtectedPath(string $path, string $prefix): bool
    {
        $relative = substr($path, strlen($prefix));
        return (bool) preg_match('~^(?:\.git|\.github/|\.vercel/|README(?:\.md)?$|LICENSE(?:\.md)?$|\.vercelignore$)~i', $relative);
    }

    private function isRateLimited(HttpResponse $response): bool
    {
        if ($response->status === 429) {
            return true;
        }
        if ($response->status !== 403) {
            return false;
        }
        return $response->header('x-ratelimit-remaining') === '0'
            || $response->header('retry-after') !== ''
            || stripos($response->body, 'rate limit') !== false;
    }

    private function encodeRef(string $ref): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $ref)));
    }

    private function api(string $method, string $path, ?array $payload = null, int $timeout = 60): HttpResponse
    {
        return $this->http->request(
            $method,
            self::API . '/repos/' . $this->repository . $path,
            [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'Content-Type' => 'application/json',
            ],
            $payload === null ? null : (string) wp_json_encode($payload),
            $timeout
        );
    }
}
