<?php
/**
 * Loader for the "chief" subsystem.
 *
 * Registers a rewrite rule and hooks into template loading to serve the
 * custom page. Styles and scripts are enqueued separately. This is only a
 * skeleton implementation.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Determine if current user has access to the chief page.
 */
function glpi_chief_has_access(): bool {
    $u = wp_get_current_user();
    if (!$u || !$u->ID) {
        return false;
    }
    $login   = isset($u->user_login) ? (string) $u->user_login : '';
    $glpi_id = (int) get_user_meta($u->ID, 'glpi_user_id', true);
    return ($login === 'vks_m5_local') || ($glpi_id === 2);
}

/** Register rewrite rule and query var. */
add_action('init', function () {
    add_rewrite_rule('^glpi-chief/?$', 'index.php?glpi_chief=1', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'glpi_chief';
    return $vars;
});

/** Template loader for the chief page. */
add_filter('template_include', function ($template) {
    if (get_query_var('glpi_chief') === '1') {
        if (!glpi_chief_has_access()) {
            status_header(403);
            echo 'Доступ ограничен';
            exit;
        }
        $base = dirname(__DIR__);
        return $base . '/chief/glpi-chief.php';
    }
    return $template;
});

/** Enqueue assets for the chief page. */
add_action('wp_enqueue_scripts', function () {
    if (get_query_var('glpi_chief') !== '1') {
        return;
    }
    $base_dir = dirname(__DIR__);
    $base_url = plugin_dir_url($base_dir . '/gexe-copy.php');
    wp_enqueue_style('glpi-chief', $base_url . 'chief/glpi-chief.css', [], '1.0.0');
    wp_enqueue_script('glpi-chief', $base_url . 'chief/glpi-chief.js', [], '1.0.0', true);
    wp_localize_script('glpi-chief', 'glpiChiefAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('glpi_chief_actions'),
    ]);
});

// Include AJAX endpoints.
require_once __DIR__ . '/glpi-chief-endpoints.php';
