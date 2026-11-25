<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Deploy\DeployFailed;
use WP2Vercel\Deploy\GitHubDeployer;
use WP2Vercel\Deploy\VercelDeployer;
use WP2Vercel\Http\HttpResponse;
use WP2Vercel\Output\Manifest;
use WP2Vercel\Tests\Support\FakeHttpClient;
use WP2Vercel\Tests\Support\TemporarySite;

final class DeployersTest extends TestCase
{
    private array $log = [];

    private function logger(): callable
    {
        return function (string $message): void {
            $this->log[] = $message;
        };
    }

    public function testGitBlobShaMatchesGit(): void
    {
        $site = new TemporarySite(['hello.txt' => "hello\n"]);
        self::assertSame('ce013625030ba8dba906f756967f9e9ca394464a', Manifest::gitBlobSha($site->path('hello.txt'), 6));
    }

    public function testVercelUploadsOnlyMissingFilesThenPolls(): void
    {
        $site = new TemporarySite(['index.html' => '<h1>Home</h1>', 'about/index.html' => '<h1>About</h1>', 'vercel.json' => '{}']);
        $manifest = $site->manifest();
        $missingSha = sha1('<h1>About</h1>');
        $http = new FakeHttpClient();
        $creates = 0;
        $http->on('POST', '~/v13/deployments~', function () use (&$creates, $missingSha): HttpResponse {
            $creates++;
            return $creates === 1
                ? FakeHttpClient::json(400, ['error' => ['code' => 'missing_files', 'message' => 'Missing files', 'missing' => [$missingSha]]])
                : FakeHttpClient::json(200, ['id' => 'dpl_1', 'url' => 'site-abc.vercel.app', 'inspectorUrl' => 'https://vercel.com/i', 'readyState' => 'QUEUED']);
        });
        $http->on('POST', '~/v2/files~', static fn(): HttpResponse => FakeHttpClient::json(200, []));
        $polls = 0;
        $http->on('GET', '~/v13/deployments/dpl_1~', function () use (&$polls): HttpResponse {
            $polls++;
            return FakeHttpClient::json(200, ['readyState' => $polls < 2 ? 'BUILDING' : 'READY', 'alias' => ['site.vercel.app']]);
        });

        $deployer = new VercelDeployer($http, 'token', 'site', 'team_1', true);
        $state = [];
        for ($tick = 0; $tick < 5 && empty($state['done']); $tick++) {
            $state['wait_until'] = 0;
            $state = $deployer->advance($state, $manifest, new Deadline(5), $this->logger());
        }

        self::assertTrue($state['done']);
        self::assertSame('https://site-abc.vercel.app', $state['url']);
        self::assertSame(['https://site.vercel.app'], $state['aliases']);

        $uploads = $http->sent('POST', '~/v2/files~');
        self::assertCount(1, $uploads);
        self::assertSame($missingSha, $uploads[0]['headers']['x-vercel-digest']);
        self::assertSame('<h1>About</h1>', $uploads[0]['body']);
        self::assertStringContainsString('teamId=team_1', $uploads[0]['url']);

        $created = $http->sent('POST', '~/v13/deployments~');
        self::assertCount(2, $created);
        $payload = json_decode($created[1]['body'], true);
        self::assertSame('production', $payload['target']);
        self::assertSame('site', $payload['project']);
        self::assertNull($payload['projectSettings']['framework']);
        self::assertContains(['file' => 'about/index.html', 'sha' => $missingSha, 'size' => 14], $payload['files']);
        self::assertSame('Bearer token', $created[0]['headers']['Authorization']);
    }

