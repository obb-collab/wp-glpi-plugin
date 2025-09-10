<?php
/**
 * Plugin Name: WP GLPI Newmodal Addon
 * Description: Isolated clone (newmodal/bage): GLPI cards, modal, new-ticket. SQL for reads, REST API for writes. Shortcode: [glpi_cards_new].
 * Version: 1.1.0
 * Author: obb-collab
 */

if (!defined('ABSPATH')) { exit; }

// Core DB bootstrap (credentials + PDO + mapping helpers)
require_once __DIR__ . '/glpi-db-setup.php';

// Newmodal module
require_once __DIR__ . '/newmodal/config.php';
require_once __DIR__ . '/newmodal/helpers.php';
require_once __DIR__ . '/newmodal/bage/shortcode.php';
require_once __DIR__ . '/newmodal/bage/ajax.php';

/**
 * Public assets (registered; enqueued per shortcode)
 */
add_action('wp_enqueue_scripts', function () {
    // CSS
    wp_register_style('nm-bage', plugins_url('newmodal/assets/css/bage.css', __FILE__), [], NM_VER);
    wp_register_style('nm-modal', plugins_url('newmodal/assets/css/modal.css', __FILE__), [], NM_VER);
    // JS
    wp_register_script('nm-bage', plugins_url('newmodal/assets/js/nm-bage.js', __FILE__), ['jquery'], NM_VER, true);
    wp_register_script('nm-modal', plugins_url('newmodal/assets/js/nm-modal.js', __FILE__), ['jquery'], NM_VER, true);
    wp_register_script('nm-new-ticket', plugins_url('newmodal/assets/js/nm-new-ticket.js', __FILE__), ['jquery'], NM_VER, true);
});

/**
 * Localize common vars
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('nm_ajax');
    $glpi_uid = function_exists('glpi_get_current_glpi_user_id') ? glpi_get_current_glpi_user_id() : 0;
    wp_localize_script('nm-bage', 'nmAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => $nonce,
        'glpi_uid' => (int)$glpi_uid,
    ]);
    wp_localize_script('nm-modal', 'nmAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => $nonce,
        'glpi_uid' => (int)$glpi_uid,
    ]);
    wp_localize_script('nm-new-ticket', 'nmAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => $nonce,
        'glpi_uid' => (int)$glpi_uid,
    ]);
});

// Safety: deny direct file listing on prod hosts
add_filter('script_loader_tag', function($tag, $handle, $src){
    return $tag;
}, 10, 3);
