<?php
/**
 * AJAX endpoints for the chief subsystem.
 *
 * Only a minimal skeleton is provided. Actual SQL logic should mirror the
 * main plugin but filter by assignee when requested.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Verify nonce and access rights. */
function glpi_chief_verify_request() {
    if (!check_ajax_referer('glpi_chief_actions', 'nonce', false)) {
        wp_send_json_error(['error' => 'bad_nonce'], 400);
    }
    if (!glpi_chief_has_access()) {
        wp_send_json_error(['error' => 'forbidden'], 403);
    }
}

add_action('wp_ajax_glpi_chief_tickets', 'glpi_chief_ajax_tickets');

function glpi_chief_ajax_tickets() {
    glpi_chief_verify_request();

    $selected = sanitize_text_field($_POST['selected_user_id'] ?? 'all');
    // TODO: implement SQL fetching similar to main page.
    wp_send_json_success([
        'selected' => $selected,
        'tickets'  => [],
    ]);
}
