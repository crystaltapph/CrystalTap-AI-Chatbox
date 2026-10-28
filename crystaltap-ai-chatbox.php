<?php
/**
 * Plugin Name: CrystalTap AI Chatbox
 * Description: Standalone customer chat, approved knowledge, human support and CrystalTap Agent Android app.
 * Version: 0.2.1
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: CrystalTap
 * Text Domain: crystaltap-ai-chatbox
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('CTAI_VERSION', '0.2.1');
define('CTAI_FILE', __FILE__);
define('CTAI_DIR', __DIR__ . '/');
if (is_file(CTAI_DIR . 'vendor/autoload.php')) {
    require_once CTAI_DIR . 'vendor/autoload.php';
}
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'CrystalTap\\')) {
        $path = CTAI_DIR . 'includes/' . substr($class, 11) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
register_activation_hook(__FILE__, [CrystalTap\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [CrystalTap\Plugin::class, 'deactivate']);
add_action('plugins_loaded', [CrystalTap\Plugin::class, 'boot']);
