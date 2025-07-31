<?php
/**
 * Plugin Name: TilesView WooCommerce Sync - Debug Edition
 * Description: Sync WooCommerce products, categories, and filters with TilesView API. Full logging and error handling.
 * Version: 1.2.1
 * Author: Your Name
 * Text Domain: tilesview-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Enable WP debug log
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Plugin constants
define('TILESVIEW_DEBUG', true);
define('TILESVIEW_LOG_PREFIX', '[TilesView Sync] ');

// Include main class
require_once plugin_dir_path(__FILE__) . 'includes/class-tilesview-sync.php';

// Initialize plugin
add_action('plugins_loaded', function() {
    new TilesView_WooCommerce_Sync();
});

// Global helper for logging
function tilesview_log($message, $data = null) {
    if (!TILESVIEW_DEBUG) {
        return;
    }
    $log = TILESVIEW_LOG_PREFIX . $message;
    if ($data !== null) {
        $log .= ' | ' . print_r($data, true);
    }
    error_log($log);
}
