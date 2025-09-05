<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-api.php';
require_once __DIR__ . '/glpi-modal-actions.php';

add_action('wp_ajax_glpi_mark_solved', 'gexe_glpi_mark_solved');
function gexe_glpi_mark_solved() {
    check_ajax_referer('glpi_modal_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $solution  = isset($_POST['solution_text']) ? sanitize_text_field(wp_unslash($_POST['solution_text'])) : '';
    if ($ticket_id <= 0) {
        wp_send_json(['ok' => false, 'error' => 'bad_ticket']);
    }
    if (!is_user_logged_in() || !gexe_can_touch_glpi_ticket($ticket_id)) {
        wp_send_json(['ok' => false, 'error' => 'forbidden']);
    }

    $base       = rtrim((string)get_option('glpi_api_base', ''), '/');
    $app_token  = (string)get_option('glpi_app_token', '');
    $user_token = (string)get_option('glpi_user_token', '');
    $status     = intval(get_option('glpi_solved_status', 6));

    if ($base === '' || $app_token === '' || $user_token === '') {
        error_log('[GLPI-SOLVE] Missing API configuration');
        wp_send_json(['ok' => false, 'error' => 'config']);
    }

    $client = new Gexe_GLPI_API($base, $app_token, $user_token);
    $ok     = false;
    try {
        $resp = $client->init_session();
        if (is_wp_error($resp)) {
            error_log('[GLPI-SOLVE] initSession: ' . $resp->get_error_message());
        } else {
            $txt = $solution !== '' ? $solution : 'Задача решена';
            $r2  = $client->add_solution($ticket_id, $txt);
            if (is_wp_error($r2) || wp_remote_retrieve_response_code($r2) >= 300) {
                $msg = is_wp_error($r2) ? $r2->get_error_message() : wp_remote_retrieve_response_message($r2);
                error_log('[GLPI-SOLVE] addSolution: ' . $msg);
            } else {
                $r3 = $client->set_ticket_status($ticket_id, $status);
                if (is_wp_error($r3) || wp_remote_retrieve_response_code($r3) >= 300) {
                    $msg = is_wp_error($r3) ? $r3->get_error_message() : wp_remote_retrieve_response_message($r3);
                    error_log('[GLPI-SOLVE] setTicketStatus: ' . $msg);
                } else {
                    $ok = true;
                }
            }
        }
    } finally {
        $client->kill_session();
    }

    wp_send_json(['ok' => $ok]);
}
