<?php
// newmodal/common/db.php
if (!defined('ABSPATH')) { exit; }

/**
 * GLPI DB helpers using $wpdb with prepared SQL only.
 * Assume GLPI tables accessible within the same DB (or via same connection).
 * Table names are built with configurable prefix (default 'glpi_').
 */

function nm_glpi_prefix() {
    $p = get_option('nm_glpi_prefix');
    if (!$p) { $p = 'glpi_'; }
    return $p;
}

function nm_tbl($name) {
    return nm_glpi_prefix() . $name;
}

function nm_db_begin() {
    global $wpdb;
    $wpdb->query('START TRANSACTION');
}

function nm_db_commit() {
    global $wpdb;
    $wpdb->query('COMMIT');
}

function nm_db_rollback() {
    global $wpdb;
    $wpdb->query('ROLLBACK');
}

/**
 * Secure query wrappers.
 */
function nm_db_query($sql, $params = []) {
    global $wpdb;
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    $r = $wpdb->query($sql);
    if ($r === false) {
        throw new Exception(__('Database query failed', 'nm'));
    }
    return $r;
}

function nm_db_get_results($sql, $params = []) {
    global $wpdb;
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($rows === null) {
        throw new Exception(__('Database select failed', 'nm'));
    }
    return $rows;
}

function nm_db_get_row($sql, $params = []) {
    global $wpdb;
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    $row = $wpdb->get_row($sql, ARRAY_A);
    return $row;
}

function nm_db_insert_id() {
    global $wpdb;
    return (int)$wpdb->insert_id;
}

/**
 * Domain helpers for tickets/followups.
 */
function nm_sql_list_tickets($glpi_user_id, $args) {
    $status_in = '';
    $params = [];
    if (!empty($args['status']) && is_array($args['status'])) {
        // build placeholders
        $in = implode(',', array_fill(0, count($args['status']), '%d'));
        $status_in = " AND t.status IN ($in) ";
        foreach ($args['status'] as $s) { $params[] = (int)$s; }
    }
    $search = '';
    if (!empty($args['search'])) {
        $search = " AND (t.name LIKE %s OR CAST(t.id AS CHAR) LIKE %s) ";
        $params[] = '%' . $args['search'] . '%';
        $params[] = '%' . $args['search'] . '%';
    }
    $assignee_filter = '';
    if (!empty($args['assignee'])) {
        $assignee_filter = " AND tu.users_id = %d ";
        $params[] = (int)$args['assignee'];
    } else {
        // default: only own tickets for non-managers
        if (!nm_is_manager()) {
            $assignee_filter = " AND tu.users_id = %d ";
            $params[] = (int)$glpi_user_id;
        }
    }
    $page = max(1, (int)($args['page'] ?? 1));
    $per  = min(100, max(1, (int)($args['per_page'] ?? 20)));
    $offset = ($page - 1) * $per;

    $sql = "
        SELECT SQL_CALC_FOUND_ROWS
            t.id, t.name, t.status, t.date, t.closedate, t.priority, t.content,
            ass.users_id as assignee_id,
            cat.name as category_name
        FROM ".nm_tbl('tickets')." t
        LEFT JOIN ".nm_tbl('itilcategories')." cat ON cat.id = t.itilcategories_id
        LEFT JOIN ".nm_tbl('tickets_users')." tu ON (tu.tickets_id = t.id AND tu.type = 2)
        LEFT JOIN ".nm_tbl('tickets_users')." ass ON (ass.tickets_id = t.id AND ass.type = 2)
        WHERE 1=1
        $status_in
        $assignee_filter
        $search
        ORDER BY t.date DESC
        LIMIT %d OFFSET %d
    ";
    $params[] = $per;
    $params[] = $offset;
    $items = nm_db_get_results($sql, $params);
    global $wpdb;
    $total = (int)$wpdb->get_var('SELECT FOUND_ROWS()');
    return [$items, $page, $total];
}

function nm_sql_counts_by_status($glpi_user_id, $assignee = null) {
    $assignee_sql = '';
    $params = [];
    if ($assignee) {
        $assignee_sql = " AND tu.users_id = %d ";
        $params[] = (int)$assignee;
    } else {
        if (!nm_is_manager()) {
            $assignee_sql = " AND tu.users_id = %d ";
            $params[] = (int)$glpi_user_id;
        }
    }
    $sql = "
        SELECT t.status, COUNT(*) as cnt
        FROM ".nm_tbl('tickets')." t
        LEFT JOIN ".nm_tbl('tickets_users')." tu ON (tu.tickets_id = t.id AND tu.type = 2)
        WHERE 1=1
        $assignee_sql
        GROUP BY t.status
    ";
    $rows = nm_db_get_results($sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['status']] = (int)$r['cnt'];
    }
    return $out;
}

function nm_sql_ticket_dto($ticket_id, $glpi_user_id) {
    // Access check embedded: if not manager, ensure current user is assignee
    $where_acl = '';
    $params = [(int)$ticket_id];
    if (!nm_is_manager()) {
        $where_acl = " AND EXISTS(SELECT 1 FROM ".nm_tbl('tickets_users')." tu WHERE tu.tickets_id = t.id AND tu.type=2 AND tu.users_id = %d) ";
        $params[] = (int)$glpi_user_id;
    }
    $sql = "
        SELECT t.*, cat.name AS category_name
        FROM ".nm_tbl('tickets')." t
        LEFT JOIN ".nm_tbl('itilcategories')." cat ON cat.id = t.itilcategories_id
        WHERE t.id = %d
        $where_acl
        LIMIT 1
    ";
    $ticket = nm_db_get_row($sql, $params);
    return $ticket;
}

function nm_sql_followups($ticket_id, $limit = 100) {
    $sql = "
        SELECT f.id, f.content, f.date, u.name AS user_name
        FROM ".nm_tbl('itilfollowups')." f
        LEFT JOIN ".nm_tbl('users')." u ON u.id = f.users_id
        WHERE f.items_id = %d AND f.itemtype = 'Ticket'
        ORDER BY f.date ASC
        LIMIT %d
    ";
    return nm_db_get_results($sql, [(int)$ticket_id, (int)$limit]);
}

function nm_sql_assignee_id($ticket_id) {
    $row = nm_db_get_row("
        SELECT users_id FROM ".nm_tbl('tickets_users')." 
        WHERE tickets_id = %d AND type = 2 LIMIT 1
    ", [(int)$ticket_id]);
    return $row ? (int)$row['users_id'] : null;
}
