<?php
/**
 * Lightweight SQL helpers for interacting with GLPI tables.
 * All queries are executed using the global $glpi_db (wpdb instance)
 * with prepared statements.
 */

require_once __DIR__ . '/../glpi-utils.php';

/**
 * Insert a followup into glpi_itilfollowups.
 *
 * @param int         $ticket_id Ticket identifier.
 * @param string      $content   Followup text.
 * @param int|null    $author_id Optional GLPI user id. When null the
 *                               current mapped GLPI user will be used.
 * @return array{ok:bool,followup_id?:int,code?:string,message?:string}
 */
function add_followup_sql($ticket_id, $content, $author_id = null) {
    return gexe_add_followup_sql($ticket_id, $content, $author_id);
}

/**
 * Update ticket status directly in glpi_tickets table.
 *
 * The operation is idempotent – if the ticket already has the requested
 * status the function succeeds with an `already` flag set to true.
 *
 * @param int      $ticket_id Ticket identifier.
 * @param int      $status    New status value.
 * @param int|null $user_id   GLPI user performing the change.
 * @return array{ok:bool,already?:bool,code?:string,message?:string,status?:int}
 */
function set_ticket_status_sql($ticket_id, $status, $user_id = null) {
    global $glpi_db;

    $ticket_id = (int) $ticket_id;
    $status    = (int) $status;
    $user_id   = $user_id !== null ? (int) $user_id : 0;
    if ($ticket_id <= 0) {
        return ['ok' => false, 'code' => 'VALIDATION', 'message' => 'bad_ticket'];
    }

    $glpi_db->query('START TRANSACTION');
    $current = $glpi_db->get_var(
        $glpi_db->prepare('SELECT status FROM glpi_tickets WHERE id=%d FOR UPDATE', $ticket_id)
    );
    if ((int) $current === $status) {
        $glpi_db->query('COMMIT');
        return ['ok' => true, 'already' => true, 'status' => $status];
    }

    $sql = $glpi_db->prepare(
        'UPDATE glpi_tickets SET status=%d, users_id_lastupdater=%d, date_mod=NOW() WHERE id=%d',
        $status,
        $user_id,
        $ticket_id
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'SQL_ERROR', 'message' => $err];
    }
    $glpi_db->query('COMMIT');
    return ['ok' => true, 'status' => $status, 'already' => false];
}

/**
 * Create a new ticket using direct SQL queries.
 * Only a subset of fields is supported which is sufficient for tests.
 *
 * @param array $data {name:string, content:string, requester_id:int,
 *                     assignee_id?:int, itilcategories_id?:int,
 *                     locations_id?:int, entities_id?:int}
 * @return array{ok:bool,ticket_id?:int,code?:string,message?:string}
 */
function create_ticket_sql(array $data) {
    global $glpi_db;

    $defaults = [
        'name'             => '',
        'content'          => '',
        'requester_id'     => 0,
        'assignee_id'      => 0,
        'itilcategories_id'=> 0,
        'locations_id'     => 0,
        'entities_id'      => 0,
        'status'           => 1,
    ];
    $p = array_merge($defaults, $data);

    $glpi_db->query('START TRANSACTION');
    $sql = $glpi_db->prepare(
        'INSERT INTO glpi_tickets (name, content, status, date, date_mod, users_id_recipient, users_id_lastupdater, entities_id, itilcategories_id, locations_id) VALUES (%s,%s,%d,NOW(),NOW(),%d,%d,%d,%d,%d)',
        $p['name'],
        $p['content'],
        $p['status'],
        $p['requester_id'],
        $p['requester_id'],
        $p['entities_id'],
        $p['itilcategories_id'],
        $p['locations_id']
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'SQL_ERROR', 'message' => $err];
    }
    $ticket_id = (int) $glpi_db->insert_id;

    // requester
    $sql = $glpi_db->prepare(
        'INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,1)',
        $ticket_id,
        $p['requester_id']
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'SQL_ERROR', 'message' => $err];
    }

    if (!empty($p['assignee_id'])) {
        $sql = $glpi_db->prepare(
            'INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,2)',
            $ticket_id,
            (int) $p['assignee_id']
        );
        if (!$glpi_db->query($sql)) {
            $err = $glpi_db->last_error;
            $glpi_db->query('ROLLBACK');
            return ['ok' => false, 'code' => 'SQL_ERROR', 'message' => $err];
        }
    }

    $glpi_db->query('COMMIT');
    return ['ok' => true, 'ticket_id' => $ticket_id];
}

/**
 * Ensure provided GLPI user is assigned to ticket as type=2.
 */
function check_assignee_match($ticket_id, $glpi_user_id) {
    global $glpi_db;
    $ticket_id    = (int) $ticket_id;
    $glpi_user_id = (int) $glpi_user_id;
    $sql = $glpi_db->prepare(
        'SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type=2 LIMIT 1',
        $ticket_id,
        $glpi_user_id
    );
    return (bool) $glpi_db->get_var($sql);
}

/**
 * Check existence of a followup with exact text for a given user.
 */
function has_followup_by_text($ticket_id, $user_id, $text) {
    global $glpi_db;
    $ticket_id = (int) $ticket_id;
    $user_id   = (int) $user_id;
    $text      = trim((string) $text);
    $sql = $glpi_db->prepare(
        "SELECT 1 FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d AND users_id=%d AND content=%s LIMIT 1",
        $ticket_id,
        $user_id,
        $text
    );
    return (bool) $glpi_db->get_var($sql);
}

?>
