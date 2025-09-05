<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-modal-actions.php';

add_action('wp_ajax_glpi_ticket_resolve', 'gexe_glpi_ticket_resolve');
function gexe_glpi_ticket_resolve() {
    check_ajax_referer('gexe_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($ticket_id <= 0) {
        wp_send_json_error(['code' => 'BAD_TICKET']);
    }
    if (!is_user_logged_in() || !gexe_can_touch_glpi_ticket($ticket_id)) {
        wp_send_json_error(['code' => 'FORBIDDEN'], 403);
    }

    $resp = gexe_glpi_rest_request('ticket_solve ' . $ticket_id, 'PUT', '/Ticket/' . $ticket_id, [
        'input' => [
            'id'     => $ticket_id,
            'status' => 6,
        ],
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['code' => 'NETWORK']);
    }

    $code = wp_remote_retrieve_response_code($resp);
    if ($code >= 300) {
        $body  = wp_remote_retrieve_body($resp);
        $short = mb_substr(trim($body), 0, 200);
        wp_send_json_error(['code' => 'GLPI_ERROR', 'message' => $short], 500);
    }

    gexe_clear_comments_cache($ticket_id);
    wp_send_json_success([
        'ticket_id' => $ticket_id,
        'status'    => 6,
    ]);
}
