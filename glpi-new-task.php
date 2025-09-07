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

    $name = sanitize_text_field($_POST['name'] ?? '');
    $desc = sanitize_textarea_field($_POST['description'] ?? '');
    $cat  = (int) ($_POST['category_id'] ?? 0);
    $loc  = (int) ($_POST['location_id'] ?? 0);
    $assign_me = !empty($_POST['assign_me']);

    $author = (int) $map['id'];

    $payload = [
        'name' => $name,
        'content' => $desc,
        'category_id' => $cat,
        'location_id' => $loc,
        'assign_me' => $assign_me,
        'requester_id' => $author,
    ];

    $res = glpi_db_create_ticket($payload);
    wp_send_json($res);
}

