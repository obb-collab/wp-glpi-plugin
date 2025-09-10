<?php
if (!defined('ABSPATH')) { exit; }

/**
 * AJAX: tickets list (SQL)
 */
add_action('wp_ajax_nm_get_cards', function () {
    try {
        check_ajax_referer('nm_ajax','nonce');
        $glpi_uid = nm_assert_user_mapping();
        global $glpi_db;

        // Filters
        $status   = isset($_POST['status']) ? (int)$_POST['status'] : 0;
        $search   = isset($_POST['q']) ? trim(wp_unslash($_POST['q'])) : '';
        $limit    = 50;

        // Only own tickets for assigned tech
        $sql = "
            SELECT t.id, t.name, t.content, t.status, t.priority,
                   t.date, t.time_to_resolve, t.solvedate,
                   tu.users_id AS assigned_id
            FROM glpi_tickets t
            JOIN glpi_tickets_users tu
              ON tu.tickets_id = t.id AND tu.type = 2
            WHERE tu.users_id = %d
        ";
        $args = [$glpi_uid];
        if ($status > 0) {
            $sql .= " AND t.status = %d";
            $args[] = $status;
        }
        if ($search !== '') {
            $sql .= " AND (t.name LIKE %s OR t.content LIKE %s)";
            $like = '%' . $glpi_db->esc_like($search) . '%';
            $args[] = $like; $args[] = $like;
        }
        $sql .= " ORDER BY t.date DESC LIMIT %d";
        $args[] = $limit;
        $rows = $glpi_db->get_results($glpi_db->prepare($sql, $args), ARRAY_A);
        nm_send_json(true, ['items' => $rows ?: []]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error' => $e->getMessage()], 400);
    }
});

/**
 * AJAX: counts (SQL)
 */
add_action('wp_ajax_nm_get_counts', function () {
    try {
        check_ajax_referer('nm_ajax','nonce');
        $glpi_uid = nm_assert_user_mapping();
        global $glpi_db;
        $sql = "
            SELECT t.status, COUNT(*) as cnt
            FROM glpi_tickets t
            JOIN glpi_tickets_users tu
              ON tu.tickets_id = t.id AND tu.type = 2 AND tu.users_id = %d
            GROUP BY t.status
        ";
        $rows = $glpi_db->get_results($glpi_db->prepare($sql, $glpi_uid), ARRAY_A);
        $map = [];
        foreach ($rows as $r) { $map[(int)$r['status']] = (int)$r['cnt']; }
        nm_send_json(true, ['counts' => $map]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error' => $e->getMessage()], 400);
    }
});

/**
 * AJAX: single card (SQL)
 */
add_action('wp_ajax_nm_get_card', function () {
    try {
        check_ajax_referer('nm_ajax','nonce');
        $glpi_uid = nm_assert_user_mapping();
        global $glpi_db;
        $tid = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
        if ($tid <= 0) { throw new RuntimeException('ticket_id required'); }

        // Verify assigned to current user
        $is_assigned = $glpi_db->get_var($glpi_db->prepare(
            "SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type=2",
            $tid, $glpi_uid
        ));
        if (!$is_assigned) { throw new RuntimeException('Access denied for this ticket'); }

        $t = $glpi_db->get_row($glpi_db->prepare(
            "SELECT id, name, content, status, priority, date, time_to_resolve, solvedate
             FROM glpi_tickets WHERE id=%d", $tid
        ), ARRAY_A);
        if (!$t) { throw new RuntimeException('Ticket not found'); }

        $f = $glpi_db->get_results($glpi_db->prepare(
            "SELECT id, date, content, users_id, is_private
             FROM glpi_itilfollowups WHERE items_id=%d AND itemtype='Ticket'
             ORDER BY date ASC", $tid
        ), ARRAY_A);

        nm_send_json(true, ['ticket' => $t, 'followups' => $f ?: []]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error' => $e->getMessage()], 400);
    }
});

/**
 * AJAX: New Ticket (REST API write)
 * Payload: name, content, category_id (itilcategories_id), location_id, due (datetime), requester_id, assignee_id
 */
add_action('wp_ajax_nm_create_ticket', function () {
    try {
        check_ajax_referer('nm_ajax','nonce');
        $glpi_uid = nm_assert_user_mapping(); // also ensures logged-in
        $name   = isset($_POST['name']) ? trim(wp_unslash($_POST['name'])) : '';
        $content= isset($_POST['content']) ? trim(wp_unslash($_POST['content'])) : '';
        $cat    = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
        $loc    = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
        $due    = isset($_POST['due']) ? trim(wp_unslash($_POST['due'])) : '';
        $assignee = isset($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : 0;
        $requester= isset($_POST['requester_id']) ? (int)$_POST['requester_id'] : $glpi_uid;
        if ($name === '' || $content === '') {
            throw new RuntimeException('Name and content are required');
        }
        // GLPI Ticket schema minimal
        $payload = [
            'input' => [
                'name'             => $name,
                'content'          => $content,
                'itilcategories_id'=> $cat ?: null,
                'locations_id'     => $loc ?: null,
                'time_to_resolve'  => $due ?: null,
                '_users_id_requester' => $requester,
                '_users_id_assign'    => $assignee ?: $glpi_uid,
            ]
        ];
        $resp = nm_glpi_api('POST', '/Ticket', $payload);
        nm_send_json(true, ['created' => $resp]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error' => $e->getMessage()], 400);
    }
});

/**
 * AJAX: Add Followup (REST API write)
 */
add_action('wp_ajax_nm_add_followup', function(){
    try {
        check_ajax_referer('nm_ajax','nonce');
        nm_assert_user_mapping();
        $tid = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
        $msg = isset($_POST['message']) ? trim(wp_unslash($_POST['message'])) : '';
        if ($tid<=0 || $msg==='') throw new RuntimeException('ticket_id and message are required');
        $payload = [
            'input' => [
                'itemtype' => 'Ticket',
                'items_id' => $tid,
                'content'  => $msg,
                'is_private'=> 0,
            ]
        ];
        $resp = nm_glpi_api('POST','/ITILFollowup', $payload);
        nm_send_json(true, ['ok'=>true,'result'=>$resp]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error'=>$e->getMessage()], 400);
    }
});

/**
 * AJAX: Update Status (REST API write)
 */
add_action('wp_ajax_nm_update_status', function(){
    try {
        check_ajax_referer('nm_ajax','nonce');
        nm_assert_user_mapping();
        $tid = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;
        if ($tid<=0 || $status<=0) throw new RuntimeException('ticket_id and status are required');
        $payload = [
            'input' => [
                'id'     => $tid,
                'status' => $status
            ]
        ];
        $resp = nm_glpi_api('PUT','/Ticket/'.$tid, $payload);
        nm_send_json(true, ['ok'=>true,'result'=>$resp]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error'=>$e->getMessage()], 400);
    }
});

/**
 * AJAX: catalogs for New Ticket (SQL reads)
 */
add_action('wp_ajax_nm_get_catalogs', function(){
    try {
        check_ajax_referer('nm_ajax','nonce');
        nm_assert_user_mapping();
        global $glpi_db;
        $cats = $glpi_db->get_results("SELECT id, name FROM glpi_itilcategories WHERE is_recursive=1 OR entities_id=0 ORDER BY name ASC", ARRAY_A);
        $locs = $glpi_db->get_results("SELECT id, name FROM glpi_locations ORDER BY name ASC", ARRAY_A);
        nm_send_json(true, ['categories'=>$cats ?: [], 'locations'=>$locs ?: []]);
    } catch (Throwable $e) {
        nm_send_json(false, ['error'=>$e->getMessage()], 400);
    }
});
