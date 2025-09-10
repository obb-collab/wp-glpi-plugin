<?php
// newmodal/bage/ajax-extra.php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_nm_get_card', 'nm_ajax_get_card');

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
