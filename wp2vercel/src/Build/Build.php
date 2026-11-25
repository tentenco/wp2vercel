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
        return $this->status === self::STATUS_PENDING || $this->status === self::STATUS_RUNNING;
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
        return $this->isRestore() ? ['setup', 'scan', 'deploy'] : self::STEPS;
    }

    public function nextStep(): ?string
    {
        $steps = $this->steps();
        $index = array_search($this->step, $steps, true);
        return $index === false ? null : ($steps[$index + 1] ?? null);
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
            'files' => (int) ($this->state['manifest']['count'] ?? 0),
            'target' => (string) ($this->state['target'] ?? 'none'),
            'steps' => $this->steps(),
            'blocked' => !empty($this->state['scan']['blocked']),
        ];
    }
}
