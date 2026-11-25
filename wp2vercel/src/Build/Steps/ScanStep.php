<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Scan\LeakScanner;

final class ScanStep implements Step
{
    private const STORED_FINDINGS = 100;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        $environment = $this->environment;
        $root = $environment->root($build);
        $site = $environment->directory->site($root);
        $files = $environment->directory->files($root);
        $scan = $build->state['scan'] ?? ['cursor' => 0, 'total' => 0, 'findings' => []];
        $scanner = new LeakScanner($environment->privateOrigins(), 200, (array) apply_filters('wp2vercel_scan_rules', [], $build));

        while ($scan['cursor'] < count($files) && !$deadline->passed()) {
            $file = $files[$scan['cursor']];
            foreach ($scanner->scanFile($file, $site . '/' . $file) as $finding) {
                $reported = apply_filters('wp2vercel_scan_finding', $finding->toArray(), $build);
                if (!is_array($reported)) {
                    continue;
                }
                $scan['total']++;
                if (count($scan['findings']) < self::STORED_FINDINGS) {
                    $scan['findings'][] = $reported;
                }
            }
            $scan['cursor']++;
        }
        $build->state['scan'] = $scan;

        if ($scan['cursor'] < count($files)) {
            return false;
        }

        if ($scan['total'] === 0) {
            $environment->logger->info($build->id, sprintf('Leak scan passed: %d files, no reference to the private origin.', count($files)));
        } else {
            $environment->logger->warning($build->id, sprintf(
                'Leak scan found %d %s. First: %s in %s (line %d).',
                $scan['total'],
                $scan['total'] === 1 ? 'issue' : 'issues',
                $scan['findings'][0]['type'],
                $scan['findings'][0]['file'],
                $scan['findings'][0]['line']
            ));
            if (apply_filters('wp2vercel_block_on_findings', false, $build, $scan)) {
                $build->state['scan']['blocked'] = true;
                throw new BuildFailed(sprintf(
                    _n(
                        'Publishing stopped: the leak scan reported %d issue. Nothing was deployed.',
                        'Publishing stopped: the leak scan reported %d issues. Nothing was deployed.',
                        $scan['total'],
                        'wp2vercel'
                    ),
                    $scan['total']
                ));
            }
        }
        return true;
    }
}
