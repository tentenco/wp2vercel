<?php
/**
 * Plugin Name:       WP2Vercel
 * Plugin URI:        https://wp2vercel.com/
 * Description:       Keep WordPress private. Publish the public site as static files to your own Git repository and Vercel account.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Tenten
 * Author URI:        https://tenten.co/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp2vercel
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP2VERCEL_VERSION', '0.1.0');
define('WP2VERCEL_FILE', __FILE__);
define('WP2VERCEL_DIR', __DIR__);

require_once __DIR__ . '/src/Autoloader.php';

WP2Vercel\Autoloader::register(__DIR__ . '/src');

register_activation_hook(__FILE__, [WP2Vercel\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [WP2Vercel\Plugin::class, 'deactivate']);

add_action('plugins_loaded', [WP2Vercel\Plugin::class, 'boot']);
