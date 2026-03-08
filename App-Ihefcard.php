<?php

/**
 * Plugin Name: Content Aplikasi
 * Description: OOP Plugin untuk Event, HF Clinic, News, Recording + REST API
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

define('CA_PATH', plugin_dir_path(__FILE__));
define('CA_URL', plugin_dir_url(__FILE__));

require_once CA_PATH . 'includes/class-plugin.php';

function run_content_aplikasi()
{
    \ContentAplikasi\Plugin::instance();
}
run_content_aplikasi();
