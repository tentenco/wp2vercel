<?php

declare(strict_types=1);

namespace WP2Vercel\Redirects;

final class NetlifyConfig
{
    public const REDIRECTS_FILE = '_redirects';
    public const HEADERS_FILE = '_headers';

    private const PATTERN = '~[(*]|(?:^|/):[A-Za-z_]~';
    private const LANGUAGE = '~^([a-z]{2,3})(?:\.\*|-.*)?$~i';

    private array $redirectLines = [];
    private array $headerBlocks = [];
    private array $skipped = [];

    public function __construct(array $vercelConfig)
    {
        foreach ((array) ($vercelConfig['redirects'] ?? []) as $rule) {
            $this->addRedirect(is_array($rule) ? $rule : []);
        }
        foreach ((array) ($vercelConfig['rewrites'] ?? []) as $rule) {
            $this->addRewrite(is_array($rule) ? $rule : []);
        }
        foreach ((array) ($vercelConfig['headers'] ?? []) as $rule) {
            $this->addHeaders(is_array($rule) ? $rule : []);
        }
    }

    public function redirects(): string
    {
        return $this->redirectLines === [] ? '' : implode("\n", $this->redirectLines) . "\n";
    }

    public function headers(): string
    {
        return $this->headerBlocks === [] ? '' : implode("\n", $this->headerBlocks) . "\n";
    }

    public function skipped(): array
    {
        return $this->skipped;
    }

    private function addRedirect(array $rule): void
    {
        $source = self::literalPath((string) ($rule['source'] ?? ''));
        $destination = self::cleanTarget((string) ($rule['destination'] ?? ''));
        $conditions = self::conditions($rule);
        if ($source === null || $destination === '' || $conditions === null) {
            $this->skipped[] = (string) ($rule['source'] ?? '');
            return;
        }
        $status = isset($rule['statusCode']) ? (int) $rule['statusCode'] : (($rule['permanent'] ?? false) === true ? 301 : 302);
        $this->redirectLines[] = trim(sprintf('%s %s %d! %s', $source, $destination, $status, $conditions));
    }

    private function addRewrite(array $rule): void
    {
        $source = self::literalPath((string) ($rule['source'] ?? ''));
        $destination = self::cleanTarget((string) ($rule['destination'] ?? ''));
        if ($source === null || $destination === '' || isset($rule['has']) || isset($rule['missing'])) {
            $this->skipped[] = (string) ($rule['source'] ?? '');
            return;
        }
        $this->redirectLines[] = sprintf('%s %s 200', $source, $destination);
    }

    private function addHeaders(array $rule): void
    {
        $rawSource = (string) ($rule['source'] ?? '');
        $source = $rawSource === '/(.*)' ? '/*' : self::literalPath($rawSource);
        if ($source === null || isset($rule['has']) || isset($rule['missing'])) {
            $this->skipped[] = $rawSource;
            return;
        }
        $lines = [$source];
        foreach ((array) ($rule['headers'] ?? []) as $header) {
            $key = preg_replace('~[^A-Za-z0-9\-]~', '', (string) ($header['key'] ?? '')) ?? '';
            $value = trim(preg_replace('~[\r\n]+~', ' ', (string) ($header['value'] ?? '')) ?? '');
            if ($key !== '' && $value !== '') {
                $lines[] = '  ' . $key . ': ' . $value;
            }
        }
        if (count($lines) > 1) {
            $this->headerBlocks[] = implode("\n", $lines);
        }
    }

    private static function conditions(array $rule): ?string
    {
        $languages = [];
        foreach ((array) ($rule['has'] ?? []) as $condition) {
            $isLanguage = ($condition['type'] ?? '') === 'header' && strtolower((string) ($condition['key'] ?? '')) === 'accept-language';
            if (!$isLanguage || preg_match(self::LANGUAGE, (string) ($condition['value'] ?? ''), $match) !== 1) {
                return null;
            }
            $languages[] = strtolower($match[1]);
        }
        foreach ((array) ($rule['missing'] ?? []) as $condition) {
            if (($condition['type'] ?? '') !== 'header' || strtolower((string) ($condition['key'] ?? '')) !== 'referer') {
                return null;
            }
        }
        return $languages === [] ? '' : 'Language=' . implode(',', array_unique($languages));
    }

    private static function literalPath(string $source): ?string
    {
        if ($source === '' || $source[0] !== '/' || preg_match(self::PATTERN, preg_replace('~\\\\.~', '', $source) ?? '') === 1) {
            return null;
        }
        return self::encodeSpaces(preg_replace('~\\\\(.)~', '$1', $source) ?? $source);
    }

    private static function cleanTarget(string $destination): string
    {
        $destination = trim($destination);
        return preg_match('~[\r\n]~', $destination) === 1 ? '' : self::encodeSpaces($destination);
    }

    private static function encodeSpaces(string $value): string
    {
        return str_replace([' ', "\t"], ['%20', '%09'], $value);
    }
}
