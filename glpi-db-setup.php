<?php
if (!defined('ABSPATH')) exit;

global $glpi_db;
if (!isset($glpi_db) || !($glpi_db instanceof wpdb)) {
    $glpi_db = new wpdb(
        'wp_glpi',            // db user
        'xapetVD4OWZqw8f',    // db password
        'glpi',               // db name
        '192.168.100.12'      // db host
    );
}

define('GEXE_TRIGGERS_VERSION', '2');

define('GEXE_GLPI_API_URL', 'http://192.168.100.12/glpi/apirest.php');
define('GEXE_GLPI_APP_TOKEN', 'nqubXrD6j55bgLRuD1mrrtz5D69cXz94HHPvgmac');
define('GEXE_GLPI_USER_TOKEN', '8ffMQJvkcgi8V5OMWrh89Xvr97jEzK4ddrkdL6pw');

function gexe_glpi_api_url(): string {
    return rtrim(GEXE_GLPI_API_URL, '/');
}

function gexe_glpi_api_headers(array $extra = []): array {
    $base = [
        'Content-Type' => 'application/json',
        'Authorization' => 'user_token ' . GEXE_GLPI_USER_TOKEN,
        'App-Token'     => GEXE_GLPI_APP_TOKEN,
    ];
    return array_merge($base, $extra);
}

function gexe_glpi_triggers_present() {
    global $glpi_db;
    $sql = "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='glpi' AND TRIGGER_NAME IN ('glpi_followups_ai','glpi_followups_ad')";
    return (int)$glpi_db->get_var($sql) === 2;
}

/** Whether followups_count column is available. */
function gexe_glpi_use_followups_count() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $opt = get_option('glpi_use_followups_count');
    if ($opt !== false) {
        $cached = ((int)$opt === 1);
        return $cached;
    }
    global $glpi_db;
    $col = $glpi_db->get_var("SHOW COLUMNS FROM glpi.glpi_tickets LIKE 'followups_count'");
    $cached = (bool)$col;
    update_option('glpi_use_followups_count', $cached ? 1 : 0);
    return $cached;
}