    public function testVercelPreviewOmitsTargetAndSurfacesErrors(): void
    {
        $site = new TemporarySite(['index.html' => 'x']);
        $http = new FakeHttpClient();
        $http->on('POST', '~/v13/deployments~', static fn(): HttpResponse => FakeHttpClient::json(403, ['error' => ['code' => 'forbidden', 'message' => 'Not authorized']]));
        $deployer = new VercelDeployer($http, 'token', 'site', '', false);

        try {
            $deployer->advance([], $site->manifest(), new Deadline(5), $this->logger());
            self::fail('Expected the deployment to fail.');
        } catch (DeployFailed $error) {
            self::assertStringContainsString('Not authorized', $error->getMessage());
        }
        $payload = json_decode($http->sent('POST', '~/v13/deployments~')[0]['body'], true);
        self::assertArrayNotHasKey('target', $payload);
        self::assertStringNotContainsString('teamId', $http->requests[0]['url']);
    }

    public function testVercelFailedBuildStateStopsTheDeploy(): void
    {
        $site = new TemporarySite(['index.html' => 'x']);
        $http = new FakeHttpClient();
        $http->on('POST', '~/v13/deployments~', static fn(): HttpResponse => FakeHttpClient::json(200, ['id' => 'dpl_2', 'url' => 'x.vercel.app']));
        $http->on('GET', '~/v13/deployments/dpl_2~', static fn(): HttpResponse => FakeHttpClient::json(200, ['readyState' => 'ERROR', 'errorMessage' => 'Invalid vercel.json']));
        $this->expectException(DeployFailed::class);
        $this->expectExceptionMessage('Invalid vercel.json');
        (new VercelDeployer($http, 'token', 'site', '', false))->advance([], $site->manifest(), new Deadline(5), $this->logger());
    }

    public function testConfigurationErrors(): void
    {
        $http = new FakeHttpClient();
        self::assertNotNull((new VercelDeployer($http, '', 'site', '', false))->configurationError());
        self::assertNotNull((new VercelDeployer($http, 'token', '', '', false))->configurationError());
        self::assertNull((new VercelDeployer($http, 'token', 'site', '', false))->configurationError());
        self::assertNotNull((new GitHubDeployer($http, 'token', '', 'preview', 'main', ''))->configurationError());
        self::assertNull((new GitHubDeployer($http, 'token', 'o/r', 'preview', 'main', ''))->configurationError());
    }

    public function testGitHubCommitsOnlyChangesAndDeletionsInOneCommit(): void
    {
        $site = new TemporarySite(['index.html' => 'new home', 'about/index.html' => 'same about', 'vercel.json' => '{}']);
        $manifest = $site->manifest();
        $unchangedSha = Manifest::gitBlobSha($site->path('about/index.html'), strlen('same about'));

        $http = new FakeHttpClient();
        $http->on('GET', '~/git/ref/heads/wp2vercel-preview$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['object' => ['sha' => 'parent1']]));
        $http->on('GET', '~/git/commits/parent1$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['tree' => ['sha' => 'tree0']]));
        $http->on('GET', '~/git/trees/tree0\?recursive=1$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['truncated' => false, 'tree' => [
            ['path' => 'index.html', 'type' => 'blob', 'sha' => 'stale'],
            ['path' => 'about/index.html', 'type' => 'blob', 'sha' => $unchangedSha],
            ['path' => 'removed/index.html', 'type' => 'blob', 'sha' => 'gone'],
            ['path' => 'README.md', 'type' => 'blob', 'sha' => 'readme'],
            ['path' => '.github/workflows/ci.yml', 'type' => 'blob', 'sha' => 'ci'],
            ['path' => 'about', 'type' => 'tree', 'sha' => 't'],
        ]]));
        $http->on('POST', '~/git/blobs$~', static function (array $request): HttpResponse {
            $content = base64_decode(json_decode($request['body'], true)['content']);
            return FakeHttpClient::json(201, ['sha' => sha1('blob ' . strlen($content) . "\0" . $content)]);
        });
        $http->on('POST', '~/git/trees$~', static fn(): HttpResponse => FakeHttpClient::json(201, ['sha' => 'tree1']));
        $http->on('POST', '~/git/commits$~', static fn(): HttpResponse => FakeHttpClient::json(201, ['sha' => 'commit1abcdef']));
        $http->on('PATCH', '~/git/refs/heads/wp2vercel-preview$~', static fn(): HttpResponse => FakeHttpClient::json(200, []));

