<?php

declare(strict_types=1);

namespace WP2Vercel;

final class Secrets
{
    public const OPTION = 'wp2vercel_secrets';
    public const NAMES = ['github_token', 'vercel_token', 'netlify_token', 'fetch_basic_auth_password'];

    private const PREFIX = 'v1:';

    public static function names(): array
    {
        return array_values(array_unique(array_merge(self::NAMES, array_filter(
            (array) apply_filters('wp2vercel_secret_names', []),
            static fn($name): bool => is_string($name) && (bool) preg_match('~^[a-z0-9_]{1,40}$~', $name)
        ))));
    }

    public function get(string $name): string
    {
        $constant = 'WP2VERCEL_' . strtoupper($name);
        if (defined($constant) && is_string(constant($constant)) && constant($constant) !== '') {
            return constant($constant);
        }
        $environment = getenv($constant);
        if (is_string($environment) && $environment !== '') {
            return $environment;
        }
        $stored = $this->stored()[$name] ?? '';
        return is_string($stored) ? $this->decrypt($stored) : '';
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== '';
    }

    public function isManagedExternally(string $name): bool
    {
        $constant = 'WP2VERCEL_' . strtoupper($name);
        return (defined($constant) && constant($constant) !== '') || (string) getenv($constant) !== '';
    }

    public function set(string $name, string $value): void
    {
        if (!in_array($name, self::names(), true)) {
            return;
        }
        $stored = $this->stored();
        if ($value === '') {
            unset($stored[$name]);
        } else {
            $stored[$name] = $this->encrypt($value);
        }
        update_option(self::OPTION, $stored, false);
    }

    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    private function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );
        return $plain === false ? '' : $plain;
    }

    private function key(): string
    {
        $material = defined('WP2VERCEL_KEY') && is_string(WP2VERCEL_KEY) && WP2VERCEL_KEY !== ''
            ? WP2VERCEL_KEY
            : wp_salt('secure_auth');
        return sodium_crypto_generichash('wp2vercel-secrets|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