function gexe_glpi_install_triggers($force = false) {
    global $glpi_db;

    if (!$force && get_option('glpi_triggers_version') === GEXE_TRIGGERS_VERSION) {
        return;
    }

    $existing = gexe_glpi_triggers_present();

    $grants = $glpi_db->get_col('SHOW GRANTS');
    $has_trigger = false;
    $has_alter   = false;
    if ($grants) {
        foreach ($grants as $g) {
            if (preg_match('~GRANT (ALL PRIVILEGES|.*TRIGGER.*) ON `?glpi`?\.\\*~i', $g)) {
                $has_trigger = true;
            }
            if (preg_match('~GRANT (ALL PRIVILEGES|.*ALTER.*) ON `?glpi`?\.\\*~i', $g)) {
                $has_alter = true;
            }
        }
    }
    if (!$has_trigger) {
        error_log('gexe/triggers: missing TRIGGER privilege on glpi schema');
        update_option('glpi_triggers_version', GEXE_TRIGGERS_VERSION);
        update_option('glpi_use_followups_count', 0);
        return;
    }

    $glpi_db->query('SET sql_notes=0');
    $glpi_db->query('START TRANSACTION');
    $ok = true;

    $col = $glpi_db->get_var("SHOW COLUMNS FROM glpi.glpi_tickets LIKE 'last_followup_at'");
    if (!$col) {
        $glpi_db->query("ALTER TABLE glpi.glpi_tickets ADD COLUMN last_followup_at DATETIME NULL AFTER date_mod");
        if ($glpi_db->last_error) $ok = false;
        if ($ok) {
            $glpi_db->query("UPDATE glpi.glpi_tickets t LEFT JOIN (SELECT items_id, MAX(date) AS d FROM glpi.glpi_itilfollowups WHERE itemtype='Ticket' GROUP BY items_id) f ON t.id = f.items_id SET t.last_followup_at = f.d");
            if ($glpi_db->last_error) $ok = false;
        }
    }

    $use_counter = (bool)$glpi_db->get_var("SHOW COLUMNS FROM glpi.glpi_tickets LIKE 'followups_count'");
    if (!$use_counter && $has_alter && $ok) {
        $glpi_db->query("ALTER TABLE glpi.glpi_tickets ADD COLUMN followups_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_followup_at");
        if ($glpi_db->last_error) {
            $ok = false;
        } else {
            $glpi_db->query("UPDATE glpi.glpi_tickets t LEFT JOIN (SELECT items_id, COUNT(*) c FROM glpi.glpi_itilfollowups WHERE itemtype='Ticket' GROUP BY items_id) f ON f.items_id = t.id SET t.followups_count = COALESCE(f.c,0)");
            if ($glpi_db->last_error) $ok = false; else $use_counter = true;
        }
    }
    if (!$use_counter && !$has_alter) {
        error_log('gexe/triggers: no ALTER privilege, falling back to COUNT(*)');
    }
    if ($use_counter && $ok) {
        $idx = $glpi_db->get_var("SHOW INDEX FROM glpi.glpi_itilfollowups WHERE Key_name='idx_followups_item'");
        if (!$idx) {
            $glpi_db->query("CREATE INDEX idx_followups_item ON glpi.glpi_itilfollowups (itemtype, items_id)");
            if ($glpi_db->last_error) $ok = false;
        }
    }

    if ($ok) {
        if ($use_counter) {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ai AFTER INSERT ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF NEW.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets SET last_followup_at = NEW.date, followups_count = followups_count + 1 WHERE id = NEW.items_id; END IF; END;");
        } else {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ai AFTER INSERT ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF NEW.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets SET last_followup_at = NEW.date WHERE id = NEW.items_id; END IF; END;");
        }
        if ($glpi_db->last_error) $ok = false;
    }
    if ($ok) {
        if ($use_counter) {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ad AFTER DELETE ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF OLD.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets t SET last_followup_at = (SELECT MAX(f.date) FROM glpi.glpi_itilfollowups f WHERE f.itemtype='Ticket' AND f.items_id = t.id), followups_count = (SELECT COUNT(*) FROM glpi.glpi_itilfollowups f WHERE f.itemtype='Ticket' AND f.items_id = t.id) WHERE t.id = OLD.items_id; END IF; END;");
        } else {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ad AFTER DELETE ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF OLD.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets t SET last_followup_at = (SELECT MAX(f.date) FROM glpi.glpi_itilfollowups f WHERE f.itemtype='Ticket' AND f.items_id = t.id) WHERE t.id = OLD.items_id; END IF; END;");
        }
        if ($glpi_db->last_error) $ok = false;
    }

    if ($ok) {
        $glpi_db->query('COMMIT');
        update_option('glpi_triggers_installed', time());
        update_option('glpi_triggers_version', GEXE_TRIGGERS_VERSION);
        update_option('glpi_use_followups_count', $use_counter ? 1 : 0);
        error_log('gexe/triggers: installation completed');
    } else {
        $glpi_db->query('ROLLBACK');
        error_log('gexe/triggers: install failed: ' . $glpi_db->last_error);
        update_option('glpi_triggers_version', GEXE_TRIGGERS_VERSION);
        update_option('glpi_use_followups_count', $use_counter ? 1 : 0);
    }
}

function gexe_glpi_remove_triggers() {
    global $glpi_db;
    $glpi_db->query('SET sql_notes=0');
    $glpi_db->query('DROP TRIGGER IF EXISTS glpi.glpi_followups_ai');
    $glpi_db->query('DROP TRIGGER IF EXISTS glpi.glpi_followups_ad');
    delete_option('glpi_triggers_installed');
    delete_option('glpi_triggers_version');
}

function gexe_glpi_triggers_status() {
    global $glpi_db;
    return $glpi_db->get_results("SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='glpi' AND TRIGGER_NAME IN ('glpi_followups_ai','glpi_followups_ad')");
}

/**
 * Insert a followup for a ticket.
 *
 * @param int    $ticket_id
 * @param int    $glpi_user_id
 * @param string $content
 * @return array{ok:bool,code?:string,msg?:string,followup?:array}
 */
