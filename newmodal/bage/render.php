<?php
// newmodal/bage/render.php
if (!defined('ABSPATH')) { exit; }

require_once NM_BASE_DIR . 'common/helpers.php';
require_once NM_BASE_DIR . 'common/db.php';

function nm_ajax_get_cards() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $wp_uid = nm_current_wp_user_id();
    $glpi_uid = nm_glpi_user_id_from_wp($wp_uid);
    if (!$glpi_uid && !nm_is_manager()) {
        nm_json_error('forbidden', __('GLPI mapping not found', 'nm'), 403);
    }
    $args = [
        'status'   => isset($_GET['status']) ? (array)$_GET['status'] : [],
        'assignee' => isset($_GET['assignee']) ? (int)$_GET['assignee'] : null,
        'search'   => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
        'page'     => isset($_GET['page']) ? (int)$_GET['page'] : 1,
        'per_page' => isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20,
    ];
    try {
        list($items, $page, $total) = nm_sql_list_tickets($glpi_uid, $args);
        $counts = nm_sql_counts_by_status($glpi_uid, $args['assignee']);
        nm_json_ok(['items' => $items, 'counts' => ['by_status' => $counts], 'page' => $page, 'total' => $total]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load cards', 'nm'));
    }
}

function nm_ajax_get_counts() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $glpi_uid = nm_glpi_user_id_from_wp();
    $assignee = isset($_GET['assignee']) ? (int)$_GET['assignee'] : null;
    try {
        $counts = nm_sql_counts_by_status($glpi_uid, $assignee);
        nm_json_ok(['counts' => ['by_status' => $counts]]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load counts', 'nm'));
    }
}

function nm_ajax_get_card() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    $glpi_uid = nm_glpi_user_id_from_wp();
    try {
        nm_require_can_view_ticket($ticket_id, $glpi_uid);
        $ticket = nm_sql_ticket_dto($ticket_id, $glpi_uid);
        $followups = nm_sql_followups($ticket_id, 500);
        nm_json_ok(['ticket' => $ticket, 'followups' => $followups]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load ticket', 'nm'));
    }
}
