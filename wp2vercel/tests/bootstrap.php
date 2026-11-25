<?php

declare(strict_types=1);

$core = getenv('WP_CORE_DIR') ?: dirname(__DIR__, 2) . '/.tools/wordpress-core/wordpress';
$includes = $core . '/wp-includes';

if (!is_file($includes . '/html-api/class-wp-html-tag-processor.php')) {
    fwrite(STDERR, "WordPress core not found. Set WP_CORE_DIR to a WordPress checkout.\n");
    exit(1);
}

define('ABSPATH', $core . '/');
define('WPINC', 'wp-includes');
define('WP2VERCEL_VERSION', 'test');

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return $number === 1 ? $single : $plural;
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    throw new LogicException($function . ': ' . $message);
}

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($value, $flags, $depth);
}

function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
{
    return $value;
}

function do_action(string $hook, mixed ...$arguments): void
{
}

require_once $includes . '/class-wp-token-map.php';
require_once $includes . '/html-api/html5-named-character-references.php';
require_once $includes . '/html-api/class-wp-html-attribute-token.php';
require_once $includes . '/html-api/class-wp-html-span.php';
require_once $includes . '/html-api/class-wp-html-text-replacement.php';
require_once $includes . '/html-api/class-wp-html-decoder.php';
require_once $includes . '/html-api/class-wp-html-tag-processor.php';

require_once dirname(__DIR__) . '/src/Autoloader.php';

WP2Vercel\Autoloader::register(dirname(__DIR__) . '/src');

spl_autoload_register(static function (string $class): void {
    $prefix = 'WP2Vercel\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