function sql_insert_followup($ticket_id, $glpi_user_id, $content) {
    global $glpi_db;

    $ticket_id    = (int) $ticket_id;
    $glpi_user_id = (int) $glpi_user_id;
    $content      = trim((string) $content);
    if ($ticket_id <= 0 || $glpi_user_id <= 0 || $content === '') {
        return ['ok' => false, 'code' => 'validation'];
    }

    $sql = $glpi_db->prepare(
        "INSERT INTO glpi_itilfollowups (items_id,itemtype,users_id,date,content,is_private) VALUES (%d,'Ticket',%d,NOW(),%s,0)",
        $ticket_id,
        $glpi_user_id,
        $content
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $fid = (int) $glpi_db->insert_id;
    return [
        'ok'       => true,
        'followup' => [
            'id'       => $fid,
            'items_id' => $ticket_id,
            'users_id' => $glpi_user_id,
            'content'  => $content,
            'date'     => date('c'),
        ],
    ];
}

/**
 * Fetch list of helpdesk-visible categories.
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array<int,array{id:int,name:string}>}
 */
function glpi_db_get_categories() {
    global $glpi_db;

    $sql = "SELECT c.id, c.completename, c.name\n"
         . "FROM glpi_itilcategories c\n"
         . "WHERE c.is_active=1 AND c.is_helpdeskvisible=1\n"
         . "  AND NOT EXISTS (\n"
         . "    SELECT 1 FROM glpi_itilcategories ch\n"
         . "    WHERE ch.is_active=1\n"
         . "      AND ch.completename LIKE CONCAT(c.completename, ' > %')\n"
         . "  )\n"
         . "UNION\n"
         . "SELECT p.id, p.completename, p.name\n"
         . "FROM glpi_itilcategories p\n"
         . "WHERE p.is_active=1 AND p.is_helpdeskvisible=1\n"
         . "  AND EXISTS (\n"
         . "    SELECT 1 FROM glpi_itilcategories ch\n"
         . "    WHERE ch.is_active=1\n"
         . "      AND ch.completename LIKE CONCAT(p.completename, ' > %')\n"
         . "      AND ch.name = p.name\n"
         . "  )\n"
         . "ORDER BY completename";

    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'categories'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'categories'];
    }

    $list = array_map(function ($r) {
        return [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'completename'=> $r['completename'],
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

/**
 * Fetch list of executors (users).
 *
 * @return array<array{glpi_user_id:int,realname:string,firstname:string}>
 */
function glpi_db_get_executors() {
    global $glpi_db, $wpdb;
    $sql = $glpi_db->prepare(
        "SELECT u.id AS glpi_user_id, u.realname, u.firstname\n"
        . "FROM glpi_users u\n"
        . "INNER JOIN {$wpdb->usermeta} m ON m.meta_key = %s AND CAST(m.meta_value AS UNSIGNED) = u.id\n"
        . "INNER JOIN {$wpdb->users} w ON w.ID = m.user_id\n"
        . "WHERE u.is_active = 1\n"
        . "ORDER BY u.realname, u.firstname",
        'glpi_user_id'
    );
    $rows = $glpi_db->get_results($sql, ARRAY_A);
    return $rows ? $rows : [];
}

/**
 * Fetch tickets filtered by executor id.
 *
 * @param string|int $executor_id 'all' or GLPI user id
 * @param int        $current_id  current GLPI user id for permission check
 * @return array<int,array>
 */
function glpi_db_get_tickets_by_executor($executor_id, $current_id) {
    global $glpi_db;
    $where_status = " t.status IN (1,2,3,4) AND t.is_deleted = 0 ";
    $join_assignee = " LEFT JOIN glpi_tickets_users tu ON t.id = tu.tickets_id AND tu.type = 2 ";
    if ($executor_id !== 'all') {
        $where_status .= $glpi_db->prepare(' AND tu.users_id = %d ', (int) $executor_id);
    }
    $sql = "
        SELECT  t.id, t.status, t.time_to_resolve,
                t.name, t.content, t.date,
                tu.users_id AS assignee_id,
                tu_req.users_id AS author_id,
                u.realname, u.firstname,
                c.completename AS category_name,
                l.completename AS location_name
        FROM glpi_tickets t
        $join_assignee
        LEFT JOIN glpi_tickets_users tu_req ON t.id = tu_req.tickets_id AND tu_req.type = 1
        LEFT JOIN glpi_users u ON tu.users_id = u.id
        LEFT JOIN glpi_itilcategories c ON t.itilcategories_id = c.id
        LEFT JOIN glpi_locations l ON t.locations_id = l.id
        WHERE $where_status
        ORDER BY t.date DESC
        LIMIT 500
    ";
    $rows = $glpi_db->get_results($sql, ARRAY_A);
    return $rows ? $rows : [];
}

/**
 * Fetch list of available locations.
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array}
 */
function glpi_db_get_locations() {
    global $glpi_db;

    $sql = "SELECT l.id, l.completename, l.name\n"
         . "FROM glpi_locations l\n"
         . "WHERE l.is_active=1\n"
         . "  AND NOT EXISTS (\n"
         . "    SELECT 1 FROM glpi_locations ch\n"
         . "    WHERE ch.is_active=1\n"
         . "      AND ch.completename LIKE CONCAT(l.completename, ' > %')\n"
         . "  )\n"
         . "UNION\n"
         . "SELECT p.id, p.completename, p.name\n"
         . "FROM glpi_locations p\n"
         . "WHERE p.is_active=1\n"
         . "  AND EXISTS (\n"
         . "    SELECT 1 FROM glpi_locations ch\n"
         . "    WHERE ch.is_active=1\n"
         . "      AND ch.completename LIKE CONCAT(p.completename, ' > %')\n"
         . "      AND ch.name = p.name\n"
         . "  )\n"
         . "ORDER BY completename";

    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'locations'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'locations'];
    }

    $list = array_map(function ($r) {
        return [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'completename'=> $r['completename'],
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

/**
 * Create ticket transaction.
 *
 * @param array $payload
 * @return array{ok:bool,code?:string,ticket_id?:int,assigned?:int|null}
 */
function glpi_db_create_ticket(array $payload) {
    global $glpi_db;

    $name   = (string) ($payload['name'] ?? '');
    $desc   = (string) ($payload['content'] ?? '');
    $cat    = (int) ($payload['category_id'] ?? 0);
    $loc    = (int) ($payload['location_id'] ?? 0);
    $req_id = (int) ($payload['requester_id'] ?? 0);
    $exec   = (int) ($payload['executor_glpi_id'] ?? 0);
    $assign_me = !empty($payload['assign_me']);
    $entity = (int) ($payload['entities_id'] ?? 0);

    if ($name === '' || strlen($name) > 200 || $cat <= 0 || $loc <= 0 || $req_id <= 0 || $exec < 0) {
        return ['ok' => false, 'code' => 'validation'];
    }
    if (strlen($desc) > 4000) {
        return ['ok' => false, 'code' => 'validation'];
    }

    $glpi_db->query('START TRANSACTION');

    // Duplicate guard
    $dup = $glpi_db->get_var($glpi_db->prepare(
        "SELECT id FROM glpi_tickets WHERE name=%s AND itilcategories_id=%d AND locations_id=%d AND SHA1(content)=SHA1(%s) AND date_creation > (NOW() - INTERVAL 8 SECOND) LIMIT 1",
        $name,
        $cat,
        $loc,
        $desc
    ));
    if ($dup) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'duplicate_submit'];
    }

    $sql = $glpi_db->prepare(
        "INSERT INTO glpi_tickets (name, content, itilcategories_id, locations_id, status, date_creation, date_mod, due_date, entities_id) VALUES (%s,%s,%d,%d,1,NOW(),NOW(),CONCAT(CURDATE(),' 17:30:00'),%d)",
        $name,
        $desc,
        $cat,
        $loc,
        $entity
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $ticket_id = (int) $glpi_db->insert_id;

    $assigned = null;
    if ($exec > 0) {
        $sql = $glpi_db->prepare(
            'INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,2)',
            $ticket_id,
            $exec
        );
        if (!$glpi_db->query($sql)) {
            $err = $glpi_db->last_error;
            $glpi_db->query('ROLLBACK');
            return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
        }
        $assigned = $exec;
    }

    if ($assign_me) {
        $sql = $glpi_db->prepare(
            'INSERT IGNORE INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,2)',
            $ticket_id,
            $req_id
        );
        if (!$glpi_db->query($sql)) {
            $err = $glpi_db->last_error;
            $glpi_db->query('ROLLBACK');
            return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
        }
        if ($assigned === null) {
            $assigned = $req_id;
        }
    }

    if ($desc !== '') {
        $sql = $glpi_db->prepare(
            "INSERT INTO glpi_itilfollowups (items_id, itemtype, users_id, date, content, is_private) VALUES (%d,'Ticket',%d,NOW(),%s,0)",
            $ticket_id,
            $req_id,
            $desc
        );
        if (!$glpi_db->query($sql)) {
            $err = $glpi_db->last_error;
            $glpi_db->query('ROLLBACK');
            return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
        }
    }

    $glpi_db->query('COMMIT');
    return ['ok' => true, 'code' => 'ok', 'ticket_id' => $ticket_id, 'assigned' => $assigned];
}

/**
 * Load status IDs from glpi_itilstatuses and map common names.
 *
 * @return array{work:int,planned:int,onhold:int,resolved:int}
 */
function gexe_glpi_status_map() {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $glpi_db;
    $cache = ['work' => 2, 'planned' => 3, 'onhold' => 4, 'resolved' => 6];
    $rows = $glpi_db->get_results('SELECT id,name FROM glpi_itilstatuses', ARRAY_A);
    if ($rows) {
        $names = [];
        foreach ($rows as $r) {
            $names[mb_strtolower(trim($r['name']))] = (int) $r['id'];
        }
        $cache['work'] = $names['в работе'] ?? $names['in progress'] ?? $cache['work'];
        $cache['planned'] = $names['в плане'] ?? $names['planned'] ?? $cache['planned'];
        $cache['onhold'] = $names['в стопе'] ?? $names['on hold'] ?? $names['ожидание'] ?? $names['等待'] ?? $cache['onhold'];
        $cache['resolved'] = $names['решено'] ?? $names['resolved'] ?? $names['solved'] ?? $cache['resolved'];
    }
    return $cache;
}

/**
 * Change ticket status and insert followup.
 *
 * @param int $ticket_id
 * @param int $glpi_user_id
 * @param int $status_id
 * @param string $followup_text
 * @return array{ok:bool,code?:string,msg?:string,extra?:array}
 */
function sql_ticket_set_status($ticket_id, $glpi_user_id, $status_id, $followup_text) {
    global $glpi_db;

    $ticket_id    = (int) $ticket_id;
    $glpi_user_id = (int) $glpi_user_id;
    $status_id    = (int) $status_id;
    $followup_text = (string) $followup_text;
    if ($ticket_id <= 0 || $glpi_user_id <= 0 || $status_id <= 0) {
        return ['ok' => false, 'code' => 'validation'];
    }

    $glpi_db->query('START TRANSACTION');

    $has = $glpi_db->get_var($glpi_db->prepare(
        'SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type IN (2) FOR UPDATE',
        $ticket_id,
        $glpi_user_id
    ));
    if (!$has) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'no_rights'];
    }

    $status = $glpi_db->get_var($glpi_db->prepare('SELECT status FROM glpi_tickets WHERE id=%d FOR UPDATE', $ticket_id));
    if ($status === null) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'not_found'];
    }
    if ((int) $status === $status_id) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'already_done', 'extra' => ['status' => $status_id]];
    }

    $sql = $glpi_db->prepare('UPDATE glpi_tickets SET status=%d WHERE id=%d', $status_id, $ticket_id);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }

    $sql = $glpi_db->prepare(
        "INSERT INTO glpi_itilfollowups (items_id,itemtype,users_id,date,content,is_private) VALUES (%d,'Ticket',%d,NOW(),%s,0)",
        $ticket_id,
        $glpi_user_id,
        $followup_text
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $fid  = (int) $glpi_db->insert_id;
    $date = $glpi_db->get_var($glpi_db->prepare('SELECT date FROM glpi_itilfollowups WHERE id=%d', $fid));

    $glpi_db->query('COMMIT');
    return [
        'ok'    => true,
        'extra' => [
            'status'   => $status_id,
            'followup' => [
                'id'      => $fid,
                'content' => $followup_text,
                'date'    => $date,
            ],
        ],
    ];
}

