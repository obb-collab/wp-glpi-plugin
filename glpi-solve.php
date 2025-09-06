<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-modal-actions.php';

add_action('wp_ajax_glpi_ticket_resolve', 'gexe_glpi_ticket_resolve');
function gexe_glpi_ticket_resolve() {
    check_ajax_referer('gexe_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($ticket_id <= 0) {
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }

    if (!is_user_logged_in()) {
        gexe_log_action('[resolve.sql] ticket=' . $ticket_id . ' result=fail code=not_logged_in');
        wp_send_json(['error' => 'not_logged_in'], 401);
    }
    $author_glpi = gexe_get_current_glpi_uid();
    if ($author_glpi <= 0) {
        gexe_log_action('[resolve.sql] ticket=' . $ticket_id . ' result=fail code=no_glpi_id');
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $status        = (int) get_option('glpi_solved_status', 6);
    $solution_text = isset($_POST['solution_text']) ? sanitize_textarea_field((string) $_POST['solution_text']) : 'Завершено';

    global $glpi_db;
    $exists = $glpi_db->get_var($glpi_db->prepare('SELECT 1 FROM glpi_tickets WHERE id=%d', $ticket_id));
    if (!$exists) {
        gexe_log_action('[resolve.sql] ticket=' . $ticket_id . ' result=fail code=ticket_not_found');
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }

    $glpi_db->query('START TRANSACTION');
    $sql = $glpi_db->prepare('UPDATE glpi_tickets SET status=%d, users_id_lastupdater=%d, date_mod=NOW() WHERE id=%d', $status, $author_glpi, $ticket_id);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $err));
        wp_send_json(['error' => 'sql_error', 'details' => mb_substr($err, 0, 200)], 500);
    }

    $f = gexe_add_followup_sql($ticket_id, $solution_text, $author_glpi);
    if (!$f['ok']) {
        $glpi_db->query('ROLLBACK');
        if (($f['code'] ?? '') === 'SQL_ERROR') {
            gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $f['message'] ?? ''));
            wp_send_json(['error' => 'sql_error', 'details' => mb_substr($f['message'] ?? '', 0, 200)], 500);
        }
        wp_send_json(['error' => $f['code'] ?? 'error'], 422);
    }
    $followup = [
        'id'       => (int) ($f['followup_id'] ?? 0),
        'items_id' => $ticket_id,
        'users_id' => $author_glpi,
        'content'  => wp_kses_post($solution_text),
        'date'     => date('c'),
    ];

    $glpi_db->query('COMMIT');
    gexe_clear_comments_cache($ticket_id);
    gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d followup=%d status=%d result=ok', $ticket_id, $author_glpi, $followup['id'], $status));
    wp_send_json([
        'ok'        => true,
        'ticket_id' => $ticket_id,
        'status'    => $status,
        'followup'  => $followup,
    ]);
}
