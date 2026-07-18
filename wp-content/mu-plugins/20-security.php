<?php

add_filter('xmlrpc_enabled', '__return_false');
add_filter('pre_option_enable_xmlrpc', '__return_false');
add_filter('xmlrpc_methods', '__return_empty_array');

if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

add_filter('allow_dev_auto_core_updates', '__return_false');
add_filter('allow_minor_auto_core_updates', '__return_false');
add_filter('allow_major_auto_core_updates', '__return_false');
add_filter('auto_update_plugin', '__return_false');
add_filter('auto_update_theme', '__return_false');
add_filter('auto_update_translation', '__return_false');

remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');
