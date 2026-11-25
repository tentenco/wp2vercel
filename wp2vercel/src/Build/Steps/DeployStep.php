<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Deploy\DeployFailed;
use WP2Vercel\Settings;

final class DeployStep implements Step
{
    public function __construct(private readonly Environment $environment)
    {
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        $environment = $this->environment;
        $manifest = $environment->manifest($build);
        $files = $environment->directory->files($environment->root($build));

        $progress = $build->state['manifest'] ?? ['cursor' => 0, 'count' => 0, 'done' => false];
        if (!$progress['done']) {
            if ($progress['cursor'] === 0) {
                $manifest->reset();
            }
            while ($progress['cursor'] < count($files) && !$deadline->passed()) {
                $manifest->append($files[$progress['cursor']]);
                $progress['cursor']++;
            }
            $progress['count'] = $progress['cursor'];
            $progress['done'] = $progress['cursor'] >= count($files);
            $build->state['manifest'] = $progress;
            if (!$progress['done']) {
                return false;
            }
        }

        $target = (string) ($build->state['target'] ?? Settings::TARGET_NONE);
        $deployer = $environment->deployer($target);
        if ($deployer !== null && empty($build->state['deployment']['done'])) {
            try {
                $state = $deployer->advance(
                    (array) ($build->state['deployment'] ?? []),
                    $manifest,
                    $deadline,
                    fn(string $message) => $environment->logger->info($build->id, $message)
                );
            } catch (DeployFailed $error) {
                throw new BuildFailed($error->getMessage(), 0, $error);
            }
            $build->state['deployment'] = $state;
            if (empty($state['done'])) {
                return false;
            }
        }

        if ($build->usesStaging()) {
            $environment->directory->promote($build->id);
        }
        return true;
    }
}
