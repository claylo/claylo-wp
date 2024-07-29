<?php

/**
 * Claylo WP Additions
 *
 * @package claylo
 * @author  Clay Loveless
 *
 * @wordpress-plugin
 * Plugin Name: Claylo's WP Additions
 * Plugin URI: https://claylo.com/
 * Description: Adds custom PHP classes and .env.ini support.
 * Version: 0.0.0
 * Author: Clay Loveless
 * Author URI: https://claylo.com/
 * License: MIT
 * Requires at least: 6.0
 * Text Domain: claylo
 * Domain Path: /languages
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;
$meta = get_file_data(__FILE__, array('version' => 'Version'));
define('CLAYLO_VERSION', $meta['version']);
$loader = require_once __DIR__ . '/vendor/autoload.php';
// $loader->add('Claylo\\Wp\\', __DIR__ . '/src');

// .env.ini support
$env_ini_found = false;
$env = [];
if (file_exists(ABSPATH . '.env.ini')) {
    $env_ini_found = true;
    $env = parse_ini_file(ABSPATH . '.env.ini');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

add_action("plugin_row_meta", function ($plugin_meta, $plugin_file, $plugin_data, $status) use ($env_ini_found, $env) {
    if ($plugin_file == plugin_basename(__FILE__) && $env_ini_found) {
        $plugin_meta['env'] = '<strong>.env.ini:</strong> ' . sizeof($env) . ' variables loaded.';
    }
    return $plugin_meta;
}, 10, 4);
