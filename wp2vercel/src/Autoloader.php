<?php

declare(strict_types=1);

namespace WP2Vercel;

final class Autoloader
{
    private const PREFIX = 'WP2Vercel\\';

    public static function register(string $sourceDirectory): void
    {
        spl_autoload_register(static function (string $class) use ($sourceDirectory): void {
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen(self::PREFIX)));
            $file = $sourceDirectory . '/' . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
