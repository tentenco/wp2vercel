<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

final class Build
{
    public const TYPE_FULL = 'full';
    public const TYPE_SINGLE = 'single';
    public const TYPE_RESTORE = 'restore';

    public const TYPES = [self::TYPE_FULL, self::TYPE_SINGLE, self::TYPE_RESTORE];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STEPS = ['setup', 'seed', 'crawl', 'finalize', 'scan', 'deploy'];

    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public string $step,
        public array $state,
        public string $message,
        public string $createdAt,
        public string $updatedAt,
        public ?string $finishedAt,
    ) {
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_WAITING], true);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function defaultSteps(): array
    {
        return $this->isRestore() ? ['setup', 'scan', 'deploy'] : self::STEPS;
    }

    public function isFull(): bool
    {
        return $this->type === self::TYPE_FULL;
    }

    public function isRestore(): bool
    {
        return $this->type === self::TYPE_RESTORE;
    }

    public function usesStaging(): bool
    {
        return $this->type !== self::TYPE_SINGLE;
    }

    public function steps(): array
    {
        $stored = $this->state['steps'] ?? null;
        return is_array($stored) && $stored !== [] ? array_values(array_map('strval', $stored)) : $this->defaultSteps();
    }

    public function nextStep(): ?string
    {
        $steps = $this->steps();
        $index = array_search($this->step, $steps, true);
        return $index === false ? null : ($steps[$index + 1] ?? null);
    }

    public function idleSeconds(int $now): int
    {
        if (!$this->isActive() || $this->isWaiting() || (int) ($this->state['deployment']['wait_until'] ?? 0) > $now) {
            return 0;
        }
        $updated = strtotime($this->updatedAt . ' UTC');
        return $updated === false ? 0 : max(0, $now - $updated);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'step' => $this->step,
            'message' => $this->message,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'finished_at' => $this->finishedAt,
            'deployment' => $this->state['deployment'] ?? null,
            'findings' => $this->state['scan']['findings'] ?? [],
            'findings_total' => (int) ($this->state['scan']['total'] ?? 0),
            'form_pages' => array_values((array) ($this->state['scan']['form_pages'] ?? [])),
            'form_pages_total' => (int) ($this->state['scan']['form_pages_total'] ?? 0),
            'files' => (int) ($this->state['manifest']['count'] ?? 0),
            'target' => (string) ($this->state['target'] ?? 'none'),
            'steps' => $this->steps(),
            'blocked' => !empty($this->state['scan']['blocked']),
            'waiting' => $this->isWaiting() ? (string) ($this->state['waiting']['reason'] ?? 'paused') : '',
            'label' => (string) ($this->state['label'] ?? ''),
            'started_by' => (int) ($this->state['started_by'] ?? 0),
        ];
    }
}
