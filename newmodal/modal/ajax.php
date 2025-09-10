<?php
// newmodal/modal/ajax.php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_nm_add_comment', 'nm_ajax_add_comment');
add_action('wp_ajax_nm_change_status', 'nm_ajax_change_status');
add_action('wp_ajax_nm_assign_user', 'nm_ajax_assign_user');

function nm_ajax_add_comment() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $ticket_id = nm_expect_int($_POST['ticket_id'] ?? 0, 'ticket_id');
    $body = nm_expect_non_empty($_POST['body'] ?? '', 'body');
    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    nm_idempotency_check_and_set($request_id);

    $glpi_uid = nm_glpi_user_id_from_wp();
    nm_require_can_view_ticket($ticket_id, $glpi_uid);

    try {
        nm_db_begin();
        nm_db_query("\
            INSERT INTO ".nm_tbl('itilfollowups')." (items_id, itemtype, users_id, content, date)\
            VALUES (%d, 'Ticket', %d, %s, %s)\
        ", [$ticket_id, $glpi_uid, $body, current_time('mysql')]);
        $fid = nm_db_insert_id();
        nm_db_commit();
        nm_notify_after_write($ticket_id, 'comment', $glpi_uid);
        nm_json_ok(['followup_id' => $fid]);
    } catch (Exception $e) {
        nm_db_rollback();
        nm_json_error('db_error', __('Failed to add comment', 'nm'));
    }
}

function nm_ajax_change_status() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $ticket_id = nm_expect_int($_POST['ticket_id'] ?? 0, 'ticket_id');
    $status = nm_expect_int($_POST['status'] ?? 0, 'status');
    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    nm_idempotency_check_and_set($request_id);

    $glpi_uid = nm_glpi_user_id_from_wp();
    nm_require_can_view_ticket($ticket_id, $glpi_uid);

    try {
        nm_db_begin();
        nm_db_query("UPDATE ".nm_tbl('tickets')." SET status = %d WHERE id = %d", [$status, $ticket_id]);
        nm_db_commit();
        nm_notify_after_write($ticket_id, 'status', $glpi_uid);
        nm_json_ok([]);
    } catch (Exception $e) {
        nm_db_rollback();
        nm_json_error('db_error', __('Failed to change status', 'nm'));
    }
}

function nm_ajax_assign_user() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $ticket_id = nm_expect_int($_POST['ticket_id'] ?? 0, 'ticket_id');
    $assignee_id = nm_expect_int($_POST['assignee_id'] ?? 0, 'assignee_id');
    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    nm_idempotency_check_and_set($request_id);

    nm_require_can_assign($assignee_id);
    $glpi_uid = nm_glpi_user_id_from_wp();
    nm_require_can_view_ticket($ticket_id, $glpi_uid);

    try {
        nm_db_begin();
        nm_db_query("DELETE FROM ".nm_tbl('tickets_users')." WHERE tickets_id = %d AND type = 2", [$ticket_id]);
        nm_db_query("INSERT INTO ".nm_tbl('tickets_users')." (tickets_id, users_id, type) VALUES (%d, %d, 2)", [$ticket_id, $assignee_id]);
        nm_db_commit();
        nm_notify_after_write($ticket_id, 'assign', $assignee_id);
        nm_json_ok([]);
    } catch (Exception $e) {
        nm_db_rollback();
        nm_json_error('db_error', __('Failed to assign user', 'nm'));
    }
}
