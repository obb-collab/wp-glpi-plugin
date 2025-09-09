<?php
if (!defined('ABSPATH')) exit;

function nt_sql_get_categories() {
    $db = nt_db();
    $sql = "SELECT id, name, completename FROM glpi_itilcategories WHERE is_helpdeskvisible=1 ORDER BY completename ASC LIMIT 2000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'completename' => (string) $r['completename'],
        ];
    }, $rows);
}

function nt_sql_get_locations() {
    $db = nt_db();
    $sql = "SELECT id, name, completename FROM glpi_locations ORDER BY completename ASC LIMIT 3000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'completename' => (string) $r['completename'],
        ];
    }, $rows);
}

function nt_sql_get_assignees() {
    $db = nt_db();
    $sql = "SELECT id, name, realname, firstname FROM glpi_users WHERE is_active=1 ORDER BY realname, firstname LIMIT 2000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function ($r) {
        $label = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
        if ($label === '') {
            $label = $r['name'] ?? '';
        }
        return [
            'id' => (int) $r['id'],
            'label' => $label,
        ];
    }, $rows);
}

function nt_sql_find_duplicate($uid, $title, $content) {
    $db = nt_db();
    $sql = "SELECT id FROM glpi_tickets WHERE users_id_recipient = %d AND name = %s AND content = %s AND TIMESTAMPDIFF(SECOND, date, NOW()) <= 3 LIMIT 1";
    $id = $db->get_var($db->prepare($sql, $uid, $title, $content));
    return $id ? (int) $id : 0;
}

function nt_sql_create_ticket($title, $content, $cat_id, $loc_id, $uid, $assignee_id) {
    $db = nt_db();
    nt_db_begin();
    $dup = nt_sql_find_duplicate($uid, $title, $content);
    if ($dup) {
        nt_db_rollback();
        return ['ok' => true, 'code' => 'already_exists', 'ticket_id' => $dup];
    }
    $now = current_time('mysql');
    $sql = "INSERT INTO glpi_tickets (name, content, status, itilcategories_id, locations_id, users_id_recipient, date, date_mod) VALUES (%s,%s,1,%d,%d,%d,%s,%s)";
    $r = $db->query($db->prepare($sql, $title, $content, $cat_id, $loc_id, $uid, $now, $now));
    if ($r === false) {
        $err = $db->last_error;
        nt_db_rollback();
        return ['ok' => false, 'code' => 'sql_error', 'message' => $err];
    }
    $tid = (int) $db->insert_id;
    $q1 = "INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,1)";
    if ($db->query($db->prepare($q1, $tid, $uid)) === false) {
        $err = $db->last_error;
        nt_db_rollback();
        return ['ok' => false, 'code' => 'sql_error', 'message' => $err];
    }
    $assign = $assignee_id > 0 ? $assignee_id : $uid;
    $q2 = "INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,2)";
    if ($db->query($db->prepare($q2, $tid, $assign)) === false) {
        $err = $db->last_error;
        nt_db_rollback();
        return ['ok' => false, 'code' => 'sql_error', 'message' => $err];
    }
    nt_db_commit();
    return ['ok' => true, 'ticket_id' => $tid];
}
