<?php
// newmodal/new-ticket/ajax.php
if (!defined('ABSPATH')) { exit; }

require_once NM_BASE_DIR . 'common/helpers.php';
require_once NM_BASE_DIR . 'common/db.php';
require_once NM_BASE_DIR . 'common/notify-api.php';

add_action('wp_ajax_nm_catalog_categories', 'nm_ajax_catalog_categories');
add_action('wp_ajax_nm_catalog_locations', 'nm_ajax_catalog_locations');
add_action('wp_ajax_nm_catalog_assignees', 'nm_ajax_catalog_assignees');
add_action('wp_ajax_nm_create_ticket', 'nm_ajax_create_ticket');

function nm_ajax_catalog_categories() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20; $offset = ($page-1)*$per;
    $params = [];
    $where = '';
    if ($q !== '') {
        $where = " WHERE name LIKE %s ";
        $params[] = '%'.$q.'%';
    }
    $sql = "SELECT id, name FROM ".nm_tbl('itilcategories')." $where ORDER BY name ASC LIMIT %d OFFSET %d";
    $params[] = $per; $params[] = $offset;
    try {
        $items = nm_db_get_results($sql, $params);
        nm_json_ok(['items' => $items, 'page' => $page, 'total' => count($items)]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load categories', 'nm'));
    }
}

function nm_ajax_catalog_locations() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20; $offset = ($page-1)*$per;
    $params = [];
    $where = '';
    if ($q !== '') {
        $where = " WHERE name LIKE %s ";
        $params[] = '%'.$q.'%';
    }
    $sql = "SELECT id, name FROM ".nm_tbl('locations')." $where ORDER BY name ASC LIMIT %d OFFSET %d";
    $params[] = $per; $params[] = $offset;
    try {
        $items = nm_db_get_results($sql, $params);
        nm_json_ok(['items' => $items, 'page' => $page, 'total' => count($items)]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load locations', 'nm'));
    }
}

function nm_ajax_catalog_assignees() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20; $offset = ($page-1)*$per;
    $params = [];
    $where = " WHERE is_active = 1 ";
    if ($q !== '') {
        $where .= " AND ( name LIKE %s OR realname LIKE %s OR firstname LIKE %s ) ";
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
    }
    $sql = "SELECT id, name, realname, firstname FROM ".nm_tbl('users')." $where ORDER BY realname ASC LIMIT %d OFFSET %d";
    $params[] = $per; $params[] = $offset;
    try {
        $rows = nm_db_get_results($sql, $params);
        $items = [];
        foreach ($rows as $r) {
            $label = trim(($r['realname'] ?? '').' '.($r['firstname'] ?? ''));
            if (!$label) $label = $r['name'];
            $items[] = ['id' => (int)$r['id'], 'name' => $label];
        }
        nm_json_ok(['items' => $items, 'page' => $page, 'total' => count($items)]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load assignees', 'nm'));
    }
}

function nm_ajax_create_ticket() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $subject = nm_expect_non_empty($_POST['subject'] ?? '', 'subject');
    $content = nm_expect_non_empty($_POST['content'] ?? '', 'content');
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $i_am_executor = !empty($_POST['i_am_executor']);
    $assignee_id = isset($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : 0;
    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    nm_idempotency_check_and_set($request_id);

    $wp_uid = nm_current_wp_user_id();
    $glpi_requester_id = nm_glpi_user_id_from_wp($wp_uid);
    if (!$glpi_requester_id) {
        nm_json_error('forbidden', __('GLPI mapping not found', 'nm'), 403);
    }
    if ($i_am_executor) {
        $assignee_id = $glpi_requester_id;
    } else {
        nm_require_can_assign($assignee_id);
    }
    $due_date = nm_calc_due_date_sql();

    try {
        nm_db_begin();
        // Insert ticket
        nm_db_query("\
            INSERT INTO ".nm_tbl('tickets')." \n            (name, content, status, priority, date, closedate, due_date, itilcategories_id, locations_id)\n            VALUES (%s, %s, %d, %d, %s, NULL, %s, %d, %d)\n        ", [
            $subject, $content, 1, 3, current_time('mysql'), $due_date, (int)$category_id, (int)$location_id
        ]);
        $ticket_id = nm_db_insert_id();
        // requester
        nm_db_query("\
            INSERT INTO ".nm_tbl('tickets_users')." (tickets_id, users_id, type) VALUES (%d, %d, 1)\n        ", [$ticket_id, $glpi_requester_id]);
        // technician
        nm_db_query("\
            INSERT INTO ".nm_tbl('tickets_users')." (tickets_id, users_id, type) VALUES (%d, %d, 2)\n        ", [$ticket_id, $assignee_id]);
        // optional auto-followup
        nm_db_query("\
            INSERT INTO ".nm_tbl('itilfollowups')." (items_id, itemtype, users_id, content, date)\n            VALUES (%d, 'Ticket', %d, %s, %s)\n        ", [$ticket_id, $assignee_id, __('Created from WordPress', 'nm'), current_time('mysql')]);
        nm_db_commit();
        nm_notify_after_write($ticket_id, 'create', $assignee_id);
        nm_json_ok(['ticket_id' => $ticket_id]);
    } catch (Exception $e) {
        nm_db_rollback();
        nm_json_error('db_error', __('Failed to create ticket', 'nm'));
    }
}
