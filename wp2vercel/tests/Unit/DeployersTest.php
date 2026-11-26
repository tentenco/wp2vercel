<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Deploy\DeployFailed;
use WP2Vercel\Deploy\GitHubDeployer;
use WP2Vercel\Deploy\NetlifyDeployer;
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
            self::assertStringContainsString('Vercel did not accept the access token', $error->getMessage());
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

    private function connectionAnswer(callable $deployer, HttpResponse $answer): string
    {
        $http = new FakeHttpClient();
        $http->on('GET', '~.~', static fn(): HttpResponse => $answer);
        try {
            return $deployer($http)->testConnection();
        } catch (DeployFailed $error) {
            return 'failed: ' . $error->getMessage();
        }
    }

    public function testAFailedGitHubConnectionTestSaysWhatToFix(): void
    {
        $github = static fn(FakeHttpClient $http): GitHubDeployer => new GitHubDeployer($http, 'token', 'acme/site', 'preview', 'main', '');

        $expired = $this->connectionAnswer($github, FakeHttpClient::json(401, ['message' => 'Bad credentials']));
        self::assertStringContainsString('did not accept the access token (HTTP 401: Bad credentials)', $expired);
        self::assertStringContainsString('expired', $expired);

        $hidden = $this->connectionAnswer($github, FakeHttpClient::json(404, ['message' => 'Not Found']));
        self::assertStringContainsString('cannot find acme/site with this access token', $hidden);
        self::assertStringContainsString('given access to this repository', $hidden);

        $refused = $this->connectionAnswer($github, FakeHttpClient::json(403, ['message' => 'Resource not accessible by personal access token']));
        self::assertStringContainsString('Resource not accessible by personal access token', $refused);
        self::assertStringContainsString('Contents', $refused);

        $offline = $this->connectionAnswer($github, new HttpResponse(0, '', [], 'cURL error 6: Could not resolve host'));
        self::assertStringContainsString('could not be reached (cURL error 6: Could not resolve host)', $offline);
        self::assertStringContainsString('api.github.com', $offline);

        self::assertStringStartsWith('failed: GitHub rejected the request (HTTP 500', $this->connectionAnswer($github, FakeHttpClient::json(500, [])));
        self::assertStringStartsWith('failed: The token can read acme/site', $this->connectionAnswer($github, FakeHttpClient::json(200, ['permissions' => ['push' => false]])));
        self::assertStringStartsWith('Connected to acme/site', $this->connectionAnswer($github, FakeHttpClient::json(200, ['permissions' => ['push' => true], 'default_branch' => 'main'])));
    }

    public function testAFailedVercelConnectionTestSaysWhatToFix(): void
    {
        $vercel = static fn(FakeHttpClient $http): VercelDeployer => new VercelDeployer($http, 'token', 'site', '', false);

        $refused = $this->connectionAnswer($vercel, FakeHttpClient::json(403, ['error' => ['message' => 'Not authorized']]));
        self::assertStringContainsString('did not accept the access token (HTTP 403: Not authorized)', $refused);
        self::assertStringContainsString('Team ID', $refused);

        $offline = $this->connectionAnswer($vercel, new HttpResponse(0, '', [], 'cURL error 28: Timed out'));
        self::assertStringContainsString('could not be reached (cURL error 28: Timed out)', $offline);

        self::assertStringStartsWith('Token accepted.', $this->connectionAnswer($vercel, FakeHttpClient::json(404, [])));
        self::assertStringStartsWith('failed: Vercel rejected the request (HTTP 500', $this->connectionAnswer($vercel, FakeHttpClient::json(500, [])));
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

    public function testAPublishWithAnExpiredGitHubTokenSaysWhatToDo(): void
    {
        $site = new TemporarySite(['index.html' => 'home']);
        $http = new FakeHttpClient();
        $http->on('GET', '~/git/ref/heads/preview$~', static fn(): HttpResponse => FakeHttpClient::json(401, ['message' => 'Bad credentials']));

        try {
            (new GitHubDeployer($http, 'token', 'owner/repo', 'preview', 'main', 'public'))->advance([], $site->manifest(), new Deadline(5), $this->logger());
            self::fail('Expected the deployment to fail.');
        } catch (DeployFailed $error) {
            self::assertStringContainsString('GitHub did not accept the access token (HTTP 401: Bad credentials)', $error->getMessage());
            self::assertStringContainsString('Create a new token', $error->getMessage());
        }
    }

    public function testAGitHubAnswerThatIsNotAboutAccessKeepsNamingTheBranch(): void
    {
        $site = new TemporarySite(['index.html' => 'home']);
        $http = new FakeHttpClient();
        $http->on('GET', '~/git/ref/heads/preview$~', static fn(): HttpResponse => FakeHttpClient::json(500, ['message' => 'Server Error']));

        try {
            (new GitHubDeployer($http, 'token', 'owner/repo', 'preview', 'main', 'public'))->advance([], $site->manifest(), new Deadline(5), $this->logger());
            self::fail('Expected the deployment to fail.');
        } catch (DeployFailed $error) {
            self::assertSame('Could not read branch "preview" (HTTP 500: Server Error).', $error->getMessage());
        }
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

    public function testNetlifyUploadsOnlyTheFilesItAsksForThenWaitsUntilReady(): void
    {
        $site = new TemporarySite(['index.html' => '<h1>Home</h1>', 'about/index.html' => '<h1>About</h1>', '博客/a b.html' => '<p>B</p>', '_redirects' => "/old /new 301!\n"]);
        $manifest = $site->manifest();
        $http = new FakeHttpClient();
        $http->on('POST', '~/sites/blue-cat\.netlify\.app/deploys$~', static fn(): HttpResponse => FakeHttpClient::json(200, [
            'id' => 'dep1', 'state' => 'preparing', 'deploy_ssl_url' => 'https://dep1--blue-cat.netlify.app', 'admin_url' => 'https://app.netlify.com/sites/blue-cat',
        ]));
        $reads = 0;
        $http->on('GET', '~/deploys/dep1$~', function () use (&$reads): HttpResponse {
            $reads++;
            return match (true) {
                $reads === 1 => FakeHttpClient::json(200, ['state' => 'preparing']),
                $reads === 2 => FakeHttpClient::json(200, ['state' => 'prepared', 'required' => [sha1('<h1>About</h1>'), sha1('<p>B</p>')]]),
                $reads === 3 => FakeHttpClient::json(200, ['state' => 'processing']),
                default => FakeHttpClient::json(200, ['state' => 'ready', 'deploy_ssl_url' => 'https://dep1--blue-cat.netlify.app', 'ssl_url' => 'https://blue-cat.netlify.app']),
            };
        });
        $http->on('PUT', '~/deploys/dep1/files/~', static fn(): HttpResponse => FakeHttpClient::json(200, ['id' => 'f']));

        $deployer = new NetlifyDeployer($http, 'token', 'blue-cat.netlify.app', true);
        $state = [];
        for ($tick = 0; $tick < 8 && empty($state['done']); $tick++) {
            $state['wait_until'] = 0;
            $state = $deployer->advance($state, $manifest, new Deadline(5), $this->logger());
        }

        self::assertTrue($state['done']);
        self::assertSame('https://dep1--blue-cat.netlify.app', $state['url']);
        self::assertSame(['https://blue-cat.netlify.app'], $state['aliases']);
        $created = $http->sent('POST', '~/deploys$~');
        self::assertCount(1, $created);
        $payload = json_decode($created[0]['body'], true);
        self::assertFalse($payload['draft']);
        self::assertTrue($payload['async']);
        self::assertSame(sha1('<h1>About</h1>'), $payload['files']['/about/index.html']);
        self::assertArrayHasKey('/_redirects', $payload['files']);
        self::assertSame('Bearer token', $created[0]['headers']['Authorization']);
        $uploads = $http->sent('PUT', '~/deploys/dep1/files/~');
        self::assertCount(2, $uploads);
        self::assertStringEndsWith('/deploys/dep1/files/about/index.html', $uploads[0]['url']);
        self::assertStringEndsWith('/deploys/dep1/files/%E5%8D%9A%E5%AE%A2/a%20b.html', $uploads[1]['url']);
        self::assertSame('<p>B</p>', $uploads[1]['body']);
        self::assertSame('application/octet-stream', $uploads[1]['headers']['Content-Type']);
    }

    public function testNetlifyDraftsUnlessLiveAndSaysWhatToFix(): void
    {
        $site = new TemporarySite(['index.html' => '<h1>Home</h1>']);
        $http = new FakeHttpClient();
        $http->on('POST', '~/deploys$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['id' => 'dep2', 'state' => 'ready', 'required' => []]));
        $http->on('GET', '~/deploys/dep2$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['state' => 'ready', 'deploy_ssl_url' => 'https://dep2--x.netlify.app']));
        $state = (new NetlifyDeployer($http, 'token', 'x.netlify.app', false))->advance([], $site->manifest(), new Deadline(5), $this->logger());
        self::assertTrue($state['done']);
        self::assertArrayNotHasKey('aliases', $state);
        self::assertTrue(json_decode($http->sent('POST', '~/deploys$~')[0]['body'], true)['draft']);
        self::assertCount(0, $http->sent('PUT', '~/files/~'));

        $answer = static function (HttpResponse $response): string {
            $http = new FakeHttpClient();
            $http->on('GET', '~/sites/~', static fn(): HttpResponse => $response);
            try {
                return (new NetlifyDeployer($http, 'token', 'x.netlify.app', false))->testConnection();
            } catch (DeployFailed $error) {
                return $error->getMessage();
            }
        };
        self::assertStringContainsString('did not accept the access token', $answer(FakeHttpClient::json(401, ['message' => 'Access Denied'])));
        self::assertStringContainsString('no site "x.netlify.app"', $answer(FakeHttpClient::json(404, ['message' => 'Not Found'])));
        self::assertStringContainsString('could not be reached', $answer(new HttpResponse(0, '', [], 'cURL error 6')));
        self::assertSame('Connected to Netlify site "x" (https://x.netlify.app).', $answer(FakeHttpClient::json(200, ['name' => 'x', 'ssl_url' => 'https://x.netlify.app'])));

        $failing = new FakeHttpClient();
        $failing->on('POST', '~/deploys$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['id' => 'dep3', 'state' => 'error', 'error_message' => 'Deploy directory too large']));
        $this->expectException(DeployFailed::class);
        $this->expectExceptionMessage('Deploy directory too large');
        (new NetlifyDeployer($failing, 'token', 'x.netlify.app', false))->advance([], $site->manifest(), new Deadline(5), $this->logger());
    }

    public function testNetlifyWaitsWhenItIsRateLimitedAndGivesUpAfterManyPauses(): void
    {
        $site = new TemporarySite(['index.html' => '<h1>Home</h1>', 'about/index.html' => '<h1>About</h1>']);
        $manifest = $site->manifest();
        $http = new FakeHttpClient();
        $http->on('POST', '~/deploys$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['id' => 'dep4', 'state' => 'prepared', 'required' => [sha1('<h1>Home</h1>'), sha1('<h1>About</h1>')]]));
        $http->on('GET', '~/deploys/dep4$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['state' => 'ready', 'deploy_ssl_url' => 'https://dep4--x.netlify.app']));
        $puts = 0;
        $http->on('PUT', '~/deploys/dep4/files/~', function () use (&$puts): HttpResponse {
            $puts++;
            return $puts === 2
                ? FakeHttpClient::json(429, ['message' => 'Rate limit exceeded'], ['x-ratelimit-reset' => (string) (time() + 30)])
                : FakeHttpClient::json(200, ['id' => 'f']);
        });
        $deployer = new NetlifyDeployer($http, 'token', 'x.netlify.app', false);

        $state = $deployer->advance([], $manifest, new Deadline(5), $this->logger());
        self::assertSame(1, $state['uploaded']);
        self::assertCount(1, $state['required']);
        self::assertGreaterThanOrEqual(time() + 30, $state['wait_until']);
        self::assertLessThanOrEqual(time() + 31, $state['wait_until']);

        $state['wait_until'] = 0;
        $state = $deployer->advance($state, $manifest, new Deadline(5), $this->logger());
        self::assertTrue($state['done']);
        self::assertSame(2, $state['uploaded']);
        self::assertSame(0, $state['pauses']);

        $busy = new FakeHttpClient();
        $busy->on('POST', '~/deploys$~', static fn(): HttpResponse => FakeHttpClient::json(200, ['id' => 'dep5', 'state' => 'prepared', 'required' => [sha1('<h1>Home</h1>')]]));
        $busy->on('PUT', '~/deploys/dep5/files/~', static fn(): HttpResponse => FakeHttpClient::json(503, ['message' => 'Service Unavailable']));
        $deployer = new NetlifyDeployer($busy, 'token', 'x.netlify.app', false);
        $state = [];
        for ($run = 0; $run < 20; $run++) {
            $before = time();
            $state = $deployer->advance($state, $manifest, new Deadline(5), $this->logger());
            self::assertGreaterThanOrEqual($before + 10, $state['wait_until']);
            self::assertLessThanOrEqual(time() + 10, $state['wait_until']);
            $state['wait_until'] = 0;
        }
        $this->expectException(DeployFailed::class);
        $this->expectExceptionMessage('Uploading index.html to Netlify failed (HTTP 503: Service Unavailable)');
        $deployer->advance($state, $manifest, new Deadline(5), $this->logger());
    }

    public function testNetlifyConfigurationErrors(): void
    {
        $http = new FakeHttpClient();
        self::assertNotNull((new NetlifyDeployer($http, '', 'x.netlify.app', false))->configurationError());
        self::assertNotNull((new NetlifyDeployer($http, 'token', '', false))->configurationError());
        self::assertNull((new NetlifyDeployer($http, 'token', 'x.netlify.app', false))->configurationError());
    }
}
