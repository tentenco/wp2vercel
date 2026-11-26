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
use WP2Vercel\Output\StorageFailed;
use WP2Vercel\Secrets;
use WP2Vercel\Settings;

final class BuildRunner
{
    public const CRON_HOOK = 'wp2vercel_tick';
    public const COPY_BEHIND_OPTION = 'wp2vercel_copy_behind';

    private const WEB_BUDGET_SECONDS = 20.0;
    private const LOCK_SECONDS = 120;
    private const KEPT_BUILDS = 20;
    private const ATTEMPTS_OPTION = 'wp2vercel_unfinished_runs';
    private const MAX_UNFINISHED_RUNS = 5;

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
        $type = in_array($type, Build::TYPES, true) ? $type : Build::TYPE_FULL;
        $copyBehind = $type === Build::TYPE_SINGLE && get_option(self::COPY_BEHIND_OPTION, false) !== false;
        $build = $this->builds->create($copyBehind ? Build::TYPE_FULL : $type, [
            'deploy' => (bool) ($options['deploy'] ?? true),
            'post_ids' => array_values(array_map('intval', (array) ($options['post_ids'] ?? []))),
            'urls' => array_values(array_map('strval', (array) ($options['urls'] ?? []))),
            'remove_urls' => array_values(array_map('strval', (array) ($options['remove_urls'] ?? []))),
            'follow_pagination' => (bool) ($options['follow_pagination'] ?? false),
            'restore_from' => (string) ($options['restore_from'] ?? ''),
            'label' => (string) ($options['label'] ?? ''),
            'started_by' => get_current_user_id(),
        ]);
        $steps = array_values(array_filter(
            (array) apply_filters('wp2vercel_build_steps', $build->defaultSteps(), $build),
            static fn($step): bool => is_string($step) && $step !== ''
        ));
        if ($steps !== [] && $steps[0] === 'setup' && $steps !== $build->defaultSteps()) {
            $build->state['steps'] = $steps;
            $this->builds->save($build);
        }
        if ($copyBehind) {
            $this->logger->warning($build->id, __('The last swap of the copy on this server failed, so this publish runs in full instead of only the changed pages.', 'wp2vercel'));
        }
        do_action('wp2vercel_build_started', $build);
        $this->continueInBackground();
        return $build;
    }

    public function runningPublish(): ?Build
    {
        return self::holdsSettings($this->builds->active());
    }

    public static function holdsSettings(?Build $active): ?Build
    {
        return $active !== null && $active->isActive() && !$active->isWaiting() ? $active : null;
    }

    public static function settingsWaitMessage(int $buildId): string
    {
        return sprintf(
            __('Publish #%d is running, so the settings were not saved: a change now would apply to only part of that publish. Save again when it has ended, or cancel it first.', 'wp2vercel'),
            $buildId
        );
    }

    public function cancel(string $message = ''): ?Build
    {
        $build = $this->builds->active();
        if ($build === null) {
            return null;
        }
        unset($build->state['waiting']);
        $this->finish($build, Build::STATUS_CANCELLED, $message !== '' ? $message : __('Cancelled.', 'wp2vercel'));
        $this->discardWhenNoWorkerRuns($build);
        return $this->builds->find($build->id) ?? $build;
    }

    private function discardWhenNoWorkerRuns(Build $build): void
    {
        if (!$build->usesStaging()) {
            return;
        }
        $lock = new Lock();
        if (!$lock->acquire(self::LOCK_SECONDS)) {
            if ($this->background) {
                wp_schedule_single_event(time() + self::LOCK_SECONDS + 10, self::CRON_HOOK);
            }
            return;
        }
        try {
            $this->discardUnfinishedFiles($build);
        } finally {
            $lock->release();
        }
    }

    public function resume(array $stateChanges = [], bool $background = true): ?Build
    {
        $this->background = $background;
        $build = $this->builds->active();
        if ($build === null || !$build->isWaiting()) {
            return null;
        }
        unset($build->state['waiting']);
        $build->state = array_merge($build->state, $stateChanges);
        $build->status = Build::STATUS_RUNNING;
        $this->builds->save($build);
        $this->continueInBackground();
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
                $this->environment()->directory->discardAllStaging();
                return null;
            }
            if (!$build->isWaiting()) {
                $unfinished = self::unfinishedRuns(get_option(self::ATTEMPTS_OPTION), $build->id, $build->step);
                if ($unfinished >= self::MAX_UNFINISHED_RUNS) {
                    delete_option(self::ATTEMPTS_OPTION);
                    $this->finish($build, Build::STATUS_FAILED, sprintf(
                        __('The publish was stopped: the server ended it %1$d times in a row at the step "%2$s", most likely at its PHP time or memory limit. The PHP error log of the server has the reason.', 'wp2vercel'),
                        $unfinished,
                        $build->step
                    ));
                    return $build;
                }
                update_option(self::ATTEMPTS_OPTION, ['build' => $build->id, 'step' => $build->step, 'count' => $unfinished + 1], false);
                $this->scheduleRetry();
                $this->work($build, new Deadline($budgetSeconds, fn (): bool => $this->builds->status($build->id) === Build::STATUS_CANCELLED));
                delete_option(self::ATTEMPTS_OPTION);
            }
            return $build;
        } finally {
            $lock->release();
            $active = $this->builds->active();
            if ($active !== null && !$active->isWaiting()) {
                $this->continueInBackground();
            }
        }
    }

    public static function unfinishedRuns(mixed $stored, int $buildId, string $step): int
    {
        return is_array($stored) && (int) ($stored['build'] ?? 0) === $buildId && ($stored['step'] ?? '') === $step
            ? max(0, (int) ($stored['count'] ?? 0))
            : 0;
    }

    public function runToCompletion(int $buildId, float $tickSeconds = 30.0): Build
    {
        $this->background = false;
        do {
            $build = $this->tick($tickSeconds);
            $current = $this->builds->find($buildId);
            if ($current === null || !$current->isActive() || $current->isWaiting()) {
                break;
            }
            if ($build === null || $build->id !== $buildId || !empty($current->state['deployment']['id']) || !empty($current->state['deployment']['wait_until'])) {
                usleep(1500000);
            }
        } while (true);
        return $this->builds->find($buildId) ?? throw new BuildFailed('Build disappeared.');
    }

    private function scheduleRetry(): void
    {
        if ($this->background && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + self::LOCK_SECONDS + 10, self::CRON_HOOK);
        }
    }

    private function continueInBackground(): void
    {
        if (!$this->background) {
            return;
        }
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next === false || $next > time() + 60) {
            if ($next !== false) {
                wp_unschedule_event($next, self::CRON_HOOK);
            }
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
                if ($this->stoppedByCancel($build)) {
                    return;
                }
                if (!$finished) {
                    if (!empty($build->state['waiting'])) {
                        $build->status = Build::STATUS_WAITING;
                        $this->builds->save($build);
                        wp_clear_scheduled_hook(self::CRON_HOOK);
                        do_action('wp2vercel_build_waiting', $build);
                        return;
                    }
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
            $this->stoppedByCancel($build);
        } catch (BuildFailed $error) {
            $this->finish($build, Build::STATUS_FAILED, $error->getMessage());
        } catch (StorageFailed $error) {
            $this->finish($build, Build::STATUS_FAILED, $error->getMessage());
        } catch (Throwable $error) {
            $this->finish($build, Build::STATUS_FAILED, sprintf('%s (%s:%d)', $error->getMessage(), basename($error->getFile()), $error->getLine()));
        }
    }

    private function stoppedByCancel(Build $build): bool
    {
        if ($this->builds->status($build->id) !== Build::STATUS_CANCELLED) {
            return false;
        }
        $build->status = Build::STATUS_CANCELLED;
        if ($build->usesStaging()) {
            $this->discardUnfinishedFiles($build);
        }
        return true;
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
            default => $this->extensionStep($name, $environment),
        };
    }

    private function extensionStep(string $name, Environment $environment): Step
    {
        $step = apply_filters('wp2vercel_step', null, $name, $environment);
        if (!$step instanceof Step) {
            throw new BuildFailed('Unknown build step: ' . $name);
        }
        return $step;
    }

    private function finish(Build $build, string $status, string $message): void
    {
        $build->status = $status;
        $build->message = $message;
        $build->finishedAt = current_time('mysql', true);
        unset($build->state['render_token']);
        if (!$this->builds->save($build)) {
            if ($status !== Build::STATUS_CANCELLED) {
                $this->stoppedByCancel($build);
            }
            return;
        }
        if ($status === Build::STATUS_FAILED && $build->usesStaging()) {
            $this->discardUnfinishedFiles($build);
        }
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

    private function discardUnfinishedFiles(Build $build): void
    {
        try {
            $this->environment()->directory->discardStaging($build->id);
        } catch (Throwable $error) {
            $this->logger->warning($build->id, sprintf('The unfinished files of this publish could not be removed: %s', $error->getMessage()));
        }
    }

    public function exportFolder(): string
    {
        $directory = $this->environment()->directory;
        return $directory->site($directory->root(0, false));
    }

    private function summary(Build $build): string
    {
        $files = (int) ($build->state['manifest']['count'] ?? 0);
        $leaks = (int) ($build->state['scan']['total'] ?? 0);
        $where = match ($build->state['target'] ?? Settings::TARGET_NONE) {
            Settings::TARGET_VERCEL => 'deployed to Vercel at ' . ($build->state['deployment']['url'] ?? ''),
            Settings::TARGET_GITHUB => 'pushed to GitHub branch ' . ($build->state['deployment']['branch'] ?? ''),
            Settings::TARGET_NETLIFY => 'deployed to Netlify at ' . ($build->state['deployment']['url'] ?? ''),
            default => 'saved on this server in ' . $this->exportFolder(),
        };
        return sprintf(
            '%s %d files, %s. Leak scan: %s.',
            $build->isRestore() ? 'Restored' : 'Published',
            $files,
            $where,
            $leaks === 0 ? 'clean' : ($leaks === 1 ? '1 finding' : $leaks . ' findings')
        );
    }
}
