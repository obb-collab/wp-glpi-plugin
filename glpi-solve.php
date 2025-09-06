<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-modal-actions.php';

add_action('wp_ajax_glpi_ticket_resolve', 'gexe_glpi_ticket_resolve');
function gexe_glpi_ticket_resolve() {
    check_ajax_referer('gexe_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($ticket_id <= 0) {
        wp_send_json(['error' => 'bad_ticket'], 422);
    }

    if (!is_user_logged_in()) {
        gexe_log_action('[resolve.sql] ticket=' . $ticket_id . ' result=fail code=not_logged_in');
        wp_send_json(['error' => 'not_logged_in'], 401);
    }
    $author_glpi = gexe_get_current_glpi_user_id(get_current_user_id());
    if ($author_glpi <= 0) {
        gexe_log_action('[resolve.sql] ticket=' . $ticket_id . ' result=fail code=no_glpi_id');
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $solution_text = isset($_POST['solution_text']) ? sanitize_textarea_field((string) $_POST['solution_text']) : '';
    $status        = (int) get_option('glpi_solved_status', 6);

    $res = set_ticket_status_sql($ticket_id, $status, $author_glpi);
    if (!$res['ok']) {
        if (($res['code'] ?? '') === 'SQL_ERROR') {
            gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $res['message'] ?? ''));
            wp_send_json(['error' => 'sql_error', 'details' => mb_substr($res['message'] ?? '', 0, 200)], 500);
        }
        wp_send_json(['error' => $res['code'] ?? 'error', 'message' => $res['message'] ?? ''], 422);
    }

    $followup_id = 0;
    if ($solution_text !== '') {
        $f = gexe_add_followup_sql($ticket_id, $solution_text, $author_glpi);
        if (!$f['ok']) {
            if (($f['code'] ?? '') === 'SQL_ERROR') {
                gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $f['message'] ?? ''));
                wp_send_json(['error' => 'sql_error', 'details' => mb_substr($f['message'] ?? '', 0, 200)], 500);
            }
            wp_send_json(['error' => $f['code'] ?? 'error', 'message' => $f['message'] ?? ''], 422);
        }
        $followup_id = $f['followup_id'] ?? 0;
    }

    gexe_clear_comments_cache($ticket_id);
    gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d followup=%d status=%d result=ok', $ticket_id, $author_glpi, $followup_id, $status));
    wp_send_json([
        'ok'          => true,
        'ticket_id'   => $ticket_id,
        'status'      => $status,
        'followup_id' => $followup_id,
    ]);
}
