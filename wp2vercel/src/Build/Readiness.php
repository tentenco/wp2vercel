<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use WP2Vercel\Crawl\FetchResult;
use WP2Vercel\Crawl\Queue;
use WP2Vercel\Settings;

final class Readiness
{
    public const OK = 'ok';
    public const TODO = 'todo';
    public const NOTE = 'note';

    public static function items(
        bool $prettyPermalinks,
        string $homeAddress,
        string $homeProblem,
        string $productionUrl,
        string $target,
        ?string $targetProblem,
        string $unwritableFolder = '',
    ): array {
        return array_values(array_filter([
            self::permalinks($prettyPermalinks),
            $homeProblem === ''
                ? self::item('reach', self::OK, sprintf(__('WordPress can load its own home page from %s.', 'wp2vercel'), $homeAddress))
                : self::item('reach', self::TODO, $homeProblem),
            $unwritableFolder === '' ? null : self::item('storage', self::TODO, sprintf(
                __('WordPress cannot write to %s, where the published files are kept. Make the web server user the owner of that folder, or ask your host to.', 'wp2vercel'),
                $unwritableFolder
            )),
            self::address($productionUrl),
            self::target($target, $targetProblem),
        ]));
    }

    public static function fetched(string $homeAddress, FetchResult $home): string
    {
        if ($home->isRedirect()) {
            return self::redirects($homeAddress, $home->location);
        }
        if ($home->status === 0) {
            return self::unreachable($homeAddress, $home->error);
        }
        if ($home->status !== 200) {
            return self::answered($homeAddress, $home->status);
        }
        return trim($home->body) === ''
            ? sprintf(__('The home page at %s came back empty. Open the site in a browser to see what is wrong.', 'wp2vercel'), $homeAddress)
            : '';
    }

    public static function queued(string $homeAddress, ?array $row): string
    {
        $note = (string) ($row['note'] ?? '');
        if (($row['status'] ?? '') === Queue::REDIRECT) {
            return self::redirects($homeAddress, $note);
        }
        $code = preg_match('~^HTTP (\d{3})$~', $note, $match) === 1 ? (int) $match[1] : (int) ($row['http_status'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            return self::answered($homeAddress, $code);
        }
        if ($note === '') {
            return sprintf(
                __('The home page could not be saved. Check that WordPress can load its own pages from %1$s. %2$s', 'wp2vercel'),
                $homeAddress,
                self::fetchAddressAdvice()
            );
        }
        return $code === 0
            ? self::unreachable($homeAddress, $note)
            : sprintf(__('The home page at %1$s could not be saved (%2$s).', 'wp2vercel'), $homeAddress, $note);
    }

    private static function unreachable(string $homeAddress, string $reason): string
    {
        return sprintf(
            __('WordPress could not load its own home page from %1$s%2$s. %3$s', 'wp2vercel'),
            $homeAddress,
            $reason === '' ? '' : ' (' . $reason . ')',
            self::fetchAddressAdvice()
        );
    }

    private static function redirects(string $homeAddress, string $destination): string
    {
        return sprintf(
            __('The home page at %1$s redirects to %2$s, so there is no home page to save. Check the Fetch address under Settings → How WordPress fetches itself, and any plugin that redirects visitors.', 'wp2vercel'),
            $homeAddress,
            $destination
        );
    }

    private static function answered(string $homeAddress, int $code): string
    {
        if ($code === 401 || $code === 403) {
            return sprintf(
                __('The home page at %1$s answered HTTP %2$d, so WordPress is not allowed to read it. If the site is password protected, enter the Basic auth user and password under Settings → How WordPress fetches itself.', 'wp2vercel'),
                $homeAddress,
                $code
            );
        }
        return sprintf(
            __('The home page at %1$s answered HTTP %2$d instead of a page. Open the site in a browser to see what is wrong.', 'wp2vercel'),
            $homeAddress,
            $code
        );
    }

    private static function fetchAddressAdvice(): string
    {
        return __('If this server cannot reach that address, enter one it can reach as Fetch address under Settings → How WordPress fetches itself.', 'wp2vercel');
    }

    private static function permalinks(bool $pretty): array
    {
        return $pretty
            ? self::item('permalinks', self::OK, __('Permalinks use a structure, so every page has its own address.', 'wp2vercel'))
            : self::item('permalinks', self::TODO, __('Plain permalinks cannot be exported. Choose a permalink structure under Settings → Permalinks.', 'wp2vercel'));
    }

    private static function address(string $productionUrl): array
    {
        return $productionUrl !== ''
            ? self::item('address', self::OK, sprintf(__('Public site address: %s', 'wp2vercel'), $productionUrl))
            : self::item('address', self::NOTE, __('No public site address yet. Until you enter one on the Settings tab, canonical tags, social tags and sitemaps are written without a domain.', 'wp2vercel'));
    }

    private static function target(string $target, ?string $problem): array
    {
        $name = match ($target) {
            Settings::TARGET_GITHUB => 'GitHub',
            Settings::TARGET_VERCEL => 'Vercel',
            Settings::TARGET_NETLIFY => 'Netlify',
            default => '',
        };
        if ($name === '') {
            return self::item('target', self::NOTE, __('Publish target: this server only. The files are saved here and nothing is deployed until you choose GitHub, Vercel or Netlify on the Settings tab.', 'wp2vercel'));
        }
        return $problem === null
            ? self::item('target', self::OK, sprintf(__('Publish target: %s. Save and test connection on the Settings tab checks the token.', 'wp2vercel'), $name))
            : self::item('target', self::TODO, sprintf(__('Publish target: %1$s. %2$s', 'wp2vercel'), $name, $problem));
    }

    private static function item(string $id, string $state, string $text): array
    {
        return ['id' => $id, 'state' => $state, 'text' => $text];
    }
}
