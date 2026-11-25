<?php

declare(strict_types=1);

namespace WP2Vercel\Admin;

use Throwable;
use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\BuildRunner;
use WP2Vercel\Crawl\Queue;
use WP2Vercel\Secrets;
use WP2Vercel\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
    public const NAMESPACE = 'wp2vercel/v1';
    public const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly BuildRunner $runner,
        private readonly Settings $settings,
        private readonly Secrets $secrets,
    ) {
    }

    public function register(): void
    {
        $routes = [
            ['/status', WP_REST_Server::READABLE, 'status'],
            ['/tick', WP_REST_Server::CREATABLE, 'tick'],
            ['/builds', WP_REST_Server::CREATABLE, 'startBuild'],
            ['/builds/cancel', WP_REST_Server::CREATABLE, 'cancelBuild'],
            ['/settings', WP_REST_Server::READABLE, 'readSettings'],
            ['/settings', WP_REST_Server::CREATABLE, 'writeSettings'],
            ['/test-connection', WP_REST_Server::CREATABLE, 'testConnection'],
        ];
        foreach ($routes as [$path, $methods, $handler]) {
            register_rest_route(self::NAMESPACE, $path, [
                'methods' => $methods,
                'callback' => [$this, $handler],
                'permission_callback' => static fn(): bool => current_user_can(self::CAPABILITY),
            ]);
        }
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->snapshot((int) $request->get_param('after_log')));
    }

    public function tick(WP_REST_Request $request): WP_REST_Response
    {
        $this->runner->tick(15.0);
        return new WP_REST_Response($this->snapshot((int) $request->get_param('after_log')));
    }

    public function startBuild(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $type = $request->get_param('type') === Build::TYPE_SINGLE ? Build::TYPE_SINGLE : Build::TYPE_FULL;
            $this->runner->start($type, [
                'deploy' => $request->get_param('deploy') !== false,
                'post_ids' => (array) ($request->get_param('post_ids') ?? []),
            ]);
        } catch (BuildFailed $error) {
            return new WP_Error('wp2vercel_busy', $error->getMessage(), ['status' => 409]);
        }
        return new WP_REST_Response($this->snapshot(0), 201);
    }

    public function cancelBuild(): WP_REST_Response
    {
        $this->runner->cancel();
        return new WP_REST_Response($this->snapshot(0));
    }

    public function readSettings(): WP_REST_Response
    {
        return new WP_REST_Response($this->settingsPayload());
    }

    public function writeSettings(WP_REST_Request $request): WP_REST_Response
    {
        $input = (array) $request->get_json_params();
        $this->settings->update($input);
        foreach (Secrets::NAMES as $name) {
            if (!array_key_exists($name, $input) || !is_string($input[$name])) {
                continue;
            }
            if ($input[$name] !== '' || !empty($input['clear_' . $name])) {
                $this->secrets->set($name, $input[$name]);
            }
        }
        return new WP_REST_Response($this->settingsPayload());
    }

    public function testConnection(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $target = (string) ($request->get_param('target') ?: $this->settings->string('deploy_target'));
        $deployer = $this->runner->environment()->deployer($target);
        if ($deployer === null) {
            return new WP_Error('wp2vercel_no_target', __('Choose GitHub or Vercel as the publish target first.', 'wp2vercel'), ['status' => 400]);
        }
        if (($problem = $deployer->configurationError()) !== null) {
            return new WP_Error('wp2vercel_incomplete', $problem, ['status' => 400]);
        }
        try {
            return new WP_REST_Response(['message' => $deployer->testConnection()]);
        } catch (Throwable $error) {
            return new WP_Error('wp2vercel_connection', $error->getMessage(), ['status' => 400]);
        }
    }

    private function snapshot(int $afterLog): array
    {
        $builds = $this->runner->builds()->latest(10);
        $latest = $builds[0] ?? null;
        $queue = new Queue();
        return [
            'active' => $latest !== null && $latest->isActive(),
            'build' => $latest?->toArray(),
            'counts' => $latest === null ? null : $queue->counts($latest->id),
            'failed' => $latest === null ? [] : $queue->listByStatus($latest->id, Queue::FAILED, 50),
            'log' => $latest === null ? [] : $this->runner->logger()->tail($latest->id, $afterLog),
            'history' => array_map(static fn(Build $build): array => $build->toArray(), $builds),
        ];
    }

    private function settingsPayload(): array
    {
        $payload = $this->settings->all();
        foreach (Secrets::NAMES as $name) {
            $payload['has_' . $name] = $this->secrets->has($name);
            $payload['external_' . $name] = $this->secrets->isManagedExternally($name);
        }
        $payload['origin_url'] = home_url('/');
        return $payload;
    }
}