/**
 * Resolve ticket by setting status to 6 and adding a followup.
 *
 * Similar to a regular followup insertion but checks current status and
 * returns `already_done` when the ticket is already resolved. The followup
 * text is fixed to "Заявка решена".
 *
 * @param int $ticket_id
 * @param int $glpi_user_id
 * @return array{ok:bool,code?:string,msg?:string,extra?:array}
 */
function sql_ticket_resolve($ticket_id, $glpi_user_id) {
    $map = gexe_glpi_status_map();
    return sql_ticket_set_status($ticket_id, $glpi_user_id, $map['resolved'], 'Заявка решена');
}

if (defined('WP_CLI') && WP_CLI) {
    class Gexe_Triggers_CLI {
        public function install() {
            gexe_glpi_install_triggers(true);
            if (gexe_glpi_triggers_present()) {
                WP_CLI::success('Triggers installed');
            } else {
                WP_CLI::error('Trigger installation failed');
            }
        }
        public function remove() {
            gexe_glpi_remove_triggers();
            WP_CLI::success('Triggers removed');
        }
        public function status() {
            $rows = gexe_glpi_triggers_status();
            if (!$rows) {
                WP_CLI::line('No triggers found');
                return;
            }
            foreach ($rows as $r) {
                WP_CLI::line(sprintf('%s: %s %s', $r->TRIGGER_NAME, $r->ACTION_TIMING, $r->EVENT_MANIPULATION));
                WP_CLI::line($r->ACTION_STATEMENT);
            }
        }
    }
    WP_CLI::add_command('gexe:triggers', 'Gexe_Triggers_CLI');
}
