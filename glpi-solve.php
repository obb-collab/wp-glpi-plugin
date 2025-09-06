<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-modal-actions.php';

add_action('wp_ajax_glpi_ticket_resolve', 'gexe_glpi_ticket_resolve');
function gexe_glpi_ticket_resolve() {
    $wp_uid = get_current_user_id();
    if (!check_ajax_referer('gexe_actions', '_ajax_nonce', false)) {
        gexe_ajax_error('NONCE_EXPIRED', 'Сессия истекла', 403);
    }

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($ticket_id <= 0) {
        gexe_ajax_error('INVALID_INPUT', 'Тикет не найден', 404);
    }

    if (!is_user_logged_in()) {
        gexe_ajax_error('NO_PERMISSION', 'Требуется вход', 401);
    }
    $author_glpi = gexe_get_current_glpi_user_id($wp_uid);
    if ($author_glpi <= 0) {
        gexe_ajax_error('NO_GLPI_USER', 'Не найден GLPI ID', 422);
    }

    $status        = (int) get_option('glpi_solved_status', 6);
    $solution_text = isset($_POST['solution_text']) ? sanitize_textarea_field((string) $_POST['solution_text']) : 'Завершено';

    global $glpi_db;
    $exists = $glpi_db->get_var($glpi_db->prepare('SELECT 1 FROM glpi_tickets WHERE id=%d', $ticket_id));
    if (!$exists) {
        gexe_ajax_error('INVALID_INPUT', 'Тикет не найден', 404);
    }

    $glpi_db->query('START TRANSACTION');
    $sql = $glpi_db->prepare('UPDATE glpi_tickets SET status=%d, users_id_lastupdater=%d, date_mod=NOW() WHERE id=%d', $status, $author_glpi, $ticket_id);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $err));
        gexe_ajax_error('SQL_OP_FAILED', 'Не удалось обновить статус', 500);
    }

    $f = gexe_add_followup_sql($ticket_id, $solution_text, $author_glpi);
    if (!$f['ok']) {
        $glpi_db->query('ROLLBACK');
        if (($f['code'] ?? '') === 'SQL_ERROR') {
            gexe_log_action(sprintf('[resolve.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $f['message'] ?? ''));
            gexe_ajax_error('SQL_OP_FAILED', 'Не удалось добавить комментарий', 500);
        }
        gexe_ajax_error('SQL_OP_FAILED', 'Не удалось завершить тикет', 422);
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
    gexe_ajax_success([
        'ticket_id' => $ticket_id,
        'status'    => $status,
        'followup'  => $followup,
    ]);
}
