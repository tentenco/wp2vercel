<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use Throwable;
use WP2Vercel\Build\Steps\CrawlStep;
use WP2Vercel\Build\Steps\DeployStep;
use WP2Vercel\Build\Steps\FinalizeStep;
use WP2Vercel\Build\Steps\ScanStep;
use WP2Vercel\Build\Steps\SeedStep;
use WP2Vercel\Build\Steps\SetupStep;
use WP2Vercel\Build\Steps\Step;
use WP2Vercel\Crawl\RenderContext;
use WP2Vercel\Secrets;
use WP2Vercel\Settings;

final class BuildRunner
{
    public const CRON_HOOK = 'wp2vercel_tick';

    private const WEB_BUDGET_SECONDS = 20.0;
    private const LOCK_SECONDS = 120;
    private const KEPT_BUILDS = 20;

    private bool $background = true;

    public function __construct(
        private readonly Settings $settings,
        private readonly Secrets $secrets,
        private readonly BuildRepository $builds,
        private readonly BuildLogger $logger,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    public function logger(): BuildLogger
    {
        return $this->logger;
    }

    public function builds(): BuildRepository
    {
        return $this->builds;
    }

    public function environment(): Environment
    {
        return new Environment($this->settings, $this->secrets, $this->logger);
    }

    public function start(string $type, array $options = [], bool $background = true): Build
    {
        $this->background = $background;
        $active = $this->builds->active();
        if ($active !== null) {
            throw new BuildFailed(sprintf(__('Publish #%d is still running.', 'wp2vercel'), $active->id));
        }
        $build = $this->builds->create(in_array($type, Build::TYPES, true) ? $type : Build::TYPE_FULL, [
            'deploy' => (bool) ($options['deploy'] ?? true),
            'post_ids' => array_values(array_map('intval', (array) ($options['post_ids'] ?? []))),
            'urls' => array_values(array_map('strval', (array) ($options['urls'] ?? []))),
            'remove_urls' => array_values(array_map('strval', (array) ($options['remove_urls'] ?? []))),
            'follow_pagination' => (bool) ($options['follow_pagination'] ?? false),
            'restore_from' => (string) ($options['restore_from'] ?? ''),
            'label' => (string) ($options['label'] ?? ''),
            'started_by' => get_current_user_id(),
        ]);
        do_action('wp2vercel_build_started', $build);
        $this->continueInBackground();
        return $build;
    }

    public function cancel(): ?Build
    {
        $build = $this->builds->active();
        if ($build === null) {
            return null;
        }
        $this->finish($build, Build::STATUS_CANCELLED, __('Cancelled.', 'wp2vercel'));
        return $build;
    }

    public function tick(float $budgetSeconds = self::WEB_BUDGET_SECONDS): ?Build
    {
        $lock = new Lock();
        if (!$lock->acquire(self::LOCK_SECONDS)) {
            return $this->builds->active();
        }

        try {
            $build = $this->builds->active();
            if ($build === null) {
                return null;
            }
            $this->work($build, new Deadline($budgetSeconds));
            return $build;
        } finally {
            $lock->release();
            if ($this->builds->active() !== null) {
                $this->continueInBackground();
            }
        }
    }

    public function runToCompletion(int $buildId, float $tickSeconds = 30.0): Build
    {
        $this->background = false;
        do {
            $build = $this->tick($tickSeconds);
            $current = $this->builds->find($buildId);
            if ($current === null || !$current->isActive()) {
                break;
            }
            if ($build === null || $build->id !== $buildId || !empty($current->state['deployment']['id']) || !empty($current->state['deployment']['wait_until'])) {
                usleep(1500000);
            }
        } while (true);
        return $this->builds->find($buildId) ?? throw new BuildFailed('Build disappeared.');
    }

    private function continueInBackground(): void
    {
        if (!$this->background) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK);
        }
        $this->dispatcher->dispatch();
    }

    private function work(Build $build, Deadline $deadline): void
    {
        if ($build->status === Build::STATUS_PENDING) {
            $build->status = Build::STATUS_RUNNING;
            $this->builds->save($build);
        }

        try {
            $environment = $this->environment();
            while (!$deadline->passed()) {
                $finished = $this->step($build->step, $environment)->run($build, $deadline);
                if ($this->builds->status($build->id) === Build::STATUS_CANCELLED) {
                    $build->status = Build::STATUS_CANCELLED;
                    return;
                }
                if (!$finished) {
                    $this->builds->save($build);
                    if ($this->shouldYield($build)) {
                        return;
                    }
                    continue;
                }
                $next = $build->nextStep();
                if ($next === null) {
                    $this->finish($build, Build::STATUS_COMPLETED, $this->summary($build));
                    return;
                }
                $build->step = $next;
                $this->builds->save($build);
            }
        } catch (BuildFailed $error) {
            $this->finish($build, Build::STATUS_FAILED, $error->getMessage());
        } catch (Throwable $error) {
            $this->finish($build, Build::STATUS_FAILED, sprintf('%s (%s:%d)', $error->getMessage(), basename($error->getFile()), $error->getLine()));
        }
    }

    private function shouldYield(Build $build): bool
    {
        if ($build->step === 'deploy') {
            return !empty($build->state['deployment']['id']) || !empty($build->state['deployment']['wait_until']);
        }
        return $build->step === 'crawl';
    }

    private function step(string $name, Environment $environment): Step
    {
        return match ($name) {
            'setup' => new SetupStep($environment),
            'seed' => new SeedStep($environment),
            'crawl' => new CrawlStep($environment),
            'finalize' => new FinalizeStep($environment),
            'scan' => new ScanStep($environment),
            'deploy' => new DeployStep($environment),
            default => throw new BuildFailed('Unknown build step: ' . $name),
        };
    }

    private function finish(Build $build, string $status, string $message): void
    {
        $build->status = $status;
        $build->message = $message;
        $build->finishedAt = current_time('mysql', true);
        unset($build->state['render_token']);
        $this->builds->save($build);
        RenderContext::revokeToken();
        Dispatcher::revoke();
        wp_clear_scheduled_hook(self::CRON_HOOK);

        if ($status === Build::STATUS_COMPLETED) {
            $this->logger->info($build->id, $message);
        } else {
            $this->logger->error($build->id, $message);
        }
        $this->builds->prune(self::KEPT_BUILDS);
        do_action('wp2vercel_build_finished', $build->id, $status);
    }

    private function summary(Build $build): string
    {
        $files = (int) ($build->state['manifest']['count'] ?? 0);
        $leaks = (int) ($build->state['scan']['total'] ?? 0);
        $where = match ($build->state['target'] ?? Settings::TARGET_NONE) {
            Settings::TARGET_VERCEL => 'deployed to Vercel at ' . ($build->state['deployment']['url'] ?? ''),
            Settings::TARGET_GITHUB => 'pushed to GitHub branch ' . ($build->state['deployment']['branch'] ?? ''),
            default => 'saved on this server',
        };
        return sprintf(
            '%s %d files, %s. Leak scan: %s.',
            $build->isRestore() ? 'Restored' : 'Published',
            $files,
            $where,
            $leaks === 0 ? 'clean' : $leaks . ' findings'
        );
    }
}
