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

if (!defined('WP_GLPI_DEBUG')) {
    define('WP_GLPI_DEBUG', false);
}

function glpi_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=192.168.100.12;dbname=glpi;charset=utf8mb4';
    $pdo = new PDO($dsn, 'wp_glpi', 'xapetVD4OWZqw8f', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    return $pdo;
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
function glpi_db_get_categories($entity_id = 0) {
    global $glpi_db;

    $sql = "SELECT c.id, c.name, c.completename, c.level, c.ancestors_cache\n"
         . "FROM glpi_itilcategories c\n"
         . "WHERE c.is_deleted = 0 AND c.is_helpdeskvisible = 1";

    if ($entity_id > 0) {
        $sql .= $glpi_db->prepare(" AND (c.entities_id = %d OR c.is_recursive = 1)", $entity_id);
    }

    $sql .= "\nORDER BY c.completename ASC";

    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'categories'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'categories'];
    }

    $list = array_map(function ($r) {
        return [
            'id'              => (int) ($r['id'] ?? 0),
            'name'            => $r['name'] ?? '',
            'completename'    => $r['completename'] ?? '',
            'level'           => isset($r['level']) ? (int) $r['level'] : null,
            'ancestors_cache' => $r['ancestors_cache'] ?? '',
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

/**
 * Fetch list of available locations.
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array}
 */
function glpi_db_get_locations() {
    global $glpi_db;

    $sql = "SELECT l.id, l.name, l.completename\n"
         . "FROM glpi_locations l\n"
         . "WHERE l.is_deleted = 0\n"
         . "ORDER BY l.completename ASC";

    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'locations'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'locations'];
    }

    $list = array_map(function ($r) {
        return [
            'id'           => (int) ($r['id'] ?? 0),
            'name'         => $r['name'] ?? '',
            'completename' => $r['completename'] ?? '',
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

/**
 * Fetch list of executors (users).
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array}
 */

/**
 * Create ticket transaction.
 *
 * @param array $payload
 * @return array{ok:bool,code?:string,ticket_id?:int,assigned?:int|null}
 */
function glpi_db_create_ticket(array $payload) {
    global $glpi_db;

    $name   = trim((string)($payload['name'] ?? ''));
    $desc   = trim((string)($payload['content'] ?? ''));
    $cat    = (int)($payload['category_id'] ?? 0);
    $loc    = (int)($payload['location_id'] ?? 0);
    $author = (int)($payload['requester_id'] ?? 0);
    $exec   = (int)($payload['executor_glpi_id'] ?? 0);
    $assign_me = !empty($payload['assign_me']);

    if ($name === '' || mb_strlen($name) > 255) {
        return ['ok' => false, 'code' => 'validation', 'field' => 'name'];
    }
    if ($desc === '' || mb_strlen($desc) > 20000) {
        return ['ok' => false, 'code' => 'validation', 'field' => 'content'];
    }
    if ($cat <= 0 || $author <= 0) {
        return ['ok' => false, 'code' => 'validation'];
    }

    $glpi_db->query('START TRANSACTION');

    $is_leaf = (int)$glpi_db->get_var($glpi_db->prepare(
        "SELECT COUNT(*) FROM glpi_itilcategories c WHERE c.id=%d AND c.is_deleted=0 AND c.is_helpdeskvisible=1 AND NOT EXISTS (SELECT 1 FROM glpi_itilcategories ch WHERE ch.is_deleted=0 AND ch.completename LIKE CONCAT(c.completename, ' > %%'))",
        $cat
    ));
    if (!$is_leaf) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'invalid_category'];
    }

    if ($loc > 0) {
        $loc_leaf = (int)$glpi_db->get_var($glpi_db->prepare(
            "SELECT COUNT(*) FROM glpi_locations l WHERE l.id=%d AND l.is_deleted=0 AND NOT EXISTS (SELECT 1 FROM glpi_locations ch WHERE ch.is_deleted=0 AND ch.completename LIKE CONCAT(l.completename, ' > %%'))",
            $loc
        ));
        if (!$loc_leaf) {
            $glpi_db->query('ROLLBACK');
            return ['ok' => false, 'code' => 'invalid_location'];
        }
    } else {
        $loc = null;
    }

    $assigned = $assign_me ? $author : $exec;
    $user_row = $glpi_db->get_row($glpi_db->prepare(
        'SELECT id, entities_id FROM glpi_users WHERE id=%d AND is_deleted=0',
        $assigned
    ), ARRAY_A);
    if (!$user_row) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'invalid_executor'];
    }
    $entities_id = (int)$user_row['entities_id'];

    $dup_id = $glpi_db->get_var($glpi_db->prepare(
        'SELECT id FROM glpi_tickets WHERE users_id_recipient=%d AND name=%s AND content=%s AND TIMESTAMPDIFF(SECOND,date,NOW())<=300 LIMIT 1',
        $author,
        $name,
        $desc
    ));
    if ($dup_id) {
        $glpi_db->query('COMMIT');
        return ['ok' => true, 'ticket_id' => (int)$dup_id, 'message' => 'already_exists'];
    }

    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $due = clone $now;
    if ((int)$now->format('H') > 18 || ((int)$now->format('H') === 18 && (int)$now->format('i') > 0)) {
        $due->modify('+1 day');
    }
    $due->setTime(18, 0, 0);
    $due_str = $due->format('Y-m-d H:i:s');

    $sql = $glpi_db->prepare(
        'INSERT INTO glpi_tickets (name, content, status, itilcategories_id, locations_id, entities_id, users_id_lastupdater, users_id_recipient, date, date_mod, due_date) VALUES (%s,%s,1,%d,%s,%d,%d,%d,NOW(),NOW(),%s)',
        $name,
        $desc,
        $cat,
        $loc,
        $entities_id,
        $author,
        $author,
        $due_str
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $ticket_id = (int)$glpi_db->insert_id;

    $sql = $glpi_db->prepare('INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,1)', $ticket_id, $author);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $sql = $glpi_db->prepare('INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,2)', $ticket_id, $assigned);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }

    $sql = $glpi_db->prepare(
        "INSERT INTO glpi_itilfollowups (items_id, itemtype, users_id, is_private, content, date) VALUES (%d,'Ticket',%d,1,%s,NOW())",
        $ticket_id,
        $author,
        'Создано через WordPress'
    );
    $glpi_db->query($sql);

    $glpi_db->query('COMMIT');
    return ['ok' => true, 'ticket_id' => $ticket_id, 'message' => 'created'];
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
