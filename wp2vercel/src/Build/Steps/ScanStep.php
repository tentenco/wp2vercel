<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildFailed;
use WP2Vercel\Build\Deadline;
use WP2Vercel\Build\Environment;
use WP2Vercel\Scan\FormFinder;
use WP2Vercel\Scan\LeakScanner;

final class ScanStep implements Step
{
    private const STORED_FINDINGS = 100;
    private const STORED_FORM_PAGES = 50;
    private const LARGEST_PAGE_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly Environment $environment)
    {
    }

    private function isReadablePage(string $path): bool
    {
        return str_ends_with($path, '.html') && (int) @filesize($path) <= self::LARGEST_PAGE_BYTES;
    }

    public function run(Build $build, Deadline $deadline): bool
    {
        $environment = $this->environment;
        $root = $environment->root($build);
        $site = $environment->directory->site($root);
        $files = $environment->directory->files($root);
        $scan = $build->state['scan'] ?? ['cursor' => 0, 'total' => 0, 'findings' => []];
        $scan += ['form_pages' => [], 'form_pages_total' => 0];
        $scanner = new LeakScanner($environment->privateOrigins(), 200, (array) apply_filters('wp2vercel_scan_rules', [], $build));
        $forms = apply_filters('wp2vercel_report_forms', true, $build) ? new FormFinder($environment->settings->string('production_url')) : null;

        while ($scan['cursor'] < count($files) && !$deadline->passed()) {
            $file = $files[$scan['cursor']];
            if ($forms !== null && $this->isReadablePage($site . '/' . $file) && $forms->postsToTheSite((string) file_get_contents($site . '/' . $file)) > 0) {
                $scan['form_pages_total']++;
                if (count($scan['form_pages']) < self::STORED_FORM_PAGES) {
                    $scan['form_pages'][] = FormFinder::pageAddress($file);
                }
            }
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

        if ($scan['form_pages_total'] > 0) {
            $environment->logger->warning($build->id, sprintf(
                _n(
                    '%1$d page has a form that sends its entries to WordPress. On the published site that form does not send anything. First: %2$s',
                    '%1$d pages have a form that sends its entries to WordPress. On the published site those forms do not send anything. First: %2$s',
                    $scan['form_pages_total'],
                    'wp2vercel'
                ),
                $scan['form_pages_total'],
                $scan['form_pages'][0]
            ));
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
