<?php
/**
 * AJAX endpoints for the "New ticket" modal.
 *
 * Provides separate endpoints for loading dictionaries and creating a ticket.
 * All responses use JSON (HTTP 200).
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-db-setup.php';
require_once __DIR__ . '/inc/user-map.php';

add_action('wp_enqueue_scripts', function () {
    wp_register_style('glpi-new-task', plugin_dir_url(__FILE__) . 'glpi-new-task.css', [], '1.0.0');
    wp_enqueue_style('glpi-new-task');

    wp_register_script('glpi-new-task-js', plugin_dir_url(__FILE__) . 'glpi-new-task.js', [], '1.0.0', true);
    wp_enqueue_script('glpi-new-task-js');
    wp_localize_script('glpi-new-task-js', 'glpiAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('glpi_new_task'),
    ]);
});

/** Verify AJAX nonce. */
function glpi_nt_verify_nonce() {
    if (!check_ajax_referer('glpi_new_task', 'nonce', false)) {
        wp_send_json(['ok' => false, 'code' => 'csrf']);
    }
}

// -------- Dictionaries --------
add_action('wp_ajax_glpi_get_categories', 'glpi_ajax_get_categories');
function glpi_ajax_get_categories() {
    glpi_nt_verify_nonce();
    $res = glpi_db_get_categories();
    wp_send_json($res);
}

add_action('wp_ajax_glpi_get_locations', 'glpi_ajax_get_locations');
function glpi_ajax_get_locations() {
    glpi_nt_verify_nonce();
    $res = glpi_db_get_locations();
    wp_send_json($res);
}

add_action('wp_ajax_glpi_get_executors', 'glpi_ajax_get_executors');
function glpi_ajax_get_executors() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'code' => 'not_logged_in']);
    }
    $map = gexe_require_glpi_user(get_current_user_id());
    if (!$map['ok']) {
        wp_send_json(['ok' => false, 'code' => $map['code']]);
    }
    // Executors come from WordPress users mapped to GLPI users.  Helper name
    // changed to `gexe_get_executors_wp()`; keep compatibility via alias.
    $res = gexe_get_executors_wp();
    wp_send_json($res);
}

// -------- Create ticket --------
add_action('wp_ajax_glpi_create_ticket', 'glpi_ajax_create_ticket');
function glpi_ajax_create_ticket() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'code' => 'not_logged_in']);
    }
    $map = gexe_require_glpi_user(get_current_user_id());
    if (!$map['ok']) {
        wp_send_json(['ok' => false, 'code' => $map['code']]);
    }

    $payload = [
        'name'        => sanitize_text_field($_POST['name'] ?? ''),
        'content'     => sanitize_textarea_field($_POST['description'] ?? ''),
        'category_id' => (int) ($_POST['category_id'] ?? 0),
        'location_id' => (int) ($_POST['location_id'] ?? 0),
        'executor_glpi_id' => (int) ($_POST['executor_glpi_id'] ?? 0),
        'assign_me'   => !empty($_POST['assign_me']),
        'requester_id'=> $map['id'],
        'entities_id' => (int) ($_POST['entities_id'] ?? 0),
    ];

    $res = glpi_db_create_ticket($payload);
    wp_send_json($res);
}

