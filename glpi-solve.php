<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-modal-actions.php';

add_action('wp_ajax_glpi_ticket_resolve', 'gexe_glpi_ticket_resolve');
function gexe_glpi_ticket_resolve() {
    $wp_uid = get_current_user_id();
    if (!check_ajax_referer('gexe_actions', '_ajax_nonce', false)) {
        error_log('[resolve] nonce_failed ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'nonce_failed'], 403);
    }

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($ticket_id <= 0) {
        error_log('[resolve] ticket_not_found ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }

    if (!is_user_logged_in()) {
        error_log('[resolve] not_logged_in ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'not_logged_in'], 401);
    }
    $author_glpi = gexe_get_current_glpi_user_id($wp_uid);
    if ($author_glpi <= 0) {
        error_log('[resolve] no_glpi_id_for_current_user ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $status        = (int) get_option('glpi_solved_status', 6);
    $solution_text = isset($_POST['solution_text']) ? sanitize_textarea_field((string) $_POST['solution_text']) : 'Завершено';

    global $glpi_db;
    $exists = $glpi_db->get_var($glpi_db->prepare('SELECT 1 FROM glpi_tickets WHERE id=%d', $ticket_id));
    if (!$exists) {
        error_log('[resolve] ticket_not_found ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }

    $glpi_db->query('START TRANSACTION');
    $sql = $glpi_db->prepare('UPDATE glpi_tickets SET status=%d, users_id_lastupdater=%d, date_mod=NOW() WHERE id=%d', $status, $author_glpi, $ticket_id);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $err));
        error_log('[resolve] sql_error status_update_failed ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi . ' sql=' . $err);
        wp_send_json(['error' => 'sql_error', 'details' => 'status_update_failed'], 500);
    }

    $f = gexe_add_followup_sql($ticket_id, $solution_text, $author_glpi);
    if (!$f['ok']) {
        $glpi_db->query('ROLLBACK');
        if (($f['code'] ?? '') === 'SQL_ERROR') {
            gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $f['message'] ?? ''));
            error_log('[resolve] sql_error followup_insert_failed ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi . ' sql=' . ($f['message'] ?? ''));
            wp_send_json(['error' => 'sql_error', 'details' => 'followup_insert_failed'], 500);
        }
        error_log('[resolve] ' . ($f['code'] ?? 'error') . ' ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
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
    wp_send_json(['ok' => true, 'payload' => [
        'ticket_id' => $ticket_id,
        'status'    => $status,
        'followup'  => $followup,
    ]]);
}