        $state = (new GitHubDeployer($http, 'token', 'owner/repo', 'wp2vercel-preview', 'main', ''))
            ->advance([], $manifest, new Deadline(5), $this->logger());

        self::assertTrue($state['done']);
        self::assertSame('commit1abcdef', $state['commit']);
        self::assertCount(2, $http->sent('POST', '~/git/blobs$~'));
        self::assertCount(1, $http->sent('POST', '~/git/commits$~'));

        $tree = json_decode($http->sent('POST', '~/git/trees$~')[0]['body'], true);
        self::assertSame('tree0', $tree['base_tree']);
        $byPath = array_column($tree['tree'], 'sha', 'path');
        self::assertEqualsCanonicalizing(['index.html', 'vercel.json', 'removed/index.html'], array_keys($byPath));
        self::assertNull($byPath['removed/index.html']);

        $commit = json_decode($http->sent('POST', '~/git/commits$~')[0]['body'], true);
        self::assertSame(['parent1'], $commit['parents']);
        self::assertSame('tree1', $commit['tree']);
        self::assertFalse(json_decode($http->sent('PATCH', '~/git/refs/~')[0]['body'], true)['force']);
    }

    public function testGitHubCreatesBranchAndSkipsCommitWhenNothingChanged(): void
    {
        $site = new TemporarySite(['index.html' => 'home']);
        $manifest = $site->manifest();
        $sha = Manifest::gitBlobSha($site->path('index.html'), 4);

        $http = new FakeHttpClient();
        $http->on('GET', '~/git/ref/heads/preview$~', static fn(): HttpResponse => FakeHttpClient::json(404, ['message' => 'Not Found']));
        $http->on('GET', '~/git/ref/heads/main$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['object' => ['sha' => 'base1']]));
        $http->on('POST', '~/git/refs$~', static fn(): HttpResponse => FakeHttpClient::json(201, []));
        $http->on('GET', '~/git/commits/base1$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['tree' => ['sha' => 'tree0']]));
        $http->on('GET', '~/git/trees/tree0~', static fn(): HttpResponse => FakeHttpClient::json(200, ['tree' => [
            ['path' => 'public/index.html', 'type' => 'blob', 'sha' => $sha],
            ['path' => 'package.json', 'type' => 'blob', 'sha' => 'pkg'],
        ]]));

        $state = (new GitHubDeployer($http, 'token', 'owner/repo', 'preview', 'main', 'public'))
            ->advance([], $manifest, new Deadline(5), $this->logger());

        self::assertTrue($state['done']);
        self::assertSame('base1', $state['commit']);
        self::assertSame(['ref' => 'refs/heads/preview', 'sha' => 'base1'], json_decode($http->sent('POST', '~/git/refs$~')[0]['body'], true));
        self::assertSame([], $http->sent('POST', '~/git/(blobs|trees|commits)$~'));
    }

    public function testGitHubRateLimitPausesInsteadOfFailing(): void
    {
        $site = new TemporarySite(['index.html' => 'home']);
        $http = new FakeHttpClient();
        $http->on('GET', '~/git/ref/heads/preview$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['object' => ['sha' => 'p']]));
        $http->on('GET', '~/git/commits/p$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['tree' => ['sha' => 't']]));
        $http->on('GET', '~/git/trees/t~', static fn(): HttpResponse => FakeHttpClient::json(200, ['tree' => []]));
        $http->on('POST', '~/git/blobs$~', static fn(): HttpResponse => FakeHttpClient::json(403, ['message' => 'API rate limit exceeded'], ['x-ratelimit-remaining' => '0']));

        $state = (new GitHubDeployer($http, 'token', 'owner/repo', 'preview', 'main', ''))
            ->advance([], $site->manifest(), new Deadline(5), $this->logger());

        self::assertArrayNotHasKey('done', $state);
        self::assertSame('blobs', $state['phase']);
        self::assertGreaterThan(time(), $state['wait_until']);
        self::assertCount(1, $http->sent('POST', '~/git/blobs$~'));
    }
}
